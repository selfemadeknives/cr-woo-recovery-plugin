/* Cart Recovery — Exit Intent Email Capture */
(function ($) {
    'use strict';

    // Don't show if no cart, email already captured, or already shown this session
    if ( ! crExitIntent.hasCart || crExitIntent.hasEmail ) return;
    if ( document.cookie.indexOf( 'cr_exit=1' ) !== -1 ) return;

    var shown    = false;
    var $overlay = null;

    function setCookie() {
        var d = new Date();
        d.setTime( d.getTime() + 24 * 60 * 60 * 1000 );
        document.cookie = 'cr_exit=1; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
    }

    function closePopup() {
        if ( ! $overlay ) return;
        $overlay.removeClass( 'cr-exit-visible' );
        setTimeout( function () { $overlay.remove(); $overlay = null; }, 320 );
    }

    function showPopup() {
        if ( shown ) return;
        shown = true;
        setCookie();

        $overlay = $( '<div id="cr-exit-overlay">' );
        var $box = $( '<div id="cr-exit-box">' );

        var gdprHtml = '';
        if ( crExitIntent.gdprText ) {
            var gdprText = $( '<span>' ).text( crExitIntent.gdprText ).html();
            var privacyLink = crExitIntent.privacyUrl
                ? ' <a href="' + crExitIntent.privacyUrl + '" target="_blank" rel="noopener" style="color:inherit;text-decoration:underline;">Privacy Policy</a>.'
                : '';
            gdprHtml = '<label id="cr-exit-gdpr-label"><input type="checkbox" id="cr-exit-gdpr-check" required> ' +
                       '<span>' + gdprText + privacyLink + '</span></label>';
        }

        $box.html(
            '<button id="cr-exit-close" aria-label="Close">&times;</button>' +
            '<div id="cr-exit-icon">🗡️</div>' +
            '<h2>Don\'t lose your cart</h2>' +
            '<p class="cr-exit-sub">Leave your email and we\'ll save it for you — no spam, just a single reminder from us.</p>' +
            '<form id="cr-exit-form" novalidate>' +
                '<input type="email" id="cr-exit-email" placeholder="Your email address" autocomplete="email">' +
                gdprHtml +
                '<button type="submit">Save my cart</button>' +
            '</form>' +
            '<a id="cr-exit-dismiss" href="#">No thanks, I\'ll leave without saving</a>'
        );

        $overlay.append( $box );
        $( 'body' ).append( $overlay );

        setTimeout( function () { $overlay.addClass( 'cr-exit-visible' ); }, 16 );
        setTimeout( function () { $( '#cr-exit-email' ).trigger( 'focus' ); }, 320 );

        $( '#cr-exit-close, #cr-exit-dismiss' ).on( 'click', function ( e ) {
            e.preventDefault();
            closePopup();
        } );
        $overlay.on( 'click', function ( e ) {
            if ( $( e.target ).is( '#cr-exit-overlay' ) ) closePopup();
        } );

        $( '#cr-exit-form' ).on( 'submit', function ( e ) {
            e.preventDefault();
            var email = $.trim( $( '#cr-exit-email' ).val() );
            var $btn  = $( '#cr-exit-form button[type=submit]' );

            $( '#cr-exit-email' ).removeClass( 'cr-error' );
            $( '#cr-exit-gdpr-label' ).removeClass( 'cr-error' );

            if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
                $( '#cr-exit-email' ).addClass( 'cr-error' ).trigger( 'focus' );
                return;
            }

            // GDPR consent required if checkbox is present
            if ( crExitIntent.gdprText && ! $( '#cr-exit-gdpr-check' ).is( ':checked' ) ) {
                $( '#cr-exit-gdpr-label' ).addClass( 'cr-error' );
                return;
            }

            $btn.prop( 'disabled', true ).text( 'Saving…' );

            $.post( crExitIntent.ajaxUrl, {
                action: 'cr_capture_exit_email',
                nonce:  crExitIntent.nonce,
                email:  email,
            }, function ( resp ) {
                if ( resp.success ) {
                    $box.html(
                        '<div id="cr-exit-success">' +
                            '<div class="cr-exit-tick">✓</div>' +
                            '<h2>Cart saved!</h2>' +
                            '<p>We\'ll send you a reminder — take your time.</p>' +
                        '</div>'
                    );
                    setTimeout( closePopup, 2200 );
                } else {
                    $btn.prop( 'disabled', false ).text( 'Save my cart' );
                    $( '#cr-exit-email' ).addClass( 'cr-error' ).trigger( 'focus' );
                }
            } ).fail( function () {
                $btn.prop( 'disabled', false ).text( 'Save my cart' );
            } );
        } );
    }

    // Desktop: mouse leaving toward top of browser
    var isMobile = ( 'ontouchstart' in window ) || window.innerWidth < 768;

    if ( ! isMobile ) {
        $( document ).on( 'mouseleave', function ( e ) {
            if ( e.clientY <= 5 ) showPopup();
        } );
    } else {
        // Mobile: configurable delay (default 120s)
        setTimeout( showPopup, ( crExitIntent.mobileDelay || 120 ) * 1000 );
    }

}( jQuery ));
