<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * wp linkweave index rebuild|status
 */
class CLI_Index {

    /**
     * Read every post again: the word index, then the link graph.
     *
     * ## EXAMPLES
     *
     *     wp linkweave index rebuild
     *
     * @param array $args
     * @param array $assoc
     */
    public function rebuild( $args, $assoc ): void {
        $t     = microtime( true );
        $posts = Index_Builder::rebuild_now();
        $links = Cron::rebuild_links_graph();
        $st    = Index_Builder::status();
        if ( "error" === $st["state"] ) {
            \WP_CLI::error( (string) $st["error"] );
        }
        \WP_CLI::success( sprintf( "Indexed %d posts and %d internal links in %.1f s.", $posts, $links, microtime( true ) - $t ) );
    }

    /**
     * What the index holds, and the last full read.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, csv, json or yaml.
     * ---
     * default: table
     * ---
     *
     * @param array $args
     * @param array $assoc
     */
    public function status( $args, $assoc ): void {
        global $wpdb;
        $st   = Index_Builder::status();
        $last = (array) get_option( "jnk_linkweave_last_broken_scan", [] );
        $rows = [
            [ "key" => "state", "value" => (string) $st["state"] ],
            [ "key" => "indexed_posts", "value" => (string) $st["indexed"] ],
            [ "key" => "posts_to_index", "value" => (string) count( Index_Builder::corpus_ids() ) ],
            [ "key" => "internal_links", "value" => (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $wpdb->prefix . "jnk_linkweave_links" ) ) ],
            [ "key" => "links_to_other_sites", "value" => (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", Cron::external_table() ) ) ],
            [ "key" => "last_full_read_utc", "value" => (string) ( $st["finished_at"] ?? "" ) ],
            [ "key" => "last_link_check_utc", "value" => (string) ( $last["finished_at"] ?? "" ) ],
            [ "key" => "error", "value" => (string) $st["error"] ],
        ];
        CLI::out( $rows, [ "key", "value" ], $assoc );
    }
}
