<?php
namespace JnkLinkweave\Admin;

use JnkLinkweave\Rules_Engine;

defined( "ABSPATH" ) || exit;

/**
 * Page chrome for every Linkweave admin screen: the branded top bar with the
 * plugin's own tabs, and a page header (title, one plain-English line, actions).
 *
 * Markup only. Menus, capabilities, nonces and REST routes are untouched, so a
 * screen behaves exactly as before; this is the layer that makes it look like one
 * product instead of eleven unrelated WordPress pages. Styles: jnk-ui.css.
 */
class UI {

    /** Main tabs, in order: page-slug suffix => label. */
    private static function primary_tabs(): array {
        return [
            ""          => __( "Overview", "jnk-linkweave" ),
            "-rules"    => __( "Auto-link rules", "jnk-linkweave" ),
            "-orphans"  => __( "Orphans", "jnk-linkweave" ),
            "-inbound"  => __( "Inbound links", "jnk-linkweave" ),
            "-broken"   => __( "Broken links", "jnk-linkweave" ),
            "-clicks"   => __( "Link clicks", "jnk-linkweave" ),
        ];
    }

    /** Pro tools that live under "More". */
    private static function more_tabs(): array {
        return [
            "-url-changer" => __( "URL changer", "jnk-linkweave" ),
            "-gsc"         => __( "Search Console", "jnk-linkweave" ),
            "-anchors"     => __( "Anchor text", "jnk-linkweave" ),
            "-cluster-map" => __( "Link map", "jnk-linkweave" ),
            "-links-report" => __( "Links per post", "jnk-linkweave" ),
        ];
    }

    /** Pro-only screens; in the free build these tabs lead to the upgrade page. */
    private const PRO_ONLY = [ "-clicks", "-url-changer", "-gsc", "-anchors", "-cluster-map", "-links-report" ];

    private static function url( string $suffix ): string {
        return admin_url( "admin.php?page=" . Admin::MENU_SLUG . $suffix );
    }

    /** Where a tab points: its own screen, or the upgrade/licence screen when locked. */
    private static function href( string $suffix ): array {
        $locked = in_array( $suffix, self::PRO_ONLY, true ) && ! Rules_Engine::is_pro();
        // Lets the Pro module keep a tab open after a licence ends (the URL changer
        // stays usable for undoing past changes).
        $locked = (bool) apply_filters( "jnk_linkweave_tab_locked", $locked, $suffix );
        if ( ! $locked ) {
            return [ self::url( $suffix ), false ];
        }
        $has_pro_build = defined( "JNK_LINKWEAVE_PRO" ) && JNK_LINKWEAVE_PRO;
        return [ $has_pro_build ? self::url( "-settings" ) : self::url( "-upgrade" ) . "&src=tab", true ];
    }

    private static function current_suffix(): string {
        $page = isset( $_GET["page"] ) ? sanitize_key( wp_unslash( $_GET["page"] ) ) : Admin::MENU_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: picks the active tab.
        return (string) substr( $page, strlen( Admin::MENU_SLUG ) );
    }

    private static function tab( string $suffix, string $label, string $current, string $class = "jnkui-tab" ): string {
        [ $href, $locked ] = self::href( $suffix );
        $active = $suffix === $current ? " is-active" : "";
        $pro    = $locked ? ' <span class="jnkui-pill is-pro">' . esc_html__( "Pro", "jnk-linkweave" ) . "</span>" : "";
        return '<a class="' . esc_attr( $class . $active ) . '" href="' . esc_url( $href ) . '"'
            . ( $active ? ' aria-current="page"' : "" ) . ">" . esc_html( $label ) . $pro . "</a>";
    }

    /**
     * Open a screen. $actions is trusted HTML built by the caller (buttons).
     */
    public static function open( string $title, string $description = "", string $actions = "" ): void {
        $current = self::current_suffix();
        $more    = self::more_tabs();
        $in_more = isset( $more[ $current ] );

        echo '<div class="wrap jnkui jnk-linkweave-wrap">';
        echo '<div class="jnkui-topbar">';
        echo '<a class="jnkui-brand" href="' . esc_url( self::url( "" ) ) . '">'
            . '<span class="jnkui-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span>'
            . "<b>" . esc_html__( "Linkweave", "jnk-linkweave" ) . "</b>"
            . '<span class="jnkui-ver">' . esc_html( "v" . JNK_LINKWEAVE_VERSION ) . "</span>"
            // Says Pro is on, rather than leaving people to infer it from tabs that unlock.
            . ( \JnkLinkweave\Rules_Engine::is_pro() ? '<span class="jnkui-pro-badge" style="margin-inline-start:6px;padding:1px 7px;border-radius:999px;background:#4f46e5;color:#fff;font-size:11px;font-weight:600;letter-spacing:.02em;vertical-align:middle">Pro</span>' : "" )
            . "</a>";

        echo '<nav class="jnkui-tabs" aria-label="' . esc_attr__( "Linkweave sections", "jnk-linkweave" ) . '">';
        foreach ( self::primary_tabs() as $suffix => $label ) {
            echo self::tab( $suffix, $label, $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* above.
        }
        echo '<details class="jnkui-more"><summary class="jnkui-tab' . ( $in_more ? " is-active" : "" ) . '">'
            . esc_html__( "More tools", "jnk-linkweave" ) . ' <span aria-hidden="true">▾</span></summary><div class="jnkui-menu">';
        foreach ( $more as $suffix => $label ) {
            echo self::tab( $suffix, $label, $current, "" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* above.
        }
        echo "</div></details></nav>";

        echo '<div class="jnkui-topbar-end">'
            . '<a href="https://jnkplugins.com/jnk-linkweave/faq.php" target="_blank" rel="noopener">' . esc_html__( "Help", "jnk-linkweave" ) . "</a>"
            . self::tab( "-settings", __( "Settings", "jnk-linkweave" ), $current )
            . "</div>";
        echo "</div>";

        // Admin notices are moved by WordPress to just after this marker.
        echo '<hr class="wp-header-end" style="display:none">';

        echo '<div class="jnkui-page"><div class="jnkui-head"><div><h1>' . esc_html( $title ) . "</h1>";
        if ( "" !== $description ) {
            echo "<p>" . esc_html( $description ) . "</p>";
        }
        echo "</div>";
        if ( "" !== $actions ) {
            echo '<div class="jnkui-head-actions">' . $actions . "</div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted markup from the calling view.
        }
        echo "</div>";
    }

    public static function close(): void {
        echo "</div></div>";
    }

    /** A friendly empty state. $action is trusted HTML (a button) or "". */
    public static function empty_state( string $title, string $text, string $action = "", string $icon = "check" ): string {
        $icons = [
            "check"  => '<path d="M20 6 9 17l-5-5"/>',
            "link"   => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            "search" => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        ];
        return '<div class="jnkui-empty"><div class="jnkui-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . ( $icons[ $icon ] ?? $icons["check"] ) . "</svg></div><h3>" . esc_html( $title ) . "</h3><p>" . esc_html( $text ) . "</p>" . $action . "</div>";
    }
}
