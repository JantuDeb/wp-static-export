<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit\Engine;

use WP_Static_Export\Engine\Http;
use WP_Static_Export\Engine\Response;
use WP_Static_Export\Engine\Sigv4;
use WP_Static_Export\Tests\Support\EngineTestCase;
use WP_Static_Export\Tests\Support\FakeTransport;

final class S3Test extends EngineTestCase {

	public function test_puts_signed_objects_with_content_type_and_batch_deletes_removed_keys(): void {
		$dir   = $this->site( array( 'index.html' => '<h1>hi</h1>', 'a.css' => 'body{}' ) );
		$state = $dir . '-state/s3.json';
		mkdir( dirname( $state ), 0777, true );
		file_put_contents( $state, json_encode( array( 'old.html' => 'zzz' ) ) );
		$t = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );

		$this->deploy( array( 'provider' => 's3', 'dir' => $dir, 'stateFile' => $state, 'config' => array( 'bucket' => 'b', 'region' => 'eu-west-1', 'accessKeyId' => 'k', 'secretAccessKey' => 's', 'prefix' => 'site' ) ) );

		$puts = array_values( array_filter( $t->requests, static function ( $r ) { return 'PUT' === $r->method; } ) );
		$urls = array_map( static function ( $r ) { return $r->url; }, $puts );
		sort( $urls );
		$this->assertSame( array( 'https://b.s3.eu-west-1.amazonaws.com/site/a.css', 'https://b.s3.eu-west-1.amazonaws.com/site/index.html' ), $urls );
		$this->assertMatchesRegularExpression( '#^AWS4-HMAC-SHA256 Credential=k/\d{8}/eu-west-1/s3/aws4_request#', $puts[0]->headers['authorization'] );
		$types = array();
		foreach ( $puts as $r ) {
			$types[ basename( $r->url ) ] = $r->headers['content-type'];
		}
		ksort( $types );
		$this->assertSame( array( 'a.css' => 'text/css; charset=utf-8', 'index.html' => 'text/html; charset=utf-8' ), $types );
		$deletes = array_filter( $t->requests, static function ( $r ) { return false !== strpos( $r->url, '?delete=' ); } );
		$this->assertCount( 1, $deletes );
		$this->assertStringContainsString( '<Key>site/old.html</Key>', reset( $deletes )->body );
		$this->rm( $dir . '-state' );
	}

	public function test_custom_endpoint_uses_path_style_and_keeps_the_port(): void {
		$dir = $this->site( array( 'x.txt' => 'x' ) );
		$t   = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$this->deploy( array( 'provider' => 's3', 'dir' => $dir, 'config' => array( 'bucket' => 'b', 'region' => 'auto', 'accessKeyId' => 'k', 'secretAccessKey' => 's', 'endpoint' => 'http://127.0.0.1:9099' ) ) );

		$this->assertSame( 'http://127.0.0.1:9099/b/x.txt', $t->requests[0]->url );
	}

	public function test_cache_control_per_extension_overrides_the_default(): void {
		$dir = $this->site( array( 'a.html' => 'x', 'b.png' => 'y' ) );
		$t   = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$this->deploy(
			array(
				'provider' => 's3',
				'dir'      => $dir,
				'config'   => array( 'bucket' => 'b', 'region' => 'r', 'accessKeyId' => 'k', 'secretAccessKey' => 's', 'cacheControl' => 'max-age=60', 'cacheControlByExt' => array( 'html' => 'no-cache' ) ),
			)
		);
		$by = array();
		foreach ( $t->requests as $r ) {
			$by[ basename( $r->url ) ] = $r->headers['cache-control'];
		}
		$this->assertSame( array( 'a.html' => 'no-cache', 'b.png' => 'max-age=60' ), $by );
	}

	public function test_signature_matches_a_reference_value(): void {
		// Values computed with the Node implementation (deployer/src/sigv4.ts) for the same inputs.
		$h = Sigv4::sign(
			array(
				'method'  => 'GET',
				'host'    => 'examplebucket.s3.amazonaws.com',
				'path'    => '/test.txt',
				'headers' => array( 'range' => 'bytes=0-9' ),
				'body'    => '',
				'region'  => 'us-east-1',
				'service' => 's3',
				'key'     => 'AKIAIOSFODNN7EXAMPLE',
				'secret'  => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
				'time'    => 1369353600, // 2013-05-24T00:00:00Z, the AWS documentation example.
			)
		);
		$this->assertStringEndsWith( 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $h['authorization'] );
	}

	public function test_verify_reports_an_unreachable_bucket(): void {
		Http::set_transport( new FakeTransport( static function () { return new Response( 403 ); } ) );
		$this->expectException( \WP_Static_Export\Engine\Deploy_Error::class );
		\WP_Static_Export\Engine\Deploy_Run::verify( array( 'provider' => 's3', 'config' => array( 'bucket' => 'b', 'region' => 'r', 'accessKeyId' => 'k', 'secretAccessKey' => 's' ) ) );
	}

	public function test_cloudfront_invalidation_is_requested_for_changed_paths(): void {
		$dir = $this->site( array( 'about/index.html' => 'x' ) );
		$t   = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$this->deploy( array( 'provider' => 's3', 'dir' => $dir, 'config' => array( 'bucket' => 'b', 'region' => 'r', 'accessKeyId' => 'k', 'secretAccessKey' => 's', 'cloudfrontDistributionId' => 'DIST' ) ) );

		$inv = end( $t->requests );
		$this->assertSame( 'https://cloudfront.amazonaws.com/2020-05-31/distribution/DIST/invalidation', $inv->url );
		$this->assertStringContainsString( '<Path>/about/</Path>', $inv->body );
		$this->assertStringContainsString( '<Path>/about/index.html</Path>', $inv->body );
	}
}
