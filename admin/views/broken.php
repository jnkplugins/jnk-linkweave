<?php
defined( "ABSPATH" ) || exit;
use JnkLinkweave\Admin\UI;

$actions = '<span id="jnk-linkweave-scan-status" class="jnkui-muted"></span>'
    . '<button type="button" class="button button-primary" id="jnk-linkweave-scan-now">' . esc_html__( "Check all links now", "jnk-linkweave" ) . "</button>";

UI::open(
    __( "Broken links", "jnk-linkweave" ),
    __( "Links in your posts that lead to a page that no longer exists. Linkweave checks every day; you can also check right now.", "jnk-linkweave" ),
    $actions
);
?>
<div id="jnk-linkweave-broken-app" data-mount="broken"></div>
<?php UI::close(); ?>
