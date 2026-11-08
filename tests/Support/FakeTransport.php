<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Support;

use WP_Static_Export\Engine\Request;
use WP_Static_Export\Engine\Response;
use WP_Static_Export\Engine\Transport;

/**
 * Records requests and answers them with a callback, so providers are tested without a network.
 */
final class FakeTransport implements Transport {

	/** @var Request[] */
	public $requests = array();

	/** @var callable */
	private $handler;

	public function __construct( callable $handler ) {
		$this->handler = $handler;
	}

	public function send( Request $request ) {
		$this->requests[] = $request;
		$response         = ( $this->handler )( $request );

		return $response instanceof Response ? $response : self::json( $response ?? array() );
	}

	public function send_many( array $requests ) {
		$out = array();
		foreach ( $requests as $key => $request ) {
			$out[ $key ] = $this->send( $request );
		}

		return $out;
	}

	/** @param mixed $data */
	public static function json( $data, int $status = 200 ): Response {
		return new Response( $status, json_encode( $data ), array( 'content-type' => 'application/json' ) );
	}

	/** "METHOD /path" for each recorded request. */
	public function log( string $strip = '' ): array {
		return array_map(
			static function ( Request $r ) use ( $strip ): string {
				$p = parse_url( $r->url );

				return $r->method . ' ' . str_replace( $strip, '', ( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' ) );
			},
			$this->requests
		);
	}
}
