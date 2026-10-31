<?php
defined( "ABSPATH" ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables, not globals.

use JnkLinkweave\Abilities;
use JnkLinkweave\Admin\Assistants;

$available = Abilities::available();
$notice    = Assistants::take_notice();
$adapter   = Assistants::adapter_state();
$levels    = Assistants::levels();
$user      = wp_get_current_user();

/**
 * What each switch allows, in plain words. The Pro module adds its own levels.
 *
 * @var array<string,array{label:string,text:string}> $level_info
 */
$level_info = (array) apply_filters( "jnk_linkweave_ai_level_info", [
    "read" => [
        "label" => __( "Read reports", "jnk-linkweave" ),
        "text"  => __( "Orphan posts, link counts, the links of a post, broken links, auto-link rules and link suggestions. Nothing is changed.", "jnk-linkweave" ),
    ],
] );

$notices = [
    "saved"           => [ "success", __( "Saved.", "jnk-linkweave" ) ],
    "adapter_ready"   => [ "success", __( "The MCP Adapter plugin is installed and active.", "jnk-linkweave" ) ],
    "install_failed"  => [ "error", __( "WordPress could not install the MCP Adapter plugin here (it may not be allowed to write files). Install it from Plugins > Add New: search for “MCP Adapter”.", "jnk-linkweave" ) ],
    "activate_failed" => [ "error", __( "The MCP Adapter plugin is installed but could not be activated. Activate it on the Plugins screen.", "jnk-linkweave" ) ],
];

\JnkLinkweave\Admin\UI::open(
    __( "AI assistants", "jnk-linkweave" ),
    __( "Let an AI assistant such as Claude or Cursor work with your internal links. You choose what it may do.", "jnk-linkweave" )
);

if ( isset( $notices[ $notice ] ) ) {
    printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notices[ $notice ][0] ), esc_html( $notices[ $notice ][1] ) );
}
?>

<?php if ( ! $available ) : ?>
    <div class="jnkui-card"><div class="jnkui-card-body">
        <p><?php esc_html_e( "This needs WordPress 6.9 or newer, which added the Abilities API that AI tools use. Update WordPress to use it.", "jnk-linkweave" ); ?></p>
    </div></div>
<?php else : ?>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( "1. What assistants may do", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "Off by default. An assistant only ever acts as the user it signs in as, so it can never do more than that user could do on these screens.", "jnk-linkweave" ); ?></p></div></div>
    <div class="jnkui-card-body">
        <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>">
            <input type="hidden" name="action" value="jnk_linkweave_assistants_save">
            <?php wp_nonce_field( "jnk_linkweave_assistants_save" ); ?>
            <?php foreach ( $levels as $level ) :
                if ( ! isset( $level_info[ $level ] ) ) {
                    continue;
                }
                $key = Abilities::LEVELS[ $level ];
                ?>
                <p>
                    <label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( Abilities::exposed( $level ) ); ?>>
                    <strong><?php echo esc_html( $level_info[ $level ]["label"] ); ?></strong></label><br>
                    <span class="jnkui-sub" style="margin-inline-start:24px"><?php echo esc_html( $level_info[ $level ]["text"] ); ?></span>
                </p>
            <?php endforeach; ?>
            <?php
            /** The Pro module adds a line here about the abilities that change links. */
            do_action( "jnk_linkweave_assistants_after_switches" );
            ?>
            <p><button type="submit" class="button button-primary"><?php esc_html_e( "Save", "jnk-linkweave" ); ?></button></p>
        </form>
    </div>
</div>

<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( "2. Connect your assistant", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "Assistants reach WordPress through the free MCP Adapter plugin, made by the WordPress AI team.", "jnk-linkweave" ); ?></p></div></div>
    <div class="jnkui-card-body">
        <h3 style="margin-top:0"><?php esc_html_e( "MCP Adapter plugin", "jnk-linkweave" ); ?>
            <?php if ( "active" === $adapter ) : ?>
                <span class="jnkui-pill is-ok"><?php esc_html_e( "Active", "jnk-linkweave" ); ?></span>
            <?php elseif ( "installed" === $adapter ) : ?>
                <span class="jnkui-pill is-warn"><?php esc_html_e( "Installed, not active", "jnk-linkweave" ); ?></span>
            <?php else : ?>
                <span class="jnkui-pill is-warn"><?php esc_html_e( "Not installed", "jnk-linkweave" ); ?></span>
            <?php endif; ?>
        </h3>
        <?php if ( "active" !== $adapter ) : ?>
            <?php if ( current_user_can( "install_plugins" ) && current_user_can( "activate_plugins" ) && ! ( defined( "DISALLOW_FILE_MODS" ) && DISALLOW_FILE_MODS && "missing" === $adapter ) ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>">
                    <input type="hidden" name="action" value="jnk_linkweave_install_adapter">
                    <?php wp_nonce_field( "jnk_linkweave_install_adapter" ); ?>
                    <button type="submit" class="button button-primary">
                        <?php echo "missing" === $adapter ? esc_html__( "Install and activate MCP Adapter", "jnk-linkweave" ) : esc_html__( "Activate MCP Adapter", "jnk-linkweave" ); ?>
                    </button>
                    <span class="jnkui-sub"><?php esc_html_e( "From wordpress.org. You can remove it any time on the Plugins screen.", "jnk-linkweave" ); ?></span>
                </form>
            <?php else : ?>
                <p><?php esc_html_e( "Ask the site administrator to install and activate the MCP Adapter plugin from wordpress.org.", "jnk-linkweave" ); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <h3><?php esc_html_e( "Application password", "jnk-linkweave" ); ?></h3>
        <?php $no_app_passwords = function_exists( "wp_is_application_passwords_available" ) && ! wp_is_application_passwords_available(); ?>
        <?php if ( $no_app_passwords ) : ?>
            <p><span class="jnkui-pill is-warn"><?php esc_html_e( "Not available", "jnk-linkweave" ); ?></span>
            <?php esc_html_e( "WordPress only offers application passwords on sites served over HTTPS. Switch the site to HTTPS first.", "jnk-linkweave" ); ?></p>
            <p><?php esc_html_e( "On a site on your own computer with WP-CLI, an assistant can connect without one. Use this as the command in its MCP settings:", "jnk-linkweave" ); ?></p>
            <textarea class="large-text code" rows="2" readonly onclick="this.select()" dir="ltr"><?php echo esc_textarea( sprintf( "wp --path=%s mcp-adapter serve --server=mcp-adapter-default-server --user=%s", escapeshellarg( untrailingslashit( ABSPATH ) ), escapeshellarg( $user->user_login ) ) ); ?></textarea>
        <?php else : ?>
            <p><?php esc_html_e( "Your assistant signs in with an application password: a separate password you can revoke without changing your own. Name it after the assistant, for example “Claude”.", "jnk-linkweave" ); ?>
            <a class="button" href="<?php echo esc_url( admin_url( "profile.php#application-passwords-section" ) ); ?>"><?php esc_html_e( "Create an application password", "jnk-linkweave" ); ?></a></p>
            <p class="jnkui-sub"><?php esc_html_e( "Tip: an assistant can do whatever the user it signs in as can do. A separate user with only the role it needs is the safest choice.", "jnk-linkweave" ); ?></p>
        <?php endif; ?>

        <?php
        $config = wp_json_encode( [
            "mcpServers" => [
                "wordpress" => [
                    "command" => "npx",
                    "args"    => [ "-y", "@automattic/mcp-wordpress-remote@latest" ],
                    "env"     => [
                        "WP_API_URL"      => Assistants::endpoint(),
                        "WP_API_USERNAME" => $user->user_login,
                        "WP_API_PASSWORD" => "YOUR-APPLICATION-PASSWORD",
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $cli = sprintf(
            "claude mcp add wordpress -e WP_API_URL=%s -e WP_API_USERNAME=%s -e WP_API_PASSWORD=YOUR-APPLICATION-PASSWORD -- npx -y @automattic/mcp-wordpress-remote@latest",
            escapeshellarg( Assistants::endpoint() ),
            escapeshellarg( $user->user_login )
        );
        ?>
        <h3><?php esc_html_e( "Claude Desktop, Cursor and other desktop apps", "jnk-linkweave" ); ?></h3>
        <p><?php esc_html_e( "Add this to the app's MCP settings file (in Claude Desktop: Settings > Developer > Edit Config), put your application password in, and restart the app. It needs Node.js on your computer.", "jnk-linkweave" ); ?></p>
        <textarea class="large-text code" rows="13" readonly onclick="this.select()" dir="ltr"><?php echo esc_textarea( (string) $config ); ?></textarea>

        <h3><?php esc_html_e( "Claude Code", "jnk-linkweave" ); ?></h3>
        <textarea class="large-text code" rows="3" readonly onclick="this.select()" dir="ltr"><?php echo esc_textarea( $cli ); ?></textarea>

        <?php $oauth = Assistants::oauth_endpoint(); ?>
        <?php if ( "" !== $oauth ) : ?>
            <p class="jnkui-sub"><?php
                printf(
                    /* translators: %s: web address of the other MCP server. */
                    esc_html__( "Another plugin on this site (for example Rank Math) added an MCP server that signs in with OAuth, at %s. Apps in a web browser, such as ChatGPT or Claude, may be able to connect there; follow that plugin's instructions. It offers the same Linkweave abilities you allow above.", "jnk-linkweave" ),
                    '<code dir="ltr">' . esc_html( $oauth ) . "</code>"
                );
            ?></p>
        <?php else : ?>
            <p class="jnkui-sub"><?php esc_html_e( "ChatGPT and Claude in a web browser cannot connect to a WordPress site this way yet: they need a sign-in method (OAuth) that WordPress does not offer yet.", "jnk-linkweave" ); ?></p>
        <?php endif; ?>
        <p class="jnkui-sub"><?php esc_html_e( "Try asking: “Which of my posts have no internal links pointing to them?”", "jnk-linkweave" ); ?></p>
    </div>
</div>

<?php $ours = Abilities::ours(); ?>
<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( "3. What an assistant sees", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "Every Linkweave ability on this site and whether assistants can use it now.", "jnk-linkweave" ); ?></p></div></div>
    <div class="jnkui-table-wrap"><table class="jnk-linkweave-table">
        <thead><tr><th><?php esc_html_e( "Ability", "jnk-linkweave" ); ?></th><th><?php esc_html_e( "What it does", "jnk-linkweave" ); ?></th><th><?php esc_html_e( "Kind", "jnk-linkweave" ); ?></th><th><?php esc_html_e( "Assistants", "jnk-linkweave" ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $ours as $ability ) :
            $level = (string) $ability->get_meta_item( "jnk_level", "read" );
            $kinds = [
                "read"   => [ "is-ok", __( "Reads", "jnk-linkweave" ) ],
                "edit"   => [ "is-warn", __( "Changes posts", "jnk-linkweave" ) ],
                "delete" => [ "is-bad", __( "Big changes", "jnk-linkweave" ) ],
            ];
            [ $kind_class, $kind_label ] = $kinds[ $level ] ?? $kinds["read"];
            $on = (bool) $ability->get_meta_item( "show_in_rest", false );
            ?>
            <tr>
                <td dir="ltr"><code><?php echo esc_html( $ability->get_name() ); ?></code><span class="jnkui-sub"><?php echo esc_html( $ability->get_label() ); ?></span></td>
                <td><?php echo esc_html( $ability->get_description() ); ?></td>
                <td><span class="jnkui-pill <?php echo esc_attr( $kind_class ); ?>"><?php echo esc_html( $kind_label ); ?></span></td>
                <td><?php echo $on ? '<span class="jnkui-pill is-ok">' . esc_html__( "Allowed", "jnk-linkweave" ) . "</span>" : '<span class="jnkui-pill">' . esc_html__( "Off", "jnk-linkweave" ) . "</span>"; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php $entries = Abilities::log_entries( 50 ); ?>
<div class="jnkui-card">
    <div class="jnkui-card-head"><div><h2><?php esc_html_e( "4. What assistants did", "jnk-linkweave" ); ?></h2>
        <p><?php esc_html_e( "The last 50 requests. Linkweave keeps the newest 200.", "jnk-linkweave" ); ?></p></div></div>
    <?php if ( ! $entries ) : ?>
        <div class="jnkui-card-body"><p class="jnkui-sub"><?php esc_html_e( "No requests yet.", "jnk-linkweave" ); ?></p></div>
    <?php else : ?>
    <div class="jnkui-table-wrap"><table class="jnk-linkweave-table">
        <thead><tr><th><?php esc_html_e( "When", "jnk-linkweave" ); ?></th><th><?php esc_html_e( "User", "jnk-linkweave" ); ?></th><th><?php esc_html_e( "Ability", "jnk-linkweave" ); ?></th><th><?php esc_html_e( "Asked", "jnk-linkweave" ); ?></th><th><?php esc_html_e( "Result", "jnk-linkweave" ); ?></th></tr></thead>
        <tbody>
        <?php foreach ( $entries as $e ) :
            $who = get_userdata( (int) ( $e["u"] ?? 0 ) );
            ?>
            <tr>
                <td><?php echo esc_html( wp_date( get_option( "date_format" ) . " " . get_option( "time_format" ), (int) ( $e["t"] ?? 0 ) ) ); ?></td>
                <td><?php echo esc_html( $who ? $who->display_name : "-" ); ?></td>
                <td dir="ltr"><code><?php echo esc_html( (string) ( $e["a"] ?? "" ) ); ?></code></td>
                <td dir="ltr"><code><?php echo esc_html( (string) ( $e["i"] ?? "" ) ); ?></code></td>
                <td><?php echo ! empty( $e["o"] ) ? '<span class="jnkui-pill is-ok">' . esc_html__( "Done", "jnk-linkweave" ) . "</span>" : '<span class="jnkui-pill is-bad">' . esc_html__( "Failed", "jnk-linkweave" ) . "</span>"; ?>
                    <?php if ( ! empty( $e["m"] ) ) : ?><span class="jnkui-sub"><?php echo esc_html( (string) $e["m"] ); ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php
/** The Pro module lists recent link changes with an Undo button here. */
do_action( "jnk_linkweave_assistants_after_log" );
?>

<?php endif; ?>
<?php \JnkLinkweave\Admin\UI::close(); ?>
