<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$insights = CR_DB::get_insights();
$t        = $insights['totals'];
$total    = (int)   ( $t->total    ?? 0 );
$recovered  = (int) ( $t->recovered ?? 0 );
$emailed    = (int) ( $t->emailed   ?? 0 );
$total_value    = (float) ( $t->total_value    ?? 0 );
$recovered_value = (float) ( $t->recovered_value ?? 0 );
$avg_value  = (float) ( $t->avg_value ?? 0 );
$recovery_rate = $total > 0 ? round( $recovered / $total * 100 ) : 0;

$recent_carts = CR_DB::get_carts( [
    'since' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
] );
$recent_carts = array_slice( $recent_carts, 0, 10 );

// Build a map of IPs that appear more than once so we can flag repeat visitors.
$ip_counts = [];
foreach ( $recent_carts as $c ) {
    if ( ! empty( $c->customer_ip ) ) {
        $ip_counts[ $c->customer_ip ] = ( $ip_counts[ $c->customer_ip ] ?? 0 ) + 1;
    }
}
?>
<div class="wrap cr-wrap">
    <div class="cr-header">
        <h1 class="cr-page-title">
            <span class="dashicons dashicons-cart"></span> Cart Recovery — Dashboard
        </h1>
        <button id="cr-sync-btn" class="button button-primary cr-sync-btn">
            <span class="dashicons dashicons-update-alt"></span> Sync Now
        </button>
    </div>
    <div id="cr-sync-msg" class="notice" style="display:none;margin-top:10px;"></div>

    <!-- Stats -->
    <div class="cr-stats-grid">
        <div class="cr-stat-card cr-stat-amber">
            <div class="cr-stat-label">Abandoned Carts</div>
            <div class="cr-stat-value"><?php echo esc_html( $total ); ?></div>
            <div class="cr-stat-sub">all time</div>
        </div>
        <div class="cr-stat-card cr-stat-stone">
            <div class="cr-stat-label">Total Value at Stake</div>
            <div class="cr-stat-value"><?php echo wp_kses_post( wc_price( $total_value ) ); ?></div>
            <div class="cr-stat-sub">abandoned cart value</div>
        </div>
        <div class="cr-stat-card cr-stat-green">
            <div class="cr-stat-label">Recovery Rate</div>
            <div class="cr-stat-value"><?php echo esc_html( $recovery_rate ); ?>%</div>
            <div class="cr-stat-sub"><?php echo esc_html( $recovered ); ?> carts recovered</div>
        </div>
        <div class="cr-stat-card cr-stat-blue">
            <div class="cr-stat-label">Avg Cart Value</div>
            <div class="cr-stat-value"><?php echo wp_kses_post( wc_price( $avg_value ) ); ?></div>
            <div class="cr-stat-sub"><?php echo esc_html( $emailed ); ?> emails sent</div>
        </div>
    </div>

    <?php if ( $total === 0 ) : ?>
    <div class="notice notice-info cr-setup-notice">
        <p><strong>Welcome to Cart Recovery!</strong> No carts have been tracked yet.</p>
        <p>The plugin will automatically start capturing carts as customers browse your store. Pending and failed WooCommerce orders are also imported — click <strong>Sync Now</strong> above to pull them in immediately.</p>
        <p>Check <a href="<?php echo esc_url( CR_Admin::admin_url( 'cr-settings' ) ); ?>">Settings</a> to configure your shop name and email sender.</p>
    </div>
    <?php endif; ?>

    <!-- Recent carts -->
    <div class="cr-card">
        <div class="cr-card-header">
            <h2>Recent Abandoned Carts</h2>
            <a href="<?php echo esc_url( CR_Admin::admin_url( 'cr-carts' ) ); ?>">View all →</a>
        </div>
        <?php if ( empty( $recent_carts ) ) : ?>
            <p class="cr-empty">No carts yet. Click <strong>Sync Now</strong> to check for pending orders.</p>
        <?php else : ?>
        <table class="cr-table widefat">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Value</th>
                    <th>Products</th>
                    <th>Abandoned</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $recent_carts as $cart ) : ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $cart->customer_name ?: 'Unknown' ); ?></strong>
                        <?php if ( ! empty( $cart->customer_ip ) && ( $ip_counts[ $cart->customer_ip ] ?? 0 ) > 1 ) : ?>
                            <span class="cr-badge cr-badge-warning" style="margin-left:6px;font-size:11px;">Repeat visitor</span>
                        <?php endif; ?>
                        <?php if ( $cart->customer_email ) : ?><br><small><?php echo esc_html( $cart->customer_email ); ?></small><?php endif; ?>
                    </td>
                    <td><strong><?php echo wp_kses_post( wc_price( (float) $cart->cart_total, [ 'currency' => $cart->currency ] ) ); ?></strong></td>
                    <td>
                        <?php if ( ! empty( $cart->item_names ) ) : ?>
                            <small><?php echo esc_html( $cart->item_names ); ?></small>
                        <?php else : ?>
                            <?php echo (int) $cart->item_count; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( cr_time_ago( $cart->abandoned_at ) ); ?></td>
                    <td><?php echo cr_status_badge( $cart->status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    <td><a href="<?php echo esc_url( CR_Admin::admin_url( 'cr-cart-detail', [ 'id' => $cart->id ] ) ); ?>" class="button button-small">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
