/* Cart Recovery — checkout email capture
 * Fires silently when the customer leaves the email field,
 * before any form submission occurs.
 */
(function ($) {
    'use strict';

    var captured = false;

    function maybeCaptureEmail() {
        if ( captured ) return;

        var email = $('#billing_email').val() || '';
        if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) return;

        captured = true;

        $.post( crCheckout.ajaxUrl, {
            action:     'cr_capture_checkout_email',
            nonce:      crCheckout.nonce,
            email:      email,
            first_name: $('#billing_first_name').val() || '',
            last_name:  $('#billing_last_name').val()  || '',
        } );
    }

    // Capture when user leaves the email field
    $(document).on( 'blur',   '#billing_email', maybeCaptureEmail );
    // Also catch browser autofill, which may not fire blur
    $(document).on( 'change', '#billing_email', maybeCaptureEmail );

}(jQuery));
