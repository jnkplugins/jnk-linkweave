<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Cron {

    public const DAILY_HOOK = "jnk_linkweave_daily_scan";

    public static function register_hooks(): void {
        add_action( self::DAILY_HOOK, [ __CLASS__, "run_daily_scan" ] );
        // The daily event was only ever scheduled on activation or upgrade, so once
        // lost (a cron table wiped by a migration or a cleanup plugin; seen in QA on
        // 8 Oct 2026 after a bulk import) the broken-link scan and the nightly
        // report refresh stopped for good, silently. Put it back if it is missing.
        add_action( "admin_init", [ __CLASS__, "ensure_daily_scheduled" ] );
        // A full index rebuild (manual "Rebuild index" button or the reindex job)
        // must also refresh the internal-link graph, otherwise the Orphans /
        // Inbound-counts reports read an empty links table right after the
        // documented first-run setup and flag EVERY post as an orphan. This fires
        // inside the same background Action Scheduler job as the rebuild, so it
        // never runs inline on a web request. (The broken-link HTTP scan stays on
        // the daily schedule — it is slow and not needed for orphan accuracy.)
        add_action( "jnk_linkweave_index_complete", [ __CLASS__, "rebuild_links_graph" ], 10, 0 );
        add_action( self::SCAN_NOW_HOOK, [ __CLASS__, "scan_now" ], 10, 0 );
        // A saved post's own links refresh in its background reindex job, so Orphans
        // and Inbound Counts follow a save without a full rebuild.
        add_action( Index_Builder::REINDEX_HOOK, [ __CLASS__, "refresh_links_for_post" ], 15, 1 );
        add_action( self::LINKS_HOOK, [ __CLASS__, "refresh_links_for_post" ], 10, 1 );
        add_action( "wp_trash_post", [ __CLASS__, "forget_post_links" ] );
        add_action( "before_delete_post", [ __CLASS__, "forget_post_links" ] );
        add_action( "admin_init", [ __CLASS__, "maybe_purge_unlinkable_rows" ] );
        add_action( "transition_post_status", static function ( $new_status, $old_status, $post ) {
            if ( "publish" === $old_status && "publish" !== $new_status && $post instanceof \WP_Post ) {
                self::forget_post_links( $post->ID );
                self::unlink_target( (int) $post->ID );
            }
            if ( "publish" === $new_status && "publish" !== $old_status && $post instanceof \WP_Post ) {
                self::adopt_links_to( $post );
            }
        }, 10, 3 );
        add_filter( "pre_schedule_event", [ __CLASS__, "guard_action_scheduler_queue" ], 10, 2 );
        add_filter( "pre_reschedule_event", [ __CLASS__, "guard_action_scheduler_queue" ], 10, 2 );
        add_filter( "pre_update_option_cron", [ __CLASS__, "strip_queue_after_deactivation" ], 10, 1 );
    }

    /** Set by the deactivation hook; read fresh from the DB by the guard below. */
    public const DEACTIVATED_AT_OPTION = "jnk_linkweave_deactivated_at";

    /**
     * Stop requests that are still running this plugin's code from putting Action
     * Scheduler's WP-Cron event back right after the plugin is deactivated.
     *
     * Clearing `action_scheduler_run_queue` once on deactivation was not enough:
     * the deactivation request itself spawns a WP-Cron request at its start (with
     * the plugin still loaded), and that request, or any other one already in
     * flight, reschedules the event after the clear. The next WP-Cron run then
     * happens with the plugin gone, the "every_minute" schedule no longer exists,
     * and WordPress logs "Cron reschedule event error … invalid_schedule".
     *
     * Blocks only when the deactivation flag is recent AND a fresh database read
     * shows this plugin is no longer active, so it can never throttle a live site.
     * Returns true (not false) so core does not log a different error instead.
     *
     * @param null|bool|\WP_Error $pre   Short-circuit value from earlier filters.
     * @param object              $event The event being (re)scheduled.
     */
    public static function ensure_daily_scheduled(): void {
        if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, "daily", self::DAILY_HOOK );
        }
    }

    public static function guard_action_scheduler_queue( $pre, $event ) {
        if ( null !== $pre || ! is_object( $event ) || "action_scheduler_run_queue" !== ( $event->hook ?? "" ) ) {
            return $pre;
        }
        return self::deactivated_recently() ? true : $pre;
    }

    /**
     * Strip Action Scheduler's WP-Cron event from any cron-array save made by a
     * request that still runs this plugin after it was deactivated.
     *
     * The guard above only sees attempts to (re)schedule that one hook. WordPress
     * keeps the whole "cron" option in each request's memory from boot, so a request
     * that started before the deactivation writes its old copy back the next time it
     * saves ANY cron change (another plugin's event, the end of a WP-Cron run),
     * silently restoring the event this plugin just cleared.
     *
     * @param mixed $value The cron array about to be stored.
     * @return mixed
     */
    public static function strip_queue_after_deactivation( $value ) {
        if ( ! is_array( $value ) || ! self::deactivated_recently() ) {
            return $value;
        }
        foreach ( $value as $timestamp => $hooks ) {
            if ( ! is_array( $hooks ) || ! isset( $hooks["action_scheduler_run_queue"] ) ) {
                continue;
            }
            unset( $value[ $timestamp ]["action_scheduler_run_queue"] );
            if ( empty( $value[ $timestamp ] ) ) {
                unset( $value[ $timestamp ] );
            }
        }
        return $value;
    }

    /**
     * True for ten minutes after this plugin's deactivation hook ran, while the
     * plugin is still not active. Both values come straight from the options table:
     * another request just changed them, and this request's object cache still holds
     * the values it booted with. Always false on multisite and whenever the plugin is
     * (again) active, so a live site is never affected.
     */
    private static function deactivated_recently(): bool {
        if ( is_multisite() ) {
            return false;
        }
        global $wpdb;
        $flag = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::DEACTIVATED_AT_OPTION ) );
        if ( $flag <= 0 || ( time() - $flag ) > 10 * MINUTE_IN_SECONDS ) {
            return false;
        }
        $active = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", "active_plugins" ) ) );
        return ! ( is_array( $active ) && in_array( plugin_basename( JNK_LINKWEAVE_FILE ), $active, true ) );
    }

    public const SCAN_NOW_HOOK      = "jnk_linkweave_scan_now";
    public const SCAN_PENDING_OPTION = "jnk_linkweave_broken_scan_pending";

    /**
     * Queue an on-demand broken-link scan ("Scan now"). Before this, a user who
     * fixed or added links had to wait up to a day for the report to catch up.
     * Runs in the background when Action Scheduler is available, inline otherwise.
     *
     * @return bool True when queued, false when it ran inline.
     */
    public static function queue_scan_now(): bool {
        if ( function_exists( "as_enqueue_async_action" ) ) {
            update_option( self::SCAN_PENDING_OPTION, time(), false );
            if ( ! function_exists( "as_next_scheduled_action" ) || false === as_next_scheduled_action( self::SCAN_NOW_HOOK, [], "jnk-linkweave" ) ) {
                as_enqueue_async_action( self::SCAN_NOW_HOOK, [], "jnk-linkweave" );
            }
            return true;
        }
        self::scan_now();
        return false;
    }

    public static function scan_now(): void {
        self::rebuild_links_graph();
        self::scan_broken_links();
        delete_option( self::SCAN_PENDING_OPTION );
    }

    /** True while a queued "Scan now" has not finished (stale after 10 minutes). */
    public static function scan_pending(): bool {
        $queued = (int) get_option( self::SCAN_PENDING_OPTION, 0 );
        return $queued > 0 && ( time() - $queued ) < 10 * MINUTE_IN_SECONDS;
    }

    public static function run_daily_scan(): void {
        $settings = get_option( "jnk_linkweave_settings", [] );
        $freq     = (string) ( $settings["broken_link_check_freq"] ?? "daily" );

        self::rebuild_links_graph();

        if ( "never" !== $freq ) {
            self::scan_broken_links();
        }
    }

    /**
     * Walk every published post, parse internal anchors out of post_content,
     * and rebuild the wp_jnk_linkweave_links table. Cheap enough to do daily;
     * keeps orphan + inbound-count reports accurate without DB triggers.
     */
    public static function rebuild_links_graph(): int {
        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";

        $settings   = get_option( "jnk_linkweave_settings", [] );
        $post_types = (array) apply_filters(
            "jnk_linkweave_suggestion_post_types",
            $settings["post_types"] ?? [ "post", "page" ]
        );

        $ids = get_posts( [
            "post_type"        => $post_types,
            "post_status"      => "publish",
            "posts_per_page"   => -1,
            "fields"           => "ids",
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- scan the canonical published corpus for broken links; other plugins' query filters must not alter the set.
            "suppress_filters" => true,
        ] );

        // Keep the last scan's verdict for links that still exist. A rebuild used to
        // re-insert every row with is_broken = 0, so "Rebuild index" silently emptied
        // the Broken Links report while the dead links were still in the posts.
        $previous = self::previous_verdicts( $wpdb->get_results( $wpdb->prepare( "SELECT source_post_id, target_post_id, target_url, anchor_text, is_broken, last_checked FROM %i", $table ) ) );

        // Fresh rebuild: truncate then repopulate.
        $wpdb->query( $wpdb->prepare( "TRUNCATE TABLE %i", $table ) );

        $written = 0;
        foreach ( $ids as $source_id ) {
            $written += self::write_links_for_post( (int) $source_id, $previous );
            clean_post_cache( $source_id );
        }

        self::bump_graph_version();
        return $written;
    }

    /**
     * Refresh one post's outgoing links in the graph.
     *
     * Runs in the background per-post reindex job after a save. Until this existed
     * only a full rebuild (or the daily scan) touched the graph, so a link inserted
     * and saved left its target on the Orphans report and out of Inbound Counts.
     */
    public static function refresh_links_for_post( int $post_id ): int {
        global $wpdb;
        if ( $post_id <= 0 ) {
            return 0;
        }
        $table = $wpdb->prefix . "jnk_linkweave_links";

        $previous = self::previous_verdicts( $wpdb->get_results( $wpdb->prepare(
            "SELECT source_post_id, target_post_id, target_url, anchor_text, is_broken, last_checked FROM %i WHERE source_post_id = %d",
            $table,
            $post_id
        ) ) );
        $wpdb->delete( $table, [ "source_post_id" => $post_id ], [ "%d" ] );

        $post       = get_post( $post_id );
        $settings   = get_option( "jnk_linkweave_settings", [] );
        $post_types = (array) apply_filters( "jnk_linkweave_suggestion_post_types", $settings["post_types"] ?? [ "post", "page" ] );
        $written    = 0;
        if ( $post && "publish" === $post->post_status && in_array( $post->post_type, $post_types, true ) ) {
            $written = self::write_links_for_post( $post_id, $previous );
        }
        self::bump_graph_version();
        return $written;
    }

    public const LINKS_HOOK = "jnk_linkweave_refresh_post_links";

    /** Queue a background refresh of one post's links (used when auto-index is off). */
    public static function schedule_links_refresh( int $post_id ): void {
        if ( $post_id <= 0 ) {
            return;
        }
        if ( function_exists( "as_enqueue_async_action" ) ) {
            as_enqueue_async_action( self::LINKS_HOOK, [ $post_id ], "jnk-linkweave" );
            return;
        }
        if ( ! wp_next_scheduled( self::LINKS_HOOK, [ $post_id ] ) ) {
            wp_schedule_single_event( time() + 5, self::LINKS_HOOK, [ $post_id ] );
        }
    }

    /** Can visitors open this post? Published, or an attachment page of one. */
    public static function is_public_target( int $post_id ): bool {
        $status = get_post_status( $post_id );
        if ( "publish" === $status ) {
            return true;
        }
        if ( "inherit" === $status && "attachment" === get_post_type( $post_id ) ) {
            $parent = (int) wp_get_post_parent_id( $post_id );
            return 0 === $parent || "publish" === get_post_status( $parent );
        }
        return false;
    }

    /**
     * url_to_postid() does not resolve a pretty permalink of a draft. Does this
     * same-site URL's last path segment name a post that is not live yet?
     */
    private static function names_unpublished_post( string $url ): bool {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), "/" );
        if ( "" === $path ) {
            return false;
        }
        $slug = sanitize_title( basename( $path ) );
        if ( "" === $slug ) {
            return false;
        }
        $ids = get_posts( [
            "name"             => $slug,
            "post_type"        => array_values( get_post_types( [ "public" => true ] ) ),
            "post_status"      => [ "draft", "pending", "future", "private" ],
            "posts_per_page"   => 1,
            "fields"           => "ids",
            "no_found_rows"    => true,
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- exact slug lookup.
            "suppress_filters" => true,
        ] );
        return ! empty( $ids );
    }

    /**
     * A post was just published: links that already point at it (written while it
     * was a draft, or before it was restored) become internal links now, instead
     * of waiting for the next full rebuild.
     */
    public static function adopt_links_to( \WP_Post $post ): int {
        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";
        $id    = (int) $post->ID;
        $like  = [ "%p=" . $id, "%p=" . $id . "&%", "%page_id=" . $id . "%" ];
        $sql   = "SELECT id, target_url FROM %i WHERE target_post_id = 0 AND ( target_url LIKE %s OR target_url LIKE %s OR target_url LIKE %s";
        $args  = array_merge( [ $table ], $like );
        $name  = (string) $post->post_name;
        if ( "" !== $name ) {
            $sql   .= " OR target_url LIKE %s";
            $args[] = "%/" . $wpdb->esc_like( $name ) . "%";
        }
        $sql .= " ) LIMIT 2000";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only, built above.
        $n    = 0;
        foreach ( (array) $rows as $row ) {
            if ( url_to_postid( (string) $row->target_url ) !== $id ) {
                continue;
            }
            // IGNORE: the same link may already be stored resolved. Then this
            // unresolved copy is a duplicate and goes.
            $updated = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE IGNORE %i SET target_post_id = %d, is_broken = 0 WHERE id = %d",
                $table,
                $id,
                (int) $row->id
            ) );
            if ( 0 === $updated ) {
                $wpdb->delete( $table, [ "id" => (int) $row->id, "target_post_id" => 0 ], [ "%d", "%d" ] );
            }
            $n++;
        }
        if ( $n > 0 ) {
            self::bump_graph_version();
        }
        return $n;
    }

    /** A post left "publish": links to it stop counting as internal links. */
    public static function unlink_target( int $post_id ): void {
        global $wpdb;
        if ( $post_id <= 0 ) {
            return;
        }
        $table = $wpdb->prefix . "jnk_linkweave_links";
        $wpdb->query( $wpdb->prepare( "UPDATE IGNORE %i SET target_post_id = 0, is_broken = 1 WHERE target_post_id = %d", $table, $post_id ) );
        // Rows IGNORE skipped duplicate a row that is already unresolved.
        $wpdb->delete( $table, [ "target_post_id" => $post_id ], [ "%d" ] );
        self::bump_graph_version();
    }

    /** Drop a deleted or trashed post's outgoing links from the graph. */
    public static function forget_post_links( $post_id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . "jnk_linkweave_links", [ "source_post_id" => (int) $post_id ], [ "%d" ] );
        self::bump_graph_version();
    }

    /**
     * Anything that changes the index or the link graph changes suggestions (the
     * already-linked penalty, the orphan boost, the candidate set), so the cached
     * suggestion lists fold this counter into their key.
     */
    public static function bump_graph_version(): void {
        update_option( "jnk_linkweave_graph_version", (int) get_option( "jnk_linkweave_graph_version", 0 ) + 1, false );
    }

    /** @return array<string,object> "source|url|anchor" => old row */
    private static function previous_verdicts( $rows ): array {
        $previous = [];
        foreach ( (array) $rows as $old ) {
            $previous[ (int) $old->source_post_id . "|" . $old->target_url . "|" . $old->anchor_text ] = $old;
        }
        return $previous;
    }

    /**
     * Is this href a front-end page link worth putting in the graph?
     *
     * Content is rendered in background requests (WP-Cron, the Action Scheduler
     * queue runner on admin-ajax.php, a REST call). Blocks that build links from the
     * current request, such as a sortable table header, then produce hrefs like
     * wp-cron.php?doing_wp_cron=… or admin-ajax.php?action=…&nonce=…, and those were
     * stored as "internal links" and shown in the reports with a live nonce. Only
     * real front-end URLs are kept: nothing under wp-admin, no wp-cron, admin-ajax,
     * wp-login, xmlrpc or REST URL, and no URL carrying a nonce.
     *
     * @param string $url An href, already entity-decoded.
     */
    public static function is_linkable_url( string $url ): bool {
        $url = trim( $url );
        if ( "" === $url || 0 === strpos( $url, "#" ) ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }
        $scheme = strtolower( (string) ( $parts["scheme"] ?? "" ) );
        if ( "" !== $scheme && ! in_array( $scheme, [ "http", "https" ], true ) ) {
            return false;
        }
        $path = strtolower( rawurldecode( (string) ( $parts["path"] ?? "" ) ) );
        if ( preg_match( "~(^|/)(wp-admin|wp-json)(/|$)~", $path )
            || preg_match( "~(^|/)(wp-cron|wp-login|xmlrpc|admin-ajax|admin-post|wp-signup|wp-activate)\\.php$~", $path )
        ) {
            return false;
        }
        if ( ! empty( $parts["query"] ) ) {
            wp_parse_str( (string) $parts["query"], $query );
            foreach ( array_keys( (array) $query ) as $key ) {
                $key = strtolower( (string) $key );
                if ( in_array( $key, [ "doing_wp_cron", "rest_route", "_wpnonce", "nonce", "_ajax_nonce", "preview_nonce" ], true )
                    || false !== strpos( $key, "nonce" )
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Delete stored rows that is_linkable_url() now rejects. Earlier versions wrote
     * them; a rebuild clears them too, but reports should not wait for one.
     */
    public static function purge_unlinkable_rows(): int {
        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";
        // Rows with no URL were written by the editor's insert call before the post
        // was saved; the graph itself always stores the URL. They may describe
        // content that was never saved, so they go too.
        $gone  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE target_url = ''", $table ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, target_url FROM %i WHERE target_url <> ''", $table ) );
        foreach ( (array) $rows as $row ) {
            if ( ! self::is_linkable_url( html_entity_decode( (string) $row->target_url, ENT_QUOTES | ENT_HTML5, "UTF-8" ) ) ) {
                $gone += (int) $wpdb->delete( $table, [ "id" => (int) $row->id ], [ "%d" ] );
            }
        }
        if ( $gone ) {
            self::bump_graph_version();
        }
        return $gone;
    }

    /** One-time clean-up on the first admin request after updating. */
    public const JUNK_PURGE_OPTION = "jnk_linkweave_links_filter_v2";

    public static function maybe_purge_unlinkable_rows(): void {
        if ( get_option( self::JUNK_PURGE_OPTION ) ) {
            return;
        }
        update_option( self::JUNK_PURGE_OPTION, time(), false );
        self::purge_unlinkable_rows();
    }

    /** Parse a post's rendered content and insert its outgoing internal links. */
    private static function write_links_for_post( int $source_id, array $previous ): int {
        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";
        $post  = get_post( $source_id );
        if ( ! $post ) {
            return 0;
        }
        $host = wp_parse_url( home_url( "/" ), PHP_URL_HOST );

        $content = (string) $post->post_content;
        if ( false !== strpos( $content, "<!-- wp:" ) && function_exists( "do_blocks" ) ) {
            $content = do_blocks( $content );
        }
        if ( ! preg_match_all( "~<a\\b[^>]*href=([\"\\'])(.*?)\\1[^>]*>(.*?)</a>~is", $content, $matches, PREG_SET_ORDER ) ) {
            return 0;
        }
        $written = 0;
        foreach ( $matches as $m ) {
            // Hrefs in rendered HTML are entity-encoded ("&#038;"); store the real URL.
            $url      = trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, "UTF-8" ) );
            $anchor   = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES | ENT_HTML5, "UTF-8" ) );
            $url_host = wp_parse_url( $url, PHP_URL_HOST );
            if ( $url_host && $host && strtolower( $url_host ) !== strtolower( $host ) ) {
                continue;
            }

            // In-page anchors, mailto:/tel:, javascript: handlers, and admin, cron,
            // AJAX, login, REST or nonce-bearing URLs are not page links.
            if ( ! self::is_linkable_url( $url ) ) {
                continue;
            }
            $target_id = url_to_postid( $url );
            if ( $target_id && $target_id === $source_id ) {
                continue; // self-link
            }
            // A draft, pending, scheduled, private or trashed post is a 404 for
            // visitors, so a link to it is not an internal link yet. It is stored
            // as unresolved and broken, the same verdict the daily scan reaches, so
            // the counts do not jump an hour later with no edit. adopt_links_to()
            // resolves it when the target is published.
            $unpublished = $target_id > 0 ? ! self::is_public_target( $target_id ) : self::names_unpublished_post( $url );
            if ( $unpublished ) {
                $target_id = 0;
            }

            // A same-host href that resolves to no post is not noise to be dropped —
            // it IS a dead internal link, which is the entire point of the broken-link
            // report. Record it with target_post_id 0 and keep the URL so the scan and
            // the report have something to work with.
            $absolute      = $url_host ? $url : home_url( $url );
            $stored_url    = mb_substr( $absolute, 0, 500 );
            $stored_anchor = mb_substr( $anchor, 0, 500 );

            $is_broken    = 0;
            $last_checked = null;
            $old          = $previous[ $source_id . "|" . $stored_url . "|" . $stored_anchor ] ?? null;
            if ( $old ) {
                $last_checked = $old->last_checked;
                // A dead URL that now resolves to a post (the page was created or
                // restored) is no longer broken; anything else keeps its verdict
                // until the next scan looks again.
                $now_resolves = (int) $target_id > 0 && 0 === (int) $old->target_post_id;
                $is_broken    = $now_resolves ? 0 : (int) $old->is_broken;
            }
            if ( $unpublished ) {
                $is_broken = 1;
            }

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO %i (source_post_id, target_post_id, target_url, anchor_text, is_broken, last_checked, created_at)
                 VALUES (%d, %d, %s, %s, %d, " . ( null === $last_checked ? "NULL" : "%s" ) . ", %s)
                 ON DUPLICATE KEY UPDATE anchor_text = VALUES(anchor_text), target_url = VALUES(target_url)",
                array_merge(
                    [ $table, $source_id, $target_id, $stored_url, $stored_anchor, $is_broken ],
                    null === $last_checked ? [] : [ (string) $last_checked ],
                    [ current_time( "mysql", true ) ]
                )
            ) );
            $written++;
        }
        return $written;
    }

    /**
     * HEAD-check every recorded internal link. Marks 404s as broken.
     */
    public static function scan_broken_links(): int {
        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, source_post_id, target_post_id, target_url FROM %i", $table ) );

        $broken = 0;
        foreach ( (array) $rows as $row ) {
            // Resolved links are checked at their current permalink (it may have moved
            // since the anchor was written); unresolved ones at the URL as authored.
            $url = (int) $row->target_post_id > 0
                ? get_permalink( (int) $row->target_post_id )
                : (string) $row->target_url;

            $is_break = ! $url;
            if ( ! $is_break ) {
                $resp = wp_remote_head( $url, [ "timeout" => 5, "redirection" => 3 ] );
                $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
                $is_break = ( $code >= 400 || 0 === $code );
            }
            $wpdb->update(
                $table,
                [
                    "is_broken"    => $is_break ? 1 : 0,
                    "last_checked" => current_time( "mysql", true ),
                ],
                [ "id" => (int) $row->id ],
                [ "%d", "%s" ],
                [ "%d" ]
            );
            if ( $is_break ) {
                $broken++;
                do_action( "jnk_linkweave_broken_link_found", (int) $row->source_post_id, $url );
            }
        }

        // Record when we last looked. Without it the report cannot tell "no broken
        // links" from "this has never run", and both render as "Nothing to show."
        update_option(
            "jnk_linkweave_last_broken_scan",
            [
                "finished_at" => current_time( "mysql", true ),
                "checked"     => count( (array) $rows ),
                "broken"      => $broken,
            ],
            false
        );

        return $broken;
    }
}
