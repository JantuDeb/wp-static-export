<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit\Engine;

use WP_Static_Export\Engine\Deploy_Error;
use WP_Static_Export\Engine\Deploy_Run;
use WP_Static_Export\Engine\Http;
use WP_Static_Export\Engine\Response;
use WP_Static_Export\Engine\Util;
use WP_Static_Export\Tests\Support\EngineTestCase;
use WP_Static_Export\Tests\Support\FakeTransport;

final class DeployRunTest extends EngineTestCase {

	private function s3_job( string $dir, string $state, array $extra = array() ): array {
		return $extra + array(
			'provider'  => 's3',
			'dir'       => $dir,
			'stateFile' => $state,
			'config'    => array( 'bucket' => 'b', 'region' => 'eu-west-1', 'accessKeyId' => 'k', 'secretAccessKey' => 's', 'prefix' => 'site' ),
		);
	}

	public function test_incremental_second_run_uploads_nothing_and_removes_deleted_files(): void {
		$dir   = $this->site( array( 'index.html' => 'one', 'a.css' => 'two', 'old.html' => 'gone-soon' ) );
		$state = $dir . '-state/s3.json';
		$t     = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );

		$first = $this->deploy( $this->s3_job( $dir, $state ) );
		$this->assertSame( 3, $first['result']['uploaded'] );
		$this->assertCount( 3, array_filter( $t->log(), static function ( $l ) { return 0 === strpos( $l, 'PUT ' ); } ) );

		$t->requests = array();
		$second      = $this->deploy( $this->s3_job( $dir, $state ) );
		$this->assertSame( 0, $second['result']['uploaded'] );
		$this->assertSame( array(), $t->requests );

		unlink( $dir . '/old.html' );
		file_put_contents( $dir . '/index.html', 'changed' );
		$third = $this->deploy( $this->s3_job( $dir, $state ) );
		$this->assertSame( 1, $third['result']['uploaded'] );
		$this->assertSame( 1, $third['result']['deleted'] );
		$this->assertNotEmpty( array_filter( $t->log(), static function ( $l ) { return false !== strpos( $l, '?delete=' ); } ) );
		$this->rm( $dir . '-state' );
	}

	public function test_a_crawl_that_lost_most_pages_does_not_wipe_the_site(): void {
		$files = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$files[ "p$i/index.html" ] = "page $i";
		}
		$dir   = $this->site( $files );
		$state = $dir . '-state/s3.json';
		Http::set_transport( new FakeTransport( static function () { return new Response( 200 ); } ) );
		$this->deploy( $this->s3_job( $dir, $state ) );

		for ( $i = 5; $i < 30; $i++ ) {
			unlink( "$dir/p$i/index.html" );
		}
		$t = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$res = $this->deploy( $this->s3_job( $dir, $state ) );

		$this->assertSame( 0, $res['result']['deleted'] );
		$this->assertStringContainsString( 'Refusing to delete 25 of 30', implode( ' ', $res['logs'] ) );
		$this->assertSame( array(), array_filter( $t->log(), static function ( $l ) { return false !== strpos( $l, 'delete' ); } ) );
		$this->rm( $dir . '-state' );
	}

	public function test_partial_scope_never_deletes_and_keeps_earlier_state(): void {
		$dir   = $this->site( array( 'a/index.html' => 'a', 'b/index.html' => 'b' ) );
		$state = $dir . '-state/s3.json';
		$t     = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$this->deploy( $this->s3_job( $dir, $state ) );

		unlink( "$dir/b/index.html" );
		file_put_contents( "$dir/a/index.html", 'a2' );
		$res = $this->deploy( $this->s3_job( $dir, $state, array( 'scope' => 'partial' ) ) );

		$this->assertSame( 1, $res['result']['uploaded'] );
		$this->assertSame( 0, $res['result']['deleted'] );
		$saved = Util::read_json( $state );
		$this->assertArrayHasKey( 'b/index.html', $saved, 'a single-page export must not forget the rest of the site' );
		$this->rm( $dir . '-state' );
	}

	public function test_ignored_files_are_not_deployed(): void {
		$dir = $this->site( array( 'index.html' => 'x', 'wp-content/uploads/wp-static-export/deploy-state/s.json' => '{}' ) );
		$t   = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$res = $this->deploy( $this->s3_job( $dir, $dir . '-state/s3.json', array( 'ignore' => array( '*/wp-static-export/deploy-state/*' ) ) ) );

		$this->assertSame( 1, $res['result']['uploaded'] );
		$this->rm( $dir . '-state' );
	}

	public function test_force_uploads_everything(): void {
		$dir   = $this->site();
		$state = $dir . '-state/s3.json';
		Http::set_transport( new FakeTransport( static function () { return new Response( 200 ); } ) );
		$this->deploy( $this->s3_job( $dir, $state ) );
		$res = $this->deploy( $this->s3_job( $dir, $state, array( 'force' => true ) ) );

		$this->assertSame( 2, $res['result']['uploaded'] );
		$this->rm( $dir . '-state' );
	}

	public function test_a_run_survives_being_split_into_many_tiny_steps(): void {
		$files = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$files[ "p$i.html" ] = "page $i";
		}
		$dir = $this->site( $files );
		$t   = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$res = $this->deploy( $this->s3_job( $dir, $dir . '-state/s3.json', array( 'concurrency' => 2 ) ), 0.0 );

		$this->assertSame( 25, $res['result']['uploaded'] );
		$this->assertCount( 25, array_unique( $t->log() ), 'every file is uploaded exactly once even when steps are cut short' );
		$this->rm( $dir . '-state' );
	}

	public function test_an_empty_export_is_refused(): void {
		$dir = $this->site( array() );
		$this->expectException( Deploy_Error::class );
		$this->expectExceptionMessage( 'empty' );
		$this->deploy( $this->s3_job( $dir, $dir . '-state/s3.json' ) );
	}

	public function test_missing_credentials_are_reported_before_anything_runs(): void {
		$this->expectException( Deploy_Error::class );
		$this->expectExceptionMessage( 'missing setting(s): accessKeyId' );
		Deploy_Run::start( array( 'provider' => 's3', 'dir' => sys_get_temp_dir(), 'config' => array( 'bucket' => 'b', 'region' => 'r', 'secretAccessKey' => 's' ) ) );
	}

	public function test_http_errors_are_readable_and_hide_query_strings(): void {
		Http::set_transport( new FakeTransport( static function () { return new Response( 403, '<Error>AccessDenied</Error>' ); } ) );
		try {
			Http::request( 'GET', 'https://x.test/p?token=secret' );
			$this->fail( 'expected an error' );
		} catch ( Deploy_Error $e ) {
			$this->assertStringContainsString( 'GET https://x.test/p failed: 403', $e->getMessage() );
			$this->assertStringNotContainsString( 'secret', $e->getMessage() );
		}
	}

	public function test_server_errors_are_retried(): void {
		$n = 0;
		Http::set_transport( new FakeTransport( static function () use ( &$n ) { return ++$n < 3 ? new Response( 503 ) : new Response( 200, 'ok' ); } ) );

		$this->assertSame( 'ok', Http::request( 'GET', 'https://x.test/' )->body );
		$this->assertSame( 3, $n );
	}
}
