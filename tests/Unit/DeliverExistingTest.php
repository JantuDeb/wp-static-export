<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use WP_Static_Export\Deliver_Existing;
use WP_Static_Export\Tests\Support\UnitTestCase;
use WP_Static_Export\Tests\Support\WpTestEnvironment as WpEnv;

final class DeliverExistingTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		foreach ( array(
			'src/class-ss-plugin.php',
			'src/class-ss-util.php',
			'src/class-ss-options.php',
			'src/deploy/class-ss-deploy-config.php',
			'src/features/class-ss-deliver-existing.php',
		) as $file ) {
			$this->requireSource( $file );
		}
	}

	public function test_only_a_fresh_flag_counts(): void {
		unset( WpEnv::$options[ Deliver_Existing::FLAG ] );
		$this->assertFalse( Deliver_Existing::active() );

		WpEnv::$options[ Deliver_Existing::FLAG ] = time();
		$this->assertTrue( Deliver_Existing::active() );

		// A start that never finished must not turn a later export into a delivery.
		WpEnv::$options[ Deliver_Existing::FLAG ] = time() - Deliver_Existing::FLAG_TTL - 5;
		$this->assertFalse( Deliver_Existing::active() );
	}

	public function test_delivery_tasks_skip_crawling_and_processing(): void {
		$this->assertSame( array( 'create_zip_archive', 'wrapup' ), Deliver_Existing::task_list( 'zip' ) );
		$this->assertSame( array( 'transfer_files_locally', 'wrapup' ), Deliver_Existing::task_list( 'local' ) );
		$this->assertSame( array( 'deploy', 'wrapup' ), Deliver_Existing::task_list( 'cloudflare-workers' ) );
		$this->assertSame( array( 'wrapup' ), Deliver_Existing::task_list( 'unknown' ) );
	}
}
