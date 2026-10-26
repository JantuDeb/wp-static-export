<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use WP_Static_Export\Tests\Support\UnitTestCase;
use WP_Static_Export\Report_Admin;
use WP_Static_Export\Report_Service;

final class ReportServiceTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		foreach ( array(
			'src/class-ss-plugin.php',
			'src/class-ss-util.php',
			'src/class-ss-options.php',
			'src/models/class-ss-model.php',
			'src/models/class-ss-report.php',
			'src/models/class-ss-report-item.php',
			'src/features/class-ss-report-service.php',
			'src/features/class-ss-report-admin.php',
		) as $file ) {
			$this->requireSource( $file );
		}
	}

	public function test_missing_when_no_page_row(): void {
		$this->assertSame( Report_Service::MISSING, Report_Service::classify( null, false, null )[0] );
	}

	public function test_excluded_when_no_page_row_and_excluded(): void {
		$this->assertSame( Report_Service::EXCLUDED, Report_Service::classify( null, true, null )[0] );
	}

	public function test_ok_needs_a_non_empty_file(): void {
		$page = array( 'http_status_code' => '200', 'file_path' => 'a/index.html', 'status_message' => '' );
		$this->assertSame( Report_Service::OK, Report_Service::classify( $page, false, 120 )[0] );
		$this->assertSame( Report_Service::EMPTY_, Report_Service::classify( $page, false, 0 )[0] );
		$this->assertSame( Report_Service::ERROR, Report_Service::classify( $page, false, null )[0] );
	}

	public function test_200_without_file_path_is_an_error(): void {
		$this->assertSame( Report_Service::ERROR, Report_Service::classify( array( 'http_status_code' => 200, 'file_path' => '' ), false, null )[0] );
	}

	public function test_redirects_and_http_errors(): void {
		$this->assertSame( Report_Service::REDIRECT, Report_Service::classify( array( 'http_status_code' => 301 ), false, null )[0] );
		list( $state, $note, $code ) = Report_Service::classify( array( 'http_status_code' => 500, 'file_path' => '' ), false, null );
		$this->assertSame( Report_Service::ERROR, $state );
		$this->assertSame( 500, $code );
		$this->assertStringContainsString( '500', $note );
	}

	public function test_page_never_fetched_is_an_error(): void {
		$this->assertSame( Report_Service::ERROR, Report_Service::classify( array( 'http_status_code' => null ), false, null )[0] );
	}

	public function test_csv_cells_cannot_become_formulas(): void {
		$this->assertSame( "'=HYPERLINK(1)", Report_Admin::csv_safe( '=HYPERLINK(1)' ) );
		$this->assertSame( "'@x", Report_Admin::csv_safe( '@x' ) );
		$this->assertSame( 'Hello', Report_Admin::csv_safe( 'Hello' ) );
		$this->assertSame( '', Report_Admin::csv_safe( '' ) );
	}
}
