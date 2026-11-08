<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AWS Signature Version 4.
 */
class Sigv4 {

	/**
	 * Canonical URI: each path segment URI-encoded once (S3 style, no double-encoding).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function canonical_path( $path ) {
		$encoded = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );

		return '' === $encoded ? '/' : $encoded;
	}

	/**
	 * Headers to add to a request, Authorization included. `query` must already be sorted and encoded as it
	 * appears in the URL.
	 *
	 * @param array $o method, host, path, query, headers, body, region, service, key (id), secret, token, time.
	 * @return array Header name => value (lower-case names; no host).
	 */
	public static function sign( array $o ) {
		$time    = $o['time'] ?? time();
		$stamp   = gmdate( 'Ymd\THis\Z', $time );
		$day     = gmdate( 'Ymd', $time );
		$payload = hash( 'sha256', (string) $o['body'] );

		$headers = array();
		foreach ( (array) ( $o['headers'] ?? array() ) as $name => $value ) {
			$headers[ strtolower( $name ) ] = trim( (string) $value );
		}
		$headers['host']                 = $o['host'];
		$headers['x-amz-content-sha256'] = $payload;
		$headers['x-amz-date']           = $stamp;
		if ( ! empty( $o['token'] ) ) {
			$headers['x-amz-security-token'] = $o['token'];
		}
		ksort( $headers );

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed    = implode( ';', array_keys( $headers ) );
		$canonical = implode( "\n", array( $o['method'], self::canonical_path( $o['path'] ), $o['query'] ?? '', $canonical_headers, $signed, $payload ) );
		$scope     = $day . '/' . $o['region'] . '/' . $o['service'] . '/aws4_request';
		$to_sign   = implode( "\n", array( 'AWS4-HMAC-SHA256', $stamp, $scope, hash( 'sha256', $canonical ) ) );

		$k_date    = hash_hmac( 'sha256', $day, 'AWS4' . $o['secret'], true );
		$k_region  = hash_hmac( 'sha256', $o['region'], $k_date, true );
		$k_service = hash_hmac( 'sha256', $o['service'], $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
		$signature = hash_hmac( 'sha256', $to_sign, $k_signing );

		$headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $o['key'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
		unset( $headers['host'] );

		return $headers;
	}
}
