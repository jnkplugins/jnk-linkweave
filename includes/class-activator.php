<?php
defined( "ABSPATH" ) || exit;

class JnkLinkweave_Activator {

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
                vector LONGTEXT NOT NULL,
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
