<?php
namespace JnkLinkweave;

defined( "ABSPATH" ) || exit;

/**
 * Empty the page cache when auto-link rules change.
 *
 * Rules are applied when a page is rendered, so a page cache keeps serving
 * pages without a new rule's links (or with a removed rule's links) until it
 * expires. On a shop with WP Super Cache a new rule showed to the owner but
 * not to visitors (persona run, 29 Oct 2026). When a rule is added, changed or
 * removed, the common cache plugins are told to empty their cache, through
 * their own public functions or actions, and only when they are installed.
 * One purge per request, however many rules changed.
 */
class Page_Cache {

    private static bool $queued = false;

    public static function register_hooks(): void {
        add_action( "jnk_linkweave_rule_saved", [ __CLASS__, "request_purge" ], 10, 0 );
        add_action( "jnk_linkweave_rule_deleted", [ __CLASS__, "request_purge" ], 10, 0 );
        add_action( "jnk_linkweave_rules_changed", [ __CLASS__, "request_purge" ], 10, 0 );
    }

    /** Purge once, at the end of this request. */
    public static function request_purge(): void {
        if ( self::$queued ) {
            return;
        }
        self::$queued = true;
        add_action( "shutdown", [ __CLASS__, "purge_queued" ], 5 );
    }

    public static function is_queued(): bool {
        return self::$queued;
    }

    public static function purge_queued(): void {
        if ( ! self::$queued ) {
            return;
        }
        self::$queued = false;
        self::purge_all();
    }

    /**
     * Ask every cache plugin that is present to empty its page cache.
     *
     * @return string[] the cache plugins that were asked
     */
    public static function purge_all(): array {
        if ( ! apply_filters( "jnk_linkweave_purge_page_cache", true ) ) {
            return [];
        }
        $done = [];
        // WP Super Cache.
        if ( function_exists( "wp_cache_clear_cache" ) ) {
            wp_cache_clear_cache();
            $done[] = "wp-super-cache";
        }
        // W3 Total Cache.
        if ( function_exists( "w3tc_flush_all" ) ) {
            w3tc_flush_all();
            $done[] = "w3-total-cache";
        }
        // WP Rocket.
        if ( function_exists( "rocket_clean_domain" ) ) {
            rocket_clean_domain();
            $done[] = "wp-rocket";
        }
        // LiteSpeed Cache listens for this action.
        if ( defined( "LSCWP_V" ) || has_action( "litespeed_purge_all" ) ) {
            do_action( "litespeed_purge_all", "JnK Linkweave: auto-link rules changed" );
            $done[] = "litespeed-cache";
        }
        // WP Fastest Cache.
        if ( function_exists( "wpfc_clear_all_cache" ) ) {
            wpfc_clear_all_cache( true );
            $done[] = "wp-fastest-cache";
        }
        if ( $done ) {
            do_action( "jnk_linkweave_page_cache_purged", $done );
        }
        return $done;
    }
}
