<?php

namespace WP_Static_Export;

/**
 * Checks that every published post and page made it into the export, before anything is delivered.
 * A problem in the checker itself never fails the export.
 */
class Verify_Task extends Task {

	use runsNodeJob;

	const PHASE_OPTION = 'wpse_verify_phase';

	/**
	 * Task name.
	 *
	 * @var string
	 */
	protected static $task_name = 'verify';

	/**
	 * @return bool|\WP_Error True when done, false to be called again.
	 */
	public function perform() {
		$phase = get_option( self::PHASE_OPTION );

		if ( ! is_array( $phase ) ) {
			$result = $this->check_coverage();
			if ( ! is_array( $result ) ) {
				return $result;
			}
			$phase = $result;
		}

		if ( ! empty( $phase['deep'] ) ) {
			$deep = $this->check_site( (int) $phase['report_id'] );
			if ( false === $deep ) {
				return false;
			}
		}
		delete_option( self::PHASE_OPTION );

		$missing = (int) $phase['missing'];
		$this->save_status_message(
			0 === $missing
				? __( 'Verified: every published item is in the export.', 'wp-static-export' )
				/* translators: %d: number of items */
				: sprintf( __( 'Verification found %d items that are missing or broken. See Export Report.', 'wp-static-export' ), $missing )
		);

		if ( $missing > 0 && $this->options->get( 'report_block_delivery' ) ) {
			return new \WP_Error( 'ss_verify_blocked', __( 'Delivery stopped: some published items are missing from the export. See Export Report.', 'wp-static-export' ) );
		}

		return true;
	}

	/**
	 * Compare posts with the exported files.
	 *
	 * @return array|bool Phase data when finished, false to be called again, true to skip the rest.
	 */
	protected function check_coverage() {
		try {
			$ids = Single_Export::normalize_ids( get_option( Single_Export::FLAG ) );
			if ( ! Verifier::state() ) {
				Verifier::begin( $ids );
			}
			$step = Verifier::step( 8 );
		} catch ( \Throwable $e ) {
			Util::debug_log( 'Verification failed: ' . $e->getMessage() );
			Verifier::reset();
			$this->save_status_message( __( 'Could not verify the export (continuing).', 'wp-static-export' ) );

			return true;
		}

		if ( 'running' === $step['status'] ) {
			$this->save_status_message(
				sprintf(
					/* translators: 1: checked, 2: total */
					__( 'Checked %1$d of %2$d published items', 'wp-static-export' ),
					$step['done'],
					$step['total']
				)
			);

			return false;
		}

		$report = Report_Service::get( $step['report_id'] );
		$states = $report['totals']['states'] ?? array();
		$phase  = array(
			'report_id' => (int) $step['report_id'],
			'missing'   => (int) ( $states[ Report_Service::MISSING ] ?? 0 ) + (int) ( $states[ Report_Service::ERROR ] ?? 0 ) + (int) ( $states[ Report_Service::EMPTY_ ] ?? 0 ),
			// A single-page export leaves most of the site untouched, so the site-wide scan would only repeat the last full one.
			'deep'      => Report_Service::deep_enabled() && ! Verifier::was_partial( $report ),
		);
		update_option( self::PHASE_OPTION, $phase, false );

		return $phase;
	}

	/**
	 * Scan the exported files for broken links, error pages and similar with the bundled Node tool.
	 * Never fails the export: without Node the report simply has no site checks.
	 *
	 * @param int $report_id Report to attach the result to.
	 * @return array|bool|null False while running, otherwise done (result or null).
	 */
	protected function check_site( $report_id ) {
		$step = $this->advance_node_job(
			'verify_run',
			function () {
				$hosts = array_filter( array( wp_parse_url( home_url(), PHP_URL_HOST ), (string) $this->options->get( 'destination_host' ) ) );

				return array(
					'action' => 'verify_site',
					'dir'    => untrailingslashit( $this->options->get_archive_dir() ),
					'hosts'  => array_values( $hosts ),
				);
			},
			function ( $event ) {
				static $last = 0;
				if ( microtime( true ) - $last < 1 ) {
					return;
				}
				$last = microtime( true );
				$this->save_status_message( sprintf( /* translators: 1: done, 2: total */ __( 'Scanned %1$d of %2$d pages for broken links', 'wp-static-export' ), (int) $event['done'], (int) $event['total'] ) );
			}
		);

		if ( is_wp_error( $step ) ) {
			Util::debug_log( 'Site check skipped: ' . $step->get_error_message() );
			$this->options->set( 'verify_run', null )->save();

			return null;
		}
		if ( 'running' === $step['state'] ) {
			return false;
		}

		unset( $step['result']['type'] );
		global $wpdb;
		$wpdb->update( Report::table_name(), array( 'deep' => wp_json_encode( $step['result'] ) ), array( 'id' => $report_id ) );

		return $step['result'];
	}
}
