<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A conservative HTML minifier: removes comments and collapses whitespace but keeps one space wherever
 * whitespace could matter between inline elements, and never touches <pre>, <textarea> or script contents
 * (except plain JavaScript and CSS, which are minified).
 */
class Html_Minify {

	const BLOCK = array(
		'html', 'head', 'body', 'title', 'meta', 'link', 'base', 'script', 'style', 'noscript', 'template',
		'div', 'p', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
		'form', 'fieldset', 'legend', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main',
		'figure', 'figcaption', 'hr', 'blockquote', 'address', 'details', 'summary', 'br', 'select', 'option', 'optgroup',
		'picture', 'source', 'video', 'audio', 'canvas', 'iframe', 'svg',
	);

	/**
	 * @param string $html Document or fragment.
	 * @return string
	 */
	public static function minify( $html ) {
		$saved = array();
		$hold  = static function ( $value ) use ( &$saved ) {
			$saved[] = $value;

			return "\x01H" . ( count( $saved ) - 1 ) . "\x01";
		};

		$text = preg_replace_callback(
			'~<!--(?!\[if|<!\[endif|!).*?-->|(<pre\b[^>]*>.*?</pre\s*>)|(<textarea\b[^>]*>.*?</textarea\s*>)|<(script)\b([^>]*)>(.*?)</script\s*>|<(style)\b([^>]*)>(.*?)</style\s*>~is',
			static function ( $m ) use ( $hold ) {
				if ( 0 === strpos( $m[0], '<!--' ) ) {
					return '';
				}
				if ( ! empty( $m[1] ) || ! empty( $m[2] ) ) {
					return $hold( $m[0] );
				}
				if ( ! empty( $m[3] ) ) {
					return $hold( '<script' . $m[4] . '>' . Html_Minify::inline_js( $m[4], $m[5] ) . '</script>' );
				}

				return $hold( '<style' . $m[7] . '>' . Html_Minify::inline_css( $m[8] ) . '</style>' );
			},
			$html
		);
		if ( null === $text ) {
			return $html; // PCRE gave up on a huge page: keep it as is.
		}

		$text = preg_replace( '/[ \t\n\r\f]+/', ' ', $text );

		// Whitespace between two tags goes when either neighbour is a block-level tag; otherwise one space stays.
		$parts = preg_split( '~(<[a-zA-Z/!](?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>|\x01H\d+\x01)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}
		$name = static function ( $token ) {
			if ( 0 === strpos( $token, "\x01H" ) ) {
				return 'script'; // A protected region: whitespace next to it never matters.
			}

			return preg_match( '~^</?([a-zA-Z][a-zA-Z0-9:-]*)~', $token, $m ) ? strtolower( $m[1] ) : '!';
		};
		$out = '';
		$n   = count( $parts );
		for ( $i = 0; $i < $n; $i++ ) {
			$token = $parts[ $i ];
			if ( ' ' === $token && $i > 0 && $i < $n - 1 ) {
				$left  = $name( $parts[ $i - 1 ] );
				$right = $name( $parts[ $i + 1 ] );
				if ( in_array( $left, self::BLOCK, true ) || in_array( $right, self::BLOCK, true ) || '!' === $left || '!' === $right ) {
					continue;
				}
			}
			$out .= $token;
		}
		$text = $out;
		$text = trim( (string) $text );

		return preg_replace_callback(
			'~\x01H(\d+)\x01~',
			static function ( $m ) use ( $saved ) {
				return $saved[ (int) $m[1] ];
			},
			$text
		);
	}

	/**
	 * @param string $attrs   Attributes of the script tag.
	 * @param string $content Script text.
	 * @return string
	 */
	public static function inline_js( $attrs, $content ) {
		if ( '' === trim( $content ) || preg_match( '/\bsrc\s*=/i', $attrs ) ) {
			return $content;
		}
		if ( preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $t ) && ! preg_match( '#^(text|application)/(javascript|ecmascript)$|^module$#i', $t[1] ) ) {
			return $content; // JSON, templates, importmaps and the like.
		}
		if ( Minifiers::looks_minified( $content ) ) {
			return $content;
		}
		try {
			$min = Minifiers::js( $content );

			return '' !== $min && strlen( $min ) < strlen( $content ) ? $min : $content;
		} catch ( \Throwable $e ) {
			return $content;
		}
	}

	/**
	 * @param string $css Style text.
	 * @return string
	 */
	public static function inline_css( $css ) {
		if ( '' === trim( $css ) || Minifiers::looks_minified( $css ) ) {
			return $css;
		}
		try {
			$min = Minifiers::css( $css );

			return '' !== $min && strlen( $min ) < strlen( $css ) ? $min : $css;
		} catch ( \Throwable $e ) {
			return $css;
		}
	}
}
