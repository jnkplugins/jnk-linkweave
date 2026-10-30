<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package JnkLinkweave
 */

defined( "WP_UNINSTALL_PLUGIN" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

global $wpdb;

$jnk_linkweave_settings = get_option( "jnk_linkweave_settings", [] );
if ( empty( $jnk_linkweave_settings["delete_data_on_uninstall"] ) ) {
    return;
}

// Drop custom tables.
$jnk_linkweave_tables = [ "jnk_linkweave_index", "jnk_linkweave_links", "jnk_linkweave_rules" ];
foreach ( $jnk_linkweave_tables as $jnk_linkweave_t ) {
    $jnk_linkweave_table = $wpdb->prefix . sanitize_key( $jnk_linkweave_t );
    $wpdb->query( $wpdb->prepare( "DROP TABLE IF EXISTS %i", $jnk_linkweave_table ) );
}

// Remove options.
delete_option( "jnk_linkweave_settings" );
delete_option( "jnk_linkweave_version" );
delete_option( "jnk_linkweave_ready_notice" );
delete_option( "jnk_linkweave_index_status" );
delete_option( "jnk_linkweave_idf" );
delete_option( "jnk_linkweave_rules_needing_pro" );
delete_post_meta_by_key( "_jnk_linkweave_no_autolink" );

// Clear scheduled events.
wp_clear_scheduled_hook( "jnk_linkweave_daily_scan" );

// Premium builds carry their own teardown (the URL-changer table, license/SDK
// state). Present only in the paid build; the free wordpress.org build ships no
// such file, so this is a no-op there.
if ( file_exists( __DIR__ . "/pro/uninstall.php" ) ) {
    include __DIR__ . "/pro/uninstall.php";
}
