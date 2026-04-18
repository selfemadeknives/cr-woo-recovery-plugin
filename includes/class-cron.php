<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CR_Cron {

    const HOOK = 'cr_check_abandoned';

    public static function init(): void {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );

        // Register every-5-minutes schedule if not existing
        add_filter( 'cron_schedules', [ __CLASS__, 'add_schedules' ] );

        // Schedule if not already scheduled
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'cr_every_15_min', self::HOOK );
        }
    }

    public static function add_schedules( array $schedules ): array {
        $schedules['cr_every_5_min'] = [
            'interval' => 300,
            'display'  => 'Every 5 minutes',
        ];
        $schedules['cr_every_15_min'] = [
            'interval' => 900,
            'display'  => 'Every 15 minutes',
        ];
        $schedules['cr_every_30_min'] = [
            'interval' => 1800,
            'display'  => 'Every 30 minutes',
        ];
        return $schedules;
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    public static function run(): void {
        global $wpdb;

        $settings          = get_option( 'cr_settings', [] );
        $threshold_minutes = (int) ( $settings['abandonment_threshold'] ?? 60 );

        // Mark active carts as abandoned if last_activity is older than threshold
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cr_carts
             SET status = 'abandoned', abandoned_at = last_activity
             WHERE status = 'active'
               AND last_activity < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
            $threshold_minutes
        ) );

        // Also check WooCommerce pending/failed orders and upsert them
        self::sync_woo_orders();
    }

    private static function sync_woo_orders(): void {
        if ( ! function_exists( 'wc_get_orders' ) ) return;

        $settings    = get_option( 'cr_settings', [] );
        $lookback    = (int) ( $settings['lookback_days'] ?? 30 );
        $after       = gmdate( 'Y-m-d', strtotime( "-{$lookback} days" ) );

        foreach ( [ 'pending', 'failed' ] as $wc_status ) {
            $orders = wc_get_orders( [
                'status'       => $wc_status,
                'limit'        => 200,
                'date_created' => '>' . strtotime( $after ),
                'return'       => 'objects',
            ] );

            foreach ( $orders as $order ) {
                self::upsert_from_order( $order, $wc_status );
            }
        }
    }

    private static function upsert_from_order( WC_Order $order, string $wc_status ): void {
        $session_key = 'order_' . $order->get_id();
        $source      = $wc_status === 'failed' ? 'failed_order' : 'pending_order';

        $items = [];
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product   = $item->get_product();
            $product_id = (int) $item->get_product_id();
            $image_url = null;
            $product_url = null;
            if ( $product ) {
                $img_id      = $product->get_image_id();
                $image_url   = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : null;
                $product_url = get_permalink( $product_id ) ?: null;
            }
            $items[] = [
                'product_id'   => $product_id ?: null,
                'variation_id' => (int) $item->get_variation_id() ?: null,
                'product_name' => $item->get_name(),
                'product_sku'  => $product ? $product->get_sku() : null,
                'quantity'     => (int) $item->get_quantity(),
                'unit_price'   => (float) $item->get_subtotal() / max( 1, (int) $item->get_quantity() ),
                'line_total'   => (float) $item->get_subtotal(),
                'image_url'    => $image_url,
                'product_url'  => $product_url,
            ];
        }

        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $raw_ip = method_exists( $order, 'get_customer_ip_address' ) ? $order->get_customer_ip_address() : '';
        $data = [
            'source'           => $source,
            'woo_order_id'     => $order->get_id(),
            'customer_name'    => $name ?: null,
            'customer_email'   => $order->get_billing_email() ?: null,
            'customer_ip'      => filter_var( $raw_ip, FILTER_VALIDATE_IP ) ?: null,
            'customer_city'    => $order->get_billing_city() ?: null,
            'customer_county'  => $order->get_billing_state() ?: null,
            'customer_country' => $order->get_billing_country() ?: 'GB',
            'cart_total'       => (float) $order->get_total(),
            'currency'         => $order->get_currency(),
            'item_count'       => $order->get_item_count(),
            'last_activity'    => $order->get_date_modified()
                ? $order->get_date_modified()->date( 'Y-m-d H:i:s' )
                : current_time( 'mysql' ),
            'abandoned_at'     => $order->get_date_modified()
                ? $order->get_date_modified()->date( 'Y-m-d H:i:s' )
                : current_time( 'mysql' ),
        ];

        $cart_id = CR_DB::upsert_cart( $session_key, $data );
        if ( ! empty( $items ) ) {
            CR_DB::replace_cart_items( $cart_id, $items );
        }
    }
}
