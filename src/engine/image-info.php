<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads what is needed about an image from its first bytes, without decoding it.
 */
class Image_Info {

	/**
	 * Bytes enough to find the dimensions of any normal image.
	 */
	const HEAD_BYTES = 262144;

	/**
	 * @param string $bytes Image bytes (a leading part is enough).
	 * @return array|null [ width, height ] as displayed, or null if unknown.
	 */
	public static function size( $bytes ) {
		if ( strlen( $bytes ) < 12 ) {
			return null;
		}
		if ( preg_match( '/<svg\b/i', substr( $bytes, 0, 2048 ) ) && "\xFF\xD8" !== substr( $bytes, 0, 2 ) && "\x89PNG" !== substr( $bytes, 0, 4 ) ) {
			return self::svg_size( substr( $bytes, 0, 2048 ) );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || $info[0] <= 0 || $info[1] <= 0 ) {
			return null;
		}
		$size = array( (int) $info[0], (int) $info[1] );
		if ( IMAGETYPE_JPEG === $info[2] && self::orientation( $bytes ) >= 5 ) {
			$size = array( $size[1], $size[0] );
		}

		return $size;
	}

	/**
	 * EXIF orientation of a JPEG (1 when absent). 5 to 8 mean the stored width and height are swapped.
	 *
	 * @param string $bytes JPEG bytes (the first 64 KB are enough).
	 * @return int
	 */
	public static function orientation( $bytes ) {
		$exif = strpos( $bytes, "Exif\0\0" );
		if ( false === $exif || $exif > 4096 ) {
			return 1;
		}
		$tiff   = $exif + 6;
		$little = 'II' === substr( $bytes, $tiff, 2 );
		$u16    = static function ( $o ) use ( $bytes, $little ) {
			$d = substr( $bytes, $o, 2 );

			return 2 === strlen( $d ) ? unpack( $little ? 'v' : 'n', $d )[1] : null;
		};
		$u32    = static function ( $o ) use ( $bytes, $little ) {
			$d = substr( $bytes, $o, 4 );

			return 4 === strlen( $d ) ? unpack( $little ? 'V' : 'N', $d )[1] : null;
		};
		$off = $u32( $tiff + 4 );
		if ( null === $off ) {
			return 1;
		}
		$ifd = $tiff + $off;
		$n   = $u16( $ifd );
		if ( null === $n || $n > 256 ) {
			return 1;
		}
		for ( $i = 0; $i < $n; $i++ ) {
			$e = $ifd + 2 + $i * 12;
			if ( 0x0112 === $u16( $e ) ) {
				$o = $u16( $e + 8 );

				return $o >= 1 && $o <= 8 ? $o : 1;
			}
		}

		return 1;
	}

	/**
	 * @param string $head Start of an SVG file.
	 * @return array|null
	 */
	protected static function svg_size( $head ) {
		$tag = preg_match( '/<svg\b[^>]*>/i', $head, $m ) ? $m[0] : '';
		$num = static function ( $name ) use ( $tag ) {
			return preg_match( '/\s' . $name . '\s*=\s*["\']\s*([0-9.]+)\s*(px)?\s*["\']/i', $tag, $m ) ? (float) $m[1] : null;
		};
		$w = $num( 'width' );
		$h = $num( 'height' );
		if ( $w && $h ) {
			return array( (int) round( $w ), (int) round( $h ) );
		}
		if ( preg_match( '/viewBox\s*=\s*["\']\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*["\']/i', $tag, $m ) ) {
			return array( (int) round( (float) $m[1] ), (int) round( (float) $m[2] ) );
		}

		return null;
	}
}
