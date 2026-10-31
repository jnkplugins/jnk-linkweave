<?php
namespace JnkLinkweave\Admin;

use JnkLinkweave\Abilities;

defined( "ABSPATH" ) || exit;

/**
 * The "AI assistants" screen: what an assistant may do on this site, how to
 * connect one, and what it did. Saves through admin-post (nonce +
 * manage_options), and installs the MCP Adapter plugin from wordpress.org in
 * one click for people who may install plugins.
 */
class Assistants {

    public const ADAPTER      = "mcp-adapter/mcp-adapter.php";
    public const ADAPTER_SLUG = "mcp-adapter";
    private const NOTICE      = "jnk_linkweave_assistants_notice";

    public static function register(): void {
        add_action( "admin_post_jnk_linkweave_assistants_save", [ __CLASS__, "save" ] );
        add_action( "admin_post_jnk_linkweave_install_adapter", [ __CLASS__, "install_adapter" ] );
    }

    public static function url(): string {
        return admin_url( "admin.php?page=" . Admin::MENU_SLUG . "-assistants" );
    }

    /**
     * Levels the screen offers. The free plugin only reads; the Pro module adds
     * "edit" and "delete" with its link tools.
     *
     * @return string[]
     */
    public static function levels(): array {
        $levels = (array) apply_filters( "jnk_linkweave_ai_levels", [ "read" ] );
        return array_values( array_intersect( array_keys( Abilities::LEVELS ), $levels ) );
    }

    /** "active", "installed" or "missing". A copy bundled inside another plugin counts as active. */
    public static function adapter_state(): string {
        if ( class_exists( "\\WP\\MCP\\Core\\McpAdapter" ) ) {
            return "active";
        }
        if ( ! function_exists( "get_plugins" ) ) {
            require_once ABSPATH . "wp-admin/includes/plugin.php";
        }
        return isset( get_plugins()[ self::ADAPTER ] ) ? "installed" : "missing";
    }

    /** The default MCP server the adapter registers. */
    public static function endpoint(): string {
        return rest_url( "mcp/mcp-adapter-default-server" );
    }

    /**
     * Another MCP server on this site that signs in with OAuth (Rank Math adds
     * one at /mcp/mcp-oauth-server), or "". Web apps can connect to those.
     */
    public static function oauth_endpoint(): string {
        $routes = rest_get_server()->get_routes( "mcp" );
        foreach ( array_keys( $routes ) as $route ) {
            if ( false !== stripos( $route, "oauth" ) ) {
                return rest_url( ltrim( $route, "/" ) );
            }
        }
        return "";
    }

    public static function save(): void {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( esc_html__( "Sorry, you are not allowed to do that.", "jnk-linkweave" ), 403 );
        }
        check_admin_referer( "jnk_linkweave_assistants_save" );
        $opts = (array) get_option( "jnk_linkweave_settings", [] );
        foreach ( self::levels() as $level ) {
            $key          = Abilities::LEVELS[ $level ];
            $opts[ $key ] = ! empty( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
        }
        // Edit or delete without read would leave an assistant changing links it cannot see.
        if ( ! empty( $opts["ai_edit"] ) || ! empty( $opts["ai_delete"] ) ) {
            $opts["ai_read"] = true;
        }
        // Delete is the stronger switch: it only makes sense with edit.
        if ( ! empty( $opts["ai_delete"] ) ) {
            $opts["ai_edit"] = true;
        }
        unset( $opts["abilities_public"] );
        update_option( "jnk_linkweave_settings", $opts );
        self::redirect( "saved" );
    }

    public static function install_adapter(): void {
        if ( ! current_user_can( "install_plugins" ) || ! current_user_can( "activate_plugins" ) ) {
            wp_die( esc_html__( "Sorry, you are not allowed to install plugins on this site.", "jnk-linkweave" ), 403 );
        }
        check_admin_referer( "jnk_linkweave_install_adapter" );

        require_once ABSPATH . "wp-admin/includes/plugin.php";
        if ( "missing" === self::adapter_state() ) {
            require_once ABSPATH . "wp-admin/includes/plugin-install.php";
            require_once ABSPATH . "wp-admin/includes/class-wp-upgrader.php";
            require_once ABSPATH . "wp-admin/includes/file.php";
            require_once ABSPATH . "wp-admin/includes/misc.php";
            $api = plugins_api( "plugin_information", [ "slug" => self::ADAPTER_SLUG, "fields" => [ "sections" => false ] ] );
            if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
                self::redirect( "install_failed" );
            }
            // A skin that never prints and never asks for FTP details: when WordPress
            // cannot write files directly, the install fails and we say so.
            $skin     = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader( $skin );
            $result   = $upgrader->install( $api->download_link );
            if ( true !== $result || ! isset( get_plugins()[ self::ADAPTER ] ) ) {
                self::redirect( "install_failed" );
            }
        }
        if ( "active" !== self::adapter_state() ) {
            $activated = activate_plugin( self::ADAPTER );
            if ( is_wp_error( $activated ) ) {
                self::redirect( "activate_failed" );
            }
        }
        self::redirect( "adapter_ready" );
    }

    private static function redirect( string $notice ): void {
        set_transient( self::NOTICE . "_" . get_current_user_id(), $notice, 60 );
        wp_safe_redirect( self::url() );
        exit;
    }

    /** One-time message after a save or an install. */
    public static function take_notice(): string {
        $key    = self::NOTICE . "_" . get_current_user_id();
        $notice = (string) get_transient( $key );
        delete_transient( $key );
        return $notice;
    }

    public function render(): void {
        include JNK_LINKWEAVE_PATH . "admin/views/assistants.php";
    }
}
