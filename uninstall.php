<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package JnkLinkweave
 */

defined( "WP_UNINSTALL_PLUGIN" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

global $wpdb;

// Deleting the plugin frees this site's licence seat on the licensing server,
// whether or not its data goes too. Only builds with the licence client ship it.
$uninstall_seat_slug = "jnk-linkweave";
if ( is_readable( __DIR__ . "/pro/licensorpro-client/uninstall-seat.php" ) ) {
    include __DIR__ . "/pro/licensorpro-client/uninstall-seat.php";
}

$jnk_linkweave_settings = get_option( "jnk_linkweave_settings", [] );
if ( empty( $jnk_linkweave_settings["delete_data_on_uninstall"] ) ) {
    return;
}

// Drop every Linkweave table, found by prefix: the Pro tables too, since a
// site that ran Pro and went back to the free build still has them, and the
// free build ships no pro/uninstall.php (QA 31 Oct 2026).
$jnk_linkweave_tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix . "jnk_linkweave_" ) . "%" ) );
foreach ( array_unique( array_merge( [ "index", "links", "rules" ], (array) $jnk_linkweave_tables ) ) as $jnk_linkweave_t ) {
    $jnk_linkweave_table = 0 === strpos( (string) $jnk_linkweave_t, $wpdb->prefix ) ? (string) $jnk_linkweave_t : $wpdb->prefix . "jnk_linkweave_" . $jnk_linkweave_t;
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $jnk_linkweave_table ) );
}

// Remove every option and transient Linkweave wrote, by prefix, and those
// named after its slug (the licensing client's key, licence and update
// state): a list by hand kept missing new ones (eleven options were left).
foreach ( [ "jnk_linkweave_%", "%_jnk-linkweave_%" ] as $jnk_linkweave_p ) {
    $jnk_linkweave_like = str_replace( "_", "\\_", $jnk_linkweave_p );
    foreach ( [ "", "_transient_", "_transient_timeout_", "_site_transient_", "_site_transient_timeout_" ] as $jnk_linkweave_kind ) {
        $jnk_linkweave_names = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM %i WHERE option_name LIKE %s",
            $wpdb->options,
            $wpdb->esc_like( $jnk_linkweave_kind ) . $jnk_linkweave_like
        ) );
        foreach ( (array) $jnk_linkweave_names as $jnk_linkweave_name ) {
            delete_option( (string) $jnk_linkweave_name );
        }
    }
}
delete_post_meta_by_key( "_jnk_linkweave_no_autolink" );
delete_post_meta_by_key( "_jnk_linkweave_related" );

// Clear scheduled events: every WP-Cron hook named after the plugin, and every
// Action Scheduler action in its group.
foreach ( (array) _get_cron_array() as $jnk_linkweave_events ) {
    foreach ( array_keys( (array) $jnk_linkweave_events ) as $jnk_linkweave_hook ) {
        if ( 0 === strpos( (string) $jnk_linkweave_hook, "jnk_linkweave_" ) || false !== strpos( (string) $jnk_linkweave_hook, "_jnk-linkweave_" ) ) {
            wp_unschedule_hook( (string) $jnk_linkweave_hook );
        }
    }
}
if ( function_exists( "as_unschedule_all_actions" ) ) {
    as_unschedule_all_actions( "", [], "jnk-linkweave" );
}

// Premium builds carry their own teardown (the URL-changer table, license/SDK
// state). Present only in the paid build; the free wordpress.org build ships no
// such file, so this is a no-op there.
if ( file_exists( __DIR__ . "/pro/uninstall.php" ) ) {
    include __DIR__ . "/pro/uninstall.php";
}
