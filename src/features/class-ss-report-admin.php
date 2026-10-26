<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export Report screen: menu entry, REST API for the dashboard and the CSV download.
 */
class Report_Admin {

	const PAGE_SLUG = 'wp-static-export-report';
	const REST_NS   = 'wp-static-export-features/v1';

	/**
	 * @var Report_Admin|null
	 */
	protected static $instance = null;

	/**
	 * @return Report_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 21 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_wpse_report_csv', array( $this, 'download_csv' ) );
	}

	protected function capability() {
		return apply_filters( 'wpse_user_capability', 'manage_options', 'settings' );
	}

	public function add_menu() {
		$suffix = add_submenu_page(
			'wp-static-export-generate',
			__( 'Export Report', 'wp-static-export' ),
			__( 'Export Report', 'wp-static-export' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render' ),
			7
		);
		add_action( "admin_print_scripts-{$suffix}", array( $this, 'enqueue' ) );
	}

	public function render() {
		echo '<div class="wrap"><h1 class="screen-reader-text">' . esc_html__( 'Export Report', 'wp-static-export' ) . '</h1><div id="wpse-report-root"></div></div>';
	}

	public function enqueue() {
		$rel  = 'deployer/dist/admin/report.js';
		$file = WPSE_PATH . $rel;
		if ( ! is_readable( $file ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'The report UI bundle is missing. Run "npm run build" in the deployer folder.', 'wp-static-export' ) . '</p></div>';
				}
			);

			return;
		}
		wp_enqueue_script( 'wpse-report', WPSE_URL . '/' . $rel, array(), (string) filemtime( $file ), true );
		wp_localize_script(
			'wpse-report',
			'wpseReport',
			array(
				'restUrl'  => esc_url_raw( rest_url( self::REST_NS ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'csvUrl'   => wp_nonce_url( admin_url( 'admin-post.php?action=wpse_report_csv' ), 'wpse_report_csv' ),
				'settings' => esc_url_raw( admin_url( 'admin.php?page=' . Features_Admin::PAGE_SLUG ) ),
				'exportUrl' => esc_url_raw( admin_url( 'admin.php?page=wp-static-export-generate' ) ),
			)
		);
	}

	public function register_routes() {
		$permission = function () {
			return current_user_can( $this->capability() );
		};

		register_rest_route( self::REST_NS, '/reports', array( 'methods' => 'GET', 'callback' => array( $this, 'list_reports' ), 'permission_callback' => $permission ) );
		register_rest_route( self::REST_NS, '/reports/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'get_report' ), 'permission_callback' => $permission ) );
		register_rest_route( self::REST_NS, '/reports/(?P<id>\d+)/items', array( 'methods' => 'GET', 'callback' => array( $this, 'get_items' ), 'permission_callback' => $permission ) );
		register_rest_route( self::REST_NS, '/report-verify', array( 'methods' => 'POST', 'callback' => array( $this, 'verify_step' ), 'permission_callback' => $permission ) );
	}

	/**
	 * Is an export running right now? Verifying against a half-written folder would report false problems.
	 *
	 * @return bool
	 */
	protected function export_running() {
		return ! Plugin::instance()->get_archive_creation_job()->is_job_done();
	}

	public function list_reports() {
		return rest_ensure_response(
			array(
				'reports'   => Report_Service::list_reports( 20 ),
				'post_types' => $this->post_type_labels(),
				'running'   => $this->export_running(),
			)
		);
	}

	/**
	 * @return array<string,string> slug => label
	 */
	protected function post_type_labels() {
		$labels = array();
		foreach ( Report_Service::post_types() as $type ) {
			$object          = get_post_type_object( $type );
			$labels[ $type ] = $object ? $object->labels->name : $type;
		}

		return $labels;
	}

	public function get_report( $request ) {
		$id     = (int) $request['id'];
		$report = Report_Service::get( $id );
		if ( ! $report ) {
			return new \WP_Error( 'wpse_report_not_found', __( 'Report not found.', 'wp-static-export' ), array( 'status' => 404 ) );
		}
		$report['post_types'] = $this->post_type_labels();

		return rest_ensure_response( $report );
	}

	public function get_items( $request ) {
		$report = Report_Service::get( (int) $request['id'] );
		if ( ! $report ) {
			return new \WP_Error( 'wpse_report_not_found', __( 'Report not found.', 'wp-static-export' ), array( 'status' => 404 ) );
		}
		$result = Report_Service::query_items(
			$report['id'],
			array(
				'state'     => (string) $request->get_param( 'state' ),
				'post_type' => (string) $request->get_param( 'post_type' ),
				'search'    => (string) $request->get_param( 'search' ),
				'orderby'   => (string) $request->get_param( 'orderby' ),
				'order'     => (string) $request->get_param( 'order' ),
				'page'      => (int) $request->get_param( 'page' ),
				'per_page'  => (int) $request->get_param( 'per_page' ),
			)
		);
		$response = rest_ensure_response( $result );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );

		return $response;
	}

	/**
	 * "Verify again": compare the posts with the files of the last export. The browser calls this until it says done.
	 *
	 * @param \WP_REST_Request $request Optional `restart` flag.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify_step( $request ) {
		if ( $this->export_running() ) {
			return new \WP_Error( 'wpse_report_busy', __( 'An export is running. Wait for it to finish.', 'wp-static-export' ), array( 'status' => 409 ) );
		}
		try {
			if ( $request->get_param( 'restart' ) || ! Verifier::state() ) {
				Verifier::begin();
			}

			return rest_ensure_response( Verifier::step( 8 ) );
		} catch ( \Throwable $e ) {
			Verifier::reset();

			return new \WP_Error( 'wpse_report_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Stream a report as CSV, a page at a time so huge sites don't exhaust memory.
	 */
	public function download_csv() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-static-export' ), 403 );
		}
		check_admin_referer( 'wpse_report_csv' );

		$report = Report_Service::get( isset( $_GET['report'] ) ? absint( $_GET['report'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		if ( ! $report ) {
			wp_die( esc_html__( 'Report not found.', 'wp-static-export' ), 404 );
		}
		$args = array(
			'state'     => isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'post_type' => isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'search'    => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'per_page'  => 200,
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="export-report-' . (int) $report['id'] . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel needs the BOM for non-ASCII titles.
		fputcsv( $out, array( 'post_id', 'type', 'title', 'url', 'state', 'http_status', 'static_path', 'file_size', 'note' ) );
		$page = 1;
		do {
			$args['page'] = $page;
			$result       = Report_Service::query_items( $report['id'], $args );
			foreach ( $result['items'] as $i ) {
				fputcsv( $out, array( $i['post_id'], $i['post_type'], self::csv_safe( $i['title'] ), $i['url'], $i['state'], $i['status_code'], $i['static_path'], $i['file_size'], $i['note'] ) );
			}
			++$page;
		} while ( $page <= $result['total_pages'] );
		fclose( $out );
		exit;
	}

	/**
	 * Stop spreadsheet programs treating a title as a formula.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;

		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}
}
