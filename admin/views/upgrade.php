<?php
/**
 * JnK Linkweave — "Upgrade to Pro" page (free build only).
 *
 * A single, restrained in-admin upsell: a feature summary, the in-place upgrade
 * reassurance, and a link out to pricing. Registered only when the Pro module is
 * absent (see Admin::register_menu); the Pro build shows its License page instead.
 * No premium code, no phone-home — just static marketing + a link.
 */

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

if ( ! current_user_can( "edit_posts" ) ) {
    wp_die( esc_html__( "You do not have permission to access this page.", "jnk-linkweave" ) );
}

// utm_content records HOW the visitor reached this page, so the admin-menu item, the
// Plugins-row link and any future in-context pointer can be told apart on the far
// side. Whitelisted rather than passed through, since the value lands in a URL.
$jnk_linkweave_src     = isset( $_GET["src"] ) ? sanitize_key( wp_unslash( $_GET["src"] ) ) : ""; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only attribution, changes no state.
$jnk_linkweave_allowed = [ "plugins-row", "rules-empty", "suggestions-empty", "settings", "tab", "orphans" ];
$jnk_linkweave_content = in_array( $jnk_linkweave_src, $jnk_linkweave_allowed, true ) ? $jnk_linkweave_src : "admin-menu";

$jnk_linkweave_pricing_url = apply_filters( "jnk_linkweave_upgrade_url", "https://jnkplugins.com/jnk-linkweave/pricing.php?utm_source=jnk-linkweave&utm_medium=plugin&utm_campaign=pro-upgrade&utm_content=" . rawurlencode( $jnk_linkweave_content ) );

$jnk_linkweave_pro_features = [
    [ __( "One-click auto-link map", "jnk-linkweave" ), __( "Turn your topical clusters into auto-link rules in one click, keyed on phrases your other posts already use.", "jnk-linkweave" ) ],
    [ __( "Add links in bulk", "jnk-linkweave" ), __( "Pick several related posts and add a link from each of them to the page you are working on, in one click.", "jnk-linkweave" ) ],
    [ __( "Bulk review & approve", "jnk-linkweave" ), __( "Tick several editor suggestions and insert them all at once.", "jnk-linkweave" ) ],
    [ __( "Internal-link click analytics", "jnk-linkweave" ), __( "See which internal links visitors actually follow, tracked on your own server.", "jnk-linkweave" ) ],
    [ __( "Search Console targeting", "jnk-linkweave" ), __( "Import your Search Console Queries export to steer suggestions toward pages that earn clicks.", "jnk-linkweave" ) ],
    [ __( "Focus-keyword boost", "jnk-linkweave" ), __( "Rank suggestions using the Yoast or Rank Math focus keyphrases you already set.", "jnk-linkweave" ) ],
    [ __( "Broken-link repair", "jnk-linkweave" ), __( "Unlink or re-point the dead links the free report finds, one at a time or all at once.", "jnk-linkweave" ) ],
    [ __( "Bulk URL changer + rollback", "jnk-linkweave" ), __( "Find-and-replace a URL across your whole site, with one-click rollback of any batch.", "jnk-linkweave" ) ],
    [ __( "Link check before you publish", "jnk-linkweave" ), __( "When you click Publish, see how many internal links the post has and add a good match in one click.", "jnk-linkweave" ) ],
    [ __( "Dead-end report", "jnk-linkweave" ), __( "Find posts that link to none of your other posts, with links in and out for every post and a CSV export.", "jnk-linkweave" ) ],
    [ __( "More from each rule", "jnk-linkweave" ), __( "Other words for the same link, limit a rule to some post types or categories, mark paid links as sponsored, switch rules off for one post, and import or export rules as CSV.", "jnk-linkweave" ) ],
    [ __( "AI assistants (read only)", "jnk-linkweave" ), __( "Let AI tools that use the WordPress Abilities API read your orphan posts, link counts and suggestions. Needs WordPress 6.9 or newer.", "jnk-linkweave" ) ],
    [ __( "Anchor text report", "jnk-linkweave" ), __( "See every anchor you use, where it points, and which ones are over-used.", "jnk-linkweave" ) ],
    [ __( "Related posts", "jnk-linkweave" ), __( "A “Read more” list of related posts for readers, added with a shortcode.", "jnk-linkweave" ) ],
    [ __( "Link preview cards", "jnk-linkweave" ), __( "Optional hover cards with title, image and excerpt on internal links.", "jnk-linkweave" ) ],
    [ __( "CSV export", "jnk-linkweave" ), __( "Export the orphan-page and anchor-text reports to CSV.", "jnk-linkweave" ) ],
    [ __( "Cross-site linking", "jnk-linkweave" ), __( "Suggest links between the sites on your license, right in the editor. Works on every plan.", "jnk-linkweave" ) ],
    [ __( "AI second opinion", "jnk-linkweave" ), __( "Let AI re-order the suggestions using your own OpenAI or Anthropic key. You pay them directly; we never charge per use.", "jnk-linkweave" ) ],
];
?>
<?php
\JnkLinkweave\Admin\UI::open(
    __( "Linkweave Pro", "jnk-linkweave" ),
    __( "You are using the free version: unlimited auto-link rules, suggestions in the editor, and the orphan and broken-link reports. Pro adds the tools that do the work for you.", "jnk-linkweave" ),
    '<a href="' . esc_url( $jnk_linkweave_pricing_url ) . '" target="_blank" rel="noopener noreferrer" class="button jnkui-btn-brand">' . esc_html__( "See Pro plans", "jnk-linkweave" ) . "</a>"
);
?>
<div class="jnkui-callout" style="background:var(--jnk-ok-bg);border-color:#a7f3d0;color:#065f46">
    <p><strong><?php esc_html_e( "Upgrading keeps everything.", "jnk-linkweave" ); ?></strong>
    <?php esc_html_e( "Install Pro over this version: same rules, same reports, nothing to set up again.", "jnk-linkweave" ); ?></p>
</div>
<div class="jnkui-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
    <?php foreach ( $jnk_linkweave_pro_features as $f ) : ?>
        <div class="jnkui-stat">
            <div class="jnkui-stat-label" style="color:var(--jnk-ink);font-size:14px"><span class="jnkui-dot is-brand"></span><?php echo esc_html( $f[0] ); ?></div>
            <div class="jnkui-stat-foot" style="font-size:13px;margin-top:8px"><?php echo esc_html( $f[1] ); ?></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( "Already bought Pro?", "jnk-linkweave" ); ?></h2></div></div>
    <ol class="jnkui-steps">
        <li><strong><?php esc_html_e( "Download the Pro zip", "jnk-linkweave" ); ?></strong><?php esc_html_e( "From your account on jnkplugins.com, under your license.", "jnk-linkweave" ); ?>
            <br><a class="button button-primary" style="margin-top:6px" href="<?php echo esc_url( "https://license.jnkplugins.com/account/licenses?utm_source=plugin&utm_medium=upgrade-page&utm_campaign=jnk-linkweave" ); ?>" target="_blank" rel="noopener"><?php esc_html_e( "Download the Pro zip", "jnk-linkweave" ); ?></a></li>
        <li><strong><?php esc_html_e( "Upload it over this one", "jnk-linkweave" ); ?></strong><?php esc_html_e( "Plugins, Add New, Upload Plugin. WordPress shows this copy as JnK Linkweave and the upload as JnK Linkweave Pro. Choose “Replace current with uploaded”.", "jnk-linkweave" ); ?></li>
        <li><strong><?php esc_html_e( "Enter your license key", "jnk-linkweave" ); ?></strong><?php esc_html_e( "A notice at the top of the screen takes you to the box, in Linkweave Settings under License. The key is in your purchase email.", "jnk-linkweave" ); ?></li>
    </ol>
</div>
<?php \JnkLinkweave\Admin\UI::close(); ?>
