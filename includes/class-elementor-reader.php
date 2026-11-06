<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * Elementor pages, read (free).
 *
 * An Elementor page renders from its own JSON (_elementor_data), not from
 * post_content. Elementor writes a plain copy into post_content when the page is
 * saved in its editor, but that copy goes stale or is empty after an import or a
 * template kit (persona shop-elementor, 1 Nov 2026: such a page was not indexed
 * and its links were not counted).
 *
 *   - The page's words and links come from the JSON (filter
 *     jnk_linkweave_post_body), so suggestions, orphans and link counts follow
 *     what visitors see.
 *   - Elementor's own save queues the page to be read again.
 *   - In the Elementor editor, Page Settings has a "Linkweave link ideas"
 *     section, the same suggestions the block editor sidebar shows. Insert link
 *     puts the link on matching words in a Text Editor widget through
 *     Elementor's own editor command (editor/elementor/), so Elementor's undo
 *     and its Update button handle it; Copy link is there for its link box.
 *     Nothing here writes to the page from the server.
 *
 * Changing links inside the JSON (URL changer, repairs) is a Pro feature.
 */
class Elementor_Reader {

    public const DATA_KEY = "_elementor_data";
    public const MODE_KEY = "_elementor_edit_mode";

    /** Settings that hold words visitors read (also inside repeater items). */
    public const TEXT_KEYS = [
        "title", "editor", "text", "description", "title_text", "description_text",
        "tab_title", "tab_content", "item_title", "item_description", "content",
        "caption", "testimonial_content", "alert_title", "alert_description",
        "blockquote_content", "before_text", "highlighted_text", "after_text",
        "inner_text", "heading", "sub_heading", "subtitle", "button_text", "html",
        "paragraph",
    ];

    public static function register(): void {
        add_filter( "jnk_linkweave_post_body", [ __CLASS__, "post_body" ], 10, 2 );
        // Elementor's own save writes the page: read it again in the background.
        add_action( "elementor/editor/after_save", static function ( $post_id ) {
            Index_Builder::schedule_reindex( (int) $post_id );
        } );
        add_action( "elementor/documents/register_controls", [ __CLASS__, "editor_controls" ] );
        add_action( "elementor/editor/after_enqueue_scripts", [ __CLASS__, "editor_script" ] );
    }

    /** Elementor is running and this post is built with it. */
    public static function is_built_with( int $post_id ): bool {
        if ( ! defined( "ELEMENTOR_VERSION" ) || $post_id <= 0 ) {
            return false;
        }
        return "builder" === get_post_meta( $post_id, self::MODE_KEY, true )
            && "" !== (string) get_post_meta( $post_id, self::DATA_KEY, true );
    }

    /** @return array|null The page's element tree, or null when there is none. */
    public static function data( int $post_id ): ?array {
        $raw  = (string) get_post_meta( $post_id, self::DATA_KEY, true );
        $data = "" === $raw ? null : json_decode( $raw, true );
        return is_array( $data ) ? $data : null;
    }

    /* -------------------- reading -------------------- */

    /** Filter: the page's HTML from its Elementor data (post_content when it has none). */
    public static function post_body( $content, $post ) {
        if ( ! $post instanceof \WP_Post || ! self::is_built_with( (int) $post->ID ) ) {
            return $content;
        }
        $data = self::data( (int) $post->ID );
        $html = null === $data ? "" : trim( self::html( $data ) );
        return "" === $html ? $content : $html;
    }

    /** The words and links of an element tree as simple HTML. */
    public static function html( array $elements, int $depth = 0 ): string {
        if ( $depth > 30 ) {
            return "";
        }
        $out = "";
        foreach ( $elements as $el ) {
            if ( ! is_array( $el ) ) {
                continue;
            }
            $settings = is_array( $el["settings"] ?? null ) ? $el["settings"] : [];
            if ( "widget" === ( $el["elType"] ?? "" ) ) {
                $out .= self::widget_html( $settings );
            }
            if ( ! empty( $el["elements"] ) && is_array( $el["elements"] ) ) {
                $out .= self::html( $el["elements"], $depth + 1 );
            }
        }
        return $out;
    }

    private static function widget_html( array $settings ): string {
        $out  = "";
        $link = self::link_of( $settings );
        $used = [];
        if ( null !== $link ) {
            $label = self::link_label( $settings );
            if ( "" !== $label ) {
                $out   .= '<p><a href="' . esc_url( $link ) . '">' . esc_html( $label ) . "</a></p>\n";
                $used[] = self::label_key( $settings );
            }
        }
        foreach ( $settings as $key => $value ) {
            if ( in_array( $key, $used, true ) ) {
                continue;
            }
            $text = in_array( $key, self::TEXT_KEYS, true ) ? self::text_of( $value ) : null;
            if ( null !== $text && "" !== trim( $text ) ) {
                $out .= "<p>" . $text . "</p>\n";
            } elseif ( is_array( $value ) && array_is_list( $value ) ) {
                foreach ( $value as $item ) {
                    if ( is_array( $item ) ) {
                        $out .= self::widget_html( $item );
                    }
                }
            }
        }
        return $out;
    }

    /**
     * A setting's text: a plain string, or an Elementor 4 (atomic widget) typed
     * value such as {"$$type":"escaped-html","value":"..."} (the Paragraph and
     * Heading widgets). Null when it holds no text.
     */
    public static function text_of( $value ): ?string {
        if ( is_string( $value ) ) {
            return $value;
        }
        if ( is_array( $value ) && isset( $value['$$type'], $value["value"] ) && is_string( $value["value"] )
            && in_array( $value['$$type'], [ "escaped-html", "html", "string" ], true ) ) {
            return $value["value"];
        }
        return null;
    }

    /**
     * The address a widget (or repeater item) links to, if any: a classic link
     * control ({"url":...}) or an Elementor 4 typed link whose destination is an
     * address or a post ({"$$type":"link","value":{"destination":...}}).
     */
    public static function link_of( array $settings ): ?string {
        $link = $settings["link"] ?? null;
        if ( is_array( $link ) && isset( $link["url"] ) && is_string( $link["url"] ) && "" !== trim( $link["url"] ) ) {
            return trim( $link["url"] );
        }
        if ( is_array( $link ) && "link" === ( $link['$$type'] ?? "" ) && is_array( $link["value"]["destination"] ?? null ) ) {
            $dest = $link["value"]["destination"];
            if ( "url" === ( $dest['$$type'] ?? "" ) && is_string( $dest["value"] ?? null ) && "" !== trim( $dest["value"] ) ) {
                return trim( $dest["value"] );
            }
            if ( "query" === ( $dest['$$type'] ?? "" ) ) {
                $id  = (int) ( $dest["value"]["id"]["value"] ?? $dest["value"]["id"] ?? 0 );
                $url = $id > 0 ? get_permalink( $id ) : false;
                return $url ? (string) $url : null;
            }
        }
        return null;
    }

    private static function label_key( array $settings ): string {
        foreach ( [ "text", "title", "title_text", "item_title", "button_text", "heading", "paragraph" ] as $key ) {
            $text = self::text_of( $settings[ $key ] ?? null );
            if ( null !== $text && "" !== trim( $text ) ) {
                return $key;
            }
        }
        return "";
    }

    public static function link_label( array $settings ): string {
        $key = self::label_key( $settings );
        return "" === $key ? "" : trim( wp_strip_all_tags( (string) self::text_of( $settings[ $key ] ) ) );
    }

    /* -------------------- in the Elementor editor -------------------- */

    /** Page Settings > "Linkweave link ideas". */
    public static function editor_controls( $document ): void {
        if ( ! is_object( $document ) || ! method_exists( $document, "get_main_id" ) || ! class_exists( "\\Elementor\\Controls_Manager" ) ) {
            return;
        }
        $post_id = (int) $document->get_main_id();
        if ( $post_id <= 0 || ! current_user_can( "edit_post", $post_id ) ) {
            return;
        }
        $document->start_controls_section( "jnk_linkweave_ideas_section", [
            "label" => __( "Linkweave link ideas", "jnk-linkweave" ),
            "tab"   => \Elementor\Controls_Manager::TAB_SETTINGS,
        ] );
        $document->add_control( "jnk_linkweave_ideas", [
            "type" => \Elementor\Controls_Manager::RAW_HTML,
            "raw"  => '<div class="jnk-lw-el-ideas" data-post="' . esc_attr( (string) $post_id ) . '"><p>' . esc_html__( "Loading link ideas…", "jnk-linkweave" ) . "</p></div>",
        ] );
        $document->end_controls_section();
    }

    public static function editor_script(): void {
        $asset_file = JNK_LINKWEAVE_PATH . "editor/build-elementor/index.asset.php";
        $asset      = file_exists( $asset_file ) ? require $asset_file : [ "dependencies" => [ "wp-i18n" ], "version" => JNK_LINKWEAVE_VERSION ];
        wp_enqueue_script(
            "jnk-linkweave-elementor",
            JNK_LINKWEAVE_URL . "editor/build-elementor/index.js",
            $asset["dependencies"],
            $asset["version"],
            true
        );
        wp_set_script_translations( "jnk-linkweave-elementor", "jnk-linkweave", JNK_LINKWEAVE_PATH . "languages" );
        wp_localize_script( "jnk-linkweave-elementor", "JnkLinkweaveElementor", [
            "root"  => esc_url_raw( rest_url( REST_API::NAMESPACE_V1 . "/" ) ),
            "nonce" => wp_create_nonce( "wp_rest" ),
        ] );
    }
}
