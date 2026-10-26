<?php

namespace WP_Static_Export;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The core plugin class
 */
class Plugin {
	/** Option used as an atomic gate while an export request is being prepared. */
	const EXPORT_START_LOCK_OPTION = 'wp-static-export-export-start-lock';

	/**
	 * The slug of the plugin; used in actions, filters, i18n, table names, etc.
	 *
	 * @var string
	 */
	const SLUG = 'wp-static-export';

	/**
	 * Singleton instance.
	 *
	 * @var WP_Static_Export
	 */
	protected static $instance = null;

	/**
	 * An instance of the options structure containing all options for this plugin
	 *
	 * @var WP_Static_Export\Options
	 */
	protected $options = null;

	/**
	 * View object.
	 *
	 * @var \WP_Static_Export\View
	 */
	protected $view = null;

	/**
	 * Archive creation process
	 *
	 * @var \WP_Static_Export\Archive_Creation_Job
	 */
	protected $archive_creation_job = null;

	/**
	 * Current page name.
	 *
	 * @var string
	 */
	protected $current_page = '';

	/**
	 * @var null|\WP_Static_Export\Page_Handlers
	 */
	protected $page_handlers = null;

	/**
	 * @var null|\WP_Static_Export\Integrations
	 */
	protected $integrations = null;

	/**
	 * Token owned by this request while it prepares and starts an export.
	 *
	 * @var string
	 */
	protected $export_start_lock_token = '';


	/**
	 * Return an instance of the WP Static Export plugin
	 * @return WP_Static_Export
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->includes();
			// Page handlers must register their init callback before WordPress
			// begins running the init hook.
			self::$instance->page_handlers = new Page_Handlers();


   add_action( 'activated_plugin', array( '\\WP_Static_Export\\Util', 'maybe_auto_include_activated_plugin' ), 10, 2 );
   add_action( 'deactivated_plugin', array( '\\WP_Static_Export\\Util', 'maybe_auto_remove_deactivated_plugin' ), 10, 2 );
   add_action( 'after_switch_theme', array( '\\WP_Static_Export\\Util', 'maybe_auto_include_active_theme' ) );

			// Apply hooks after init to avoid loading issues.
			add_action( 'init', function () {
				// Run export via WP-Cron.
				add_action( 'wpse_site_export_cron', array( self::$instance, 'run_static_export' ) );

				// Forms, comments, search, multilingual.
				Deploy_Runner::init();
				Single_Export::init();
				Html_Enhancer::init();
				Multilingual::init();
				add_action( 'wpse_before_finish_transferring_files_locally', array( '\\WP_Static_Export\\Process_Config', 'transfer_extra_files' ), 10, 2 );

				// Filters.
				add_filter( 'wpse.archive_creation_job.task_list', array(
					self::$instance,
					'filter_task_list'
				), 10, 2 );

				// Maybe clear local directory.
				add_action( 'wpse_after_setup_task', array( self::$instance, 'maybe_clear_directory' ) );

				// Add quick link to the plugin page.
				add_filter( 'plugin_action_links_simply-static/wp-static-export.php', array(
					self::$instance,
					'add_quick_links'
				) );

				// Handle Basic Auth.
				add_filter( 'http_request_args', array( self::$instance, 'add_http_filters' ), 10, 2 );

				// Set up integrations.
				self::$instance->integrations = new Integrations();
				self::$instance->integrations->load();

				// Set up defaults.
				self::$instance->options              = Options::instance();
				self::$instance->view                 = new View();
				$archive_job_class = apply_filters( 'wpse_archive_creation_job_class', '\\WP_Static_Export\\Archive_Creation_Job' );
				self::$instance->archive_creation_job = new $archive_job_class();

				// Set up pagination.
				$page                         = isset( $_GET['page'] ) ? $_GET['page'] : '';
				self::$instance->current_page = $page;

				// Maybe run upgrade.
				Upgrade_Handler::run();

				// Multisite.
				if ( is_multisite() ) {
					Multisite::get_instance();
				}

				// Plugin compatibility.
				Plugin_Compatibility::get_instance();

				// Boot up admin.
				Admin_Settings::get_instance();
				// Register admin REST routes in a dedicated controller.
				Admin_Rest::get_instance();
				Features_Admin::get_instance();
				Deploy_Manifest_Service::get_instance();
				Report_Admin::get_instance();
				Report_Service::maybe_update_schema();

				// Dashboard widget.
				Admin_Dashboard_Widget::get_instance();
			} );
		}

		return self::$instance;
	}

	public function get_integrations() {
		return $this->integrations->get_integrations();
	}

	public function get_integration( $integration ) {
		$integrations = $this->integrations->get_integrations();
		if ( empty( $integrations[ $integration ] ) ) {
			return null;
		}

		$class = $integrations[ $integration ];

		return new $class();
	}

	/**
	 * Include required files
	 *
	 * @return void
	 */
	private function includes() {
		$path = plugin_dir_path( dirname( __FILE__ ) );
		require_once $path . 'src/class-ss-phpuri.php';
		require_once $path . 'src/class-ss-options.php';
		require_once $path . 'src/class-ss-view.php';
		require_once $path . 'src/class-ss-html-encoding-helper.php';
		require_once $path . 'src/class-ss-url-extractor.php';
		require_once $path . 'src/class-ss-url-fetcher.php';
		require_once $path . 'src/background/class-ss-async-request.php';
		require_once $path . 'src/background/class-ss-background-process.php';
		require_once $path . 'src/tasks/exceptions/class-ss-pause-exception.php';
		require_once $path . 'src/class-ss-archive-creation-job.php';
		require_once $path . 'src/tasks/traits/class-ss-skip-further-processing-exception.php';
		require_once $path . 'src/tasks/traits/trait-ss-can-process-pages.php';
		require_once $path . 'src/tasks/traits/trait-ss-can-transfer.php';
		require_once $path . 'src/tasks/class-ss-task.php';
		require_once $path . 'src/tasks/class-ss-setup-task.php';
		require_once $path . 'src/tasks/class-ss-discover-urls-task.php';
		require_once $path . 'src/tasks/class-ss-fetch-urls-task.php';
		require_once $path . 'src/tasks/class-ss-transfer-files-locally-task.php';
		require_once $path . 'src/tasks/class-ss-create-zip-archive.php';
		require_once $path . 'src/tasks/class-ss-wrapup-task.php';
		require_once $path . 'src/tasks/class-ss-cancel-task.php';
		require_once $path . 'src/tasks/class-ss-generate-404-task.php';
		require_once $path . 'src/tasks/traits/trait-ss-runs-node-job.php';
		require_once $path . 'src/tasks/class-ss-verify-task.php';
		require_once $path . 'src/deploy/class-ss-deploy-config.php';
		require_once $path . 'src/deploy/class-ss-deploy-runner.php';
		require_once $path . 'src/features/class-ss-features-schema.php';
		require_once $path . 'src/features/class-ss-report-service.php';
		require_once $path . 'src/features/class-ss-verifier.php';
		require_once $path . 'src/features/class-ss-report-admin.php';
		require_once $path . 'src/features/class-ss-multilingual.php';
		require_once $path . 'src/features/class-ss-process-config.php';
		require_once $path . 'src/features/class-ss-html-enhancer.php';
		require_once $path . 'src/features/class-ss-forms-enhancer.php';
		require_once $path . 'src/features/class-ss-comments-enhancer.php';
		require_once $path . 'src/features/class-ss-search-enhancer.php';
		require_once $path . 'src/features/class-ss-single-export.php';
		require_once $path . 'src/features/class-ss-features-admin.php';
		require_once $path . 'src/tasks/class-ss-deploy-task.php';
		require_once $path . 'src/tasks/class-ss-process-task.php';
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $path . 'src/cli/class-ss-cli-command.php';
			\WP_CLI::add_command( 'static-export', '\\WP_Static_Export\\CLI_Command' );
		}
		require_once $path . 'src/handlers/class-ss-page-handler.php';
		require_once $path . 'src/class-ss-query.php';
		require_once $path . 'src/models/class-ss-model.php';
		require_once $path . 'src/models/class-ss-page.php';
		require_once $path . 'src/models/class-ss-deploy-manifest.php';
		require_once $path . 'src/models/class-ss-deploy-manifest-url.php';
		require_once $path . 'src/models/class-ss-report.php';
		require_once $path . 'src/models/class-ss-report-item.php';
		require_once $path . 'src/class-ss-deploy-manifest-service.php';
		require_once $path . 'src/class-ss-diagnostic.php';
		require_once $path . 'src/class-ss-sql-permissions.php';
		require_once $path . 'src/class-ss-upgrade-handler.php';
		require_once $path . 'src/class-ss-util.php';
		require_once $path . 'src/class-ss-page-handlers.php';
		require_once $path . 'src/class-ss-integrations.php';
		require_once $path . 'src/admin/inc/class-ss-admin-settings.php';
		require_once $path . 'src/admin/inc/class-ss-admin-rest.php';
		require_once $path . 'src/admin/inc/class-ss-admin-meta.php';
		require_once $path . 'src/admin/inc/class-ss-admin-dashboard-widget.php';
		require_once $path . 'src/admin/inc/class-ss-migrate-settings.php';
		require_once $path . 'src/class-ss-multisite.php';
		require_once $path . 'src/class-ss-plugin-compatibility.php';
	}

	/**
	 * Old method to include admin menu.
	 *
	 * @return void
	 * @deprecated
	 */
	public function add_plugin_admin_menu() {
		// Deprecated, only for upgrade support.
	}

	/**
	 * Get Options instance.
	 *
	 * @return Options|null
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Set Options instance.
	 *
	 * @param Options $options Options instance.
	 *
	 * @return void
	 */
	public function set_options( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Handle static export.
	 *
	 * @param int           $blog_id         Given blog ID.
	 * @param string        $type            Export type.
	 * @param callable|null $prepare_callback Optional scope preparation callback. It
	 *                                        runs only after the atomic start gate is
	 *                                        held and the archive job is confirmed idle.
	 * @param callable|null $failure_callback Optional rollback callback invoked under
	 *                                        the same gate when preparation started but
	 *                                        the export could not be started.
	 *
	 * @return bool Whether the export was started successfully
	 */
	public function run_static_export( $blog_id = 0, $type = 'export', $prepare_callback = null, $failure_callback = null ) {
		if ( ! $this->acquire_export_start_lock() ) {
			Util::debug_log( 'Export start blocked because another request is preparing an export.' );
			return false;
		}

		$preparation_started = false;
		try {
			// This check must happen while the start gate is held. Otherwise two PHP
			// requests can both observe an idle job before either one persists its scope.
			if ( $this->is_export_active() ) {
				Util::debug_log( "Export already running. Blocking new export request." );
				if ( method_exists( $this->archive_creation_job, 'get_current_task' ) ) {
					Util::debug_log( "Current task: " . $this->archive_creation_job->get_current_task() );
				}
				if ( method_exists( $this->archive_creation_job, 'is_job_done' ) ) {
					Util::debug_log( "Is job done: " . ($this->archive_creation_job->is_job_done() ? 'true' : 'false') );
				}

				return false;
			}

			// PHP_AUTH_USER and an explicit Basic Authorization scheme are reliable
			// Basic Auth signals. REMOTE_USER/AUTH_USER may instead come from SSO,
			// Kerberos, or a reverse proxy and must not block otherwise valid exports.
			$basic_auth_on = isset( $_SERVER['PHP_AUTH_USER'] )
				&& is_string( $_SERVER['PHP_AUTH_USER'] )
				&& '' !== $_SERVER['PHP_AUTH_USER'];
			foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $authorization_key ) {
				if (
					isset( $_SERVER[ $authorization_key ] )
					&& is_string( $_SERVER[ $authorization_key ] )
					&& preg_match( '/^\s*Basic\s+/i', $_SERVER[ $authorization_key ] )
				) {
					$basic_auth_on = true;
					break;
				}
			}

			// Exit if Basic Auth but no credentials were provided.
			if ( $basic_auth_on ) {
				$options         = get_option( 'wp-static-export' );
				$options         = is_array( $options ) ? $options : array();
				$basic_auth_user = isset( $options['http_basic_auth_username'] ) ? $options['http_basic_auth_username'] : '';
				$basic_auth_pass = isset( $options['http_basic_auth_password'] ) ? $options['http_basic_auth_password'] : '';

				$credentials_configured = is_string( $basic_auth_user )
					&& is_string( $basic_auth_pass )
					&& '' !== $basic_auth_user
					&& '' !== $basic_auth_pass;

				if ( ! $credentials_configured ) {
					// Reject the export before dispatching background work. Starting and
					// immediately cancelling left stale queue and archive state behind.
					$message = __( 'Missing Basic Auth credentials - you need to configure the Basic Auth credentials in WP Static Export -> Settings -> Misc -> Basic Auth to continue the export.', 'wp-static-export' );
					$this->archive_creation_job->save_status_message( $message, 'error' );
					return false;
				}
			}

			if ( ! $blog_id ) {
				$blog_id = get_current_blog_id();
			}

			if ( is_callable( $prepare_callback ) ) {
				$preparation_started = true;
				$prepared_type = call_user_func( $prepare_callback, $blog_id, $type, $this->archive_creation_job );
				if ( false === $prepared_type || is_wp_error( $prepared_type ) ) {
					$this->rollback_failed_export_start( $failure_callback );
					$preparation_started = false;
					return false;
				}
				if ( is_string( $prepared_type ) && '' !== $prepared_type ) {
					$type = $prepared_type;
				}
			}

			do_action( 'wpse_before_static_export', $blog_id, $type );

			// Clear transients.
			Util::clear_transients();

			// Start export only after all preflight validation and atomic scope
			// preparation succeeds.
			$started = (bool) $this->archive_creation_job->start( $blog_id, $type );
			if ( ! $started && $preparation_started ) {
				$this->rollback_failed_export_start( $failure_callback );
				$preparation_started = false;
			}

			return $started;
		} catch ( \Throwable $exception ) {
			if ( $preparation_started ) {
				$this->rollback_failed_export_start( $failure_callback );
			}

			throw $exception;
		} finally {
			$this->release_export_start_lock();
		}
	}

	/** Run a supplied scope rollback without masking the original start result. */
	protected function rollback_failed_export_start( $failure_callback ) {
		if ( ! is_callable( $failure_callback ) ) {
			return;
		}

		try {
			call_user_func( $failure_callback );
		} catch ( \Throwable $exception ) {
			Util::debug_log( 'Export start rollback failed: ' . $exception->getMessage() );
		}
	}

	/** Return whether any running, paused, or queued export owns the archive state. */
	public function is_export_active() {
		$job = $this->archive_creation_job;
		return ( method_exists( $job, 'is_running' ) && $job->is_running() )
			|| ( method_exists( $job, 'is_paused' ) && $job->is_paused() )
			|| ( method_exists( $job, 'is_queued' ) && $job->is_queued() );
	}

	/**
	 * Atomically reserve the short export preparation/start window.
	 *
	 * The database uniqueness constraint behind add_option() makes this safe
	 * across PHP workers. Locks older than five minutes are treated as abandoned.
	 *
	 * @return bool
	 */
	public function acquire_export_start_lock() {
		if ( '' !== $this->export_start_lock_token ) {
			// A nested start in the same PHP request is still a competing start and
			// must not prepare a second scope before the first one is persisted.
			return false;
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ss_export_', true );
		$lock  = array(
			'token'      => $token,
			'created_at' => time(),
		);

		if ( ! add_option( self::EXPORT_START_LOCK_OPTION, $lock, '', false ) ) {
			$existing = get_option( self::EXPORT_START_LOCK_OPTION );
			$created  = is_array( $existing ) && isset( $existing['created_at'] ) ? (int) $existing['created_at'] : 0;
			if ( $created > 0 && ( time() - $created ) <= 300 ) {
				return false;
			}

			// Recover from a PHP process that died while holding the short gate.
			delete_option( self::EXPORT_START_LOCK_OPTION );
			if ( ! add_option( self::EXPORT_START_LOCK_OPTION, $lock, '', false ) ) {
				return false;
			}
		}

		$this->export_start_lock_token = $token;
		return true;
	}

	/** Release the export start gate when it is still owned by this request. */
	public function release_export_start_lock() {
		if ( '' === $this->export_start_lock_token ) {
			return;
		}

		$existing = get_option( self::EXPORT_START_LOCK_OPTION );
		if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( $this->export_start_lock_token, (string) $existing['token'] ) ) {
			delete_option( self::EXPORT_START_LOCK_OPTION );
		}

		$this->export_start_lock_token = '';
	}

	/**
	 * Handle pause archive job.
	 *
	 * @return void
	 */
	public function pause_static_export() {
		// Clear WP object cache.
		wp_cache_flush();

		// Cancel export.
		$this->archive_creation_job->pause();

		$this->get_archive_creation_job()->save_status_message( "Export paused.", 'pause', true );
	}

	/**
	 * Handle resume archive job.
	 *
	 * @return void
	 */
	public function resume_static_export() {
		// Clear WP object cache.
		wp_cache_flush();

		$this->get_archive_creation_job()->save_status_message( "Export resumed.", 'resume', true );

		// Cancel export.
		$this->archive_creation_job->resume();
	}

	/**
	 * Handle cancel archive job.
	 *
	 * @return void
	 */
	public function cancel_static_export() {
		// Clear WP object cache.
		wp_cache_flush();

		$this->get_archive_creation_job()->save_status_message( "Export cancelled.", 'cancel', true );

		// Cancel export.
		$this->archive_creation_job->cancel();
	}

	/**
	 * Get activity log data.
	 *
	 * @param int $blog_id given blog id.
	 *
	 * @return mixed
	 */
	public function get_activity_log( $blog_id = 0 ) {
		$blog_id = $blog_id ?: get_current_blog_id();

		do_action( 'wpse_before_render_activity_log', $blog_id );

		$log = $this->options->get( 'archive_status_messages' );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		foreach ( $log as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				unset( $log[ $key ] );
				continue;
			}
			$log[ $key ]['message']  = wp_kses_post( isset( $entry['message'] ) ? $entry['message'] : '' );
			$log[ $key ]['datetime'] = sanitize_text_field( isset( $entry['datetime'] ) ? $entry['datetime'] : '' );

			if ( isset( $entry['link'] ) && is_array( $entry['link'] ) ) {
				$link_url   = esc_url_raw( isset( $entry['link']['url'] ) ? $entry['link']['url'] : '' );
				$link_label = sanitize_text_field( isset( $entry['link']['label'] ) ? $entry['link']['label'] : '' );

				if ( $link_url && preg_match( '#^(?:https?://|/(?!/))#i', $link_url ) ) {
					$log[ $key ]['link'] = array(
						'url'   => $link_url,
						'label' => $link_label ?: $link_url,
					);
				} else {
					unset( $log[ $key ]['link'] );
				}
			} elseif ( array_key_exists( 'link', $entry ) ) {
				unset( $log[ $key ]['link'] );
			}
		}

		do_action( 'wpse_after_render_activity_log', $blog_id, $this->get_archive_creation_job() );

		return $log;
	}

	/**
	 * Get export log data.
	 *
	 * @param int    $per_page given per page.
	 * @param int    $current_page given current page.
	 * @param int    $blog_id given blog id.
	 * @param string $search optional search term to filter by URL, status code, or notes.
	 *
	 * @return array
	 */
	public function get_export_log( $per_page, $current_page = 1, $blog_id = 0, $search = '' ) {
		global $wpdb;

		$blog_id = $blog_id ?: get_current_blog_id();

		do_action( 'wpse_before_render_export_log', $blog_id, $this->get_archive_creation_job() );

		$per_page    = min( 200, max( 1, absint( $per_page ?: 25 ) ) );
		$current_page = max( 1, absint( $current_page ) );
		$offset       = ( $current_page - 1 ) * $per_page;
		$scope    = $this->get_export_log_scope();

		$query = Page::query()
		    ->limit( $per_page )
		    ->offset( $offset )
		    ->order( 'http_status_code DESC' );

		$this->apply_export_log_scope( $query, $scope );

		if ( ! empty( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$query->where(
				$wpdb->prepare(
					'(url LIKE %s OR http_status_code LIKE %s OR status_message LIKE %s)',
					$like,
					$like,
					$like
				)
			);
		}

		$static_pages = apply_filters( 'wpse_total_pages_log', $query->find() );

		if ( ! empty( $search ) ) {
			$like               = '%' . $wpdb->esc_like( $search ) . '%';
			$count_query        = Page::query();

			$this->apply_export_log_scope( $count_query, $scope );

			$count_query->where(
				$wpdb->prepare(
					'(url LIKE %s OR http_status_code LIKE %s OR status_message LIKE %s)',
					$like,
					$like,
					$like
				)
			);
			$total_static_pages = apply_filters( 'wpse_total_pages', (int) $count_query->count() );
			$http_status_codes  = Page::get_http_status_codes_summary();
		} else {
			if ( ! empty( $scope ) ) {
				// Count only pages that belong to the current scoped export.
				$count_query = Page::query();
				$this->apply_export_log_scope( $count_query, $scope );
				$total_static_pages = apply_filters( 'wpse_total_pages', (int) $count_query->count() );
				$http_status_codes  = Page::get_http_status_codes_summary();
			} else {
				$http_status_codes  = Page::get_http_status_codes_summary();
				$total_static_pages = apply_filters( 'wpse_total_pages', array_sum( array_values( $http_status_codes ) ) );
			}
		}

		$total_pages = ceil( $total_static_pages / $per_page );

		do_action( 'wpse_after_render_export_log', $blog_id, $this->get_archive_creation_job() );

		$static_pages_formatted = [];

		foreach ( $static_pages as $static_page ) {
			$msg                = '';
			$parent_static_page = $static_page->parent_static_page();
			if ( $parent_static_page ) {
				$display_url = Util::get_path_from_local_url( $parent_static_page->url );
				$parent_url  = esc_url( $parent_static_page->url );
				$label       = sprintf(
					/* translators: %s: URL on which the exported resource was discovered. */
					__( 'Found on %s', 'wp-static-export' ),
					$display_url
				);
				$msg         .= $parent_url
					? '<a href="' . esc_attr( $parent_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a>'
					: esc_html( $label );
			}

			// Combine status messages.
			if ( $msg !== '' && $static_page->status_message ) {
				$msg .= ' ';
			}

			// Avoid duplicate status messages.
			if ( ! empty ( $static_page->status_message ) ) {
				if ( strpos( $static_page->status_message, ';' ) !== false ) {
					$cleaned = implode( '', array_unique( explode( '; ', $static_page->status_message ) ) );
						$msg     .= wp_kses_post( $cleaned );
					} else {
						$msg .= wp_kses_post( $static_page->status_message );
				}
			} else {
				$msg .= $static_page->status_message;
			}

			$information = [
				'id'          => $static_page->id,
					'url'         => esc_url_raw( $static_page->url ),
				'processable' => in_array( $static_page->http_status_code, Page::$processable_status_codes ),
				'code'        => $static_page->http_status_code,
					'notes'       => wp_kses_post( $msg ),
					'error'       => sanitize_text_field( $static_page->error_message ),
			];

			$static_pages_formatted[] = $information;
		}

		return [
			'static_pages'       => $static_pages_formatted,
			'total_static_pages' => $total_static_pages,
			'total_pages'        => $total_pages,
			'status_codes'       => $http_status_codes,
		];
	}

	/**
	 * Get the current export log scope.
	 *
	 * @return array
	 */
	private function get_export_log_scope() {
		$use_single         = get_option( 'wp-static-export-use-single' );
		$use_build          = get_option( 'wp-static-export-use-build' );
		$generate_type      = $this->options->get( 'generate_type' );
		$archive_start_time = $this->options->get( 'archive_start_time' );

		if ( ! empty( $use_single ) ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', $use_single ) ) ) );

			if ( ! empty( $ids ) ) {
				return [
					'type' => 'single',
					'ids'  => $ids,
				];
			}
		}

		if ( 'update' === $generate_type && empty( $use_build ) && ! empty( $archive_start_time ) ) {
			return [
				'type'               => 'update',
				'archive_start_time' => $archive_start_time,
			];
		}

		return [];
	}

	/**
	 * Apply current export scoping to an export log query.
	 *
	 * @param Query $query Export log query.
	 * @param array $scope Current export log scope.
	 *
	 * @return void
	 */
	private function apply_export_log_scope( $query, $scope ) {
		if ( empty( $scope ) || empty( $scope['type'] ) ) {
			return;
		}

		if ( 'single' === $scope['type'] && ! empty( $scope['ids'] ) ) {
			$ids = $scope['ids'];

			if ( count( $ids ) === 1 ) {
				$query->where( 'post_id = ?', $ids[0] );
			} else {
				$in_clause = implode( ',', $ids );
				$query->where( "post_id IN ({$in_clause})" );
			}
		}

		if ( 'update' === $scope['type'] && ! empty( $scope['archive_start_time'] ) ) {
			$query->where( 'last_checked_at >= ?', $scope['archive_start_time'] );
			$query->where( 'updated_at >= ?', $scope['archive_start_time'] );
		}
	}

	/**
	 * Starts the archive creation job.
	 *
	 * @return Archive_Creation_Job|null
	 */
	public function get_archive_creation_job() {
		return $this->archive_creation_job;
	}

	/**
	 * Return the task list for the Archive Creation Job to process
	 *
	 * @param array $task_list The list of tasks to process.
	 * @param string $delivery_method The method of delivering static files.
	 *
	 * @return array The list of tasks to process.
	 */
	public function filter_task_list( $task_list, $delivery_method ): array {
		// 404-only export short-circuit
		$only_404 = get_option( 'wp-static-export-404-only' );
		if ( ! empty( $only_404 ) ) {
			$task_list = [ 'setup' ];
			// Always include the 404 generator for this mode.
			$task_list[] = 'generate_404';
			if ( Process_Config::needed() ) {
				$task_list[] = 'process';
			}
			if ( 'zip' === $delivery_method ) {
				$task_list[] = 'create_zip_archive';
			} elseif ( 'local' === $delivery_method ) {
				$task_list[] = 'transfer_files_locally';
			} elseif ( Deploy_Config::is_deploy_method( $delivery_method ) ) {
				$task_list[] = 'deploy';
			}
			$task_list[] = 'wrapup';
			return $task_list;
		}
		$generate_404 = $this->options->get( 'generate_404' );

		$task_list[] = 'setup';

		if ( $this->options->get( 'smart_crawl' ) ) {
			// Only include discover_urls on full exports (exclude update, single, and build exports).
			$use_single  = get_option( 'wp-static-export-use-single' );
			$use_build   = get_option( 'wp-static-export-use-build' );
			$export_type = $this->options->get( 'generate_type' );
			if ( empty( $use_single ) && empty( $use_build ) && 'update' !== $export_type ) {
				$task_list[] = 'discover_urls';
			}
		}

		$task_list[] = 'fetch_urls';

		// Add 404 task
		if ( $generate_404 ) {
			$task_list[] = 'generate_404';
		}

		if ( Process_Config::needed() ) {
			$task_list[] = 'process';
		}

		$export_type = $this->options->get( 'generate_type' );
		if ( Report_Service::enabled() && empty( get_option( 'wp-static-export-use-build' ) ) && 'update' !== $export_type ) {
			$task_list[] = 'verify';
		}

		if ( 'zip' === $delivery_method ) {
			$task_list[] = 'create_zip_archive';
		} elseif ( 'local' === $delivery_method ) {
			$task_list[] = 'transfer_files_locally';
		} elseif ( Deploy_Config::is_deploy_method( $delivery_method ) ) {
			$task_list[] = 'deploy';
		}

		$task_list[] = 'wrapup';

		return $task_list;
	}


	/**
	 * Maybe clear local directory before export.
	 *
	 * @return void
	 */
	public function maybe_clear_directory() {
		// Check the export type.
		$use_single            = get_option( 'wp-static-export-use-single' );
		$use_build             = get_option( 'wp-static-export-use-build' );
		$only_404              = get_option( 'wp-static-export-404-only' );
		$export_type           = $this->options->get( 'generate_type' ) ?: 'export';
		$delivery_method       = $this->options->get( 'delivery_method' );
		$clear_before_export   = $this->options->get( 'clear_directory_before_export' );
		$is_full_local_export  = 'export' === $export_type
			&& empty( $use_build )
			&& empty( $use_single )
			&& empty( $only_404 )
			&& $clear_before_export
			&& 'local' === $delivery_method;

		$clear_local_directory = apply_filters(
			'wpse_clear_local_directory',
			$is_full_local_export,
			$export_type,
			$use_single,
			$use_build,
			$only_404,
			$this->options
		);

		// Clear out the local directory before copying files.
		if ( $clear_local_directory ) {
			$local_dir = apply_filters( 'wpse_local_dir', $this->options->get( 'local_dir' ) );

			// Make sure the directory exists and is not empty.
			if ( is_string( $local_dir ) && Util::is_path_allowed_by_open_basedir( $local_dir ) && is_dir( $local_dir ) ) {
				$iterator = new \FilesystemIterator( $local_dir, \FilesystemIterator::SKIP_DOTS );
				if ( $iterator->valid() ) {
					Transfer_Files_Locally_Task::delete_local_directory_static_files( $local_dir, $this->options );
				}
			}
		} else {
			// Provide a small hint in debug log when skipping clearing due to special export modes.
			if ( ! empty( $only_404 ) ) {
				Util::debug_log( 'Skipping clearing local directory: 404-only export.' );
			} elseif ( $clear_before_export && 'local' === $delivery_method && 'export' !== $export_type ) {
				Util::debug_log( sprintf( 'Skipping clearing local directory: %s export.', $export_type ) );
			} elseif ( $clear_before_export && 'local' === $delivery_method && ! empty( $use_single ) ) {
				Util::debug_log( 'Skipping clearing local directory: single export.' );
			} elseif ( $clear_before_export && 'local' === $delivery_method && ! empty( $use_build ) ) {
				Util::debug_log( 'Skipping clearing local directory: build export.' );
			}
		}
	}

	/**
	 * Register quick links in plugins settings page.
	 *
	 * @param array $links given list of links.
	 *
	 * @return array
	 */
	public function add_quick_links( $links ) {
		$settings_url = esc_url( get_admin_url() . 'admin.php?page=wp-static-export-settings' );
		$docs_url     = esc_url( 'https://github.com/JantuDeb/wp-static-export#readme' );

		$links[] = '<a href="' . $settings_url . '">' . esc_html__( 'Settings', 'wp-static-export' ) . '</a>';
		$links[] = '<a target="_blank" href="' . $docs_url . '">' . esc_html__( 'Docs', 'wp-static-export' ) . '</a>';

		return $links;
	}

	/**
	 * Set HTTP Basic Auth for wp-background-processing
	 *
	 * @param array $parsed_args given args.
	 * @param string $url given URL.
	 *
	 * @return array
	 */
	public function add_http_filters( $parsed_args, $url ) {

		if ( ! Util::is_local_origin_url( $url ) ) {
			return $parsed_args;
		}

		$authorization = Util::get_basic_auth_header_for_url( $url );
		if ( null !== $authorization ) {
			$parsed_args['headers']                  = isset( $parsed_args['headers'] ) && is_array( $parsed_args['headers'] ) ? $parsed_args['headers'] : array();
			$parsed_args['headers']['Authorization'] = $authorization;
		}

		return $parsed_args;
	}
}
