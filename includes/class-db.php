<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CR_DB {

    const DB_VERSION = '1.1';

    /** Allowed cart status values — used for whitelist validation. */
    const ALLOWED_STATUSES = [ 'active', 'abandoned', 'email_sent', 'recovered', 'ignored' ];

    // ------------------------------------------------------------------ install / upgrade

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cs = $wpdb->get_charset_collate();

        dbDelta( "
            CREATE TABLE {$wpdb->prefix}cr_carts (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_key     VARCHAR(255) NOT NULL DEFAULT '',
                source          VARCHAR(20)  NOT NULL DEFAULT 'wc_session',
                woo_order_id    BIGINT UNSIGNED DEFAULT NULL,
                status          VARCHAR(20)  NOT NULL DEFAULT 'abandoned',
                customer_name   VARCHAR(255) DEFAULT NULL,
                customer_email  VARCHAR(255) DEFAULT NULL,
                customer_ip     VARCHAR(45)  DEFAULT NULL,
                customer_city   VARCHAR(100) DEFAULT NULL,
                customer_county VARCHAR(100) DEFAULT NULL,
                customer_country VARCHAR(5)  NOT NULL DEFAULT 'GB',
                cart_total      DECIMAL(10,2) NOT NULL DEFAULT 0,
                currency        VARCHAR(5)   NOT NULL DEFAULT 'GBP',
                item_count      INT UNSIGNED NOT NULL DEFAULT 0,
                abandoned_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_activity   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                email_sent      TINYINT(1)   NOT NULL DEFAULT 0,
                email_sent_at   DATETIME     DEFAULT NULL,
                recovered_at    DATETIME     DEFAULT NULL,
                notes           TEXT         DEFAULT NULL,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY session_key (session_key),
                KEY status (status),
                KEY abandoned_at (abandoned_at),
                KEY customer_email (customer_email)
            ) $cs;
        " );

        dbDelta( "
            CREATE TABLE {$wpdb->prefix}cr_cart_items (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cart_id         BIGINT UNSIGNED NOT NULL,
                product_id      BIGINT UNSIGNED DEFAULT NULL,
                variation_id    BIGINT UNSIGNED DEFAULT NULL,
                product_name    VARCHAR(255) NOT NULL DEFAULT '',
                product_sku     VARCHAR(100) DEFAULT NULL,
                variation_attrs TEXT         DEFAULT NULL,
                quantity        INT UNSIGNED NOT NULL DEFAULT 1,
                unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
                line_total      DECIMAL(10,2) NOT NULL DEFAULT 0,
                image_url       TEXT         DEFAULT NULL,
                product_url     TEXT         DEFAULT NULL,
                PRIMARY KEY (id),
                KEY cart_id (cart_id)
            ) $cs;
        " );

        dbDelta( "
            CREATE TABLE {$wpdb->prefix}cr_email_log (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cart_id         BIGINT UNSIGNED NOT NULL,
                template_id     BIGINT UNSIGNED DEFAULT NULL,
                recipient_email VARCHAR(255) NOT NULL DEFAULT '',
                subject         VARCHAR(500) NOT NULL DEFAULT '',
                body_html       LONGTEXT     NOT NULL,
                status          VARCHAR(20)  NOT NULL DEFAULT 'sent',
                error_message   TEXT         DEFAULT NULL,
                sent_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY cart_id (cart_id),
                KEY sent_at (sent_at)
            ) $cs;
        " );

        dbDelta( "
            CREATE TABLE {$wpdb->prefix}cr_email_templates (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name            VARCHAR(255) NOT NULL DEFAULT '',
                subject         VARCHAR(500) NOT NULL DEFAULT '',
                body_html       LONGTEXT     NOT NULL,
                is_default      TINYINT(1)   NOT NULL DEFAULT 0,
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $cs;
        " );

        update_option( 'cr_db_version', self::DB_VERSION );
        self::seed_templates();
    }

    /**
     * Run any DB upgrades needed for the current version.
     * Called on plugins_loaded so future schema changes are applied automatically.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'cr_db_version' ) !== self::DB_VERSION ) {
            self::install();
        }
    }

    // ------------------------------------------------------------------ seed

    private static function seed_templates(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'cr_email_templates';

        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) > 0 ) return;  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $templates = [
            [
                'name'       => 'Gentle Reminder (24h)',
                'subject'    => 'Your {{shop_name}} cart is waiting — {{cart_total}} worth of hand-forged steel',
                'body_html'  => self::tpl_gentle(),
                'is_default' => 1,
            ],
            [
                'name'       => 'Any Questions? (48h)',
                'subject'    => 'Any questions about your {{shop_name}} order?',
                'body_html'  => self::tpl_questions(),
                'is_default' => 0,
            ],
            [
                'name'       => 'Last Chance (72h)',
                'subject'    => 'Just so you know — your {{shop_name}} piece may not last',
                'body_html'  => self::tpl_scarcity(),
                'is_default' => 0,
            ],
            [
                'name'       => 'Knife Care Tips (Re-engagement)',
                'subject'    => 'Knife care tips — and your cart is still saved',
                'body_html'  => self::tpl_care(),
                'is_default' => 0,
            ],
        ];

        foreach ( $templates as $t ) {
            $wpdb->insert( $table, $t );
        }
    }

    // ------------------------------------------------------------------ carts

    /**
     * @param array{status?: string, search?: string, min_value?: float, since?: string} $args
     */
    public static function get_carts( array $args = [] ): array {
        global $wpdb;
        $t = $wpdb->prefix . 'cr_carts';

        $where  = [ '1=1' ];
        $params = [];

        // Whitelist status values to prevent SQL injection via column-value manipulation
        if ( ! empty( $args['status'] ) ) {
            if ( in_array( $args['status'], self::ALLOWED_STATUSES, true ) ) {
                $where[]  = 'status = %s';
                $params[] = $args['status'];
            }
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]  = '(customer_name LIKE %s OR customer_email LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ( isset( $args['min_value'] ) && $args['min_value'] !== '' && $args['min_value'] > 0 ) {
            $where[]  = 'cart_total >= %f';
            $params[] = (float) $args['min_value'];
        }

        if ( ! empty( $args['since'] ) ) {
            $where[]  = 'abandoned_at >= %s';
            $params[] = sanitize_text_field( $args['since'] );
        }

        $where_sql = implode( ' AND ', $where );
        $i         = $wpdb->prefix . 'cr_cart_items';
        $sql       = "SELECT c.*,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}cr_email_log el WHERE el.cart_id = c.id) AS email_count,
                        (SELECT GROUP_CONCAT(ci.product_name ORDER BY ci.id SEPARATOR ', ') FROM $i ci WHERE ci.cart_id = c.id) AS item_names
                      FROM $t c
                      WHERE $where_sql
                      ORDER BY c.abandoned_at DESC
                      LIMIT 500";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )  // spread to match variadic signature
            : $wpdb->get_results( $sql );                                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function get_cart( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cr_carts WHERE id = %d",
            $id
        ) );
    }

    public static function get_cart_by_session( string $key ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cr_carts WHERE session_key = %s",
            $key
        ) );
    }

    public static function upsert_cart( string $session_key, array $data ): int {
        global $wpdb;
        $t = $wpdb->prefix . 'cr_carts';

        $existing = self::get_cart_by_session( $session_key );

        if ( $existing ) {
            if ( in_array( $existing->status, [ 'recovered', 'ignored' ], true ) ) {
                return (int) $existing->id;
            }
            $wpdb->update( $t, array_merge( $data, [ 'updated_at' => current_time( 'mysql' ) ] ), [ 'session_key' => $session_key ] );
            return (int) $existing->id;
        }

        $wpdb->insert( $t, array_merge( [ 'session_key' => $session_key ], $data ) );
        return (int) $wpdb->insert_id;
    }

    public static function update_cart_status( int $id, string $status, array $extra = [] ): void {
        global $wpdb;
        // Whitelist status values
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) return;

        $data = array_merge( [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ], $extra );
        if ( $status === 'recovered' && empty( $extra['recovered_at'] ) ) {
            $data['recovered_at'] = current_time( 'mysql' );
        }
        $wpdb->update( $wpdb->prefix . 'cr_carts', $data, [ 'id' => $id ] );
    }

    public static function mark_recovered_by_email( string $email ): void {
        global $wpdb;
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) return;

        $now = current_time( 'mysql' );
        foreach ( [ 'abandoned', 'email_sent' ] as $status ) {
            $wpdb->update(
                $wpdb->prefix . 'cr_carts',
                [ 'status' => 'recovered', 'recovered_at' => $now, 'updated_at' => $now ],
                [ 'customer_email' => $email, 'status' => $status ]
            );
        }
    }

    // ------------------------------------------------------------------ items

    public static function get_cart_items( int $cart_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cr_cart_items WHERE cart_id = %d ORDER BY id",
            $cart_id
        ) );
    }

    public static function replace_cart_items( int $cart_id, array $items ): void {
        global $wpdb;
        $t = $wpdb->prefix . 'cr_cart_items';
        $wpdb->delete( $t, [ 'cart_id' => $cart_id ] );
        foreach ( $items as $item ) {
            $wpdb->insert( $t, array_merge( $item, [ 'cart_id' => $cart_id ] ) );
        }
    }

    // ------------------------------------------------------------------ email log

    public static function log_email( array $data ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'cr_email_log', $data );
    }

    public static function get_email_log( ?int $cart_id = null, int $limit = 200 ): array {
        global $wpdb;
        $limit = absint( $limit );
        if ( $cart_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT el.* FROM {$wpdb->prefix}cr_email_log el
                 WHERE el.cart_id = %d ORDER BY el.sent_at DESC",
                $cart_id
            ) );
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT el.*, c.customer_name
             FROM {$wpdb->prefix}cr_email_log el
             LEFT JOIN {$wpdb->prefix}cr_carts c ON el.cart_id = c.id
             ORDER BY el.sent_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Get the most recent email log entry for a cart.
     */
    public static function get_latest_email_for_cart( int $cart_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cr_email_log
             WHERE cart_id = %d ORDER BY sent_at DESC LIMIT 1",
            $cart_id
        ) );
    }

    // ------------------------------------------------------------------ templates

    public static function get_templates(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}cr_email_templates ORDER BY is_default DESC, name ASC" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
    }

    public static function get_template( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cr_email_templates WHERE id = %d",
            $id
        ) );
    }

    public static function save_template( array $data, ?int $id = null ): int {
        global $wpdb;
        $t = $wpdb->prefix . 'cr_email_templates';
        if ( $id ) {
            $wpdb->update( $t, $data, [ 'id' => $id ] );
            return $id;
        }
        $wpdb->insert( $t, $data );
        return (int) $wpdb->insert_id;
    }

    public static function delete_template( int $id ): void {
        global $wpdb;
        $row = self::get_template( $id );
        if ( ! $row || $row->is_default ) return;
        $wpdb->delete( $wpdb->prefix . 'cr_email_templates', [ 'id' => $id ] );
    }

    // ------------------------------------------------------------------ insights (cached)

    public static function get_insights(): array {
        $cache_key = 'cr_insights_v1';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        global $wpdb;
        $t = $wpdb->prefix . 'cr_carts';
        $i = $wpdb->prefix . 'cr_cart_items';

        $totals = $wpdb->get_row( "
            SELECT
                COUNT(*) AS total,
                SUM(status = 'recovered')  AS recovered,
                SUM(email_sent = 1)        AS emailed,
                SUM(status = 'recovered' AND email_sent = 1) AS recovered_after_email,
                SUM(cart_total)            AS total_value,
                SUM(IF(status='recovered',cart_total,0)) AS recovered_value,
                AVG(cart_total)            AS avg_value
            FROM $t
        " ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $top_products = $wpdb->get_results( "
            SELECT product_name,
                   SUM(quantity)           AS total_qty,
                   COUNT(DISTINCT cart_id) AS cart_count,
                   SUM(line_total)         AS total_value
            FROM $i
            GROUP BY product_name
            ORDER BY cart_count DESC
            LIMIT 10
        " ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $by_location = $wpdb->get_results( "
            SELECT COALESCE(customer_county, customer_city, 'Unknown') AS location,
                   COUNT(*)        AS count,
                   SUM(cart_total) AS total_value
            FROM $t
            WHERE customer_county IS NOT NULL OR customer_city IS NOT NULL
            GROUP BY location
            ORDER BY count DESC
            LIMIT 10
        " ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $trend = $wpdb->get_results( "
            SELECT DATE(abandoned_at) AS day,
                   COUNT(*)           AS count,
                   SUM(cart_total)    AS value
            FROM $t
            WHERE abandoned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY day
            ORDER BY day
        " ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $result = compact( 'totals', 'top_products', 'by_location', 'trend' );

        // Cache for 1 hour — invalidated on sync via cr_bust_insights_cache()
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );

        return $result;
    }

    public static function bust_insights_cache(): void {
        delete_transient( 'cr_insights_v1' );
    }

    // ------------------------------------------------------------------ email templates

    private static function tpl_gentle(): string {
        return '<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;color:#1c1917;line-height:1.7;">
<p>Hi {{customer_name}},</p>
<p>I noticed you had a look at some pieces and left a few things in your cart. No pressure at all — I just wanted to make sure you didn\'t lose them.</p>
<p><strong>What you were looking at:</strong></p>
{{cart_items_html}}
<p>Every knife I make is forged and finished by hand. If you had any questions about the steel, handle materials, or custom options before committing, just hit reply — I read every email personally.</p>
<p><a href="{{cart_url}}" style="display:inline-block;background:#1c1917;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">Return to your cart →</a></p>
<p>All the best,<br><strong>{{shop_name}}</strong></p>
</div>';
    }

    private static function tpl_questions(): string {
        return '<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;color:#1c1917;line-height:1.7;">
<p>Hi {{customer_name}},</p>
<p>You left {{cart_total}} worth of kit in your cart a couple of days ago — I wanted to reach out directly in case anything was holding you back.</p>
<p><strong>Common reasons people pause:</strong></p>
<ul>
<li><strong>"Is this the right knife for me?"</strong> — Happy to chat through exactly what you\'re using it for.</li>
<li><strong>"Lead time?"</strong> — Most pieces ship within a few days. Custom orders take a few weeks.</li>
<li><strong>"Can I get a custom handle or blade?"</strong> — Often yes. Reply and let\'s talk.</li>
<li><strong>"Returns?"</strong> — Please check the website for our full returns policy.</li>
</ul>
<p>I\'m a one-person workshop. When you order from me, you\'re talking directly to the person who made it.</p>
<p><a href="{{cart_url}}" style="display:inline-block;background:#1c1917;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">Complete your order — {{cart_total}} →</a></p>
<p>Cheers,<br><strong>{{shop_name}}</strong></p>
</div>';
    }

    private static function tpl_scarcity(): string {
        return '<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;color:#1c1917;line-height:1.7;">
<p>Hi {{customer_name}},</p>
<p>I make everything in small batches, and the piece(s) you were looking at are either one-of-a-kind or part of a very small run. I can\'t guarantee they\'ll still be available if you come back later.</p>
{{cart_items_html}}
<p><a href="{{cart_url}}" style="display:inline-block;background:#1c1917;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">Secure your order →</a></p>
<p>If something put you off or you have questions, just reply to this email. I\'m not a faceless retailer — I\'m the person who made it.</p>
<p><strong>{{shop_name}}</strong></p>
</div>';
    }

    private static function tpl_care(): string {
        return '<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;color:#1c1917;line-height:1.7;">
<p>Hi {{customer_name}},</p>
<p>While your cart is still saved, I thought I\'d share something useful: the single biggest thing that extends a hand-forged knife\'s life is a light coat of food-safe mineral oil on the blade every few weeks — especially on carbon steel.</p>
{{cart_items_html}}
<p><a href="{{cart_url}}" style="display:inline-block;background:#1c1917;color:#fff;padding:12px 24px;text-decoration:none;border-radius:4px;">Return and complete your order →</a></p>
<p>Any questions, just reply.<br><strong>{{shop_name}}</strong></p>
</div>';
    }
}
