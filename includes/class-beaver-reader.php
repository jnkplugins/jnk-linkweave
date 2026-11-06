<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * Beaver Builder pages, read (free). Works with the free Beaver Builder Lite from
 * wordpress.org and the paid versions.
 *
 * Beaver Builder keeps a page's layout in post meta: _fl_builder_data (the
 * published layout, an array of node objects: rows, column groups, columns and
 * modules, each with a parent and a position), _fl_builder_draft (unsaved work in
 * the editor) and _fl_builder_enabled. Visitors see the layout, not post_content.
 *
 * On Publish, Beaver Builder Lite 2.11 also writes the modules' HTML into
 * post_content (FLBuilderModel::save_layout(), FLBuilder::render_editor_content()),
 * leaving out modules it does not export (icon, menu, sidebar, widget). That copy
 * is missing or stale after an import, a layout copied in by a tool, or a page
 * whose layout data was changed without the editor. So, like Elementor_Reader:
 *
 *   - the page's words and links come from the PUBLISHED layout (filter
 *     jnk_linkweave_post_body), never the draft. The result replaces post_content,
 *     so nothing is counted twice;
 *   - Beaver Builder's own save queues the page to be read again.
 *
 * Read only: nothing here writes Beaver Builder data. Registered by the free core,
 * only while Beaver Builder is active.
 */
class Beaver_Reader {

    public const DATA_KEY    = "_fl_builder_data";
    public const ENABLED_KEY = "_fl_builder_enabled";

    /** Settings that hold words visitors read (module settings and repeater items). */
    public const TEXT_KEYS = [
        "text", "heading", "title", "html", "content", "caption", "label",
        "description", "subtitle", "sub_heading", "before_number_text", "after_number_text",
        "number_text", "button_group_label",
    ];

    /** Link setting => the settings that can label it, first one set wins. */
    public const LINKS = [
        "link"     => [ "cta_text", "heading", "text", "title", "label" ],
        "btn_link" => [ "btn_text", "title" ],
        "link_url" => [ "caption", "url_title" ],
    ];

    public static function active(): bool {
        return defined( "FL_BUILDER_VERSION" ) || class_exists( "FLBuilder", false );
    }

    public static function register(): void {
        if ( ! self::active() ) {
            return;
        }
        add_filter( "jnk_linkweave_post_body", [ __CLASS__, "post_body" ], 10, 2 );
        // Beaver Builder's own Publish writes the layout: read the page again.
        add_action( "fl_builder_after_save_layout", static function ( $post_id ) {
            Index_Builder::schedule_reindex( (int) $post_id );
        } );
    }

    /** Beaver Builder is running and this post is built with it. */
    public static function is_built_with( int $post_id ): bool {
        if ( $post_id <= 0 || ! self::active() ) {
            return false;
        }
        return (bool) get_post_meta( $post_id, self::ENABLED_KEY, true ) && null !== self::data( $post_id );
    }

    /**
     * The published layout as arrays (node id => node), or null when there is none.
     *
     * get_post_meta() unserializes what WordPress itself stored (maybe_unserialize);
     * nothing else is ever unserialized here. Nodes are stdClass objects, or __PHP_Incomplete_Class when
     * a layout names a class that is not loaded: both are read as arrays.
     *
     * @return array<string,array>|null
     */
    public static function data( int $post_id ): ?array {
        $nodes = self::to_array( get_post_meta( $post_id, self::DATA_KEY, true ) );
        if ( ! is_array( $nodes ) || [] === $nodes ) {
            return null;
        }
        $out = [];
        foreach ( $nodes as $id => $node ) {
            $node = self::to_array( $node );
            if ( is_array( $node ) ) {
                $out[ (string) $id ] = $node;
            }
        }
        return [] === $out ? null : $out;
    }

    /** Objects (any class, loaded or not) and arrays as arrays, all the way down. */
    private static function to_array( $value, int $depth = 0 ) {
        if ( $depth > 40 ) {
            return null;
        }
        if ( is_object( $value ) ) {
            $value = (array) $value;
            unset( $value["__PHP_Incomplete_Class_Name"] );
        }
        if ( ! is_array( $value ) ) {
            return $value;
        }
        foreach ( $value as $key => $item ) {
            if ( is_object( $item ) || is_array( $item ) ) {
                $value[ $key ] = self::to_array( $item, $depth + 1 );
            }
        }
        return $value;
    }

    /* -------------------- reading -------------------- */

    /** Filter: the page's HTML from its published Beaver Builder layout (post_content when it has none). */
    public static function post_body( $content, $post ) {
        if ( ! $post instanceof \WP_Post || ! self::is_built_with( (int) $post->ID ) ) {
            return $content;
        }
        $html = trim( self::html( (array) self::data( (int) $post->ID ) ) );
        return "" === $html ? $content : $html;
    }

    /** The words and links of a layout as simple HTML, in page order. */
    public static function html( array $nodes ): string {
        $children = [];
        foreach ( $nodes as $id => $node ) {
            $parent                = isset( $node["parent"] ) && is_scalar( $node["parent"] ) && "" !== (string) $node["parent"] ? (string) $node["parent"] : "";
            $children[ $parent ][] = (string) $id;
        }
        foreach ( $children as &$ids ) {
            usort( $ids, static function ( $a, $b ) use ( $nodes ) {
                return (int) ( $nodes[ $a ]["position"] ?? 0 ) <=> (int) ( $nodes[ $b ]["position"] ?? 0 );
            } );
        }
        unset( $ids );
        return self::walk( "", $nodes, $children, 0 );
    }

    private static function walk( string $parent, array $nodes, array $children, int $depth ): string {
        if ( $depth > 30 || empty( $children[ $parent ] ) ) {
            return "";
        }
        $out = "";
        foreach ( $children[ $parent ] as $id ) {
            $node     = $nodes[ $id ];
            $settings = is_array( $node["settings"] ?? null ) ? $node["settings"] : [];
            // Visibility "Never": no visitor sees it.
            if ( "0" === (string) ( $settings["visibility_display"] ?? "" ) ) {
                continue;
            }
            if ( "module" === ( $node["type"] ?? "" ) ) {
                $out .= self::module_html( $settings );
            }
            $out .= self::walk( $id, $nodes, $children, $depth + 1 );
        }
        return $out;
    }

    private static function module_html( array $settings, int $depth = 0 ): string {
        if ( $depth > 5 ) {
            return "";
        }
        $out  = "";
        $used = [];
        foreach ( self::LINKS as $link_key => $label_keys ) {
            $url = self::link_of( $settings, $link_key );
            if ( null === $url ) {
                continue;
            }
            foreach ( $label_keys as $label_key ) {
                $label = isset( $settings[ $label_key ] ) && is_string( $settings[ $label_key ] ) ? trim( wp_strip_all_tags( $settings[ $label_key ] ) ) : "";
                if ( "" !== $label ) {
                    $out   .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . "</a></p>\n";
                    $used[] = $label_key;
                    break;
                }
            }
        }
        foreach ( $settings as $key => $value ) {
            if ( in_array( $key, $used, true ) ) {
                continue;
            }
            if ( is_string( $value ) && in_array( $key, self::TEXT_KEYS, true ) && "" !== trim( $value ) ) {
                $out .= "<p>" . $value . "</p>\n";
            } elseif ( is_array( $value ) && array_is_list( $value ) ) {
                // Repeater items (a button group's buttons, accordion and tab items).
                foreach ( $value as $item ) {
                    if ( is_array( $item ) ) {
                        $out .= self::module_html( $item, $depth + 1 );
                    }
                }
            }
        }
        return $out;
    }

    /** The address a module (or repeater item) links to through $key, if any. */
    public static function link_of( array $settings, string $key = "link" ): ?string {
        $url = $settings[ $key ] ?? null;
        if ( ! is_string( $url ) || "" === trim( $url ) ) {
            return null;
        }
        // A photo links to its own address only when its link type says so.
        if ( "link_url" === $key && "url" !== ( $settings["link_type"] ?? "" ) ) {
            return null;
        }
        return trim( $url );
    }
}
