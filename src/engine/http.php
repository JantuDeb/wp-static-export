<?php

namespace WP_Static_Export\Engine;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A request to send.
 */
class Request {

	public $method  = 'GET';
	public $url     = '';
	public $headers = array();
	public $body    = null;
	public $timeout = 60;

	/**
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers (name => value).
	 * @param mixed  $body    String body or null.
	 * @param int    $timeout Seconds.
	 */
	public function __construct( $method, $url, array $headers = array(), $body = null, $timeout = 60 ) {
		$this->method  = strtoupper( $method );
		$this->url     = $url;
		$this->headers = $headers;
		$this->body    = $body;
		$this->timeout = $timeout;
	}
}

/**
 * A response. Status 0 means the request never completed; `error` says why.
 */
class Response {

	public $status  = 0;
	public $headers = array();
	public $body    = '';
	public $error   = '';

	/**
	 * @param int    $status  HTTP status.
	 * @param string $body    Body.
	 * @param array  $headers Headers; names are lower-cased.
	 * @param string $error   Transport error.
	 */
	public function __construct( $status = 0, $body = '', array $headers = array(), $error = '' ) {
		$this->status  = (int) $status;
		$this->body    = (string) $body;
		$this->error   = (string) $error;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * @return bool
	 */
	public function ok() {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * @param string $name Header name.
	 * @return string
	 */
	public function header( $name ) {
		$value = $this->headers[ strtolower( $name ) ] ?? '';

		return is_array( $value ) ? (string) end( $value ) : (string) $value;
	}

	/**
	 * @return array Decoded JSON body, or an empty array.
	 */
	public function json() {
		$data = json_decode( $this->body, true );

		return is_array( $data ) ? $data : array();
	}
}

/**
 * Something that can send requests. The WordPress implementation is below; tests use a fake.
 */
interface Transport {

	/**
	 * @param Request $request Request.
	 * @return Response
	 */
	public function send( Request $request );

	/**
	 * Send several requests at once.
	 *
	 * @param Request[] $requests Keyed requests.
	 * @return Response[] Same keys.
	 */
	public function send_many( array $requests );
}

/**
 * Sends requests with the WordPress HTTP API (cURL or streams, proxy settings and CA bundle included).
 */
class Wp_Transport implements Transport {

	/**
	 * @param Request $request Request.
	 * @return Response
	 */
	public function send( Request $request ) {
		$args = array(
			'method'      => $request->method,
			'headers'     => $request->headers,
			'timeout'     => $request->timeout,
			'redirection' => 5,
			'user-agent'  => 'WP-Static-Export',
		);
		if ( null !== $request->body ) {
			$args['body'] = $request->body;
		}
		$result = wp_remote_request( $request->url, $args );
		if ( is_wp_error( $result ) ) {
			return new Response( 0, '', array(), $result->get_error_message() );
		}
		$headers = wp_remote_retrieve_headers( $result );
		$headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;

		return new Response( wp_remote_retrieve_response_code( $result ), wp_remote_retrieve_body( $result ), $headers );
	}

	/**
	 * @param Request[] $requests Keyed requests.
	 * @return Response[]
	 */
	public function send_many( array $requests ) {
		if ( ! $requests ) {
			return array();
		}
		if ( ! class_exists( '\WpOrg\Requests\Requests' ) ) {
			$out = array();
			foreach ( $requests as $key => $request ) {
				$out[ $key ] = $this->send( $request );
			}

			return $out;
		}

		$prepared = array();
		foreach ( $requests as $key => $request ) {
			$prepared[ $key ] = array(
				'url'     => $request->url,
				'type'    => $request->method,
				'headers' => $request->headers,
				'data'    => null === $request->body ? array() : $request->body,
				'options' => array(
					'timeout'          => $request->timeout,
					'connect_timeout'  => min( 20, $request->timeout ),
					'follow_redirects' => true,
					'useragent'        => 'WP-Static-Export',
					'verify'           => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				),
			);
		}

		$out = array();
		foreach ( \WpOrg\Requests\Requests::request_multiple( $prepared, array( 'timeout' => 60 ) ) as $key => $response ) {
			if ( $response instanceof \WpOrg\Requests\Response ) {
				$out[ $key ] = new Response( $response->status_code, $response->body, $response->headers->getAll() );
			} else {
				$out[ $key ] = new Response( 0, '', array(), $response instanceof \Exception ? $response->getMessage() : 'Request failed' );
			}
		}

		return $out;
	}
}

/**
 * Sends requests with retries, and turns failures into readable errors.
 */
class Http {

	/** @var Transport|null */
	protected static $transport = null;

	/**
	 * Seconds to wait before retry n (0-based). Tests set this to zeros.
	 *
	 * @var float[]
	 */
	public static $backoff = array( 0.5, 1.0, 2.0 );

	/**
	 * @param Transport|null $transport Replacement (tests), or null for the default.
	 * @return void
	 */
	public static function set_transport( $transport ) {
		self::$transport = $transport;
	}

	/**
	 * @return Transport
	 */
	public static function transport() {
		if ( null === self::$transport ) {
			self::$transport = new Wp_Transport();
		}

		return self::$transport;
	}

	/**
	 * @param Response $response Response.
	 * @return bool Whether it is worth trying again.
	 */
	protected static function retryable( Response $response ) {
		return 0 === $response->status || 429 === $response->status || $response->status >= 500;
	}

	/**
	 * @param float $seconds Seconds to wait.
	 * @return void
	 */
	protected static function wait( $seconds ) {
		if ( $seconds > 0 ) {
			usleep( (int) ( $seconds * 1000000 ) );
		}
	}

	/**
	 * Send one request. Retries network errors, 429 and 5xx, then fails with a readable message unless the
	 * caller accepts the status.
	 *
	 * @param string        $method  Method.
	 * @param string        $url     URL.
	 * @param array         $headers Headers.
	 * @param string|null   $body    Body.
	 * @param array         $opts    ok (callable status => bool), timeout (seconds), retries.
	 * @return Response
	 * @throws Deploy_Error When the request failed.
	 */
	public static function request( $method, $url, array $headers = array(), $body = null, array $opts = array() ) {
		$retries  = $opts['retries'] ?? count( self::$backoff );
		$request  = new Request( $method, $url, $headers, $body, $opts['timeout'] ?? 60 );
		$attempt  = 0;
		$response = null;
		while ( true ) {
			$response = self::transport()->send( $request );
			if ( self::retryable( $response ) && $attempt < $retries ) {
				$retry_after = (float) $response->header( 'retry-after' );
				self::wait( $retry_after > 0 ? min( $retry_after, 30 ) : ( self::$backoff[ $attempt ] ?? 2.0 ) );
				++$attempt;
				continue;
			}
			break;
		}
		self::assert_ok( $request, $response, $opts['ok'] ?? null );

		return $response;
	}

	/**
	 * Send several requests in parallel. A request that fails is retried once on its own; any that still
	 * fail raise an error.
	 *
	 * @param Request[] $requests Keyed requests.
	 * @param callable|null $ok   Accept a status.
	 * @return Response[] Same keys.
	 * @throws Deploy_Error When one failed.
	 */
	public static function request_many( array $requests, $ok = null ) {
		$responses = self::transport()->send_many( $requests );
		foreach ( $requests as $key => $request ) {
			$response = $responses[ $key ] ?? new Response( 0, '', array(), 'No response' );
			$tries    = 0;
			while ( self::retryable( $response ) && $tries < count( self::$backoff ) ) {
				self::wait( self::$backoff[ $tries ] );
				$response = self::transport()->send( $request );
				++$tries;
			}
			self::assert_ok( $request, $response, $ok );
			$responses[ $key ] = $response;
		}

		return $responses;
	}

	/**
	 * @param Request       $request  Request.
	 * @param Response      $response Response.
	 * @param callable|null $ok       Accept a status.
	 * @return void
	 * @throws Deploy_Error When not accepted.
	 */
	protected static function assert_ok( Request $request, Response $response, $ok ) {
		if ( $ok ? $ok( $response->status ) : $response->ok() ) {
			return;
		}
		$detail = 0 === $response->status ? $response->error : $response->status . ' ' . substr( $response->body, 0, 300 );
		throw new Deploy_Error( $request->method . ' ' . Util::redact( $request->url ) . ' failed: ' . $detail );
	}
}
