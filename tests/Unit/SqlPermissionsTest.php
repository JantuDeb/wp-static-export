<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use ReflectionProperty;
use WP_Static_Export\Sql_Permissions;
use WP_Static_Export\Tests\Support\UnitTestCase;

final class SqlPermissionsWpdb {

	/** @var string */
	public $dbname = 'wordpress';

	/** @var array<int,array{string}> */
	public $rows = array();

	/** @return array<int,array{string}> */
	public function get_results( string $query, $output = null ): array {
		return $this->rows;
	}
}

final class SqlPermissionsTest extends UnitTestCase {

	/** @var mixed */
	private $previous_wpdb;

	/** @var SqlPermissionsWpdb */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->requireSource( 'src/class-ss-sql-permissions.php' );

		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new SqlPermissionsWpdb();
		$GLOBALS['wpdb']     = $this->wpdb;
		$this->resetInstance();
	}

	protected function tearDown(): void {
		$this->resetInstance();
		$GLOBALS['wpdb'] = $this->previous_wpdb;

		parent::tearDown();
	}

	public function test_role_grants_are_skipped_while_direct_permissions_are_detected(): void {
		$this->wpdb->rows = array(
			array( 'GRANT `rds_superuser_role`@`%` TO `admin`@`%`' ),
			array( 'GRANT SELECT, INSERT ON `wordpress`.* TO `admin`@`%`' ),
		);

		$permissions = Sql_Permissions::instance();

		self::assertTrue( $permissions->can( 'select' ) );
		self::assertTrue( $permissions->can( 'insert' ) );
		self::assertFalse( $permissions->can( 'update' ) );
	}

	private function resetInstance(): void {
		$property = new ReflectionProperty( Sql_Permissions::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	}
}
