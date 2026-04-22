<?php
/**
 * Plugin Name: Cart Recovery for WooCommerce
 * Description: Track abandoned carts, view customer details, and send personalised recovery emails — all from your WordPress admin.
 * Version:     1.1.2
 * Author:      Your Shop
 * License:     GPL-2.0+
 * Text Domain: cr-woo-recovery
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CR_VERSION',     '1.1.2' );
define( 'CR_PLUGIN_FILE', __FILE__ );
define( 'CR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Helper functions (always loaded)
require_once plugin_dir_path( __FILE__ ) . 'includes/helpers.php';

// Autoload classes
spl_autoload_register( function ( string $class ) {
    $map = [
        'CR_DB'       => CR_PLUGIN_DIR . 'includes/class-db.php',
        'CR_Tracker'  => CR_PLUGIN_DIR . 'includes/class-tracker.php',
        'CR_Cron'     => CR_PLUGIN_DIR . 'includes/class-cron.php',
        'CR_Emailer'  => CR_PLUGIN_DIR . 'includes/class-emailer.php',
        'CR_Updater'  => CR_PLUGIN_DIR . 'includes/class-updater.php',
        'CR_Admin'    => CR_PLUGIN_DIR . 'admin/class-admin.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require_once $map[ $class ];
    }
} );

// Activation / deactivation / uninstall
register_activation_hook( __FILE__, [ 'CR_DB', 'install' ] );
register_deactivation_hook( __FILE__, [ 'CR_Cron', 'deactivate' ] );

// Boot
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Cart Recovery</strong> requires WooCommerce to be installed and active.</p></div>';
        } );
        return;
    }

    // Run DB migrations on any version change without requiring a deactivate/reactivate cycle.
    CR_DB::maybe_upgrade();

    CR_Tracker::init();
    CR_Cron::init();
    CR_Updater::init();

    if ( is_admin() ) {
        CR_Admin::init();
    }
} );

// Block wordpress.org from treating this private plugin as a public repo plugin.
// Without this, WP matches the folder slug against wp.org and may show false update notices.
add_filter( 'site_transient_update_plugins', function ( $transient ) {
    $plugin_file = plugin_basename( CR_PLUGIN_FILE );
    if ( isset( $transient->response[ $plugin_file ] ) ) {
        unset( $transient->response[ $plugin_file ] );
    }
    return $transient;
} );
