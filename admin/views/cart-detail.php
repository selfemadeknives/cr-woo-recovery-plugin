<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$cart_id = (int) ( $_GET['id'] ?? 0 );
$cart    = $cart_id ? CR_DB::get_cart( $cart_id ) : null;

if ( ! $cart ) {
    echo '<div class="wrap"><div class="notice notice-error"><p>Cart not found.</p></div></div>';
    return;
}

$items     = CR_DB::get_cart_items( $cart_id );
$email_log = CR_DB::get_email_log( $cart_id );
$templates = CR_DB::get_templates();
$default_tpl = null;
foreach ( $templates as $tpl ) {
    if ( $tpl->is_default ) { $default_tpl = $tpl; break; }
}
if ( ! $default_tpl && ! empty( $templates ) ) $default_tpl = $templates[0];
?>
<div class="wrap cr-wrap">
    <div class="cr-header">
        <h1 class="cr-page-title">
            <a href="<?php echo esc_url( CR_Admin::admin_url( 'cr-carts' ) ); ?>" class="cr-back">← Carts</a>
            <?php echo esc_html( $cart->customer_name ?: 'Unknown customer' ); ?>
        </h1>
        <div class="cr-header-value">
            <?php echo wp_kses_post( wc_price( (float) $cart->cart_total, [ 'currency' => $cart->currency ] ) ); ?>
            <small>(<?php echo (int) $cart->item_count; ?> item<?php echo (int) $cart->item_count !== 1 ? 's' : ''; ?>)</small>
        </div>
    </div>

    <div class="cr-detail-grid">
        <!-- LEFT: items + email log + notes -->
        <div class="cr-detail-main">

            <!-- Cart items -->
            <div class="cr-card">
                <div class="cr-card-header"><h2>Cart Items</h2></div>
                <table class="cr-table widefat">
                    <thead><tr><th>Product</th><th class="cr-center">Qty</th><th class="cr-right">Price each</th><th class="cr-right">Total</th></tr></thead>
                    <tbody>
                    <?php foreach ( $items as $item ) : ?>
                        <tr>
                            <td>
                                <?php if ( $item->image_url ) : ?>
                                    <img src="<?php echo esc_url( $item->image_url ); ?>" class="cr-product-thumb" alt="">
                                <?php endif; ?>
                                <?php if ( $item->product_url ) : ?>
                                    <a href="<?php echo esc_url( $item->product_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $item->product_name ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $item->product_name ); ?>
                                <?php endif; ?>
                                <?php if ( ! empty( $item->variation_attrs ) ) :
                                    $attrs = json_decode( $item->variation_attrs, true );
                                    if ( is_array( $attrs ) ) : ?>
                                        <br><small class="cr-muted"><?php echo esc_html( implode( ' · ', array_map( fn( $k, $v ) => "$k: $v", array_keys( $attrs ), $attrs ) ) ); ?></small>
                                    <?php endif;
                                endif; ?>
                                <?php if ( $item->product_sku ) : ?><br><small class="cr-muted">SKU: <?php echo esc_html( $item->product_sku ); ?></small><?php endif; ?>
                            </td>
                            <td class="cr-center"><?php echo (int) $item->quantity; ?></td>
                            <td class="cr-right"><?php echo wp_kses_post( wc_price( (float) $item->unit_price, [ 'currency' => $cart->currency ] ) ); ?></td>
                            <td class="cr-right"><strong><?php echo wp_kses_post( wc_price( (float) $item->line_total, [ 'currency' => $cart->currency ] ) ); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="cr-right"><strong>Cart Total</strong></td>
                            <td class="cr-right"><strong><?php echo wp_kses_post( wc_price( (float) $cart->cart_total, [ 'currency' => $cart->currency ] ) ); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Email history -->
            <?php if ( ! empty( $email_log ) ) : ?>
            <div class="cr-card">
                <div class="cr-card-header"><h2>Email History</h2></div>
                <table class="cr-table widefat">
                    <thead><tr><th>Subject</th><th>Sent to</th><th>Date</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ( $email_log as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( $log->subject ); ?></td>
                            <td><?php echo esc_html( $log->recipient_email ); ?></td>
                            <td><?php echo esc_html( cr_format_datetime( $log->sent_at ) ); ?></td>
                            <td><?php echo cr_status_badge( $log->status ); // phpcs:ignore ?>
                                <?php if ( $log->error_message ) : ?><br><small class="cr-error"><?php echo esc_html( $log->error_message ); ?></small><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Notes -->
            <div class="cr-card">
                <div class="cr-card-header"><h2>Private Notes</h2></div>
                <div class="cr-card-body">
                    <textarea id="cr-notes" rows="4" class="large-text"><?php echo esc_textarea( $cart->notes ?? '' ); ?></textarea>
                    <p><button class="button" id="cr-save-notes" data-cart-id="<?php echo (int) $cart->id; ?>">Save Notes</button>
                    <span id="cr-notes-msg" class="cr-inline-msg"></span></p>
                </div>
            </div>
        </div>

        <!-- RIGHT: customer + actions + timeline -->
        <div class="cr-detail-sidebar">

            <!-- Customer -->
            <div class="cr-card">
                <div class="cr-card-header"><h2>Customer</h2></div>
                <div class="cr-card-body cr-dl">
                    <?php if ( $cart->customer_name ) : ?>
                        <dt>Name</dt><dd><?php echo esc_html( $cart->customer_name ); ?></dd>
                    <?php endif; ?>
                    <?php if ( $cart->customer_email ) : ?>
                        <dt>Email</dt><dd><a href="mailto:<?php echo esc_attr( $cart->customer_email ); ?>"><?php echo esc_html( $cart->customer_email ); ?></a></dd>
                    <?php endif; ?>
                    <?php if ( $cart->customer_city || $cart->customer_county ) : ?>
                        <dt>Location</dt><dd><?php echo esc_html( implode( ', ', array_filter( [ $cart->customer_city, $cart->customer_county ] ) ) ); ?></dd>
                    <?php endif; ?>
                    <dt>Country</dt><dd><?php echo esc_html( $cart->customer_country ); ?></dd>
                    <dt>Source</dt><dd><?php echo cr_source_badge( $cart->source ); // phpcs:ignore ?></dd>
                    <?php if ( $cart->woo_order_id ) : ?>
                        <dt>Order</dt><dd><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $cart->woo_order_id . '&action=edit' ) ); ?>" target="_blank">#<?php echo (int) $cart->woo_order_id; ?></a></dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $cart->customer_ip ) ) :
                        $ip_carts = CR_DB::get_carts( [ 'ip' => $cart->customer_ip ] );
                        $ip_count = count( $ip_carts );
                        $ip_url   = CR_Admin::admin_url( 'cr-carts', [ 'status' => 'all', 'ip' => $cart->customer_ip ] );
                    ?>
                        <dt>IP Address</dt>
                        <dd>
                            <code><?php echo esc_html( $cart->customer_ip ); ?></code>
                            <?php if ( $ip_count > 1 ) : ?>
                                &nbsp;<a href="<?php echo esc_url( $ip_url ); ?>" class="cr-badge cr-badge-warning" style="text-decoration:none;">
                                    <?php echo (int) $ip_count; ?> carts from this IP
                                </a>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                    <dt>Status</dt><dd><?php echo cr_status_badge( $cart->status ); // phpcs:ignore ?></dd>
                </div>
            </div>

            <!-- Actions -->
            <div class="cr-card">
                <div class="cr-card-header"><h2>Actions</h2></div>
                <div class="cr-card-body cr-actions">
                    <?php if ( $cart->customer_email && $cart->status !== 'recovered' ) : ?>
                    <button class="button button-primary cr-btn-full" id="cr-open-email">
                        <span class="dashicons dashicons-email-alt"></span> Send Recovery Email
                    </button>
                    <?php endif; ?>
                    <?php if ( $cart->status !== 'recovered' ) : ?>
                    <button class="button cr-btn-full cr-status-btn" data-cart-id="<?php echo (int) $cart->id; ?>" data-status="recovered">
                        <span class="dashicons dashicons-yes"></span> Mark as Recovered
                    </button>
                    <?php endif; ?>
                    <?php if ( $cart->status !== 'ignored' && $cart->status !== 'recovered' ) : ?>
                    <button class="button cr-btn-full cr-btn-muted cr-status-btn" data-cart-id="<?php echo (int) $cart->id; ?>" data-status="ignored">
                        Ignore
                    </button>
                    <?php endif; ?>
                    <?php if ( in_array( $cart->status, [ 'ignored', 'recovered' ], true ) ) : ?>
                    <button class="button cr-btn-full cr-status-btn" data-cart-id="<?php echo (int) $cart->id; ?>" data-status="abandoned">
                        Reopen
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Timeline -->
            <div class="cr-card">
                <div class="cr-card-header"><h2>Timeline</h2></div>
                <div class="cr-card-body cr-dl">
                    <dt>Cart created</dt><dd><?php echo esc_html( cr_format_datetime( $cart->created_at ) ); ?></dd>
                    <dt>Last activity</dt><dd><?php echo esc_html( cr_format_datetime( $cart->last_activity ) ); ?></dd>
                    <dt>Abandoned</dt><dd><?php echo esc_html( cr_format_datetime( $cart->abandoned_at ) ); ?></dd>
                    <?php if ( $cart->email_sent_at ) : ?>
                        <dt>Email sent</dt><dd><?php echo esc_html( cr_format_datetime( $cart->email_sent_at ) ); ?></dd>
                    <?php endif; ?>
                    <?php if ( $cart->recovered_at ) : ?>
                        <dt class="cr-success">Recovered</dt><dd class="cr-success"><strong><?php echo esc_html( cr_format_datetime( $cart->recovered_at ) ); ?></strong></dd>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Email modal -->
<div id="cr-email-modal" class="cr-modal" style="display:none;">
    <div class="cr-modal-backdrop"></div>
    <div class="cr-modal-box">
        <div class="cr-modal-header">
            <h2>Send Recovery Email</h2>
            <button class="cr-modal-close" id="cr-close-email"><span class="dashicons dashicons-no-alt"></span></button>
        </div>
        <div class="cr-modal-body">
            <p class="cr-to-line">To: <strong><?php echo esc_html( $cart->customer_name ?: '' ); ?></strong>
            <span class="cr-muted">&lt;<?php echo esc_html( $cart->customer_email ?: '' ); ?>&gt;</span></p>

            <label class="cr-label">Template</label>
            <select id="cr-template-select" class="widefat">
                <?php foreach ( $templates as $tpl ) : ?>
                <option value="<?php echo (int) $tpl->id; ?>"
                    <?php selected( $tpl->is_default, 1 ); ?>>
                    <?php echo esc_html( $tpl->name ); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <label class="cr-label">Subject</label>
            <input type="text" id="cr-email-subject" class="widefat"
                value="<?php echo esc_attr( $default_tpl?->subject ?? '' ); ?>">

            <label class="cr-label">Body (HTML — supports <code>{{variables}}</code>)</label>
            <textarea id="cr-email-body" rows="12" class="widefat cr-mono"><?php echo esc_textarea( $default_tpl?->body_html ?? '' ); ?></textarea>
            <p class="cr-hint">Variables: <code>{{customer_name}}</code> <code>{{cart_total}}</code>
            <code>{{cart_items_html}}</code> <code>{{shop_name}}</code> <code>{{cart_url}}</code></p>

            <div id="cr-send-msg" class="notice" style="display:none;margin:8px 0;"></div>

            <div class="cr-modal-footer">
                <button class="button" id="cr-cancel-email">Cancel</button>
                <button class="button button-primary" id="cr-send-email-btn"
                    data-cart-id="<?php echo (int) $cart->id; ?>">
                    <span class="dashicons dashicons-email-alt"></span> Send Email
                </button>
            </div>
        </div>
    </div>
</div>
