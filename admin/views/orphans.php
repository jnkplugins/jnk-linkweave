<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
use JnkLinkweave\Admin\UI;

/**
 * Premium builds add an "Export CSV" action here (the export REST route is
 * registered only by pro/). The free build renders nothing extra.
 */
ob_start();
do_action( "jnk_linkweave_orphans_page_actions" );
$actions = (string) ob_get_clean();

UI::open(
    __( "Posts nothing links to", "jnk-linkweave" ),
    __( "Published posts with no internal links pointing at them. Pick one to see which of your posts should link to it.", "jnk-linkweave" ),
    $actions
);
?>
<div id="jnk-linkweave-orphans-app" data-mount="orphans"></div>
<p class="description" style="margin-top:16px">
    <?php esc_html_e( "Only links saved in your posts count here. Links added on the fly by auto-link rules are not stored, so a post that only gets those can still show up. New links are picked up within a minute of saving a post.", "jnk-linkweave" ); ?>
</p>
<?php UI::close(); ?>
