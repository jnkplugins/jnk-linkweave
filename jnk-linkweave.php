<?php
/**
 * Plugin Name:       JnK Linkweave
 * Plugin URI:        https://jnkplugins.com/jnk-linkweave/
 * Update URI:        https://api.jnkplugins.com/
 * Description:       Affordable, server-side internal linking for WordPress — suggestions, auto-linking rules, and orphan &amp; broken-link reports.
 * Version:           1.14.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            JnK Plugins
 * Author URI:        https://jnkplugins.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jnk-linkweave
 * Domain Path:       /languages
 */

defined( "ABSPATH" ) || exit;

define( "JNK_LINKWEAVE_VERSION", "1.14.0" );
define( "JNK_LINKWEAVE_FILE",    __FILE__ );
define( "JNK_LINKWEAVE_PATH",    plugin_dir_path( __FILE__ ) );
define( "JNK_LINKWEAVE_URL",     plugin_dir_url( __FILE__ ) );

// Composer autoload (optional; created on `composer install`).
if ( file_exists( JNK_LINKWEAVE_PATH . "vendor/autoload.php" ) ) {
    require_once JNK_LINKWEAVE_PATH . "vendor/autoload.php";
}

// Action Scheduler must be loaded directly (not via Composer autoload).
$jnk_linkweave_as = JNK_LINKWEAVE_PATH . "vendor/woocommerce/action-scheduler/action-scheduler.php";
if ( file_exists( $jnk_linkweave_as ) ) {
    require_once $jnk_linkweave_as;
}

/**
 * Plugin autoloader. Maps JnkLinkweave\Foo_Bar => includes/class-foo-bar.php
 * and JnkLinkweave\Admin\Foo => admin/class-foo.php. Files in this repo follow
 * the WordPress class-foo-bar.php naming, not strict PSR-4 — so we resolve
 * them ourselves rather than relying on Composer's PSR-4 lookup.
 *
 * Free classes live in includes/ (and admin/, editor/); premium classes live in
 * pro/includes/ (and pro/admin/), which the free wordpress.org build omits — so
 * those classes simply never load there. We check the free base first, then pro/.
 */
spl_autoload_register( static function ( $class ) {
    if ( 0 !== strpos( $class, "JnkLinkweave\\" ) ) {
        return;
    }
    $relative = substr( $class, strlen( "JnkLinkweave\\" ) );
    $parts    = explode( "\\", $relative );
    $leaf     = array_pop( $parts );
    $file     = "class-" . strtolower( str_replace( "_", "-", $leaf ) ) . ".php";
    $sub      = empty( $parts ) ? "includes/" : strtolower( implode( "/", $parts ) ) . "/";
    foreach ( [ JNK_LINKWEAVE_PATH, JNK_LINKWEAVE_PATH . "pro/" ] as $root ) {
        $path = $root . $sub . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
            return;
        }
    }
} );

require_once JNK_LINKWEAVE_PATH . "includes/class-activator.php";
require_once JNK_LINKWEAVE_PATH . "includes/class-deactivator.php";

// Pro module: licensing SDK + premium features (URL changer, LLM re-ranking, CSV
// export). Absent from the free wordpress.org build, so when it isn't present the
// plugin runs as the fully-functional free tier — no SDK, no phone-home, no caps.
if ( file_exists( JNK_LINKWEAVE_PATH . "pro/bootstrap.php" ) ) {
    require_once JNK_LINKWEAVE_PATH . "pro/bootstrap.php";
}

register_activation_hook(   __FILE__, [ "JnkLinkweave_Activator",   "activate"   ] );
register_deactivation_hook( __FILE__, [ "JnkLinkweave_Deactivator", "deactivate" ] );

add_action( "plugins_loaded", static function () {
    if ( class_exists( "\\JnkLinkweave\\Plugin" ) ) {
        \JnkLinkweave\Plugin::instance()->boot();
    }
}, 20 );
