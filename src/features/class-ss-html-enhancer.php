<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the forms / comments / search features to each exported HTML page.
 *
 * Runs on `ss_after_replace_urls_in_html`, i.e. after URLs were rewritten, so the markup
 * added here must already use final URLs (see asset_url()).
 */
class Html_Enhancer {

	/** Directory inside the export that holds the browser scripts and search index. */
	const ASSET_DIR = 'ss-assets';

	public static function init() {
		add_filter( 'ss_after_replace_urls_in_html', array( __CLASS__, 'enhance' ), 15, 2 );
	}

	/**
	 * @param string      $html HTML of the page.
	 * @param object|null $page Static page record.
	 * @return string
	 */
	public static function enhance( $html, $page = null ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<html' ) ) {
			return $html;
		}

		$html = Forms_Enhancer::process( $html, $page );
		$html = Comments_Enhancer::process( $html );
		$html = Search_Enhancer::process( $html, $page );

		return $html;
	}

	/**
	 * URL of a file in the export as the page should reference it.
	 *
	 * @param object|null $page Static page (needed for relative "offline" URLs).
	 * @param string      $path Path inside the export, e.g. "ss-assets/forms.js".
	 * @return string
	 */
	public static function asset_url( $page, $path ) {
		$o    = Options::instance();
		$path = ltrim( $path, '/' );

		switch ( $o->get( 'destination_url_type' ) ) {
			case 'absolute':
				return untrailingslashit( $o->get( 'destination_scheme' ) . $o->get( 'destination_host' ) ) . '/' . $path;
			case 'relative':
				return untrailingslashit( (string) $o->get( 'relative_path' ) ) . '/' . $path;
		}

		// Offline use: climb from the page's directory to the export root.
		$depth = 0;
		if ( is_object( $page ) && ! empty( $page->url ) ) {
			$page_path = (string) wp_parse_url( $page->url, PHP_URL_PATH );
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '' !== $home_path && 0 === strpos( $page_path, $home_path ) ) {
				$page_path = substr( $page_path, strlen( $home_path ) );
			}
			$segments = array_values( array_filter( explode( '/', trim( $page_path, '/' ) ), 'strlen' ) );
			// "a/b/" and "a/b" (no extension) are directories served as a/b/index.html; "a/b.html" is a file.
			$last_is_file = ! empty( $segments ) && false !== strpos( (string) end( $segments ), '.' ) && '/' !== substr( $page_path, -1 );
			$depth        = count( $segments ) - ( $last_is_file ? 1 : 0 );
		}

		return ( $depth > 0 ? str_repeat( '../', $depth ) : './' ) . $path;
	}

	/**
	 * Insert markup before the closing </body>.
	 *
	 * @param string $html   HTML.
	 * @param string $markup Markup.
	 * @return string
	 */
	public static function inject_before_body_end( $html, $markup ) {
		$pos = strripos( $html, '</body>' );

		return false === $pos ? $html . $markup : substr_replace( $html, $markup, $pos, 0 );
	}

	/**
	 * JSON that is safe inside a <script> element.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function json_for_script( $data ) {
		return wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Parse the attributes of a single start tag.
	 *
	 * @param string $tag Start tag, e.g. <form id="a" class="b">.
	 * @return array<string,string|null> Lowercase name => value (null for boolean attributes).
	 */
	public static function parse_attributes( $tag ) {
		$attrs = array();
		$inner = preg_replace( '/^<\s*[a-zA-Z0-9]+|\/?>$/', '', trim( $tag ) );
		if ( preg_match_all( '/([^\s"\'=<>\/]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/', (string) $inner, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $set ) {
				$name = strtolower( $set[1] );
				if ( isset( $set[4] ) && '' !== $set[4] ) {
					$value = $set[4];
				} elseif ( isset( $set[3] ) && '' !== $set[3] ) {
					$value = $set[3];
				} elseif ( isset( $set[2] ) ) {
					$value = $set[2];
				} else {
					$value = null;
				}
				$attrs[ $name ] = null === $value ? null : html_entity_decode( $value, ENT_QUOTES );
			}
		}

		return $attrs;
	}

	/**
	 * Build a start tag from attributes.
	 *
	 * @param string $name  Tag name.
	 * @param array  $attrs Attributes.
	 * @return string
	 */
	public static function build_tag( $name, array $attrs ) {
		$out = '<' . $name;
		foreach ( $attrs as $key => $value ) {
			$out .= null === $value ? ' ' . $key : ' ' . $key . '="' . esc_attr( $value ) . '"';
		}

		return $out . '>';
	}

	/**
	 * Find the extent of the element whose start tag matches $start_pattern, balancing nested same-name tags.
	 *
	 * @param string $html          HTML.
	 * @param string $start_pattern Regex matching the element's start tag.
	 * @param string $tag           Tag name, e.g. "div".
	 * @return array{0:int,1:int}|null Start offset and end offset (exclusive).
	 */
	public static function find_element( $html, $start_pattern, $tag = 'div' ) {
		if ( ! preg_match( $start_pattern, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = $m[0][1];
		$pos   = $start + strlen( $m[0][0] );
		$depth = 1;
		$re    = '#<(/?)' . preg_quote( $tag, '#' ) . '\b[^>]*>#i';
		while ( $depth > 0 && preg_match( $re, $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
			$depth += '/' === $t[1][0] ? -1 : 1;
			$pos    = $t[0][1] + strlen( $t[0][0] );
		}

		return 0 === $depth ? array( $start, $pos ) : null;
	}
}
