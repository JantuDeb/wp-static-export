<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Pages direct upload.
 */
class Provider_Cloudflare_Pages extends Provider {

	const SPECIAL         = array( '_headers', '_redirects', '_routes.json', '_worker.js' );
	const MAX_BUCKET_BYTES = 40 * 1024 * 1024;
	const MAX_BUCKET_FILES = 2000;

	public function id() {
		return 'cloudflare-pages';
	}

	public function validate( array $config ) {
		Util::required( $config, array( 'apiToken', 'accountId', 'project' ), 'cloudflare-pages' );

		return $config + array( 'apiUrl' => 'https://api.cloudflare.com/client/v4' );
	}

	public function verify( array $config ) {
		$config = $this->validate( $config );
		$res    = Http::request( 'GET', rtrim( $config['apiUrl'], '/' ) . '/accounts/' . $config['accountId'] . '/pages/projects/' . rawurlencode( $config['project'] ), array( 'Authorization' => 'Bearer ' . $config['apiToken'] ) )->json();

		return 'Connected to Pages project ' . ( $res['result']['name'] ?? $config['project'] );
	}

	public function advance( Context $ctx ) {
		$config = $ctx->config;
		$api    = rtrim( $config['apiUrl'], '/' );
		$acct   = $api . '/accounts/' . $config['accountId'] . '/pages/projects/' . rawurlencode( $config['project'] );
		$cf     = array( 'Authorization' => 'Bearer ' . $config['apiToken'] );
		$s      = $ctx->state + array( 'stage' => 'hash', 'hashes' => array(), 'at' => 0, 'bucket' => 0, 'uploaded' => 0 );
		$skip   = static function ( $path ) {
			return in_array( $path, Provider_Cloudflare_Pages::SPECIAL, true );
		};

		if ( 'hash' === $s['stage'] ) {
			if ( ! Cloudflare_Hash::hash_all( $ctx, $s['hashes'], $s['at'], $skip ) ) {
				$ctx->state = $s;

				return false;
			}
			$s['stage'] = 'missing';
		}

		$jwt     = null;
		$jwtauth = function () use ( &$jwt, $acct, $cf ) {
			if ( null === $jwt ) {
				$jwt = Http::request( 'GET', $acct . '/upload-token', $cf )->json()['result']['jwt'] ?? '';
				if ( '' === $jwt ) {
					throw new Deploy_Error( 'Cloudflare did not return an upload token' );
				}
			}

			return array( 'Authorization' => 'Bearer ' . $jwt, 'Content-Type' => 'application/json' );
		};

		if ( 'missing' === $s['stage'] ) {
			$by_hash = array_flip( array_flip( $s['hashes'] ) );
			$missing = Http::request( 'POST', $api . '/pages/assets/check-missing', $jwtauth(), json_encode( array( 'hashes' => array_values( $by_hash ) ) ) )->json()['result'] ?? array();
			$ctx->log( 'Cloudflare is missing ' . count( $missing ) . ' of ' . count( $by_hash ) . ' assets' );
			$s['missing_n'] = count( $missing );

			// Upload in buckets bounded by payload size and count, as the API enforces limits.
			$path_of = array_flip( $s['hashes'] );
			$sizes   = array();
			foreach ( $ctx->all as $f ) {
				$sizes[ $f['path'] ] = $f['size'];
			}
			$buckets = array();
			$cur     = array();
			$bytes   = 0;
			foreach ( $missing as $h ) {
				if ( ! isset( $path_of[ $h ] ) ) {
					continue;
				}
				$size = $sizes[ $path_of[ $h ] ] ?? 0;
				if ( $cur && ( $bytes + $size > self::MAX_BUCKET_BYTES || count( $cur ) >= self::MAX_BUCKET_FILES ) ) {
					$buckets[] = $cur;
					$cur       = array();
					$bytes     = 0;
				}
				$cur[] = $h;
				$bytes += $size;
			}
			if ( $cur ) {
				$buckets[] = $cur;
			}
			$s['buckets'] = $buckets;
			$s['stage']   = 'upload';
		}

		if ( 'upload' === $s['stage'] ) {
			$path_of = array_flip( $s['hashes'] );
			$total   = (int) $s['missing_n'];
			while ( $s['bucket'] < count( $s['buckets'] ) ) {
				if ( $ctx->out_of_time() ) {
					$ctx->state = $s;

					return false;
				}
				$payload = array();
				foreach ( $s['buckets'][ $s['bucket'] ] as $h ) {
					$path      = $path_of[ $h ];
					$payload[] = array(
						'key'      => $h,
						'value'    => base64_encode( (string) file_get_contents( $ctx->dir . '/' . $path ) ),
						'metadata' => array( 'contentType' => Util::mime_type( $path ) ),
						'base64'   => true,
					);
				}
				Http::request( 'POST', $api . '/pages/assets/upload', $jwtauth(), json_encode( $payload ) );
				$s['uploaded'] += count( $payload );
				++$s['bucket'];
				$ctx->progress( $s['uploaded'], $total, 'assets' );
			}
			foreach ( array_chunk( array_values( array_flip( array_flip( $s['hashes'] ) ) ), 5000 ) as $part ) {
				Http::request( 'POST', $api . '/pages/assets/upsert-hashes', $jwtauth(), json_encode( array( 'hashes' => $part ) ) );
			}
			$s['stage'] = 'deploy';
		}

		$manifest = array();
		foreach ( $s['hashes'] as $path => $h ) {
			$manifest[ '/' . $path ] = $h;
		}
		$parts = array( array( 'name' => 'manifest', 'body' => json_encode( (object) $manifest ) ) );
		if ( ! empty( $config['branch'] ) ) {
			$parts[] = array( 'name' => 'branch', 'body' => $config['branch'] );
		}
		foreach ( $ctx->all as $f ) {
			if ( $skip( $f['path'] ) && '_worker.js' !== $f['path'] ) {
				$parts[] = array( 'name' => $f['path'], 'filename' => $f['path'], 'type' => 'application/octet-stream', 'body' => $ctx->read( $f ) );
			}
		}
		list( $type, $body ) = Util::multipart( $parts );
		$dep                 = Http::request( 'POST', $acct . '/deployments', $cf + array( 'Content-Type' => $type ), $body )->json();
		if ( isset( $dep['success'] ) && ! $dep['success'] ) {
			throw new Deploy_Error( 'Cloudflare deployment rejected: ' . json_encode( $dep['errors'] ?? array() ) );
		}
		$ctx->result = array( 'uploaded' => (int) $s['missing_n'], 'deleted' => 0, 'url' => $dep['result']['url'] ?? '', 'message' => 'Cloudflare Pages deployment created' );

		return true;
	}
}
