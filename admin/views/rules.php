<?php
defined( "ABSPATH" ) || exit;
use JnkLinkweave\Admin\UI;

// Extensions add page actions here (e.g. rule import / export).
ob_start();
do_action( "jnk_linkweave_rules_page_actions" );
$jnk_linkweave_rule_actions = (string) ob_get_clean();

UI::open(
    __( "Auto-link rules", "jnk-linkweave" ),
    __( "Pick a word or phrase and the page it should link to. Linkweave adds the link wherever that phrase appears, without changing your saved posts.", "jnk-linkweave" ),
    $jnk_linkweave_rule_actions
);
?>
<div id="jnk-linkweave-rules-app" data-mount="rules"></div>

<?php if ( ! \JnkLinkweave\Rules_Engine::is_pro() ) : ?>
    <?php // One restrained line at the point of intent: someone writing rules by
          // hand is exactly who wants to know the bulk URL changer exists. Nothing
          // padlocked, nothing disabled: a table of contents, not nagware. ?>
    <p class="description" style="margin-top:16px">
        <?php
        printf(
            /* translators: 1: opening link tag, 2: closing link tag. */
            esc_html__( "Need to change a URL across every post at once, with one-click undo? %1\$sSee what Pro adds%2\$s.", "jnk-linkweave" ),
            '<a href="' . esc_url( admin_url( 'admin.php?page=' . \JnkLinkweave\Admin\Admin::MENU_SLUG . '-upgrade&src=rules-empty' ) ) . '">',
            '</a>'
        );
        ?>
    </p>
<?php endif; ?>
<?php UI::close(); ?>
