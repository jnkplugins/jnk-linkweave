<?php
namespace JnkLinkweave\Admin;

use JnkLinkweave\REST_API;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- reads the plugin's own links table; one grouped query per list screen.

/**
 * "Links" column on the Posts (and Pages, and other indexed types) list: how many
 * internal links point at each post and how many it sends out, read from the
 * link graph. One grouped query for the whole page of rows, not one per row.
 */
class Posts_Column {

    public const COLUMN = "jnk_linkweave_links";

    /** @var array<int, array{in:int,out:int}>|null */
    private static ?array $counts = null;

    public static function register(): void {
        add_action( "admin_init", [ __CLASS__, "hook_post_types" ] );
    }

    public static function hook_post_types(): void {
        if ( ! current_user_can( "edit_posts" ) ) {
            return;
        }
        foreach ( REST_API::report_post_types() as $type ) {
            add_filter( "manage_{$type}_posts_columns", [ __CLASS__, "add_column" ] );
            add_action( "manage_{$type}_posts_custom_column", [ __CLASS__, "render" ], 10, 2 );
        }
        add_action( "admin_head-edit.php", [ __CLASS__, "styles" ] );
    }

    public static function add_column( array $columns ): array {
        $label = '<span title="' . esc_attr__( "Internal links: how many of your posts link here, and how many links this post sends out", "jnk-linkweave" ) . '">'
            . esc_html__( "Links in / out", "jnk-linkweave" ) . "</span>";
        // Before the date, where it reads with the other per-post facts.
        $out = [];
        foreach ( $columns as $key => $name ) {
            if ( "date" === $key ) {
                $out[ self::COLUMN ] = $label;
            }
            $out[ $key ] = $name;
        }
        if ( ! isset( $out[ self::COLUMN ] ) ) {
            $out[ self::COLUMN ] = $label;
        }
        return $out;
    }

    public static function render( string $column, int $post_id ): void {
        if ( self::COLUMN !== $column ) {
            return;
        }
        if ( "publish" !== get_post_status( $post_id ) ) {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( "Counted once published", "jnk-linkweave" ) . "</span>";
            return;
        }
        if ( null === self::$counts ) {
            $ids = [];
            foreach ( (array) ( $GLOBALS["wp_query"]->posts ?? [] ) as $p ) {
                $ids[] = is_object( $p ) ? (int) $p->ID : (int) $p;
            }
            self::$counts = self::counts_for( $ids ?: [ $post_id ] );
        }
        $c = self::$counts[ $post_id ] ?? self::counts_for( [ $post_id ] )[ $post_id ];
        printf(
            '<span class="jnk-lw-col%1$s" title="%2$s">%3$s</span> <span class="jnk-lw-col-sep" aria-hidden="true">/</span> <span class="jnk-lw-col" title="%4$s">%5$s</span>',
            0 === $c["in"] ? " is-zero" : "",
            esc_attr( sprintf(
                /* translators: %d: number of internal links pointing at this post. */
                _n( "%d internal link points here", "%d internal links point here", $c["in"], "jnk-linkweave" ),
                $c["in"]
            ) ),
            esc_html( sprintf(
                /* translators: %d: number of links in. */
                __( "%d in", "jnk-linkweave" ),
                $c["in"]
            ) ),
            esc_attr( sprintf(
                /* translators: %d: number of internal links in this post. */
                _n( "This post links to %d of your posts", "This post links to %d of your posts", $c["out"], "jnk-linkweave" ),
                $c["out"]
            ) ),
            esc_html( sprintf(
                /* translators: %d: number of links out. */
                __( "%d out", "jnk-linkweave" ),
                $c["out"]
            ) )
        );
    }

    /**
     * In/out counts for a set of posts, from the link graph.
     *
     * @param int[] $ids
     * @return array<int, array{in:int,out:int}>
     */
    public static function counts_for( array $ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( "intval", $ids ) ) ) );
        $out = [];
        foreach ( $ids as $id ) {
            $out[ $id ] = [ "in" => 0, "out" => 0 ];
        }
        if ( ! $ids ) {
            return $out;
        }
        $table = $wpdb->prefix . "jnk_linkweave_links";
        $in    = implode( ",", array_fill( 0, count( $ids ), "%d" ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %d placeholders.
        $rows_in = $wpdb->get_results( $wpdb->prepare(
            "SELECT target_post_id AS id, COUNT(*) AS n FROM %i WHERE target_post_id IN ($in) GROUP BY target_post_id",
            array_merge( [ $table ], $ids )
        ) );
        $rows_out = $wpdb->get_results( $wpdb->prepare(
            "SELECT source_post_id AS id, COUNT(*) AS n FROM %i WHERE target_post_id > 0 AND source_post_id IN ($in) GROUP BY source_post_id",
            array_merge( [ $table ], $ids )
        ) );
        // phpcs:enable
        foreach ( (array) $rows_in as $r ) {
            $out[ (int) $r->id ]["in"] = (int) $r->n;
        }
        foreach ( (array) $rows_out as $r ) {
            $out[ (int) $r->id ]["out"] = (int) $r->n;
        }
        return $out;
    }

    /** Clear the per-request cache (tests, and list tables rendered twice). */
    public static function reset(): void {
        self::$counts = null;
    }

    public static function styles(): void {
        echo "<style>.column-" . esc_attr( self::COLUMN ) . "{width:9em}.jnk-lw-col.is-zero{color:#b32d2e;font-weight:600}.jnk-lw-col-sep{color:#8c8f94}</style>";
    }
}
