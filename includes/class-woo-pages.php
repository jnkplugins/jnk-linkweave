<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * WooCommerce's own pages.
 *
 * Cart, Checkout and My account are pages, so they were indexed, offered as
 * link targets and counted in the reports. Their content is a block or
 * shortcode that WooCommerce fills per visitor: the Cart page read as 4
 * product links in an admin request and 0 in a cron run, so "Internal links"
 * and the orphan count moved with no edit (QA 2-3 Nov 2026). They are left out
 * of the index, the link graph (as sources) and every report.
 *
 * The Shop page is an archive: url_to_postid() gives 0 for /shop/, so links to
 * it were stored as unresolved. resolve() maps that address to the Shop page.
 */
final class Woo_Pages {

    /** Option: the ids whose rows were last purged, so a changed page is purged again. */
    public const PURGED_OPTION = "jnk_linkweave_woo_pages_purged";

    /**
     * Cart, Checkout and My account page ids, found with wc_get_page_id() so any
     * slug or language works. Empty without WooCommerce.
     * Filter: jnk_linkweave_excluded_post_ids.
     *
     * @return int[]
     */
    public static function excluded_ids(): array {
        $ids = [];
        if ( Index_Builder::woocommerce_active() && function_exists( "wc_get_page_id" ) ) {
            foreach ( [ "cart", "checkout", "myaccount" ] as $page ) {
                $id = (int) wc_get_page_id( $page );
                if ( $id > 0 ) {
                    $ids[] = $id;
                }
            }
        }
        /** Filter: jnk_linkweave_excluded_post_ids. Posts left out of the index, graph and reports. */
        $ids = array_map( "intval", (array) apply_filters( "jnk_linkweave_excluded_post_ids", $ids ) );
        $ids = array_values( array_unique( array_filter( $ids, static fn( $id ) => $id > 0 ) ) );
        sort( $ids );
        return $ids;
    }

    public static function is_excluded( int $post_id ): bool {
        return $post_id > 0 && in_array( $post_id, self::excluded_ids(), true );
    }

    /**
     * " AND <column> NOT IN (...)" for a report query, or "" when nothing is
     * excluded. The list is integers only, so it is safe inside the SQL.
     */
    public static function sql_exclude( string $column ): string {
        $ids = self::excluded_ids();
        return $ids ? " AND {$column} NOT IN (" . implode( ",", $ids ) . ")" : "";
    }

    /** The Shop page id, or 0 (no WooCommerce, no Shop page set). */
    public static function shop_page_id(): int {
        if ( ! Index_Builder::woocommerce_active() || ! function_exists( "wc_get_page_id" ) ) {
            return 0;
        }
        $id = (int) wc_get_page_id( "shop" );
        return $id > 0 && "page" === get_post_type( $id ) ? $id : 0;
    }

    /**
     * url_to_postid(), plus the Shop page for its address (the /shop/ product
     * archive, which url_to_postid() does not resolve).
     */
    public static function resolve( string $url ): int {
        $id = (int) url_to_postid( $url );
        if ( $id > 0 || "" === $url ) {
            return $id;
        }
        $shop = self::shop_page_id();
        if ( 0 === $shop ) {
            return 0;
        }
        $want = self::path_of( (string) get_permalink( $shop ) );
        $have = self::path_of( str_starts_with( $url, "/" ) && ! str_starts_with( $url, "//" ) ? home_url( $url ) : $url );
        if ( null !== $have && ( $have === $want || "\0product-archive" === $have ) ) {
            return $shop;
        }
        $archive = function_exists( "get_post_type_archive_link" ) ? (string) get_post_type_archive_link( "product" ) : "";
        if ( "" !== $archive && null !== $have && $have === self::path_of( $archive ) ) {
            return $shop;
        }
        return 0;
    }

    /** Lower-cased, decoded path without trailing slash, or null for another site. */
    private static function path_of( string $url ): ?string {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return null;
        }
        $home = wp_parse_url( home_url( "/" ) );
        if ( ! empty( $parts["host"] ) && strtolower( (string) $parts["host"] ) !== strtolower( (string) ( $home["host"] ?? "" ) ) ) {
            return null;
        }
        // ?post_type=product is the archive on plain permalinks.
        if ( ! empty( $parts["query"] ) ) {
            parse_str( (string) $parts["query"], $q );
            if ( isset( $q["post_type"] ) && "product" === $q["post_type"] ) {
                return "\0product-archive";
            }
            if ( isset( $q["page_id"] ) ) {
                return null; // url_to_postid() reads those already
            }
        }
        return strtolower( untrailingslashit( rawurldecode( (string) ( $parts["path"] ?? "/" ) ) ) );
    }

    /**
     * admin_init: drop index rows and outgoing links of the excluded pages once
     * (again when the ids change, e.g. another Cart page is chosen). A full
     * rebuild drops them as well.
     */
    public static function maybe_purge(): void {
        $ids = self::excluded_ids();
        if ( get_option( self::PURGED_OPTION, null ) === $ids ) {
            return;
        }
        self::purge( $ids );
        update_option( self::PURGED_OPTION, $ids, false );
    }

    /** @param int[] $ids */
    public static function purge( array $ids ): int {
        global $wpdb;
        $n        = 0;
        $external = $ids && Cron::external_table() === $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( Cron::external_table() ) ) );
        foreach ( $ids as $id ) {
            $n += (int) $wpdb->delete( Index_Builder::table(), [ "post_id" => (int) $id ], [ "%d" ] );
            $n += (int) $wpdb->delete( $wpdb->prefix . "jnk_linkweave_links", [ "source_post_id" => (int) $id ], [ "%d" ] );
            if ( $external ) {
                $n += (int) $wpdb->delete( Cron::external_table(), [ "source_post_id" => (int) $id ], [ "%d" ] );
            }
        }
        if ( $n > 0 ) {
            Cron::bump_graph_version();
        }
        return $n;
    }
}
