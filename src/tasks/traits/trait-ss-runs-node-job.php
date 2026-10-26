<?php

namespace WP_Static_Export;

/**
 * Starts a Node deployer job on the first call and polls it on later calls.
 * Tasks using this return `false` while the job is running so the queue keeps calling them.
 */
trait runsNodeJob {

	/**
	 * Seconds to wait between polls so the background queue doesn't spin.
	 *
	 * @var int
	 */
	protected $node_poll_interval = 2;

	/**
	 * @param string   $option_key Option holding the run descriptor between calls.
	 * @param callable $make_job   Returns the job array or WP_Error; only called when starting.
	 * @param callable $on_event   Optional callback for each progress event.
	 * @return array|\WP_Error ['state' => 'running'] or ['state' => 'done', 'result' => array].
	 */
	protected function advance_node_job( $option_key, callable $make_job, callable $on_event = null ) {
		$run = $this->options->get( $option_key );

		if ( empty( $run ) ) {
			$job = $make_job();
			if ( is_wp_error( $job ) ) {
				return $job;
			}
			$started = Deploy_Runner::start( $job );
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			$this->options->set( $option_key, $started )->save();

			return array( 'state' => 'running' );
		}

		$this->check_if_running();
		sleep( $this->node_poll_interval );

		$polled = Deploy_Runner::poll( $run );
		$result = null;
		$error  = null;

		foreach ( $polled['events'] as $event ) {
			switch ( $event['type'] ?? '' ) {
				case 'log':
					Util::debug_log( '[node] ' . ( $event['message'] ?? '' ) );
					break;
				case 'progress':
					if ( $on_event ) {
						$on_event( $event );
					}
					break;
				case 'result':
					$result = $event;
					break;
				case 'error':
					$error = (string) ( $event['message'] ?? 'Unknown error' );
					break;
			}
		}

		$this->options->set( $option_key, $polled['run'] )->save();

		if ( ! $polled['finished'] ) {
			return array( 'state' => 'running' );
		}

		Deploy_Runner::cleanup( $polled['run'] );
		$this->options->set( $option_key, null )->save();

		if ( 0 !== $polled['exit_code'] || null !== $error ) {
			return new \WP_Error( 'ss_node_job_failed', $error ?: __( 'unknown error', 'wp-static-export' ) );
		}

		return array(
			'state'  => 'done',
			'result' => $result ?: array(),
		);
	}
}
