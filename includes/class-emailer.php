<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CR_Emailer {

    /** Allowed template variable names — prevents unknown variable leakage. */
    const ALLOWED_VARS = [
        'customer_name', 'shop_name', 'cart_total',
        'cart_items_html', 'cart_items_list', 'cart_url', 'shop_url',
    ];

    /**
     * Send a recovery email for a cart and log the result.
     *
     * @return array{success: bool, error: ?string}
     */
    public static function send( int $cart_id, int $template_id, string $subject, string $body_html ): array {
        $cart = CR_DB::get_cart( $cart_id );
        if ( ! $cart ) {
            return [ 'success' => false, 'error' => 'Cart not found' ];
        }

        $email = sanitize_email( $cart->customer_email ?? '' );
        if ( ! is_email( $email ) ) {
            return [ 'success' => false, 'error' => 'No valid customer email on record for this cart' ];
        }

        $items   = CR_DB::get_cart_items( $cart_id );
        $vars    = self::build_vars( $cart, $items );
        $subject = self::interpolate( sanitize_text_field( $subject ), $vars );
        // body_html has already been through cr_kses_email() before reaching here
        $body_html = self::interpolate( $body_html, $vars );

        $settings   = get_option( 'cr_settings', [] );
        $from_name  = self::sanitize_header_value( $settings['from_name'] ?? get_bloginfo( 'name' ) );
        $from_email = sanitize_email( $settings['from_email'] ?? get_option( 'admin_email' ) );

        if ( ! is_email( $from_email ) ) {
            return [ 'success' => false, 'error' => 'Invalid "From" email address in Settings' ];
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            // Sanitized above — no newlines possible in from_name or from_email
            'From: ' . $from_name . ' <' . $from_email . '>',
        ];

        $sent   = wp_mail( $email, $subject, $body_html, $headers );
        $status = $sent ? 'sent' : 'failed';

        CR_DB::log_email( [
            'cart_id'         => $cart_id,
            'template_id'     => $template_id ?: null,
            'recipient_email' => $email,
            'subject'         => $subject,
            'body_html'       => $body_html,
            'status'          => $status,
            'error_message'   => $sent ? null : 'wp_mail() returned false — check your SMTP / email configuration',
            'sent_at'         => current_time( 'mysql' ),
        ] );

        if ( $sent ) {
            CR_DB::update_cart_status( $cart_id, 'email_sent', [
                'email_sent'    => 1,
                'email_sent_at' => current_time( 'mysql' ),
            ] );
            CR_DB::bust_insights_cache();
        }

        return [ 'success' => $sent, 'error' => $sent ? null : 'wp_mail() failed — check SMTP settings' ];
    }

    // ------------------------------------------------------------------ template variables

    private static function build_vars( object $cart, array $items ): array {
        $settings  = get_option( 'cr_settings', [] );
        $shop_name = $settings['shop_name'] ?? get_bloginfo( 'name' );
        $shop_url  = get_home_url();
        $cart_url  = wc_get_cart_url();

        $first_name = explode( ' ', (string) ( $cart->customer_name ?? '' ) )[0] ?: 'there';
        $currency   = $cart->currency ?: get_woocommerce_currency();

        return [
            'customer_name'   => esc_html( $first_name ),
            'shop_name'       => esc_html( $shop_name ),
            'cart_total'      => html_entity_decode( wc_price( (float) $cart->cart_total, [ 'currency' => $currency ] ) ),
            'cart_items_html' => self::build_items_html( $items, $currency ),
            'cart_items_list' => self::build_items_text( $items, $currency ),
            'cart_url'        => esc_url( $cart_url ),
            'shop_url'        => esc_url( $shop_url ),
        ];
    }

    private static function build_items_html( array $items, string $currency ): string {
        if ( empty( $items ) ) return '';

        $rows = '';
        foreach ( $items as $item ) {
            $image = $item->image_url
                ? '<img src="' . esc_url( $item->image_url ) . '" width="50" height="50" style="vertical-align:middle;margin-right:8px;object-fit:cover;border-radius:3px;" alt="">'
                : '';

            $variation = '';
            if ( ! empty( $item->variation_attrs ) ) {
                $attrs = json_decode( $item->variation_attrs, true );
                if ( is_array( $attrs ) ) {
                    $parts = [];
                    foreach ( $attrs as $k => $v ) {
                        $parts[] = esc_html( $k ) . ': ' . esc_html( $v );
                    }
                    $variation = '<br><small style="color:#666;">' . implode( ', ', $parts ) . '</small>';
                }
            }
            $price = html_entity_decode( wc_price( (float) $item->line_total, [ 'currency' => $currency ] ) );
            $rows .= '<tr>
                <td style="padding:8px;border-bottom:1px solid #eee;">'
                    . $image . esc_html( $item->product_name ) . $variation
                . '</td>
                <td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">' . absint( $item->quantity ) . '</td>
                <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . $price . '</td>
            </tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;font-family:sans-serif;font-size:14px;">
            <thead><tr style="background:#f5f5f5;">
                <th style="padding:8px;text-align:left;">Item</th>
                <th style="padding:8px;text-align:center;">Qty</th>
                <th style="padding:8px;text-align:right;">Total</th>
            </tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>';
    }

    private static function build_items_text( array $items, string $currency ): string {
        $lines = [];
        foreach ( $items as $item ) {
            $price   = html_entity_decode( strip_tags( wc_price( (float) $item->line_total, [ 'currency' => $currency ] ) ) );
            $lines[] = '• ' . $item->product_name . ' x' . absint( $item->quantity ) . ' — ' . $price;
        }
        return implode( "\n", $lines );
    }

    /**
     * Interpolate {{variable}} placeholders. Only known variables are replaced.
     */
    public static function interpolate( string $template, array $vars ): string {
        foreach ( self::ALLOWED_VARS as $key ) {
            if ( isset( $vars[ $key ] ) ) {
                $template = str_replace( '{{' . $key . '}}', $vars[ $key ], $template );
            }
        }
        return $template;
    }

    /**
     * Strip newlines and carriage returns from email header values to prevent injection.
     */
    private static function sanitize_header_value( string $value ): string {
        return sanitize_text_field( str_replace( [ "\r", "\n", "\r\n" ], '', $value ) );
    }
}
