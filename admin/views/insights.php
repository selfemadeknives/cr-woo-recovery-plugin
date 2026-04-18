<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$data     = CR_DB::get_insights();
$t        = $data['totals'];
$total    = (int) ( $t->total ?? 0 );
$recovered = (int) ( $t->recovered ?? 0 );
$emailed   = (int) ( $t->emailed ?? 0 );
$rec_after = (int) ( $t->recovered_after_email ?? 0 );
$recovery_rate = $total > 0 ? round( $recovered / $total * 100 ) : 0;
$email_rate    = $emailed > 0 ? round( $rec_after / $emailed * 100 ) : 0;
$top_products  = $data['top_products'];
$by_location   = $data['by_location'];
$trend         = $data['trend'];

$static_insights = [
    [ 'icon' => '🔪', 'title' => 'The Custom Upgrade Offer',
      'body' => 'Customers abandoning a standard knife are prime candidates for a custom upgrade. Mention in your recovery email that for a little more, you can add a personalised handle, engraving, or custom sheath. A £265 knife becoming a £320 custom piece is a better outcome than a lost sale — and sets you apart from every retailer.' ],
    [ 'icon' => '🧴', 'title' => 'Bundle Knife + Care Kit',
      'body' => 'Customers with knives in their cart but no care products represent an easy upsell. A "free care kit with every knife" offer in a recovery email has high perceived value at low cost — and teaches them to look after your work.' ],
    [ 'icon' => '🎁', 'title' => 'Gift Purchase Behaviour',
      'body' => 'Expect abandonment spikes in November/December and around Father\'s Day. These are gift buyers who hesitate on delivery dates and personalisation. Consider a "choose delivery date" and "add a gift message" option to reduce this friction at checkout.' ],
    [ 'icon' => '📸', 'title' => 'Photography & Trust for High-Value Items',
      'body' => 'Knives above £200 with high abandonment benefit from more trust-building content: in-progress forge shots, steel spec details, handle close-ups, and customer testimonials. Link to a "how it\'s made" page in recovery emails for your most expensive pieces.' ],
    [ 'icon' => '⚡', 'title' => 'Honest Scarcity for One-of-a-Kind Pieces',
      'body' => 'For one-off or small-run knives, genuine scarcity messaging is powerful and truthful: "This piece is one of three from this Damascus billet — I can\'t guarantee it will be here if you come back." Authenticity matters — never fake scarcity on production items.' ],
    [ 'icon' => '🤝', 'title' => 'Personalise for Returning Customers',
      'body' => 'A returning customer abandoning a cart is a warmer lead. Personalise their email: "You\'ve ordered from me before and I wanted to reach out personally." This takes seconds to add and converts far better than a generic template.' ],
    [ 'icon' => '💷', 'title' => 'Protect Pricing with Value-Add Offers',
      'body' => 'Avoid blanket discounts on handmade goods — they erode perceived quality. Instead offer: free sharpening, a leather sheath, monogramming, or a care kit. These feel generous without undermining what your work is worth.' ],
    [ 'icon' => '📧', 'title' => 'The Workshop Newsletter as Long-Term Nurture',
      'body' => 'Every abandoned cart is a warm lead. Even customers who don\'t convert now are interested in your craft. Offer to add them to a low-frequency "new pieces from the forge" email. People who follow your work for six months often become your best customers — especially for commissions.' ],
    [ 'icon' => '🧠', 'title' => 'Pricing Psychology Around £150',
      'body' => 'Your £120–£350 range spans a psychological purchase threshold. Items just above £150 may benefit from a direct comparison in recovery emails: "A quality kitchen knife from a retailer costs £80–£120. Mine is made by hand, from steel I chose, with a handle I fitted. It will outlast anything mass-produced."' ],
    [ 'icon' => '✉️', 'title' => 'Commission Conversion',
      'body' => 'Some customers abandoning a standard knife actually want something custom but don\'t know how to ask. Add to recovery emails: "If none of my current pieces are quite right, I take a small number of commissions — reply and tell me what you have in mind." This opens conversations that can become your highest-margin work.' ],
];
?>
<div class="wrap cr-wrap">
    <h1 class="cr-page-title"><span class="dashicons dashicons-chart-bar"></span> Insights & Strategy</h1>
    <p class="cr-subheading">Live data from your carts, plus strategic advice tailored for a custom knife maker.</p>

    <?php if ( $total > 0 ) : ?>
    <div class="cr-stats-grid cr-stats-grid-wide">
        <div class="cr-stat-card cr-stat-amber"><div class="cr-stat-label">Recovery Rate</div><div class="cr-stat-value"><?php echo $recovery_rate; ?>%</div><div class="cr-stat-sub"><?php echo $recovered; ?>/<?php echo $total; ?> carts</div></div>
        <div class="cr-stat-card cr-stat-green"><div class="cr-stat-label">Email Recovery</div><div class="cr-stat-value"><?php echo $email_rate; ?>%</div><div class="cr-stat-sub">of emailed carts</div></div>
        <div class="cr-stat-card cr-stat-stone"><div class="cr-stat-label">Value Recovered</div><div class="cr-stat-value"><?php echo wp_kses_post( wc_price( (float) ( $t->recovered_value ?? 0 ) ) ); ?></div><div class="cr-stat-sub">from <?php echo $recovered; ?> carts</div></div>
        <div class="cr-stat-card cr-stat-blue"><div class="cr-stat-label">Avg Cart Value</div><div class="cr-stat-value"><?php echo wp_kses_post( wc_price( (float) ( $t->avg_value ?? 0 ) ) ); ?></div><div class="cr-stat-sub">per abandoned cart</div></div>
    </div>

    <?php if ( ! empty( $top_products ) || ! empty( $by_location ) ) : ?>
    <div class="cr-two-col">
        <?php if ( ! empty( $top_products ) ) : ?>
        <div class="cr-card">
            <div class="cr-card-header"><h2>Most Abandoned Products</h2></div>
            <table class="cr-table widefat">
                <thead><tr><th>Product</th><th class="cr-center">Carts</th><th class="cr-right">Total Value</th></tr></thead>
                <tbody>
                <?php foreach ( array_slice( $top_products, 0, 8 ) as $p ) : ?>
                    <tr>
                        <td><?php echo esc_html( $p->product_name ); ?></td>
                        <td class="cr-center"><?php echo (int) $p->cart_count; ?></td>
                        <td class="cr-right"><?php echo wp_kses_post( wc_price( (float) $p->total_value ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $by_location ) ) : ?>
        <div class="cr-card">
            <div class="cr-card-header"><h2>Geographic Clusters</h2></div>
            <table class="cr-table widefat">
                <thead><tr><th>Location</th><th class="cr-center">Carts</th><th class="cr-right">Value</th></tr></thead>
                <tbody>
                <?php foreach ( array_slice( $by_location, 0, 8 ) as $loc ) : ?>
                    <tr>
                        <td><?php echo esc_html( $loc->location ); ?></td>
                        <td class="cr-center"><?php echo (int) $loc->count; ?></td>
                        <td class="cr-right"><?php echo wp_kses_post( wc_price( (float) $loc->total_value ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Static strategy cards -->
    <h2 class="cr-section-title">Strategy for Custom Knife Makers</h2>
    <div class="cr-insight-grid">
        <?php foreach ( $static_insights as $ins ) : ?>
        <div class="cr-insight-card">
            <div class="cr-insight-icon"><?php echo esc_html( $ins['icon'] ); ?></div>
            <div>
                <h3><?php echo esc_html( $ins['title'] ); ?></h3>
                <p><?php echo esc_html( $ins['body'] ); ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
