<?php

namespace WP_Static_Export;

/**
 * Post-processes the exported files before delivery: copies the browser scripts for forms and
 * search, then minifies, compresses images, applies Lighthouse fixes and builds/pushes the search index.
 */
class Process_Task extends Task {

	use runsEngineJob;

	/**
	 * Task name.
	 *
	 * @var string
	 */
	protected static $task_name = 'process';

	/**
	 * @return bool|\WP_Error
	 */
	public function perform() {
		$archive_dir = rtrim( $this->options->get_archive_dir(), '/\\' );

		// Plain file copies are idempotent, so do them on every call before the job starts.
		if ( empty( $this->options->get( 'process_run' ) ) ) {
			$copied = Process_Config::copy_assets( $archive_dir );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
			$this->options->set( 'process_extra_files', $copied )->save();
		}

		$ops = Process_Config::ops( $this->options );
		if ( ! $ops ) {
			return true;
		}

		$step = $this->advance_engine_job(
			'process_run',
			'process',
			function ( $starting ) use ( $archive_dir, $ops ) {
				if ( $starting ) {
					$this->save_status_message( __( 'Optimizing files and building search index', 'wp-static-export' ) );
				}

				return array(
					'dir'    => $archive_dir,
					'scope'  => Process_Config::scope(),
					'ops'    => $ops,
					'runDir' => Deploy_Runner::state_dir(),
				);
			},
			function ( $event ) {
				static $last = 0;
				if ( microtime( true ) - $last < 1 ) {
					return;
				}
				$last = microtime( true );
				/* translators: 1: done, 2: total, 3: what */
				$this->save_status_message( sprintf( __( 'Processed %1$d of %2$d %3$s', 'wp-static-export' ), (int) $event['done'], (int) $event['total'], (string) $event['label'] ) );
			}
		);

		if ( is_wp_error( $step ) ) {
			return new \WP_Error( 'ss_process_failed', sprintf( /* translators: %s: error */ __( 'Processing failed: %s', 'wp-static-export' ), $step->get_error_message() ) );
		}
		if ( 'running' === $step['state'] ) {
			return false;
		}

		$created = array_merge( (array) $this->options->get( 'process_extra_files' ), (array) ( $step['result']['created'] ?? array() ) );
		$this->options->set( 'process_extra_files', array_values( array_unique( $created ) ) )->save();
		$this->save_status_message( (string) ( $step['result']['message'] ?? __( 'Processing finished', 'wp-static-export' ) ) );

		return true;
	}
}
