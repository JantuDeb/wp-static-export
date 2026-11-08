<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Working out which exported file a URL in a page refers to.
 */
class Paths {

	/**
	 * Map a URL found in a page to a path inside the export (no leading slash), or null for URLs that can't be
	 * local files (data:, mailto:, ...). Absolute URLs keep only their path, which is right when the site is
	 * deployed at the root of its host; for other layouts the file simply won't be found.
	 *
	 * @param string $url       URL as written in the page.
	 * @param string $page_path Export-relative path of the page, e.g. "blog/post/index.html".
	 * @return string|null
	 */
	public static function resolve_site_path( $url, $page_path ) {
		$url = trim( (string) $url );
		if ( preg_match( '#^(data:|blob:|mailto:|tel:|javascript:|\#)#i', $url ) ) {
			return null;
		}
		$parts = parse_url( $url );
		if ( false === $parts ) {
			return null;
		}
		$path = $parts['path'] ?? '';
		if ( isset( $parts['host'] ) || 0 === strpos( $url, '//' ) ) {
			// Absolute or protocol-relative: only the path matters.
		} elseif ( '' !== $path && '/' !== $path[0] ) {
			$dir  = dirname( '/' . $page_path );
			$path = ( '/' === $dir ? '' : $dir ) . '/' . $path;
		} elseif ( '' === $path ) {
			$dir  = dirname( '/' . $page_path );
			$path = '/' === $dir ? '/' : $dir . '/';
		}

		return ltrim( rawurldecode( self::normalize( $path ) ), '/' );
	}

	/**
	 * Resolve "." and ".." segments, keeping a trailing slash.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		return self::normalize( $path );
	}

	protected static function normalize( $path ) {
		$trailing = '' !== $path && ( '/' === substr( $path, -1 ) || preg_match( '#/\.\.?$#', $path ) );
		$out      = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $out );
				continue;
			}
			$out[] = $seg;
		}

		return '/' . implode( '/', $out ) . ( $trailing && $out ? '/' : '' );
	}
}
