<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own link table.

/**
 * The link map on the Inbound links screen (free).
 *
 * Every published post of the indexed types, grouped by topic, with its inbound
 * count; how many links run from each topic to each other topic; and the links
 * themselves (one per linking post pair) for the graph view. Read from the links
 * table the index fills, so it costs no pass over post content.
 */
class Link_Map {

    /** Most posts on the map. */
    public const MAX_POSTS = 3000;

    /** Most links in the graph. Past this the strongest-linked pairs are kept. */
    public const MAX_EDGES = 30000;

    /**
     * @return array{edit:string,view:string,types:array<int,array{0:string,1:string}>,rows:array<int,array{name:string,posts:array}>,topics:string[],matrix:array,edges:int[],total:int}
     *   rows[].posts[] = [ id, links in, title, type index ]; edges = flat [ source id, target id, ... ].
     */
    public static function data(): array {
        global $wpdb;
        $links_t = $wpdb->prefix . "jnk_linkweave_links";
        $types   = REST_API::report_post_types();
        $in      = implode( ",", array_fill( 0, count( $types ), "%s" ) );
        $posts   = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders.
            "SELECT p.ID AS id, p.post_title AS t, p.post_type AS pt, COUNT(l.id) AS n FROM {$wpdb->posts} p
             LEFT JOIN %i l ON l.target_post_id = p.ID
             WHERE p.post_status = 'publish' AND p.post_type IN ($in)" . Woo_Pages::sql_exclude( "p.ID" ) . "
             GROUP BY p.ID, p.post_title, p.post_type ORDER BY n DESC, p.ID DESC LIMIT %d",
            array_merge( [ $links_t ], $types, [ self::MAX_POSTS ] )
        ) );

        // Topic = the post's main category: its most-used category other than the
        // default one. Other post types (pages, products) are grouped under their
        // type name.
        $topic_of = [];
        $type_idx = [];
        $type_list = [];
        if ( $posts ) {
            $ids  = implode( ",", array_map( static fn( $r ) => (int) $r->id, $posts ) );
            $cats = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list built above.
                "SELECT tr.object_id AS id, t.name, tt.term_id, tt.count FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
                 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                 WHERE tr.object_id IN ($ids) AND tt.term_id <> " . (int) get_option( "default_category" )
            );
            $best = [];
            foreach ( $cats as $c ) {
                $cur = $best[ (int) $c->id ] ?? null;
                if ( ! $cur || (int) $c->count > $cur[1] || ( (int) $c->count === $cur[1] && (int) $c->term_id < $cur[2] ) ) {
                    $best[ (int) $c->id ] = [ html_entity_decode( (string) $c->name ), (int) $c->count, (int) $c->term_id ];
                }
            }
            foreach ( $posts as $r ) {
                $pt = (string) $r->pt;
                if ( ! isset( $type_idx[ $pt ] ) ) {
                    $obj               = get_post_type_object( $pt );
                    $type_idx[ $pt ]   = count( $type_list );
                    $type_list[]       = [ $pt, $obj ? html_entity_decode( (string) $obj->labels->name ) : $pt ];
                }
                if ( isset( $best[ (int) $r->id ] ) ) {
                    $topic_of[ (int) $r->id ] = $best[ (int) $r->id ][0];
                } elseif ( "post" !== $pt ) {
                    $topic_of[ (int) $r->id ] = $type_list[ $type_idx[ $pt ] ][1];
                } else {
                    $topic_of[ (int) $r->id ] = __( "No category", "jnk-linkweave" );
                }
            }
        }

        // The 12 biggest topics get their own row; the rest share an "Other topics" row.
        $sizes = array_count_values( $topic_of );
        arsort( $sizes );
        $named = array_slice( array_keys( $sizes ), 0, count( $sizes ) > 13 ? 12 : 13 );
        $other = __( "Other topics", "jnk-linkweave" );
        $rows  = [];
        foreach ( $posts as $r ) {
            $topic = in_array( $topic_of[ (int) $r->id ], $named, true ) ? $topic_of[ (int) $r->id ] : $other;
            $topic_of[ (int) $r->id ] = $topic;
            $rows[ $topic ][] = [ (int) $r->id, (int) $r->n, html_entity_decode( (string) $r->t ), $type_idx[ (string) $r->pt ] ];
        }

        // Biggest topics first (ties by name), "Other topics" always last.
        uksort( $rows, static function ( $x, $y ) use ( $rows, $other ) {
            if ( $x === $other || $y === $other ) {
                return $x === $other ? 1 : -1;
            }
            return count( $rows[ $y ] ) <=> count( $rows[ $x ] ) ?: strcasecmp( $x, $y );
        } );

        // Links between posts on the map, once per linking post pair (self-links
        // left out). Feeds both the topic grid and the graph.
        $pairs = $posts ? $wpdb->get_results( $wpdb->prepare(
            "SELECT source_post_id AS s, target_post_id AS t, COUNT(*) AS c FROM %i
             WHERE source_post_id <> target_post_id GROUP BY source_post_id, target_post_id",
            $links_t
        ) ) : [];
        $edges = [];
        foreach ( $pairs as $e ) {
            if ( isset( $topic_of[ (int) $e->s ], $topic_of[ (int) $e->t ] ) ) {
                $edges[] = [ (int) $e->s, (int) $e->t, (int) $e->c ];
            }
        }
        if ( count( $edges ) > self::MAX_EDGES ) {
            usort( $edges, static fn( $a, $b ) => $b[2] <=> $a[2] ?: $a[0] <=> $b[0] ?: $a[1] <=> $b[1] );
            $edges = array_slice( $edges, 0, self::MAX_EDGES );
        }

        $matrix = [];
        $mnames = array_slice( array_keys( $rows ), 0, 10 );
        if ( count( $mnames ) > 1 ) {
            $pos    = array_flip( $mnames );
            $matrix = array_fill( 0, count( $mnames ), array_fill( 0, count( $mnames ), 0 ) );
            foreach ( $pairs as $e ) {
                $from = $topic_of[ (int) $e->s ] ?? null;
                $to   = $topic_of[ (int) $e->t ] ?? null;
                if ( null !== $from && null !== $to && isset( $pos[ $from ], $pos[ $to ] ) ) {
                    $matrix[ $pos[ $from ] ][ $pos[ $to ] ]++;
                }
            }
        }

        $flat = [];
        foreach ( $edges as $e ) {
            $flat[] = $e[0];
            $flat[] = $e[1];
        }

        return [
            "edit"   => admin_url( "post.php?action=edit&post=" ),
            "view"   => home_url( "/" ),
            "types"  => $type_list,
            "rows"   => array_map( static fn( $name, $p ) => [ "name" => $name, "posts" => $p ], array_keys( $rows ), array_values( $rows ) ),
            "topics" => $mnames,
            "matrix" => $matrix,
            "edges"  => $flat,
            "total"  => count( $posts ),
        ];
    }
}
