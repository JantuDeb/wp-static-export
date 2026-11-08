<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-encodes JPEG and PNG images and makes WebP twins with PHP's GD extension.
 *
 * JPEG is lossy, so every re-encode loses quality: each image we write carries a marker (a JPEG comment or a PNG
 * text chunk) and an image with the marker is never re-encoded, even when the state file is gone.
 */
class Image_Optimizer {

	const MARKER = 'wp-static-export';

	/**
	 * @return bool Whether images can be processed at all.
	 */
	public static function available() {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) && function_exists( 'imagepng' );
	}

	/**
	 * @return bool Whether WebP can be written.
	 */
	public static function webp_available() {
		return function_exists( 'imagewebp' );
	}

	/**
	 * @param string $bytes Image bytes.
	 * @return bool
	 */
	public static function is_optimized( $bytes ) {
		return false !== strpos( substr( $bytes, 0, 4096 ), self::MARKER );
	}

	/**
	 * @param string $bytes   Original bytes.
	 * @param string $ext     jpg, jpeg or png.
	 * @param int    $quality 1 to 100.
	 * @param bool   $webp    Also make a WebP version.
	 * @return array|null null when the image should be left alone. Otherwise: image (new bytes or null when not
	 *                    smaller), webp (bytes or null), reason (why skipped, if so).
	 */
	public static function optimize( $bytes, $ext, $quality, $webp ) {
		$jpeg = in_array( $ext, array( 'jpg', 'jpeg' ), true );
		if ( self::is_optimized( $bytes ) ) {
			return null;
		}
		if ( ! $jpeg && false !== strpos( substr( $bytes, 0, 4096 ), 'acTL' ) ) {
			return null; // Animated PNG: GD would keep only the first frame.
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return null;
		}
		if ( $jpeg && isset( $info['channels'] ) && 4 === (int) $info['channels'] ) {
			return null; // CMYK: GD would invert the colors.
		}
		if ( ! self::fits_in_memory( (int) $info[0], (int) $info[1] ) ) {
			return array( 'image' => null, 'webp' => null, 'reason' => 'too large to process with the available memory' );
		}

		$img = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $img ) {
			return null;
		}
		if ( $jpeg ) {
			$img = self::orient( $img, Image_Info::orientation( substr( $bytes, 0, 65536 ) ) );
		}

		$encoded = $jpeg ? self::encode_jpeg( $img, $quality ) : self::encode_png( $img );
		$image   = null !== $encoded && strlen( $encoded ) < strlen( $bytes ) ? $encoded : null;
		$twin    = null;
		if ( $webp && self::webp_available() ) {
			$data = self::encode_webp( $img, $quality );
			// A WebP that isn't smaller than the (optimized) original isn't worth serving.
			if ( null !== $data && strlen( $data ) < strlen( $image ?? $bytes ) ) {
				$twin = $data;
			}
		}
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $img ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		}

		return array( 'image' => $image, 'webp' => $twin, 'reason' => '' );
	}

	/**
	 * GD needs roughly width * height * 5 bytes (pixels plus working copies).
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return bool
	 */
	public static function fits_in_memory( $w, $h ) {
		$limit = ini_get( 'memory_limit' );
		if ( '-1' === $limit || '' === $limit ) {
			return true;
		}
		$bytes = (int) $limit;
		switch ( strtolower( substr( $limit, -1 ) ) ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}

		return ( $w * $h * 5 * 2 ) < ( $bytes - memory_get_usage() );
	}

	/**
	 * Apply an EXIF orientation to the pixels (the re-encoded file carries no EXIF).
	 *
	 * @param \GdImage|resource $img         Image.
	 * @param int               $orientation 1 to 8.
	 * @return \GdImage|resource
	 */
	protected static function orient( $img, $orientation ) {
		$black = 0;
		switch ( $orientation ) {
			case 2:
				imageflip( $img, IMG_FLIP_HORIZONTAL );
				break;
			case 3:
				$img = imagerotate( $img, 180, $black );
				break;
			case 4:
				imageflip( $img, IMG_FLIP_VERTICAL );
				break;
			case 5:
				$img = imagerotate( $img, 90, $black );
				imageflip( $img, IMG_FLIP_VERTICAL );
				break;
			case 6:
				$img = imagerotate( $img, -90, $black );
				break;
			case 7:
				$img = imagerotate( $img, -90, $black );
				imageflip( $img, IMG_FLIP_VERTICAL );
				break;
			case 8:
				$img = imagerotate( $img, 90, $black );
				break;
		}

		return $img;
	}

	/**
	 * @param \GdImage|resource $img     Image.
	 * @param int               $quality Quality.
	 * @return string|null
	 */
	protected static function encode_jpeg( $img, $quality ) {
		if ( ! imageistruecolor( $img ) ) {
			imagepalettetotruecolor( $img );
		}
		imageinterlace( $img, true ); // Progressive JPEGs are usually smaller and render sooner.
		ob_start();
		$ok   = imagejpeg( $img, null, $quality );
		$data = (string) ob_get_clean();
		if ( ! $ok || '' === $data ) {
			return null;
		}
		$comment = "\xFF\xFE" . pack( 'n', strlen( self::MARKER ) + 2 ) . self::MARKER;

		return substr( $data, 0, 2 ) . $comment . substr( $data, 2 );
	}

	/**
	 * @param \GdImage|resource $img Image.
	 * @return string|null
	 */
	protected static function encode_png( $img ) {
		imagealphablending( $img, false );
		imagesavealpha( $img, true );
		ob_start();
		$ok   = imagepng( $img, null, 9 );
		$data = (string) ob_get_clean();
		if ( ! $ok || strlen( $data ) < 33 ) {
			return null;
		}
		// A tEXt chunk right after IHDR (8 byte signature + 25 byte IHDR chunk).
		$body  = 'Software' . "\0" . self::MARKER;
		$chunk = pack( 'N', strlen( $body ) ) . 'tEXt' . $body . pack( 'N', crc32( 'tEXt' . $body ) );

		return substr( $data, 0, 33 ) . $chunk . substr( $data, 33 );
	}

	/**
	 * @param \GdImage|resource $img     Image.
	 * @param int               $quality Quality.
	 * @return string|null
	 */
	protected static function encode_webp( $img, $quality ) {
		if ( ! imageistruecolor( $img ) ) {
			imagepalettetotruecolor( $img );
		}
		imagealphablending( $img, false );
		imagesavealpha( $img, true );
		ob_start();
		$ok   = imagewebp( $img, null, $quality );
		$data = (string) ob_get_clean();

		return $ok && '' !== $data ? $data : null;
	}

	/**
	 * Wrap <img> in <picture> with a WebP <source> when every referenced file has a WebP twin.
	 *
	 * @param string   $html      HTML.
	 * @param string   $page_path Export-relative path of the page.
	 * @param string[] $webp      Set of existing twins as keys (path => true), e.g. "a/b.jpg.webp".
	 * @return string
	 */
	public static function add_picture_sources( $html, $page_path, array $webp ) {
		$attr = static function ( $tag, $name ) {
			return Fixes::attr( $tag, $name );
		};
		$out  = '';
		$last = 0;
		if ( ! preg_match_all( '~<img\b[^>]*>~i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		foreach ( $matches[0] as $m ) {
			$tag    = $m[0];
			$offset = $m[1];
			$before = substr( $html, 0, $offset );
			$open   = strrpos( $before, '<picture' );
			$close  = strrpos( $before, '</picture>' );
			$inside = false !== $open && ( false === $close || $open > $close );
			$src    = $attr( $tag, 'src' );
			$srcset = $attr( $tag, 'srcset' );
			if ( $inside || ! $src ) {
				continue;
			}

			$entries = array();
			if ( $srcset ) {
				foreach ( explode( ',', $srcset ) as $piece ) {
					$piece = trim( $piece );
					if ( '' === $piece ) {
						continue;
					}
					$bits      = preg_split( '/\s+/', $piece );
					$url       = array_shift( $bits );
					$entries[] = array( $url, implode( ' ', $bits ) );
				}
			} else {
				$entries[] = array( $src, '' );
			}

			$converted = array();
			foreach ( $entries as $e ) {
				$p = Paths::resolve_site_path( $e[0], $page_path );
				if ( ! $p || ! isset( $webp[ $p . '.webp' ] ) ) {
					$converted = null;
					break;
				}
				$converted[] = array( $e[0] . '.webp', $e[1] );
			}
			if ( null === $converted ) {
				continue;
			}

			$sizes  = $attr( $tag, 'sizes' );
			$list   = array();
			foreach ( $converted as $c ) {
				$list[] = $c[1] ? $c[0] . ' ' . $c[1] : $c[0];
			}
			$source = '<source type="image/webp" srcset="' . implode( ', ', $list ) . '"' . ( $sizes ? ' sizes="' . $sizes . '"' : '' ) . '>';
			$out   .= substr( $html, $last, $offset - $last ) . '<picture>' . $source . $tag . '</picture>';
			$last   = $offset + strlen( $tag );
		}

		return $last ? $out . substr( $html, $last ) : $html;
	}
}
