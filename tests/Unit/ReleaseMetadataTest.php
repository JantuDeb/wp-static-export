<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use WP_Static_Export\Tests\Support\UnitTestCase;

final class ReleaseMetadataTest extends UnitTestCase {

	public function test_release_metadata_is_aligned_across_files(): void {
		$root      = dirname( __DIR__, 2 );
		$bootstrap = (string) file_get_contents( $root . '/wp-static-export.php' );
		$readme    = (string) file_get_contents( $root . '/readme.txt' );
		$package   = json_decode( (string) file_get_contents( $root . '/src/admin/package.json' ), true );
		$lock      = json_decode( (string) file_get_contents( $root . '/src/admin/package-lock.json' ), true );

		self::assertMatchesRegularExpression( '/^ \* Version:\s+3\.8\.18$/m', $bootstrap );
		self::assertMatchesRegularExpression( '/^ \* Requires at least:\s+6\.2$/m', $bootstrap );
		self::assertMatchesRegularExpression( '/^ \* Text Domain:\s+wp-static-export$/m', $bootstrap );
		self::assertStringContainsString( "define( 'WPSE_VERSION', '3.8.18' );", $bootstrap );
		self::assertStringContainsString( "version_compare( get_bloginfo( 'version' ), '6.2', '<' )", $bootstrap );
		self::assertStringContainsString( 'WP Static Export requires WordPress 6.2 or higher.', $bootstrap );
		self::assertStringContainsString( "add_action( 'init', 'wpse_load_textdomain', 0 );", $bootstrap );
		self::assertStringContainsString( "load_textdomain( 'wp-static-export', \$mofile );", $bootstrap );
		self::assertMatchesRegularExpression( '/^Stable tag:\s+3\.8\.18$/m', $readme );
		self::assertMatchesRegularExpression( '/^Tested up to:\s+7\.1$/m', $readme );
		self::assertMatchesRegularExpression( '/^Requires at least:\s+6\.2$/m', $readme );
		self::assertSame( '3.8.18', $package['version'] ?? null );
		self::assertSame( '3.8.18', $lock['version'] ?? null );
		self::assertSame( '3.8.18', $lock['packages']['']['version'] ?? null );
	}

	public function test_no_identifier_from_the_upstream_plugin_is_left_in_the_code(): void {
		$root    = dirname( __DIR__, 2 );
		$pattern = '/\bSimply_Static\b|\bSIMPLY_STATIC_|\bsimply_static_|\'simply-static\'|simplystatic\/v1/';
		$hits    = array();
		foreach ( array( 'src', 'tests', 'wp-static-export.php', 'uninstall.php' ) as $path ) {
			$files = is_dir( $root . '/' . $path )
				? new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $path, \FilesystemIterator::SKIP_DOTS ) )
				: array( new \SplFileInfo( $root . '/' . $path ) );
			foreach ( $files as $file ) {
				$name = $file->getPathname();
				if ( false !== strpos( $name, 'node_modules' ) || false !== strpos( $name, '/build/' ) || ! preg_match( '/\.(php|js|jsx)$/', $name ) || __FILE__ === $name ) {
					continue;
				}
				if ( preg_match( $pattern, (string) file_get_contents( $name ) ) ) {
					$hits[] = substr( $name, strlen( $root ) + 1 );
				}
			}
		}

		// If this fails, the plugin could clash with the original Simply Static plugin when both are active.
		self::assertSame( array(), $hits );
	}
}
