<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BunnyCDN storage zones, with an optional pull zone cache purge.
 */
class Provider_Bunny extends Provider {

	public function id() {
		return 'bunny';
	}

	public function validate( array $config ) {
		Util::required( $config, array( 'storageKey', 'storageZone' ), 'bunny' );

		return $config;
	}

	/**
	 * @param array $config Settings.
	 * @return string Storage API base URL.
	 */
	protected function base( array $config ) {
		$host = ! empty( $config['region'] ) ? $config['region'] . '.storage.bunnycdn.com' : 'storage.bunnycdn.com';

		return 'https://' . $host . '/' . rawurlencode( $config['storageZone'] );
	}

	public function verify( array $config ) {
		Http::request( 'GET', $this->base( $config ) . '/', array( 'AccessKey' => $config['storageKey'] ) );

		return 'Storage zone ' . $config['storageZone'] . ' is reachable';
	}

	public function advance( Context $ctx ) {
		$config = $ctx->config;
		$base   = $this->base( $config );
		$s      = $ctx->state + array( 'up' => 0, 'del' => 0 );
		$total  = count( $ctx->changed );

		while ( $s['up'] < $total ) {
			if ( $ctx->out_of_time() ) {
				$ctx->state = $s;

				return false;
			}
			$batch = array_slice( $ctx->changed, $s['up'], $ctx->concurrency );
			$reqs  = array();
			foreach ( $batch as $i => $f ) {
				if ( $f['size'] > Context::MAX_FILE_BYTES ) {
					throw new Deploy_Error( $f['path'] . ' is too large to upload from PHP (limit ' . ( Context::MAX_FILE_BYTES / 1048576 ) . ' MB)' );
				}
				$reqs[ $i ] = new Request( 'PUT', $base . '/' . Util::encode_path( $f['path'] ), array( 'AccessKey' => $config['storageKey'], 'Content-Type' => 'application/octet-stream' ), $ctx->read( $f ) );
			}
			Http::request_many( $reqs );
			$s['up'] += count( $batch );
			$ctx->progress( $s['up'], $total, 'files' );
		}

		$deleted = $ctx->deleted;
		while ( $s['del'] < count( $deleted ) ) {
			if ( $ctx->out_of_time() ) {
				$ctx->state = $s;

				return false;
			}
			$reqs = array();
			foreach ( array_slice( $deleted, $s['del'], $ctx->concurrency ) as $i => $p ) {
				$reqs[ $i ] = new Request( 'DELETE', $base . '/' . Util::encode_path( $p ), array( 'AccessKey' => $config['storageKey'] ) );
			}
			Http::request_many(
				$reqs,
				static function ( $status ) {
					return ( $status >= 200 && $status < 300 ) || 404 === $status;
				}
			);
			$s['del'] += count( $reqs );
		}

		if ( ! empty( $config['apiKey'] ) && ! empty( $config['pullZoneId'] ) && ( $total || $deleted ) ) {
			Http::request( 'POST', 'https://api.bunny.net/pullzone/' . $config['pullZoneId'] . '/purgeCache', array( 'AccessKey' => $config['apiKey'], 'Content-Type' => 'application/json' ), '{}' );
			$ctx->log( 'Pull zone cache purged' );
		}
		$ctx->result = array( 'uploaded' => $total, 'deleted' => count( $deleted ), 'message' => 'Synced to Bunny storage zone ' . $config['storageZone'] );

		return true;
	}
}
