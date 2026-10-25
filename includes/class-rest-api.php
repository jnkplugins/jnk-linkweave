<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class REST_API {

    public const NAMESPACE_V1 = "jnk-linkweave/v1";
    private const RATE_LIMIT  = 60; // requests per minute per user

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE_V1, "/suggestions/(?P<post_id>\\d+)", [
            "methods"             => "GET",
            "callback"            => [ $this, "get_suggestions" ],
            "permission_callback" => [ $this, "perm_edit_posts" ],
            "args"                => [
                // No static defaults: when the client omits these, the engine
                // falls back to the site's configured suggestions_per_post /
                // min_relevance_score settings.
                "limit"     => [ "type" => "integer" ],
                "min_score" => [ "type" => "number" ],
                "mode"      => [ "type" => "string", "default" => "" ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, "/links/insert", [
            "methods"             => "POST",
            "callback"            => [ $this, "insert_link" ],
            "permission_callback" => [ $this, "perm_edit_posts" ],
        ] );

        register_rest_route( self::NAMESPACE_V1, "/index/rebuild", [
            "methods"             => "POST",
            "callback"            => [ $this, "rebuild_index" ],
            "permission_callback" => [ $this, "perm_manage_options" ],
        ] );
        register_rest_route( self::NAMESPACE_V1, "/index/status", [
            "methods"             => "GET",
            "callback"            => [ $this, "index_status" ],
            // Index status is site-wide state, not per-post — restrict to admins.
            "permission_callback" => [ $this, "perm_manage_options" ],
        ] );

        register_rest_route( self::NAMESPACE_V1, "/rules", [
            [
                "methods"             => "GET",
                "callback"            => [ $this, "list_rules" ],
                // Auto-link rules are site-wide config (applied at the_content for
                // every post). Reading them is admin-level, same as writing them.
                "permission_callback" => [ $this, "perm_manage_options" ],
            ],
            [
                "methods"             => "POST",
                "callback"            => [ $this, "create_rule" ],
                // Auto-link rules apply site-wide at render time (the_content), so a
                // rule created by anyone injects links into every post — including
                // ones the author cannot edit. Restrict writes to administrators.
                "permission_callback" => [ $this, "perm_manage_options" ],
            ],
        ] );
        register_rest_route( self::NAMESPACE_V1, "/rules/(?P<id>\\d+)", [
            [
                "methods"             => "GET",
                "callback"            => [ $this, "get_rule" ],
                // Single rule read is site-wide config — admin-level (see list_rules).
                "permission_callback" => [ $this, "perm_manage_options" ],
            ],
            [
                "methods"             => "PUT,PATCH",
                "callback"            => [ $this, "update_rule" ],
                "permission_callback" => [ $this, "perm_manage_options" ],
            ],
            [
                "methods"             => "DELETE",
                "callback"            => [ $this, "delete_rule" ],
                "permission_callback" => [ $this, "perm_manage_options" ],
            ],
        ] );

        // Read-only analysis reports (orphan pages, inbound counts, and the
        // broken-link list flagged by the free daily cron scan). These run
        // unbounded site-wide queries across ALL posts, so they are admin-level
        // even though read-only — gate on manage_options, not edit_posts.
        register_rest_route( self::NAMESPACE_V1, "/reports/orphans", [
            "methods"             => "GET",
            "callback"            => [ $this, "report_orphans" ],
            "permission_callback" => [ $this, "perm_manage_options" ],
        ] );
        register_rest_route( self::NAMESPACE_V1, "/reports/inbound-counts", [
            "methods"             => "GET",
            "callback"            => [ $this, "report_inbound_counts" ],
            "permission_callback" => [ $this, "perm_manage_options" ],
        ] );
        register_rest_route( self::NAMESPACE_V1, "/reports/broken-links", [
            "methods"             => "GET",
            "callback"            => [ $this, "report_broken_links" ],
            "permission_callback" => [ $this, "perm_manage_options" ],
        ] );
        register_rest_route( self::NAMESPACE_V1, "/reports/broken-links/scan", [
            "methods"             => "POST",
            "callback"            => [ $this, "scan_broken_links_now" ],
            "permission_callback" => [ $this, "perm_manage_options" ],
        ] );
        // One step of a running scan, driven by the open report page so the check
        // finishes even where background jobs are slow or WP-Cron is off.
        register_rest_route( self::NAMESPACE_V1, "/reports/broken-links/scan/step", [
            "methods"             => "POST",
            "callback"            => [ $this, "scan_broken_links_step" ],
            "permission_callback" => [ $this, "perm_manage_options" ],
        ] );
        // Inbound-link opportunities for a post: which existing posts should link
        // to it. Read-only hint (free); surfaced in the editor sidebar.
        register_rest_route( self::NAMESPACE_V1, "/reports/inbound-opportunities/(?P<post_id>\\d+)", [
            "methods"             => "GET",
            "callback"            => [ $this, "report_inbound_opportunities" ],
            "permission_callback" => [ $this, "perm_edit_posts" ],
        ] );

        register_rest_route( self::NAMESPACE_V1, "/settings", [
            [
                "methods"             => "GET",
                "callback"            => [ $this, "get_settings" ],
                "permission_callback" => [ $this, "perm_manage_options" ],
            ],
            [
                "methods"             => "POST",
                "callback"            => [ $this, "update_settings" ],
                "permission_callback" => [ $this, "perm_manage_options" ],
            ],
        ] );

        // Premium REST controller (URL changer write routes, orphans CSV export)
        // self-registers on this action from pro/. In the free build nothing
        // listens, so those routes simply do not exist (native 404, no 402).
        do_action( "jnk_linkweave_register_rest_routes", self::NAMESPACE_V1 );
    }

    /* -------------------- permission callbacks -------------------- */
    /*
     * These are public static so the premium REST controller in pro/ can reuse the
     * same nonce / rate-limit / capability guards instead of duplicating them.
     */

    public static function perm_edit_posts( \WP_REST_Request $request ) {
        if ( ! current_user_can( "edit_posts" ) ) {
            return new \WP_Error( "jnk_linkweave_forbidden", "Insufficient permissions.", [ "status" => 403 ] );
        }
        return self::check_nonce_and_rate( $request );
    }

    public static function perm_manage_options( \WP_REST_Request $request ) {
        if ( ! current_user_can( "manage_options" ) ) {
            return new \WP_Error( "jnk_linkweave_forbidden", "Insufficient permissions.", [ "status" => 403 ] );
        }
        return self::check_nonce_and_rate( $request );
    }

    public static function check_nonce_and_rate( \WP_REST_Request $request ) {
        // Prefer the X-WP-Nonce header (AJAX), but fall back to the _wpnonce query
        // param so GET download links (e.g. the orphans CSV export) can authenticate
        // without setting a custom header.
        $nonce = $request->get_header( "X-WP-Nonce" );
        if ( ! $nonce ) {
            $nonce = (string) $request->get_param( "_wpnonce" );
        }
        if ( ! $nonce || ! wp_verify_nonce( $nonce, "wp_rest" ) ) {
            return new \WP_Error( "jnk_linkweave_invalid_nonce", "Invalid nonce.", [ "status" => 403 ] );
        }
        // The plugin's own screens poll these two cheap, read-only endpoints every few
        // seconds while a rebuild or scan runs. Counting them locked admins out with
        // "Rate limit exceeded." part-way through a normal rebuild.
        if ( "GET" === $request->get_method()
            && preg_match( "~/jnk-linkweave/v1/(index/status|reports/broken-links)$~", (string) $request->get_route() )
        ) {
            return true;
        }
        $user_id = get_current_user_id();
        $key     = "jnk_linkweave_rl_" . $user_id;
        $count   = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT ) {
            return new \WP_Error( "jnk_linkweave_rate_limit", "Rate limit exceeded.", [ "status" => 429 ] );
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }

    /* -------------------- handlers -------------------- */

    public function get_suggestions( \WP_REST_Request $r ): \WP_REST_Response {
        $post_id = (int) $r["post_id"];
        if ( ! current_user_can( "read_post", $post_id ) ) {
            return new \WP_REST_Response( [ "error" => "Forbidden." ], 403 );
        }
        // Only forward params the client actually sent; omitted ones fall through
        // to the engine's settings-driven defaults (suggestions_per_post /
        // min_relevance_score) rather than being pinned here.
        $args  = [];
        $limit = $r->get_param( "limit" );
        if ( null !== $limit ) {
            $args["limit"] = min( 50, max( 1, (int) $limit ) );
        }
        $min = $r->get_param( "min_score" );
        if ( null !== $min ) {
            $args["min_score"] = max( 0.0, min( 1.0, (float) $min ) );
        }
        $mode = (string) $r->get_param( "mode" );
        if ( "" !== $mode ) {
            // LLM re-ranking is premium; the Suggestion_Engine routes the "llm"
            // mode through the jnk_linkweave_rerank filter, which only the Pro module
            // attaches. In the free build that filter is a no-op, so an "llm"
            // request transparently returns the TF-IDF order — no 402, no upsell.
            $args["mode"] = $mode;
        }
        $engine = new Suggestion_Engine();
        return new \WP_REST_Response( $engine->get_suggestions( $post_id, $args ) );
    }

    public function insert_link( \WP_REST_Request $r ): \WP_REST_Response {
        $source = (int) $r->get_param( "source_post_id" );
        $target = (int) $r->get_param( "target_post_id" );
        $anchor = sanitize_text_field( (string) $r->get_param( "anchor_text" ) );
        if ( ! $source || ! $target || "" === $anchor ) {
            return new \WP_REST_Response( [ "error" => "Missing fields." ], 400 );
        }
        if ( ! get_post( $source ) || ! get_post( $target ) ) {
            return new \WP_REST_Response( [ "error" => "Post not found." ], 404 );
        }
        if ( ! current_user_can( "edit_post", $source ) ) {
            return new \WP_REST_Response( [ "error" => "Forbidden." ], 403 );
        }
        $id = ( new Suggestion_Engine() )->record_insertion( $source, $target, $anchor );
        return new \WP_REST_Response( [ "link_id" => $id ], 201 );
    }

    /**
     * Start a full rebuild.
     *
     * Action Scheduler only starts its queue from an admin page load, never from a
     * REST call, so on a site with WP-Cron off the job could sit queued for minutes
     * while the dashboard said "0 of 83". So:
     *   - small sites (inline_max posts or fewer) are read inside this request;
     *   - "now" (the dashboard's Run now button) does the same on any site;
     *   - otherwise the job is queued and the dashboard offers Run now if nothing
     *     has picked it up after a few seconds.
     */
    public function rebuild_index( \WP_REST_Request $r ): \WP_REST_Response {
        $now    = rest_sanitize_boolean( $r->get_param( "now" ) );
        $status = Index_Builder::status();
        if ( "running" === $status["state"] && ! empty( $status["is_running"] ) ) {
            return new \WP_REST_Response( [ "message" => "Already running.", "status" => $status ], 202 );
        }
        $total = count( Index_Builder::corpus_ids() );
        if ( $now || $total <= Index_Builder::inline_max() ) {
            Index_Builder::rebuild_now();
            return new \WP_REST_Response( [ "ran_inline" => true, "status" => Index_Builder::status() ], 200 );
        }
        if ( "queued" === $status["state"] && ! empty( $status["is_running"] ) && Index_Builder::has_pending_action() ) {
            return new \WP_REST_Response( [ "message" => "Rebuild already queued.", "status" => $status ], 202 );
        }
        Index_Builder::schedule_rebuild();
        return new \WP_REST_Response( [ "job_id" => wp_generate_uuid4(), "status" => Index_Builder::status() ], 202 );
    }

    public function index_status( \WP_REST_Request $r ): \WP_REST_Response {
        return new \WP_REST_Response( Index_Builder::status() );
    }

    public function list_rules( \WP_REST_Request $r ): \WP_REST_Response {
        $page     = max( 1, (int) $r->get_param( "page" ) );
        $per_page = min( 100, max( 1, (int) ( $r->get_param( "per_page" ) ?: 20 ) ) );
        return new \WP_REST_Response( ( new Rules_Engine() )->list_rules( $page, $per_page ) );
    }

    public function create_rule( \WP_REST_Request $r ): \WP_REST_Response {
        // Free tier creates unlimited auto-linking rules — no cap.
        $id = ( new Rules_Engine() )->create( (array) $r->get_json_params() );
        return new \WP_REST_Response( [ "id" => $id ], 201 );
    }

    public function get_rule( \WP_REST_Request $r ): \WP_REST_Response {
        $rule = ( new Rules_Engine() )->find( (int) $r["id"] );
        return $rule
            ? new \WP_REST_Response( $rule )
            : new \WP_REST_Response( [ "error" => "Not found." ], 404 );
    }

    public function update_rule( \WP_REST_Request $r ): \WP_REST_Response {
        $ok = ( new Rules_Engine() )->update( (int) $r["id"], (array) $r->get_json_params() );
        return new \WP_REST_Response( [ "updated" => $ok ] );
    }

    public function delete_rule( \WP_REST_Request $r ): \WP_REST_Response {
        $ok = ( new Rules_Engine() )->delete( (int) $r["id"] );
        return new \WP_REST_Response( [ "deleted" => $ok ] );
    }

    /** Post types the reports cover: the ones the index is configured for. */
    public static function report_post_types(): array {
        $settings = get_option( "jnk_linkweave_settings", [] );
        $types    = (array) apply_filters( "jnk_linkweave_suggestion_post_types", $settings["post_types"] ?? [ "post", "page" ] );
        $types    = array_values( array_filter( array_map( "sanitize_key", $types ) ) );
        return $types ?: [ "post", "page" ];
    }

    /**
     * Published posts of the indexed post types with zero saved inbound links.
     *
     * This used to start from the index, so a published post too short to index
     * (below "Min indexed words") was silently absent even though nothing linked
     * to it. Every published post is covered now, flagged with whether it is
     * indexed. Shared with the premium CSV export so both agree.
     *
     * @return object[] post_id, post_title, post_type, indexed
     */
    public static function orphan_rows(): array {
        global $wpdb;
        $types = self::report_post_types();
        $in    = implode( ",", array_fill( 0, count( $types ), "%s" ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders.
            "SELECT p.ID AS post_id, p.post_title, p.post_type, MAX(i.post_id IS NOT NULL) AS indexed
             FROM {$wpdb->posts} p
             LEFT JOIN %i i ON i.post_id = p.ID
             LEFT JOIN %i l ON l.target_post_id = p.ID
             WHERE l.id IS NULL AND p.post_status = 'publish' AND p.post_type IN ($in)
             GROUP BY p.ID, p.post_title, p.post_type
             ORDER BY p.post_title ASC",
            array_merge( [ Index_Builder::table(), $wpdb->prefix . "jnk_linkweave_links" ], $types )
        ) );
        foreach ( (array) $rows as $row ) {
            $row->post_id = (int) $row->post_id;
            $row->indexed = (bool) (int) $row->indexed;
        }
        return $rows ?: [];
    }

    /** Edit + view links, so a report row can be acted on without hunting for the post. */
    private static function with_post_links( array $rows ): array {
        foreach ( $rows as $row ) {
            $row->edit_url = (string) get_edit_post_link( (int) $row->post_id, "raw" );
            $row->view_url = (string) get_permalink( (int) $row->post_id );
        }
        return $rows;
    }

    public function report_orphans( \WP_REST_Request $r ) {
        return new \WP_REST_Response( [ "orphans" => self::with_post_links( self::orphan_rows() ) ] );
    }

    public function report_inbound_counts( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $types = self::report_post_types();
        $in    = implode( ",", array_fill( 0, count( $types ), "%s" ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders.
            "SELECT p.ID AS post_id, p.post_title, COUNT(l.id) AS inbound
             FROM {$wpdb->posts} p
             LEFT JOIN %i l ON l.target_post_id = p.ID
             WHERE p.post_status = 'publish' AND p.post_type IN ($in)
             GROUP BY p.ID, p.post_title
             ORDER BY inbound DESC, p.post_title ASC",
            array_merge( [ $wpdb->prefix . "jnk_linkweave_links" ], $types )
        ) );
        return new \WP_REST_Response( [ "counts" => self::with_post_links( $rows ?: [] ) ] );
    }

    public function scan_broken_links_now( \WP_REST_Request $r ): \WP_REST_Response {
        Cron::queue_scan_now();
        return new \WP_REST_Response( [ "queued" => true, "scan" => Cron::scan_progress() ], 202 );
    }

    public function scan_broken_links_step( \WP_REST_Request $r ): \WP_REST_Response {
        return new \WP_REST_Response( [ "scan" => Cron::scan_step( 15 ) ] );
    }

    /**
     * A post title as plain text. get_the_title() runs wptexturize, which turns
     * "&" into "&#038;"; the screens escape what they get, so the entity showed
     * up as "Story &#038; Cast" (and "&amp;#038;" after a refresh).
     *
     * @param int|\WP_Post $post
     */
    public static function plain_title( $post ): string {
        return trim( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, "UTF-8" ) );
    }

    public function report_broken_links( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $links = $wpdb->prefix . "jnk_linkweave_links";
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_post_id, target_post_id, target_url, anchor_text, is_broken, last_checked
             FROM %i WHERE is_broken > 0 ORDER BY is_broken ASC, last_checked DESC",
            $links
        ) );

        // Raw post IDs are unreadable in a report you are meant to act on. Resolve the
        // source title, and give the target a usable label: its title where the post
        // still exists, otherwise the dead URL itself.
        foreach ( (array) $rows as $row ) {
            $row->source_title = self::plain_title( (int) $row->source_post_id ) ?: sprintf( "#%d", (int) $row->source_post_id );
            $row->source_edit  = get_edit_post_link( (int) $row->source_post_id, "raw" );
            $row->target_label = (int) $row->target_post_id > 0
                ? ( self::plain_title( (int) $row->target_post_id ) ?: sprintf( "#%d", (int) $row->target_post_id ) )
                : (string) $row->target_url;
        }

        // Report when the scan last ran so an empty list can say which of the two
        // things it means: nothing is broken, or nothing has been checked yet.
        $scan = Cron::scan_progress();

        return new \WP_REST_Response( [
            "broken"       => $rows ?: [],
            "last_scan"    => $scan["last_scan"],
            "scan_pending" => $scan["running"],
            "scan"         => $scan,
        ] );
    }

    public function report_inbound_opportunities( \WP_REST_Request $r ): \WP_REST_Response {
        $post_id = (int) $r["post_id"];
        if ( ! current_user_can( "read_post", $post_id ) ) {
            return new \WP_REST_Response( [ "error" => "Forbidden." ], 403 );
        }
        // Omitted limit falls through to the engine's suggestions_per_post default.
        $args  = [];
        $limit = $r->get_param( "limit" );
        if ( null !== $limit ) {
            $args["limit"] = min( 50, max( 1, (int) $limit ) );
        }
        $res = ( new Suggestion_Engine() )->inbound_opportunities( $post_id, $args );
        foreach ( $res["opportunities"] as &$o ) {
            $o["edit_url"] = get_edit_post_link( (int) $o["target_id"], "raw" );
        }
        unset( $o );
        return new \WP_REST_Response( $res );
    }

    public function get_settings( \WP_REST_Request $r ): \WP_REST_Response {
        $opts = get_option( "jnk_linkweave_settings", [] );
        // Never expose the encrypted API key blob. The Pro module adds the
        // "is a key set" flag (and any other premium-only fields) via this filter;
        // free core never references the LLM adapter.
        unset( $opts["llm_api_key"] );
        $opts = (array) apply_filters( "jnk_linkweave_settings_response", $opts, [], false );
        return new \WP_REST_Response( $opts );
    }

    public function update_settings( \WP_REST_Request $r ): \WP_REST_Response {
        $body = (array) $r->get_json_params();
        $opts = get_option( "jnk_linkweave_settings", [] );

        $allowed_freq        = [ "daily", "weekly", "never" ];
        $allowed_post_types  = (array) apply_filters( "jnk_linkweave_allowed_post_types", get_post_types( [ "public" => true ] ) );

        if ( array_key_exists( "post_types", $body ) ) {
            $opts["post_types"] = array_values( array_intersect(
                array_map( "sanitize_key", (array) $body["post_types"] ),
                $allowed_post_types
            ) );
        }
        if ( array_key_exists( "min_post_length", $body ) ) {
            $opts["min_post_length"] = max( 0, (int) $body["min_post_length"] );
        }
        if ( array_key_exists( "suggestions_per_post", $body ) ) {
            $opts["suggestions_per_post"] = max( 1, min( 50, (int) $body["suggestions_per_post"] ) );
        }
        if ( array_key_exists( "min_relevance_score", $body ) ) {
            $opts["min_relevance_score"] = max( 0.0, min( 1.0, (float) $body["min_relevance_score"] ) );
        }
        if ( array_key_exists( "ranking", $body ) ) {
            $opts["ranking"] = in_array( $body["ranking"], [ "bm25", "tfidf" ], true ) ? $body["ranking"] : "bm25";
        }
        if ( array_key_exists( "auto_index_on_save", $body ) ) {
            $opts["auto_index_on_save"] = (bool) $body["auto_index_on_save"];
        }
        if ( array_key_exists( "broken_link_check_freq", $body ) ) {
            $opts["broken_link_check_freq"] = in_array( $body["broken_link_check_freq"], $allowed_freq, true )
                ? $body["broken_link_check_freq"] : "daily";
        }
        if ( array_key_exists( "language", $body ) ) {
            $opts["language"] = substr( sanitize_key( (string) $body["language"] ), 0, 10 );
        }
        $rebuild = false;
        if ( array_key_exists( "stopwords_custom", $body ) ) {
            $words = is_array( $body["stopwords_custom"] ) ? $body["stopwords_custom"] : preg_split( "/[,\n]+/", (string) $body["stopwords_custom"] );
            $words = array_values( array_unique( array_filter( array_map(
                static fn( $w ) => mb_strtolower( trim( sanitize_text_field( (string) $w ) ) ),
                array_slice( (array) $words, 0, 500 )
            ) ) ) );
            // The index drops ignored words when it is built, so a change only
            // shows after a rebuild; start one instead of leaving that to the user.
            $rebuild = $words !== array_values( (array) ( $opts["stopwords_custom"] ?? [] ) );
            $opts["stopwords_custom"] = $words;
        }
        if ( array_key_exists( "delete_data_on_uninstall", $body ) ) {
            $opts["delete_data_on_uninstall"] = ! empty( $body["delete_data_on_uninstall"] );
        }

        // Premium LLM fields (mode/model) are handled by the Pro module, which
        // hooks this filter; free core neither knows nor stores them.
        $opts = (array) apply_filters( "jnk_linkweave_settings_pre_save", $opts, $body );

        // API-key changes require HTTPS. The guard is generic; the Pro module does
        // the actual (encrypted) write in the jnk_linkweave_settings_response filter.
        if ( isset( $body["llm_api_key"] ) && "" !== $body["llm_api_key"] && ! is_ssl() ) {
            return new \WP_REST_Response( [ "error" => "API key changes require HTTPS." ], 400 );
        }

        $before_types = (array) ( get_option( "jnk_linkweave_settings", [] )["post_types"] ?? [ "post", "page" ] );
        update_option( "jnk_linkweave_settings", $opts );

        // Unticked post types leave the index immediately, not at the next rebuild.
        // A newly ticked type is indexed by a background rebuild; it used to stay out
        // of the index (and the reports) until someone clicked Rebuild.
        $rebuild_queued = false;
        if ( array_key_exists( "post_types", $body ) ) {
            Index_Builder::purge_excluded_types( (array) $opts["post_types"] );
            if ( array_diff( (array) $opts["post_types"], $before_types ) ) {
                Index_Builder::schedule_rebuild();
                $rebuild_queued = true;
            }
        }
        if ( $rebuild && ! $rebuild_queued ) {
            Index_Builder::schedule_rebuild();
            $rebuild_queued = true;
        }
        // Ranking, thresholds and post types all change suggestion lists.
        Cron::bump_graph_version();

        unset( $opts["llm_api_key"] );
        $opts = (array) apply_filters( "jnk_linkweave_settings_response", $opts, $body, true );
        $opts["rebuild_queued"] = $rebuild_queued;
        return new \WP_REST_Response( $opts );
    }
}
