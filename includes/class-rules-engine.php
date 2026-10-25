<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Rules_Engine {

    /** Elements whose text never gets a link: links, code, headings, forms, scripts. */
    public const PROTECTED_TAGS = "a|code|pre|h[1-6]|form|label|button|select|option|textarea|script|style";

    private const CACHE_KEY = "jnk_linkweave_active_rules";
    private const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Option holding the per-site cap allocation: rule id => [ post id => links ].
     * Only rules with a positive max_per_site appear. Written by
     * recompute_applied_counts(), read at render time.
     */
    public const SITE_ALLOCATION_OPTION = "jnk_linkweave_rule_site_allocation";

    /** Per-request copy of the allocation option. */
    private static ?array $allocation = null;

    /**
     * Ids of rules that use options only the Pro build can apply (other words,
     * post type or category limits, rel="sponsored"). The Pro build keeps this list
     * in step with its own data; the free build only reads it.
     */
    public const NEEDS_PRO_OPTION = "jnk_linkweave_rules_needing_pro";

    /**
     * Post meta: the owner asked for no auto-links on this post. The switch to set
     * it is a Pro screen, but the choice is honoured by every build, so a post that
     * was opted out stays free of auto-links after Pro is removed.
     */
    public const OPT_OUT_META = "_jnk_linkweave_no_autolink";

    /** Did the owner switch auto-links off for this post? */
    public static function post_opted_out( int $post_id ): bool {
        return $post_id > 0 && (bool) get_post_meta( $post_id, self::OPT_OUT_META, true );
    }

    /**
     * Can this install apply those options? True whenever the Pro code is loaded
     * and was ever licensed, including after a licence lapses, so a live site keeps
     * the links it had. False on the free build.
     */
    public static function rule_options_supported(): bool {
        return (bool) apply_filters( "jnk_linkweave_rule_options_supported", false );
    }

    /** @return array<int,true> rule id => true */
    public static function rules_needing_pro(): array {
        $ids = get_option( self::NEEDS_PRO_OPTION, [] );
        $out = [];
        foreach ( is_array( $ids ) ? $ids : [] as $id ) {
            if ( (int) $id > 0 ) {
                $out[ (int) $id ] = true;
            }
        }
        return $out;
    }

    /**
     * A rule that needs Pro options is paused when they cannot be applied. Running
     * it without them would link on every post instead of the chosen ones and drop
     * rel="sponsored" from paid links. Its data is kept, so it resumes with Pro.
     */
    public static function is_paused_without_pro( int $rule_id ): bool {
        return ! self::rule_options_supported() && isset( self::rules_needing_pro()[ $rule_id ] );
    }

    /**
     * @param object[] $rules
     * @return object[]
     */
    private static function drop_paused( array $rules ): array {
        if ( self::rule_options_supported() ) {
            return $rules;
        }
        $needs = self::rules_needing_pro();
        if ( ! $needs ) {
            return $rules;
        }
        return array_values( array_filter( $rules, static fn( $r ) => ! isset( $needs[ (int) ( $r->id ?? 0 ) ] ) ) );
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . "jnk_linkweave_rules";
    }

    /**
     * Reduce a URL to a form two spellings of the same page share, so a rule
     * target can be compared against a permalink. Ignores scheme, a leading
     * "www.", host case, the trailing slash, and any fragment. Query strings are
     * kept: "?p=2" and "?p=3" are different pages.
     *
     * Returns "" for anything unparseable, and callers must treat "" as
     * "no match" rather than comparing two empty strings as equal.
     */
    private static function normalize_url( string $url ): string {
        $url = trim( $url );
        if ( "" === $url ) {
            return "";
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ( empty( $parts["host"] ) && empty( $parts["path"] ) ) ) {
            return "";
        }
        $host = isset( $parts["host"] ) ? strtolower( $parts["host"] ) : "";
        if ( str_starts_with( $host, "www." ) ) {
            $host = substr( $host, 4 );
        }
        $path  = isset( $parts["path"] ) ? rtrim( $parts["path"], "/" ) : "";
        $query = isset( $parts["query"] ) && "" !== $parts["query"] ? "?" . $parts["query"] : "";

        return $host . $path . $query;
    }

    /**
     * normalize_url() for comparing links inside a post: a relative href
     * ("/grinder-guide/") is the same page as the rule's absolute target, so it
     * borrows this site's host before normalising.
     */
    private static function link_key( string $url ): string {
        $url = trim( $url );
        if ( str_starts_with( $url, "/" ) && ! str_starts_with( $url, "//" ) ) {
            $url = untrailingslashit( home_url() ) . $url;
        }
        return self::normalize_url( $url );
    }

    public function apply_rules_to_content( string $content, ?int $post_id = null ): string {
        if ( is_admin() || is_feed() || empty( $content ) ) {
            return $content;
        }
        if ( null === $post_id ) {
            $post_id = (int) get_the_ID();
        }

        $rules = self::limit_rules( $this->get_active_rules() );
        if ( empty( $rules ) ) {
            return $content;
        }

        // "Max per site" is a site-wide budget. It used to be compared against the
        // rule's stored site-wide total on every render, so any cap below that total
        // switched the rule off on every page. The budget is now shared out across
        // posts (oldest first) by recompute_applied_counts(), and each post renders
        // only its own share.
        $site_caps  = [];
        $allocation = self::site_allocation();
        foreach ( $rules as $rule ) {
            if ( (int) $rule->max_per_site > 0 ) {
                $site_caps[ (int) $rule->id ] = (int) ( $allocation[ (int) $rule->id ][ $post_id ] ?? 0 );
            }
        }

        $result = self::link_content( $content, $rules, $post_id, $site_caps, true );
        return $result["content"];
    }

    /**
     * Apply the optional jnk_linkweave_max_active_rules cap. Default 0 means
     * UNLIMITED (the free tier ships with no cap); a host filter may set a
     * positive limit if desired.
     */
    private static function limit_rules( array $rules ): array {
        $max = (int) apply_filters( "jnk_linkweave_max_active_rules", 0 );
        if ( $max > 0 ) {
            $rules = array_slice( $rules, 0, $max );
        }
        return $rules;
    }

    /**
     * The one linking pass, shared by rendering and by the Applied count, so the
     * number on the Rules screen is the number of links a visitor actually gets.
     * (The count used to regex the raw content and ignored the self-link skip and
     * the protected regions below, so it overstated every rule.)
     *
     * @param object[]       $rules     Active rules, in application order.
     * @param array<int,int> $site_caps Rule id => links this post may receive under
     *                                  the rule's max_per_site budget. Rules absent
     *                                  from the map have no site budget.
     * @param bool           $fire      Fire jnk_linkweave_rule_applied (render only).
     * @return array{content:string,applied:array<int,int>}
     */
    public static function link_content( string $content, array $rules, int $post_id, array $site_caps = [], bool $fire = false ): array {
        $applied = [];
        if ( "" === $content || empty( $rules ) || self::post_opted_out( $post_id ) ) {
            return [ "content" => $content, "applied" => $applied ];
        }
        /**
         * The rules that may link inside this post. Runs for rendering and for the
         * Links added count alike, so both agree. Extensions can narrow the list
         * per post (a rule limited to some post types, a post that opts out).
         *
         * @param object[] $rules   Active rules, in application order.
         * @param int      $post_id Post being linked (0 when unknown).
         */
        $rules = array_values( (array) apply_filters( "jnk_linkweave_rules_for_post", self::drop_paused( $rules ), $post_id ) );
        if ( empty( $rules ) ) {
            return [ "content" => $content, "applied" => $applied ];
        }

        // Pages this post already links to, by hand or from an earlier rule. A rule
        // aimed at one of them would add a second link to the same page, which
        // reads as spam to visitors and to search engines.
        $linked = [];
        if ( preg_match_all( "~<a\\b[^>]*?\\bhref\\s*=\\s*([\"'])(.*?)\\1~is", $content, $hrefs ) ) {
            foreach ( $hrefs[2] as $href ) {
                $key = self::link_key( html_entity_decode( (string) $href, ENT_QUOTES ) );
                if ( "" !== $key ) {
                    $linked[ $key ] = true;
                }
            }
        }

        // Protect regions whose contents must not be touched.
        $placeholders = [];
        $protect      = static function ( $m ) use ( &$placeholders ) {
            $key                  = "%%AUTOLINK_PROTECT_" . count( $placeholders ) . "%%";
            $placeholders[ $key ] = $m[0];
            return $key;
        };
        // Forms too: a link inside a label or an option took a visitor who tapped
        // "Email" away from the form they were filling in (QA 25 Oct 2026).
        $protected = preg_replace_callback( "~<(" . self::PROTECTED_TAGS . ")\\b[^>]*>.*?</\\1>~is", $protect, $content );
        // HTML comments (block delimiters carry JSON that the shortcode pattern
        // below would otherwise half-match).
        $protected = preg_replace_callback( "~<!--.*?-->~s", $protect, (string) $protected );
        // Raw shortcodes that survived do_shortcode.
        $protected = preg_replace_callback( "~\\[[^\\]]+\\]~", $protect, (string) $protected );
        // Every remaining tag, so a keyword inside an attribute (alt="…", title="…")
        // can never be turned into a link that breaks the tag.
        $protected = preg_replace_callback( "~<[^>]+>~", $protect, (string) $protected );
        $protected = (string) $protected;

        // A rule whose target is the post we're rendering would link the post to
        // itself. Resolve the current permalink once and skip those rules.
        $self_url = $post_id > 0 ? self::normalize_url( (string) get_permalink( $post_id ) ) : "";

        foreach ( $rules as $rule ) {
            $id = (int) $rule->id;
            if ( "" !== $self_url && self::normalize_url( (string) $rule->target_url ) === $self_url ) {
                continue;
            }
            // A shortlink target (/?p=123, /?page_id=123) never equals the pretty
            // permalink, so compare the id it names as well.
            if ( $post_id > 0 && self::shortlink_post_id( (string) $rule->target_url ) === $post_id ) {
                continue;
            }
            $cap = (int) $rule->max_per_post > 0 ? (int) $rule->max_per_post : 1;
            if ( array_key_exists( $id, $site_caps ) ) {
                $cap = min( $cap, max( 0, (int) $site_caps[ $id ] ) );
            }
            if ( $cap <= 0 || ( $applied[ $id ] ?? 0 ) >= $cap ) {
                continue;
            }
            $target_key = self::link_key( (string) $rule->target_url );
            if ( "" !== $target_key && isset( $linked[ $target_key ] ) ) {
                continue;
            }
            $protected = self::replace_matches( $protected, $rule, $cap, $applied, $post_id, $placeholders, $fire );
            if ( "" !== $target_key && ( $applied[ $id ] ?? 0 ) > 0 ) {
                $linked[ $target_key ] = true;
            }
        }

        // Placeholders can nest (a protected tag inside a protected region), so
        // restore until nothing is left to restore.
        $out = $protected;
        for ( $i = 0; $i < 5 && false !== strpos( $out, "%%AUTOLINK_PROTECT_" ); $i++ ) {
            $out = strtr( $out, $placeholders );
        }

        return [ "content" => $out, "applied" => $applied ];
    }

    /**
     * @param array<string,string> $placeholders Protected-fragment map (passed by
     *        reference). Each anchor this method inserts is itself stashed as a
     *        placeholder so that later rules in the same pass cannot match inside an
     *        anchor we just created.
     */
    private static function replace_matches( string $content, object $rule, int $cap, array &$applied, int $post_id, array &$placeholders, bool $fire ): string {
        $flags = ! empty( $rule->case_sensitive ) ? "u" : "iu";
        /**
         * Phrases a rule links. One per rule by default; an extension may add
         * other spellings or synonyms. The rule's caps cover all of them together.
         *
         * @param string[] $keywords Phrases, the rule's own keyword first.
         * @param object   $rule     The rule row.
         */
        $keywords = array_values( array_unique( array_filter( array_map(
            static fn( $k ) => trim( (string) $k ),
            (array) apply_filters( "jnk_linkweave_rule_keywords", [ (string) $rule->keyword ], $rule )
        ) ) ) );
        if ( ! $keywords ) {
            return $content;
        }
        // Longest first, so "page caching plugin" wins over "page caching".
        usort( $keywords, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
        $pattern   = "/\\b(?:" . implode( "|", array_map( static fn( $k ) => preg_quote( $k, "/" ), $keywords ) ) . ")\\b/" . $flags;
        $remaining = $cap - ( $applied[ (int) $rule->id ] ?? 0 );
        if ( $remaining <= 0 ) {
            return $content;
        }

        $result = preg_replace_callback( $pattern, static function ( $m ) use ( $rule, &$applied, $post_id, &$placeholders, $fire ) {
            $applied[ (int) $rule->id ] = ( $applied[ (int) $rule->id ] ?? 0 ) + 1;
            if ( $fire ) {
                do_action( "jnk_linkweave_rule_applied", (int) $rule->id, $post_id );
            }

            $target_attr = ! empty( $rule->open_in_new_tab ) ? ' target="_blank"' : "";
            $rel_parts   = [];
            if ( ! empty( $rule->open_in_new_tab ) ) {
                $rel_parts[] = "noopener";
            }
            if ( ! empty( $rule->nofollow ) ) {
                $rel_parts[] = "nofollow";
            }
            /**
             * rel values for a link a rule adds (e.g. "sponsored" for paid links).
             *
             * @param string[] $rel_parts rel tokens so far.
             * @param object   $rule      The rule row.
             */
            $rel_parts = array_values( array_unique( array_map( "sanitize_html_class", (array) apply_filters( "jnk_linkweave_rule_rel", $rel_parts, $rule ) ) ) );
            $rel_attr = $rel_parts ? ' rel="' . esc_attr( implode( " ", $rel_parts ) ) . '"' : "";

            $anchor = sprintf(
                '<a href="%s"%s%s class="jnk-linkweave-auto">%s</a>',
                esc_url( $rule->target_url ),
                $target_attr,
                $rel_attr,
                esc_html( $m[0] )
            );

            $key                  = "%%AUTOLINK_PROTECT_" . count( $placeholders ) . "%%";
            $placeholders[ $key ] = $anchor;
            return $key;
        }, $content, $remaining );

        return null === $result ? $content : $result;
    }

    public function get_active_rules(): array {
        $cached = wp_cache_get( self::CACHE_KEY, "jnk-linkweave" );
        if ( false !== $cached ) {
            return $cached;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM %i WHERE is_active = 1 ORDER BY id ASC", self::table() )
        );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }
        wp_cache_set( self::CACHE_KEY, $rows, "jnk-linkweave", self::CACHE_TTL );
        return $rows;
    }

    public static function flush_cache(): void {
        wp_cache_delete( self::CACHE_KEY, "jnk-linkweave" );
        self::$allocation = null;
    }

    /** @return array<int,array<int,int>> rule id => [ post id => links allowed ] */
    public static function site_allocation(): array {
        if ( null === self::$allocation ) {
            $stored           = get_option( self::SITE_ALLOCATION_OPTION, [] );
            self::$allocation = is_array( $stored ) ? $stored : [];
        }
        return self::$allocation;
    }

    /**
     * Recompute every rule's applied_count as the number of links it currently
     * contributes site-wide, by running the same linking pass the front end runs
     * over each published post of the configured post types. Inactive rules count 0.
     *
     * The same walk shares out each max_per_site budget, oldest post first, and
     * stores the per-post shares for rendering to read.
     */
    public static function recompute_applied_counts(): void {
        global $wpdb;

        $all = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i ORDER BY id ASC", self::table() ) );
        if ( empty( $all ) ) {
            delete_option( self::SITE_ALLOCATION_OPTION );
            self::flush_cache();
            return;
        }

        $active = self::limit_rules( array_values( array_filter( (array) $all, static fn( $r ) => ! empty( $r->is_active ) ) ) );

        $settings   = (array) get_option( "jnk_linkweave_settings", [] );
        $post_types = ! empty( $settings["post_types"] ) && is_array( $settings["post_types"] )
            ? array_map( "sanitize_key", $settings["post_types"] )
            : [ "post", "page" ];
        $in = implode( ",", array_fill( 0, count( $post_types ), "%s" ) );

        $totals    = [];
        $remaining = [];
        $alloc     = [];
        foreach ( $active as $rule ) {
            $totals[ (int) $rule->id ] = 0;
            if ( (int) $rule->max_per_site > 0 ) {
                $remaining[ (int) $rule->id ] = (int) $rule->max_per_site;
                $alloc[ (int) $rule->id ]     = [];
            }
        }

        if ( ! empty( $active ) ) {
            // Oldest first, so a new post never takes the budget from an old one that
            // already shows the link.
            $posts = $wpdb->get_results( $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() placeholder list is built from %s at runtime; post types are the prepared args.
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY post_date ASC, ID ASC",
                $post_types
            ) );

            foreach ( (array) $posts as $post ) {
                $content = (string) $post->post_content;
                if ( "" === $content ) {
                    continue;
                }
                $result = self::link_content( $content, $active, (int) $post->ID, $remaining, false );
                foreach ( $result["applied"] as $rule_id => $n ) {
                    $totals[ $rule_id ] = ( $totals[ $rule_id ] ?? 0 ) + $n;
                    if ( isset( $remaining[ $rule_id ] ) ) {
                        $remaining[ $rule_id ]                = max( 0, $remaining[ $rule_id ] - $n );
                        $alloc[ $rule_id ][ (int) $post->ID ] = $n;
                    }
                }
            }
        }

        foreach ( (array) $all as $rule ) {
            $wpdb->update( self::table(), [ "applied_count" => (int) ( $totals[ (int) $rule->id ] ?? 0 ) ], [ "id" => (int) $rule->id ], [ "%d" ], [ "%d" ] );
        }

        update_option( self::SITE_ALLOCATION_OPTION, $alloc, false );
        self::flush_cache();
    }

    /** Debounced full recount after published content changes. */
    const RECOUNT_HOOK = "jnk_linkweave_recount_rules";

    public static function register_recount_hooks(): void {
        add_action( self::RECOUNT_HOOK, [ self::class, "recompute_applied_counts" ], 10, 0 );
        add_action( "transition_post_status", [ self::class, "schedule_recount_on_transition" ], 10, 3 );
    }

    /**
     * The Applied count used to be recomputed only when a rule was saved, so a post
     * published afterwards that mentions a keyword never moved the number, and
     * unpublishing one never lowered it. Any change that touches published content
     * now queues one recount a minute later; a burst of saves shares that one walk.
     *
     * @param string   $new_status New post status.
     * @param string   $old_status Previous post status.
     * @param \WP_Post $post       The post.
     */
    public static function schedule_recount_on_transition( $new_status, $old_status, $post ): void {
        if ( "publish" !== $new_status && "publish" !== $old_status ) {
            return;
        }
        if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }
        $settings   = (array) get_option( "jnk_linkweave_settings", [] );
        $post_types = ! empty( $settings["post_types"] ) && is_array( $settings["post_types"] )
            ? array_map( "sanitize_key", $settings["post_types"] )
            : [ "post", "page" ];
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }
        if ( empty( ( new self() )->get_active_rules() ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::RECOUNT_HOOK ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RECOUNT_HOOK );
        }
    }

    /**
     * After a single post is reindexed, a site budget may have room for it. Only
     * worth the full walk when some active rule actually has a budget.
     */
    public static function maybe_recompute_for_site_caps(): void {
        foreach ( ( new self() )->get_active_rules() as $rule ) {
            if ( (int) $rule->max_per_site > 0 ) {
                self::recompute_applied_counts();
                return;
            }
        }
    }

    /* -------------------- CRUD -------------------- */

    /**
     * @param bool $recount Recount every rule afterwards. Bulk callers pass false
     *                      and recount once at the end.
     */
    public function create( array $data, bool $recount = true ): int {
        global $wpdb;
        $now    = current_time( "mysql", true );
        $insert = $this->sanitize( $data );
        $insert["created_at"] = $now;
        $insert["updated_at"] = $now;
        $wpdb->insert( self::table(), $insert );
        $id = (int) $wpdb->insert_id;
        /**
         * A rule was created or updated. $data is the raw request body, so an
         * extension can store its own fields for the rule.
         */
        do_action( "jnk_linkweave_rule_saved", $id, $data );
        self::flush_cache();
        if ( $recount ) {
            self::recompute_applied_counts();
        }
        return $id;
    }

    public function update( int $id, array $data, bool $recount = true ): bool {
        global $wpdb;
        $update = $this->sanitize( $data );
        $update["updated_at"] = current_time( "mysql", true );
        $ok = $wpdb->update( self::table(), $update, [ "id" => $id ] );
        do_action( "jnk_linkweave_rule_saved", $id, $data );
        self::flush_cache();
        if ( $recount ) {
            self::recompute_applied_counts();
        }
        return false !== $ok;
    }

    public function delete( int $id ): bool {
        global $wpdb;
        $ok = $wpdb->delete( self::table(), [ "id" => $id ], [ "%d" ] );
        $needs = self::rules_needing_pro();
        if ( isset( $needs[ $id ] ) ) {
            unset( $needs[ $id ] );
            update_option( self::NEEDS_PRO_OPTION, array_keys( $needs ), true );
        }
        do_action( "jnk_linkweave_rule_deleted", $id );
        self::flush_cache();
        self::recompute_applied_counts();
        return false !== $ok;
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", self::table(), $id ) );
        return $row ? self::typed( $row ) : null;
    }

    public function list_rules( int $page = 1, int $per_page = 20 ): array {
        global $wpdb;
        $offset = max( 0, ( $page - 1 ) * $per_page );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d",
            self::table(),
            $per_page,
            $offset
        ) );
        $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", self::table() ) );
        return [
            "rules"    => array_map( [ self::class, "typed" ], $rows ?: [] ),
            "total"    => $total,
            "page"     => $page,
            "per_page" => $per_page,
        ];
    }

    /**
     * Give a rule row real types for the REST API. $wpdb returns every column as a
     * string, and "0" is truthy in JavaScript, so the edit form pre-ticked every
     * flag that was off and saving quietly turned them on.
     */
    public static function typed( object $row ): object {
        $out = clone $row;
        foreach ( [ "id", "max_per_post", "max_per_site", "applied_count" ] as $int_key ) {
            if ( isset( $out->$int_key ) ) {
                $out->$int_key = (int) $out->$int_key;
            }
        }
        foreach ( [ "case_sensitive", "open_in_new_tab", "nofollow", "is_active", "is_generated" ] as $bool_key ) {
            if ( isset( $out->$bool_key ) ) {
                $out->$bool_key = (bool) (int) $out->$bool_key;
            }
        }
        $out->paused_needs_pro = isset( $out->id ) && self::is_paused_without_pro( (int) $out->id );
        /** Lets an extension add its own fields to a rule in REST responses. */
        return (object) apply_filters( "jnk_linkweave_rule_row", $out );
    }

    private function sanitize( array $data ): array {
        $out = [];
        if ( isset( $data["keyword"] ) ) {
            $out["keyword"] = sanitize_text_field( (string) $data["keyword"] );
        }
        if ( isset( $data["target_url"] ) ) {
            $out["target_url"] = esc_url_raw( (string) $data["target_url"] );
        }
        foreach ( [ "case_sensitive", "open_in_new_tab", "nofollow", "is_active" ] as $bool_key ) {
            if ( array_key_exists( $bool_key, $data ) ) {
                // "0" and "false" arrive from forms and older clients; both mean off.
                $v = $data[ $bool_key ];
                $out[ $bool_key ] = ( empty( $v ) || "false" === $v ) ? 0 : 1;
            }
        }
        if ( isset( $data["max_per_post"] ) ) {
            $out["max_per_post"] = max( 1, (int) $data["max_per_post"] );
        }
        if ( isset( $data["max_per_site"] ) ) {
            $out["max_per_site"] = max( 0, (int) $data["max_per_site"] );
        }
        return $out;
    }

    public static function is_pro(): bool {
        $license = get_option( "jnk_linkweave_license", [] );
        $is_pro  = ! empty( $license["is_pro"] );
        return (bool) apply_filters( "jnk_linkweave_is_pro", $is_pro );
    }

    /**
     * The post id named by a same-site shortlink target ("?p=123" or
     * "?page_id=123"), or 0. Cheap string parsing, no database lookup.
     */
    public static function shortlink_post_id( string $url ): int {
        $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
        if ( "" === $query ) {
            return 0;
        }
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( "" !== $host && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
            return 0;
        }
        parse_str( $query, $args );
        foreach ( [ "p", "page_id" ] as $key ) {
            if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && ctype_digit( (string) $args[ $key ] ) ) {
                return (int) $args[ $key ];
            }
        }
        return 0;
    }
}
