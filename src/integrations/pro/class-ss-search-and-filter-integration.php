<?php
namespace WP_Static_Export;

class SearchAndFilter_Integration extends Pro_Integration {
	/**
	 * A string ID of integration.
	 *
	 * @var string
	 */
	protected $id = 'search-and-filter';

	public function __construct() {
		$this->name        = __( 'Search and Filter', 'wp-static-export' );
		$this->description = __( 'Integrates the popular Search and Filter plugin to be used on static sites.', 'wp-static-export' );
	}

	public function dependency_active() {
		return class_exists( 'Search_Filter' );
	}

}