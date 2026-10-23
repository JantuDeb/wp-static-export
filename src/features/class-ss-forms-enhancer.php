<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detaches forms from WordPress and marks them for the browser-side forms.js runtime.
 */
class Forms_Enhancer {

	/**
	 * @param string      $html HTML.
	 * @param object|null $page Static page.
	 * @return string
	 */
	public static function process( $html, $page ) {
		$o = Options::instance();
		if ( ! $o->get( 'forms_enabled' ) ) {
			return $html;
		}
		$rules = $o->get( 'forms_rules' );
		if ( ! is_array( $rules ) || ! $rules || false === stripos( $html, '<form' ) ) {
			return $html;
		}

		$found   = 0;
		$counter = 0;
		$html    = preg_replace_callback(
			'/<form\b[^>]*>/i',
			function ( $m ) use ( $rules, &$found, &$counter ) {
				$attrs = Html_Enhancer::parse_attributes( $m[0] );
				if ( self::should_skip( $attrs ) ) {
					return $m[0];
				}
				$index = self::match_rule( $attrs, $rules );
				if ( null === $index ) {
					return $m[0];
				}
				++$found;
				++$counter;

				$name = ! empty( $attrs['id'] ) ? $attrs['id'] : 'form-' . $index . '-' . $counter;
				unset( $attrs['action'], $attrs['target'] );
				$attrs['method']       = 'post';
				$attrs['data-ss-form'] = (string) $index;
				$attrs['data-ss-name'] = $name;

				$extra = '';
				if ( 'netlify' === ( $rules[ $index ]['provider'] ?? '' ) ) {
					// Netlify discovers forms by parsing the deployed HTML.
					$attrs['name']         = $name;
					$attrs['data-netlify'] = 'true';
					$extra                 = '<input type="hidden" name="form-name" value="' . esc_attr( $name ) . '">';
				}

				return Html_Enhancer::build_tag( 'form', $attrs ) . $extra;
			},
			$html
		);

		if ( ! $found ) {
			return $html;
		}

		$public = array_map(
			static function ( $r ) {
				return array(
					'provider'        => (string) ( $r['provider'] ?? '' ),
					'endpoint'        => (string) ( $r['endpoint'] ?? '' ),
					'fields'          => (string) ( $r['fields'] ?? '' ),
					'success_message' => (string) ( $r['success_message'] ?? '' ),
				);
			},
			$rules
		);

		$markup = '<script id="ss-forms-config" type="application/json">' . Html_Enhancer::json_for_script( array( 'rules' => $public ) ) . '</script>'
			. '<script src="' . esc_url( Html_Enhancer::asset_url( $page, Html_Enhancer::ASSET_DIR . '/forms.js' ) ) . '" defer></script>';

		return Html_Enhancer::inject_before_body_end( $html, $markup );
	}

	/**
	 * Forms that have their own handling (search, comments, login) or opted out.
	 *
	 * @param array $attrs Form attributes.
	 * @return bool
	 */
	public static function should_skip( array $attrs ) {
		if ( array_key_exists( 'data-ss-skip', $attrs ) || array_key_exists( 'data-ss-form', $attrs ) ) {
			return true;
		}
		$class  = ' ' . strtolower( (string) ( $attrs['class'] ?? '' ) ) . ' ';
		$action = strtolower( (string) ( $attrs['action'] ?? '' ) );
		$id     = strtolower( (string) ( $attrs['id'] ?? '' ) );

		return 'search' === ( $attrs['role'] ?? '' )
			|| false !== strpos( $class, ' search-form ' )
			|| false !== strpos( $class, ' comment-form ' )
			|| 'commentform' === $id
			|| 'loginform' === $id
			|| false !== strpos( $action, 'wp-comments-post' )
			|| false !== strpos( $action, 'wp-login' )
			|| false !== strpos( $action, '?s=' );
	}

	/**
	 * Index of the first rule matching a form, or null.
	 *
	 * Match syntax: "*" (any), "#id", ".class", a bare id/class, or a plugin shortcut:
	 * cf7, wpforms, gravityforms, fluentform.
	 *
	 * @param array $attrs Form attributes.
	 * @param array $rules Rules.
	 * @return int|null
	 */
	public static function match_rule( array $attrs, array $rules ) {
		$id      = (string) ( $attrs['id'] ?? '' );
		$classes = preg_split( '/\s+/', trim( (string) ( $attrs['class'] ?? '' ) ) );

		foreach ( $rules as $index => $rule ) {
			$match = trim( (string) ( $rule['match'] ?? '*' ) );
			if ( '' === $match || '*' === $match ) {
				return (int) $index;
			}
			switch ( strtolower( $match ) ) {
				case 'cf7':
					$hit = in_array( 'wpcf7-form', $classes, true );
					break;
				case 'wpforms':
					$hit = in_array( 'wpforms-form', $classes, true );
					break;
				case 'gravityforms':
					$hit = 0 === strpos( $id, 'gform_' );
					break;
				case 'fluentform':
					$hit = in_array( 'frm-fluent-form', $classes, true );
					break;
				default:
					$bare = ltrim( $match, '#.' );
					if ( '#' === $match[0] ) {
						$hit = $id === $bare;
					} elseif ( '.' === $match[0] ) {
						$hit = in_array( $bare, $classes, true );
					} else {
						$hit = $id === $bare || in_array( $bare, $classes, true );
					}
			}
			if ( $hit ) {
				return (int) $index;
			}
		}

		return null;
	}
}
