<?php
defined( "ABSPATH" ) || exit;
use JnkLinkweave\Admin\UI;

UI::open(
    __( "Inbound links", "jnk-linkweave" ),
    __( "How many of your own posts link to each page. The pages at the top are the ones your site treats as most important.", "jnk-linkweave" )
);
// Link map data: every published post (of the indexed types) grouped by topic, with
// its inbound count, plus how many links run from each topic to each other topic.
// Tiles and a grid stay readable at thousands of posts, where a node graph does not.
global $wpdb;
$jnk_links_t = $wpdb->prefix . "jnk_linkweave_links";
$jnk_types   = \JnkLinkweave\REST_API::report_post_types();
$jnk_in      = implode( ",", array_fill( 0, count( $jnk_types ), "%s" ) );
$jnk_posts   = $wpdb->get_results( $wpdb->prepare(
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders.
    "SELECT p.ID AS id, p.post_title AS t, p.post_type AS pt, COUNT(l.id) AS n FROM {$wpdb->posts} p
     LEFT JOIN %i l ON l.target_post_id = p.ID
     WHERE p.post_status = 'publish' AND p.post_type IN ($jnk_in)
     GROUP BY p.ID, p.post_title, p.post_type ORDER BY n DESC, p.ID DESC LIMIT 3000",
    array_merge( [ $jnk_links_t ], $jnk_types )
) );

// Topic = the post's main category: its most-used category other than the default
// one. Other post types (pages, products) are grouped under their type name.
$jnk_topic_of = [];
if ( $jnk_posts ) {
    $jnk_ids  = implode( ",", array_map( static fn( $r ) => (int) $r->id, $jnk_posts ) );
    $jnk_cats = $wpdb->get_results(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list built above.
        "SELECT tr.object_id AS id, t.name, tt.term_id, tt.count FROM {$wpdb->term_relationships} tr
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'
         JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         WHERE tr.object_id IN ($jnk_ids) AND tt.term_id <> " . (int) get_option( "default_category" )
    );
    $jnk_best = [];
    foreach ( $jnk_cats as $c ) {
        $cur = $jnk_best[ (int) $c->id ] ?? null;
        if ( ! $cur || (int) $c->count > $cur[1] || ( (int) $c->count === $cur[1] && (int) $c->term_id < $cur[2] ) ) {
            $jnk_best[ (int) $c->id ] = [ html_entity_decode( (string) $c->name ), (int) $c->count, (int) $c->term_id ];
        }
    }
    foreach ( $jnk_posts as $r ) {
        if ( isset( $jnk_best[ (int) $r->id ] ) ) {
            $jnk_topic_of[ (int) $r->id ] = $jnk_best[ (int) $r->id ][0];
        } elseif ( "post" !== $r->pt ) {
            $obj = get_post_type_object( $r->pt );
            $jnk_topic_of[ (int) $r->id ] = $obj ? (string) $obj->labels->name : (string) $r->pt;
        } else {
            $jnk_topic_of[ (int) $r->id ] = __( "No category", "jnk-linkweave" );
        }
    }
}

// The 12 biggest topics get their own row; the rest share an "Other topics" row.
$jnk_sizes = array_count_values( $jnk_topic_of );
arsort( $jnk_sizes );
$jnk_named = array_slice( array_keys( $jnk_sizes ), 0, count( $jnk_sizes ) > 13 ? 12 : 13 );
$jnk_other = __( "Other topics", "jnk-linkweave" );
$jnk_rows  = [];
foreach ( $jnk_posts as $r ) {
    $topic = in_array( $jnk_topic_of[ (int) $r->id ], $jnk_named, true ) ? $jnk_topic_of[ (int) $r->id ] : $jnk_other;
    $jnk_topic_of[ (int) $r->id ] = $topic;
    $jnk_rows[ $topic ][] = [ (int) $r->id, (int) $r->n, html_entity_decode( (string) $r->t ) ];
}

// Biggest topics first (ties by name), "Other topics" always last.
uksort( $jnk_rows, static function ( $x, $y ) use ( $jnk_rows, $jnk_other ) {
    if ( $x === $jnk_other || $y === $jnk_other ) {
        return $x === $jnk_other ? 1 : -1;
    }
    return count( $jnk_rows[ $y ] ) <=> count( $jnk_rows[ $x ] ) ?: strcasecmp( $x, $y );
} );

// Links between topics, counted once per linking post pair.
$jnk_matrix = [];
$jnk_mnames = array_slice( array_keys( $jnk_rows ), 0, 10 );
if ( count( $jnk_mnames ) > 1 ) {
    $jnk_pos   = array_flip( $jnk_mnames );
    $jnk_pairs = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT source_post_id AS s, target_post_id AS t FROM %i", $jnk_links_t ) );
    $jnk_matrix = array_fill( 0, count( $jnk_mnames ), array_fill( 0, count( $jnk_mnames ), 0 ) );
    foreach ( $jnk_pairs as $e ) {
        $from = $jnk_topic_of[ (int) $e->s ] ?? null;
        $to   = $jnk_topic_of[ (int) $e->t ] ?? null;
        if ( null !== $from && null !== $to && isset( $jnk_pos[ $from ], $jnk_pos[ $to ] ) ) {
            $jnk_matrix[ $jnk_pos[ $from ] ][ $jnk_pos[ $to ] ]++;
        }
    }
}
$jnk_map = [
    "edit"   => admin_url( "post.php?action=edit&post=" ),
    "rows"   => array_map( static fn( $name, $posts ) => [ "name" => $name, "posts" => $posts ], array_keys( $jnk_rows ), array_values( $jnk_rows ) ),
    "topics" => $jnk_mnames,
    "matrix" => $jnk_matrix,
];
?>
<?php if ( count( $jnk_posts ) > 1 ) : ?>
<div class="jnkui-card">
    <div class="jnkui-card-head">
        <div><h2><?php esc_html_e( "Your link map", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "Every square is one post, grouped by topic. The colour shows how many of your posts link to it. Hover for the title, click to open it.", "jnk-linkweave" ); ?></p></div>
        <?php if ( count( $jnk_mnames ) > 1 ) : ?>
        <div class="jnkui-seg-toggle" role="tablist">
            <button type="button" class="button button-small is-active" data-view="tiles"><?php esc_html_e( "Posts", "jnk-linkweave" ); ?></button>
            <button type="button" class="button button-small" data-view="grid"><?php esc_html_e( "Topics", "jnk-linkweave" ); ?></button>
        </div>
        <?php endif; ?>
    </div>
    <div class="jnk-map" id="jnk-linkweave-map" data-map="<?php echo esc_attr( wp_json_encode( $jnk_map ) ); ?>">
        <div class="jnk-map-tip" hidden></div>
    </div>
</div>
<?php endif; ?>
<div id="jnk-linkweave-inbound-app" data-mount="inbound-counts"></div>
<p class="description" style="margin-top:16px">
    <?php esc_html_e( "Only links saved in your posts count here. Links added on the fly by auto-link rules are not stored. New links are counted within a minute of saving a post.", "jnk-linkweave" ); ?>
</p>
<?php UI::close(); ?>
