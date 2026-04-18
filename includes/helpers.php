<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render a coloured status badge (output is safe — all parts are hardcoded or esc_html'd).
 *
 * @return string Safe HTML.
 */
function cr_status_badge( string $status ): string {
    $map = [
        'active'     => [ 'cr-badge-info',    'Active' ],
        'abandoned'  => [ 'cr-badge-warning', 'Abandoned' ],
        'email_sent' => [ 'cr-badge-info',    'Email Sent' ],
        'recovered'  => [ 'cr-badge-success', 'Recovered' ],
        'ignored'    => [ 'cr-badge-muted',   'Ignored' ],
        'sent'       => [ 'cr-badge-success', 'Sent' ],
        'failed'     => [ 'cr-badge-danger',  'Failed' ],
    ];
    $cfg = $map[ $status ] ?? [ 'cr-badge-muted', $status ];
    return '<span class="cr-badge ' . esc_attr( $cfg[0] ) . '">' . esc_html( $cfg[1] ) . '</span>';
}

/**
 * Render a source badge.
 *
 * @return string Safe HTML.
 */
function cr_source_badge( string $source ): string {
    $map = [
        'wc_session'    => [ 'cr-badge-info',   'Live Cart' ],
        'pending_order' => [ 'cr-badge-muted',  'Pending Order' ],
        'failed_order'  => [ 'cr-badge-danger', 'Failed Payment' ],
    ];
    $cfg = $map[ $source ] ?? [ 'cr-badge-muted', $source ];
    return '<span class="cr-badge ' . esc_attr( $cfg[0] ) . '">' . esc_html( $cfg[1] ) . '</span>';
}

/**
 * Human-readable time ago string.
 */
function cr_time_ago( string $datetime ): string {
    $ts   = strtotime( $datetime );
    if ( ! $ts ) return '—';
    $diff = time() - $ts;

    if ( $diff < 60 )     return 'just now';
    if ( $diff < 3600 )   return round( $diff / 60 ) . 'm ago';
    if ( $diff < 86400 )  return round( $diff / 3600 ) . 'h ago';
    if ( $diff < 604800 ) return round( $diff / 86400 ) . 'd ago';
    return (string) wp_date( 'j M Y', $ts );
}

/**
 * Format a datetime string for display using WordPress timezone.
 */
function cr_format_datetime( ?string $datetime ): string {
    if ( ! $datetime ) return '—';
    $ts = strtotime( $datetime );
    return $ts ? (string) wp_date( 'j M Y, H:i', $ts ) : '—';
}

/**
 * Returns a wp_kses allowlist suitable for HTML email body content.
 * Allows inline styles needed for email rendering across clients.
 */
function cr_kses_email_allowlist(): array {
    $shared = [
        'style' => true,
        'class' => true,
        'id'    => true,
    ];
    return [
        'div'    => $shared,
        'span'   => $shared,
        'p'      => $shared,
        'br'     => [],
        'hr'     => $shared,
        'h1'     => $shared,
        'h2'     => $shared,
        'h3'     => $shared,
        'h4'     => $shared,
        'strong' => $shared,
        'b'      => $shared,
        'em'     => $shared,
        'i'      => $shared,
        'small'  => $shared,
        'ul'     => $shared,
        'ol'     => $shared,
        'li'     => $shared,
        'a'      => array_merge( $shared, [
            'href'   => true,
            'target' => true,
            'rel'    => true,
            'title'  => true,
        ] ),
        'img'    => array_merge( $shared, [
            'src'    => true,
            'alt'    => true,
            'width'  => true,
            'height' => true,
            'border' => true,
        ] ),
        'table'  => array_merge( $shared, [
            'width'       => true,
            'border'      => true,
            'cellpadding' => true,
            'cellspacing' => true,
            'align'       => true,
        ] ),
        'thead'  => $shared,
        'tbody'  => $shared,
        'tfoot'  => $shared,
        'tr'     => array_merge( $shared, [ 'align' => true, 'valign' => true ] ),
        'th'     => array_merge( $shared, [
            'width'   => true,
            'align'   => true,
            'valign'  => true,
            'colspan' => true,
            'rowspan' => true,
        ] ),
        'td'     => array_merge( $shared, [
            'width'   => true,
            'align'   => true,
            'valign'  => true,
            'colspan' => true,
            'rowspan' => true,
        ] ),
        'center' => [],
        'font'   => [ 'color' => true, 'size' => true, 'face' => true ],
    ];
}

/**
 * Sanitize HTML for safe storage/display as an email body.
 * More permissive than wp_kses_post — allows inline styles and table attributes.
 */
function cr_kses_email( string $html ): string {
    return wp_kses( $html, cr_kses_email_allowlist() );
}
