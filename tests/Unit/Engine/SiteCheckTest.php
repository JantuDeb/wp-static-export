<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit\Engine;

use WP_Static_Export\Engine\Site_Check;
use WP_Static_Export\Tests\Support\EngineTestCase;

final class SiteCheckTest extends EngineTestCase {

	private function page( string $body, string $title = 'T' ): string {
		return "<html><head><title>$title</title></head><body>$body</body></html>";
	}

	public function test_a_healthy_site_has_nothing_to_report(): void {
		$dir = $this->site(
			array(
				'index.html'       => $this->page( '<a href="/about/">a</a><a href="https://wp.test/about/">b</a><img src="/img/a.png"><a href="https://other.com/x">x</a>' ),
				'about/index.html' => $this->page( '<a href="../">home</a><script src="/app.js"></script>' ),
				'img/a.png'        => 'x',
				'app.js'           => 'x',
			)
		);
		$r = $this->check_site( array( 'dir' => $dir, 'hosts' => array( 'wp.test' ) ) );

		$this->assertSame( array(), $r['broken_links'] );
		$this->assertSame( 2, $r['checked'] );
		$this->assertSame( 0, $r['no_title'] );
	}

	public function test_broken_links_are_reported_once_with_where_they_were_found(): void {
		$dir = $this->site(
			array(
				'index.html'   => $this->page( '<a href="/gone/">x</a><a href="/gone/#top">y</a><img src="/missing.png">' ),
				'b/index.html' => $this->page( '<a href="/gone/">z</a>' ),
			)
		);
		$r  = $this->check_site( array( 'dir' => $dir ) );
		$to = array_column( $r['broken_links'], 'to' );
		sort( $to );

		$this->assertSame( 2, $r['broken_total'] );
		$this->assertSame( array( '/gone/', '/missing.png' ), $to );
	}

	public function test_error_pages_empty_files_missing_titles_and_wordpress_only_links_are_flagged(): void {
		$dir = $this->site(
			array(
				'index.html'      => '<html><body><a href="/wp-json/wp/v2/posts">api</a><link rel="alternate" href="/wp-json/oembed/1.0/embed"><a href="/wp-login.php?redirect_to=%2Fa%2F">log in</a></body></html>',
				'oops/index.html' => $this->page( '<p>There has been a critical error on this website.</p>' ),
				'404.html'        => $this->page( '<p>There has been a critical error on this website.</p>' ),
				'blank.txt'       => '',
			)
		);
		$r = $this->check_site( array( 'dir' => $dir ) );

		$this->assertSame( array( 'oops/index.html' ), array_column( $r['error_pages'], 'path' ) );
		$this->assertSame( array( 'blank.txt' ), $r['empty_files'] );
		$this->assertSame( 1, $r['no_title'] );
		$this->assertSame( array( '/wp-json/wp/v2/posts', '/wp-login.php?redirect_to=%2Fa%2F' ), array_column( $r['wp_only'], 'url' ) );
	}

	public function test_x_is_served_as_x_html_and_redirect_pages_need_no_title(): void {
		$dir = $this->site(
			array(
				'index.html'     => $this->page( '<a href="/x">x</a>' ),
				'x.html'         => $this->page( '' ),
				'old/index.html' => '<html><head><meta http-equiv="refresh" content="0;url=/"></head></html>',
			)
		);
		$r = $this->check_site( array( 'dir' => $dir ) );

		$this->assertSame( array(), $r['broken_links'] );
		$this->assertSame( 0, $r['no_title'] );
	}

	public function test_it_stops_at_the_limit_and_says_so(): void {
		$dir = $this->site( array( 'a/index.html' => $this->page( '' ), 'b/index.html' => $this->page( '' ), 'c/index.html' => $this->page( '' ) ) );
		$r   = $this->check_site( array( 'dir' => $dir, 'maxFiles' => 2 ) );

		$this->assertSame( 2, $r['checked'] );
		$this->assertTrue( $r['truncated'] );
	}

	public function test_error_signature_recognises_php_errors_but_not_prose_about_them(): void {
		$this->assertSame( 'PHP error', Site_Check::error_signature( '<br /><b>Fatal error</b>: Uncaught Error' ) );
		$this->assertNull( Site_Check::error_signature( '<p>How to fix a fatal error in your code</p>' ) );
	}

	public function test_the_check_can_be_split_into_many_steps(): void {
		$files = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$files[ "p$i/index.html" ] = $this->page( '<a href="/nope' . $i . '/">x</a>' );
		}
		$dir = $this->site( $files );
		$r   = $this->check_site( array( 'dir' => $dir ), 0.0 );

		$this->assertSame( 20, $r['checked'] );
		$this->assertSame( 20, $r['broken_total'] );
	}
}
