<?php
/**
 * Benchmarks the engine on a synthetic export, outside WordPress.
 *
 * Usage: php scripts/bench.php [pages=5000] [step-seconds=8] [images=0] [page-kb=30]
 *
 * Measures each stage's wall time, the number of steps it needed, peak memory and the size of the run file that is
 * rewritten every step. HTTP is replaced by an in-process fake so the numbers are the engine's own overhead.
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/src/engine/autoload.php';

use WP_Static_Export\Engine\Deploy_Run;
use WP_Static_Export\Engine\Http;
use WP_Static_Export\Engine\Process_Run;
use WP_Static_Export\Engine\Request;
use WP_Static_Export\Engine\Response;
use WP_Static_Export\Engine\Site_Check;
use WP_Static_Export\Engine\Transport;

$pages  = (int) ( $argv[1] ?? 5000 );
$budget = (float) ( $argv[2] ?? 8 );
$images = (int) ( $argv[3] ?? 0 );
$kb     = (int) ( $argv[4] ?? 30 ); // Page size in KB; real WordPress pages are 40 to 250 KB.

class Bench_Transport implements Transport {
	public $count = 0;
	public function send( Request $r ) {
		++$this->count;

		return new Response( 200, '{}' );
	}
	public function send_many( array $reqs ) {
		$out = array();
		foreach ( $reqs as $k => $r ) {
			$out[ $k ] = $this->send( $r );
		}

		return $out;
	}
}

function rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
}

function page( $i, $pages ) {
	$links = '';
	for ( $k = 1; $k <= 12; $k++ ) {
		$links .= '<a href="/p' . ( ( $i * $k ) % $pages ) . '/">link ' . $k . '</a> ';
	}
	$text = str_repeat( "<div class=\"block\">\n      <p>Paragraph $i of readable text with   several   words and <b>bold</b> parts for the index. <a href=\"/p" . ( ( $i + 7 ) % $pages ) . "/\" target=\"_blank\">more</a></p>\n    </div>\n    ", (int) ( $GLOBALS["kb"] * 1024 / 190 ) );

	return "<!DOCTYPE html>\n<html>\n<head>\n  <title>Page $i</title>\n  <style>\n    body { margin : 0 ; }\n  </style>\n</head>\n<body>\n  <header><nav>$links</nav></header>\n  <main>\n    <h1>Page $i</h1>\n    $text\n    <img src=\"/img/i" . ( $i % 50 ) . ".jpg\">\n  </main>\n</body>\n</html>\n";
}

$root = sys_get_temp_dir() . '/wpse-bench-' . getmypid();
$dir  = $root . '/site';
mkdir( $dir . '/img', 0777, true );
mkdir( $root . '/state', 0777, true );

$t0 = microtime( true );
for ( $i = 0; $i < $pages; $i++ ) {
	mkdir( "$dir/p$i" );
	file_put_contents( "$dir/p$i/index.html", page( $i, $pages ) );
}
for ( $i = 0; $i < 50; $i++ ) {
	$img = imagecreatetruecolor( 400, 300 );
	for ( $k = 0; $k < 300; $k += 3 ) {
		imagefilledrectangle( $img, 0, $k, 400, $k + 3, imagecolorallocate( $img, ( $i * 5 + $k ) % 256, $k % 256, ( $k * 2 ) % 256 ) );
	}
	imagejpeg( $img, "$dir/img/i$i.jpg", 95 );
}
file_put_contents( "$dir/a.css", str_repeat( "a { color : red ; }\n", 500 ) );
printf( "synthetic site: %d pages, %.1f s to generate\n\n", $pages, microtime( true ) - $t0 );

$rows = array();
$run  = static function ( $label, $callable ) use ( &$rows ) {
	gc_collect_cycles();
	memory_reset_peak_usage();
	$start = microtime( true );
	$info  = $callable();
	$rows[] = array( $label, microtime( true ) - $start, memory_get_peak_usage() / 1048576, $info['steps'] ?? 0, $info['note'] ?? '' );
};
if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
	function memory_reset_peak_usage() {}
}

$transport = new Bench_Transport();
Http::set_transport( $transport );
$job = array(
	'provider'    => 's3',
	'dir'         => $dir,
	'stateFile'   => $root . '/state/s3.json',
	'concurrency' => 8,
	'config'      => array( 'bucket' => 'b', 'region' => 'r', 'accessKeyId' => 'k', 'secretAccessKey' => 's' ),
);

$deploy = static function () use ( &$job, $budget, $root, $transport ) {
	$r     = Deploy_Run::start( $job );
	$steps = 0;
	$size  = 0;
	do {
		$s = Deploy_Run::step( $r, $job, $budget );
		++$steps;
		$size = max( $size, is_file( $r['file'] ) ? filesize( $r['file'] ) : 0 );
	} while ( 'done' !== $s['state'] );

	return array( 'steps' => $steps, 'note' => sprintf( '%d uploaded, run file up to %.1f MB, %d requests', $s['result']['uploaded'], $size / 1048576, $transport->count ) );
};
$run( 'deploy (first, full)', $deploy );
$transport->count = 0;
$run( 'deploy (second, unchanged)', $deploy );

$proc = static function ( $ops ) use ( $dir, $budget, $root ) {
	$job   = array( 'dir' => $dir, 'ops' => $ops, 'runDir' => $root . '/state', 'scope' => 'full' );
	$r     = Process_Run::start( $job );
	$steps = 0;
	do {
		$s = Process_Run::step( $r, $job, $budget );
		++$steps;
	} while ( 'done' !== $s['state'] );
	@unlink( Process_Run::state_file( $dir ) );

	return array( 'steps' => $steps, 'note' => substr( $s['result']['message'], 0, 90 ) );
};
$run( 'process: fixes', static function () use ( $proc ) {
	return $proc( array( 'fixes' => array( 'viewport' => true, 'lang' => 'en', 'imageAlt' => true, 'lazyImages' => true, 'noopener' => true, 'fontDisplay' => true ) ) );
} );
$run( 'process: minify html+css', static function () use ( $proc ) {
	return $proc( array( 'optimize' => array( 'html' => true, 'css' => true ) ) );
} );
$run( 'process: search index', static function () use ( $proc ) {
	return $proc( array( 'search' => array( 'engine' => 'fuse' ) ) );
} );
if ( $images ) {
	$run( 'process: images + webp', static function () use ( $proc ) {
		return $proc( array( 'optimize' => array( 'images' => true, 'webp' => true, 'quality' => 80 ) ) );
	} );
}
$run( 'site check', static function () use ( $dir, $budget, $root ) {
	$job   = array( 'dir' => $dir, 'runDir' => $root . '/state' );
	$r     = Site_Check::start( $job );
	$steps = 0;
	do {
		$s = Site_Check::step( $r, $job, $budget );
		++$steps;
	} while ( 'done' !== $s['state'] );

	return array( 'steps' => $steps, 'note' => sprintf( '%d broken links, %d pages', $s['result']['broken_total'], $s['result']['checked'] ) );
} );

printf( "%-30s %9s %10s %6s  %s\n", 'stage', 'seconds', 'peak MB', 'steps', 'note' );
foreach ( $rows as $r ) {
	printf( "%-30s %9.2f %10.1f %6d  %s\n", $r[0], $r[1], $r[2], $r[3], $r[4] );
}
rrmdir( $root );
