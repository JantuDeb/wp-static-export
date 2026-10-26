<?php
namespace WP_Static_Export;

class Complianz_Integration extends Pro_Integration {
	/**
	 * A string ID of integration.
	 *
	 * @var string
	 */
	protected $id = 'complianz';

	public function __construct() {
		$this->name        = __( 'Complianz | GDPR/CCPA Cookie Consent', 'wp-static-export' );
		$this->description = __( 'Integrates Complianz Cookie banner to work on the static site.', 'wp-static-export' );
	}

	public function dependency_active() {
		return class_exists( 'COMPLIANZ' );
	}

}