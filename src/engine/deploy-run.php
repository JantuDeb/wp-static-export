<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a deployment in time-boxed steps so it fits in a web request or a background queue window.
 *
 * The run is a small state machine: list the files, hash them, plan what changed since the last deploy,
 * let the provider upload (resumable), then remember the new state. Everything needed to continue lives in
 * a JSON file next to the deploy state; no credentials are stored in it (the caller passes the job again).
 *
 * Job keys (same as the former Node tool): provider, dir, stateFile, scope (full|partial), force,
 * concurrency, maxDeleteRatio, ignore (globs), config.
 */
class Deploy_Run {

	const HASH_BATCH = 40;

	/**
	 * Begin a run.
	 *
	 * @param array $job Job.
	 * @return array Descriptor to keep and pass to step().
	 * @throws Deploy_Error When the job is invalid.
	 */
	public static function start( array $job ) {
		$provider = Providers::get( (string) ( $job['provider'] ?? '' ) );
		$provider->validate( (array) ( $job['config'] ?? array() ) );

		$dir = rtrim( (string) ( $job['dir'] ?? '' ), '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			throw new Deploy_Error( 'Export directory not found: ' . $dir );
		}
		$base = ! empty( $job['stateFile'] ) ? dirname( $job['stateFile'] ) : sys_get_temp_dir();
		$id   = bin2hex( random_bytes( 6 ) );
		$run  = array(
			'id'   => $id,
			'file' => rtrim( $base, '/\\' ) . '/run-' . $id . '.json',
		);
		Util::write_file( $run['file'], json_encode( array( 'phase' => 'list', 'cursor' => 0 ) ) );

		return $run;
	}

	/**
	 * Advance a run for up to $seconds.
	 *
	 * @param array $run     Descriptor from start().
	 * @param array $job     The same job.
	 * @param float $seconds Time budget.
	 * @return array state (running|done), logs, progress (done,total,label) or null, result when done.
	 * @throws Deploy_Error On failure.
	 */
	public static function step( array $run, array $job, $seconds = 10.0 ) {
		$deadline = microtime( true ) + max( 0.0, (float) $seconds );
		$data     = Util::read_json( $run['file'] );
		if ( ! $data ) {
			throw new Deploy_Error( 'The deploy state was lost. Start the export again.' );
		}
		$provider = Providers::get( (string) $job['provider'] );
		$config   = $provider->validate( (array) ( $job['config'] ?? array() ) );
		$logs     = array();
		$progress = null;
		$dir      = rtrim( (string) $job['dir'], '/\\' );

		// 1. List.
		if ( 'list' === $data['phase'] ) {
			$files = self::list_files( $dir, (array) ( $job['ignore'] ?? array() ) );
			if ( ! $files ) {
				throw new Deploy_Error( 'Export directory is empty: ' . $dir );
			}
			$data['files']  = $files; // Each: [ path, size, sha256|null ].
			$data['phase']  = 'hash';
			$data['cursor'] = 0;
		}

		// 2. Hash.
		if ( 'hash' === $data['phase'] ) {
			$total = count( $data['files'] );
			$i     = (int) $data['cursor'];
			for ( ; $i < $total; $i++ ) {
				$hash = @hash_file( 'sha256', $dir . '/' . $data['files'][ $i ][0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $hash ) {
					throw new Deploy_Error( 'Could not read ' . $data['files'][ $i ][0] );
				}
				$data['files'][ $i ][2] = $hash;
				if ( 0 === ( $i + 1 ) % self::HASH_BATCH && microtime( true ) >= $deadline ) {
					++$i;
					break;
				}
			}
			$data['cursor'] = $i;
			$progress       = array( $i, $total, 'files scanned' );
			if ( $i >= $total ) {
				$data['phase'] = 'plan';
			}
		}

		// 3. Plan.
		if ( 'plan' === $data['phase'] ) {
			$scope = 'partial' === ( $job['scope'] ?? 'full' ) ? 'partial' : 'full';
			$prev  = ! empty( $job['force'] ) ? array() : self::load_state( $job['stateFile'] ?? '' );
			list( $changed, $deleted ) = self::diff( $data['files'], $prev, $scope, ! empty( $job['force'] ) );

			// A crawl that silently lost most pages must not wipe the live site.
			$prev_count = count( $prev );
			$max_ratio  = (float) ( $job['maxDeleteRatio'] ?? 0.5 );
			if ( $prev_count >= 20 && count( $deleted ) > $prev_count * $max_ratio ) {
				$logs[]  = array( 'warn', sprintf( 'Refusing to delete %d of %d previously deployed files (limit %d%%). Uploading only.', count( $deleted ), $prev_count, round( $max_ratio * 100 ) ) );
				$deleted = array();
			}
			$logs[]          = array( 'info', sprintf( '%d files, %d changed, %d removed', count( $data['files'] ), count( $changed ), count( $deleted ) ) );
			$data['changed'] = $changed; // Paths.
			$data['deleted'] = $deleted;
			$data['p']       = array();
			$data['phase']   = 'deploy';
		}

		// 4. Provider.
		if ( 'deploy' === $data['phase'] ) {
			$ctx              = self::context( $data, $dir, $config, $job, $deadline, $logs, $progress );
			$finished         = $provider->advance( $ctx );
			$data['p']        = $ctx->state;
			if ( $finished ) {
				$data['result'] = $ctx->result;
				$data['phase']  = 'finish';
			}
		}

		// 5. Finish: remember what is now deployed.
		if ( 'finish' === $data['phase'] ) {
			self::save_state( $job, $data['files'] );
			if ( is_file( $run['file'] ) ) {
				@unlink( $run['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			return array( 'state' => 'done', 'logs' => $logs, 'progress' => $progress, 'result' => $data['result'] );
		}

		Util::write_file( $run['file'], json_encode( $data ) );

		return array( 'state' => 'running', 'logs' => $logs, 'progress' => $progress );
	}

	/**
	 * Remove the run's files.
	 *
	 * @param array $run Descriptor.
	 * @return void
	 */
	public static function cleanup( array $run ) {
		if ( ! empty( $run['file'] ) && is_file( $run['file'] ) ) {
			@unlink( $run['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Test the credentials of a target.
	 *
	 * @param array $job Job (provider, config).
	 * @return string Message.
	 * @throws Deploy_Error On failure.
	 */
	public static function verify( array $job ) {
		$provider = Providers::get( (string) ( $job['provider'] ?? '' ) );

		return $provider->verify( $provider->validate( (array) ( $job['config'] ?? array() ) ) );
	}

	/**
	 * @param array    $data     Run data.
	 * @param string   $dir      Export dir.
	 * @param array    $config   Settings.
	 * @param array    $job      Job.
	 * @param float    $deadline Deadline.
	 * @param array    $logs     Log sink (by reference).
	 * @param array|null $progress Progress sink (by reference).
	 * @return Context
	 */
	protected static function context( array $data, $dir, array $config, array $job, $deadline, array &$logs, &$progress ) {
		$by_path = array();
		$all     = array();
		foreach ( $data['files'] as $f ) {
			$entry                = array( 'path' => $f[0], 'abs' => $dir . '/' . $f[0], 'size' => (int) $f[1], 'sha256' => $f[2] );
			$all[]                = $entry;
			$by_path[ $f[0] ]     = $entry;
		}
		$changed = array();
		foreach ( $data['changed'] as $path ) {
			$changed[] = $by_path[ $path ];
		}

		$ctx              = new Context();
		$ctx->dir         = $dir;
		$ctx->all         = $all;
		$ctx->changed     = $changed;
		$ctx->deleted     = $data['deleted'];
		$ctx->config      = $config;
		$ctx->concurrency = max( 1, (int) ( $job['concurrency'] ?? 8 ) );
		$ctx->state       = (array) ( $data['p'] ?? array() );
		$ctx->deadline    = $deadline;
		$ctx->cache_file  = ! empty( $job['stateFile'] ) ? preg_replace( '/\.json$/', '', $job['stateFile'] ) . '.' . ( $job['provider'] ?? 'x' ) . '-cache.json' : '';
		$ctx->logger      = static function ( $level, $message ) use ( &$logs ) {
			$logs[] = array( $level, $message );
		};
		$ctx->progress_cb = static function ( $done, $total, $label ) use ( &$progress ) {
			$progress = array( (int) $done, (int) $total, (string) $label );
		};

		return $ctx;
	}

	/**
	 * @param string   $dir      Export root (no trailing slash).
	 * @param string[] $patterns Globs of paths to leave out.
	 * @return array[] Each: [ path, size, null ] sorted by path.
	 */
	public static function list_files( $dir, array $patterns ) {
		$out = array();
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		$len = strlen( $dir ) + 1;
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = substr( $file->getPathname(), $len );
			if ( Util::matches_any( $path, $patterns ) ) {
				continue;
			}
			$out[] = array( $path, $file->getSize(), null );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a[0], $b[0] );
			}
		);

		return $out;
	}

	/**
	 * @param string $file State file.
	 * @return array path => sha256
	 */
	public static function load_state( $file ) {
		return $file ? Util::read_json( $file ) : array();
	}

	/**
	 * @param array[] $files   Run files: [ path, size, sha256 ].
	 * @param array   $prev    Previous state.
	 * @param string  $scope   full|partial.
	 * @param bool    $force   Upload everything.
	 * @return array [ changed paths, deleted paths ]
	 */
	public static function diff( array $files, array $prev, $scope, $force ) {
		$changed = array();
		$present = array();
		foreach ( $files as $f ) {
			$present[ $f[0] ] = true;
			if ( $force || ! isset( $prev[ $f[0] ] ) || $prev[ $f[0] ] !== $f[2] ) {
				$changed[] = $f[0];
			}
		}
		$deleted = array();
		if ( 'full' === $scope ) {
			foreach ( $prev as $path => $_ ) {
				if ( ! isset( $present[ $path ] ) ) {
					$deleted[] = (string) $path;
				}
			}
		}

		return array( $changed, $deleted );
	}

	/**
	 * @param array   $job   Job.
	 * @param array[] $files Run files.
	 * @return void
	 */
	protected static function save_state( array $job, array $files ) {
		if ( empty( $job['stateFile'] ) ) {
			return;
		}
		$state = 'partial' === ( $job['scope'] ?? 'full' ) ? self::load_state( $job['stateFile'] ) : array();
		foreach ( $files as $f ) {
			$state[ $f[0] ] = $f[2];
		}
		Util::write_file( $job['stateFile'], json_encode( (object) $state ) );
	}
}
