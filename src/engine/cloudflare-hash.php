<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare identifies an asset by blake3(base64(content) + extension), first 32 hex characters (same as wrangler).
 * Pure-PHP BLAKE3 is slow on large files, so results are cached by the file's SHA-256 between deploys.
 */
class Cloudflare_Hash {

	/**
	 * @param string $content Bytes.
	 * @param string $path    File path (only the extension matters).
	 * @return string 32 hex characters.
	 */
	public static function hash( $content, $path ) {
		$ext = pathinfo( $path, PATHINFO_EXTENSION );

		return substr( Blake3::hash_hex( base64_encode( $content ) . $ext ), 0, 32 );
	}

	/**
	 * Hash files, reusing and extending a cache, and stop when out of time.
	 *
	 * @param Context $ctx    Context.
	 * @param array   $hashes path => hash so far (by reference).
	 * @param int     $at     Index of the next file in $ctx->all (by reference).
	 * @param callable|null $skip function ( $path ) => bool for paths that are not assets.
	 * @return bool True when every file has a hash.
	 */
	public static function hash_all( Context $ctx, array &$hashes, &$at, $skip = null ) {
		$cache   = Util::read_json( $ctx->cache_file );
		$changed = false;
		$total   = count( $ctx->all );
		for ( ; $at < $total; $at++ ) {
			$f = $ctx->all[ $at ];
			if ( $skip && $skip( $f['path'] ) ) {
				continue;
			}
			$key = $f['sha256'] . '.' . strtolower( pathinfo( $f['path'], PATHINFO_EXTENSION ) );
			if ( isset( $cache[ $key ] ) ) {
				$hashes[ $f['path'] ] = $cache[ $key ];
			} else {
				$hashes[ $f['path'] ] = self::hash( $ctx->read( $f ), $f['path'] );
				$cache[ $key ]        = $hashes[ $f['path'] ];
				$changed              = true;
			}
			if ( $ctx->out_of_time() ) {
				++$at;
				break;
			}
		}
		if ( $changed && $ctx->cache_file ) {
			// Keep the cache from growing without bound across years of deploys.
			if ( count( $cache ) > 200000 ) {
				$cache = array_slice( $cache, -100000, null, true );
			}
			Util::write_file( $ctx->cache_file, json_encode( (object) $cache ) );
		}
		$ctx->progress( min( $at, $total ), $total, 'files fingerprinted' );

		return $at >= $total;
	}
}
