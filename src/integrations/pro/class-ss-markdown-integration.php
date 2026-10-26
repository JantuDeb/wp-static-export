<?php
namespace WP_Static_Export;

class Markdown_Integration extends Pro_Integration {

	protected $id = 'markdown';

	public function __construct() {
		$this->name = __( 'Markdown (Core)', 'wp-static-export' );
		$this->description = __( 'Generates Markdown versions of your pages for optimal AI agent accessibility, adds proper meta tags, and ensures your static site follows AI-readability best practices.', 'wp-static-export' );
	}

}
