/* Cart Recovery — Admin JS */
(function ($) {
    'use strict';

    // ------------------------------------------------------------------ Sync Now
    $(document).on('click', '#cr-sync-btn', function () {
        var $btn = $(this);
        var $msg = $('#cr-sync-msg');

        $btn.prop('disabled', true).find('.dashicons').addClass('spin');
        $msg.hide();

        $.post(crAjax.url, {
            action: 'cr_sync_now',
            nonce:  crAjax.nonce
        }, function (res) {
            $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
            if (res.success) {
                $msg.removeClass('notice-error').addClass('notice notice-success')
                    .html('<p>Sync complete. Refreshing…</p>').show();
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                var $errP = $('<p>').text('Sync failed: ' + (res.data || 'unknown error'));
                $msg.removeClass('notice-success').addClass('notice notice-error')
                    .empty().append($errP).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $msg.addClass('notice notice-error').html('<p>Request failed — please try again.</p>').show();
        });
    });

    // ------------------------------------------------------------------ Status buttons
    $(document).on('click', '.cr-status-btn', function () {
        var $btn    = $(this);
        var cart_id = $btn.data('cart-id');
        var status  = $btn.data('status');

        $btn.prop('disabled', true).text('Saving…');

        $.post(crAjax.url, {
            action:  'cr_update_status',
            nonce:   crAjax.nonce,
            cart_id: cart_id,
            status:  status
        }, function (res) {
            if (res.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false).text(status);
                alert('Error: ' + (res.data || 'unknown'));
            }
        });
    });

    // ------------------------------------------------------------------ Save notes
    $(document).on('click', '#cr-save-notes', function () {
        var $btn    = $(this);
        var cart_id = $btn.data('cart-id');
        var notes   = $('#cr-notes').val();
        var $msg    = $('#cr-notes-msg');

        $btn.prop('disabled', true).text('Saving…');

        $.post(crAjax.url, {
            action:  'cr_save_notes',
            nonce:   crAjax.nonce,
            cart_id: cart_id,
            notes:   notes
        }, function (res) {
            $btn.prop('disabled', false).text('Save Notes');
            if (res.success) {
                $msg.text('Saved!').css('color', '#16a34a').show();
                setTimeout(function () { $msg.fadeOut(); }, 2000);
            } else {
                $msg.text('Failed').css('color', '#dc2626').show();
            }
        });
    });

    // ------------------------------------------------------------------ Email modal
    $(document).on('click', '#cr-open-email', function () {
        $('#cr-email-modal').show();
        $('body').css('overflow', 'hidden');
    });

    function closeEmailModal() {
        $('#cr-email-modal').hide();
        $('body').css('overflow', '');
    }

    $(document).on('click', '#cr-close-email, #cr-cancel-email, .cr-modal-backdrop', closeEmailModal);

    // Template switcher — reads from crTemplates object (set via wp_localize_script),
    // never from HTML attributes, to prevent reflected-XSS via template body content.
    $(document).on('change', '#cr-template-select', function () {
        var id  = $(this).val();
        var tpl = (typeof crTemplates !== 'undefined') ? crTemplates[id] : null;
        if (tpl) {
            $('#cr-email-subject').val(tpl.subject);
            $('#cr-email-body').val(tpl.body);
        }
    });

    // Send email
    $(document).on('click', '#cr-send-email-btn', function () {
        var $btn     = $(this);
        var cart_id  = $btn.data('cart-id');
        var tpl_id   = $('#cr-template-select').val();
        var subject  = $('#cr-email-subject').val();
        var body     = $('#cr-email-body').val();
        var $msg     = $('#cr-send-msg');

        if (!subject || !body) {
            $msg.removeClass('cr-send-msg-success').addClass('notice cr-send-msg-error')
                .html('<p>Subject and body are required.</p>').show();
            return;
        }

        $btn.prop('disabled', true).html('<span class="dashicons dashicons-email-alt"></span> Sending…');
        $msg.hide();

        $.post(crAjax.url, {
            action:      'cr_send_email',
            nonce:       crAjax.nonce,
            cart_id:     cart_id,
            template_id: tpl_id,
            subject:     subject,
            body_html:   body
        }, function (res) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send Email');
            if (res.success) {
                $msg.removeClass('cr-send-msg-error').addClass('notice cr-send-msg-success')
                    .html('<p>Email sent successfully!</p>').show();
                setTimeout(function () {
                    closeEmailModal();
                    location.reload();
                }, 1500);
            } else {
                var $failP = $('<p>').text(res.data || 'Failed to send email. Check Settings > Email.');
                $msg.removeClass('cr-send-msg-success').addClass('notice cr-send-msg-error')
                    .empty().append($failP).show();
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> Send Email');
            $msg.addClass('notice cr-send-msg-error').html('<p>Request failed — please try again.</p>').show();
        });
    });

    // ------------------------------------------------------------------ Delete template
    $(document).on('click', '.cr-delete-template', function () {
        if (!confirm('Delete this template? This cannot be undone.')) return;

        var $btn = $(this);
        var id   = $btn.data('id');

        $.post(crAjax.url, {
            action:      'cr_delete_template',
            nonce:       crAjax.nonce,
            template_id: id
        }, function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
            } else {
                alert('Could not delete: ' + (res.data || 'unknown error'));
            }
        });
    });

    // ------------------------------------------------------------------ Dismiss modal on Escape
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeEmailModal();
    });

    // ------------------------------------------------------------------ Spinner animation (dashicons)
    var styleEl = document.createElement('style');
    styleEl.textContent = '@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} } .spin{animation:spin .7s linear infinite}';
    document.head.appendChild(styleEl);

}(jQuery));
