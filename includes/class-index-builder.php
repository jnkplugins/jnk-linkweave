<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom-table data layer; direct $wpdb is required, results cached where practical.

class Index_Builder {

    private const TOP_TERMS    = 200;
    public const REBUILD_HOOK  = "jnk_linkweave_rebuild_index";
    public const REINDEX_HOOK  = "jnk_linkweave_reindex_post";

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . "jnk_linkweave_index";
    }

    public static function register_hooks(): void {
        add_action( self::REINDEX_HOOK, [ __CLASS__, "index_post" ], 10, 1 );
        add_action( self::REBUILD_HOOK, [ __CLASS__, "rebuild" ], 10, 0 );
        // Refresh each rule's applied_count once the corpus is rebuilt (replaces
        // the old per-render counter). Rule CRUD recomputes on its own.
        add_action( "jnk_linkweave_index_complete", [ Rules_Engine::class, "recompute_applied_counts" ], 10, 0 );
        // A "Max per site" budget is shared out per post; a newly published post
        // needs its share worked out, or it would never receive the link.
        add_action( self::REINDEX_HOOK, [ Rules_Engine::class, "maybe_recompute_for_site_caps" ], 20, 0 );
        Rules_Engine::register_recount_hooks();
    }

    /**
     * Drop index rows for post types that are no longer indexed. Unticking a post
     * type used to leave its rows behind, so the dashboard count and the index
     * disagreed and those posts kept feeding the corpus statistics.
     */
    public static function purge_excluded_types( array $post_types ): int {
        global $wpdb;
        $post_types = array_values( array_filter( array_map( "sanitize_key", $post_types ) ) );
        if ( empty( $post_types ) ) {
            return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i", self::table() ) );
        }
        $in = implode( ",", array_fill( 0, count( $post_types ), "%s" ) );
        return (int) $wpdb->query( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- IN() list built from %s placeholders.
            "DELETE FROM %i WHERE post_type NOT IN ($in)",
            array_merge( [ self::table() ], $post_types )
        ) );
    }

    /**
     * Rows actually in the index. The dashboard used to print the count saved by the
     * last full rebuild, which drifted as soon as a post was indexed on publish or a
     * post type was unticked.
     */
    public static function indexed_count(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", self::table() ) );
    }

    public static function schedule_reindex( int $post_id ): void {
        if ( $post_id <= 0 ) {
            return;
        }
        if ( function_exists( "as_enqueue_async_action" ) ) {
            as_enqueue_async_action( self::REINDEX_HOOK, [ $post_id ], "jnk-linkweave" );
            return;
        }
        if ( ! wp_next_scheduled( self::REINDEX_HOOK, [ $post_id ] ) ) {
            wp_schedule_single_event( time() + 5, self::REINDEX_HOOK, [ $post_id ] );
        }
    }

    /** Option that holds the rebuild status the dashboard polls. */
    public const STATUS_OPTION = "jnk_linkweave_index_status";
    /** Set on activation; the next admin page load starts the first index. */
    public const FIRST_INDEX_OPTION = "jnk_linkweave_first_index_pending";
    /** A short-lived lock so two rebuilds never run at once (queue + "Run now"). */
    private const LOCK_OPTION = "jnk_linkweave_rebuild_lock";

    /** True while rebuild() is running in this request (read by the shutdown handler). */
    private static bool $in_rebuild = false;
    private static bool $shutdown_registered = false;

    public static function schedule_rebuild(): void {
        // Synchronously flag the rebuild as queued BEFORE the async job is
        // queued. Otherwise the first /index/status poll (which fires within
        // milliseconds) reads the previous, idle option and the dashboard
        // declares "Done (0)" while the job is still pending. The async
        // rebuild() then overwrites this with live progress / the final count.
        self::mark_rebuild_pending();

        if ( function_exists( "as_enqueue_async_action" ) ) {
            if ( ! self::has_pending_action() ) {
                as_enqueue_async_action( self::REBUILD_HOOK, [], "jnk-linkweave" );
            }
            return;
        }
        if ( ! wp_next_scheduled( self::REBUILD_HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::REBUILD_HOOK );
        }
    }

    /** Is a rebuild waiting in Action Scheduler or WP-Cron? */
    public static function has_pending_action(): bool {
        if ( function_exists( "as_next_scheduled_action" ) && did_action( "action_scheduler_init" ) ) {
            if ( false !== as_next_scheduled_action( self::REBUILD_HOOK, [], "jnk-linkweave" ) ) {
                return true;
            }
        }
        return false !== wp_next_scheduled( self::REBUILD_HOOK );
    }

    /** Drop any queued rebuild: used when the rebuild runs right now instead. */
    public static function unschedule_pending(): void {
        if ( function_exists( "as_unschedule_all_actions" ) && did_action( "action_scheduler_init" ) ) {
            as_unschedule_all_actions( self::REBUILD_HOOK, [], "jnk-linkweave" );
        }
        wp_clear_scheduled_hook( self::REBUILD_HOOK );
    }

    /**
     * Posts the index covers: published posts of the indexed post types.
     *
     * @return int[]
     */
    public static function corpus_ids(): array {
        $settings   = get_option( "jnk_linkweave_settings", [] );
        $post_types = (array) apply_filters(
            "jnk_linkweave_suggestion_post_types",
            $settings["post_types"] ?? [ "post", "page" ]
        );
        return array_map( "intval", get_posts( [
            "post_type"        => $post_types,
            "post_status"      => "publish",
            "posts_per_page"   => -1,
            "fields"           => "ids",
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- index the canonical published corpus; other plugins' query filters must not alter what we index.
            "suppress_filters" => true,
        ] ) );
    }

    /**
     * Count the posts a rebuild will index and record a "queued" status so the
     * dashboard can tell "waiting for WordPress to start it" from "reading posts".
     */
    public static function mark_rebuild_pending(): void {
        $total = count( self::corpus_ids() );
        $now   = current_time( "mysql", true );
        update_option( self::STATUS_OPTION, [
            "is_running"  => true,
            "state"       => "queued",
            "phase"       => "",
            "progress"    => 0.0,
            "total"       => $total,
            "read"        => 0,
            "indexed"     => 0,
            "queued_at"   => $now,
            "started_at"  => null,
            "updated_at"  => $now,
            "finished_at" => null,
            "error"       => "",
        ], false );
    }

    /**
     * Merge $changes into the stored status. Keeps started_at / queued_at, which
     * the old progress writes overwrote with "now" on every batch.
     */
    private static function write_status( array $changes ): void {
        $status = get_option( self::STATUS_OPTION, [] );
        $status = is_array( $status ) ? $status : [];
        $status = array_merge( $status, $changes, [ "updated_at" => current_time( "mysql", true ) ] );
        update_option( self::STATUS_OPTION, $status, false );
    }

    /** Seconds without progress before a run counts as stalled. */
    public static function stall_seconds(): int {
        return max( 30, (int) apply_filters( "jnk_linkweave_index_stall_seconds", 5 * MINUTE_IN_SECONDS ) );
    }

    /**
     * Sites with at most this many posts are indexed inside the click itself:
     * a few seconds of work, with no wait for a background queue.
     */
    public static function inline_max(): int {
        return max( 0, (int) apply_filters( "jnk_linkweave_inline_rebuild_max", 500 ) );
    }

    /**
     * The status the dashboard shows, with a stalled run detected.
     *
     * state: idle | queued | running | stalled | error
     */
    public static function status(): array {
        $status = get_option( self::STATUS_OPTION, [] );
        $status = wp_parse_args( is_array( $status ) ? $status : [], [
            "is_running"  => false,
            "state"       => "",
            "phase"       => "",
            "progress"    => 0.0,
            "total"       => 0,
            "read"        => 0,
            "indexed"     => 0,
            "queued_at"   => null,
            "started_at"  => null,
            "updated_at"  => null,
            "finished_at" => null,
            "error"       => "",
        ] );
        if ( "" === (string) $status["state"] ) {
            // Status saved by 1.10 and older: no state field.
            $status["state"] = empty( $status["is_running"] ) ? "idle" : "running";
        }

        if ( ! empty( $status["is_running"] ) ) {
            $last = (string) ( $status["updated_at"] ?: ( $status["started_at"] ?: $status["queued_at"] ) );
            $age  = "" !== $last ? time() - (int) strtotime( $last . " UTC" ) : PHP_INT_MAX;
            // A queued job only stalls once nothing could start it any more; a running
            // one stalls when it stops reporting progress (a fatal error, a timeout).
            if ( $age > self::stall_seconds() && ( "running" === $status["state"] || ! self::has_pending_action() || $age > 2 * self::stall_seconds() ) ) {
                if ( "" === (string) $status["error"] ) {
                    $status["error"] = "running" === $status["state"] ? "no_progress" : "never_started";
                }
                $status["is_running"] = false;
                $status["state"]      = "stalled";
            }
        }
        if ( empty( $status["is_running"] ) ) {
            // Once idle, report what is in the index, not the count the last rebuild saved.
            $status["indexed"] = self::indexed_count();
        }
        $status["total"]   = (int) $status["total"];
        $status["read"]    = (int) $status["read"];
        $status["indexed"] = (int) $status["indexed"];
        return $status;
    }

    /**
     * Set on activation. Activation runs before Action Scheduler is ready in that
     * request, so the queue is filled on the next admin page load instead.
     */
    public static function flag_first_index(): void {
        update_option( self::FIRST_INDEX_OPTION, 1, false );
    }

    /** admin_init: start the very first index once, when the index is empty. */
    public static function maybe_start_first_index(): void {
        if ( ! get_option( self::FIRST_INDEX_OPTION ) ) {
            return;
        }
        delete_option( self::FIRST_INDEX_OPTION );
        if ( self::indexed_count() > 0 ) {
            return;
        }
        $status = self::status();
        if ( ! empty( $status["is_running"] ) ) {
            return;
        }
        self::schedule_rebuild();
    }

    /**
     * Run the rebuild in this request (the "Run now" button, and small sites).
     * Any queued copy is dropped first so it does not run a second time.
     */
    public static function rebuild_now(): int {
        self::unschedule_pending();
        if ( function_exists( "set_time_limit" ) ) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a full read of a large site can outlast the default limit.
            @set_time_limit( 0 );
        }
        return self::rebuild();
    }

    /** Record that the rebuild died, so the dashboard stops saying "running". */
    private static function record_failure( string $message ): void {
        self::write_status( [
            "is_running"  => false,
            "state"       => "error",
            "error"       => $message,
            "finished_at" => current_time( "mysql", true ),
        ] );
        delete_option( self::LOCK_OPTION );
    }

    /** Shutdown handler: a fatal error or a timeout inside rebuild() lands here. */
    public static function on_shutdown(): void {
        if ( ! self::$in_rebuild ) {
            return;
        }
        self::$in_rebuild = false;
        $err     = error_get_last();
        $message = "stopped";
        if ( is_array( $err ) && in_array( (int) $err["type"], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) {
            $message = substr( (string) $err["message"], 0, 300 );
        }
        self::record_failure( $message );
    }

        /**
     * Index one post against the most recent corpus IDF (refreshed on full rebuilds).
     */
    public static function index_post( int $post_id ): bool {
        $post = get_post( $post_id );
        if ( ! $post || "publish" !== $post->post_status ) {
            self::delete( $post_id );
            return false;
        }

        if ( apply_filters( "jnk_linkweave_skip_indexing_post", false, $post_id, $post ) ) {
            return false;
        }

        $settings   = get_option( "jnk_linkweave_settings", [] );
        $post_types = (array) apply_filters(
            "jnk_linkweave_suggestion_post_types",
            $settings["post_types"] ?? [ "post", "page" ]
        );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            self::delete( $post_id );
            return false;
        }

        $min_words = (int) apply_filters(
            "jnk_linkweave_min_post_length",
            (int) ( $settings["min_post_length"] ?? 40 )
        );
        $language = (string) ( $settings["language"] ?? "en" );

        $built = self::vector_for_content( (string) $post->post_content, $language );
        if ( $built["words"] < $min_words ) {
            self::delete( $post_id );
            return false;
        }
        $vector = $built["vector"];

        global $wpdb;
        $wpdb->replace(
            self::table(),
            [
                "post_id"     => $post_id,
                "post_type"   => $post->post_type,
                "language"    => $language,
                "word_count"  => $built["words"],
                "vector"      => wp_json_encode( $vector ),
                "vector_norm" => $built["norm"],
                "indexed_at"  => current_time( "mysql", true ),
            ],
            [ "%d", "%s", "%s", "%d", "%s", "%f", "%s" ]
        );

        Cron::bump_graph_version();
        do_action( "jnk_linkweave_post_indexed", $post_id, $vector );
        return true;
    }

    /**
     * A term vector for any text, weighted by the corpus IDF from the last full
     * rebuild. index_post() stores it; the suggestion engine also uses it for
     * drafts, which are not in the index until they are published.
     *
     * @return array{vector: array<string,float>, norm: float, words: int}
     */
    public static function vector_for_content( string $content, string $language = "en" ): array {
        $tokens = Tokenizer::tokenize( $content, $language );
        $tf     = self::term_frequencies( $tokens );
        $idf    = self::load_idf();
        $vector = [];
        foreach ( $tf as $term => $freq ) {
            $vector[ $term ] = $freq * ( $idf[ $term ] ?? log( 1 + 1 ) );
        }
        arsort( $vector );
        $vector = array_slice( $vector, 0, self::TOP_TERMS, true );
        return [ "vector" => $vector, "norm" => self::l2_norm( $vector ), "words" => count( $tokens ) ];
    }

    public static function delete( int $post_id ): void {
        global $wpdb;
        if ( $wpdb->delete( self::table(), [ "post_id" => $post_id ], [ "%d" ] ) ) {
            Cron::bump_graph_version();
        }
    }

    /**
     * Full corpus rebuild. Every way out (done, an exception, a fatal error or a
     * timeout) leaves the status idle or "error", never stuck on "running".
     */
    public static function rebuild(): int {
        // One rebuild at a time. add_option() is an atomic insert, so a queued run
        // and a "Run now" click cannot both get in. A lock older than the stall
        // window belongs to a run that died without cleaning up.
        $lock = get_option( self::LOCK_OPTION );
        if ( $lock && ( time() - (int) $lock ) > self::stall_seconds() ) {
            delete_option( self::LOCK_OPTION );
        }
        if ( ! add_option( self::LOCK_OPTION, time(), "", false ) ) {
            return 0;
        }

        if ( ! self::$shutdown_registered ) {
            register_shutdown_function( [ __CLASS__, "on_shutdown" ] );
            self::$shutdown_registered = true;
        }
        self::$in_rebuild = true;
        try {
            $written = self::do_rebuild();
        } catch ( \Throwable $e ) {
            self::$in_rebuild = false;
            self::record_failure( substr( $e->getMessage(), 0, 300 ) );
            return 0;
        }
        self::$in_rebuild = false;
        delete_option( self::LOCK_OPTION );
        do_action( "jnk_linkweave_index_complete", $written );
        return $written;
    }

    /**
     * Two-pass: pass 1 streams TF rows to a temp file while accumulating document
     * frequency; pass 2 multiplies by IDF and writes vectors. Avoids holding the
     * whole corpus in memory.
     */
    private static function do_rebuild(): int {
        $settings = get_option( "jnk_linkweave_settings", [] );
        $language = (string) ( $settings["language"] ?? "en" );
        $post_types = (array) apply_filters(
            "jnk_linkweave_suggestion_post_types",
            $settings["post_types"] ?? [ "post", "page" ]
        );

        // Same minimum-length gate index_post() applies on per-save reindex, so
        // index membership is stable: a post the rebuild keeps won't vanish on
        // its next save (and vice-versa).
        $min_words = (int) apply_filters(
            "jnk_linkweave_min_post_length",
            (int) ( $settings["min_post_length"] ?? 40 )
        );

        $ids   = self::corpus_ids();
        $total = count( $ids );
        $now   = current_time( "mysql", true );
        $prev  = get_option( self::STATUS_OPTION, [] );
        update_option( self::STATUS_OPTION, [
            "is_running"  => true,
            "state"       => "running",
            "phase"       => "reading",
            "progress"    => 0.0,
            "total"       => $total,
            "read"        => 0,
            "indexed"     => 0,
            "queued_at"   => is_array( $prev ) ? ( $prev["queued_at"] ?? null ) : null,
            "started_at"  => $now,
            "updated_at"  => $now,
            "finished_at" => null,
            "error"       => "",
        ], false );

        // Rows for anything no longer in the corpus (an unticked post type, an
        // unpublished post) would otherwise survive every rebuild.
        global $wpdb;
        self::purge_excluded_types( $post_types );
        $stale = array_diff(
            array_map( "intval", (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM %i", self::table() ) ) ),
            $ids
        );
        foreach ( $stale as $stale_id ) {
            self::delete( (int) $stale_id );
        }

        $cache_path = (string) apply_filters( "jnk_linkweave_tf_cache_path", self::tf_cache_path() );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming scratch file in get_temp_dir() to keep memory flat on large sites; WP_Filesystem has no streaming/append API.
        $tf_cache = @fopen( $cache_path, "w+" );
        if ( ! $tf_cache ) {
            // An unwritable temp dir used to end the rebuild here with the status
            // stuck on "running". Fall back to a memory-backed stream instead.
            $cache_path = "";
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory fallback stream.
            $tf_cache = fopen( "php://temp/maxmemory:" . ( 8 * MB_IN_BYTES ), "w+" );
        }
        if ( ! $tf_cache ) {
            throw new \RuntimeException( "Could not open a temporary file to build the index." );
        }

        $df   = [];
        $read = 0;
        foreach ( $ids as $post_id ) {
            $read++;
            if ( 0 === $read % 10 ) {
                // Pass 1 used to report nothing, so the counter sat at 0 until the
                // second pass, which is most of the run.
                self::write_status( [
                    "read"     => $read,
                    "progress" => 0.5 * $read / max( 1, $total ),
                ] );
            }
            do_action( "jnk_linkweave_rebuild_progress", "reading", $read, $total );
            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }
            $tokens = Tokenizer::tokenize( (string) $post->post_content, $language );
            if ( count( $tokens ) < $min_words ) {
                // Too short to index — keep membership consistent with
                // index_post() by dropping any stale row and excluding it from
                // the corpus statistics and vector writes.
                self::delete( $post_id );
                clean_post_cache( $post_id );
                continue;
            }
            $tf     = self::term_frequencies( $tokens );
            foreach ( array_keys( $tf ) as $term ) {
                $df[ $term ] = ( $df[ $term ] ?? 0 ) + 1;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming scratch file in get_temp_dir(); see fopen note above.
            fwrite( $tf_cache, wp_json_encode( [
                "post_id"   => $post_id,
                "post_type" => $post->post_type,
                "tf"        => $tf,
                "words"     => count( $tokens ),
            ] ) . "\n" );
            clean_post_cache( $post_id );
        }
        $to_write = max( 1, $read );

        $idf = [];
        $n   = max( 1, $total );
        foreach ( $df as $term => $doc_count ) {
            $idf[ $term ] = log( $n / ( 1 + $doc_count ) );
        }
        update_option( "jnk_linkweave_idf", $idf, false );

        self::write_status( [ "phase" => "writing", "read" => $read, "progress" => 0.5 ] );

        rewind( $tf_cache );
        $written = 0;
        while ( ( $line = fgets( $tf_cache ) ) !== false ) {
            $line = trim( $line );
            if ( "" === $line ) {
                continue;
            }
            $row = json_decode( $line, true );
            if ( ! is_array( $row ) || empty( $row["tf"] ) ) {
                continue;
            }

            $vector = [];
            foreach ( $row["tf"] as $term => $freq ) {
                if ( isset( $idf[ $term ] ) ) {
                    $vector[ $term ] = $freq * $idf[ $term ];
                }
            }
            arsort( $vector );
            $vector = array_slice( $vector, 0, self::TOP_TERMS, true );

            $wpdb->replace(
                self::table(),
                [
                    "post_id"     => (int) $row["post_id"],
                    "post_type"   => (string) $row["post_type"],
                    "language"    => $language,
                    "word_count"  => (int) $row["words"],
                    "vector"      => wp_json_encode( $vector ),
                    "vector_norm" => self::l2_norm( $vector ),
                    "indexed_at"  => current_time( "mysql", true ),
                ],
                [ "%d", "%s", "%s", "%d", "%s", "%f", "%s" ]
            );
            $written++;
            do_action( "jnk_linkweave_rebuild_progress", "writing", $written, $total );

            if ( 0 === $written % 25 ) {
                self::write_status( [
                    "progress" => 0.5 + 0.5 * $written / $to_write,
                    "indexed"  => $written,
                ] );
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the streaming scratch file opened above.
        fclose( $tf_cache );
        if ( "" !== $cache_path ) {
            wp_delete_file( $cache_path );
        }

        self::write_status( [
            "is_running"  => false,
            "state"       => "idle",
            "phase"       => "",
            "progress"    => 1.0,
            "total"       => $total,
            "read"        => $read,
            "indexed"     => $written,
            "finished_at" => current_time( "mysql", true ),
            "error"       => "",
        ] );
        return $written;
    }

    private static function load_idf(): array {
        $idf = get_option( "jnk_linkweave_idf", [] );
        return is_array( $idf ) ? $idf : [];
    }

    private static function term_frequencies( array $tokens ): array {
        $count = count( $tokens );
        if ( 0 === $count ) {
            return [];
        }
        $tf = [];
        foreach ( $tokens as $term ) {
            $tf[ $term ] = ( $tf[ $term ] ?? 0 ) + 1;
        }
        foreach ( $tf as $term => $freq ) {
            $tf[ $term ] = $freq / $count;
        }
        return $tf;
    }

    private static function l2_norm( array $vector ): float {
        $sum = 0.0;
        foreach ( $vector as $score ) {
            $sum += $score * $score;
        }
        return sqrt( $sum );
    }

    private static function tf_cache_path(): string {
        return trailingslashit( get_temp_dir() ) . "jnk-linkweave-tf-" . wp_generate_password( 8, false ) . ".jsonl";
    }
}
