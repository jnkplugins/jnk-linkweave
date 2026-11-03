<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * WP-CLI: wp linkweave ...
 *
 *   wp linkweave index rebuild   read every post again (words and links)
 *   wp linkweave index status    what the index holds and the last full read
 *   wp linkweave scan            check every internal link now
 *   wp linkweave rules list      the auto-link rules
 *
 * Pro adds report commands of its own.
 */
class CLI {

    /** @param bool|null $cli null = only when running under WP-CLI (tests pass true). */
    public static function register( ?bool $cli = null ): void {
        $cli = $cli ?? ( defined( "WP_CLI" ) && WP_CLI );
        if ( ! $cli || ! class_exists( "\\WP_CLI" ) ) {
            return;
        }
        \WP_CLI::add_command( "linkweave index", CLI_Index::class );
        \WP_CLI::add_command( "linkweave scan", [ __CLASS__, "scan" ] );
        \WP_CLI::add_command( "linkweave rules list", [ __CLASS__, "rules_list" ] );
    }

    /** Print rows in the format asked for (table, csv, json, yaml, count, ids). */
    public static function out( array $rows, array $fields, array $assoc ): void {
        \WP_CLI\Utils\format_items( (string) ( $assoc["format"] ?? "table" ), $rows, $fields );
    }

    /**
     * Check every internal link now, in this process. Links to other sites are
     * checked afterwards by Pro's polite outside check, not here.
     *
     * ## EXAMPLES
     *
     *     wp linkweave scan
     *
     * @param array $args
     * @param array $assoc
     */
    public static function scan( $args, $assoc ): void {
        if ( 0 === (int) $GLOBALS["wpdb"]->get_var( $GLOBALS["wpdb"]->prepare( "SELECT COUNT(*) FROM %i", $GLOBALS["wpdb"]->prefix . "jnk_linkweave_links" ) ) ) {
            Cron::rebuild_links_graph();
        }
        $t      = microtime( true );
        $broken = Cron::scan_broken_links();
        $last   = (array) get_option( "jnk_linkweave_last_broken_scan", [] );
        \WP_CLI::success( sprintf(
            "Checked %d links in %.1f s: %d missing, %d did not answer.",
            (int) ( $last["checked"] ?? 0 ),
            microtime( true ) - $t,
            $broken,
            (int) ( $last["unreachable"] ?? 0 )
        ) );
    }

    /**
     * List the auto-link rules.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv, json, yaml, count or ids.
     * ---
     * default: table
     * ---
     *
     * @param array $args
     * @param array $assoc
     */
    public static function rules_list( $args, $assoc ): void {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, keyword, target_url, is_active, max_per_post, applied_count FROM %i ORDER BY id ASC", Rules_Engine::table() ), ARRAY_A );
        self::out( $rows, [ "id", "keyword", "target_url", "is_active", "max_per_post", "applied_count" ], $assoc );
    }
}
