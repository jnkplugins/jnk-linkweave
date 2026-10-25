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
        add_action( "action_scheduler_failed_action", [ __CLASS__, "resume_after_failed_step" ], 10, 1 );
        add_action( "action_scheduler_failed_execution", [ __CLASS__, "resume_after_failed_step" ], 10, 1 );
        add_action( "action_scheduler_unexpected_shutdown", [ __CLASS__, "resume_after_failed_step" ], 10, 1 );
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

    public const SCAN_NOW_HOOK       = "jnk_linkweave_scan_now";
    /** Pre-1.15 "a scan is queued" flag. Only deleted now; the scan state replaced it. */
    public const SCAN_PENDING_OPTION = "jnk_linkweave_broken_scan_pending";
    /** The scan in progress: { started_at, step_at, graph, last_id, checked, broken, unreachable, total }. */
    public const SCAN_STATE_OPTION   = "jnk_linkweave_broken_scan_state";
    private const SCAN_LOCK_OPTION   = "jnk_linkweave_broken_scan_lock";
    /** Seconds one background step may spend checking links. Far below the 300 s Action Scheduler limit. */
    private const SCAN_STEP_SECONDS  = 20;
    /** After this many requests in a row to the site itself got no answer, stop asking it. */
    private const SELF_DOWN_AFTER = 3;
    /** A background step that finds the scan busy tries again after this long. */
    private const SCAN_BUSY_RETRY_SECONDS = 30;
    /** A scan with no step for this long has stalled (its job died); a new one may replace it. */
    private const SCAN_STALL_SECONDS = 10 * MINUTE_IN_SECONDS;

    /** is_broken values. 2 is "did not answer": never shown as missing, never bulk-removed. */
    public const LINK_OK          = 0;
    public const LINK_BROKEN      = 1;
    public const LINK_UNREACHABLE = 2;

    /**
     * Start an on-demand broken-link scan ("Check all links now").
     *
     * The scan used to be one background job that HEAD-checked every link in a row.
     * On a site with ~1,100 links it ran past Action Scheduler's 300 s limit, was
     * marked failed, never recorded a result, and the button looked as if it had
     * worked (seen on a live site, 24 Oct 2026). It now runs in short steps: the
     * open report page drives them (scan_step via REST) and a background job keeps
     * going when the page is closed.
     *
     * @return bool Always true: the scan runs in steps, never inline here.
     */
    public static function queue_scan_now(): bool {
        self::start_scan();
        self::schedule_scan_step();
        return true;
    }

    /** Begin a scan unless one is already making progress. Returns its state. */
    public static function start_scan( bool $graph_done = false ): array {
        $state = self::scan_state();
        if ( $state && ! self::scan_stalled( $state ) ) {
            return $state;
        }
        // A scan that stalled (its background job never came back) carries on from
        // where it stopped, so "Check all links now" finishes it instead of
        // starting 10,000 links again. One older than a day starts over.
        if ( $state && time() - (int) $state["started_at"] < DAY_IN_SECONDS ) {
            delete_option( self::SCAN_LOCK_OPTION );
            self::save_scan_state( $state );
            return self::scan_state();
        }
        delete_option( self::SCAN_PENDING_OPTION );
        delete_option( self::SCAN_LOCK_OPTION );
        $state = [
            "started_at"  => time(),
            "step_at"     => time(),
            "graph"       => $graph_done,
            "last_id"     => 0,
            "checked"     => 0,
            "broken"      => 0,
            "unreachable" => 0,
            "total"       => $graph_done ? self::count_links() : 0,
        ];
        update_option( self::SCAN_STATE_OPTION, $state, false );
        return $state;
    }

    /**
     * Queue the next background step (Action Scheduler when present, WP-Cron otherwise).
     *
     * Only a PENDING step counts as already queued. Called from inside the running
     * step, as_next_scheduled_action() also sees that step itself (in progress) and
     * returns true, so the scan stopped after one step once the page was closed.
     */
    public static function schedule_scan_step( int $delay = 0 ): void {
        if ( function_exists( "as_enqueue_async_action" ) ) {
            $pending = function_exists( "as_get_scheduled_actions" )
                ? as_get_scheduled_actions( [ "hook" => self::SCAN_NOW_HOOK, "group" => "jnk-linkweave", "status" => "pending", "per_page" => 1 ], "ids" )
                : [];
            if ( ! $pending ) {
                if ( $delay > 0 && function_exists( "as_schedule_single_action" ) ) {
                    as_schedule_single_action( time() + $delay, self::SCAN_NOW_HOOK, [], "jnk-linkweave" );
                } else {
                    as_enqueue_async_action( self::SCAN_NOW_HOOK, [], "jnk-linkweave" );
                }
            }
            return;
        }
        if ( ! wp_next_scheduled( self::SCAN_NOW_HOOK ) ) {
            wp_schedule_single_event( time() + $delay, self::SCAN_NOW_HOOK );
        }
    }

    /**
     * Background job: one step, then queue the next while links are left.
     *
     * A step that finds another one running (the open report page, or a step
     * whose PHP worker was killed and left its lock) tries again in a while, not
     * at once: an immediate retry ran straight away, found the lock again, and
     * made about 3,000 Scheduled Actions in two minutes (QA 25 Oct 2026).
     */
    public static function scan_now(): void {
        /** Filter: jnk_linkweave_scan_step_seconds. How long one background step checks links (host time limits). */
        $progress = self::scan_step( (float) apply_filters( "jnk_linkweave_scan_step_seconds", self::SCAN_STEP_SECONDS ) );
        if ( self::scan_state() ) {
            self::schedule_scan_step( empty( $progress["busy"] ) ? 0 : self::SCAN_BUSY_RETRY_SECONDS );
        }
    }

    /**
     * A background step that died (PHP worker killed, out of memory, an error)
     * queued no successor, so the scan sat still until someone opened the report.
     * Action Scheduler tells us when it gives up on a step; queue the next one.
     *
     * @param int $action_id The action Action Scheduler marked failed.
     */
    public static function resume_after_failed_step( $action_id ): void {
        if ( ! self::scan_state() || ! class_exists( "ActionScheduler" ) ) {
            return;
        }
        try {
            $action = \ActionScheduler::store()->fetch_action( (int) $action_id );
        } catch ( \Throwable $e ) {
            return;
        }
        if ( $action && self::SCAN_NOW_HOOK === $action->get_hook() ) {
            self::schedule_scan_step( self::SCAN_BUSY_RETRY_SECONDS );
        }
    }

    /**
     * Check links for up to $seconds, then save where the scan got to. A step
     * that finds another step running returns straight away with "busy".
     *
     * @return array Progress (see scan_progress()) plus "busy".
     */
    public static function scan_step( float $seconds = self::SCAN_STEP_SECONDS ): array {
        $state = self::scan_state();
        if ( ! $state ) {
            return self::scan_progress() + [ "busy" => false ];
        }
        // add_option() is atomic: only one request gets the lock. A lock older than
        // two minutes belongs to a step that died.
        if ( ! add_option( self::SCAN_LOCK_OPTION, (string) time(), "", false ) ) {
            if ( time() - (int) get_option( self::SCAN_LOCK_OPTION, 0 ) < 2 * MINUTE_IN_SECONDS ) {
                return self::scan_progress() + [ "busy" => true ];
            }
            update_option( self::SCAN_LOCK_OPTION, (string) time(), false );
        }

        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";
        $until = microtime( true ) + $seconds;
        try {
            if ( empty( $state["graph"] ) ) {
                self::rebuild_links_graph();
                $state            = self::scan_state() ?: $state;
                $state["graph"]   = true;
                $state["last_id"] = 0;
                $state["total"]   = self::count_links();
                self::save_scan_state( $state );
            }
            $done = false;
            // At least one link per step, however long the graph rebuild took, so
            // every step moves the scan forward.
            do {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, source_post_id, target_post_id, target_url, is_broken FROM %i WHERE id > %d ORDER BY id ASC LIMIT 25",
                    $table,
                    (int) $state["last_id"]
                ) );
                if ( ! $rows ) {
                    $done = true;
                    break;
                }
                foreach ( $rows as $row ) {
                    $status = self::scan_verdict( $row, $state );
                    $wpdb->update(
                        $table,
                        [ "is_broken" => $status, "last_checked" => current_time( "mysql", true ) ],
                        [ "id" => (int) $row->id ],
                        [ "%d", "%s" ],
                        [ "%d" ]
                    );
                    $state["last_id"] = (int) $row->id;
                    $state["checked"]++;
                    if ( self::LINK_BROKEN === $status ) {
                        $state["broken"]++;
                        do_action( "jnk_linkweave_broken_link_found", (int) $row->source_post_id, (string) $row->target_url );
                    } elseif ( self::LINK_UNREACHABLE === $status ) {
                        $state["unreachable"]++;
                    }
                    if ( microtime( true ) >= $until ) {
                        break 2;
                    }
                }
            } while ( microtime( true ) < $until );
            if ( $done ) {
                self::finish_scan( $state );
            } else {
                self::save_scan_state( $state );
            }
        } finally {
            delete_option( self::SCAN_LOCK_OPTION );
        }
        return self::scan_progress() + [ "busy" => false ];
    }

    /** True while a scan has not finished and is still making progress. */
    public static function scan_pending(): bool {
        $state = self::scan_state();
        return (bool) $state && ! self::scan_stalled( $state );
    }

    /**
     * What the report shows about scanning: the scan in progress (if any), the
     * last finished one, and how many links have never been checked at all.
     */
    public static function scan_progress(): array {
        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";
        $state = self::scan_state();
        $last  = get_option( "jnk_linkweave_last_broken_scan", [] );
        return [
            "running"       => (bool) $state && ! self::scan_stalled( $state ),
            "stalled"       => (bool) $state && self::scan_stalled( $state ),
            "checked"       => $state ? (int) $state["checked"] : 0,
            "total"         => $state ? (int) $state["total"] : 0,
            "started_at"    => $state ? gmdate( "Y-m-d H:i:s", (int) $state["started_at"] ) : null,
            "last_scan"     => is_array( $last ) ? $last : [],
            "links"         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $table ) ),
            "never_checked" => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE last_checked IS NULL", $table ) ),
        ];
    }

    private static function scan_state(): array {
        $state = get_option( self::SCAN_STATE_OPTION, [] );
        return is_array( $state ) && isset( $state["started_at"] ) ? $state : [];
    }

    private static function save_scan_state( array $state ): void {
        $state["step_at"] = time();
        update_option( self::SCAN_STATE_OPTION, $state, false );
    }

    private static function scan_stalled( array $state ): bool {
        return ( time() - (int) ( $state["step_at"] ?? 0 ) ) > self::SCAN_STALL_SECONDS;
    }

    private static function finish_scan( array $state ): void {
        // Record when we last looked. Without it the report cannot tell "no broken
        // links" from "this has never run", and both render as "Nothing to show."
        update_option(
            "jnk_linkweave_last_broken_scan",
            [
                "started_at"  => gmdate( "Y-m-d H:i:s", (int) $state["started_at"] ),
                "finished_at" => current_time( "mysql", true ),
                "checked"     => (int) $state["checked"],
                "broken"      => (int) $state["broken"],
                "unreachable" => (int) $state["unreachable"],
            ],
            false
        );
        delete_option( self::SCAN_STATE_OPTION );
    }

    private static function count_links(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $wpdb->prefix . "jnk_linkweave_links" ) );
    }

    /**
     * One link's verdict. A link that resolves to a post on this site is judged by
     * the post (published = fine) with no HTTP request: a loopback request to a
     * heavy page used to time out and get the link reported as "Page missing".
     */
    public static function link_status( object $row ): int {
        $target = (int) $row->target_post_id;
        if ( $target > 0 ) {
            return get_post( $target ) && self::is_public_target( $target ) ? self::LINK_OK : self::LINK_BROKEN;
        }
        $url = (string) $row->target_url;
        if ( "" === $url ) {
            return self::LINK_BROKEN;
        }
        return self::url_status( $url );
    }

    /**
     * A scanned link's verdict, with what this scan already learnt.
     *
     * A link to this site that names no live post is first judged from the
     * database (a draft, a trashed post, ?p= of a post that is gone). Only what
     * the database cannot tell needs a request back to the site. On a host whose
     * requests to itself fail, each of those took 25 s and ended "did not
     * answer", so a scan of 7,000 links took hours and reported no missing pages
     * (QA 25 Oct 2026). Once the site has not answered itself a few times in a
     * row, the rest of the scan skips those requests. A link already known to be
     * missing stays missing when the site cannot be asked. The same URL is asked
     * once per scan.
     *
     * @param object $row   id, target_post_id, target_url, is_broken
     * @param array  $state The scan state; counts "self_fail" in a row.
     */
    private static function scan_verdict( object $row, array &$state ): int {
        $url = (string) $row->target_url;
        if ( (int) $row->target_post_id > 0 || "" === $url ) {
            return self::link_status( $row );
        }
        $local = self::local_status( $url );
        if ( null !== $local ) {
            return $local;
        }
        $own = self::is_own_site( $url );
        if ( ! $own || (int) ( $state["self_fail"] ?? 0 ) < self::SELF_DOWN_AFTER ) {
            global $wpdb;
            $seen = $wpdb->get_var( $wpdb->prepare(
                "SELECT is_broken FROM %i WHERE target_url = %s AND target_post_id = 0 AND id < %d AND last_checked >= %s LIMIT 1",
                $wpdb->prefix . "jnk_linkweave_links",
                $url,
                (int) $row->id,
                gmdate( "Y-m-d H:i:s", (int) $state["started_at"] )
            ) );
            if ( null !== $seen ) {
                return (int) $seen;
            }
            $code = self::http_code( $url );
            if ( ! $own ) {
                return self::code_status( $code );
            }
            if ( 0 !== $code ) {
                $state["self_fail"] = 0;
                return self::code_status( $code );
            }
            $state["self_fail"] = (int) ( $state["self_fail"] ?? 0 ) + 1;
        }
        return self::LINK_BROKEN === (int) ( $row->is_broken ?? 0 ) ? self::LINK_BROKEN : self::LINK_UNREACHABLE;
    }

    /**
     * Same-site URL that names no live post: what the database says, or null when
     * only a request can tell (an archive, a file, another app on the domain).
     */
    private static function local_status( string $url ): ?int {
        if ( ! self::is_own_site( $url ) ) {
            return null;
        }
        $id = url_to_postid( $url );
        if ( $id > 0 ) {
            return self::is_public_target( $id ) ? self::LINK_OK : self::LINK_BROKEN;
        }
        return self::names_unpublished_post( $url ) ? self::LINK_BROKEN : null;
    }

    /** Same host AND port as the site (the graph ignores the port; requests do not). */
    private static function is_own_site( string $url ): bool {
        $home = wp_parse_url( home_url( "/" ) );
        $link = wp_parse_url( $url );
        if ( ! is_array( $home ) || ! is_array( $link ) ) {
            return false;
        }
        if ( empty( $link["host"] ) ) {
            return true;
        }
        return strtolower( (string) $link["host"] ) === strtolower( (string) ( $home["host"] ?? "" ) )
            && (int) ( $link["port"] ?? 0 ) === (int) ( $home["port"] ?? 0 );
    }

    /**
     * Ask a URL whether it is there. HEAD first; on no answer, a server error or a
     * refused HEAD, try GET with a longer wait. Only a real "not found" style answer
     * is broken. A timeout, 5xx, 401/403 or 429 is "did not answer": the page may
     * well be there, so it is never called missing and never bulk-removed.
     */
    public static function url_status( string $url ): int {
        return self::code_status( self::http_code( $url ) );
    }

    /** The answer's status code; 0 when nothing answered. */
    private static function http_code( string $url ): int {
        $args = [ "timeout" => 10, "redirection" => 3 ];
        $resp = wp_remote_head( $url, $args );
        $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
        if ( 0 === $code || $code >= 500 || in_array( $code, [ 401, 403, 405, 429 ], true ) ) {
            $resp = wp_remote_get( $url, [ "timeout" => 15, "redirection" => 3, "limit_response_size" => 8192 ] );
            $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
        }
        return $code;
    }

    private static function code_status( int $code ): int {
        if ( 0 === $code || $code >= 500 || in_array( $code, [ 401, 403, 429 ], true ) ) {
            return self::LINK_UNREACHABLE;
        }
        return $code >= 400 ? self::LINK_BROKEN : self::LINK_OK;
    }

    public static function run_daily_scan(): void {
        $settings = get_option( "jnk_linkweave_settings", [] );
        $freq     = (string) ( $settings["broken_link_check_freq"] ?? "daily" );

        self::rebuild_links_graph();

        if ( "never" !== $freq ) {
            // Same steps as "Check all links now": as far as one step gets here,
            // the rest in background jobs.
            self::start_scan( true );
            self::scan_now();
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

        // TRUNCATE restarts the row ids, so a scan in progress starts over rather
        // than skip every row below the id it had reached.
        $state = self::scan_state();
        if ( $state && ! empty( $state["graph"] ) ) {
            $state = array_merge( $state, [ "last_id" => 0, "checked" => 0, "broken" => 0, "unreachable" => 0, "total" => $written ] );
            self::save_scan_state( $state );
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
     * same-site URL's last path segment name a post that is not live yet, or one
     * in the trash? Trashing renames the slug to "<slug>__trashed", and WordPress
     * then redirects the old address to a post with a similar name, so the scan
     * called links to trashed posts fine (QA 25 Oct 2026).
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
            "post_name__in"    => [ $slug, $slug . "__trashed" ],
            "post_type"        => array_values( get_post_types( [ "public" => true ] ) ),
            "post_status"      => [ "draft", "pending", "future", "private", "trash" ],
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
     * Check every recorded link now, in this request (WP-CLI, tests). The web
     * paths use start_scan() + scan_step() instead.
     *
     * @return int Number of broken links found.
     */
    public static function scan_broken_links(): int {
        delete_option( self::SCAN_STATE_OPTION );
        self::start_scan( true );
        do {
            $p = self::scan_step( 3600 );
        } while ( $p["running"] && empty( $p["busy"] ) );
        $last = get_option( "jnk_linkweave_last_broken_scan", [] );
        return (int) ( is_array( $last ) ? ( $last["broken"] ?? 0 ) : 0 );
    }
}
