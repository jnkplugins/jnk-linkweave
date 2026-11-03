<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * Post types that hold content a visitor reads on its own page, for the post-type
 * pickers. "public" alone also lists media and page-builder storage: Elementor's
 * Template (elementor_library) and Floating Element (e-floating-buttons) are
 * public, queryable and viewable, yet nobody reads them as a page, and a "Read
 * more" list added to them showed up inside every page that used the template.
 */
final class Post_Types {

    /**
     * Builder and theme storage types: templates, sections, layouts, hooks.
     * Filter: jnk_linkweave_non_content_post_types.
     */
    private const NON_CONTENT = [
        "attachment",
        "elementor_library",   // Elementor: Templates
        "e-floating-buttons",  // Elementor: Floating Elements
        "elementor_snippet",   // Elementor Pro: custom code
        "elementor_font",
        "elementor_icons",
        "et_pb_layout",        // Divi library
        "bricks_template",
        "ct_template",         // Oxygen
        "fl-builder-template", // Beaver Builder
        "fl-theme-layout",
        "brizy_template",
        "astra-advanced-hook",
        "kadence_element",
        "oceanwp_library",
        "elementskit_template",
        "jet-theme-core",
        "wp_block",
        "wp_template",
        "wp_template_part",
        "wp_navigation",
    ];

    /**
     * Public post types with a page of their own: viewable (pages count, though
     * publicly_queryable is false for them), not media, not builder storage, and
     * not a non-built-in type hidden from search (template libraries mark
     * themselves that way).
     *
     * @param string $output "names" or "objects"
     * @return array<string,\WP_Post_Type>|array<string,string> keyed by name
     */
    public static function content( string $output = "names" ): array {
        $skip = (array) apply_filters( "jnk_linkweave_non_content_post_types", self::NON_CONTENT );
        $out  = [];
        foreach ( get_post_types( [ "public" => true ], "objects" ) as $name => $type ) {
            if ( in_array( $name, $skip, true ) || ! is_post_type_viewable( $type ) ) {
                continue;
            }
            if ( empty( $type->_builtin ) && ! empty( $type->exclude_from_search ) ) {
                continue;
            }
            $out[ $name ] = "objects" === $output ? $type : $name;
        }
        /** Filter: jnk_linkweave_content_post_types. The post types the pickers offer. */
        return (array) apply_filters( "jnk_linkweave_content_post_types", $out, $output );
    }
}
