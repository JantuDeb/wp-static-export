<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use WP_Static_Export\Tests\Support\UnitTestCase;
use WP_Static_Export\Tests\Support\WpTestEnvironment as WpEnv;
use WP_Static_Export\Util;

final class PublicPostStatusTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->requireSource( 'src/class-ss-util.php' );
	}

	public function test_registered_public_statuses_are_exportable(): void {
		WpEnv::$registered_post_statuses = array(
			'publish'    => (object) array( 'name' => 'publish', 'public' => true ),
			'nepromovat' => (object) array( 'name' => 'nepromovat', 'public' => true ),
			'draft'      => (object) array( 'name' => 'draft', 'public' => false ),
		);

		self::assertSame( array( 'publish', 'nepromovat' ), Util::get_public_post_statuses() );
		self::assertTrue( Util::is_public_post_status( 'nepromovat' ) );
		self::assertFalse( Util::is_public_post_status( 'draft' ) );
	}

	public function test_filter_can_add_an_explicit_exportable_status(): void {
		add_filter(
			'wpse_public_post_statuses',
			static function ( array $statuses ): array {
				$statuses[] = 'externally-visible';

				return $statuses;
			}
		);

		self::assertTrue( Util::is_public_post_status( 'externally-visible' ) );
	}
}
