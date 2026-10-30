<?php
defined( "ABSPATH" ) || exit;

class JnkLinkweave_Activator {

    /** Option: show "JnK Linkweave is ready. Start here" on the Plugins screen. */
    public const READY_NOTICE_OPTION = "jnk_linkweave_ready_notice";

    /**
     * The activation hook. Sets up the tables and settings, and, for a single
     * activation from the Plugins screen (not bulk, not network-wide), asks the
     * Plugins screen to point at the first screen once. No redirect: the owner
     * stays where they were.
     *
     * @param bool $network_wide
     */
    public static function on_activate( $network_wide = false ): void {
        self::activate();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, WordPress checked the nonce for this activation.
        $action = isset( $_REQUEST["action"] ) ? sanitize_key( wp_unslash( $_REQUEST["action"] ) ) : "";
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
        $action2 = isset( $_REQUEST["action2"] ) ? sanitize_key( wp_unslash( $_REQUEST["action2"] ) ) : "";
        $bulk    = in_array( "activate-selected", [ $action, $action2 ], true );
        if ( $network_wide || $bulk || ( defined( "WP_CLI" ) && WP_CLI ) ) {
            return;
        }
        update_option( self::READY_NOTICE_OPTION, 1 );
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . "wp-admin/includes/upgrade.php";

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix . "jnk_linkweave_";

        $statements = [
            "CREATE TABLE {$prefix}index (
                post_id BIGINT UNSIGNED NOT NULL,
                post_type VARCHAR(20) NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT \"en\",
                word_count INT UNSIGNED NOT NULL DEFAULT 0,
                `vector` LONGTEXT NOT NULL,
                vector_norm DOUBLE NOT NULL DEFAULT 0,
                indexed_at DATETIME NOT NULL,
                PRIMARY KEY (post_id),
                KEY idx_post_type (post_type),
                KEY idx_indexed_at (indexed_at)
            ) {$charset_collate};",

            // target_post_id is 0 when the href points at this site but resolves to no
            // post — a deleted page, an unpublished one, a changed slug. Those are
            // precisely the broken links worth reporting, so they are recorded with the
            // raw URL rather than discarded. The unique key therefore has to include
            // target_url, or every dead link from one post would collide on (source, 0).
            "CREATE TABLE {$prefix}links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_post_id BIGINT UNSIGNED NOT NULL,
                target_post_id BIGINT UNSIGNED NOT NULL,
                target_url VARCHAR(500) NOT NULL DEFAULT '',
                anchor_text VARCHAR(500) NOT NULL,
                is_broken TINYINT(1) NOT NULL DEFAULT 0,
                last_checked DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_link (source_post_id, target_post_id, anchor_text(100), target_url(100)),
                KEY idx_target (target_post_id),
                KEY idx_broken (is_broken)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL,
                target_url TEXT NOT NULL,
                case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
                max_per_post SMALLINT NOT NULL DEFAULT 1,
                max_per_site INT NOT NULL DEFAULT 0,
                open_in_new_tab TINYINT(1) NOT NULL DEFAULT 0,
                nofollow TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_generated TINYINT(1) NOT NULL DEFAULT 0,
                applied_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_keyword (keyword(50)),
                KEY idx_active (is_active),
                KEY idx_generated (is_generated)
            ) {$charset_collate};",
        ];

        foreach ( $statements as $sql ) {
            dbDelta( $sql );
        }

        self::migrate_links_unique_key();
        self::check_tables();

        $version = defined( "JNK_LINKWEAVE_VERSION" ) ? JNK_LINKWEAVE_VERSION : "1.0.0";
        update_option( "jnk_linkweave_version", $version );

        if ( false === get_option( "jnk_linkweave_settings" ) ) {
            update_option( "jnk_linkweave_settings", self::default_settings() );
        }

        // Read the posts once, straight after activation, so suggestions are ready
        // without anyone having to find the Rebuild button. Action Scheduler is not
        // ready inside the activation request, so the next admin page load queues it
        // (Index_Builder::maybe_start_first_index), and only while the index is empty.
        update_option( "jnk_linkweave_first_index_pending", 1, false );

        if ( ! wp_next_scheduled( "jnk_linkweave_daily_scan" ) ) {
            wp_schedule_event( time(), "daily", "jnk_linkweave_daily_scan" );
        }
    }

    /** The tables Linkweave cannot work without, without the prefix. */
    public const TABLES = [ "index", "links", "rules" ];

    /** Option holding what check_tables() found missing, if anything. */
    public const SCHEMA_PROBLEM_OPTION = "jnk_linkweave_schema_problem";

    /**
     * Confirm the tables exist after dbDelta(), which fails without a word.
     *
     * On MariaDB 11.7+ the index table was never created (VECTOR became a
     * reserved word, fixed in 1.11.2), yet the plugin activated fine and the
     * dashboard said "100%" over an index that did not exist. Remember what is
     * missing, with the database's own error, so the screens can say so.
     *
     * @return string[] the missing table names
     */
    public static function check_tables(): array {
        global $wpdb;
        $missing = self::missing_tables();
        if ( $missing ) {
            update_option( self::SCHEMA_PROBLEM_OPTION, [ "tables" => $missing, "error" => (string) $wpdb->last_error ], false );
        } else {
            delete_option( self::SCHEMA_PROBLEM_OPTION );
        }
        return $missing;
    }

    /** @return string[] the full names of the tables this build needs */
    public static function expected_tables(): array {
        global $wpdb;
        $tables = array_unique( array_merge( self::TABLES, (array) apply_filters( "jnk_linkweave_expected_tables", [] ) ) );
        return array_values( array_map( static fn( $t ) => $wpdb->prefix . "jnk_linkweave_" . $t, $tables ) );
    }

    /** @return string[] the needed tables the database does not have (read only) */
    public static function missing_tables(): array {
        global $wpdb;
        $missing = [];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema introspection; no cache applies.
        foreach ( self::expected_tables() as $name ) {
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $name ) ) ) !== $name ) {
                $missing[] = $name;
            }
        }
        // phpcs:enable
        return $missing;
    }

    /** What is missing, or null: [ "tables" => string[], "error" => string ]. */
    public static function schema_problem(): ?array {
        $p = get_option( self::SCHEMA_PROBLEM_OPTION, null );
        return is_array( $p ) && ! empty( $p["tables"] ) ? $p : null;
    }

    /**
     * Run pending schema migrations without a manual deactivate/reactivate.
     *
     * Hooked on admin_init. The activation hook does NOT fire on plugin update
     * (e.g. when a free install is upgraded in place to Pro by uploading the Pro
     * zip), so new tables/columns would otherwise be missing until reactivation.
     * activate() is idempotent (dbDelta, guarded settings + cron), so re-running
     * it once per release — gated on the stored version — is safe.
     */
    public static function maybe_upgrade(): void {
        $version = defined( "JNK_LINKWEAVE_VERSION" ) ? JNK_LINKWEAVE_VERSION : "1.0.0";
        if ( get_option( "jnk_linkweave_version" ) === $version ) {
            return;
        }
        self::activate();
    }

    /**
     * Widen the links unique key to include target_url.
     *
     * dbDelta adds the target_url COLUMN happily but will not replace an existing
     * UNIQUE KEY of the same name, so an upgraded site would keep
     * (source_post_id, target_post_id, anchor_text(191)). Under that key every
     * unresolved link from one post shares (source, 0) and only the first survives —
     * which is the bug this migration exists to close. Drop and re-add explicitly.
     *
     * Safe to re-run: it inspects the current index before touching anything.
     */
    private static function migrate_links_unique_key(): void {
        global $wpdb;
        $table = $wpdb->prefix . "jnk_linkweave_links";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema introspection; no cache applies.
        $cols = $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM %i", $table ) );
        if ( ! $cols || ! in_array( "target_url", (array) $cols, true ) ) {
            return; // dbDelta has not added the column yet; nothing safe to do.
        }

        $index = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'unique_link'", $table ) );
        foreach ( (array) $index as $part ) {
            if ( "target_url" === $part->Column_name ) {
                return; // already migrated
            }
        }

        if ( $index ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i DROP INDEX unique_link", $table ) );
        }
        $wpdb->query( $wpdb->prepare(
            "ALTER TABLE %i ADD UNIQUE KEY unique_link (source_post_id, target_post_id, anchor_text(100), target_url(100))",
            $table
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
    }

    private static function default_settings(): array {
        return [
            "post_types"             => [ "post", "page" ],
            "min_post_length"        => 40,
            "suggestions_per_post"   => 10,
            "min_relevance_score"    => 0.25,
            "auto_index_on_save"    => true,
            "broken_link_check_freq" => "daily",
            "language"               => "en",
            "stopwords_custom"       => [],
        ];
    }
}
