<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'esc_sql' ) ) {
		/**
		 * Minimal WordPress-compatible escaping stub for query unit tests.
		 *
		 * @param mixed $value
		 * @return mixed
		 */
		function esc_sql( $value ) {
			if ( is_array( $value ) ) {
				return array_map( 'esc_sql', $value );
			}

			return addslashes( (string) $value );
		}
	}
}

namespace WP_Static_Export\Tests\Unit {

	use WP_Static_Export\Query;
	use WP_Static_Export\Tests\Support\UnitTestCase;

	final class QueryUpdateWpdb {

		/** @var string[] */
		public $queries = array();

		public function query( string $query ): int {
			$this->queries[] = $query;
			return 2;
		}
	}

	final class QueryUpdateTestModel {
		public static function table_name(): string {
			return 'wp_wpse_test';
		}
	}

	final class QueryUpdateTest extends UnitTestCase {

		/** @var QueryUpdateWpdb */
		private $wpdb;

		protected function setUp(): void {
			parent::setUp();
			$this->requireSource( 'src/class-ss-query.php' );

			$this->wpdb       = new QueryUpdateWpdb();
			$GLOBALS['wpdb'] = $this->wpdb;
		}

		public function test_multi_column_update_assignments_are_comma_separated(): void {
			$query = new Query( QueryUpdateTestModel::class );

			self::assertSame(
				2,
				$query->update_all(
					array(
						'widget_id' => 2,
						'type'      => 'sprocket',
					)
				)
			);
			self::assertSame(
				"UPDATE  wp_wpse_test SET widget_id = '2', type = 'sprocket' ",
				$this->wpdb->queries[0]
			);
		}
	}
}
