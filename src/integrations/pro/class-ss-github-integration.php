<?php
namespace WP_Static_Export;

class Github_Integration extends Pro_Integration {
	protected $id = 'github';

	protected $always_active = true;

	public function __construct() {
		$this->name = __( 'GitHub', 'wp-static-export' );
		$this->description = __( 'Used when deploying exported sites to GitHub.', 'wp-static-export' );
	}
}
