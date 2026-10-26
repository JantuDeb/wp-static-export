<?php
namespace WP_Static_Export;

// Pro-only placeholder for UAM in the Free plugin.
// Mirrors the Environments pattern: visible in the UI, cannot run without Pro.

class SS_UAM_Integration extends Pro_Integration {

    /** @var string */
    protected $id = 'ss-uam';

    public function __construct() {
        $this->name        = __( 'User Access Management (Core)', 'wp-static-export' );
        $this->description = __( 'Control access to WP Static Export pages, menus, and features by assigning a minimum role.', 'wp-static-export' );
    }
}
