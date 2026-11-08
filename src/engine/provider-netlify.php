<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Netlify deploys are atomic snapshots, so the complete file list (not just the diff) is sent. Netlify answers
 * with the digests it is missing and only those files are uploaded.
 */
class Provider_Netlify extends Provider {

	const POLLS = 120;

	/** @var float Seconds between status polls (tests set 0). */
	public static $poll_wait = 2.0;

	public function id() {
		return 'netlify';
	}

	public function validate( array $config ) {
		Util::required( $config, array( 'token', 'siteId' ), 'netlify' );

		return $config + array( 'apiUrl' => 'https://api.netlify.com/api/v1' );
	}

	public function verify( array $config ) {
		$config = $this->validate( $config );
		$site   = Http::request( 'GET', rtrim( $config['apiUrl'], '/' ) . '/sites/' . $config['siteId'], array( 'Authorization' => 'Bearer ' . $config['token'] ) )->json();

		return 'Connected to Netlify site ' . ( $site['name'] ?? $config['siteId'] );
	}

	public function advance( Context $ctx ) {
		$config = $ctx->config;
		$api    = rtrim( $config['apiUrl'], '/' );
		$auth   = array( 'Authorization' => 'Bearer ' . $config['token'] );
		$s      = $ctx->state + array( 'stage' => 'digest', 'digests' => array(), 'at' => 0, 'up' => 0, 'polls' => 0 );

		if ( 'digest' === $s['stage'] ) {
			$all = $ctx->all;
			for ( $i = $s['at']; $i < count( $all ); $i++ ) {
				$s['digests'][ $all[ $i ]['path'] ] = sha1_file( $all[ $i ]['abs'] );
				if ( 0 === ( $i + 1 ) % 50 && $ctx->out_of_time() ) {
					$s['at']    = $i + 1;
					$ctx->state = $s;
					$ctx->progress( $s['at'], count( $all ), 'files fingerprinted' );

					return false;
				}
			}
			$s['stage'] = 'create';
		}

		if ( 'create' === $s['stage'] ) {
			$files = array();
			foreach ( $s['digests'] as $path => $digest ) {
				$files[ '/' . $path ] = $digest;
			}
			$body = array( 'files' => (object) $files, 'draft' => ! empty( $config['draft'] ) );
			if ( ! empty( $config['title'] ) ) {
				$body['title'] = $config['title'];
			}
			$created = Http::request( 'POST', $api . '/sites/' . $config['siteId'] . '/deploys', $auth + array( 'Content-Type' => 'application/json' ), json_encode( $body ) )->json();
			if ( empty( $created['id'] ) ) {
				throw new Deploy_Error( 'Netlify did not create a deploy' );
			}
			$needed       = array_flip( (array) ( $created['required'] ?? array() ) );
			$s['id']      = $created['id'];
			$s['upload']  = array();
			foreach ( $ctx->all as $f ) {
				if ( isset( $needed[ $s['digests'][ $f['path'] ] ] ) ) {
					$s['upload'][] = $f['path'];
				}
			}
			$s['digests'] = array();
			$ctx->log( 'Netlify needs ' . count( $s['upload'] ) . ' of ' . count( $ctx->all ) . ' files' );
			$s['stage'] = 'upload';
		}

		if ( 'upload' === $s['stage'] ) {
			$total = count( $s['upload'] );
			while ( $s['up'] < $total ) {
				if ( $ctx->out_of_time() ) {
					$ctx->state = $s;

					return false;
				}
				$reqs = array();
				foreach ( array_slice( $s['upload'], $s['up'], min( $ctx->concurrency, 8 ) ) as $i => $path ) {
					$abs = $ctx->dir . '/' . $path;
					if ( filesize( $abs ) > Context::MAX_FILE_BYTES ) {
						throw new Deploy_Error( $path . ' is too large to upload from PHP (limit ' . ( Context::MAX_FILE_BYTES / 1048576 ) . ' MB)' );
					}
					$reqs[ $i ] = new Request( 'PUT', $api . '/deploys/' . $s['id'] . '/files/' . Util::encode_path( $path ), $auth + array( 'Content-Type' => 'application/octet-stream' ), (string) file_get_contents( $abs ) );
				}
				Http::request_many( $reqs );
				$s['up'] += count( $reqs );
				$ctx->progress( $s['up'], $total, 'files' );
			}
			$s['stage'] = 'wait';
		}

		// Wait for the deploy to go live: one poll per call, two seconds apart.
		while ( $s['polls'] < self::POLLS ) {
			$d = Http::request( 'GET', $api . '/deploys/' . $s['id'], $auth )->json();
			if ( 'ready' === ( $d['state'] ?? '' ) ) {
				$ctx->result = array(
					'uploaded' => count( $s['upload'] ),
					'deleted'  => 0,
					'url'      => $d['ssl_url'] ?? $d['deploy_ssl_url'] ?? $d['url'] ?? '',
					'message'  => 'Netlify deploy is live',
				);

				return true;
			}
			if ( 'error' === ( $d['state'] ?? '' ) ) {
				throw new Deploy_Error( 'Netlify deploy failed: ' . ( $d['error_message'] ?? 'unknown error' ) );
			}
			++$s['polls'];
			if ( $ctx->out_of_time() ) {
				$ctx->state = $s;

				return false;
			}
			usleep( (int) ( self::$poll_wait * 1000000 ) );
		}
		throw new Deploy_Error( 'Timed out waiting for Netlify deploy to become ready' );
	}
}
