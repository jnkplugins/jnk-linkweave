<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
use JnkLinkweave\Admin\UI;

// Normalised status (state: idle / queued / running / stalled / error). While idle
// it counts the rows really in the index: the number saved by the last full rebuild
// went stale as posts were indexed on publish or a post type was unticked.
$status   = \JnkLinkweave\Index_Builder::status();
$total    = (int) $status["total"];
$state    = (string) $status["state"];
$running  = ! empty( $status["is_running"] );
$indexed  = $running ? (int) $status["indexed"] : \JnkLinkweave\Index_Builder::indexed_count();
$read     = (int) $status["read"];
// Nothing read yet (fresh install, or the first read still queued/running): the
// link graph is empty too, so link counts would claim every post is an orphan.
$not_read = 0 === $indexed;

global $wpdb;
$links_table = $wpdb->prefix . "jnk_linkweave_links";
$orphans     = \JnkLinkweave\REST_API::orphan_rows();
$broken      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_broken = 1", $links_table ) );
$link_total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE target_post_id > 0", $links_table ) );
$rules       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_active = 1", $wpdb->prefix . "jnk_linkweave_rules" ) );
$top_linked  = $wpdb->get_results( $wpdb->prepare(
    "SELECT target_post_id AS post_id, COUNT(*) AS n FROM %i WHERE target_post_id > 0 GROUP BY target_post_id ORDER BY n DESC LIMIT 5",
    $links_table
) );
// Link coverage: how many published posts receive 0, 1-2, 3-5 and 6+ internal links.
$types_in    = \JnkLinkweave\REST_API::report_post_types();
$coverage    = [ 0 => 0, 1 => 0, 3 => 0, 6 => 0 ];
$inbound_all = $wpdb->get_col( $wpdb->prepare(
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders.
    "SELECT COUNT(l.id) FROM {$wpdb->posts} p LEFT JOIN %i l ON l.target_post_id = p.ID
     WHERE p.post_status = 'publish' AND p.post_type IN (" . implode( ",", array_fill( 0, count( $types_in ), "%s" ) ) . ")
     GROUP BY p.ID",
    array_merge( [ $links_table ], $types_in )
) );
foreach ( $inbound_all as $n ) {
    $n = (int) $n;
    $coverage[ $n >= 6 ? 6 : ( $n >= 3 ? 3 : ( $n >= 1 ? 1 : 0 ) ) ]++;
}
$coverage_total = max( 1, array_sum( $coverage ) );
$page = static fn( string $suffix ): string => admin_url( "admin.php?page=jnk-linkweave" . $suffix );

$actions = '<span id="jnk-linkweave-rebuild-status" class="jnkui-muted" role="status" aria-live="polite"></span>'
    . '<button type="button" class="button" id="jnk-linkweave-run-now" style="display:none">' . esc_html__( "Run now", "jnk-linkweave" ) . "</button>"
    . '<button type="button" class="button button-primary" id="jnk-linkweave-rebuild" data-state="' . esc_attr( $state ) . '" data-queued-at="' . esc_attr( (string) $status["queued_at"] ) . '"' . ( $running ? " disabled" : "" ) . ">" . esc_html__( "Rebuild index", "jnk-linkweave" ) . "</button>";

UI::open(
    __( "Overview", "jnk-linkweave" ),
    __( "How your internal links are doing, and what to fix next.", "jnk-linkweave" ),
    $actions
);
?>

<?php if ( $indexed === 0 && $running ) : ?>
    <div class="jnkui-callout">
        <p><strong><?php esc_html_e( "Linkweave is reading your posts for the first time.", "jnk-linkweave" ); ?></strong>
        <?php esc_html_e( "This page updates by itself. If it still says Queued after a few seconds, click Run now.", "jnk-linkweave" ); ?></p>
    </div>
<?php elseif ( in_array( $state, [ "stalled", "error" ], true ) ) : ?>
    <div class="jnkui-callout">
        <p><strong><?php esc_html_e( "The last read of your posts stopped before it finished.", "jnk-linkweave" ); ?></strong>
        <?php esc_html_e( "Nothing is lost. Click Run it again now, top right.", "jnk-linkweave" ); ?></p>
    </div>
<?php elseif ( $indexed === 0 ) : ?>
    <div class="jnkui-callout">
        <p><strong><?php esc_html_e( "Start here: let Linkweave read your posts once.", "jnk-linkweave" ); ?></strong>
        <?php esc_html_e( "Linkweave suggests links by comparing your posts with each other. Click Rebuild index; it takes a minute or two on most sites and then keeps itself up to date as you publish.", "jnk-linkweave" ); ?></p>
    </div>
<?php endif; ?>

<div class="jnkui-grid">
    <div class="jnkui-stat">
        <div class="jnkui-stat-label"><span class="jnkui-dot <?php echo $indexed > 0 ? "is-ok" : "is-warn"; ?>"></span><?php esc_html_e( "Posts Linkweave knows", "jnk-linkweave" ); ?></div>
        <div class="jnkui-stat-value" id="jnk-linkweave-index-count"><?php echo esc_html( number_format_i18n( $indexed ) ); ?></div>
        <div class="jnkui-stat-foot" id="jnk-linkweave-index-summary">
            <?php if ( $running && "queued" === $state ) : ?>
                <?php esc_html_e( "Queued, waiting for WordPress to start it", "jnk-linkweave" ); ?>
            <?php elseif ( $running ) : ?>
                <?php printf(
                    /* translators: 1: posts read so far, 2: total posts. */
                    esc_html__( "Reading posts %1\$d of %2\$d", "jnk-linkweave" ),
                    (int) ( "writing" === $status["phase"] ? $total : $read ),
                    (int) $total
                ); ?>
            <?php else : ?>
                <?php printf(
                    /* translators: %s: time of the last full rebuild. */
                    esc_html__( "Last full read: %s", "jnk-linkweave" ),
                    esc_html( \JnkLinkweave\Dates::human( $status["finished_at"] ?? "", true, __( "never", "jnk-linkweave" ) ) )
                ); ?>
            <?php endif; ?>
        </div>
    </div>
    <a class="jnkui-stat" href="<?php echo esc_url( $page( "-orphans" ) ); ?>">
        <div class="jnkui-stat-label"><span class="jnkui-dot <?php echo $not_read ? "is-brand" : ( $orphans ? "is-warn" : "is-ok" ); ?>"></span><?php esc_html_e( "Posts nothing links to", "jnk-linkweave" ); ?></div>
        <div class="jnkui-stat-value"><?php echo $not_read ? "&ndash;" : esc_html( number_format_i18n( count( $orphans ) ) ); ?></div>
        <div class="jnkui-stat-foot"><?php echo $not_read ? esc_html__( "Counted after the first read", "jnk-linkweave" ) : ( $orphans ? esc_html__( "Readers and search engines struggle to find these", "jnk-linkweave" ) : esc_html__( "Every post has at least one link in", "jnk-linkweave" ) ); ?></div>
    </a>
    <a class="jnkui-stat" href="<?php echo esc_url( $page( "-broken" ) ); ?>">
        <div class="jnkui-stat-label"><span class="jnkui-dot <?php echo $not_read ? "is-brand" : ( $broken ? "is-bad" : "is-ok" ); ?>"></span><?php esc_html_e( "Broken links", "jnk-linkweave" ); ?></div>
        <div class="jnkui-stat-value"><?php echo $not_read ? "&ndash;" : esc_html( number_format_i18n( $broken ) ); ?></div>
        <div class="jnkui-stat-foot"><?php echo $not_read ? esc_html__( "Counted after the first read", "jnk-linkweave" ) : ( $broken ? esc_html__( "Links that lead to a missing page", "jnk-linkweave" ) : esc_html__( "No dead links found", "jnk-linkweave" ) ); ?></div>
    </a>
    <a class="jnkui-stat" href="<?php echo esc_url( $page( "-inbound" ) ); ?>">
        <div class="jnkui-stat-label"><span class="jnkui-dot is-brand"></span><?php esc_html_e( "Internal links", "jnk-linkweave" ); ?></div>
        <div class="jnkui-stat-value"><?php echo $not_read ? "&ndash;" : esc_html( number_format_i18n( $link_total ) ); ?></div>
        <div class="jnkui-stat-foot">
            <?php if ( $not_read ) : esc_html_e( "Counted after the first read", "jnk-linkweave" ); else : printf(
                /* translators: %s: number of active auto-link rules. */
                esc_html( _n( "Plus %s auto-link rule", "Plus %s auto-link rules", $rules, "jnk-linkweave" ) ),
                esc_html( number_format_i18n( $rules ) )
            ); endif; ?>
        </div>
    </a>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head">
        <div><h2><?php esc_html_e( "Link coverage", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "How many internal links point at each of your posts. Aim to move posts out of the red.", "jnk-linkweave" ); ?></p></div>
    </div>
    <div class="jnkui-card-body">
        <?php if ( $not_read ) : ?>
            <p class="jnkui-muted"><?php esc_html_e( "Counted after the first read", "jnk-linkweave" ); ?></p>
        <?php else : ?>
        <?php
        $buckets = [
            0 => [ __( "No links in", "jnk-linkweave" ), "#ef4444" ],
            1 => [ __( "1 to 2 links", "jnk-linkweave" ), "#f59e0b" ],
            3 => [ __( "3 to 5 links", "jnk-linkweave" ), "#22c55e" ],
            6 => [ __( "6 or more", "jnk-linkweave" ), "#0e7490" ],
        ];
        ?>
        <div class="jnkui-seg" role="img" aria-label="<?php esc_attr_e( "Share of posts by number of internal links pointing at them", "jnk-linkweave" ); ?>">
            <?php foreach ( $buckets as $k => [ $label, $color ] ) : if ( ! $coverage[ $k ] ) { continue; } ?>
                <span style="width:<?php echo esc_attr( (string) round( $coverage[ $k ] / $coverage_total * 100, 2 ) ); ?>%;background:<?php echo esc_attr( $color ); ?>" title="<?php echo esc_attr( $label . ": " . $coverage[ $k ] ); ?>"></span>
            <?php endforeach; ?>
        </div>
        <div class="jnkui-legend">
            <?php foreach ( $buckets as $k => [ $label, $color ] ) : ?>
                <div><b><?php echo esc_html( number_format_i18n( $coverage[ $k ] ) ); ?></b><small><i style="background:<?php echo esc_attr( $color ); ?>"></i><?php echo esc_html( $label ); ?></small></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="jnkui-grid-2">
    <div class="jnkui-card">
        <div class="jnkui-card-head">
            <div><h2><?php esc_html_e( "Posts that need a link", "jnk-linkweave" ); ?></h2>
            <p><?php esc_html_e( "Open one and Linkweave shows which of your posts should link to it.", "jnk-linkweave" ); ?></p></div>
            <?php if ( ! $not_read && count( $orphans ) > 5 ) : ?><a class="button button-small" href="<?php echo esc_url( $page( "-orphans" ) ); ?>"><?php esc_html_e( "See all", "jnk-linkweave" ); ?></a><?php endif; ?>
        </div>
        <?php if ( $not_read ) : ?>
            <div class="jnkui-card-body"><p class="jnkui-muted"><?php esc_html_e( "Counted after the first read", "jnk-linkweave" ); ?></p></div>
        <?php elseif ( ! $orphans ) : ?>
            <div class="jnkui-card-body"><?php echo UI::empty_state( __( "Nothing to fix", "jnk-linkweave" ), __( "Every post has at least one internal link pointing at it.", "jnk-linkweave" ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
        <?php else : ?>
            <ul class="jnkui-list">
                <?php foreach ( array_slice( $orphans, 0, 5 ) as $o ) :
                    $type_obj = get_post_type_object( $o->post_type ); ?>
                    <li>
                        <span><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $o->post_id, "raw" ) ); ?>"><?php echo esc_html( $o->post_title ?: sprintf( "#%d", (int) $o->post_id ) ); ?></a>
                        <span class="jnkui-sub"><?php echo esc_html( $type_obj ? (string) $type_obj->labels->singular_name : (string) $o->post_type ); ?></span></span>
                        <span class="jnkui-pill is-warn"><?php esc_html_e( "0 links in", "jnk-linkweave" ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="jnkui-card">
        <?php
        /**
         * The right-hand card. Premium builds show click analytics here; the free
         * build shows a getting-started checklist.
         */
        if ( has_action( "jnk_linkweave_dashboard_side_card" ) ) :
            do_action( "jnk_linkweave_dashboard_side_card" );
        else : ?>
            <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Getting started", "jnk-linkweave" ); ?></h2>
            <p><?php esc_html_e( "Four steps to a well-linked site.", "jnk-linkweave" ); ?></p></div></div>
            <ol class="jnkui-steps">
                <li class="<?php echo $indexed > 0 ? "is-done" : ""; ?>"><strong><?php esc_html_e( "Let Linkweave read your posts", "jnk-linkweave" ); ?></strong><?php esc_html_e( "Rebuild index, top right.", "jnk-linkweave" ); ?></li>
                <li class="<?php echo $link_total > 0 ? "is-done" : ""; ?>"><strong><?php esc_html_e( "Add links while you write", "jnk-linkweave" ); ?></strong><?php esc_html_e( "Open any post: suggestions appear in the Linkweave sidebar.", "jnk-linkweave" ); ?></li>
                <li class="<?php echo $rules > 0 ? "is-done" : ""; ?>"><strong><?php esc_html_e( "Add an auto-link rule", "jnk-linkweave" ); ?></strong><?php esc_html_e( "Link a phrase to a page everywhere it appears.", "jnk-linkweave" ); ?></li>
                <li class="<?php echo ! $orphans && $indexed > 0 ? "is-done" : ""; ?>"><strong><?php esc_html_e( "Give every post a link in", "jnk-linkweave" ); ?></strong><?php esc_html_e( "Work down the list on the left.", "jnk-linkweave" ); ?></li>
            </ol>
        <?php endif; ?>
    </div>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head">
        <div><h2><?php esc_html_e( "Your most linked-to posts", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "The pages your own site points to most. Search engines treat these as your most important pages.", "jnk-linkweave" ); ?></p></div>
        <a class="button button-small" href="<?php echo esc_url( $page( "-inbound" ) ); ?>"><?php esc_html_e( "All posts", "jnk-linkweave" ); ?></a>
    </div>
    <?php if ( $not_read ) : ?>
        <div class="jnkui-card-body"><p class="jnkui-muted"><?php esc_html_e( "Counted after the first read", "jnk-linkweave" ); ?></p></div>
    <?php elseif ( ! $top_linked ) : ?>
        <div class="jnkui-card-body"><?php echo UI::empty_state( __( "No internal links yet", "jnk-linkweave" ), __( "Once your posts link to each other, the busiest pages show up here.", "jnk-linkweave" ), "", "link" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
    <?php else :
        $max = max( 1, (int) $top_linked[0]->n ); ?>
        <ul class="jnkui-list">
            <?php foreach ( $top_linked as $t ) : ?>
                <li>
                    <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $t->post_id, "raw" ) ); ?>"><?php echo esc_html( get_the_title( (int) $t->post_id ) ?: sprintf( "#%d", (int) $t->post_id ) ); ?></a>
                    <span style="display:flex;align-items:center;gap:12px;flex:0 0 46%">
                        <span class="jnkui-bar" style="flex:1"><i style="width:<?php echo esc_attr( (string) round( (int) $t->n / $max * 100 ) ); ?>%"></i></span>
                        <span class="jnkui-muted" style="min-width:64px;text-align:right"><?php printf(
                            /* translators: %s: number of links. */
                            esc_html( _n( "%s link", "%s links", (int) $t->n, "jnk-linkweave" ) ),
                            esc_html( number_format_i18n( (int) $t->n ) )
                        ); ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php UI::close(); ?>
