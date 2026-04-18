<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CR_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_cr_send_email',     [ __CLASS__, 'ajax_send_email' ] );
        add_action( 'wp_ajax_cr_update_status',  [ __CLASS__, 'ajax_update_status' ] );
        add_action( 'wp_ajax_cr_save_notes',     [ __CLASS__, 'ajax_save_notes' ] );
        add_action( 'wp_ajax_cr_sync_now',       [ __CLASS__, 'ajax_sync_now' ] );
        add_action( 'wp_ajax_cr_delete_template',[ __CLASS__, 'ajax_delete_template' ] );
        add_action( 'admin_post_cr_save_settings',  [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'admin_post_cr_save_template',  [ __CLASS__, 'handle_save_template' ] );
    }

    // ------------------------------------------------------------------ menu

    public static function register_menu(): void {
        add_menu_page(
            'Cart Recovery',
            'Cart Recovery',
            'manage_woocommerce',
            'cart-recovery',
            [ __CLASS__, 'page_dashboard' ],
            'dashicons-cart',
            56
        );
        add_submenu_page( 'cart-recovery', 'Dashboard',     'Dashboard',     'manage_woocommerce', 'cart-recovery',               [ __CLASS__, 'page_dashboard' ] );
        add_submenu_page( 'cart-recovery', 'Carts',         'Carts',         'manage_woocommerce', 'cr-carts',                    [ __CLASS__, 'page_carts' ] );
        add_submenu_page( 'cart-recovery', 'Cart Detail',   '',              'manage_woocommerce', 'cr-cart-detail',              [ __CLASS__, 'page_cart_detail' ] );
        add_submenu_page( 'cart-recovery', 'Email History', 'Email History', 'manage_woocommerce', 'cr-email-history',            [ __CLASS__, 'page_email_history' ] );
        add_submenu_page( 'cart-recovery', 'Insights',      'Insights',      'manage_woocommerce', 'cr-insights',                 [ __CLASS__, 'page_insights' ] );
        add_submenu_page( 'cart-recovery', 'Settings',      'Settings',      'manage_woocommerce', 'cr-settings',                 [ __CLASS__, 'page_settings' ] );
    }

    // ------------------------------------------------------------------ assets

    public static function enqueue_assets( string $hook ): void {
        $cr_pages = [
            'toplevel_page_cart-recovery',
            'cart-recovery_page_cr-carts',
            'cart-recovery_page_cr-cart-detail',
            'cart-recovery_page_cr-email-history',
            'cart-recovery_page_cr-insights',
            'cart-recovery_page_cr-settings',
        ];

        if ( ! in_array( $hook, $cr_pages, true ) ) return;

        wp_enqueue_style(
            'cr-admin',
            CR_PLUGIN_URL . 'admin/assets/admin.css',
            [],
            CR_VERSION
        );
        wp_enqueue_script(
            'cr-admin',
            CR_PLUGIN_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            CR_VERSION,
            true
        );
        wp_localize_script( 'cr-admin', 'crAjax', [
            'url'   => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'cr_ajax' ),
        ] );

        // On the cart-detail page, pass templates as a JS object so the switcher
        // never reads HTML attributes — avoids reflected-XSS via data-body/data-subject.
        if ( $hook === 'cart-recovery_page_cr-cart-detail' ) {
            $tpl_data = [];
            foreach ( CR_DB::get_templates() as $tpl ) {
                $tpl_data[ (int) $tpl->id ] = [
                    'subject' => $tpl->subject,
                    'body'    => $tpl->body_html,
                ];
            }
            wp_localize_script( 'cr-admin', 'crTemplates', $tpl_data );
        }
    }

    // ------------------------------------------------------------------ pages

    public static function page_dashboard(): void {
        require CR_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    public static function page_carts(): void {
        require CR_PLUGIN_DIR . 'admin/views/carts.php';
    }
    public static function page_cart_detail(): void {
        require CR_PLUGIN_DIR . 'admin/views/cart-detail.php';
    }
    public static function page_email_history(): void {
        require CR_PLUGIN_DIR . 'admin/views/email-history.php';
    }
    public static function page_insights(): void {
        require CR_PLUGIN_DIR . 'admin/views/insights.php';
    }
    public static function page_settings(): void {
        require CR_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ------------------------------------------------------------------ AJAX

    public static function ajax_send_email(): void {
        check_ajax_referer( 'cr_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $cart_id     = (int) ( $_POST['cart_id']     ?? 0 );
        $template_id = (int) ( $_POST['template_id'] ?? 0 );
        $subject     = sanitize_text_field( wp_unslash( $_POST['subject']   ?? '' ) );
        $body_html   = cr_kses_email( wp_unslash( $_POST['body_html'] ?? '' ) );

        if ( ! $cart_id || ! $subject || ! $body_html ) {
            wp_send_json_error( 'Missing required fields' );
        }

        // Rate-limit: one email per cart per 5 minutes to prevent accidental double-sends.
        $rate_key = 'cr_email_sent_' . $cart_id;
        if ( get_transient( $rate_key ) ) {
            wp_send_json_error( 'An email was sent for this cart very recently. Please wait a few minutes before sending again.' );
        }
        set_transient( $rate_key, 1, 5 * MINUTE_IN_SECONDS );

        $result = CR_Emailer::send( $cart_id, $template_id, $subject, $body_html );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result['error'] );
    }

    public static function ajax_update_status(): void {
        check_ajax_referer( 'cr_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $cart_id = (int) ( $_POST['cart_id'] ?? 0 );
        $status  = sanitize_key( $_POST['status'] ?? '' );
        $allowed = [ 'abandoned', 'email_sent', 'recovered', 'ignored' ];

        if ( ! $cart_id || ! in_array( $status, $allowed, true ) ) {
            wp_send_json_error( 'Invalid parameters' );
        }

        CR_DB::update_cart_status( $cart_id, $status );
        wp_send_json_success( [ 'status' => $status ] );
    }

    public static function ajax_save_notes(): void {
        check_ajax_referer( 'cr_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $cart_id = (int) ( $_POST['cart_id'] ?? 0 );
        $notes   = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

        if ( ! $cart_id ) wp_send_json_error( 'Invalid cart' );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cr_carts',
            [ 'notes' => $notes, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $cart_id ]
        );
        wp_send_json_success();
    }

    public static function ajax_sync_now(): void {
        check_ajax_referer( 'cr_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        CR_Cron::run();
        wp_send_json_success( [ 'message' => 'Sync complete' ] );
    }

    public static function ajax_delete_template(): void {
        check_ajax_referer( 'cr_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $id = (int) ( $_POST['template_id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid template' );
        CR_DB::delete_template( $id );
        wp_send_json_success();
    }

    // ------------------------------------------------------------------ form handlers

    public static function handle_save_settings(): void {
        check_admin_referer( 'cr_save_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $allowed = [
            'shop_name', 'from_name',
            'abandonment_threshold', 'lookback_days',
            'cron_interval',
            'exit_intent_mobile_delay', 'exit_intent_gdpr_text',
        ];
        $settings = get_option( 'cr_settings', [] );
        foreach ( $allowed as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $settings[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
            }
        }
        // from_email must be sanitized as an email address, not plain text.
        if ( isset( $_POST['from_email'] ) ) {
            $settings['from_email'] = sanitize_email( wp_unslash( $_POST['from_email'] ) );
        }
        update_option( 'cr_settings', $settings );

        // Re-schedule cron with new interval
        $interval = $settings['cron_interval'] ?? 'cr_every_15_min';
        $ts = wp_next_scheduled( CR_Cron::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, CR_Cron::HOOK );
        wp_schedule_event( time(), $interval, CR_Cron::HOOK );

        wp_safe_redirect( admin_url( 'admin.php?page=cr-settings&saved=1' ) );
        exit;
    }

    public static function handle_save_template(): void {
        check_admin_referer( 'cr_save_template' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $id        = (int) ( $_POST['template_id'] ?? 0 );
        $name      = sanitize_text_field( wp_unslash( $_POST['name']      ?? '' ) );
        $subject   = sanitize_text_field( wp_unslash( $_POST['subject']   ?? '' ) );
        $body_html = cr_kses_email( wp_unslash( $_POST['body_html'] ?? '' ) );

        if ( ! $name || ! $subject || ! $body_html ) {
            wp_safe_redirect( admin_url( 'admin.php?page=cr-settings&tab=templates&error=missing_fields' ) );
            exit;
        }

        CR_DB::save_template( compact( 'name', 'subject', 'body_html' ), $id ?: null );
        wp_safe_redirect( admin_url( 'admin.php?page=cr-settings&tab=templates&saved=1' ) );
        exit;
    }

    // ------------------------------------------------------------------ helpers

    public static function admin_url( string $page, array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => $page ], $args ), admin_url( 'admin.php' ) );
    }
}
