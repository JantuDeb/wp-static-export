<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multilingual export support for WPML, Polylang and TranslatePress.
 *
 * Crawling only follows links, and language plugins filter queries to the current language,
 * so posts in other languages that nothing links to would be missed. This class lists the
 * public URLs of every language and hands them to the crawler.
 */
class Multilingual {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wpse_additional_urls', array( __CLASS__, 'add_language_urls' ) );
		add_filter( 'wpse_after_replace_urls_in_html', array( __CLASS__, 'maybe_add_language_redirect' ), 20, 2 );
	}

	/**
	 * Detect the active language plugin.
	 *
	 * @return array{plugin: string, default: string, languages: array<string,array{name:string,home:string,locale:string}>}|null
	 */
	public static function detect() {
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_home_url' ) ) {
			$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
			$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			$out     = array();
			foreach ( $slugs as $i => $slug ) {
				$out[ $slug ] = array(
					'name'   => (string) ( $names[ $i ] ?? $slug ),
					'home'   => (string) pll_home_url( $slug ),
					'locale' => (string) ( $locales[ $i ] ?? $slug ),
				);
			}

			return self::result( 'polylang', function_exists( 'pll_default_language' ) ? (string) pll_default_language() : (string) key( $out ), $out );
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_active_languages' ) ) {
			$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $active ) && $active ) {
				$out = array();
				foreach ( $active as $code => $lang ) {
					$out[ $code ] = array(
						'name'   => (string) ( $lang['native_name'] ?? $code ),
						'home'   => (string) apply_filters( 'wpml_permalink', home_url( '/' ), $code ),
						'locale' => (string) ( $lang['default_locale'] ?? $code ),
					);
				}

				return self::result( 'wpml', (string) apply_filters( 'wpml_default_language', null ), $out );
			}
		}

		if ( class_exists( '\TRP_Translate_Press' ) ) {
			$settings  = get_option( 'trp_settings', array() );
			$published = (array) ( $settings['publish-languages'] ?? array() );
			$default   = (string) ( $settings['default-language'] ?? '' );
			$slugs     = (array) ( $settings['url-slugs'] ?? array() );
			$out       = array();
			foreach ( $published as $locale ) {
				$slug         = (string) ( $slugs[ $locale ] ?? $locale );
				$out[ $slug ] = array(
					'name'   => $slug,
					'home'   => $locale === $default ? home_url( '/' ) : trailingslashit( home_url( '/' . $slug ) ),
					'locale' => $locale,
				);
			}
			$default_slug = (string) ( $slugs[ $default ] ?? $default );

			return self::result( 'translatepress', $default_slug, $out );
		}

		return null;
	}

	/**
	 * @param string $plugin    Plugin id.
	 * @param string $default   Default language code.
	 * @param array  $languages Languages.
	 * @return array|null
	 */
	protected static function result( $plugin, $default, array $languages ) {
		return $languages ? array(
			'plugin'    => $plugin,
			'default'   => $default,
			'languages' => $languages,
		) : null;
	}

	/**
	 * Add language homes and the content of every language to the crawl.
	 *
	 * @param array $urls URLs gathered so far.
	 * @return array
	 */
	public static function add_language_urls( $urls ) {
		if ( ! Options::instance()->get( 'ml_enabled' ) ) {
			return $urls;
		}
		$info = self::detect();
		if ( ! $info ) {
			return $urls;
		}

		$urls = is_array( $urls ) ? $urls : array();
		foreach ( $info['languages'] as $code => $lang ) {
			$urls[] = $lang['home'];
			foreach ( self::content_urls( $info['plugin'], $code ) as $url ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Permalinks of published content and archives in one language.
	 *
	 * @param string $plugin Plugin id.
	 * @param string $code   Language code.
	 * @return string[]
	 */
	protected static function content_urls( $plugin, $code ) {
		$urls    = array();
		$restore = null;

		if ( 'wpml' === $plugin ) {
			$restore = apply_filters( 'wpml_current_language', null );
			do_action( 'wpml_switch_language', $code );
		}

		$args = array(
			'post_type'        => get_post_types( array( 'public' => true ) ),
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => 'wpml' !== $plugin,
		);
		if ( 'polylang' === $plugin ) {
			$args['lang'] = $code;
			// Polylang filters via query vars even with suppress_filters, so ask for the language explicitly.
			$args['suppress_filters'] = false;
		}

		foreach ( get_posts( $args ) as $id ) {
			$link = get_permalink( $id );
			if ( $link ) {
				$urls[] = $link;
			}
		}

		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_id ) {
				$link = get_term_link( (int) $term_id, $taxonomy );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		if ( 'wpml' === $plugin && $restore ) {
			do_action( 'wpml_switch_language', $restore );
		}

		return $urls;
	}

	/**
	 * On the site root, send visitors to their browser language if a translation exists.
	 * Choosing a language manually (visiting any language URL) disables the redirect.
	 *
	 * @param string      $html Page HTML.
	 * @param object|null $page Static page.
	 * @return string
	 */
	public static function maybe_add_language_redirect( $html, $page ) {
		$options = Options::instance();
		if ( ! $options->get( 'ml_enabled' ) || ! $options->get( 'ml_default_redirect' ) || ! is_object( $page ) ) {
			return $html;
		}
		$path = (string) wp_parse_url( (string) $page->url, PHP_URL_PATH );
		if ( '' !== trim( $path, '/' ) && trim( $path, '/' ) !== trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) ) {
			return $html;
		}
		$info = self::detect();
		if ( ! $info ) {
			return $html;
		}

		$map = array();
		foreach ( $info['languages'] as $code => $lang ) {
			if ( $code === $info['default'] ) {
				continue;
			}
			$map[ strtolower( substr( $lang['locale'], 0, 2 ) ) ] = (string) wp_parse_url( $lang['home'], PHP_URL_PATH );
		}
		if ( ! $map ) {
			return $html;
		}

		$script = '<script>(function(){try{if(sessionStorage.getItem("ss-lang-redirected"))return;sessionStorage.setItem("ss-lang-redirected","1");'
			. 'var m=' . wp_json_encode( $map ) . ',l=(navigator.language||"").slice(0,2).toLowerCase();'
			. 'if(m[l]&&location.pathname!==m[l])location.replace(m[l]);}catch(e){}})();</script>';

		return self::inject_head( $html, $script );
	}

	/**
	 * Insert markup right after <head>.
	 *
	 * @param string $html   HTML.
	 * @param string $markup Markup.
	 * @return string
	 */
	protected static function inject_head( $html, $markup ) {
		$count = 0;
		$out   = preg_replace( '/<head(\s[^>]*)?>/i', '$0' . addcslashes( $markup, '\\$' ), $html, 1, $count );

		return $count ? $out : $html;
	}
}
