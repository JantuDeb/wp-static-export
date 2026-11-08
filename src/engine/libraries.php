<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the third-party libraries the engine uses (the minifier and the SFTP client). In a release they are shipped in
 * vendor-prefixed/ under this plugin's own namespace, so they cannot clash with another plugin that bundles the same
 * libraries; a development checkout falls back to Composer's vendor/.
 */
class Libraries {

	/** @var bool */
	protected static $loaded = false;

	/**
	 * @return void
	 */
	public static function load() {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;
		$root         = dirname( __DIR__, 2 ) . '/';
		foreach ( array( 'vendor-prefixed/autoload.php', 'vendor/autoload.php' ) as $file ) {
			if ( is_readable( $root . $file ) ) {
				require_once $root . $file;

				return;
			}
		}
	}
}
