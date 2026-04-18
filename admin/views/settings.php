<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$settings  = get_option( 'cr_settings', [] );
$tab       = sanitize_key( $_GET['tab'] ?? 'general' );
$saved     = ! empty( $_GET['saved'] );
$templates = CR_DB::get_templates();
$edit_tpl  = null;
if ( ! empty( $_GET['edit_template'] ) ) {
    $edit_tpl = CR_DB::get_template( (int) $_GET['edit_template'] );
}
?>
<div class="wrap cr-wrap">
    <h1 class="cr-page-title"><span class="dashicons dashicons-admin-settings"></span> Settings</h1>

    <?php if ( $saved ) : ?>
    <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper cr-tab-nav">
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-settings', 'tab' => 'general' ], admin_url( 'admin.php' ) ) ); ?>"
           class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-settings', 'tab' => 'sync' ], admin_url( 'admin.php' ) ) ); ?>"
           class="nav-tab <?php echo $tab === 'sync' ? 'nav-tab-active' : ''; ?>">Sync</a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-settings', 'tab' => 'capture' ], admin_url( 'admin.php' ) ) ); ?>"
           class="nav-tab <?php echo $tab === 'capture' ? 'nav-tab-active' : ''; ?>">Cart Capture</a>
        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-settings', 'tab' => 'templates' ], admin_url( 'admin.php' ) ) ); ?>"
           class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>">Email Templates</a>
    </nav>

    <?php if ( $tab === 'general' ) : ?>
    <div class="cr-card cr-card-form">
        <div class="cr-card-header"><h2>General & Email Settings</h2></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cr_save_settings' ); ?>
            <input type="hidden" name="action" value="cr_save_settings">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="shop_name">Shop Name</label></th>
                    <td><input type="text" id="shop_name" name="shop_name" class="regular-text"
                               value="<?php echo esc_attr( $settings['shop_name'] ?? get_bloginfo( 'name' ) ); ?>">
                        <p class="description">Used in email templates as <code>{{shop_name}}</code>.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="from_name">Email From Name</label></th>
                    <td><input type="text" id="from_name" name="from_name" class="regular-text"
                               value="<?php echo esc_attr( $settings['from_name'] ?? get_bloginfo( 'name' ) ); ?>">
                        <p class="description">The sender name customers will see.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="from_email">Email From Address</label></th>
                    <td><input type="email" id="from_email" name="from_email" class="regular-text"
                               value="<?php echo esc_attr( $settings['from_email'] ?? get_option( 'admin_email' ) ); ?>">
                        <p class="description">Sent via WordPress's built-in <code>wp_mail()</code>. Use an SMTP plugin (e.g. WP Mail SMTP) for reliable delivery.</p></td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>
        <div class="cr-notice-info">
            <strong>Email delivery tip:</strong> Install <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener">WP Mail SMTP</a>
            (free) and connect it to Gmail, Mailgun, or your hosting SMTP to ensure emails land in inboxes rather than spam.
        </div>
    </div>

    <?php elseif ( $tab === 'sync' ) : ?>
    <div class="cr-card cr-card-form">
        <div class="cr-card-header"><h2>Sync & Abandonment Settings</h2></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cr_save_settings' ); ?>
            <input type="hidden" name="action" value="cr_save_settings">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="abandonment_threshold">Abandonment Threshold</label></th>
                    <td>
                        <select id="abandonment_threshold" name="abandonment_threshold">
                            <?php foreach ( [
                                30  => '30 minutes',
                                60  => '1 hour',
                                90  => '90 minutes',
                                120 => '2 hours',
                                180 => '3 hours',
                                240 => '4 hours',
                                360 => '6 hours (recommended for high-value items)',
                                480 => '8 hours',
                                720 => '12 hours',
                                1440 => '24 hours',
                            ] as $mins => $label ) : ?>
                            <option value="<?php echo $mins; ?>" <?php selected( (int) ( $settings['abandonment_threshold'] ?? 360 ), $mins ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Carts with no activity for this long are marked abandoned. For high-value handmade items, 6 hours or more is recommended — customers often browse, step away, and return to buy.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cron_interval">Sync Frequency</label></th>
                    <td>
                        <select id="cron_interval" name="cron_interval">
                            <option value="cr_every_5_min"  <?php selected( $settings['cron_interval'] ?? '', 'cr_every_5_min' ); ?>>Every 5 minutes</option>
                            <option value="cr_every_15_min" <?php selected( $settings['cron_interval'] ?? 'cr_every_15_min', 'cr_every_15_min' ); ?>>Every 15 minutes</option>
                            <option value="cr_every_30_min" <?php selected( $settings['cron_interval'] ?? '', 'cr_every_30_min' ); ?>>Every 30 minutes</option>
                            <option value="hourly"          <?php selected( $settings['cron_interval'] ?? '', 'hourly' ); ?>>Every hour</option>
                        </select>
                        <p class="description">How often WP-Cron checks for abandoned carts and imports pending/failed orders.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="lookback_days">Look-back Days</label></th>
                    <td><input type="number" id="lookback_days" name="lookback_days" class="small-text" min="1" max="90"
                               value="<?php echo esc_attr( $settings['lookback_days'] ?? 30 ); ?>">
                        <p class="description">How many days back to scan for pending/failed orders on each sync.</p></td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>
        <div class="cr-notice-info">
            <strong>WP-Cron note:</strong> On low-traffic sites, WP-Cron only fires when someone visits your site.
            For reliable background processing, ask your host about setting up a real cron job:
            <code>*/15 * * * * curl https://yoursite.co.uk/wp-cron.php?doing_wp_cron >/dev/null 2>&1</code>
        </div>
    </div>

    <?php elseif ( $tab === 'capture' ) : ?>
    <div class="cr-card cr-card-form">
        <div class="cr-card-header"><h2>Exit-Intent Popup Settings</h2></div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cr_save_settings' ); ?>
            <input type="hidden" name="action" value="cr_save_settings">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="exit_intent_mobile_delay">Mobile Popup Delay</label></th>
                    <td>
                        <select id="exit_intent_mobile_delay" name="exit_intent_mobile_delay">
                            <?php foreach ( [
                                30  => '30 seconds',
                                60  => '1 minute',
                                90  => '90 seconds',
                                120 => '2 minutes (recommended)',
                                180 => '3 minutes',
                                300 => '5 minutes',
                            ] as $secs => $label ) : ?>
                            <option value="<?php echo $secs; ?>" <?php selected( (int) ( $settings['exit_intent_mobile_delay'] ?? 120 ), $secs ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">On mobile devices, exit-intent cannot be detected by mouse movement. Instead, the popup appears after this delay. Desktop visitors always see it when their mouse moves toward the browser top.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="exit_intent_gdpr_text">GDPR Consent Text</label></th>
                    <td>
                        <textarea id="exit_intent_gdpr_text" name="exit_intent_gdpr_text" class="large-text" rows="3"><?php echo esc_textarea( $settings['exit_intent_gdpr_text'] ?? 'I agree to receive one cart reminder email. I can unsubscribe at any time.' ); ?></textarea>
                        <p class="description">Displayed as a required checkbox in the popup. Customers must tick this before submitting their email. Leave blank to remove the checkbox (not recommended — UK GDPR requires consent for marketing emails).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>
        <div class="cr-notice-info">
            <strong>UK GDPR note:</strong> Capturing emails for cart recovery emails requires explicit consent from new contacts.
            The checkbox above records that consent at the point of capture. Keep the wording clear and honest — avoid pre-ticking the box or hiding it.
        </div>
    </div>

    <?php elseif ( $tab === 'templates' ) : ?>
    <!-- Template list -->
    <?php if ( ! $edit_tpl ) : ?>
    <div class="cr-card cr-card-flush">
        <div class="cr-card-header">
            <h2>Email Templates</h2>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-settings', 'tab' => 'templates', 'edit_template' => 'new' ], admin_url( 'admin.php' ) ) ); ?>"
               class="button button-primary">Add Template</a>
        </div>
        <table class="cr-table widefat">
            <thead><tr><th>Name</th><th>Subject</th><th>Default</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $templates as $tpl ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $tpl->name ); ?></strong></td>
                    <td class="cr-muted"><?php echo esc_html( $tpl->subject ); ?></td>
                    <td><?php echo $tpl->is_default ? '<span class="cr-badge cr-badge-success">Default</span>' : ''; ?></td>
                    <td>
                        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-settings', 'tab' => 'templates', 'edit_template' => $tpl->id ], admin_url( 'admin.php' ) ) ); ?>"
                           class="button button-small">Edit</a>
                        <?php if ( ! $tpl->is_default ) : ?>
                        <button class="button button-small cr-delete-template" data-id="<?php echo (int) $tpl->id; ?>">Delete</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else : /* edit / new template */ ?>
    <div class="cr-card cr-card-form">
        <div class="cr-card-header">
            <h2><?php echo $edit_tpl === 'new' || ! is_object( $edit_tpl ) ? 'New Template' : 'Edit Template: ' . esc_html( $edit_tpl->name ); ?></h2>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'cr-settings', 'tab' => 'templates' ], admin_url( 'admin.php' ) ) ); ?>" class="button">← Back</a>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'cr_save_template' ); ?>
            <input type="hidden" name="action" value="cr_save_template">
            <?php if ( is_object( $edit_tpl ) ) : ?>
            <input type="hidden" name="template_id" value="<?php echo (int) $edit_tpl->id; ?>">
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="tpl_name">Template Name</label></th>
                    <td><input type="text" id="tpl_name" name="name" class="regular-text"
                               value="<?php echo is_object( $edit_tpl ) ? esc_attr( $edit_tpl->name ) : ''; ?>" required></td>
                </tr>
                <tr>
                    <th><label for="tpl_subject">Subject</label></th>
                    <td><input type="text" id="tpl_subject" name="subject" class="large-text"
                               value="<?php echo is_object( $edit_tpl ) ? esc_attr( $edit_tpl->subject ) : ''; ?>" required>
                        <p class="description">Supports <code>{{customer_name}}</code> <code>{{shop_name}}</code> <code>{{cart_total}}</code></p></td>
                </tr>
                <tr>
                    <th><label for="tpl_body">Body (HTML)</label></th>
                    <td><textarea id="tpl_body" name="body_html" rows="20" class="large-text cr-mono" required><?php echo is_object( $edit_tpl ) ? esc_textarea( $edit_tpl->body_html ) : ''; ?></textarea>
                        <p class="description">Variables: <code>{{customer_name}}</code> <code>{{shop_name}}</code> <code>{{cart_total}}</code>
                        <code>{{cart_items_html}}</code> <code>{{cart_items_list}}</code> <code>{{cart_url}}</code> <code>{{shop_url}}</code></p></td>
                </tr>
            </table>
            <?php submit_button( is_object( $edit_tpl ) ? 'Update Template' : 'Save Template' ); ?>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
