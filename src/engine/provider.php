<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A deployment target.
 */
abstract class Provider {

	/**
	 * @return string Id used in settings.
	 */
	abstract public function id();

	/**
	 * Check the settings and fill in defaults.
	 *
	 * @param array $config Settings.
	 * @return array
	 * @throws Deploy_Error When something is missing or malformed.
	 */
	public function validate( array $config ) {
		return $config;
	}

	/**
	 * A cheap authenticated call proving the credentials work; must not change anything remotely.
	 *
	 * @param array $config Validated settings.
	 * @return string Message for the owner.
	 * @throws Deploy_Error On failure.
	 */
	abstract public function verify( array $config );

	/**
	 * Do some of the deployment. Called repeatedly until it returns true. Keep progress in $ctx->state, and
	 * stop when $ctx->out_of_time(). On completion set $ctx->result.
	 *
	 * @param Context $ctx Context.
	 * @return bool True when the deployment is finished.
	 * @throws Deploy_Error On failure.
	 */
	abstract public function advance( Context $ctx );
}
