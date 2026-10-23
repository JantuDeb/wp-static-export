<?php

namespace Simply_Static;

/**
 * Pushes the finished export to the configured remote (GitHub, Netlify, Cloudflare Pages,
 * S3, BunnyCDN, SFTP) using the bundled TypeScript deployer.
 */
class Deploy_Task extends Task {

	/**
	 * Task name.
	 *
	 * @var string
	 */
	protected static $task_name = 'deploy';

	/**
	 * Seconds to wait between polls so the background queue doesn't spin.
	 */
	const POLL_INTERVAL = 2;

	/**
	 * @return bool|\WP_Error True when done, false to be called again.
	 */
	public function perform() {
		$method = $this->options->get( 'delivery_method' );
		$run    = $this->options->get( 'deploy_run' );

		if ( empty( $run ) ) {
			$started = $this->start( $method );
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			$this->options->set( 'deploy_run', $started )->save();

			return false;
		}

		$this->check_if_running();
		sleep( self::POLL_INTERVAL );

		$polled = Deploy_Runner::poll( $run );
		$result = null;
		$error  = null;

		foreach ( $polled['events'] as $event ) {
			switch ( $event['type'] ?? '' ) {
				case 'log':
					Util::debug_log( '[deploy] ' . ( $event['message'] ?? '' ) );
					break;
				case 'progress':
					$this->save_status_message(
						sprintf(
							/* translators: 1: done, 2: total, 3: what (files/objects/...) */
							__( 'Uploaded %1$d of %2$d %3$s', 'simply-static' ),
							(int) $event['done'],
							(int) $event['total'],
							(string) ( $event['label'] ?? '' )
						)
					);
					break;
				case 'result':
					$result = $event;
					break;
				case 'error':
					$error = (string) ( $event['message'] ?? 'Unknown error' );
					break;
			}
		}

		$this->options->set( 'deploy_run', $polled['run'] )->save();

		if ( ! $polled['finished'] ) {
			return false;
		}

		Deploy_Runner::cleanup( $polled['run'] );
		$this->options->set( 'deploy_run', null )->save();

		if ( 0 !== $polled['exit_code'] || null !== $error ) {
			return new \WP_Error( 'ss_deploy_failed', sprintf( /* translators: %s: error */ __( 'Deploy failed: %s', 'simply-static' ), $error ?: __( 'unknown error', 'simply-static' ) ) );
		}

		$message = $result['message'] ?? __( 'Deploy finished', 'simply-static' );
		$message = sprintf(
			/* translators: 1: status, 2: uploaded count, 3: deleted count */
			__( '%1$s (%2$d uploaded, %3$d removed)', 'simply-static' ),
			$message,
			(int) ( $result['uploaded'] ?? 0 ),
			(int) ( $result['deleted'] ?? 0 )
		);
		$link = ! empty( $result['url'] ) ? array( 'url' => esc_url_raw( $result['url'] ), 'label' => __( 'View deployment', 'simply-static' ) ) : null;
		$this->save_status_message( $message, null, $link );

		do_action( 'ss_after_deploy', $method, $result );

		return true;
	}

	/**
	 * @param string $method Delivery method.
	 * @return array|\WP_Error
	 */
	protected function start( $method ) {
		if ( ! Deploy_Config::is_deploy_method( $method ) ) {
			return new \WP_Error( 'ss_deploy_method', sprintf( /* translators: %s: method */ __( 'Unknown deploy target "%s".', 'simply-static' ), $method ) );
		}

		$archive_dir = rtrim( $this->options->get_archive_dir(), '/\\' );
		if ( ! is_dir( $archive_dir ) ) {
			return new \WP_Error( 'ss_deploy_no_archive', __( 'There is no exported site to deploy.', 'simply-static' ) );
		}

		$config = Deploy_Config::build( $method, $this->options );
		$job    = array(
			'provider'    => $method,
			'dir'         => $archive_dir,
			'stateFile'   => Deploy_Runner::state_dir() . Deploy_Config::target_key( $method, $config ) . '.json',
			'scope'       => $this->is_full_export() ? 'full' : 'partial',
			'force'       => (bool) $this->options->get( 'deploy_force_full' ),
			'concurrency' => (int) apply_filters( 'ss_deploy_concurrency', 8 ),
			'config'      => $config,
		);

		$this->save_status_message( sprintf( /* translators: %s: target */ __( 'Deploying to %s', 'simply-static' ), $method ) );

		return Deploy_Runner::start( apply_filters( 'ss_deploy_job', $job, $method ) );
	}

	/**
	 * A full export contains the whole site, so files missing from it may be removed remotely.
	 * Single, build, update and 404-only exports contain a subset and must never delete.
	 *
	 * @return bool
	 */
	protected function is_full_export() {
		$type = $this->options->get( 'generate_type' ) ?: 'export';

		return 'export' === $type
			&& ! get_option( 'simply-static-use-single' )
			&& ! get_option( 'simply-static-use-build' )
			&& ! get_option( 'simply-static-404-only' );
	}
}
