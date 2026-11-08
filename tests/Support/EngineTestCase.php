<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Support;

use WP_Static_Export\Engine\Context;
use WP_Static_Export\Engine\Deploy_Run;
use WP_Static_Export\Engine\Http;

abstract class EngineTestCase extends UnitTestCase {

	/** @var string[] */
	private $tmp = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/src/engine/autoload.php';
		Http::$backoff = array( 0, 0, 0 );
	}

	protected function tearDown(): void {
		Http::set_transport( null );
		foreach ( $this->tmp as $dir ) {
			$this->rm( $dir );
		}
		$this->tmp = array();
		parent::tearDown();
	}

	protected function rm( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $dir );
	}

	/** A temp export folder with the given files (path => content). */
	protected function site( array $files = array( 'index.html' => '<h1>hi</h1>', 'a.css' => 'body{}' ) ): string {
		$dir = sys_get_temp_dir() . '/wpse-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0777, true );
		$this->tmp[] = $dir;
		foreach ( $files as $path => $content ) {
			if ( ! is_dir( dirname( "$dir/$path" ) ) ) {
				mkdir( dirname( "$dir/$path" ), 0777, true );
			}
			file_put_contents( "$dir/$path", $content );
		}

		return $dir;
	}

	/** Run a whole deployment to the end. Returns the result and every log line. */
	protected function deploy( array $job, float $step_seconds = 5.0, int $max_steps = 200 ): array {
		$job += array( 'concurrency' => 4 );
		$run  = Deploy_Run::start( $job );
		$logs = array();
		for ( $i = 0; $i < $max_steps; $i++ ) {
			$step = Deploy_Run::step( $run, $job, $step_seconds );
			foreach ( $step['logs'] as $l ) {
				$logs[] = $l[1];
			}
			if ( 'done' === $step['state'] ) {
				return array( 'result' => $step['result'], 'logs' => $logs, 'steps' => $i + 1 );
			}
		}
		$this->fail( 'The deployment did not finish.' );
	}

	/** Run a whole processing job to the end. */
	protected function process( array $job, float $step_seconds = 5.0, int $max_steps = 500 ): array {
		$job += array( 'scope' => 'full' );
		$run  = \WP_Static_Export\Engine\Process_Run::start( $job );
		$logs = array();
		for ( $i = 0; $i < $max_steps; $i++ ) {
			$step = \WP_Static_Export\Engine\Process_Run::step( $run, $job, $step_seconds );
			foreach ( $step['logs'] as $l ) {
				$logs[] = $l;
			}
			if ( 'done' === $step['state'] ) {
				return array( 'result' => $step['result'], 'logs' => $logs, 'steps' => $i + 1 );
			}
		}
		$this->fail( 'Processing did not finish.' );
	}

	/** Run the site check to the end. */
	protected function check_site( array $job, float $step_seconds = 5.0 ): array {
		$run = \WP_Static_Export\Engine\Site_Check::start( $job );
		for ( $i = 0; $i < 5000; $i++ ) {
			$step = \WP_Static_Export\Engine\Site_Check::step( $run, $job, $step_seconds );
			if ( 'done' === $step['state'] ) {
				return $step['result'];
			}
		}
		$this->fail( 'The site check did not finish.' );
	}

	protected function ctx( array $config, array $changed, array $deleted = array() ): Context {
		$ctx              = new Context();
		$ctx->config      = $config;
		$ctx->changed     = $changed;
		$ctx->all         = $changed;
		$ctx->deleted     = $deleted;
		$ctx->deadline    = microtime( true ) + 60;
		$ctx->concurrency = 4;

		return $ctx;
	}
}
