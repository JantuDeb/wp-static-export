<?php

namespace WP_Static_Export;

use WP_Static_Export\Engine\Deploy_Error;

/**
 * Runs one of the engine's time-boxed jobs (deploy, process, site check) a step at a time. Tasks using this return
 * `false` while the job is unfinished, so the background queue calls them again.
 */
trait runsEngineJob {

	/**
	 * Seconds one call may spend working.
	 *
	 * @var float
	 */
	protected $engine_budget = 8.0;

	/**
	 * @param string        $option_key Option holding the run descriptor between calls.
	 * @param string        $engine     deploy, process or site.
	 * @param callable      $make_job   function ( bool $starting ) returning the job array or WP_Error. Called on every
	 *                                  step because no credentials are kept in the run.
	 * @param callable|null $on_event   Called with [ done, total, label ] when there is progress.
	 * @return array|\WP_Error ['state' => 'running'] or ['state' => 'done', 'result' => array].
	 */
	protected function advance_engine_job( $option_key, $engine, callable $make_job, callable $on_event = null ) {
		$class = array(
			'deploy'  => '\\WP_Static_Export\\Engine\\Deploy_Run',
			'process' => '\\WP_Static_Export\\Engine\\Process_Run',
			'site'    => '\\WP_Static_Export\\Engine\\Site_Check',
		)[ $engine ];

		$run      = $this->options->get( $option_key );
		$starting = empty( $run ) || ! is_array( $run );
		$job      = $make_job( $starting );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		try {
			if ( $starting ) {
				$run = $class::start( $job );
				$this->options->set( $option_key, $run )->save();
			}
			$step = $class::step( $run, $job, $this->engine_budget );
		} catch ( \Throwable $e ) {
			if ( is_array( $run ) ) {
				$class::cleanup( $run );
			}
			$this->options->set( $option_key, null )->save();
			if ( ! ( $e instanceof Deploy_Error ) ) {
				Util::debug_log( '[engine] ' . get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			}

			return new \WP_Error( 'ss_engine_failed', $e->getMessage() );
		}

		foreach ( (array) ( $step['logs'] ?? array() ) as $log ) {
			Util::debug_log( '[engine] ' . $log[1] );
		}
		if ( $on_event && ! empty( $step['progress'] ) ) {
			$on_event( array( 'done' => $step['progress'][0], 'total' => $step['progress'][1], 'label' => $step['progress'][2] ) );
		}

		if ( 'running' === $step['state'] ) {
			return array( 'state' => 'running' );
		}

		$this->options->set( $option_key, null )->save();

		return array( 'state' => 'done', 'result' => (array) $step['result'] );
	}
}
