<?php
namespace JnkLinkweave\Admin;

use JnkLinkweave\Index_Builder;
use JnkLinkweave\Rules_Engine;

defined( "ABSPATH" ) || exit;

class Admin {

    public const MENU_SLUG = "jnk-linkweave";

    public function register_hooks(): void {
        add_action( "admin_menu", [ $this, "register_menu" ] );
        add_action( "admin_enqueue_scripts", [ $this, "enqueue_assets" ] );
        add_filter( "plugin_action_links_" . plugin_basename( JNK_LINKWEAVE_FILE ), [ $this, "plugin_action_links" ] );
        // Late priority, so WordPress's own "View details" item is already present.
        add_filter( "plugin_row_meta", [ $this, "plugin_row_meta" ], 99, 2 );
        add_action( "admin_notices", [ $this, "unlicensed_pro_notice" ] );
        add_action( "admin_notices", [ $this, "schema_problem_notice" ] );
        add_action( "admin_notices", [ $this, "ready_notice" ] );
        add_action( "wp_ajax_jnk_linkweave_dismiss_ready", [ $this, "dismiss_ready_notice" ] );
        add_filter( "site_status_tests", [ $this, "site_status_tests" ] );
        // "Links in / out" column on the Posts list (free).
        Posts_Column::register();
    }

    /** @param array<string,mixed> $tests */
    public function site_status_tests( $tests ): array {
        $tests                                 = (array) $tests;
        $tests["direct"]["jnk_linkweave_tables"] = [
            "label" => __( "JnK Linkweave database tables", "jnk-linkweave" ),
            "test"  => [ $this, "site_health" ],
        ];
        return $tests;
    }

    /**
     * Tools > Site Health, the same check as every JnK plugin's: are the tables
     * there, and on the Pro build, is the Pro data ready. Checked live.
     */
    public function site_health(): array {
        $problem = \JnkLinkweave_Activator::schema_problem();
        $missing = \JnkLinkweave_Activator::missing_tables();
        $badge   = [ "label" => "JnK Linkweave", "color" => $missing ? "red" : "blue" ];
        if ( $missing ) {
            return [
                "label"       => __( "JnK Linkweave is missing database tables", "jnk-linkweave" ),
                "status"      => "critical",
                "badge"       => $badge,
                "description" => "<p>" . esc_html__( "Link suggestions and the reports cannot work without these tables. Send this list to support (jnkplugins.com/contact):", "jnk-linkweave" ) . "</p><p><code>"
                    . esc_html( implode( ", ", $missing ) . ( $problem && "" !== $problem["error"] ? ": " . $problem["error"] : "" ) ) . "</code></p>",
                "actions"     => "",
                "test"        => "jnk_linkweave_tables",
            ];
        }
        $pro   = defined( "JNK_LINKWEAVE_PRO" ) && JNK_LINKWEAVE_PRO;
        $count = count( \JnkLinkweave_Activator::expected_tables() );
        return [
            "label"       => $pro ? __( "JnK Linkweave Pro data is ready", "jnk-linkweave" ) : __( "JnK Linkweave database tables are ready", "jnk-linkweave" ),
            "status"      => "good",
            "badge"       => $badge,
            "description" => "<p>" . esc_html( sprintf(
                /* translators: %d: number of database tables */
                _n( "The %d table JnK Linkweave needs is in the database.", "All %d tables JnK Linkweave needs are in the database.", $count, "jnk-linkweave" ),
                $count
            ) ) . "</p>",
            "actions"     => "",
            "test"        => "jnk_linkweave_tables",
        ];
    }

    /**
     * A table Linkweave needs could not be created. dbDelta() says nothing, so
     * without this the plugin looked healthy while suggestions stayed empty.
     */
    public function schema_problem_notice(): void {
        $problem = \JnkLinkweave_Activator::schema_problem();
        if ( ! $problem || ! current_user_can( "manage_options" ) ) {
            return;
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : "";
        if ( false === strpos( $id, self::MENU_SLUG ) && ! in_array( $id, [ "plugins", "dashboard" ], true ) ) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p></div>',
            esc_html__( "JnK Linkweave could not create its database tables.", "jnk-linkweave" ),
            esc_html__( "Link suggestions and the reports need them. Send the details below to support (jnkplugins.com/contact) and we will sort it out with you.", "jnk-linkweave" ),
            esc_html( implode( ", ", $problem["tables"] ) . ( "" !== $problem["error"] ? ": " . $problem["error"] : "" ) )
        );
    }

    /**
     * "JnK Linkweave is ready. Start here" on the Plugins screen, once, after the
     * plugin is activated on its own (not in a bulk or network activation).
     * Strangers activated it and then did not know where it lived (persona runs
     * 29 Oct 2026). No redirect. Gone once the link is clicked, the notice is
     * dismissed, or the Overview is opened any other way.
     */
    public function ready_notice(): void {
        if ( ! get_option( \JnkLinkweave_Activator::READY_NOTICE_OPTION ) || ! current_user_can( "manage_options" ) ) {
            return;
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        if ( ! $screen || "plugins" !== $screen->id ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only: WordPress's own "activated several plugins" flag.
        if ( isset( $_GET["activate-multi"] ) ) {
            delete_option( \JnkLinkweave_Activator::READY_NOTICE_OPTION );
            return;
        }
        printf(
            '<div class="notice notice-success is-dismissible jnk-linkweave-ready-notice"><p><strong>%s</strong> <a href="%s">%s</a></p></div>',
            /* translators: %s: plugin name. */
            esc_html( sprintf( __( "%s is ready.", "jnk-linkweave" ), "JnK Linkweave" ) ),
            esc_url( admin_url( "admin.php?page=" . self::MENU_SLUG ) ),
            esc_html__( "Start here", "jnk-linkweave" )
        );
        // WordPress adds the dismiss button; tell the server when it is used.
        printf(
            "<script>document.addEventListener('click',function(e){if(e.target.closest&&e.target.closest('.jnk-linkweave-ready-notice .notice-dismiss')){var b=new FormData();b.append('action','jnk_linkweave_dismiss_ready');b.append('_ajax_nonce',%s);fetch(%s,{method:'POST',credentials:'same-origin',body:b});}});</script>",
            wp_json_encode( wp_create_nonce( "jnk_linkweave_dismiss_ready" ) ),
            wp_json_encode( admin_url( "admin-ajax.php" ) )
        );
    }

    /** AJAX: the ready notice was dismissed. */
    public function dismiss_ready_notice(): void {
        check_ajax_referer( "jnk_linkweave_dismiss_ready" );
        if ( ! current_user_can( "manage_options" ) ) {
            wp_send_json_error( null, 403 );
        }
        delete_option( \JnkLinkweave_Activator::READY_NOTICE_OPTION );
        wp_send_json_success();
    }

    /**
     * Tell someone running the Pro build with no license what to do about it.
     *
     * Until a key is entered the Pro build is indistinguishable from the free one —
     * same menu, no locked items, nothing naming the features that are sitting there
     * dormant. A customer who has just paid and uploaded the zip has no way to tell
     * whether they installed the right file.
     */
    public function unlicensed_pro_notice(): void {
        if ( ! defined( "JNK_LINKWEAVE_PRO" ) || ! JNK_LINKWEAVE_PRO ) {
            return;
        }
        if ( Rules_Engine::is_pro() || ! current_user_can( "manage_options" ) ) {
            return;
        }
        // The licensing SDK (1.8.0) shows this notice the same way in every JnK
        // plugin. This one stays for an older SDK copy loaded by another plugin.
        if ( apply_filters( "jnk_linkweave_sdk_shows_licence_notice", false ) ) {
            return;
        }
        // An expired, locked or revoked licence gets the licensing module's own
        // notice instead; showing both would say the same thing twice.
        if ( "unlicensed" !== (string) apply_filters( "jnk_linkweave_licence_state", "unlicensed" ) ) {
            return;
        }
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        // Also on the Plugins screen: WordPress's "Replace current with uploaded"
        // table cannot tell the two builds apart (same name and version), so this is
        // the first place a buyer can confirm the Pro zip went in.
        if ( ! $screen || ( false === strpos( (string) $screen->id, self::MENU_SLUG ) && "plugins" !== $screen->id ) ) {
            return;
        }
        // The class lets the licence box hide this once activation succeeds, so the
        // page never shows "no license key" directly above "License activated."
        printf(
            '<div class="notice notice-info jnk-linkweave-unlicensed-notice"><p>%s <a href="%s">%s</a></p></div>',
            // The same notice shows right after "Deactivate this site", so it must not
            // claim a key was never entered, and it names every feature that is off.
            esc_html__( "This is JnK Linkweave Pro, but no license is active on this site, so the Pro features are switched off: the URL Changer, link click analytics, Search Console targeting, the auto-link map, the Anchor Text and Links per post reports, broken-link repair, related posts, link preview cards, bulk link insertion, the link check before you publish, extra rule options, cross-site linking, AI re-ranking, AI assistant access and the CSV exports.", "jnk-linkweave" ),
            esc_url( admin_url( "admin.php?page=" . self::MENU_SLUG . "-settings" ) ),
            esc_html__( "Enter your license key", "jnk-linkweave" )
        );
    }

    /**
     * Action links on the Plugins screen (next to Deactivate): Settings, and an
     * Upgrade link whenever Pro is not active (mirrors the admin-menu gating).
     */
    public function plugin_action_links( array $links ): array {
        $settings = '<a href="' . esc_url( admin_url( "admin.php?page=" . self::MENU_SLUG . "-settings" ) ) . '">'
            . esc_html__( "Settings", "jnk-linkweave" ) . "</a>";
        array_unshift( $links, $settings );

        if ( ! Rules_Engine::is_pro() ) {
            // `src` distinguishes this entry point from the admin-menu item in the
            // upgrade page's outbound utm_content, which was otherwise constant.
            $links["jnk_linkweave_upgrade"] = '<a href="' . esc_url( admin_url( "admin.php?page=" . self::MENU_SLUG . "-upgrade&src=plugins-row" ) ) . '" '
                . 'style="color:#b45309;font-weight:600;">' . esc_html__( "Upgrade to Pro", "jnk-linkweave" ) . "</a>";
        }
        return $links;
    }

    /**
     * Row meta on the Plugins screen: Docs + Support on our own site.
     *
     * With the licensing client loaded (the Free and Pro builds), WordPress's
     * "View details" popup is answered by our server, like Trinity Forms and
     * EntryVault, so it is left alone (it used to be swapped for the docs page;
     * owner report, 30 Oct 2026). Without the client (the wordpress.org build) it
     * would open the closed wordpress.org listing, so it points to our site.
     */
    public function plugin_row_meta( array $meta, string $file ): array {
        if ( plugin_basename( JNK_LINKWEAVE_FILE ) !== $file ) {
            return $meta;
        }
        if ( empty( $GLOBALS["jnk_linkweave_license_client"] ) ) {
            foreach ( $meta as $i => $item ) {
                if ( false !== strpos( (string) $item, "tab=plugin-information" ) ) {
                    $meta[ $i ] = '<a href="https://jnkplugins.com/jnk-linkweave/" target="_blank" rel="noopener">'
                        . esc_html__( "View details", "jnk-linkweave" ) . "</a>";
                }
            }
        }
        $meta[] = '<a href="https://jnkplugins.com/jnk-linkweave/" target="_blank" rel="noopener">'
            . esc_html__( "Docs", "jnk-linkweave" ) . "</a>";
        $meta[] = '<a href="https://jnkplugins.com/contact.php" target="_blank" rel="noopener">'
            . esc_html__( "Support", "jnk-linkweave" ) . "</a>";
        return $meta;
    }

    public function register_menu(): void {
        // Every screen here reads or writes SITE-WIDE data (the index, the
        // link graph, auto-link rules applied on the_content for all posts) and
        // its REST endpoints all require manage_options. Gate the whole menu on
        // manage_options too, so the menu a role can SEE matches the data it can
        // actually load — otherwise an Editor opens a page whose AJAX 403s.
        $cap = "manage_options";
        add_menu_page(
            "JnK Linkweave",
            "JnK Linkweave",
            $cap,
            self::MENU_SLUG,
            [ $this, "render_dashboard" ],
            "dashicons-admin-links",
            // Sit with the other content tools just below Comments (25), not down
            // past Settings (80) where the menu is easy to miss. EntryVault takes
            // 26 and Trinity Forms' CPT falls in around 25, so use 27 — positions
            // are array keys, and a duplicate silently overwrites the other menu.
            27
        );
        // Same names as the tabs on each screen, so they are translated too (the
        // menu stayed English on a Korean site while every screen was Korean).
        $pages = [
            ""          => [ __( "Overview", "jnk-linkweave" ),        "render_dashboard" ],
            "-rules"    => [ __( "Auto-link rules", "jnk-linkweave" ), "render_rules" ],
            "-orphans"  => [ __( "Orphans", "jnk-linkweave" ),         "render_orphans" ],
            "-inbound"  => [ __( "Inbound links", "jnk-linkweave" ),   "render_inbound" ],
            "-broken"   => [ __( "Broken links", "jnk-linkweave" ),    "render_broken" ],
            "-settings" => [ __( "Settings", "jnk-linkweave" ),        "render_settings" ],
        ];
        foreach ( $pages as $suffix => [ $label, $render ] ) {
            add_submenu_page( self::MENU_SLUG, $label, $label, $cap, self::MENU_SLUG . $suffix, [ $this, $render ] );
        }

        // Premium submenus (the bulk URL Changer) register here from pro/; the free
        // build adds nothing further.
        do_action( "jnk_linkweave_register_admin_menu", self::MENU_SLUG );

        // Entry point for everyone who is not currently licensed — but the wording
        // has to match the situation. Someone running the Pro build has already
        // bought it and only needs to activate; inviting them to "Upgrade to Pro"
        // reads as though the purchase did not register, and nothing else on screen
        // hints that the Pro features are sitting there waiting for a key.
        if ( ! Rules_Engine::is_pro() ) {
            $has_pro_build = defined( "JNK_LINKWEAVE_PRO" ) && JNK_LINKWEAVE_PRO;

            if ( $has_pro_build ) {
                // A lapsed or refunded licence needs renewing, not activating.
                $menu_label = "unlicensed" === apply_filters( "jnk_linkweave_licence_state", "unlicensed" )
                    ? __( "Activate license", "jnk-linkweave" )
                    : __( "Renew license", "jnk-linkweave" );
                add_submenu_page(
                    self::MENU_SLUG,
                    $menu_label,
                    '<span class="jnk-linkweave-upgrade-menu" style="color:#f0b849;">' . esc_html( $menu_label ) . '</span>',
                    $cap,
                    self::MENU_SLUG . "-settings"
                );
            } else {
                add_submenu_page(
                    self::MENU_SLUG,
                    __( "Upgrade to Pro", "jnk-linkweave" ),
                    '<span class="jnk-linkweave-upgrade-menu" style="color:#f0b849;">' . esc_html__( "Upgrade to Pro", "jnk-linkweave" ) . '</span>',
                    $cap,
                    self::MENU_SLUG . "-upgrade",
                    [ $this, "render_upgrade" ]
                );
            }
        }
    }

    public function render_upgrade(): void {
        include JNK_LINKWEAVE_PATH . "admin/views/upgrade.php";
    }

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, self::MENU_SLUG ) ) {
            return;
        }
        // Shared JnK UI kit first (identical file in all three JnK plugins, every
        // rule scoped under .jnkui), then this plugin's own tweaks on top.
        wp_enqueue_style(
            "jnk-linkweave-ui",
            JNK_LINKWEAVE_URL . "admin/assets/css/jnk-ui.css",
            [],
            JNK_LINKWEAVE_VERSION
        );
        wp_enqueue_style(
            "jnk-linkweave-admin",
            JNK_LINKWEAVE_URL . "admin/assets/css/admin.css",
            [ "jnk-linkweave-ui" ],
            JNK_LINKWEAVE_VERSION
        );
        wp_enqueue_script(
            "jnk-linkweave-admin",
            JNK_LINKWEAVE_URL . "admin/assets/js/admin.js",
            [ "wp-api-fetch", "wp-i18n" ],
            JNK_LINKWEAVE_VERSION,
            true
        );
        // Translations for these scripts are the languages/*.json files.
        wp_set_script_translations( "jnk-linkweave-admin", "jnk-linkweave", JNK_LINKWEAVE_PATH . "languages" );
        if ( false !== strpos( $hook, self::MENU_SLUG . "-inbound" ) ) {
            wp_enqueue_script( "jnk-linkweave-linkmap", JNK_LINKWEAVE_URL . "admin/assets/js/linkmap.js", [ "wp-i18n" ], JNK_LINKWEAVE_VERSION, true );
            wp_set_script_translations( "jnk-linkweave-linkmap", "jnk-linkweave", JNK_LINKWEAVE_PATH . "languages" );
        }
        wp_localize_script( "jnk-linkweave-admin", "JnkLinkweaveAdmin", [
            "restUrl"   => esc_url_raw( rest_url( "jnk-linkweave/v1/" ) ),
            "nonce"     => wp_create_nonce( "wp_rest" ),
            "isPro"     => Rules_Engine::is_pro(),
            // Times on these screens in the site's zone, like the Overview.
            "siteTz"     => wp_timezone_string(),
            "siteOffset" => (int) wp_timezone()->getOffset( new \DateTimeImmutable( "now" ) ),
            "i18n"      => [
                "rebuild"  => __( "Rebuild index", "jnk-linkweave" ),
                "running"  => __( "Reading your posts…", "jnk-linkweave" ),
                "complete" => __( "Done", "jnk-linkweave" ),
                /* translators: %d: number of posts read. */
                "indexedJustNow" => __( "Last full read: just now (%d posts)", "jnk-linkweave" ),
            ],
        ] );
    }

    public function render_dashboard(): void {
        // The first screen: the "ready, start here" notice has done its job.
        delete_option( \JnkLinkweave_Activator::READY_NOTICE_OPTION );
        $status = get_option( "jnk_linkweave_index_status", [] );
        include JNK_LINKWEAVE_PATH . "admin/views/dashboard.php";
    }

    public function render_rules(): void {
        include JNK_LINKWEAVE_PATH . "admin/views/rules.php";
    }

    public function render_orphans(): void {
        include JNK_LINKWEAVE_PATH . "admin/views/orphans.php";
    }

    public function render_inbound(): void {
        include JNK_LINKWEAVE_PATH . "admin/views/inbound.php";
    }

    public function render_broken(): void {
        include JNK_LINKWEAVE_PATH . "admin/views/broken.php";
    }

    public function render_settings(): void {
        $settings = get_option( "jnk_linkweave_settings", [] );
        include JNK_LINKWEAVE_PATH . "admin/views/settings.php";
    }
}
