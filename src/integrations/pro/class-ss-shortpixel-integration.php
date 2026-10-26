<?php
namespace WP_Static_Export;

class Shortpixel_Integration extends Pro_Integration {

	protected $id = 'shortpixel';

	protected $always_active = true;

	public function __construct() {
		$this->name = __( 'ShortPixel', 'wp-static-export' );
		$this->description = __( 'Optimizes Images before exporting them for static sites.', 'wp-static-export' );
	}

}
