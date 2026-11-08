<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatic fixes for what Lighthouse reports, applied to the exported HTML and CSS. Each option maps to the
 * audit(s) it addresses. Edits are surgical (attributes are inserted, nothing is re-serialized) so markup that
 * is already fine stays byte-identical.
 */
class Fixes {

	// ---- tag helpers -------------------------------------------------------------------------

	/**
	 * @param string $name Tag name.
	 * @return string Regex matching an opening tag.
	 */
	protected static function tag_re( $name ) {
		return '~<' . $name . '\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i';
	}

	/**
	 * @param string $tag  Opening tag.
	 * @param string $name Attribute.
	 * @return string|null Value, '' for a bare attribute, null when absent.
	 */
	public static function attr( $tag, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m, PREG_UNMATCHED_AS_NULL ) ) {
			return $m[1] ?? $m[2] ?? $m[3] ?? '';
		}

		return preg_match( '/\s' . preg_quote( $name, '/' ) . '(?=[\s\/>])/i', $tag ) ? '' : null;
	}

	/**
	 * Insert attributes just before the tag's closing `>` or `/>`.
	 *
	 * @param string $tag   Tag.
	 * @param string $attrs Attributes to add.
	 * @return string
	 */
	protected static function add_attrs( $tag, $attrs ) {
		return preg_replace_callback(
			'/\s*(\/?)>$/',
			static function ( $m ) use ( $attrs ) {
				return ' ' . $attrs . $m[1] . '>';
			},
			$tag,
			1
		);
	}

	/**
	 * @param string $s Text.
	 * @return string Escaped for an attribute value.
	 */
	protected static function esc( $s ) {
		return str_replace( array( '&', '"', '<', '>' ), array( '&amp;', '&quot;', '&lt;', '&gt;' ), $s );
	}

	/**
	 * preg_replace_callback that keeps the input when PCRE gives up (huge pages), instead of returning null.
	 *
	 * @param string   $pattern Regex.
	 * @param callable $cb      Callback.
	 * @param string   $subject Subject.
	 * @return string
	 */
	protected static function replace( $pattern, $cb, $subject ) {
		$out = preg_replace_callback( $pattern, $cb, $subject );

		return null === $out ? $subject : $out;
	}

	/**
	 * Hide regions whose contents must never be edited.
	 *
	 * @param string $html HTML.
	 * @return array [ text with placeholders, saved regions ]
	 */
	protected static function protect( $html ) {
		$saved = array();
		$text  = self::replace(
			'~<!--.*?-->|<(script|style|textarea|template|noscript)\b.*?</\1\s*>~is',
			static function ( $m ) use ( &$saved ) {
				$saved[] = $m[0];

				return "\x01P" . ( count( $saved ) - 1 ) . "\x01";
			},
			$html
		);

		return array( $text, $saved );
	}

	/**
	 * @param string   $text  Text with placeholders.
	 * @param string[] $saved Regions.
	 * @return string
	 */
	protected static function restore( $text, array $saved ) {
		return self::replace(
			'~\x01P(\d+)\x01~',
			static function ( $m ) use ( $saved ) {
				return $saved[ (int) $m[1] ];
			},
			$text
		);
	}

	// ---- CSS ---------------------------------------------------------------------------------

	/**
	 * @param string $css CSS.
	 * @return array [ css, count ]
	 */
	public static function font_display( $css ) {
		$count = 0;
		$out   = self::replace(
			'/@font-face\s*\{[^}]*\}/i',
			static function ( $m ) use ( &$count ) {
				if ( preg_match( '/font-display\s*:/i', $m[0] ) ) {
					return $m[0];
				}
				++$count;

				return preg_replace( '/;?\s*\}$/', ';font-display:swap}', $m[0] );
			},
			$css
		);

		return array( $out, $count );
	}

	// ---- HTML --------------------------------------------------------------------------------

	/**
	 * @param string $html HTML.
	 * @return string|null First paragraph of real text.
	 */
	protected static function first_paragraph( $html ) {
		$dom = self::dom( $html );
		if ( ! $dom ) {
			return null;
		}
		$xp    = new \DOMXPath( $dom );
		$scope = null;
		foreach ( array( '//main', '//article', '//body' ) as $q ) {
			$nodes = $xp->query( $q );
			if ( $nodes && $nodes->length ) {
				$scope = $nodes->item( 0 );
				break;
			}
		}
		if ( ! $scope ) {
			return null;
		}
		foreach ( $xp->query( './/p', $scope ) as $p ) {
			$text = trim( preg_replace( '/\s+/u', ' ', $p->textContent ) );
			if ( mb_strlen( $text ) >= 50 ) {
				return $text;
			}
		}

		return null;
	}

	/**
	 * @param string $html HTML.
	 * @return \DOMDocument|null
	 */
	public static function dom( $html ) {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$ok   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $ok ? $dom : null;
	}

	/**
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	protected static function truncate( $text, $max = 155 ) {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		$at  = mb_strrpos( $cut, ' ' );

		return preg_replace( '/[\s,;:.-]+$/u', '', mb_substr( $cut, 0, max( false === $at ? 0 : $at, 100 ) ) ) . '…';
	}

	/**
	 * "About us – My Site" becomes "About us". Titles without a separator are left alone.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function clean_title( $title ) {
		$t     = trim( preg_replace( '/\s+/u', ' ', $title ) );
		$parts = preg_split( '/\s+[–—|·-]\s+/u', $t );

		return trim( '' !== $parts[0] && mb_strlen( $parts[0] ) > 2 ? $parts[0] : $t );
	}

	/**
	 * Export file that serves an internal link target, e.g. "/blog/x/#c" becomes "blog/x/index.html".
	 *
	 * @param string $href      Link.
	 * @param string $page_path Page path.
	 * @return string|null
	 */
	public static function href_to_file( $href, $page_path ) {
		$base = explode( '#', $href )[0];
		$site = Paths::resolve_site_path( $base, $page_path );
		if ( null === $site || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) || 0 === strpos( $href, '//' ) ) {
			return null;
		}
		if ( '' === $site || '/' === substr( $site, -1 ) ) {
			return $site . 'index.html';
		}

		return preg_match( '/\.[a-z0-9]+$/i', $site ) ? $site : $site . '/index.html';
	}

	/**
	 * @param string $input HTML.
	 * @param array  $ctx   pagePath, sizeOf (callable path => [w,h]|null), titleOf (callable file => string|null).
	 * @param array  $opts  Fix options (viewport, lang, metaDescription, noopener, imageAlt, lazyImages, linkNames, imageDimensions, fontDisplay).
	 * @return array [ html, counts ]
	 */
	public static function fix_html( $input, array $ctx, array $opts ) {
		$counts = array();
		$bump   = static function ( $k, $n = 1 ) use ( &$counts ) {
			if ( $n > 0 ) {
				$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + $n;
			}
		};
		if ( ! preg_match( '/<html[\s>]/i', $input ) ) {
			return array( $input, $counts );
		}

		// <style> blocks are CSS; fix them before they get hidden.
		$html = $input;
		if ( ! empty( $opts['fontDisplay'] ) ) {
			$html = self::replace(
				'~(<style\b[^>]*>)(.*?)(</style\s*>)~is',
				static function ( $m ) use ( $bump ) {
					list( $css, $n ) = Fixes::font_display( $m[2] );
					$bump( 'fontDisplay', $n );

					return $m[1] . $css . $m[3];
				},
				$html
			);
		}

		$robots = (bool) preg_match( '/<meta\b[^>]*name=["\']robots["\'][^>]*content=["\'][^"\']*noindex/i', $html );
		list( $text, $saved ) = self::protect( $html );

		if ( ! empty( $opts['lang'] ) ) {
			$text = preg_replace_callback(
				'~<html\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i',
				static function ( $m ) use ( $opts, $bump ) {
					if ( null !== Fixes::attr( $m[0], 'lang' ) ) {
						return $m[0];
					}
					$bump( 'lang' );

					return Fixes::add_attrs_public( $m[0], 'lang="' . Fixes::esc_public( $opts['lang'] ) . '"' );
				},
				$text,
				1
			);
		}

		if ( ! empty( $opts['viewport'] ) && ! preg_match( '/<meta\b[^>]*name=["\']viewport["\']/i', self::restore( $text, $saved ) ) ) {
			$text = preg_replace_callback(
				'/<head\b[^>]*>/i',
				static function ( $m ) use ( $bump ) {
					$bump( 'viewport' );

					return $m[0] . '<meta name="viewport" content="width=device-width, initial-scale=1">';
				},
				$text,
				1
			);
		}

		if ( ! empty( $opts['metaDescription'] ) && ! $robots && ! preg_match( '/<meta\b[^>]*name=["\']description["\']/i', self::restore( $text, $saved ) ) ) {
			$p = self::first_paragraph( self::restore( $text, $saved ) );
			if ( $p ) {
				$text = preg_replace_callback(
					'~</head\s*>~i',
					static function ( $m ) use ( $p, $bump ) {
						$bump( 'metaDescription' );

						return '<meta name="description" content="' . Fixes::esc_public( Fixes::truncate_public( $p ) ) . '">' . $m[0];
					},
					$text,
					1
				);
			}
		}

		if ( ! empty( $opts['noopener'] ) ) {
			$text = self::replace(
				self::tag_re( 'a' ),
				static function ( $m ) use ( $bump ) {
					$tag = $m[0];
					if ( ! preg_match( '/^_blank$/i', (string) Fixes::attr( $tag, 'target' ) ) ) {
						return $tag;
					}
					$rel = Fixes::attr( $tag, 'rel' );
					if ( null !== $rel && preg_match( '/\b(noopener|noreferrer)\b/i', $rel ) ) {
						return $tag;
					}
					$bump( 'noopener' );
					if ( null === $rel ) {
						return Fixes::add_attrs_public( $tag, 'rel="noopener"' );
					}

					return preg_replace_callback(
						'/(\srel\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/i',
						static function ( $r ) {
							$v = trim( ( isset( $r[3] ) && '' !== $r[3] ) ? $r[3] : ( $r[2] ?? '' ) );

							return $r[1] . '"' . ( '' === $v ? 'noopener' : $v . ' noopener' ) . '"';
						},
						$tag,
						1
					);
				},
				$text
			);
		}

		if ( ! empty( $opts['linkNames'] ) && ! empty( $ctx['titleOf'] ) ) {
			$text = self::replace(
				'~<a\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>(.*?)</a\s*>~is',
				static function ( $m ) use ( $ctx, $bump ) {
					$whole = $m[0];
					$inner = $m[1];
					preg_match( '~^<a\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i', $whole, $o );
					$open = $o[0];
					$href = Fixes::attr( $open, 'href' );
					if ( ! $href || null !== Fixes::attr( $open, 'aria-label' ) || null !== Fixes::attr( $open, 'aria-labelledby' ) || null !== Fixes::attr( $open, 'title' ) ) {
						return $whole;
					}
					// Only image/icon-only links: any visible text already names the link.
					if ( ! preg_match( '/<(img|svg)\b/i', $inner ) || '' !== preg_replace( '/&nbsp;|\s/', '', preg_replace( '/<[^>]*>/', '', $inner ) ) ) {
						return $whole;
					}
					if ( preg_match_all( '~<img\b(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i', $inner, $imgs ) ) {
						foreach ( $imgs[0] as $img ) {
							if ( '' !== trim( (string) Fixes::attr( $img, 'alt' ) ) ) {
								return $whole;
							}
						}
					}
					$file  = Fixes::href_to_file( $href, $ctx['pagePath'] );
					$title = $file ? call_user_func( $ctx['titleOf'], $file ) : null;
					if ( ! $title ) {
						return $whole;
					}
					$bump( 'linkNames' );

					return Fixes::add_attrs_public( $open, 'aria-label="' . Fixes::esc_public( $title ) . '"' ) . substr( $whole, strlen( $open ) );
				},
				$text
			);
		}

		$dims_added = 0;
		$img_index  = 0;
		$lcp_marked = false;
		if ( ! empty( $opts['imageAlt'] ) || ! empty( $opts['lazyImages'] ) || ! empty( $opts['imageDimensions'] ) ) {
			$text = self::replace(
				self::tag_re( 'img' ),
				static function ( $m ) use ( &$img_index, &$lcp_marked, &$dims_added, $opts, $ctx, $bump ) {
					$t = $m[0];
					$i = $img_index++;

					if ( ! empty( $opts['imageAlt'] ) && null === Fixes::attr( $t, 'alt' ) ) {
						$t = Fixes::add_attrs_public( $t, 'alt=""' );
						$bump( 'imageAlt' );
					}

					if ( ! empty( $opts['imageDimensions'] ) && ! empty( $ctx['sizeOf'] ) ) {
						$w       = Fixes::attr( $t, 'width' );
						$h       = Fixes::attr( $t, 'height' );
						$src     = Fixes::attr( $t, 'src' );
						$numeric = static function ( $v ) {
							return null !== $v && preg_match( '/^\d+$/', $v );
						};
						if ( $src && ( ! $numeric( $w ) || ! $numeric( $h ) ) && ! ( false !== strpos( (string) $w, '%' ) || false !== strpos( (string) $h, '%' ) ) ) {
							$site = Paths::resolve_site_path( $src, $ctx['pagePath'] );
							$size = $site ? call_user_func( $ctx['sizeOf'], $site ) : null;
							if ( $size && $size[0] > 0 && $size[1] > 0 ) {
								$adds = array();
								if ( $numeric( $w ) && ! $numeric( $h ) ) {
									$adds[] = 'height="' . (int) round( ( (float) $w * $size[1] ) / $size[0] ) . '"';
								} elseif ( $numeric( $h ) && ! $numeric( $w ) ) {
									$adds[] = 'width="' . (int) round( ( (float) $h * $size[0] ) / $size[1] ) . '"';
								} elseif ( ! $numeric( $w ) && ! $numeric( $h ) ) {
									$adds[] = 'width="' . $size[0] . '"';
									$adds[] = 'height="' . $size[1] . '"';
								}
								if ( $adds ) {
									$t = Fixes::add_attrs_public( $t, implode( ' ', $adds ) );
									++$dims_added;
									$bump( 'imageDimensions' );
								}
							}
						}
					}

					$loading     = Fixes::attr( $t, 'loading' );
					$priority    = Fixes::attr( $t, 'fetchpriority' );
					$prioritized = ( null !== $loading && 'eager' === strtolower( $loading ) ) || ( null !== $priority && 'high' === strtolower( $priority ) );
					if ( $prioritized ) {
						// Author-marked (or marked by a previous run): this is the LCP candidate, leave it alone.
						$lcp_marked = true;
					} elseif ( ! empty( $opts['lazyImages'] ) && null === $loading ) {
						// The first big image is the likeliest largest-contentful-paint element: load it early, never lazily.
						$width = Fixes::attr( $t, 'width' );
						$tiny  = null !== $width && is_numeric( $width ) && (float) $width < 100;
						if ( ! $lcp_marked && $i < 3 && ! $tiny ) {
							$lcp_marked = true;
							$t          = Fixes::add_attrs_public( $t, 'fetchpriority="high"' );
							$bump( 'lcpPriority' );
						} elseif ( $lcp_marked || $i >= 3 ) {
							$t = Fixes::add_attrs_public( $t, null === Fixes::attr( $t, 'decoding' ) ? 'loading="lazy" decoding="async"' : 'loading="lazy"' );
							$bump( 'lazyImages' );
						}
					}

					return $t;
				},
				$text
			);
		}

		if ( ! empty( $opts['lazyImages'] ) ) {
			$text = self::replace(
				self::tag_re( 'iframe' ),
				static function ( $m ) use ( $bump ) {
					if ( null !== Fixes::attr( $m[0], 'loading' ) ) {
						return $m[0];
					}
					$bump( 'lazyIframes' );

					return Fixes::add_attrs_public( $m[0], 'loading="lazy"' );
				},
				$text
			);
		}

		// Width/height attributes must not stretch images that CSS scales down. This zero-specificity rule
		// restores auto height unless the theme sets one itself.
		if ( $dims_added && ! preg_match( '/id=["\']ss-dims["\']/', self::restore( $text, $saved ) ) ) {
			$text = preg_replace_callback(
				'~</head\s*>~i',
				static function ( $m ) {
					return '<style id="ss-dims">:where(img[width][height]){height:auto}</style>' . $m[0];
				},
				$text,
				1
			);
		}

		return array( self::restore( $text, $saved ), $counts );
	}

	// Closures above run outside the class scope rules for protected members, so they go through these.

	public static function add_attrs_public( $tag, $attrs ) {
		return self::add_attrs( $tag, $attrs );
	}

	public static function esc_public( $s ) {
		return self::esc( $s );
	}

	public static function truncate_public( $s ) {
		return self::truncate( $s );
	}

	// ---- _headers ----------------------------------------------------------------------------

	/**
	 * Netlify and Cloudflare `_headers` file: sane caching and a few security headers.
	 *
	 * @return string
	 */
	public static function headers_file() {
		return "# Generated by WP Static Export. Delete this file to manage headers yourself.\n"
			. "/*\n  X-Content-Type-Options: nosniff\n  Referrer-Policy: strict-origin-when-cross-origin\n\n"
			. "/wp-content/uploads/*\n  Cache-Control: public, max-age=2592000\n\n"
			. "/wp-content/themes/*\n  Cache-Control: public, max-age=86400\n\n"
			. "/wp-content/plugins/*\n  Cache-Control: public, max-age=86400\n\n"
			. "/wp-includes/*\n  Cache-Control: public, max-age=86400\n\n"
			. "/*.woff2\n  Cache-Control: public, max-age=31536000, immutable\n\n"
			. "/ss-assets/*\n  Cache-Control: public, max-age=0, must-revalidate\n";
	}
}
