<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page and REST API for the deploy/forms/search/optimize/comments/multilingual settings.
 */
class Features_Admin {

	const PAGE_SLUG  = 'wp-static-export-features';
	const REST_NS    = 'wp-static-export-features/v1';
	const CLEAR_MARK = "\0clear";

	/** @var Features_Admin|null */
	protected static $instance = null;

	/**
	 * @return Features_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return string
	 */
	protected function capability() {
		return apply_filters( 'wpse_user_capability', 'manage_options', 'settings' );
	}

	public function add_menu() {
		$suffix = add_submenu_page(
			'wp-static-export-generate',
			__( 'Deploy & Features', 'wp-static-export' ),
			__( 'Deploy & Features', 'wp-static-export' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render' ),
			6
		);
		add_action( "admin_print_scripts-{$suffix}", array( $this, 'enqueue' ) );
	}

	public function render() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Deploy & Features', 'wp-static-export' ) . '</h1><div id="ss-features-root"></div></div>';
	}

	public function enqueue() {
		$handle = 'ss-features';
		$rel    = 'deployer/dist/admin/settings.js';
		$file   = WPSE_PATH . $rel;

		if ( ! is_readable( $file ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'The settings UI bundle is missing. Run "npm run build" in the deployer folder.', 'wp-static-export' ) . '</p></div>';
				}
			);
			return;
		}

		wp_enqueue_script( $handle, WPSE_URL . '/' . $rel, array(), (string) filemtime( $file ), true );
		wp_localize_script(
			$handle,
			'ssFeatures',
			array(
				'restUrl' => esc_url_raw( rest_url( self::REST_NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function register_routes() {
		$permission = function () {
			return current_user_can( $this->capability() );
		};

		register_rest_route(
			self::REST_NS,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			self::REST_NS,
			'/export-single',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export_single' ),
				'permission_callback' => function ( $request ) {
					$ids = Single_Export::normalize_ids( $request->get_param( 'post_ids' ) );
					if ( ! $ids ) {
						return current_user_can( 'edit_posts' );
					}
					foreach ( $ids as $id ) {
						if ( ! current_user_can( 'edit_post', $id ) ) {
							return false;
						}
					}

					return true;
				},
			)
		);

		register_rest_route(
			self::REST_NS,
			'/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Settings for the UI. Secret values never leave the server: only whether they are set.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		$options = Options::instance();
		$values  = array();
		$secrets = array();
		$locked  = array();

		foreach ( Features_Schema::fields() as $key => $field ) {
			$value = $options->get( $key );
			if ( defined( 'WPSE_' . strtoupper( $key ) ) ) {
				$locked[ $key ] = true;
			}
			if ( ! empty( $field['secret'] ) ) {
				$secrets[ $key ] = ! empty( $value );
				continue;
			}
			$values[ $key ] = null === $value ? ( $field['default'] ?? ( 'checkbox' === $field['type'] ? false : ( 'repeater' === $field['type'] ? array() : '' ) ) ) : $value;
		}

		return rest_ensure_response(
			array(
				'schema'  => Features_Schema::sections(),
				'values'  => $values,
				'secrets' => $secrets,
				'locked'  => $locked,
				'env'     => $this->environment(),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_settings( $request ) {
		$posted = $request->get_json_params();
		$posted = is_array( $posted['values'] ?? null ) ? $posted['values'] : array();
		$fields = Features_Schema::fields();
		$o      = Options::instance();
		$saved  = array();

		foreach ( $posted as $key => $raw ) {
			if ( ! isset( $fields[ $key ] ) || defined( 'WPSE_' . strtoupper( $key ) ) ) {
				continue; // Unknown or locked by wp-config.php.
			}
			$field = $fields[ $key ];

			if ( ! empty( $field['secret'] ) ) {
				if ( self::CLEAR_MARK === $raw ) {
					$o->set( $key, '' );
					$saved[] = $key;
				} elseif ( is_string( $raw ) && '' !== $raw ) {
					$o->set( $key, 'textarea' === $field['type'] ? str_replace( "\r\n", "\n", $raw ) : trim( $raw ) );
					$saved[] = $key;
				}
				continue; // Empty = keep what is stored.
			}

			$o->set( $key, Features_Schema::sanitize( $field, $raw ) );
			$saved[] = $key;
		}

		$o->save();
		do_action( 'wpse_features_settings_saved', $saved );

		return $this->get_settings();
	}

	/**
	 * @param \WP_REST_Request $request Request with post_ids.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_single( $request ) {
		$result = Single_Export::start( $request->get_param( 'post_ids' ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 'ss_single_busy' === $result->get_error_code() ? 409 : 400 ) );
		}

		return new \WP_REST_Response( array( 'started' => true ), 202 );
	}

	/**
	 * Test the credentials of the selected delivery target without deploying anything.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify() {
		$method = Options::instance()->get( 'delivery_method' );
		if ( ! Deploy_Config::is_deploy_method( $method ) ) {
			return new \WP_Error( 'ss_verify_not_applicable', __( 'The selected delivery method needs no credentials.', 'wp-static-export' ), array( 'status' => 400 ) );
		}

		$result = Deploy_Runner::run_sync(
			array(
				'action'   => 'verify',
				'provider' => $method,
				'dir'      => '',
				'config'   => Deploy_Config::build( $method ),
			),
			30
		);
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 502 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Facts the UI shows next to the settings.
	 *
	 * @return array
	 */
	protected function environment() {
		$node = Deploy_Runner::find_node();

		return array(
			'node'         => is_wp_error( $node ) ? null : $node,
			'node_error'   => is_wp_error( $node ) ? $node->get_error_message() : null,
			'bundle'       => is_readable( Deploy_Runner::cli_path() ),
			'multilingual' => Multilingual::detect(),
		);
	}
}
