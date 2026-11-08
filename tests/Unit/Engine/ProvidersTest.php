<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit\Engine;

use WP_Static_Export\Engine\Cloudflare_Hash;
use WP_Static_Export\Engine\Deploy_Error;
use WP_Static_Export\Engine\Deploy_Run;
use WP_Static_Export\Engine\Http;
use WP_Static_Export\Engine\Provider_Netlify;
use WP_Static_Export\Engine\Provider_Sftp;
use WP_Static_Export\Engine\Response;
use WP_Static_Export\Tests\Support\EngineTestCase;
use WP_Static_Export\Tests\Support\FakeTransport;

final class ProvidersTest extends EngineTestCase {

	protected function setUp(): void {
		parent::setUp();
		Provider_Netlify::$poll_wait = 0;
	}

	protected function tearDown(): void {
		Provider_Sftp::$factory = null;
		parent::tearDown();
	}

	public function test_github_creates_blobs_tree_commit_and_moves_the_ref(): void {
		$dir   = $this->site();
		$state = $dir . '-state/gh.json';
		$t     = new FakeTransport(
			static function ( $r ) {
				$p = parse_url( $r->url, PHP_URL_PATH );
				$p = str_replace( '/repos/o/r', '', $p );
				$b = $r->body ? json_decode( $r->body, true ) : array();
				switch ( $r->method . ' ' . $p ) {
					case 'GET /git/ref/heads/main':
						return array( 'object' => array( 'sha' => 'head1' ) );
					case 'GET /git/commits/head1':
						return array( 'tree' => array( 'sha' => 'tree0' ) );
					case 'POST /git/blobs':
						return array( 'sha' => 'blob' . substr( md5( $b['content'] ), 0, 6 ) );
					case 'POST /git/trees':
						return array( 'sha' => 'tree1' );
					case 'POST /git/commits':
						return array( 'sha' => 'commit1abcdef' );
					case 'PATCH /git/refs/heads/main':
						return array();
				}

				return new Response( 404, 'unexpected ' . $r->method . ' ' . $p );
			}
		);
		Http::set_transport( $t );
		$job = array( 'provider' => 'github', 'dir' => $dir, 'stateFile' => $state, 'config' => array( 'token' => 't', 'repository' => 'o/r', 'branch' => 'main', 'folder' => 'public' ) );

		$res = $this->deploy( $job );

		$this->assertSame( 2, $res['result']['uploaded'] );
		$this->assertSame( 'https://github.com/o/r/commit/commit1abcdef', $res['result']['url'] );
		$tree = null;
		foreach ( $t->requests as $r ) {
			if ( substr( $r->url, -10 ) === '/git/trees' ) {
				$tree = json_decode( $r->body, true );
			}
		}
		$this->assertSame( 'tree0', $tree['base_tree'] );
		$paths = array_column( $tree['tree'], 'path' );
		sort( $paths );
		$this->assertSame( array( 'public/a.css', 'public/index.html' ), $paths );
		$this->assertSame( 'Bearer t', $t->requests[0]->headers['Authorization'] );

		$t->requests = array();
		$again       = $this->deploy( $job );
		$this->assertSame( 'Nothing to deploy', $again['result']['message'] );
		$this->assertNotContains( 'POST /git/commits', $t->log( '/repos/o/r' ), 'an unchanged export must not create an empty commit' );
		$this->rm( $dir . '-state' );
	}

	public function test_github_deletes_removed_files_with_null_shas_and_initializes_an_empty_repo(): void {
		$dir   = $this->site( array( 'keep.html' => 'k', 'old.html' => 'o' ) );
		$state = $dir . '-state/gh.json';
		$calls = array();
		$empty = true;
		Http::set_transport(
			new FakeTransport(
				static function ( $r ) use ( &$calls, &$empty ) {
					$p       = str_replace( '/repos/o/r', '', parse_url( $r->url, PHP_URL_PATH ) );
					$calls[] = $r->method . ' ' . $p;
					if ( 'GET /git/ref/heads/main' === $r->method . ' ' . $p ) {
						if ( $empty ) {
							$empty = false;

							return new Response( 409, '{"message":"Git Repository is empty."}' );
						}

						return array( 'object' => array( 'sha' => 'h' ) );
					}
					if ( 'GET /git/commits/h' === $r->method . ' ' . $p ) {
						return array( 'tree' => array( 'sha' => 't0' ) );
					}
					if ( 'POST /git/trees' === $r->method . ' ' . $p ) {
						$GLOBALS['wpse_tree'] = json_decode( $r->body, true );

						return array( 'sha' => 't1' );
					}
					if ( 'POST /git/blobs' === $r->method . ' ' . $p ) {
						return array( 'sha' => 'b' );
					}
					return array( 'sha' => 'c1c1c1c1' );
				}
			)
		);
		$job = array( 'provider' => 'github', 'dir' => $dir, 'stateFile' => $state, 'config' => array( 'token' => 't', 'repository' => 'o/r' ) );
		$this->deploy( $job );
		$this->assertContains( 'PUT /contents/.nojekyll', $calls );

		unlink( $dir . '/old.html' );
		$this->deploy( $job );
		$null = array_filter( $GLOBALS['wpse_tree']['tree'], static function ( $e ) { return null === $e['sha']; } );
		$this->assertSame( array( 'old.html' ), array_column( $null, 'path' ) );
		$this->rm( $dir . '-state' );
	}

	public function test_github_and_cloudflare_resume_without_repeating_uploads_when_steps_are_cut_short(): void {
		$files = array();
		for ( $i = 0; $i < 9; $i++ ) {
			$files[ "p$i.html" ] = "page $i";
		}
		$dir   = $this->site( $files );
		$blobs = array();
		Http::set_transport(
			new FakeTransport(
				static function ( $r ) use ( &$blobs ) {
					$p = str_replace( '/repos/o/r', '', parse_url( $r->url, PHP_URL_PATH ) );
					if ( 'GET /git/ref/heads/main' === $r->method . ' ' . $p ) {
						return array( 'object' => array( 'sha' => 'h' ) );
					}
					if ( 'GET /git/commits/h' === $r->method . ' ' . $p ) {
						return array( 'tree' => array( 'sha' => 't0' ) );
					}
					if ( '/git/blobs' === $p ) {
						$blobs[] = $r->body;

						return array( 'sha' => 'b' . count( $blobs ) );
					}
					return array( 'sha' => 'x1x1x1x1' );
				}
			)
		);
		$res = $this->deploy( array( 'provider' => 'github', 'dir' => $dir, 'concurrency' => 2, 'config' => array( 'token' => 't', 'repository' => 'o/r' ) ), 0.0 );

		$this->assertSame( 9, $res['result']['uploaded'] );
		$this->assertCount( 9, $blobs );
		$this->assertCount( 9, array_unique( $blobs ), 'every blob is created exactly once' );
		$this->assertGreaterThan( 3, $res['steps'] );
	}

	public function test_github_rejects_a_malformed_repository(): void {
		$this->expectException( Deploy_Error::class );
		Deploy_Run::start( array( 'provider' => 'github', 'dir' => sys_get_temp_dir(), 'config' => array( 'token' => 't', 'repository' => 'nope' ) ) );
	}

	public function test_netlify_uploads_only_files_it_is_missing_and_waits_until_ready(): void {
		$dir = $this->site();
		$sha = sha1( '<h1>hi</h1>' );
		$t   = new FakeTransport(
			static function ( $r ) use ( $sha ) {
				$p = str_replace( '/api/v1', '', parse_url( $r->url, PHP_URL_PATH ) );
				if ( 'POST' === $r->method && '/sites/s1/deploys' === $p ) {
					$body = json_decode( $r->body, true );
					\PHPUnit\Framework\Assert::assertSame( $sha, $body['files']['/index.html'] );

					return array( 'id' => 'd1', 'required' => array( $sha ) );
				}
				if ( 'PUT' === $r->method ) {
					return new Response( 200 );
				}
				static $polls = 0;
				return ++$polls < 2 ? array( 'state' => 'processing' ) : array( 'state' => 'ready', 'ssl_url' => 'https://x.netlify.app' );
			}
		);
		Http::set_transport( $t );
		$res = $this->deploy( array( 'provider' => 'netlify', 'dir' => $dir, 'config' => array( 'token' => 't', 'siteId' => 's1' ) ) );

		$puts = array_filter( $t->log( '/api/v1' ), static function ( $l ) { return 0 === strpos( $l, 'PUT ' ); } );
		$this->assertSame( array( 'PUT /deploys/d1/files/index.html' ), array_values( $puts ) );
		$this->assertSame( 'https://x.netlify.app', $res['result']['url'] );
	}

	public function test_netlify_reports_a_failed_deploy(): void {
		$dir = $this->site();
		Http::set_transport(
			new FakeTransport(
				static function ( $r ) {
					if ( 'POST' === $r->method ) {
						return array( 'id' => 'd', 'required' => array() );
					}

					return array( 'state' => 'error', 'error_message' => 'bad file' );
				}
			)
		);
		$this->expectExceptionMessage( 'Netlify deploy failed: bad file' );
		$this->deploy( array( 'provider' => 'netlify', 'dir' => $dir, 'config' => array( 'token' => 't', 'siteId' => 's1' ) ) );
	}

	public function test_cloudflare_hash_is_32_hex_chars_and_depends_on_the_extension(): void {
		$a = Cloudflare_Hash::hash( 'x', 'a.css' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $a );
		$this->assertNotSame( $a, Cloudflare_Hash::hash( 'x', 'a.js' ) );
	}

	public function test_cloudflare_pages_uploads_missing_assets_then_creates_the_deployment(): void {
		$dir = $this->site( array( 'index.html' => 'hi', '_headers' => '/*' . "\n" . '  X-Test: 1' ) );
		$t   = new FakeTransport(
			function ( $r ) {
				$p = parse_url( $r->url, PHP_URL_PATH );
				if ( false !== strpos( $p, '/upload-token' ) ) {
					return array( 'result' => array( 'jwt' => 'JWT' ) );
				}
				if ( false !== strpos( $p, 'check-missing' ) ) {
					return array( 'result' => json_decode( $r->body, true )['hashes'] );
				}
				if ( false !== strpos( $p, '/deployments' ) ) {
					$this->assertStringContainsString( 'name="manifest"', $r->body );
					$this->assertStringContainsString( 'name="_headers"', $r->body );
					$this->assertStringNotContainsString( '"/_headers"', $r->body, 'special files are not assets' );

					return array( 'success' => true, 'result' => array( 'url' => 'https://abc.pages.dev' ) );
				}

				return array( 'success' => true );
			}
		);
		Http::set_transport( $t );
		$res = $this->deploy( array( 'provider' => 'cloudflare-pages', 'dir' => $dir, 'config' => array( 'apiToken' => 't', 'accountId' => 'acc', 'project' => 'p' ) ) );

		$this->assertSame( 1, $res['result']['uploaded'] );
		$this->assertSame( 'https://abc.pages.dev', $res['result']['url'] );
		$upload = array_values( array_filter( $t->requests, static function ( $r ) { return false !== strpos( $r->url, 'assets/upload' ); } ) );
		$this->assertCount( 1, $upload );
		$this->assertSame( 'Bearer JWT', $upload[0]->headers['Authorization'] );
		$this->assertSame( base64_encode( 'hi' ), json_decode( $upload[0]->body, true )[0]['value'] );
	}

	public function test_cloudflare_workers_opens_a_session_uploads_buckets_and_publishes_with_the_completion_token(): void {
		$dir = $this->site();
		$t   = new FakeTransport(
			function ( $r ) {
				$p = str_replace( '/client/v4/accounts/acc/workers', '', parse_url( $r->url, PHP_URL_PATH ) );
				if ( '/scripts/site/assets-upload-session' === $p ) {
					$manifest = json_decode( $r->body, true )['manifest'];
					$this->assertSame( array( '/a.css', '/index.html' ), array_keys( $manifest ) );
					$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $manifest['/a.css']['hash'] );

					return array( 'success' => true, 'result' => array( 'jwt' => 'upload-jwt', 'buckets' => array( array( $manifest['/index.html']['hash'] ) ) ) );
				}
				if ( '/assets/upload' === $p ) {
					$this->assertSame( 'Bearer upload-jwt', $r->headers['Authorization'] );

					return new Response( 201, json_encode( array( 'result' => array( 'jwt' => 'done-jwt' ) ) ) );
				}
				if ( '/scripts/site' === $p ) {
					$this->assertMatchesRegularExpression( '/"jwt":"done-jwt"/', $r->body );

					return array( 'success' => true );
				}

				return new Response( 404 );
			}
		);
		Http::set_transport( $t );
		$res = $this->deploy( array( 'provider' => 'cloudflare-workers', 'dir' => $dir, 'config' => array( 'apiToken' => 't', 'accountId' => 'acc', 'project' => 'site' ) ) );

		$this->assertSame( 1, $res['result']['uploaded'] );
		$this->assertSame( array( 'POST /scripts/site/assets-upload-session', 'POST /assets/upload?base64=true', 'PUT /scripts/site' ), $t->log( '/client/v4/accounts/acc/workers' ) );
	}

	public function test_cloudflare_workers_skips_the_upload_when_nothing_is_needed(): void {
		$dir = $this->site();
		$jwt = null;
		Http::set_transport(
			new FakeTransport(
				static function ( $r ) use ( &$jwt ) {
					if ( substr( $r->url, -21 ) === 'assets-upload-session' ) {
						return array( 'success' => true, 'result' => array( 'jwt' => 'complete', 'buckets' => array() ) );
					}
					$jwt = $r->body;

					return array( 'success' => true );
				}
			)
		);
		$res = $this->deploy( array( 'provider' => 'cloudflare-workers', 'dir' => $dir, 'config' => array( 'apiToken' => 't', 'accountId' => 'acc', 'project' => 'site' ) ) );

		$this->assertSame( 0, $res['result']['uploaded'] );
		$this->assertStringContainsString( '"jwt":"complete"', $jwt );
	}

	public function test_cloudflare_workers_verify_tells_whether_the_worker_exists(): void {
		Http::set_transport( new FakeTransport( static function () { return array( 'result' => array( array( 'id' => 'site' ) ) ); } ) );
		$this->assertStringContainsString( 'exists and will be updated', Deploy_Run::verify( array( 'provider' => 'cloudflare-workers', 'config' => array( 'apiToken' => 't', 'accountId' => 'a', 'project' => 'site' ) ) ) );
		$this->assertStringContainsString( 'will be created', Deploy_Run::verify( array( 'provider' => 'cloudflare-workers', 'config' => array( 'apiToken' => 't', 'accountId' => 'a', 'project' => 'other' ) ) ) );
	}

	public function test_cloudflare_hashes_are_cached_between_deploys(): void {
		$dir   = $this->site();
		$state = $dir . '-state/cf.json';
		Http::set_transport( new FakeTransport( static function ( $r ) { return substr( $r->url, -21 ) === 'assets-upload-session' ? array( 'result' => array( 'jwt' => 'j', 'buckets' => array() ) ) : array( 'success' => true ); } ) );
		$job = array( 'provider' => 'cloudflare-workers', 'dir' => $dir, 'stateFile' => $state, 'config' => array( 'apiToken' => 't', 'accountId' => 'acc', 'project' => 'site' ) );
		$this->deploy( $job );

		$cache = json_decode( (string) file_get_contents( $dir . '-state/cf.cloudflare-workers-cache.json' ), true );
		$this->assertCount( 2, $cache );
		$this->rm( $dir . '-state' );
	}

	public function test_bunny_puts_files_deletes_removed_ones_and_purges_the_cache(): void {
		$dir   = $this->site();
		$state = $dir . '-state/b.json';
		mkdir( dirname( $state ), 0777, true );
		file_put_contents( $state, json_encode( array( 'old.html' => 'x' ) ) );
		$t = new FakeTransport( static function () { return new Response( 200 ); } );
		Http::set_transport( $t );
		$this->deploy( array( 'provider' => 'bunny', 'dir' => $dir, 'stateFile' => $state, 'config' => array( 'storageKey' => 'k', 'storageZone' => 'zone', 'region' => 'ny', 'apiKey' => 'api', 'pullZoneId' => 7 ) ) );

		$log = $t->log();
		sort( $log );
		$this->assertSame( array( 'DELETE /zone/old.html', 'POST /pullzone/7/purgeCache', 'PUT /zone/a.css', 'PUT /zone/index.html' ), $log );
		$this->assertSame( 'k', $t->requests[0]->headers['AccessKey'] );
		$this->assertStringContainsString( 'ny.storage.bunnycdn.com', $t->requests[0]->url );
		$this->rm( $dir . '-state' );
	}

	public function test_sftp_creates_folders_uploads_through_a_temp_name_and_deletes(): void {
		$dir   = $this->site( array( 'a/b/index.html' => 'x', 'root.txt' => 'r' ) );
		$state = $dir . '-state/sftp.json';
		mkdir( dirname( $state ), 0777, true );
		file_put_contents( $state, json_encode( array( 'gone.html' => 'z' ) ) );
		$fake = new class() {
			public $log = array();
			public function login( $u, $k ) { $this->log[] = "login $u"; return true; }
			public function is_dir( $d ) { return '/var/www' === $d; }
			public function mkdir( $d, $m = -1, $r = false ) { $this->log[] = "mkdir $d"; return true; }
			public function put( $p, $data ) { $this->log[] = "put $p"; return true; }
			public function rename( $a, $b ) { $this->log[] = "rename $b"; return true; }
			public function delete( $p ) { $this->log[] = "delete $p"; return true; }
		};
		Provider_Sftp::$factory = static function () use ( $fake ) { return $fake; };

		$res = $this->deploy( array( 'provider' => 'sftp', 'dir' => $dir, 'stateFile' => $state, 'config' => array( 'host' => 'h', 'username' => 'u', 'password' => 'p', 'folder' => '/var/www/' ) ) );

		$this->assertSame( 2, $res['result']['uploaded'] );
		$this->assertContains( 'mkdir /var/www/a/b', $fake->log );
		$this->assertContains( 'put /var/www/a/b/index.html.part', $fake->log );
		$this->assertContains( 'rename /var/www/a/b/index.html', $fake->log );
		$this->assertContains( 'delete /var/www/gone.html', $fake->log );
		$this->rm( $dir . '-state' );
	}

	public function test_sftp_needs_a_password_or_key(): void {
		$this->expectExceptionMessage( 'provide a password or privateKey' );
		Deploy_Run::start( array( 'provider' => 'sftp', 'dir' => sys_get_temp_dir(), 'config' => array( 'host' => 'h', 'username' => 'u', 'folder' => '/x' ) ) );
	}

	public function test_sftp_login_failure_is_readable(): void {
		$dir = $this->site();
		Provider_Sftp::$factory = static function () {
			return new class() {
				public function login( $u, $k ) { return false; }
			};
		};
		$this->expectExceptionMessage( 'SFTP login to h failed' );
		$this->deploy( array( 'provider' => 'sftp', 'dir' => $dir, 'config' => array( 'host' => 'h', 'username' => 'u', 'password' => 'p', 'folder' => '/x' ) ) );
	}
}
