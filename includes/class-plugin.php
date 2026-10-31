<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

final class Plugin {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function boot(): void {
        add_action( "init", [ self::class, "load_textdomain" ] );

        Index_Builder::register_hooks();
        Cron::register_hooks();
        // New, changed or removed auto-link rules empty the page cache.
        Page_Cache::register_hooks();

        add_action( "save_post", [ $this, "reindex_post_on_save" ], 20, 3 );
        add_action( "wp_trash_post", [ Index_Builder::class, "delete" ] );
        add_action( "before_delete_post", [ Index_Builder::class, "delete" ] );

        add_action( "rest_api_init", function () {
            ( new REST_API() )->register_routes();
        } );

        add_filter( "the_content", [ $this, "apply_auto_link_rules" ], 99 );

        // Reports for AI assistants (WordPress 6.9+ Abilities API).
        Abilities::register();

        add_action( "enqueue_block_editor_assets", [ $this, "enqueue_editor_assets" ] );

        // Classic Editor (TinyMCE) integration.
        add_filter( "mce_external_plugins", [ $this, "register_tinymce_plugin" ] );
        add_filter( "mce_buttons", [ $this, "register_tinymce_button" ] );
        add_action( "admin_enqueue_scripts", [ $this, "localize_tinymce" ] );

        if ( is_admin() ) {
            // Apply pending schema migrations on update (the activation hook does
            // not fire on in-place upgrades, e.g. free → Pro via zip upload).
            add_action( "admin_init", [ "JnkLinkweave_Activator", "maybe_upgrade" ] );
            // The first index after activation (only while the index is empty).
            add_action( "admin_init", [ Index_Builder::class, "maybe_start_first_index" ], 20 );
            // WooCommerce products join the default post types; read them once.
            add_action( "admin_init", [ Index_Builder::class, "maybe_index_new_default_types" ], 21 );
            ( new \JnkLinkweave\Admin\Admin() )->register_hooks();
        }
    }

    /**
     * Load the translations that ship in languages/ (es_ES, ko_KR, ...). The
     * wordpress.org translation platform is not available to us, so the .mo files
     * travel inside the zip. A matching file in wp-content/languages/plugins/
     * still wins, so a site can override any string.
     */
    public static function load_textdomain(): void {
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- translations ship in the zip.
        load_plugin_textdomain( "jnk-linkweave", false, dirname( plugin_basename( JNK_LINKWEAVE_FILE ) ) . "/languages" );
    }

    public function reindex_post_on_save( int $post_id, \WP_Post $post, bool $update ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( "publish" !== $post->post_status ) {
            return;
        }
        $settings = get_option( "jnk_linkweave_settings", [] );
        if ( empty( $settings["auto_index_on_save"] ) ) {
            // Indexing is off, but the link graph must still follow saved content,
            // or Orphans and Inbound Counts would wait for the next full rebuild.
            Cron::schedule_links_refresh( $post_id );
            return;
        }
        Index_Builder::schedule_reindex( $post_id );
    }

    public function apply_auto_link_rules( string $content ): string {
        return ( new Rules_Engine() )->apply_rules_to_content( $content );
    }

    public function register_tinymce_plugin( array $plugins ): array {
        if ( current_user_can( "edit_posts" ) ) {
            $plugins["jnk-linkweave"] = JNK_LINKWEAVE_URL . "editor/tinymce/plugin.js";
        }
        return $plugins;
    }

    public function register_tinymce_button( array $buttons ): array {
        if ( current_user_can( "edit_posts" ) ) {
            $buttons[] = "jnk-linkweave";
        }
        return $buttons;
    }

    public function localize_tinymce(): void {
        if ( ! current_user_can( "edit_posts" ) ) {
            return;
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->base, [ "post", "post-new" ], true ) ) {
            return;
        }
        wp_register_script( "jnk-linkweave-tinymce-cfg", "", [], JNK_LINKWEAVE_VERSION, true );
        wp_enqueue_script( "jnk-linkweave-tinymce-cfg" );
        $payload = [
            "restUrl" => esc_url_raw( rest_url( "jnk-linkweave/v1/" ) ),
            "nonce"   => wp_create_nonce( "wp_rest" ),
            "i18n"    => [
                "tooltip" => __( "Insert a JnK Linkweave suggestion", "jnk-linkweave" ),
                "title"   => __( "JnK Linkweave suggestions", "jnk-linkweave" ),
                "close"   => __( "Close", "jnk-linkweave" ),
                "empty"   => __( "No suggestions for this post yet.", "jnk-linkweave" ),
                "noPost"  => __( "Save the post once before requesting suggestions.", "jnk-linkweave" ),
                "insert"  => __( "Insert", "jnk-linkweave" ),
                "hint"    => __( "The link goes on a matching phrase already in your text when there is one; otherwise at the cursor. Select words first to link those instead.", "jnk-linkweave" ),
                "reasons" => [
                    "not_published" => __( "Suggestions appear once this post is published: drafts are not indexed yet. Publish it, wait a moment, then click the button again.", "jnk-linkweave" ),
                    "not_indexed"   => __( "This post has not been indexed yet. It is normally picked up within a minute of saving; try again shortly, or rebuild the index from the JnK Linkweave dashboard.", "jnk-linkweave" ),
                    /* translators: 1: indexable word count of this post, 2: configured minimum. */
                    "too_short"     => __( "This post is too short to index, so it has no suggestions yet. It has %1\$d indexable words and the minimum is %2\$d (small, common words are not counted).", "jnk-linkweave" ),
                    "type_excluded" => __( "This post type is not being indexed. You can add it under Post types in JnK Linkweave → Settings.", "jnk-linkweave" ),
                    "no_matches"    => __( "No related posts cleared the relevance threshold for this one. Lowering Minimum relevance in Settings will surface weaker matches.", "jnk-linkweave" ),
                    "index_empty"   => __( "The index has not been built yet. Build it once from JnK Linkweave → Dashboard → Rebuild index.", "jnk-linkweave" ),
                ],
                "strength" => [
                    __( "Very close match", "jnk-linkweave" ),
                    __( "Close match", "jnk-linkweave" ),
                    __( "Related", "jnk-linkweave" ),
                    __( "Loosely related", "jnk-linkweave" ),
                ],
            ],
        ];
        wp_add_inline_script(
            "jnk-linkweave-tinymce-cfg",
            "window.JnkLinkweaveTinyMCE = " . wp_json_encode( $payload ) . ";",
            "before"
        );
        // The same suggestions from the Code (Text) tab's toolbar, which the
        // TinyMCE button never reached. Classic editor screens only.
        if ( ! ( method_exists( $screen, "is_block_editor" ) && $screen->is_block_editor() ) ) {
            wp_enqueue_script( "jnk-linkweave-quicktags", JNK_LINKWEAVE_URL . "editor/quicktags.js", [ "quicktags", "jnk-linkweave-tinymce-cfg" ], JNK_LINKWEAVE_VERSION, true );
        }
    }

    public function enqueue_editor_assets(): void {
        $asset_file = JNK_LINKWEAVE_PATH . "editor/build/index.asset.php";
        if ( ! file_exists( $asset_file ) ) {
            return;
        }
        $asset = include $asset_file;
        wp_enqueue_script(
            "jnk-linkweave-editor",
            JNK_LINKWEAVE_URL . "editor/build/index.js",
            $asset["dependencies"] ?? [],
            $asset["version"] ?? JNK_LINKWEAVE_VERSION,
            true
        );
        // The sidebar uses wp.i18n; its translations are the languages/*.json files.
        wp_set_script_translations( "jnk-linkweave-editor", "jnk-linkweave", JNK_LINKWEAVE_PATH . "languages" );
        // The build extracts the sidebar styles (import './editor.css') into a
        // sibling stylesheet — enqueue it so the suggestion list is styled.
        $style = JNK_LINKWEAVE_PATH . "editor/build/index.css";
        if ( file_exists( $style ) ) {
            wp_enqueue_style(
                "jnk-linkweave-editor",
                JNK_LINKWEAVE_URL . "editor/build/index.css",
                [ "wp-components" ],
                $asset["version"] ?? JNK_LINKWEAVE_VERSION
            );
            // The build also writes index-rtl.css (sides flipped); WordPress loads
            // it instead for right-to-left languages.
            wp_style_add_data( "jnk-linkweave-editor", "rtl", "replace" );
        }
    }

}
