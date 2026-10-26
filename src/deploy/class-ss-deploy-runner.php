<?php

namespace WP_Static_Export;

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

	/** Directory (relative to uploads) holding incremental deploy state. */
	const STATE_DIR = 'wp-static-export/deploy-state';

	/**
	 * Keep the state directory out of exports: it lives in uploads, which the crawler scans, and the
	 * files list every deployed path and hash.
	 */
	/** Options holding the descriptor of a running Node job between task calls. */
	const RUN_OPTIONS = array( 'deploy_run', 'process_run' );

	public static function init() {
		// A hard-killed export (PHP crash, worker restart) leaves its run descriptor behind, and the next
		// export would mistake it for its own job. Start every export, and end every export, clean.
		add_action( 'wpse_after_setup_task', array( __CLASS__, 'reset_runs' ) );
		add_action( 'wpse_completed', array( __CLASS__, 'reset_runs' ), 6 );

		add_filter(
			'wpse_skip_crawl_uploads_directories',
			static function ( $dirs ) {
				return array_merge( (array) $dirs, array( self::STATE_DIR ) );
			}
		);
	}

	/**
	 * Stop and forget any Node job recorded by an earlier export.
	 *
	 * @return void
	 */
	public static function reset_runs() {
		$o       = Options::instance();
		$changed = false;
		foreach ( self::RUN_OPTIONS as $key ) {
			$run = $o->get( $key );
			if ( ! empty( $run ) ) {
				if ( is_array( $run ) ) {
					self::cleanup( $run );
				}
				$o->set( $key, null );
				$changed = true;
			}
		}
		if ( $changed ) {
			$o->save();
		}
	}

	/**
	 * Path globs (relative to the export) that must never be deployed even if they end up in it.
	 *
	 * @return string[]
	 */
	public static function ignore_patterns() {
		return apply_filters(
			'wpse_deploy_ignore',
			array(
				'*/' . self::STATE_DIR . '/*',
				'*/wp-static-export/temp-files/*',
				'*/ss-deploy-state/*',
			)
		);
	}

	/**
	 * Path of the bundled CLI.
	 *
	 * @return string
	 */
	public static function cli_path() {
		return apply_filters( 'wpse_deploy_cli_path', WPSE_PATH . 'deployer/dist/cli.cjs' );
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
			return new \WP_Error( 'ss_deploy_no_exec', __( 'PHP exec() is disabled, so the deployer cannot be started.', 'wp-static-export' ) );
		}
		if ( 0 === stripos( PHP_OS, 'WIN' ) ) {
			return new \WP_Error( 'ss_deploy_windows', __( 'Deploying from a Windows server is not supported yet.', 'wp-static-export' ) );
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
				__( 'Node.js %d or newer was not found. Install it or set the "node_path" option.', 'wp-static-export' ),
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
		$dir    = trailingslashit( $upload['basedir'] ) . self::STATE_DIR . '/';
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
			return new \WP_Error( 'ss_deploy_no_cli', __( 'The deployer bundle is missing. Run "npm run build" in the deployer folder.', 'wp-static-export' ) );
		}

		$dir = Util::get_temp_dir();
		wp_mkdir_p( $dir );
		$id       = wp_generate_password( 12, false );
		$job_file = $dir . 'deploy-job-' . $id . '.json';
		$log_file = $dir . 'deploy-log-' . $id . '.ndjson';

		// The job file holds credentials: create it private, and the CLI deletes it after reading.
		$written = file_put_contents( $job_file, wp_json_encode( $job ) );
		if ( false === $written ) {
			return new \WP_Error( 'ss_deploy_job_file', __( 'Could not write the deploy job file.', 'wp-static-export' ) );
		}
		chmod( $job_file, 0600 );
		touch( $log_file );
		chmod( $log_file, 0600 );

		$inner = escapeshellarg( $node ) . ' ' . escapeshellarg( $cli ) . ' --job ' . escapeshellarg( $job_file )
			. '; echo "{\"type\":\"exit\",\"code\":$?}"';
		$cmd   = 'nohup sh -c ' . escapeshellarg( $inner ) . ' >> ' . escapeshellarg( $log_file ) . ' 2>&1 < /dev/null & echo $!';

		Util::debug_log( '[node] starting ' . basename( $cli ) . ' for job ' . ( $job['action'] ?? 'deploy' ) );
		$out = array();
		exec( $cmd, $out );
		$pid = isset( $out[0] ) ? (int) $out[0] : 0;
		if ( $pid <= 0 ) {
			@unlink( $job_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'ss_deploy_spawn', __( 'Could not start the deployer process.', 'wp-static-export' ) );
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
			$events[]  = array( 'type' => 'error', 'message' => __( 'The deployer process stopped unexpectedly.', 'wp-static-export' ) );
		}

		return array(
			'run'       => $run,
			'events'    => $events,
			'finished'  => null !== $exit_code,
			'exit_code' => $exit_code,
		);
	}


	/**
	 * Run a short job and wait for it (used for "test connection").
	 *
	 * @param array $job     Job description.
	 * @param int   $timeout Seconds before the process is killed.
	 * @return array|\WP_Error The "result" event, or an error built from the "error" event.
	 */
	public static function run_sync( array $job, $timeout = 30 ) {
		$node = self::find_node();
		if ( is_wp_error( $node ) ) {
			return $node;
		}
		$cli = self::cli_path();
		if ( ! is_readable( $cli ) ) {
			return new \WP_Error( 'ss_deploy_no_cli', __( 'The deployer bundle is missing. Run "npm run build" in the deployer folder.', 'wp-static-export' ) );
		}

		$dir = Util::get_temp_dir();
		wp_mkdir_p( $dir );
		$job_file = $dir . 'deploy-job-' . wp_generate_password( 12, false ) . '.json';
		file_put_contents( $job_file, wp_json_encode( $job ) );
		chmod( $job_file, 0600 );

		$proc = proc_open(
			array( $node, $cli, '--job', $job_file ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		if ( ! is_resource( $proc ) ) {
			@unlink( $job_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'ss_deploy_spawn', __( 'Could not start the deployer process.', 'wp-static-export' ) );
		}
		stream_set_blocking( $pipes[1], false );

		$buffer   = '';
		$deadline = time() + (int) $timeout;
		while ( time() < $deadline ) {
			$read = array( $pipes[1] );
			$w    = null;
			$e    = null;
			if ( stream_select( $read, $w, $e, 1 ) ) {
				$chunk = fread( $pipes[1], 8192 );
				if ( false === $chunk || ( '' === $chunk && feof( $pipes[1] ) ) ) {
					break;
				}
				$buffer .= $chunk;
			}
		}
		$timed_out = time() >= $deadline;
		if ( $timed_out ) {
			proc_terminate( $proc );
		}
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );
		@unlink( $job_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$result = null;
		$error  = null;
		foreach ( explode( "\n", $buffer ) as $line ) {
			$event = json_decode( trim( $line ), true );
			if ( is_array( $event ) && 'result' === ( $event['type'] ?? '' ) ) {
				$result = $event;
			} elseif ( is_array( $event ) && 'error' === ( $event['type'] ?? '' ) ) {
				$error = (string) $event['message'];
			}
		}

		if ( $timed_out && ! $result ) {
			return new \WP_Error( 'ss_deploy_timeout', __( 'The check timed out.', 'wp-static-export' ) );
		}
		if ( $error || ! $result ) {
			return new \WP_Error( 'ss_deploy_failed', $error ?: __( 'The deployer returned no result.', 'wp-static-export' ) );
		}

		return $result;
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
