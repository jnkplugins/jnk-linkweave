<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.
/** @var array $settings */
$settings = $settings ?? [];
$min_len  = (int)    ( $settings["min_post_length"] ?? 40 );
$per_post  = max( 1, min( 50, (int) ( $settings["suggestions_per_post"] ?? 10 ) ) );
$min_score = max( 0.0, min( 1.0, (float) ( $settings["min_relevance_score"] ?? 0.25 ) ) );
$ignore    = implode( ", ", array_map( "strval", (array) ( $settings["stopwords_custom"] ?? [] ) ) );
$auto     = ! empty( $settings["auto_index_on_save"] );
$delete   = ! empty( $settings["delete_data_on_uninstall"] );
$ranking  = ( ( $settings["ranking"] ?? "bm25" ) === "tfidf" ) ? "tfidf" : "bm25";
$selected_types = \JnkLinkweave\Index_Builder::base_post_types( (array) $settings );
$public_types   = get_post_types( [ "public" => true ], "objects" );
unset( $public_types["attachment"] ); // media has no linkable body content
?>
<?php
\JnkLinkweave\Admin\UI::open(
    __( "Settings", "jnk-linkweave" ),
    __( "Choose what Linkweave reads and how picky its suggestions are.", "jnk-linkweave" )
);
?>
    <?php if ( has_action( "jnk_linkweave_settings_after" ) ) : ?>
        <?php // The license card sits under the settings; the same jump link as in every JnK plugin. ?>
        <p style="margin:0 0 8px;text-align:end"><a href="#lp-jnk-linkweave-license"><?php esc_html_e( "License", "jnk-linkweave" ); ?> &darr;</a></p>
    <?php endif; ?>
    <form id="jnk-linkweave-settings-form" onsubmit="return false;">
        <h2 class="jnkui-section-title"><?php esc_html_e( "What Linkweave reads", "jnk-linkweave" ); ?></h2>
        <p class="jnkui-section-desc"><?php esc_html_e( "Which content is used for suggestions and reports.", "jnk-linkweave" ); ?></p>
        <table class="form-table" role="presentation">
            <tbody>
            <tr>
                <th scope="row"><?php esc_html_e( "Post types", "jnk-linkweave" ); ?></th>
                <td>
                    <fieldset id="jnk-linkweave-post-types">
                        <?php foreach ( $public_types as $type ) : ?>
                            <label style="margin-inline-end:14px;">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $selected_types, true ) ); ?>>
                                <?php echo esc_html( $type->labels->name ?: $type->name ); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description"><?php esc_html_e( "The kinds of content Linkweave reads and suggests links to. Posts and Pages by default, and Products when WooCommerce is active. Changes take effect straight away; adding a type reads those posts in the background.", "jnk-linkweave" ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="jnk-linkweave-min-len"><?php esc_html_e( "Shortest post to suggest", "jnk-linkweave" ); ?></label></th>
                <td>
                    <input id="jnk-linkweave-min-len" type="number" min="1" name="min_post_length" value="<?php echo esc_attr( (string) $min_len ); ?>">
                    <p class="description"><?php esc_html_e( "Very short posts are left out of suggestions. Small, common words (such as articles and conjunctions) are not counted, so 40 here is roughly an 80 to 100 word post.", "jnk-linkweave" ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( "Keep up to date", "jnk-linkweave" ); ?></th>
                <td><label><input type="checkbox" name="auto_index_on_save" value="1" <?php checked( $auto ); ?>> <?php esc_html_e( "Re-read a post every time it is saved", "jnk-linkweave" ); ?></label></td>
            </tr>
            </tbody>
        </table>
        <h2 class="jnkui-section-title"><?php esc_html_e( "Suggestions", "jnk-linkweave" ); ?></h2>
        <p class="jnkui-section-desc"><?php esc_html_e( "How link ideas are picked and shown in the editor.", "jnk-linkweave" ); ?></p>
        <table class="form-table" role="presentation">
            <tbody>
            <tr>
                <th scope="row"><label for="jnk-linkweave-ranking"><?php esc_html_e( "How posts are matched", "jnk-linkweave" ); ?></label></th>
                <td>
                    <select id="jnk-linkweave-ranking" name="ranking">
                        <option value="bm25"  <?php selected( $ranking, "bm25" ); ?>><?php esc_html_e( "Smart match (recommended)", "jnk-linkweave" ); ?></option>
                        <option value="tfidf" <?php selected( $ranking, "tfidf" ); ?>><?php esc_html_e( "Classic match", "jnk-linkweave" ); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e( "Smart match is not fooled by a word repeated many times or by very long posts, so its suggestions are usually sharper. You can switch at any time.", "jnk-linkweave" ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="jnk-linkweave-per-post"><?php esc_html_e( "Suggestions per post", "jnk-linkweave" ); ?></label></th>
                <td>
                    <input id="jnk-linkweave-per-post" type="number" min="1" max="50" name="suggestions_per_post" value="<?php echo esc_attr( (string) $per_post ); ?>">
                    <p class="description"><?php esc_html_e( "How many link ideas the editor sidebar shows for each post (1 to 50).", "jnk-linkweave" ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="jnk-linkweave-min-score"><?php esc_html_e( "How close a match must be", "jnk-linkweave" ); ?></label></th>
                <td>
                    <input id="jnk-linkweave-min-score" type="number" min="0" max="1" step="0.05" name="min_relevance_score" value="<?php echo esc_attr( (string) $min_score ); ?>">
                    <p class="description"><?php esc_html_e( "From 0 to 1. Lower shows more ideas, higher shows only very close matches. 0.25 is a good start.", "jnk-linkweave" ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="jnk-linkweave-ignore-words"><?php esc_html_e( "Words to ignore", "jnk-linkweave" ); ?></label></th>
                <td>
                    <textarea id="jnk-linkweave-ignore-words" name="stopwords_custom" rows="3" class="large-text" placeholder="<?php esc_attr_e( "watch, online, free, review", "jnk-linkweave" ); ?>"><?php echo esc_textarea( $ignore ); ?></textarea>
                    <p class="description"><?php esc_html_e( "Words your site uses in almost every post, so they say nothing about a topic. Separate them with commas. Words found in more than a third of your posts are already ignored. Saving a change rebuilds the index in the background.", "jnk-linkweave" ); ?></p>
                </td>
            </tr>
            <?php
            /**
             * Premium settings rows (BYOK LLM mode / model / API key) render here
             * from pro/. The free build emits nothing — no LLM fields at all.
             */
            do_action( "jnk_linkweave_settings_form_rows", $settings );
            ?>
            </tbody>
        </table>
        <h2 class="jnkui-section-title"><?php esc_html_e( "If you remove the plugin", "jnk-linkweave" ); ?></h2>
        <p class="jnkui-section-desc"><?php esc_html_e( "Your data is kept unless you say otherwise.", "jnk-linkweave" ); ?></p>
        <table class="form-table" role="presentation">
            <tbody>
            <tr>
                <th scope="row"><?php esc_html_e( "When you delete the plugin", "jnk-linkweave" ); ?></th>
                <td>
                    <label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $delete ); ?>> <?php esc_html_e( "Also delete Linkweave's data (rules, reports and settings)", "jnk-linkweave" ); ?></label>
                    <p class="description"><?php esc_html_e( "Off by default, so nothing is lost if you deactivate the plugin, delete it, or a Pro license runs out.", "jnk-linkweave" ); ?></p>
                    <?php $jnk_linkweave_delete_warning = __( "Deleting Linkweave will then permanently remove all your auto-link rules, reports and settings. Links already in your posts stay. This cannot be undone.", "jnk-linkweave" ); ?>
                    <div id="jnk-linkweave-delete-data-warning" class="notice notice-warning inline" data-confirm="<?php echo esc_attr( $jnk_linkweave_delete_warning . "\n\n" . __( "Tick this anyway?", "jnk-linkweave" ) ); ?>"<?php echo $delete ? "" : ' style="display:none"'; ?>><p><?php echo esc_html( $jnk_linkweave_delete_warning ); ?></p></div>
                    <script>
                    ( function () {
                        // Ticking it is one click; what it does on delete cannot be undone, so ask once and keep the warning in view.
                        var box = document.querySelector( 'input[type=checkbox][name="delete_data_on_uninstall"]' ), warn = document.getElementById( 'jnk-linkweave-delete-data-warning' );
                        if ( ! box || ! warn ) { return; }
                        function show() { warn.style.display = box.checked ? "" : "none"; }
                        box.addEventListener( "change", function () {
                            if ( box.checked && ! window.confirm( warn.getAttribute( "data-confirm" ) ) ) { box.checked = false; }
                            show();
                        } );
                        show();
                    } )();
                    </script>
                </td>
            </tr>
            </tbody>
        </table>

        <p style="display:flex;align-items:center;gap:10px"><button type="button" class="button button-primary" data-action="save-settings"><?php esc_html_e( "Save settings", "jnk-linkweave" ); ?></button> <span id="jnk-linkweave-settings-status" class="jnkui-muted"></span></p>
    </form>
    <?php
    /**
     * Premium license/activation UI renders here (from pro/), so a plugin's
     * settings and licensing live on one screen instead of under WP → Settings.
     * The free build emits nothing.
     */
    do_action( "jnk_linkweave_settings_after", $settings );
    ?>
<?php \JnkLinkweave\Admin\UI::close(); ?>
