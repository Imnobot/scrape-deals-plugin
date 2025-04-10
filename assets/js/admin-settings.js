// File: assets/js/admin-settings.js (v1.0.8 level - Updates AJAX action for manual send)

jQuery(document).ready(function($) {
    // Use more specific IDs for the manual send elements
    const button = $('#dsp-send-manual-email-button');
    const spinner = $('#dsp-manual-email-spinner');
    const statusDiv = $('#dsp-manual-email-status');

    if (!button.length) {
        return; // Exit if button not found
    }

    button.on('click', function() {
        // Clear previous status, show spinner, disable button
        statusDiv
            .html('')
            .removeClass('notice notice-success notice-error is-dismissible');
        spinner.css('visibility', 'visible');
        button.prop('disabled', true);

        // Make AJAX call
        $.ajax({
            url: dsp_admin_ajax_obj.ajax_url,
            type: 'POST',
            data: {
                action: 'dsp_send_manual_email', // <<< CHANGED ACTION NAME HERE
                nonce: dsp_admin_ajax_obj.nonce
            },
            success: function(response) {
                let message = '';
                let noticeClass = '';

                if (response.success && response.data?.message) {
                    message = response.data.message;
                    noticeClass = 'notice-success';
                } else {
                    message = response.data?.message || dsp_admin_ajax_obj.error_text;
                    noticeClass = 'notice-error';
                    console.error("DSP Manual Email Error (Success False):", response);
                }
                // Add dismiss button and WP classes for styling
                 statusDiv
                    .html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>')
                    .addClass('notice is-dismissible ' + noticeClass)
                    .show();
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("DSP Manual Email AJAX Error:", textStatus, errorThrown, jqXHR.responseJSON);
                const message = dsp_admin_ajax_obj.error_text + ' (' + textStatus + (errorThrown ? ': ' + errorThrown : '') + ')';
                statusDiv
                    .html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>')
                    .addClass('notice notice-error is-dismissible')
                    .show();
            },
            complete: function() {
                spinner.css('visibility', 'hidden');
                button.prop('disabled', false);
            }
        });
    });

    // Delegate dismissal click event
    statusDiv.on('click', '.notice-dismiss', function(e) {
        e.preventDefault();
        $(this).closest('.notice').fadeOut();
    });

});