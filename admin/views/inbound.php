<?php
defined( "ABSPATH" ) || exit;
use JnkLinkweave\Admin\UI;

UI::open(
    __( "Inbound links", "jnk-linkweave" ),
    __( "How many of your own posts link to each page. The pages at the top are the ones your site treats as most important.", "jnk-linkweave" )
);
// Link map data (JnkLinkweave\Link_Map): every published post of the indexed
// types grouped by topic, its inbound count, links between topics, and the
// post-to-post links for the graph view.
$jnk_map    = \JnkLinkweave\Link_Map::data();
$jnk_mnames = $jnk_map["topics"];
?>
<?php if ( $jnk_map["total"] > 1 ) : ?>
<div class="jnkui-card">
    <div class="jnkui-card-head">
        <div><h2><?php esc_html_e( "Your link map", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "Every square is one post, grouped by topic. The colour shows how many of your posts link to it. Hover for the title, click to open it.", "jnk-linkweave" ); ?></p></div>
        <div class="jnkui-seg-toggle" role="tablist">
            <button type="button" class="button button-small is-active" data-view="tiles"><?php esc_html_e( "Posts", "jnk-linkweave" ); ?></button>
            <button type="button" class="button button-small" data-view="graph"><?php esc_html_e( "Graph", "jnk-linkweave" ); ?></button>
            <?php if ( count( $jnk_mnames ) > 1 ) : ?>
            <button type="button" class="button button-small" data-view="grid"><?php esc_html_e( "Topics", "jnk-linkweave" ); ?></button>
            <?php endif; ?>
        </div>
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
