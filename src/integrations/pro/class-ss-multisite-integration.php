<?php
namespace WP_Static_Export;

class Multisite_Integration extends Pro_Integration {
	/**
	 * A string ID of integration.
	 *
	 * @var string
	 */
	protected $id = 'multisite';

	public function __construct() {
		$this->name = __( 'Multisite (Core)', 'wp-static-export' );
		$this->description = __( 'Allows queued multisite exports and management through network dashboard.', 'wp-static-export' );
		// Making sure this shows onc Integrations screen if only free plugin is there.
		$this->always_active = defined( 'WPSE_PRO_VERSION' ) ? true : false;
	}

	public function dependency_active() {
		return is_multisite();
	}

}