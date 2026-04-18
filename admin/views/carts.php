<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
// Default to abandoned — normal sales (active→recovered) should not clutter this view.
// 'status=all' shows everything; omitting the param defaults to abandoned.
$show_all      = isset( $_GET['status'] ) && sanitize_key( $_GET['status'] ) === 'all';
$status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'abandoned';
$search        = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
$min_value     = (float) ( $_GET['min_value'] ?? 0 );

$args = [];
if ( ! $show_all )    $args['status']    = $status_filter;
if ( $search )        $args['search']    = $search;
if ( $min_value > 0 ) $args['min_value'] = $min_value;

$carts    = CR_DB::get_carts( $args );
$statuses = [ 'abandoned' => 'Abandoned', 'email_sent' => 'Email Sent', 'recovered' => 'Recovered', 'ignored' => 'Ignored', 'all' => 'All' ];

$ip_counts = [];
foreach ( $carts as $c ) {
    if ( ! empty( $c->customer_ip ) ) {
        $ip_counts[ $c->customer_ip ] = ( $ip_counts[ $c->customer_ip ] ?? 0 ) + 1;
    }
}
?>
<div class="wrap cr-wrap">
    <div class="cr-header">
        <h1 class="cr-page-title"><span class="dashicons dashicons-cart"></span> Abandoned Carts</h1>
        <span class="cr-count"><?php echo count( $carts ); ?> cart<?php echo count( $carts ) !== 1 ? 's' : ''; ?></span>
    </div>

    <!-- Filters -->
    <form method="get" class="cr-filter-bar">
        <input type="hidden" name="page" value="cr-carts">
        <div class="cr-status-tabs">
            <?php foreach ( $statuses as $val => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-carts', 'status' => $val ], admin_url( 'admin.php' ) ) ); ?>"
               class="cr-status-tab <?php echo ( $show_all && $val === 'all' ) || ( ! $show_all && $status_filter === $val ) ? 'active' : ''; ?>">
                <?php echo esc_html( $label ); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="cr-filter-inputs">
            <input type="text" name="search" placeholder="Search name or email…" value="<?php echo esc_attr( $search ); ?>" class="regular-text">
            <input type="number" name="min_value" placeholder="Min £ value" value="<?php echo $min_value > 0 ? esc_attr( $min_value ) : ''; ?>" class="small-text" step="0.01">
            <button type="submit" class="button">Filter</button>
            <?php if ( $search || $min_value > 0 ) : ?>
            <a href="<?php echo esc_url( CR_Admin::admin_url( 'cr-carts', [ 'status' => $status_filter ] ) ); ?>" class="button">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ( empty( $carts ) ) : ?>
        <div class="cr-card"><p class="cr-empty">No carts match your filters.</p></div>
    <?php else : ?>
    <div class="cr-card cr-card-flush">
        <table class="cr-table widefat striped">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Location</th>
                    <th>Source</th>
                    <th class="cr-right">Value</th>
                    <th>Products</th>
                    <th>Abandoned</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $carts as $cart ) : ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $cart->customer_name ?: 'Unknown' ); ?></strong>
                        <?php if ( ! empty( $cart->customer_ip ) && ( $ip_counts[ $cart->customer_ip ] ?? 0 ) > 1 ) : ?>
                            <span class="cr-badge cr-badge-warning" style="margin-left:6px;font-size:11px;">Repeat visitor</span>
                        <?php endif; ?>
                        <?php if ( $cart->customer_email ) : ?>
                            <br><small class="cr-muted"><?php echo esc_html( $cart->customer_email ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="cr-muted"><?php echo esc_html( implode( ', ', array_filter( [ $cart->customer_city, $cart->customer_county ] ) ) ); ?></td>
                    <td><?php echo cr_source_badge( $cart->source ); // phpcs:ignore ?></td>
                    <td class="cr-right"><strong><?php echo wp_kses_post( wc_price( (float) $cart->cart_total, [ 'currency' => $cart->currency ] ) ); ?></strong></td>
                    <td>
                        <?php if ( ! empty( $cart->item_names ) ) : ?>
                            <small><?php echo esc_html( $cart->item_names ); ?></small>
                        <?php else : ?>
                            <small class="cr-muted"><?php echo (int) $cart->item_count; ?> item<?php echo (int) $cart->item_count !== 1 ? 's' : ''; ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="cr-muted"><?php echo esc_html( cr_time_ago( $cart->abandoned_at ) ); ?></td>
                    <td><?php echo cr_status_badge( $cart->status ); // phpcs:ignore ?></td>
                    <td><a href="<?php echo esc_url( CR_Admin::admin_url( 'cr-cart-detail', [ 'id' => $cart->id ] ) ); ?>" class="button button-small">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
