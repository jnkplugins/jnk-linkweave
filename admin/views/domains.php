<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
use JnkLinkweave\Admin\UI;
use JnkLinkweave\Domains_Report;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters for a report screen.
$search = isset( $_GET["s"] ) ? sanitize_text_field( wp_unslash( $_GET["s"] ) ) : "";
$domain = isset( $_GET["domain"] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET["domain"] ) ) ) : "";
// phpcs:enable
$base    = admin_url( "admin.php?page=jnk-linkweave-domains" );
$summary = Domains_Report::summary();
$checked = $summary["checked"] > 0;

UI::open(
    __( "Sites you link to", "jnk-linkweave" ),
    __( "Every link in your posts that goes to another website, grouped by site. See who you send readers to most, and how many of those links are marked nofollow or sponsored.", "jnk-linkweave" )
);
?>
<div class="jnkui-grid">
    <div class="jnkui-stat">
        <div class="jnkui-stat-label"><span class="jnkui-dot is-brand"></span><?php esc_html_e( "Links to other sites", "jnk-linkweave" ); ?></div>
        <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( $summary["links"] ) ); ?></div>
        <div class="jnkui-stat-foot"><?php printf(
            /* translators: %s: number of posts. */
            esc_html( _n( "In %s post", "In %s posts", $summary["posts"], "jnk-linkweave" ) ),
            esc_html( number_format_i18n( $summary["posts"] ) )
        ); ?></div>
    </div>
    <div class="jnkui-stat">
        <div class="jnkui-stat-label"><span class="jnkui-dot is-brand"></span><?php esc_html_e( "Sites", "jnk-linkweave" ); ?></div>
        <div class="jnkui-stat-value"><?php echo esc_html( number_format_i18n( $summary["domains"] ) ); ?></div>
        <div class="jnkui-stat-foot"><?php esc_html_e( "Different domains your posts link to", "jnk-linkweave" ); ?></div>
    </div>
    <div class="jnkui-stat">
        <div class="jnkui-stat-label"><span class="jnkui-dot is-brand"></span><?php esc_html_e( "Nofollow / sponsored", "jnk-linkweave" ); ?></div>
        <div class="jnkui-stat-value"><?php echo esc_html( Domains_Report::share( $summary["nofollow"], $summary["links"] ) . " / " . Domains_Report::share( $summary["sponsored"], $summary["links"] ) ); ?></div>
        <div class="jnkui-stat-foot"><?php esc_html_e( "Share of links to other sites", "jnk-linkweave" ); ?></div>
    </div>
</div>

<?php if ( "" !== $domain ) :
    $links = Domains_Report::links( $domain ); ?>
<div class="jnkui-card">
    <div class="jnkui-card-head">
        <div><h2><bdi dir="ltr"><?php echo esc_html( $domain ); ?></bdi></h2>
        <p><?php printf(
            /* translators: %s: number of links. */
            esc_html( _n( "%s link", "%s links", count( $links ), "jnk-linkweave" ) ),
            esc_html( number_format_i18n( count( $links ) ) )
        ); ?></p></div>
        <a class="button" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( "All sites", "jnk-linkweave" ); ?></a>
    </div>
    <?php if ( ! $links ) : ?>
        <div class="jnkui-card-body"><?php echo UI::empty_state( __( "Nothing to show", "jnk-linkweave" ), __( "No links to this site were found in your posts.", "jnk-linkweave" ), "", "search" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
    <?php else : ?>
        <div class="jnkui-table-wrap"><table class="jnkui-table">
            <thead><tr>
                <th><?php esc_html_e( "Found in", "jnk-linkweave" ); ?></th>
                <th><?php esc_html_e( "Link text", "jnk-linkweave" ); ?></th>
                <th><?php esc_html_e( "Address", "jnk-linkweave" ); ?></th>
                <th><?php esc_html_e( "Marked as", "jnk-linkweave" ); ?></th>
                <?php if ( $checked ) : ?><th><?php esc_html_e( "Status", "jnk-linkweave" ); ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ( $links as $l ) : ?>
                <tr>
                    <td><?php if ( $l["source_edit"] ) : ?><a href="<?php echo esc_url( $l["source_edit"] ); ?>"><?php echo esc_html( $l["source_title"] ); ?></a><?php else : echo esc_html( $l["source_title"] ); endif; ?></td>
                    <td><bdi><?php echo esc_html( $l["anchor_text"] ); ?></bdi></td>
                    <td class="jnk-linkweave-target"><a href="<?php echo esc_url( $l["url"] ); ?>" target="_blank" rel="noopener noreferrer"><bdi dir="ltr"><?php echo esc_html( rawurldecode( $l["url"] ) ); ?></bdi></a></td>
                    <td><?php echo $l["rel"] ? esc_html( implode( ", ", $l["rel"] ) ) : '<span class="jnkui-muted">' . esc_html__( "followed", "jnk-linkweave" ) . "</span>"; ?></td>
                    <?php if ( $checked ) : ?><td><?php
                    if ( "" === $l["last_checked"] ) {
                        echo '<span class="jnkui-muted">' . esc_html__( "Not checked yet", "jnk-linkweave" ) . "</span>";
                    } elseif ( 1 === $l["is_broken"] ) {
                        echo '<span class="jnk-linkweave-pill is-broken">' . esc_html__( "Page missing", "jnk-linkweave" ) . "</span>";
                    } elseif ( 2 === $l["is_broken"] ) {
                        echo '<span class="jnk-linkweave-pill is-unreachable">' . esc_html__( "Did not answer", "jnk-linkweave" ) . "</span>";
                    } else {
                        echo '<span class="jnkui-pill is-ok">' . esc_html__( "Works", "jnk-linkweave" ) . "</span>";
                    }
                    ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php else :
    $rows = Domains_Report::rows( $search ); ?>
<div class="jnkui-card">
    <div class="jnkui-card-head">
        <div><h2><?php esc_html_e( "By site", "jnk-linkweave" ); ?></h2></div>
        <form method="get" action="<?php echo esc_url( admin_url( "admin.php" ) ); ?>">
            <input type="hidden" name="page" value="jnk-linkweave-domains">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( "Filter by domain…", "jnk-linkweave" ); ?>" aria-label="<?php esc_attr_e( "Filter by domain", "jnk-linkweave" ); ?>">
        </form>
    </div>
    <?php if ( ! $rows ) : ?>
        <div class="jnkui-card-body"><?php
        echo "" === $search // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
            ? UI::empty_state( __( "No links to other sites yet", "jnk-linkweave" ), __( "Your posts link only to your own site, or the links have not been read yet. Linkweave reads them when it builds the index and once a day.", "jnk-linkweave" ), "", "link" )
            : UI::empty_state( __( "Nothing to show", "jnk-linkweave" ), __( "No site matches that filter.", "jnk-linkweave" ), "", "search" );
        ?></div>
    <?php else : ?>
        <div class="jnkui-table-wrap"><table class="jnkui-table">
            <thead><tr>
                <th><?php esc_html_e( "Site", "jnk-linkweave" ); ?></th>
                <th class="is-num"><?php esc_html_e( "Links", "jnk-linkweave" ); ?></th>
                <th class="is-num"><?php esc_html_e( "Posts", "jnk-linkweave" ); ?></th>
                <th class="is-num"><?php esc_html_e( "Nofollow", "jnk-linkweave" ); ?></th>
                <th class="is-num"><?php esc_html_e( "Sponsored", "jnk-linkweave" ); ?></th>
                <?php if ( $checked ) : ?><th class="is-num"><?php esc_html_e( "Not working", "jnk-linkweave" ); ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( add_query_arg( "domain", rawurlencode( $r["domain"] ), $base ) ); ?>"><strong><bdi dir="ltr"><?php echo esc_html( $r["domain"] ); ?></bdi></strong></a></td>
                    <td class="is-num"><span class="jnkui-pill"><?php echo esc_html( number_format_i18n( $r["links"] ) ); ?></span></td>
                    <td class="is-num"><?php echo esc_html( number_format_i18n( $r["posts"] ) ); ?></td>
                    <td class="is-num"><?php echo esc_html( Domains_Report::share( $r["nofollow"], $r["links"] ) ); ?></td>
                    <td class="is-num"><?php echo esc_html( Domains_Report::share( $r["sponsored"], $r["links"] ) ); ?></td>
                    <?php if ( $checked ) : ?><td class="is-num"><?php echo $r["broken"] ? '<span class="jnkui-pill is-warn">' . esc_html( number_format_i18n( $r["broken"] ) ) . "</span>" : esc_html( number_format_i18n( 0 ) ); ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php endif; ?>
<p class="description"><?php
/** Filter: jnk_linkweave_domains_footnote. The line under the Sites you link to report. */
echo esc_html( (string) apply_filters( "jnk_linkweave_domains_footnote", __( "Read from the links saved in your posts. Links added by auto-link rules go to your own pages, so they are not here.", "jnk-linkweave" ) ) );
?></p>
<?php UI::close(); ?>
