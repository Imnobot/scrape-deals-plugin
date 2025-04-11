// File: assets/js/admin-settings.js (v1.1.10 - Add Test Button JS)

jQuery(document).ready(function($) {

    // --- Manual Email Send ---
    const manualEmailButton = $('#dsp-send-manual-email-button'); const manualEmailSpinner = $('#dsp-manual-email-spinner'); const manualEmailStatusDiv = $('#dsp-manual-email-status');
    if (manualEmailButton.length > 0) { manualEmailButton.on('click', function() { manualEmailStatusDiv.html('').removeClass('notice notice-success notice-error is-dismissible'); if (manualEmailSpinner.length) manualEmailSpinner.css('visibility', 'visible'); manualEmailButton.prop('disabled', true); $.ajax({ url: dsp_admin_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_send_manual_email', nonce: dsp_admin_ajax_obj.nonce }, success: function(response) { let message = ''; let noticeClass = ''; if (response.success && response.data?.message) { message = response.data.message; noticeClass = 'notice-success'; } else { message = response.data?.message || dsp_admin_ajax_obj.error_text; noticeClass = 'notice-error'; console.error("DSP Manual Email Error (Success False):", response); } if (manualEmailStatusDiv.length) { manualEmailStatusDiv.html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>').addClass('notice is-dismissible ' + noticeClass).show(); } }, error: function(jqXHR, textStatus, errorThrown) { console.error("DSP Manual Email AJAX Error:", textStatus, errorThrown, jqXHR.responseJSON); const message = dsp_admin_ajax_obj.error_text + ' (' + textStatus + (errorThrown ? ': ' + errorThrown : '') + ')'; if (manualEmailStatusDiv.length) { manualEmailStatusDiv.html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>').addClass('notice notice-error is-dismissible').show(); } }, complete: function() { if (manualEmailSpinner.length) manualEmailSpinner.css('visibility', 'hidden'); manualEmailButton.prop('disabled', false); } }); }); if (manualEmailStatusDiv.length) { manualEmailStatusDiv.on('click', '.notice-dismiss', function(e) { e.preventDefault(); $(this).closest('.notice').fadeOut(); }); } }

    // --- Source Management ---
    const sourcesTableBody = $('#dsp-sources-list'); const addSourceButton = $('#dsp-add-source-button'); const sourceRowTemplate = $('#dsp-source-row-template');
    if (sourcesTableBody.length > 0 && addSourceButton.length > 0 && sourceRowTemplate.length > 0) {
        // Add Source Handler (Existing)
        addSourceButton.on('click', function(e) { e.preventDefault(); sourcesTableBody.find('.dsp-no-sources-row').remove(); let nextIndex = 0; sourcesTableBody.find('.dsp-source-row').each(function() { const currentIndex = parseInt($(this).data('index'), 10); if (!isNaN(currentIndex) && currentIndex >= nextIndex) { nextIndex = currentIndex + 1; } }); const templateContent = sourceRowTemplate.html(); if (!templateContent) { console.error("DSP Error: Source template content is empty."); return; } const newRowHtml = templateContent.replace(/__INDEX__/g, nextIndex); const newRow = $(newRowHtml); newRow.attr('data-index', nextIndex); sourcesTableBody.append(newRow); newRow.find('input[type="text"]').first().focus(); });
        // Delete Source Handler (Existing)
        sourcesTableBody.on('click', '.dsp-delete-source-button', function(e) { e.preventDefault(); const button = $(this); const rowToDelete = button.closest('tr.dsp-source-row'); if (!confirm('Are you sure you want to delete this source? This cannot be undone.')) { return; } rowToDelete.fadeOut(300, function() { $(this).remove(); if (sourcesTableBody.find('.dsp-source-row').length === 0) { const noSourcesText = typeof dsp_admin_ajax_obj !== 'undefined' && dsp_admin_ajax_obj.no_sources_text ? dsp_admin_ajax_obj.no_sources_text : 'No sources configured yet. Click "Add Source" below.'; const noSourcesRowHtml = '<tr class="dsp-no-sources-row"><td colspan="5">' + noSourcesText + '</td></tr>'; sourcesTableBody.append(noSourcesRowHtml); } }); });

        // *** NEW: Test Source Handler ***
        sourcesTableBody.on('click', '.dsp-test-source-button', function(e) {
            e.preventDefault();
            const testButton = $(this);
            const sourceRow = testButton.closest('tr.dsp-source-row');
            const spinner = sourceRow.find('.dsp-test-source-spinner');
            const resultDiv = sourceRow.find('.dsp-test-source-result');

            // Get values from the inputs/select in THIS row
            const url = sourceRow.find('.dsp-source-input-url').val();
            const parserFile = sourceRow.find('.dsp-source-input-parser').val(); // Read from select
            const siteName = sourceRow.find('.dsp-source-input-name').val(); // Pass name for context

            // Basic validation
            if (!url || !parserFile) {
                resultDiv.text(dsp_admin_ajax_obj.test_error_text || 'URL and Parser File selection required.').removeClass('success').addClass('error');
                return;
            }

            // Show loading state
            resultDiv.text(dsp_admin_ajax_obj.testing_text || 'Testing...').removeClass('success error');
            spinner.css('visibility', 'visible');
            testButton.prop('disabled', true);

            // Perform AJAX request
            $.ajax({
                url: dsp_admin_ajax_obj.ajax_url,
                type: 'POST',
                data: {
                    action: 'dsp_test_parser',
                    nonce: dsp_admin_ajax_obj.nonce, // Use admin nonce
                    url: url,
                    parser_file: parserFile,
                    site_name: siteName // Optional context
                },
                success: function(response) {
                    if (response.success && response.data?.message) {
                         // Use html() to allow potential <em> tags etc. in message
                        resultDiv.html(response.data.message).removeClass('error').addClass('success');
                    } else {
                        resultDiv.html(response.data?.message || dsp_admin_ajax_obj.error_text).removeClass('success').addClass('error');
                        console.error("DSP Test Parser Error (Success False):", response);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("DSP Test Parser AJAX Error:", textStatus, errorThrown, jqXHR.responseJSON);
                    const message = dsp_admin_ajax_obj.error_text + ' (AJAX: ' + textStatus + ')';
                    resultDiv.text(message).removeClass('success').addClass('error');
                },
                complete: function() {
                    spinner.css('visibility', 'hidden');
                    testButton.prop('disabled', false);
                    // Optional: auto-hide result message after a delay
                    // setTimeout(() => { resultDiv.empty().removeClass('success error'); }, 10000);
                }
            });
        });
        // *** END NEW TEST HANDLER ***

    } // End if source management elements exist

}); // End document ready