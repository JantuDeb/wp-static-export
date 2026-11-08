<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Amazon S3 and compatible stores (R2, MinIO, DigitalOcean Spaces, ...), optionally with a CloudFront invalidation.
 */
class Provider_S3 extends Provider {

	public function id() {
		return 's3';
	}

	public function validate( array $config ) {
		Util::required( $config, array( 'bucket', 'region', 'accessKeyId', 'secretAccessKey' ), 's3' );

		return $config;
	}

	public function verify( array $config ) {
		// HEAD on the bucket root: 200 means it exists and we may list it; 403/404 raise a readable error.
		$this->send( $config, 'HEAD', '' );

		return 'Bucket ' . $config['bucket'] . ' is reachable';
	}

	/**
	 * @param array $config Settings.
	 * @param string $key   Object key (may be empty for the bucket itself).
	 * @return array host, path, url.
	 */
	protected function target( array $config, $key ) {
		$path_style = ! empty( $config['pathStyle'] ) || ! empty( $config['endpoint'] );
		$base       = parse_url( ! empty( $config['endpoint'] ) ? $config['endpoint'] : 'https://s3.' . $config['region'] . '.amazonaws.com' );
		$scheme     = $base['scheme'] ?? 'https';
		$host       = $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
		if ( $path_style ) {
			$path = rtrim( $base['path'] ?? '', '/' ) . '/' . $config['bucket'] . '/' . $key;

			return array( $host, $path, $scheme . '://' . $host );
		}
		$host = $config['bucket'] . '.' . $host;

		return array( $host, '/' . $key, $scheme . '://' . $host );
	}

	/**
	 * Build a signed request.
	 *
	 * @param array  $config  Settings.
	 * @param string $method  Method.
	 * @param string $key     Key.
	 * @param string $body    Body.
	 * @param array  $headers Extra headers.
	 * @param string $query   Query string as it appears in the URL.
	 * @return Request
	 */
	protected function build( array $config, $method, $key, $body = '', array $headers = array(), $query = '' ) {
		list( $host, $path, $url ) = $this->target( $config, $key );
		$signed                    = Sigv4::sign(
			array(
				'method'  => $method,
				'host'    => $host,
				'path'    => $path,
				'query'   => $query,
				'headers' => $headers,
				'body'    => $body,
				'region'  => $config['region'],
				'service' => 's3',
				'key'     => $config['accessKeyId'],
				'secret'  => $config['secretAccessKey'],
				'token'   => $config['sessionToken'] ?? '',
			)
		);

		return new Request( $method, $url . Sigv4::canonical_path( $path ) . ( $query ? '?' . $query : '' ), $signed, '' === $body ? null : $body );
	}

	/**
	 * @param array  $config  Settings.
	 * @param string $method  Method.
	 * @param string $key     Key.
	 * @param string $body    Body.
	 * @param array  $headers Headers.
	 * @param string $query   Query.
	 * @return Response
	 */
	protected function send( array $config, $method, $key, $body = '', array $headers = array(), $query = '' ) {
		$request = $this->build( $config, $method, $key, $body, $headers, $query );

		return Http::request( $request->method, $request->url, $request->headers, $request->body );
	}

	/**
	 * @param array $config Settings.
	 * @return string Key prefix with a trailing slash, or ''.
	 */
	protected function prefix( array $config ) {
		$prefix = ltrim( (string) ( $config['prefix'] ?? '' ), '/' );
		if ( '' !== $prefix && '/' !== substr( $prefix, -1 ) ) {
			$prefix .= '/';
		}

		return $prefix;
	}

	public function advance( Context $ctx ) {
		$config = $ctx->config;
		$prefix = $this->prefix( $config );
		$s      = $ctx->state + array( 'up' => 0, 'del' => 0, 'inv' => false );
		$total  = count( $ctx->changed );

		// Uploads, a batch of parallel PUTs at a time.
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
				$ext     = strtolower( pathinfo( $f['path'], PATHINFO_EXTENSION ) );
				$cache   = $config['cacheControlByExt'][ $ext ] ?? ( $config['cacheControl'] ?? '' );
				$headers = array( 'content-type' => Util::mime_type( $f['path'] ) );
				if ( $cache ) {
					$headers['cache-control'] = $cache;
				}
				$reqs[ $i ] = $this->build( $config, 'PUT', $prefix . $f['path'], $ctx->read( $f ), $headers );
			}
			Http::request_many( $reqs );
			$s['up'] += count( $batch );
			$ctx->progress( $s['up'], $total, 'objects' );
		}

		// Batch delete (max 1000 keys per request).
		$deleted = $ctx->deleted;
		while ( $s['del'] < count( $deleted ) ) {
			if ( $ctx->out_of_time() ) {
				$ctx->state = $s;

				return false;
			}
			$part = array_slice( $deleted, $s['del'], 1000 );
			$xml  = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
			foreach ( $part as $p ) {
				$xml .= '<Object><Key>' . htmlspecialchars( $prefix . $p, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</Key></Object>';
			}
			$xml .= '</Delete>';
			$this->send( $config, 'POST', '', $xml, array( 'content-type' => 'application/xml', 'content-md5' => base64_encode( md5( $xml, true ) ) ), 'delete=' );
			$s['del'] += count( $part );
		}

		if ( ! $s['inv'] && ! empty( $config['cloudfrontDistributionId'] ) && ( $total || $deleted ) ) {
			$paths = array();
			foreach ( $ctx->changed as $f ) {
				$paths[] = $f['path'];
			}
			$this->invalidate( $config, array_merge( $paths, $deleted ) );
			$ctx->log( 'CloudFront invalidation requested' );
		}

		$ctx->result = array(
			'uploaded' => $total,
			'deleted'  => count( $deleted ),
			'message'  => 'Synced to s3://' . $config['bucket'] . '/' . $prefix,
		);

		return true;
	}

	/**
	 * @param array    $config Settings.
	 * @param string[] $paths  Changed and deleted paths.
	 * @return void
	 */
	protected function invalidate( array $config, array $paths ) {
		// Invalidate index pages by their pretty URL as well as the file path.
		$urls = array();
		foreach ( $paths as $p ) {
			$urls[ '/' . $p ] = true;
			if ( '/index.html' === substr( '/' . $p, -11 ) ) {
				$urls[ '/' . substr( $p, 0, -10 ) ] = true;
			}
		}
		// A wildcard is cheaper than thousands of paths.
		$items = count( $urls ) > 500 ? array( '/*' ) : array_keys( $urls );
		$xml   = '<?xml version="1.0" encoding="UTF-8"?><InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
			. '<CallerReference>' . round( microtime( true ) * 1000 ) . '</CallerReference><Paths><Quantity>' . count( $items ) . '</Quantity><Items>';
		foreach ( $items as $i ) {
			$xml .= '<Path>' . htmlspecialchars( $i, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</Path>';
		}
		$xml   .= '</Items></Paths></InvalidationBatch>';
		$path   = '/2020-05-31/distribution/' . $config['cloudfrontDistributionId'] . '/invalidation';
		$headers = Sigv4::sign(
			array(
				'method'  => 'POST',
				'host'    => 'cloudfront.amazonaws.com',
				'path'    => $path,
				'headers' => array( 'content-type' => 'application/xml' ),
				'body'    => $xml,
				'region'  => 'us-east-1',
				'service' => 'cloudfront',
				'key'     => $config['accessKeyId'],
				'secret'  => $config['secretAccessKey'],
				'token'   => $config['sessionToken'] ?? '',
			)
		);
		Http::request( 'POST', 'https://cloudfront.amazonaws.com' . $path, $headers, $xml );
	}
}
