<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counts from the plugin's own tables.

/**
 * Abilities for AI assistants, through the WordPress Abilities API (WordPress
 * 6.9 and newer). An assistant connected through the MCP Adapter plugin, or any
 * other tool that reads the Abilities API, can ask "which posts are orphans?",
 * "what should this post link to?" or "which links are broken?".
 *
 * The free plugin registers the read-only reports. Other code (the Pro module)
 * adds abilities that change links through the jnk_linkweave_register_abilities
 * action and add().
 *
 * Every ability is registered, so PHP code can call it, but it is only shown to
 * the REST API and to MCP when the site owner switched on its level on the
 * AI assistants screen: "read", "edit" or "delete". Permission checks run on
 * every call whatever the switches say. On WordPress without the Abilities API
 * none of this runs.
 */
class Abilities {

    public const CATEGORY = "jnk-linkweave";
    public const LOG      = "jnk_linkweave_ai_log";
    public const LOG_MAX  = 200;

    /** Setting key for each level. */
    public const LEVELS = [
        "read"   => "ai_read",
        "edit"   => "ai_edit",
        "delete" => "ai_delete",
    ];

    /** The pre-1.22 single switch ("Let AI assistants read Linkweave reports"). */
    private const OLD_SETTING = "abilities_public";

    public static function available(): bool {
        return function_exists( "wp_register_ability" ) && function_exists( "wp_register_ability_category" );
    }

    public static function register(): void {
        if ( ! self::available() ) {
            return;
        }
        add_action( "wp_abilities_api_categories_init", [ __CLASS__, "register_category" ] );
        add_action( "wp_abilities_api_init", [ __CLASS__, "register_abilities" ] );
    }

    /** Whether abilities of this level are shown to REST and MCP. */
    public static function exposed( string $level = "read" ): bool {
        $opts = (array) get_option( "jnk_linkweave_settings", [] );
        $key  = self::LEVELS[ $level ] ?? "";
        if ( "" === $key ) {
            return false;
        }
        if ( "read" === $level && ! array_key_exists( $key, $opts ) ) {
            // Sites that ticked the old single switch keep their reports exposed.
            return ! empty( $opts[ self::OLD_SETTING ] );
        }
        return ! empty( $opts[ $key ] );
    }

    public static function register_category(): void {
        wp_register_ability_category( self::CATEGORY, [
            "label"       => __( "JnK Linkweave", "jnk-linkweave" ),
            "description" => __( "Internal-link reports and link suggestions from JnK Linkweave.", "jnk-linkweave" ),
        ] );
    }

    /**
     * Register one ability in our category. $level is "read", "edit" or
     * "delete": it sets the annotations and which switch exposes it. Calls are
     * written to the access log.
     *
     * @param string               $slug  ability name without the "jnk-linkweave/" prefix
     * @param array<string,mixed>  $args  wp_register_ability() arguments (no category/meta needed)
     */
    public static function add( string $slug, string $level, array $args ): void {
        if ( ! isset( self::LEVELS[ $level ] ) ) {
            return;
        }
        $name   = self::CATEGORY . "/" . $slug;
        $public = self::exposed( $level );
        $run    = $args["execute_callback"];

        $args["category"]         = self::CATEGORY;
        $args["execute_callback"] = static function ( $input = null ) use ( $run, $slug ) {
            $result = call_user_func( $run, $input );
            self::log( $slug, $input, $result );
            // "log" is the line for the access log, not part of the answer.
            if ( is_array( $result ) ) {
                unset( $result["log"] );
            }
            return $result;
        };
        $args["meta"] = array_merge( (array) ( $args["meta"] ?? [] ), [
            "annotations"  => [
                "readonly"    => "read" === $level,
                "destructive" => "delete" === $level,
                "idempotent"  => "read" === $level,
            ],
            "public"       => $public,
            "show_in_rest" => $public,
            // MCP Adapter 0.x reads this key; WordPress 7.1 reads "public".
            "mcp"          => [ "public" => $public ],
            "jnk_level"    => $level,
        ] );
        wp_register_ability( $name, $args );
    }

    public static function register_abilities(): void {
        $post_schema = [
            "type"       => "object",
            "properties" => [
                "post_id" => [ "type" => "integer" ],
                "title"   => [ "type" => "string" ],
                "url"     => [ "type" => "string" ],
            ],
        ];
        $no_input = [ "type" => "object", "properties" => [], "additionalProperties" => false, "default" => [] ];

        self::add( "list-orphans", "read", [
            "label"               => __( "List orphan posts", "jnk-linkweave" ),
            "description"         => __( "Published posts that no other post on the site links to. Use it to find pages that need an internal link.", "jnk-linkweave" ),
            "input_schema"        => [
                "type"                 => "object",
                "properties"           => [
                    "limit" => [ "type" => "integer", "minimum" => 1, "maximum" => 500, "default" => 100, "description" => "How many posts to return." ],
                ],
                "additionalProperties" => false,
                "default"              => [],
            ],
            "output_schema"       => [
                "type"       => "object",
                "properties" => [
                    "total"   => [ "type" => "integer" ],
                    "orphans" => [ "type" => "array", "items" => $post_schema ],
                ],
            ],
            "execute_callback"    => [ __CLASS__, "list_orphans" ],
            "permission_callback" => [ __CLASS__, "can_read_reports" ],
        ] );

        self::add( "get-link-suggestions", "read", [
            "label"               => __( "Get link suggestions for a post", "jnk-linkweave" ),
            "description"         => __( "Posts on this site that a given post should link to, best match first, with the words they share.", "jnk-linkweave" ),
            "input_schema"        => [
                "type"                 => "object",
                "properties"           => [
                    "post_id" => [ "type" => "integer", "minimum" => 1, "description" => "ID of the post to find links for." ],
                    "limit"   => [ "type" => "integer", "minimum" => 1, "maximum" => 50, "default" => 10 ],
                ],
                "required"             => [ "post_id" ],
                "additionalProperties" => false,
            ],
            "output_schema"       => [
                "type"       => "object",
                "properties" => [
                    "post_id"     => [ "type" => "integer" ],
                    "suggestions" => [
                        "type"  => "array",
                        "items" => [
                            "type"       => "object",
                            "properties" => [
                                "post_id"       => [ "type" => "integer" ],
                                "title"         => [ "type" => "string" ],
                                "url"           => [ "type" => "string" ],
                                "score"         => [ "type" => "number" ],
                                "matched_terms" => [ "type" => "array", "items" => [ "type" => "string" ] ],
                            ],
                        ],
                    ],
                    "reason"      => [ "type" => [ "string", "null" ] ],
                ],
            ],
            "execute_callback"    => [ __CLASS__, "get_suggestions" ],
            "permission_callback" => [ __CLASS__, "can_read_post" ],
        ] );

        self::add( "get-link-stats", "read", [
            "label"               => __( "Get internal link stats", "jnk-linkweave" ),
            "description"         => __( "Site-wide internal linking numbers: posts indexed, internal links, orphans, dead ends, broken links and active auto-link rules.", "jnk-linkweave" ),
            "input_schema"        => $no_input,
            "output_schema"       => [
                "type"       => "object",
                "properties" => [
                    "indexed_posts"  => [ "type" => "integer" ],
                    "published"      => [ "type" => "integer" ],
                    "internal_links" => [ "type" => "integer" ],
                    "orphans"        => [ "type" => "integer" ],
                    "dead_ends"      => [ "type" => "integer" ],
                    "broken_links"   => [ "type" => "integer" ],
                    "active_rules"   => [ "type" => "integer" ],
                ],
            ],
            "execute_callback"    => [ __CLASS__, "get_stats" ],
            "permission_callback" => [ __CLASS__, "can_read_reports" ],
        ] );

        self::add( "get-post-links", "read", [
            "label"               => __( "Get the links of one post", "jnk-linkweave" ),
            "description"         => __( "The internal links a post makes (outbound) and the posts that link to it (inbound), with their link text.", "jnk-linkweave" ),
            "input_schema"        => [
                "type"                 => "object",
                "properties"           => [
                    "post_id" => [ "type" => "integer", "minimum" => 1 ],
                ],
                "required"             => [ "post_id" ],
                "additionalProperties" => false,
            ],
            "output_schema"       => [
                "type"       => "object",
                "properties" => [
                    "post_id"  => [ "type" => "integer" ],
                    "outbound" => [ "type" => "array", "items" => [ "type" => "object" ] ],
                    "inbound"  => [ "type" => "array", "items" => [ "type" => "object" ] ],
                ],
            ],
            "execute_callback"    => [ __CLASS__, "get_post_links" ],
            "permission_callback" => [ __CLASS__, "can_read_post" ],
        ] );

        self::add( "list-broken-links", "read", [
            "label"               => __( "List broken links", "jnk-linkweave" ),
            "description"         => __( "Links found by the last broken-link check: the post they are in, where they point, their text, and whether the page is missing or did not answer.", "jnk-linkweave" ),
            "input_schema"        => [
                "type"                 => "object",
                "properties"           => [
                    "limit" => [ "type" => "integer", "minimum" => 1, "maximum" => 500, "default" => 100 ],
                ],
                "additionalProperties" => false,
                "default"              => [],
            ],
            "output_schema"       => [
                "type"       => "object",
                "properties" => [
                    "total"     => [ "type" => "integer" ],
                    "last_scan" => [ "type" => [ "string", "null" ] ],
                    "links"     => [ "type" => "array", "items" => [ "type" => "object" ] ],
                ],
            ],
            "execute_callback"    => [ __CLASS__, "list_broken_links" ],
            "permission_callback" => [ __CLASS__, "can_read_reports" ],
        ] );

        self::add( "list-rules", "read", [
            "label"               => __( "List auto-link rules", "jnk-linkweave" ),
            "description"         => __( "The keyword-to-address rules that add links automatically, with how many posts each one links from.", "jnk-linkweave" ),
            "input_schema"        => [
                "type"                 => "object",
                "properties"           => [
                    "limit" => [ "type" => "integer", "minimum" => 1, "maximum" => 100, "default" => 50 ],
                ],
                "additionalProperties" => false,
                "default"              => [],
            ],
            "output_schema"       => [
                "type"       => "object",
                "properties" => [
                    "total" => [ "type" => "integer" ],
                    "rules" => [ "type" => "array", "items" => [ "type" => "object" ] ],
                ],
            ],
            "execute_callback"    => [ __CLASS__, "list_rules" ],
            "permission_callback" => [ __CLASS__, "can_read_reports" ],
        ] );

        /** Add more abilities with Abilities::add() (the Pro module's link tools). */
        do_action( "jnk_linkweave_register_abilities" );
    }

    /* ---------------------------------------------------------------- permissions */

    /** Site-wide reports: the same capability as the Linkweave screens. */
    public static function can_read_reports( $input = null ): bool {
        return current_user_can( "manage_options" );
    }

    public static function can_read_post( $input = null ): bool {
        $id = (int) ( is_array( $input ) ? ( $input["post_id"] ?? 0 ) : 0 );
        return $id > 0 && current_user_can( "edit_posts" ) && current_user_can( "read_post", $id );
    }

    /* ---------------------------------------------------------------- callbacks */

    private static function int_arg( $input, string $key, int $default, int $min, int $max ): int {
        $value = is_array( $input ) && isset( $input[ $key ] ) ? (int) $input[ $key ] : $default;
        return max( $min, min( $max, $value ) );
    }

    public static function list_orphans( $input = null ): array {
        $limit = self::int_arg( $input, "limit", 100, 1, 500 );
        $rows  = REST_API::orphan_rows();
        $out   = [];
        foreach ( array_slice( $rows, 0, $limit ) as $r ) {
            $out[] = [
                "post_id" => (int) $r->post_id,
                "title"   => html_entity_decode( (string) $r->post_title, ENT_QUOTES, "UTF-8" ),
                "url"     => (string) get_permalink( (int) $r->post_id ),
            ];
        }
        return [ "total" => count( $rows ), "orphans" => $out ];
    }

    public static function get_suggestions( $input = null ) {
        $id    = (int) ( $input["post_id"] ?? 0 );
        $limit = self::int_arg( $input, "limit", 10, 1, 50 );
        if ( ! get_post( $id ) ) {
            return new \WP_Error( "jnk_linkweave_not_found", __( "Post not found.", "jnk-linkweave" ) );
        }
        $res = ( new Suggestion_Engine() )->get_suggestions( $id, [ "limit" => $limit ] );
        $out = [];
        foreach ( (array) ( $res["suggestions"] ?? [] ) as $s ) {
            $out[] = [
                "post_id"       => (int) $s["target_id"],
                "title"         => html_entity_decode( (string) $s["title"], ENT_QUOTES, "UTF-8" ),
                "url"           => (string) $s["url"],
                "score"         => (float) $s["score"],
                "matched_terms" => array_values( array_map( "strval", (array) ( $s["matched_terms"] ?? [] ) ) ),
            ];
        }
        return [
            "post_id"     => $id,
            "suggestions" => $out,
            "reason"      => $out ? null : (string) ( $res["reason"]["code"] ?? "no_matches" ),
        ];
    }

    public static function get_stats( $input = null ): array {
        global $wpdb;
        $links = $wpdb->prefix . "jnk_linkweave_links";
        $types = REST_API::report_post_types();
        $in    = implode( ",", array_fill( 0, count( $types ), "%s" ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders.
        $published = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)",
            $types
        ) );
        $dead_ends = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_status = 'publish' AND p.post_type IN ($in)
               AND NOT EXISTS (SELECT 1 FROM %i l WHERE l.source_post_id = p.ID AND l.target_post_id > 0)",
            array_merge( $types, [ $links ] )
        ) );
        // phpcs:enable
        return [
            "indexed_posts"  => Index_Builder::indexed_count(),
            "published"      => $published,
            "internal_links" => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE target_post_id > 0", $links ) ),
            "orphans"        => count( REST_API::orphan_rows() ),
            "dead_ends"      => $dead_ends,
            "broken_links"   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_broken > 0", $links ) ),
            "active_rules"   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_active = 1", Rules_Engine::table() ) ),
        ];
    }

    public static function get_post_links( $input = null ) {
        global $wpdb;
        $id = (int) ( $input["post_id"] ?? 0 );
        if ( ! get_post( $id ) ) {
            return new \WP_Error( "jnk_linkweave_not_found", __( "Post not found.", "jnk-linkweave" ) );
        }
        $links = $wpdb->prefix . "jnk_linkweave_links";
        $out   = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, target_post_id, target_url, anchor_text, is_broken FROM %i WHERE source_post_id = %d ORDER BY id LIMIT 500",
            $links,
            $id
        ) );
        $in = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_post_id, anchor_text FROM %i WHERE target_post_id = %d ORDER BY id LIMIT 500",
            $links,
            $id
        ) );
        $outbound = [];
        foreach ( (array) $out as $r ) {
            $target     = (int) $r->target_post_id;
            $outbound[] = [
                "link_id"        => (int) $r->id,
                "target_post_id" => $target,
                "target_title"   => $target > 0 ? REST_API::plain_title( $target ) : "",
                "url"            => $target > 0 ? (string) get_permalink( $target ) : (string) $r->target_url,
                "anchor"         => (string) $r->anchor_text,
                "broken"         => (int) $r->is_broken > 0,
            ];
        }
        $inbound = [];
        foreach ( (array) $in as $r ) {
            $source = (int) $r->source_post_id;
            if ( ! current_user_can( "read_post", $source ) ) {
                continue;
            }
            $inbound[] = [
                "link_id"        => (int) $r->id,
                "source_post_id" => $source,
                "source_title"   => REST_API::plain_title( $source ),
                "anchor"         => (string) $r->anchor_text,
            ];
        }
        return [ "post_id" => $id, "outbound" => $outbound, "inbound" => $inbound ];
    }

    public static function list_broken_links( $input = null ): array {
        global $wpdb;
        $limit = self::int_arg( $input, "limit", 100, 1, 500 );
        $links = $wpdb->prefix . "jnk_linkweave_links";
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_broken > 0", $links ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_post_id, target_post_id, target_url, anchor_text, is_broken, last_checked
             FROM %i WHERE is_broken > 0 ORDER BY is_broken ASC, last_checked DESC LIMIT %d",
            $links,
            $limit
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                "link_id"        => (int) $r->id,
                "source_post_id" => (int) $r->source_post_id,
                "source_title"   => REST_API::plain_title( (int) $r->source_post_id ),
                "url"            => (string) $r->target_url,
                "anchor"         => (string) $r->anchor_text,
                // 1 = the page is missing (404/410); 2 = it did not answer (timeout, 5xx).
                "status"         => 1 === (int) $r->is_broken ? "missing" : "no_answer",
                "last_checked"   => $r->last_checked ? (string) $r->last_checked : null,
            ];
        }
        $scan = Cron::scan_progress();
        return [
            "total"     => $total,
            "last_scan" => $scan["last_scan"] ? (string) $scan["last_scan"] : null,
            "links"     => $out,
        ];
    }

    public static function list_rules( $input = null ): array {
        $limit = self::int_arg( $input, "limit", 50, 1, 100 );
        $list  = ( new Rules_Engine() )->list_rules( 1, $limit );
        $out   = [];
        foreach ( (array) $list["rules"] as $rule ) {
            $out[] = [
                "id"              => (int) $rule->id,
                "keyword"         => (string) $rule->keyword,
                "target_url"      => (string) $rule->target_url,
                "active"          => (bool) $rule->is_active,
                "posts_linked"    => (int) $rule->applied_count,
                "max_per_post"    => (int) $rule->max_per_post,
                "nofollow"        => (bool) $rule->nofollow,
                "open_in_new_tab" => (bool) $rule->open_in_new_tab,
            ];
        }
        return [ "total" => (int) $list["total"], "rules" => $out ];
    }

    /* ---------------------------------------------------------------- access log */

    /**
     * Remember one call: who, which ability, a short form of what was asked, and
     * whether it worked. Kept to the newest LOG_MAX calls.
     */
    public static function log( string $slug, $input, $result ): void {
        $ask = is_array( $input ) && $input ? (string) wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : "";
        $ok  = ! is_wp_error( $result );
        $msg = $ok ? "" : $result->get_error_message();
        if ( $ok && is_array( $result ) && isset( $result["log"] ) ) {
            $msg = (string) $result["log"];
        }
        $entries   = (array) get_option( self::LOG, [] );
        $entries[] = [
            "t" => time(),
            "u" => get_current_user_id(),
            "a" => $slug,
            "i" => mb_substr( $ask, 0, 200 ),
            "o" => $ok,
            "m" => mb_substr( $msg, 0, 200 ),
        ];
        if ( count( $entries ) > self::LOG_MAX ) {
            $entries = array_slice( $entries, -self::LOG_MAX );
        }
        update_option( self::LOG, $entries, false );
    }

    /** Newest first. */
    public static function log_entries( int $limit = 50 ): array {
        return array_slice( array_reverse( (array) get_option( self::LOG, [] ) ), 0, $limit );
    }

    /** Our registered abilities, for the screen that lists them. */
    public static function ours(): array {
        if ( ! self::available() || ! function_exists( "wp_get_abilities" ) ) {
            return [];
        }
        $out = [];
        foreach ( wp_get_abilities() as $ability ) {
            if ( 0 === strpos( $ability->get_name(), self::CATEGORY . "/" ) ) {
                $out[] = $ability;
            }
        }
        return $out;
    }
}
