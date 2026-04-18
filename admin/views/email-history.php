<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php $emails = CR_DB::get_email_log( null, 300 ); ?>
<div class="wrap cr-wrap">
    <div class="cr-header">
        <h1 class="cr-page-title"><span class="dashicons dashicons-email-alt"></span> Email History</h1>
        <span class="cr-count"><?php echo count( $emails ); ?> email<?php echo count( $emails ) !== 1 ? 's' : ''; ?></span>
    </div>

    <?php if ( empty( $emails ) ) : ?>
        <div class="cr-card"><p class="cr-empty">No emails sent yet. Open a cart and click <strong>Send Recovery Email</strong>.</p></div>
    <?php else : ?>
    <div class="cr-card cr-card-flush">
        <table class="cr-table widefat striped">
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Sent</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $emails as $email ) : ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $email->customer_name ?? $email->recipient_email ); ?></strong>
                        <br><small class="cr-muted"><?php echo esc_html( $email->recipient_email ); ?></small>
                    </td>
                    <td><?php echo esc_html( $email->subject ); ?></td>
                    <td class="cr-muted"><?php echo esc_html( cr_format_datetime( $email->sent_at ) ); ?></td>
                    <td><?php echo cr_status_badge( $email->status ); // phpcs:ignore ?>
                        <?php if ( ! empty( $email->error_message ) ) : ?><br><small class="cr-error"><?php echo esc_html( $email->error_message ); ?></small><?php endif; ?>
                    </td>
                    <td><a href="<?php echo esc_url( CR_Admin::admin_url( 'cr-cart-detail', [ 'id' => $email->cart_id ] ) ); ?>" class="button button-small">View Cart</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
