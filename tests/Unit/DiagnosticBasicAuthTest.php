<?php

declare(strict_types=1);

namespace WP_Static_Export\Tests\Unit;

use ReflectionClass;
use WP_Static_Export\Diagnostic;
use WP_Static_Export\Options;
use WP_Static_Export\Tests\Support\UnitTestCase;
use WP_Static_Export\Tests\Support\WpTestEnvironment as WpEnv;

final class DiagnosticBasicAuthTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->requireSource( 'src/class-ss-plugin.php' );
		$this->requireSource( 'src/class-ss-options.php' );
		$this->requireSource( 'src/class-ss-util.php' );
		$this->requireSource( 'src/class-ss-diagnostic.php' );
	}

	public function test_missing_server_software_is_handled_as_no_basic_auth(): void {
		$result = $this->diagnostic()->check_basic_auth_status();

		self::assertTrue( $result['test'] );
		self::assertSame( 'Basic Auth is not enabled.', $result['description'] );
	}

	public function test_detected_basic_auth_requires_both_credentials(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'apache/2.4';
		$_SERVER['PHP_AUTH_USER']    = '0';
		WpEnv::$options['wp-static-export'] = array(
			'http_basic_auth_username' => 'crawler',
			'http_basic_auth_password' => '',
		);

		$result = $this->diagnostic()->check_basic_auth_status();

		self::assertFalse( $result['test'] );
		self::assertStringContainsString( 'no username or password is set', $result['error'] );
	}

	public function test_detected_basic_auth_accepts_complete_credentials(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'NGINX';
		$_SERVER['REMOTE_USER']     = 'crawler';
		WpEnv::$options['wp-static-export'] = array(
			'http_basic_auth_username' => '0',
			'http_basic_auth_password' => '0',
		);

		$result = $this->diagnostic()->check_basic_auth_status();

		self::assertTrue( $result['test'] );
		self::assertStringContainsString( 'username and password are set', $result['description'] );
	}

	private function diagnostic(): Diagnostic {
		$reflection = new ReflectionClass( Diagnostic::class );
		$diagnostic = $reflection->newInstanceWithoutConstructor();
		$options    = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue( $diagnostic, Options::reinstance() );

		return $diagnostic;
	}
}
