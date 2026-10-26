<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use WP_Static_Export\Query;
use WP_Static_Export\Tests\Support\UnitTestCase;

final class QuerySecurityTestModel {
	public static function table_name(): string {
		return 'wp_wpse_test';
	}
}

final class QuerySecurityTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->requireSource( 'src/class-ss-query.php' );
	}

	/**
	 * @dataProvider unsafePaginationProvider
	 * @param mixed $limit
	 * @param mixed $offset
	 */
	public function test_limit_and_offset_are_cast_before_sql_composition( $limit, $offset, string $expected_suffix ): void {
		$query = ( new Query( QuerySecurityTestModel::class ) )->limit( $limit )->offset( $offset );

		self::assertSame(
			'SELECT * FROM  wp_wpse_test' . $expected_suffix,
			$query->get_raw_sql( '*' )
		);
	}

	/** @return array<string,array{mixed,mixed,string}> */
	public function unsafePaginationProvider(): array {
		return array(
			'injection strings' => array( '1; DROP TABLE users', '0 UNION SELECT 1', ' LIMIT 1' ),
			'negative values'   => array( -20, -100, ' LIMIT 1' ),
			'numeric strings'   => array( '25', '50', ' LIMIT 25 OFFSET 50' ),
		);
	}
}
