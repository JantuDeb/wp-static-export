<?php
/**
 * Runs the plugin's post-processing engine on an export folder, outside WordPress.
 *
 * Usage: php scripts/process.php <export-dir> '<ops json>' [full|partial]
 *
 * Used by the Lighthouse tool (deployer/tools/lighthouse) to measure what the automatic fixes and
 * optimizations do to a real export. Prints a JSON document: { message, created, warnings }.
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/src/engine/autoload.php';

use WP_Static_Export\Engine\Process_Run;

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php scripts/process.php <export-dir> '<ops json>' [full|partial]\n" );
	exit( 2 );
}

$job = array(
	'dir'   => rtrim( $argv[1], '/\\' ),
	'ops'   => json_decode( $argv[2], true ) ?: array(),
	'scope' => $argv[3] ?? 'full',
);
$warnings = array();
$run      = Process_Run::start( $job );
do {
	$step = Process_Run::step( $run, $job, 30 );
	foreach ( $step['logs'] as $log ) {
		if ( 'warn' === $log[0] ) {
			$warnings[] = $log[1];
		}
	}
} while ( 'done' !== $step['state'] );

echo json_encode( array( 'message' => $step['result']['message'], 'created' => $step['result']['created'], 'warnings' => $warnings ) ), "\n";
