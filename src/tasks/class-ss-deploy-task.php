<?php

namespace Simply_Static;

/**
 * Pushes the finished export to the configured remote (GitHub, Netlify, Cloudflare Pages,
 * S3, BunnyCDN, SFTP) using the bundled TypeScript deployer.
 */
class Deploy_Task extends Task {

	use runsNodeJob;

	/**
	 * Task name.
	 *
	 * @var string
	 */
	protected static $task_name = 'deploy';

	/**
	 * @return bool|\WP_Error True when done, false to be called again.
	 */
	public function perform() {
		$method = $this->options->get( 'delivery_method' );

		$step = $this->advance_node_job(
			'deploy_run',
			function () use ( $method ) {
				return $this->build_job( $method );
			},
			function ( $event ) {
				$this->save_status_message(
					sprintf(
						/* translators: 1: done, 2: total, 3: what (files/objects/...) */
						__( 'Uploaded %1$d of %2$d %3$s', 'simply-static' ),
						(int) $event['done'],
						(int) $event['total'],
						(string) ( $event['label'] ?? '' )
					)
				);
			}
		);

		if ( is_wp_error( $step ) ) {
			return new \WP_Error( 'ss_deploy_failed', sprintf( /* translators: %s: error */ __( 'Deploy failed: %s', 'simply-static' ), $step->get_error_message() ) );
		}
		if ( 'running' === $step['state'] ) {
			return false;
		}

		$result  = $step['result'];
		$message = sprintf(
			/* translators: 1: status, 2: uploaded count, 3: deleted count */
			__( '%1$s (%2$d uploaded, %3$d removed)', 'simply-static' ),
			$result['message'] ?? __( 'Deploy finished', 'simply-static' ),
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
	protected function build_job( $method ) {
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
			'scope'       => Process_Config::scope(),
			'force'       => (bool) $this->options->get( 'deploy_force_full' ),
			'concurrency' => (int) apply_filters( 'ss_deploy_concurrency', 8 ),
			'ignore'      => Deploy_Runner::ignore_patterns(),
			'config'      => $config,
		);

		$this->save_status_message( sprintf( /* translators: %s: target */ __( 'Deploying to %s', 'simply-static' ), $method ) );

		return apply_filters( 'ss_deploy_job', $job, $method );
	}
}
