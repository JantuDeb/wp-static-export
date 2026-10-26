<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the search runtime (search.js) with its configuration on every exported page.
 */
class Search_Enhancer {

	/**
	 * @param string      $html HTML.
	 * @param object|null $page Static page.
	 * @return string
	 */
	public static function process( $html, $page ) {
		$o = Options::instance();
		if ( ! $o->get( 'static_search_enabled' ) ) {
			return $html;
		}
		$engine = 'algolia' === $o->get( 'static_search_engine' ) ? 'algolia' : 'fuse';

		$config = array(
			'engine'       => $engine,
			'replaceForms' => (bool) $o->get( 'static_search_replace_forms' ),
		);
		if ( 'algolia' === $engine ) {
			$app = (string) $o->get( 'static_search_algolia_app_id' );
			$key = (string) $o->get( 'static_search_algolia_search_key' );
			$idx = (string) $o->get( 'static_search_algolia_index' );
			if ( '' === $app || '' === $key || '' === $idx ) {
				return $html; // Not configured: don't ship a broken search box.
			}
			$config['algolia'] = array(
				'appId'     => $app,
				'searchKey' => $key,
				'index'     => $idx,
			);
		} else {
			$config['indexUrl'] = Html_Enhancer::asset_url( $page, Html_Enhancer::ASSET_DIR . '/search-index.json' );
		}

		$markup = '<script id="ss-search-config" type="application/json">' . Html_Enhancer::json_for_script( $config ) . '</script>'
			. '<script src="' . esc_url( Html_Enhancer::asset_url( $page, Html_Enhancer::ASSET_DIR . '/search.js' ) ) . '" defer></script>';

		return Html_Enhancer::inject_before_body_end( $html, $markup );
	}
}
