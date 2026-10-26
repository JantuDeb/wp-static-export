<?php
namespace WP_Static_Export;

class Environments_Integration extends Pro_Integration {

	protected $id = 'environments';

	public function __construct() {
		$this->name = __( 'Environments (Core)', 'wp-static-export' );
		$this->description = __( 'Define multiple environments of WP Static Export so you can easily change between saved configurations.', 'wp-static-export' );
	}

}