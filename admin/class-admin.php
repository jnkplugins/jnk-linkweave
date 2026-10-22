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
        // "Links in / out" column on the Posts list (free).
        Posts_Column::register();
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
     * Row meta on the Plugins screen: View details, Docs + Support, all on our own
     * site. WordPress's "View details" opens the wordpress.org listing for this
     * slug, and that listing is closed, so it showed a "closed" error; the old
     * Support link went to that listing's forum.
     */
    public function plugin_row_meta( array $meta, string $file ): array {
        if ( plugin_basename( JNK_LINKWEAVE_FILE ) !== $file ) {
            return $meta;
        }
        foreach ( $meta as $i => $item ) {
            if ( false !== strpos( (string) $item, "tab=plugin-information" ) ) {
                $meta[ $i ] = '<a href="https://jnkplugins.com/jnk-linkweave/" target="_blank" rel="noopener">'
                    . esc_html__( "View details", "jnk-linkweave" ) . "</a>";
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
        add_submenu_page( self::MENU_SLUG, "Dashboard",      "Dashboard",      $cap, self::MENU_SLUG,                 [ $this, "render_dashboard" ] );
        add_submenu_page( self::MENU_SLUG, "Rules",          "Rules",          $cap, self::MENU_SLUG . "-rules",      [ $this, "render_rules" ] );
        add_submenu_page( self::MENU_SLUG, "Orphans",        "Orphans",        $cap, self::MENU_SLUG . "-orphans",    [ $this, "render_orphans" ] );
        add_submenu_page( self::MENU_SLUG, "Inbound Counts", "Inbound Counts", $cap, self::MENU_SLUG . "-inbound",    [ $this, "render_inbound" ] );
        add_submenu_page( self::MENU_SLUG, "Broken Links",   "Broken Links",   $cap, self::MENU_SLUG . "-broken",     [ $this, "render_broken" ] );
        add_submenu_page( self::MENU_SLUG, "Settings",       "Settings",       $cap, self::MENU_SLUG . "-settings",   [ $this, "render_settings" ] );

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
