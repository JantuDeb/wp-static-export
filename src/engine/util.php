<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small helpers shared by the deploy and process engines.
 */
class Util {

	const MIME = array(
		'html'  => 'text/html; charset=utf-8',
		'htm'   => 'text/html; charset=utf-8',
		'css'   => 'text/css; charset=utf-8',
		'js'    => 'text/javascript; charset=utf-8',
		'mjs'   => 'text/javascript; charset=utf-8',
		'json'  => 'application/json',
		'xml'   => 'application/xml',
		'txt'   => 'text/plain; charset=utf-8',
		'svg'   => 'image/svg+xml',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'avif'  => 'image/avif',
		'ico'   => 'image/x-icon',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
		'pdf'   => 'application/pdf',
		'mp4'   => 'video/mp4',
		'webm'  => 'video/webm',
		'mp3'   => 'audio/mpeg',
		'zip'   => 'application/zip',
		'map'   => 'application/json',
		'rss'   => 'application/rss+xml',
		'atom'  => 'application/atom+xml',
		'wasm'  => 'application/wasm',
	);

	/**
	 * @param string $file Path or name.
	 * @return string Content type.
	 */
	public static function mime_type( $file ) {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return self::MIME[ $ext ] ?? 'application/octet-stream';
	}

	/**
	 * Match a path against simple patterns where `*` matches anything (including slashes).
	 *
	 * @param string        $path     Path relative to the export, no leading slash.
	 * @param string[]|null $patterns Patterns.
	 * @return bool
	 */
	public static function matches_any( $path, $patterns ) {
		if ( empty( $patterns ) ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			$re = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#s';
			// Patterns written with a leading slash (URL style) should match slash-less paths.
			if ( preg_match( $re, $path ) || preg_match( $re, '/' . $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $url URL.
	 * @return string Origin and path only, so tokens in query strings never reach logs or errors.
	 */
	public static function redact( $url ) {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( $parts['path'] ?? '' );
	}

	/**
	 * @param array    $config Config.
	 * @param string[] $keys   Required keys.
	 * @param string   $label  Provider label for the message.
	 * @return void
	 * @throws Deploy_Error When something is missing.
	 */
	public static function required( array $config, array $keys, $label ) {
		$missing = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $config[ $key ] ) || '' === $config[ $key ] || array() === $config[ $key ] ) {
				$missing[] = $key;
			}
		}
		if ( $missing ) {
			throw new Deploy_Error( $label . ': missing setting(s): ' . implode( ', ', $missing ) );
		}
	}

	/**
	 * @param string $path Relative path with forward slashes.
	 * @return string Same path with each segment URL-encoded.
	 */
	public static function encode_path( $path ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/**
	 * Write a file atomically so a crash never leaves half a JSON document behind.
	 *
	 * @param string $file    Target.
	 * @param string $content Content.
	 * @return void
	 * @throws Deploy_Error On failure.
	 */
	public static function write_file( $file, $content ) {
		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new Deploy_Error( 'Could not create the folder ' . $dir );
		}
		$tmp = $file . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, $content ) ) {
			throw new Deploy_Error( 'Could not write ' . basename( $file ) );
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new Deploy_Error( 'Could not save ' . basename( $file ) );
		}
	}

	/**
	 * @param string $file JSON file.
	 * @return array Decoded content or an empty array.
	 */
	public static function read_json( $file ) {
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build a multipart/form-data body.
	 *
	 * @param array[] $parts Each: name, body, optional filename, optional type.
	 * @return array{0:string,1:string} Content-Type header value and body.
	 */
	public static function multipart( array $parts ) {
		$boundary = '----wpse' . bin2hex( random_bytes( 12 ) );
		$body     = '';
		foreach ( $parts as $part ) {
			$disposition = 'form-data; name="' . str_replace( '"', '%22', $part['name'] ) . '"';
			if ( isset( $part['filename'] ) ) {
				$disposition .= '; filename="' . str_replace( '"', '%22', $part['filename'] ) . '"';
			}
			$body .= '--' . $boundary . "\r\nContent-Disposition: " . $disposition . "\r\n";
			if ( isset( $part['type'] ) ) {
				$body .= 'Content-Type: ' . $part['type'] . "\r\n";
			}
			$body .= "\r\n" . $part['body'] . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";

		return array( 'multipart/form-data; boundary=' . $boundary, $body );
	}
}
