<?php
namespace Simply_Static;

class Environments_Integration extends Pro_Integration {

	protected $id = 'environments';

	public function __construct() {
		$this->name = __( 'Environments (Core)', 'simply-static' );
		$this->description = __( 'Define multiple environments of WP Static Export so you can easily change between saved configurations.', 'simply-static' );
	}

}