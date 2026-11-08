<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Performance;

use WP_Static_Export\Engine\Blake3;
use WP_Static_Export\Engine\Http;
use WP_Static_Export\Engine\Image_Optimizer;
use WP_Static_Export\Engine\Request;
use WP_Static_Export\Engine\Response;
use WP_Static_Export\Engine\Transport;
use WP_Static_Export\Tests\Support\EngineTestCase;

/**
 * Budgets for the engine at scale. They are generous (a slow CI machine passes) but catch an accidental
 * quadratic loop or a memory blow-up. Run with: WPSE_PERF=1 vendor/bin/phpunit tests/Performance
 * Set WPSE_PERF_PAGES to change the size (default 1500 pages of about 40 KB).
 */
final class EnginePerformanceTest extends EngineTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! getenv( 'WPSE_PERF' ) ) {
			$this->markTestSkipped( 'Set WPSE_PERF=1 to run the performance tests.' );
		}
	}

	/** Answers 200 to everything and remembers nothing (FakeTransport would keep every request body in memory). */
	private function silent_transport(): Transport {
		return new class() implements Transport {
			public function send( Request $request ) {
				return new Response( 200 );
			}

			public function send_many( array $requests ) {
				return array_map( static function () { return new Response( 200 ); }, $requests );
			}
		};
	}

	private function pages(): int {
		return (int) ( getenv( 'WPSE_PERF_PAGES' ) ?: 1500 );
	}

	/** @return array<string,string> */
	private function make_site( int $n, int $kb = 40 ): array {
		$files = array();
		$block = "<div class=\"block\">\n  <p>Readable text with   several   words and <b>bold</b> parts. <a href=\"/p1/\" target=\"_blank\">more</a></p>\n</div>\n";
		$body  = str_repeat( $block, (int) ( $kb * 1024 / strlen( $block ) ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$files[ "p$i/index.html" ] = "<!DOCTYPE html>\n<html>\n<head>\n  <title>Page $i</title>\n</head>\n<body>\n<main>\n<h1>Page $i</h1>\n$body<img src=\"/img/a.jpg\">\n</main>\n</body>\n</html>\n";
		}

		return $files;
	}

	private function peak_mb(): float {
		return memory_get_peak_usage() / 1048576;
	}

	private function reset_peak(): void {
		gc_collect_cycles();
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
	}

	public function test_a_big_deploy_is_fast_and_a_second_run_does_no_work(): void {
		$n   = $this->pages();
		$dir = $this->site( $this->make_site( $n ) );
		Http::set_transport( $this->silent_transport() );
		$job = array( 'provider' => 's3', 'dir' => $dir, 'stateFile' => $dir . '-state/s3.json', 'concurrency' => 8, 'config' => array( 'bucket' => 'b', 'region' => 'r', 'accessKeyId' => 'k', 'secretAccessKey' => 's' ) );

		$this->reset_peak();
		$t     = microtime( true );
		$first = $this->deploy( $job, 8.0 );
		$took  = microtime( true ) - $t;
		$this->assertSame( $n, $first['result']['uploaded'] );
		$this->assertLessThan( 4 + $n * 0.004, $took, "deploying $n pages took {$took}s" );

		$t      = microtime( true );
		$second = $this->deploy( $job, 8.0 );
		$this->assertSame( 0, $second['result']['uploaded'] );
		$this->assertLessThan( 2 + $n * 0.002, microtime( true ) - $t, 'an unchanged deploy should only hash the files' );
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			$this->assertLessThan( 48, $this->peak_mb(), 'deploy memory should not grow with the number of files' );
		}
		$this->rm( $dir . '-state' );
	}

	public function test_many_tiny_steps_do_not_make_a_run_quadratic(): void {
		$n   = 600;
		$dir = $this->site( $this->make_site( $n, 2 ) );
		Http::set_transport( $this->silent_transport() );
		$job = array( 'provider' => 's3', 'dir' => $dir, 'concurrency' => 4, 'config' => array( 'bucket' => 'b', 'region' => 'r', 'accessKeyId' => 'k', 'secretAccessKey' => 's' ) );

		$t   = microtime( true );
		$res = $this->deploy( $job, 0.0, 5000 );
		$this->assertSame( $n, $res['result']['uploaded'] );
		$this->assertLessThan( 60, microtime( true ) - $t, "{$res['steps']} steps for $n files" );
	}

	public function test_the_search_index_is_built_without_holding_the_site_in_memory(): void {
		$n    = $this->pages();
		$site = $this->site( $this->make_site( $n, 8 ) );
		$this->reset_peak();
		$t = microtime( true );
		$this->process( array( 'dir' => $site, 'ops' => array( 'search' => array( 'engine' => 'fuse' ) ) ), 8.0, 5000 );
		$took = microtime( true ) - $t;

		$this->assertLessThan( 3 + $n * 0.01, $took, "indexing $n pages took {$took}s" );
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			$this->assertLessThan( 64, $this->peak_mb(), 'index memory should be flat, not proportional to the index size' );
		}
	}

	public function test_minifying_and_fixing_pages_scales_linearly(): void {
		$per = array();
		foreach ( array( 300, 1200 ) as $n ) {
			$dir = $this->site( $this->make_site( $n, 30 ) );
			$t   = microtime( true );
			$this->process( array( 'dir' => $dir, 'ops' => array( 'fixes' => array( 'viewport' => true, 'lang' => 'en', 'imageAlt' => true, 'lazyImages' => true, 'noopener' => true ), 'optimize' => array( 'html' => true ) ) ), 8.0, 5000 );
			$per[ $n ] = ( microtime( true ) - $t ) / $n;
			@unlink( \WP_Static_Export\Engine\Process_Run::state_file( $dir ) );
		}
		// Four times the pages must not cost more than twice as much per page.
		$this->assertLessThan( $per[300] * 2 + 0.002, $per[1200], sprintf( 'per page: %.2f ms at 300, %.2f ms at 1200', $per[300] * 1000, $per[1200] * 1000 ) );
		$this->assertLessThan( 0.05, $per[1200], 'under 50 ms per 30 KB page' );
	}

	public function test_the_site_check_scales_linearly(): void {
		$per = array();
		foreach ( array( 500, 2000 ) as $n ) {
			$dir = $this->site( $this->make_site( $n, 20 ) );
			$t   = microtime( true );
			$this->check_site( array( 'dir' => $dir ), 8.0 );
			$per[ $n ] = ( microtime( true ) - $t ) / $n;
		}
		$this->assertLessThan( $per[500] * 2 + 0.002, $per[2000], sprintf( 'per page: %.2f ms at 500, %.2f ms at 2000', $per[500] * 1000, $per[2000] * 1000 ) );
	}

	public function test_blake3_hashes_at_a_usable_speed(): void {
		$data = random_bytes( 2 * 1024 * 1024 );
		$t    = microtime( true );
		Blake3::hash( $data );
		$mbps = 2 / ( microtime( true ) - $t );
		$this->assertGreaterThan( 2.0, $mbps, sprintf( 'BLAKE3 ran at %.1f MB/s', $mbps ) );
	}

	public function test_images_are_recompressed_fast_enough(): void {
		if ( ! Image_Optimizer::available() ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$img = imagecreatetruecolor( 1200, 800 );
		for ( $y = 0; $y < 800; $y += 4 ) {
			imagefilledrectangle( $img, 0, $y, 1200, $y + 4, imagecolorallocate( $img, $y % 256, ( $y * 3 ) % 256, 90 ) );
		}
		ob_start();
		imagejpeg( $img, null, 98 );
		$jpeg = (string) ob_get_clean();

		$t = microtime( true );
		for ( $i = 0; $i < 5; $i++ ) {
			Image_Optimizer::optimize( $jpeg, 'jpg', 80, true );
		}
		$each = ( microtime( true ) - $t ) / 5;
		$this->assertLessThan( 1.5, $each, sprintf( 'a 1200x800 image took %.2f s to recompress with a WebP twin', $each ) );
	}
}
