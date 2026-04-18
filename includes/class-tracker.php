<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks into WooCommerce to capture cart data as customers browse.
 *
 * Capture chain:
 * 1. woocommerce_add_to_cart / woocommerce_cart_updated  → save snapshot
 * 2. checkout.js blur on #billing_email                  → attach guest email before submission
 * 3. woocommerce_checkout_posted_data                    → attach guest email on submission (fallback)
 * 4. woocommerce_checkout_order_processed                → mark recovered
 * 5. CR_Cron                                             → detect abandoned sessions
 */
class CR_Tracker {

    /** Flag to prevent duplicate cart saves within a single request. */
    private static bool $snapshot_scheduled = false;

    public static function init(): void {
        // Capture cart changes — schedule a single save per request to avoid duplicate writes
        add_action( 'woocommerce_add_to_cart',                    [ __CLASS__, 'schedule_snapshot' ], 20 );
        add_action( 'woocommerce_cart_item_removed',              [ __CLASS__, 'schedule_snapshot' ], 20 );
        add_action( 'woocommerce_cart_item_restored',             [ __CLASS__, 'schedule_snapshot' ], 20 );
        add_action( 'woocommerce_after_cart_item_quantity_update', [ __CLASS__, 'schedule_snapshot' ], 20 );

        // Enqueue checkout JS — captures email on field blur before any form submission
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_checkout_script' ] );
        add_action( 'wp_ajax_cr_capture_checkout_email',        [ __CLASS__, 'ajax_capture_checkout_email' ] );
        add_action( 'wp_ajax_nopriv_cr_capture_checkout_email', [ __CLASS__, 'ajax_capture_checkout_email' ] );

        // Enqueue exit-intent popup — captures email on any page before visitor leaves
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_exit_intent_script' ] );
        add_action( 'wp_ajax_cr_capture_exit_email',        [ __CLASS__, 'ajax_capture_exit_email' ] );
        add_action( 'wp_ajax_nopriv_cr_capture_exit_email', [ __CLASS__, 'ajax_capture_exit_email' ] );

        // Capture guest billing email at checkout (fallback: fires on form submission)
        add_action( 'woocommerce_checkout_posted_data', [ __CLASS__, 'capture_guest_email' ], 10, 1 );

        // Mark recovered when an order is successfully placed or paid
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'on_order_placed' ], 10, 3 );
        add_action( 'woocommerce_payment_complete',          [ __CLASS__, 'on_payment_complete' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed',    [ __CLASS__, 'on_order_status_change' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing',   [ __CLASS__, 'on_order_status_change' ], 10, 1 );
    }

    // ------------------------------------------------------------------ cart snapshot

    /**
     * Schedule a single snapshot save after all cart mutations in a request complete.
     * Using a flag prevents multiple DB writes when several cart actions fire at once.
     */
    public static function schedule_snapshot(): void {
        if ( self::$snapshot_scheduled ) return;
        self::$snapshot_scheduled = true;
        add_action( 'woocommerce_cart_updated', [ __CLASS__, 'save_cart_snapshot' ], 20 );
    }

    public static function save_cart_snapshot(): void {
        // Defensive null checks before accessing WC globals
        if ( ! function_exists( 'WC' ) || ! WC() instanceof WooCommerce ) return;
        if ( ! WC()->cart instanceof WC_Cart ) return;
        if ( WC()->cart->is_empty() ) return;

        $session_key = self::get_session_key();
        if ( ! $session_key ) return;

        $cart_items = self::extract_cart_items();
        if ( empty( $cart_items ) ) return;

        $customer = self::get_customer_data();

        // Prefer subtotal (ex tax) so we store a consistent pre-tax figure
        $cart_total = (float) WC()->cart->get_subtotal();
        if ( $cart_total <= 0 ) {
            $cart_total = (float) WC()->cart->get_cart_total();
        }

        $data = [
            'source'           => 'wc_session',
            'status'           => 'active',
            'customer_name'    => $customer['name'],
            'customer_email'   => $customer['email'],
            'customer_ip'      => self::get_client_ip(),
            'customer_city'    => $customer['city'],
            'customer_county'  => $customer['county'],
            'customer_country' => $customer['country'],
            'cart_total'       => $cart_total,
            'currency'         => get_woocommerce_currency(),
            'item_count'       => WC()->cart->get_cart_contents_count(),
            'last_activity'    => current_time( 'mysql' ),
        ];

        $cart_id = CR_DB::upsert_cart( $session_key, $data );
        CR_DB::replace_cart_items( $cart_id, $cart_items );
        CR_DB::bust_insights_cache();
    }

    // ------------------------------------------------------------------ guest email capture

    public static function capture_guest_email( array $data ): array {
        $email = sanitize_email( $data['billing_email'] ?? '' );
        if ( ! is_email( $email ) ) return $data;

        $session_key = self::get_session_key();
        if ( ! $session_key ) return $data;

        $existing = CR_DB::get_cart_by_session( $session_key );
        if ( $existing && empty( $existing->customer_email ) ) {
            global $wpdb;
            $first = sanitize_text_field( $data['billing_first_name'] ?? '' );
            $last  = sanitize_text_field( $data['billing_last_name']  ?? '' );
            $name  = trim( "$first $last" ) ?: null;

            $wpdb->update(
                $wpdb->prefix . 'cr_carts',
                [
                    'customer_email'  => $email,
                    'customer_name'   => $name,
                    'customer_city'   => sanitize_text_field( $data['billing_city']    ?? '' ) ?: null,
                    'customer_county' => sanitize_text_field( $data['billing_state']   ?? '' ) ?: null,
                    'customer_country'=> sanitize_text_field( $data['billing_country'] ?? 'GB' ),
                ],
                [ 'session_key' => $session_key ]
            );
        }
        return $data;
    }

    // ------------------------------------------------------------------ order recovery

    public static function on_order_placed( int $order_id, array $posted_data, WC_Order $order ): void {
        self::mark_recovered_from_order( $order );
    }

    public static function on_payment_complete( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( $order instanceof WC_Order ) {
            self::mark_recovered_from_order( $order );
        }
    }

    public static function on_order_status_change( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( $order instanceof WC_Order ) {
            self::mark_recovered_from_order( $order );
        }
    }

    private static function mark_recovered_from_order( WC_Order $order ): void {
        $email = sanitize_email( $order->get_billing_email() );
        if ( is_email( $email ) ) {
            CR_DB::mark_recovered_by_email( $email );
        }

        // Also recover by session key in case email didn't match
        $session_key = self::get_session_key();
        if ( $session_key ) {
            $cart = CR_DB::get_cart_by_session( $session_key );
            if ( $cart && ! in_array( $cart->status, [ 'recovered', 'ignored' ], true ) ) {
                CR_DB::update_cart_status( (int) $cart->id, 'recovered', [
                    'woo_order_id' => $order->get_id(),
                ] );
            }
        }

        CR_DB::bust_insights_cache();
    }

    // ------------------------------------------------------------------ checkout email capture (JS-driven)

    public static function enqueue_checkout_script(): void {
        if ( ! is_checkout() ) return;

        wp_enqueue_script(
            'cr-checkout',
            CR_PLUGIN_URL . 'assets/checkout.js',
            [ 'jquery' ],
            CR_VERSION,
            true
        );
        wp_localize_script( 'cr-checkout', 'crCheckout', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cr_checkout' ),
        ] );
    }

    public static function ajax_capture_checkout_email(): void {
        check_ajax_referer( 'cr_checkout', 'nonce' );

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! is_email( $email ) ) wp_send_json_error();

        $session_key = self::get_session_key();
        if ( ! $session_key ) wp_send_json_error();

        $cart = CR_DB::get_cart_by_session( $session_key );
        if ( ! $cart ) wp_send_json_error();

        $first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last  = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );
        $name  = trim( "$first $last" ) ?: null;

        $update = [ 'customer_email' => $email ];
        // Only overwrite name if we don't already have one
        if ( $name && empty( $cart->customer_name ) ) {
            $update['customer_name'] = $name;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cr_carts',
            $update,
            [ 'session_key' => $session_key ]
        );

        CR_DB::bust_insights_cache();
        wp_send_json_success();
    }

    // ------------------------------------------------------------------ helpers

    public static function get_session_key(): string {
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            return 'user_' . $user_id;
        }

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
            return '';
        }

        $session_id = WC()->session->get_customer_id();
        return $session_id ? 'guest_' . $session_id : '';
    }

    public static function enqueue_exit_intent_script(): void {
        // Not on checkout (checkout.js already handles it) or order-received page
        if ( is_checkout() || is_order_received_page() ) return;
        // Only when cart has items
        if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof WC_Cart || WC()->cart->is_empty() ) return;

        $session_key = self::get_session_key();
        $has_email   = false;
        if ( $session_key ) {
            $cart      = CR_DB::get_cart_by_session( $session_key );
            $has_email = $cart && ! empty( $cart->customer_email );
        }

        wp_enqueue_style(
            'cr-exit-intent',
            CR_PLUGIN_URL . 'assets/exit-intent.css',
            [],
            CR_VERSION
        );
        wp_enqueue_script(
            'cr-exit-intent',
            CR_PLUGIN_URL . 'assets/exit-intent.js',
            [ 'jquery' ],
            CR_VERSION,
            true
        );
        $settings = get_option( 'cr_settings', [] );
        wp_localize_script( 'cr-exit-intent', 'crExitIntent', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'cr_exit_intent' ),
            'hasCart'     => true,
            'hasEmail'    => $has_email,
            'mobileDelay' => (int) ( $settings['exit_intent_mobile_delay'] ?? 120 ),
            'gdprText'    => $settings['exit_intent_gdpr_text'] ?? 'I agree to receive one cart reminder email. I can unsubscribe at any time.',
        ] );
    }

    public static function ajax_capture_exit_email(): void {
        check_ajax_referer( 'cr_exit_intent', 'nonce' );

        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! is_email( $email ) ) wp_send_json_error( 'invalid_email' );

        $session_key = self::get_session_key();
        if ( ! $session_key ) wp_send_json_error( 'no_session' );

        $cart = CR_DB::get_cart_by_session( $session_key );

        if ( $cart ) {
            // Attach email to existing cart record
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'cr_carts',
                [ 'customer_email' => $email ],
                [ 'session_key' => $session_key ]
            );
        } else {
            // No snapshot yet — save one now so the email has a cart to attach to
            if ( function_exists( 'WC' ) && WC()->cart instanceof WC_Cart && ! WC()->cart->is_empty() ) {
                self::save_cart_snapshot();
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'cr_carts',
                    [ 'customer_email' => $email ],
                    [ 'session_key' => $session_key ]
                );
            } else {
                wp_send_json_error( 'no_cart' );
            }
        }

        CR_DB::bust_insights_cache();
        wp_send_json_success();
    }

    public static function get_client_ip(): ?string {
        if ( class_exists( 'WC_Geolocation' ) ) {
            $ip = WC_Geolocation::get_ip_address();
        } else {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        }
        $ip = filter_var( $ip, FILTER_VALIDATE_IP );
        return $ip ?: null;
    }

    private static function get_customer_data(): array {
        $data = [ 'name' => null, 'email' => null, 'city' => null, 'county' => null, 'country' => 'GB' ];

        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            $user          = get_userdata( $user_id );
            $data['email'] = $user ? $user->user_email : null;
            $first = get_user_meta( $user_id, 'billing_first_name', true );
            $last  = get_user_meta( $user_id, 'billing_last_name', true );
            $name  = trim( "$first $last" );
            $data['name']    = $name ?: ( $user ? $user->display_name : null );
            $data['city']    = get_user_meta( $user_id, 'billing_city', true ) ?: null;
            $data['county']  = get_user_meta( $user_id, 'billing_state', true ) ?: null;
            $data['country'] = get_user_meta( $user_id, 'billing_country', true ) ?: 'GB';
        }

        return $data;
    }

    private static function extract_cart_items(): array {
        if ( ! WC()->cart instanceof WC_Cart ) return [];

        $items = [];
        foreach ( WC()->cart->get_cart() as $item ) {
            $product    = $item['data'] ?? null;
            $product_id = absint( $item['product_id'] ?? 0 );
            $var_id     = absint( $item['variation_id'] ?? 0 );
            $quantity   = absint( $item['quantity'] ?? 1 );

            $image_url   = null;
            $product_url = null;
            if ( $product instanceof WC_Product ) {
                $image_id    = $product->get_image_id();
                $image_url   = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : null;
                $product_url = get_permalink( $product_id ) ?: null;
            }

            $variation_attrs = [];
            if ( ! empty( $item['variation'] ) && is_array( $item['variation'] ) ) {
                foreach ( $item['variation'] as $k => $v ) {
                    $label = wc_attribute_label( str_replace( 'attribute_', '', $k ) );
                    $variation_attrs[ sanitize_text_field( $label ) ] = sanitize_text_field( $v );
                }
            }

            $line_subtotal = (float) ( $item['line_subtotal'] ?? 0 );
            $unit_price    = $product instanceof WC_Product
                ? (float) wc_get_price_excluding_tax( $product )
                : ( $quantity > 0 ? $line_subtotal / $quantity : 0 );

            $items[] = [
                'product_id'      => $product_id ?: null,
                'variation_id'    => $var_id ?: null,
                'product_name'    => $product instanceof WC_Product ? $product->get_name() : sanitize_text_field( $item['name'] ?? 'Product' ),
                'product_sku'     => $product instanceof WC_Product ? $product->get_sku() : null,
                'variation_attrs' => $variation_attrs ? wp_json_encode( $variation_attrs ) : null,
                'quantity'        => $quantity,
                'unit_price'      => $unit_price,
                'line_total'      => $line_subtotal,
                'image_url'       => $image_url ?: null,
                'product_url'     => $product_url ?: null,
            ];
        }
        return $items;
    }
}
