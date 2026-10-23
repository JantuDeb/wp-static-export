<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the bundled Node deployer (deployer/dist/cli.cjs) as a detached process and reads
 * its newline-delimited JSON output. Detaching keeps long uploads from hitting PHP's
 * max_execution_time or the web server timeout; the task just polls the log file.
 */
class Deploy_Runner {

	const MIN_NODE_MAJOR = 18;

	/**
	 * Path of the bundled CLI.
	 *
	 * @return string
	 */
	public static function cli_path() {
		return apply_filters( 'ss_deploy_cli_path', SIMPLY_STATIC_PATH . 'deployer/dist/cli.cjs' );
	}

	/**
	 * Whether PHP may spawn shell commands.
	 *
	 * @return bool
	 */
	public static function can_exec() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'exec', $disabled, true );
	}

	/**
	 * Locate a usable Node.js binary (>= 18).
	 *
	 * @return string|\WP_Error Binary path/name.
	 */
	public static function find_node() {
		if ( ! self::can_exec() ) {
			return new \WP_Error( 'ss_deploy_no_exec', __( 'PHP exec() is disabled, so the deployer cannot be started.', 'simply-static' ) );
		}
		if ( 0 === stripos( PHP_OS, 'WIN' ) ) {
			return new \WP_Error( 'ss_deploy_windows', __( 'Deploying from a Windows server is not supported yet.', 'simply-static' ) );
		}

		$candidates = array_filter(
			array(
				Options::instance()->get( 'node_path' ),
				'node',
				'/usr/local/bin/node',
				'/usr/bin/node',
				'/opt/homebrew/bin/node',
			)
		);

		foreach ( $candidates as $candidate ) {
			$out = array();
			$rc  = 1;
			exec( escapeshellarg( $candidate ) . ' --version 2>&1', $out, $rc );
			if ( 0 === $rc && ! empty( $out[0] ) && preg_match( '/^v(\d+)\./', $out[0], $m ) && (int) $m[1] >= self::MIN_NODE_MAJOR ) {
				return $candidate;
			}
		}

		return new \WP_Error(
			'ss_deploy_no_node',
			sprintf(
				/* translators: %d: minimum Node.js major version. */
				__( 'Node.js %d or newer was not found. Install it or set the "node_path" option.', 'simply-static' ),
				self::MIN_NODE_MAJOR
			)
		);
	}

	/**
	 * Directory where incremental deploy state is kept between exports.
	 *
	 * @return string
	 */
	public static function state_dir() {
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . 'ss-deploy-state/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// State only holds paths and hashes, but there is no reason to serve it.
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $dir . '.htaccess', "Require all denied\n" );
		}

		return $dir;
	}

	/**
	 * Start the deployer in the background.
	 *
	 * @param array $job Job description (see deployer/src/types.ts Job).
	 * @return array|\WP_Error Run descriptor: pid, log file, offset.
	 */
	public static function start( array $job ) {
		$node = self::find_node();
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$cli = self::cli_path();
		if ( ! is_readable( $cli ) ) {
			return new \WP_Error( 'ss_deploy_no_cli', __( 'The deployer bundle is missing. Run "npm run build" in the deployer folder.', 'simply-static' ) );
		}

		$dir = Util::get_temp_dir();
		wp_mkdir_p( $dir );
		$id       = wp_generate_password( 12, false );
		$job_file = $dir . 'deploy-job-' . $id . '.json';
		$log_file = $dir . 'deploy-log-' . $id . '.ndjson';

		// The job file holds credentials: create it private, and the CLI deletes it after reading.
		$written = file_put_contents( $job_file, wp_json_encode( $job ) );
		if ( false === $written ) {
			return new \WP_Error( 'ss_deploy_job_file', __( 'Could not write the deploy job file.', 'simply-static' ) );
		}
		chmod( $job_file, 0600 );
		touch( $log_file );
		chmod( $log_file, 0600 );

		$inner = escapeshellarg( $node ) . ' ' . escapeshellarg( $cli ) . ' --job ' . escapeshellarg( $job_file )
			. '; echo "{\"type\":\"exit\",\"code\":$?}"';
		$cmd   = 'nohup sh -c ' . escapeshellarg( $inner ) . ' >> ' . escapeshellarg( $log_file ) . ' 2>&1 < /dev/null & echo $!';

		$out = array();
		exec( $cmd, $out );
		$pid = isset( $out[0] ) ? (int) $out[0] : 0;
		if ( $pid <= 0 ) {
			@unlink( $job_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'ss_deploy_spawn', __( 'Could not start the deployer process.', 'simply-static' ) );
		}

		return array(
			'pid'      => $pid,
			'log_file' => $log_file,
			'job_file' => $job_file,
			'offset'   => 0,
			'started'  => time(),
		);
	}

	/**
	 * Read new output since the last poll.
	 *
	 * @param array $run Run descriptor from start().
	 * @return array{run: array, events: array, finished: bool, exit_code: int|null}
	 */
	public static function poll( array $run ) {
		$events    = array();
		$exit_code = null;

		if ( is_readable( $run['log_file'] ) ) {
			$fh = fopen( $run['log_file'], 'r' );
			if ( $fh ) {
				fseek( $fh, (int) $run['offset'] );
				while ( ( $line = fgets( $fh ) ) !== false ) {
					if ( substr( $line, -1 ) !== "\n" ) {
						break; // Partial line; read it on the next poll.
					}
					$run['offset'] += strlen( $line );
					$event          = json_decode( trim( $line ), true );
					if ( ! is_array( $event ) ) {
						// Non-JSON output (e.g. a Node crash trace) is kept as a log line.
						$event = array( 'type' => 'log', 'level' => 'warn', 'message' => trim( $line ) );
					}
					if ( 'exit' === ( $event['type'] ?? '' ) ) {
						$exit_code = (int) $event['code'];
					}
					$events[] = $event;
				}
				fclose( $fh );
			}
		}

		// If the process vanished without writing its exit marker (killed, OOM), treat as failure.
		if ( null === $exit_code && ! self::is_alive( (int) $run['pid'] ) ) {
			clearstatcache();
			$exit_code = -1;
			$events[]  = array( 'type' => 'error', 'message' => __( 'The deployer process stopped unexpectedly.', 'simply-static' ) );
		}

		return array(
			'run'       => $run,
			'events'    => $events,
			'finished'  => null !== $exit_code,
			'exit_code' => $exit_code,
		);
	}

	/**
	 * Terminate a running deployer and remove its files.
	 *
	 * @param array $run Run descriptor.
	 * @return void
	 */
	public static function cleanup( array $run ) {
		if ( ! empty( $run['pid'] ) && self::is_alive( (int) $run['pid'] ) && function_exists( 'posix_kill' ) ) {
			posix_kill( (int) $run['pid'], 15 );
		}
		foreach ( array( 'job_file', 'log_file' ) as $key ) {
			if ( ! empty( $run[ $key ] ) && file_exists( $run[ $key ] ) ) {
				@unlink( $run[ $key ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * @param int $pid Process id.
	 * @return bool
	 */
	protected static function is_alive( $pid ) {
		if ( $pid <= 0 ) {
			return false;
		}
		if ( function_exists( 'posix_kill' ) ) {
			return posix_kill( $pid, 0 );
		}

		return is_dir( '/proc/' . $pid );
	}
}
