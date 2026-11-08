<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the engine's classes on demand: WP_Static_Export\Engine\Provider_Cloudflare_Pages lives in
 * provider-cloudflare-pages.php. A few small classes share their file with the one that uses them.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WP_Static_Export\\Engine\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$name   = substr( $class, strlen( $prefix ) );
		$shared = array(
			'Request'      => 'http',
			'Response'     => 'http',
			'Transport'    => 'http',
			'Wp_Transport' => 'http',
		);
		$file   = $shared[ $name ] ?? strtolower( str_replace( '_', '-', $name ) );
		$path   = __DIR__ . '/' . $file . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
