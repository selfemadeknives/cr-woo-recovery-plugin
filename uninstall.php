<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cr_email_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cr_cart_items" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cr_carts" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cr_email_templates" );

delete_option( 'cr_settings' );
delete_option( 'cr_db_version' );

wp_clear_scheduled_hook( 'cr_check_abandoned' );
