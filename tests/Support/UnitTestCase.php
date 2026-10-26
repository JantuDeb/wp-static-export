<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class UnitTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WpTestEnvironment::reset();

		if ( class_exists( '\\WP_Static_Export\\Options', false ) ) {
			\WP_Static_Export\Options::reinstance();
		}
	}

	protected function tearDown(): void {
		if ( class_exists( '\\WP_Static_Export\\Options', false ) ) {
			\WP_Static_Export\Options::reinstance();
		}

		parent::tearDown();
	}

	protected function requireSource( string $relative_path ): void {
		require_once dirname( __DIR__, 2 ) . '/' . ltrim( $relative_path, '/' );
	}
}
