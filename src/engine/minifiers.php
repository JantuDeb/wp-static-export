<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSS and JavaScript minification with matthiasmullie/minify (also used by popular caching plugins).
 */
class Minifiers {

	/**
	 * @param string $name CSS or JS.
	 * @return string Class name of the library, looking for the prefixed copy shipped with the plugin first.
	 * @throws Deploy_Error If the library is missing.
	 */
	protected static function library( $name ) {
		Libraries::load();
		foreach ( array( 'WP_Static_Export\\Vendor\\MatthiasMullie\\Minify\\', 'MatthiasMullie\\Minify\\' ) as $ns ) {
			if ( class_exists( $ns . $name ) ) {
				return $ns . $name;
			}
		}
		throw new Deploy_Error( 'The minifier library is not installed.' );
	}

	/**
	 * @param string $css CSS.
	 * @return string
	 */
	public static function css( $css ) {
		$class = self::library( 'CSS' );
		$min   = new $class( $css );
		// Never read other files from disk: inputs are plain strings, so nothing may be inlined.
		$min->setMaxImportSize( 0 );
		$min->setImportExtensions( array() );

		return (string) $min->minify();
	}

	/**
	 * @param string $js JavaScript.
	 * @return string
	 */
	public static function js( $js ) {
		$class = self::library( 'JS' );
		$min   = new $class( $js );

		return (string) $min->minify();
	}

	/**
	 * Minifiers are not idempotent: minifying their own output changes it again (and then the file is
	 * re-uploaded on every deploy). The state file that normally prevents this lives in the temp folder, which
	 * other parts of the plugin clear, so also recognize minified output by its shape: few, very long lines.
	 *
	 * @param string $text Source or minified text.
	 * @return bool
	 */
	public static function looks_minified( $text ) {
		// Characters, not bytes: a page in Hindi or Chinese is two to three times longer in bytes.
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		if ( $len < 400 ) {
			return false;
		}

		return $len / ( substr_count( $text, "\n" ) + 1 ) > 250;
	}
}
