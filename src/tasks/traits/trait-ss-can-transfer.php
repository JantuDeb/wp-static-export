<?php
namespace WP_Static_Export;

trait canTransfer {

	/**
	 * @param Page $static_page object.
	 *
	 * @return string
	 */
	protected function get_page_file_path( $static_page ) {
		$file_path = apply_filters( 'wpse_get_page_file_path_for_transfer', $static_page->file_path, $static_page );

		// Transfer paths are always archive-relative, regardless of the host OS or
		// the separator style returned by an integration/filter.
		return ltrim( Util::normalize_slashes( (string) $file_path ), '/' );
	}
}
