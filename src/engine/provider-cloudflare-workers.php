<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Workers static assets (the replacement for Pages direct upload): an upload session, asset uploads per
 * bucket, then publishing the Worker with the completion token. Every deploy sends the full manifest, so files
 * removed from the export disappear from the site.
 */
class Provider_Cloudflare_Workers extends Provider {

	const MAX_FILE_BYTES = 25 * 1024 * 1024;

	public function id() {
		return 'cloudflare-workers';
	}

	public function validate( array $config ) {
		Util::required( $config, array( 'apiToken', 'accountId', 'project' ), 'cloudflare-workers' );

		return $config + array( 'apiUrl' => 'https://api.cloudflare.com/client/v4' );
	}

	public function verify( array $config ) {
		$config = $this->validate( $config );
		$res    = Http::request( 'GET', rtrim( $config['apiUrl'], '/' ) . '/accounts/' . $config['accountId'] . '/workers/scripts', array( 'Authorization' => 'Bearer ' . $config['apiToken'] ) )->json();
		$exists = false;
		foreach ( (array) ( $res['result'] ?? array() ) as $script ) {
			if ( ( $script['id'] ?? '' ) === $config['project'] ) {
				$exists = true;
			}
		}

		return $exists
			? 'Connected; Worker ' . $config['project'] . ' exists and will be updated'
			: 'Connected; Worker ' . $config['project'] . ' will be created on the first deploy';
	}

	public function advance( Context $ctx ) {
		$config = $ctx->config;
		$base   = rtrim( $config['apiUrl'], '/' ) . '/accounts/' . $config['accountId'] . '/workers';
		$cf     = array( 'Authorization' => 'Bearer ' . $config['apiToken'] );
		$s      = $ctx->state + array( 'stage' => 'hash', 'hashes' => array(), 'at' => 0, 'bucket' => 0, 'uploaded' => 0, 'buckets' => array() );

		if ( 'hash' === $s['stage'] ) {
			foreach ( $ctx->all as $f ) {
				if ( $f['size'] > self::MAX_FILE_BYTES ) {
					throw new Deploy_Error( $f['path'] . " is larger than Cloudflare's 25 MiB per-file limit" );
				}
			}
			if ( ! Cloudflare_Hash::hash_all( $ctx, $s['hashes'], $s['at'] ) ) {
				$ctx->state = $s;

				return false;
			}
			$s['stage'] = 'session';
		}

		if ( 'session' === $s['stage'] ) {
			$manifest = array();
			foreach ( $ctx->all as $f ) {
				$manifest[ '/' . $f['path'] ] = array( 'hash' => $s['hashes'][ $f['path'] ], 'size' => $f['size'] );
			}
			$session = Http::request(
				'POST',
				$base . '/scripts/' . rawurlencode( $config['project'] ) . '/assets-upload-session',
				$cf + array( 'Content-Type' => 'application/json' ),
				json_encode( array( 'manifest' => (object) $manifest ) )
			)->json();
			$s['jwt'] = $session['result']['jwt'] ?? '';
			if ( '' === $s['jwt'] ) {
				throw new Deploy_Error( 'Cloudflare did not return an upload token' );
			}
			$s['buckets']  = $session['result']['buckets'] ?? array();
			$s['needed']   = array_sum( array_map( 'count', $s['buckets'] ) );
			$ctx->log( 'Cloudflare needs ' . $s['needed'] . ' of ' . count( $ctx->all ) . ' assets' );
			$s['stage'] = 'upload';
		}

		if ( 'upload' === $s['stage'] ) {
			$path_of = array_flip( $s['hashes'] );
			while ( $s['bucket'] < count( $s['buckets'] ) ) {
				if ( $ctx->out_of_time() ) {
					$ctx->state = $s;

					return false;
				}
				$parts = array();
				foreach ( $s['buckets'][ $s['bucket'] ] as $hash ) {
					if ( ! isset( $path_of[ $hash ] ) ) {
						continue;
					}
					$path    = $path_of[ $hash ];
					$parts[] = array( 'name' => $hash, 'filename' => $hash, 'type' => Util::mime_type( $path ), 'body' => base64_encode( (string) file_get_contents( $ctx->dir . '/' . $path ) ) );
				}
				list( $type, $body ) = Util::multipart( $parts );
				$res                 = Http::request( 'POST', $base . '/assets/upload?base64=true', array( 'Authorization' => 'Bearer ' . $s['jwt'], 'Content-Type' => $type ), $body )->json();
				// The last bucket answers with the completion token that replaces the session token.
				if ( ! empty( $res['result']['jwt'] ) ) {
					$s['jwt'] = $res['result']['jwt'];
				}
				$s['uploaded'] += count( $s['buckets'][ $s['bucket'] ] );
				++$s['bucket'];
				$ctx->progress( $s['uploaded'], (int) $s['needed'], 'assets' );
			}
			$s['stage'] = 'publish';
		}

		$metadata = array(
			'compatibility_date' => gmdate( 'Y-m-d' ),
			'assets'             => array( 'jwt' => $s['jwt'], 'config' => array( 'html_handling' => 'auto-trailing-slash', 'not_found_handling' => '404-page' ) ),
		);
		list( $type, $body ) = Util::multipart( array( array( 'name' => 'metadata', 'filename' => 'metadata', 'type' => 'application/json', 'body' => json_encode( $metadata ) ) ) );
		$dep                 = Http::request( 'PUT', $base . '/scripts/' . rawurlencode( $config['project'] ), $cf + array( 'Content-Type' => $type ), $body )->json();
		if ( isset( $dep['success'] ) && false === $dep['success'] ) {
			throw new Deploy_Error( 'Cloudflare rejected the Worker: ' . json_encode( $dep['errors'] ?? array() ) );
		}
		$ctx->result = array( 'uploaded' => (int) $s['needed'], 'deleted' => 0, 'message' => 'Cloudflare Workers deployment published (' . (int) $s['needed'] . ' new assets)' );

		return true;
	}
}
