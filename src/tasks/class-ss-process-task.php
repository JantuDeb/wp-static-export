<?php

namespace Simply_Static;

/**
 * Post-processes the exported files before delivery: copies the browser scripts for forms and
 * search, then (with Node) minifies, compresses images and builds/pushes the search index.
 */
class Process_Task extends Task {

	use runsNodeJob;

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

		// Plain file copies need no Node and are idempotent, so do them on every call before the job starts.
		if ( empty( $this->options->get( 'process_run' ) ) ) {
			$copied = Process_Config::copy_assets( $archive_dir );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
			$this->options->set( 'process_extra_files', $copied )->save();
		}

		$ops = Process_Config::node_ops( $this->options );
		if ( ! $ops ) {
			return true;
		}

		$step = $this->advance_node_job(
			'process_run',
			function () use ( $archive_dir, $ops ) {
				$this->save_status_message( __( 'Optimizing files and building search index', 'simply-static' ) );

				return array(
					'action' => 'process',
					'dir'    => $archive_dir,
					'scope'  => Process_Config::scope(),
					'ops'    => $ops,
					'config' => new \stdClass(),
				);
			}
		);

		if ( is_wp_error( $step ) ) {
			return new \WP_Error( 'ss_process_failed', sprintf( /* translators: %s: error */ __( 'Processing failed: %s', 'simply-static' ), $step->get_error_message() ) );
		}
		if ( 'running' === $step['state'] ) {
			return false;
		}

		$created = array_merge( (array) $this->options->get( 'process_extra_files' ), (array) ( $step['result']['created'] ?? array() ) );
		$this->options->set( 'process_extra_files', array_values( array_unique( $created ) ) )->save();
		$this->save_status_message( (string) ( $step['result']['message'] ?? __( 'Processing finished', 'simply-static' ) ) );

		return true;
	}
}
