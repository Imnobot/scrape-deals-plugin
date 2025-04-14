// File: assets/js/admin-settings.js (v1.1.29 + AJAX Source Actions)

jQuery(document).ready(function($) {

    // --- Helper Function for AJAX Feedback ---
    function showAjaxFeedback(element, message, isSuccess) {
        element.text(message).removeClass('success error').addClass(isSuccess ? 'success' : 'error').fadeIn();
        // Auto-hide after a few seconds
        setTimeout(() => {
            element.fadeOut(function() { $(this).empty().removeClass('success error'); });
        }, isSuccess ? 3000 : 6000); // Longer display for errors
    }

    // --- Manual Email Send ---
    const manualEmailButton = $('#dsp-send-manual-email-button');
    const manualEmailSpinner = $('#dsp-manual-email-spinner');
    const manualEmailStatusDiv = $('#dsp-manual-email-status');
    if (manualEmailButton.length > 0) {
        manualEmailButton.on('click', function() {
            manualEmailStatusDiv.html('').removeClass('notice notice-success notice-error is-dismissible');
            if (manualEmailSpinner.length) manualEmailSpinner.css('visibility', 'visible');
            manualEmailButton.prop('disabled', true);
            $.ajax({
                url: dsp_admin_ajax_obj.ajax_url, type: 'POST',
                data: { action: 'dsp_send_manual_email', nonce: dsp_admin_ajax_obj.nonce },
                success: function(response) {
                    let message = ''; let noticeClass = '';
                    if (response.success && response.data?.message) { message = response.data.message; noticeClass = 'notice-success'; }
                    else { message = response.data?.message || dsp_admin_ajax_obj.error_text; noticeClass = 'notice-error'; console.error("DSP Manual Email Error (Success False):", response); }
                    if (manualEmailStatusDiv.length) { manualEmailStatusDiv.html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>').addClass('notice is-dismissible ' + noticeClass).show(); }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("DSP Manual Email AJAX Error:", textStatus, errorThrown, jqXHR.responseJSON);
                    const message = dsp_admin_ajax_obj.error_text + ' (' + textStatus + (errorThrown ? ': ' + errorThrown : '') + ')';
                    if (manualEmailStatusDiv.length) { manualEmailStatusDiv.html('<p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>').addClass('notice notice-error is-dismissible').show(); }
                },
                complete: function() {
                    if (manualEmailSpinner.length) manualEmailSpinner.css('visibility', 'hidden');
                    manualEmailButton.prop('disabled', false);
                }
            });
        });
        // Dismiss handler for the manual email status notice
        if (manualEmailStatusDiv.length) {
            manualEmailStatusDiv.on('click', '.notice-dismiss', function(e) {
                e.preventDefault(); $(this).closest('.notice').fadeOut();
            });
        }
    }

    // --- Source Management ---
    const sourcesTableBody = $('#dsp-sources-list');
    const addSourceButton = $('#dsp-add-source-button');
    const sourceRowTemplate = $('#dsp-source-row-template');

    if (sourcesTableBody.length > 0 && sourceRowTemplate.length > 0) {

        // Add Source Handler (Does NOT use AJAX - requires full save)
        if (addSourceButton.length > 0) {
            addSourceButton.on('click', function(e) {
                e.preventDefault();
                sourcesTableBody.find('.dsp-no-sources-row').remove();
                let nextIndex = 0;
                sourcesTableBody.find('.dsp-source-row').each(function() { const currentIndex = parseInt($(this).data('index'), 10); if (!isNaN(currentIndex) && currentIndex >= nextIndex) { nextIndex = currentIndex + 1; } });
                const templateContent = sourceRowTemplate.html();
                if (!templateContent) { console.error("DSP Error: Source template content is empty."); return; }
                const newRowHtml = templateContent.replace(/__INDEX__/g, nextIndex);
                const newRow = $(newRowHtml);
                newRow.attr('data-index', nextIndex); // Ensure new row has index for AJAX actions
                sourcesTableBody.append(newRow);
                newRow.find('input[type="text"]').first().focus();
                // Note: Name/URL fields still require main "Save Settings" button press
            });
        }

        // --- AJAX Action for Updating Single Setting (Enabled, Parser) ---
        function handleSourceSettingUpdate(sourceRow, settingKey, settingValue, inputElement) {
            const rowIndex = sourceRow.data('index');
            const spinner = inputElement.siblings('.dsp-input-spinner');
            const statusDiv = inputElement.siblings('.dsp-input-save-status');

            // Prevent update if index is invalid (e.g., for newly added row before save)
            if (typeof rowIndex === 'undefined' || rowIndex === '__INDEX__') {
                showAjaxFeedback(statusDiv, 'Save Required', false); // Indicate main save is needed
                console.warn("DSP AJAX Update: Row index invalid, requires main save.");
                return;
            }

            console.log(`DSP AJAX Update: Row ${rowIndex}, Setting ${settingKey}, Value ${settingValue}`);
            spinner.css('visibility', 'visible');
            statusDiv.empty().removeClass('success error');
            inputElement.prop('disabled', true); // Disable input during AJAX

            $.ajax({
                url: dsp_admin_ajax_obj.ajax_url, type: 'POST',
                data: {
                    action: 'dsp_update_source_setting',
                    nonce: dsp_admin_ajax_obj.nonce,
                    source_index: rowIndex,
                    setting_key: settingKey,
                    setting_value: settingValue
                },
                success: function(response) {
                    if (response.success) {
                        showAjaxFeedback(statusDiv, response.data?.message || 'Saved', true);
                    } else {
                        showAjaxFeedback(statusDiv, response.data?.message || 'Error', false);
                        console.error(`DSP AJAX Update Error (${settingKey}):`, response);
                        // Optionally revert the change visually on error?
                        // if (settingKey === 'enabled') inputElement.prop('checked', !settingValue);
                        // else inputElement.val(inputElement.data('original-value')); // Requires storing original value
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("DSP AJAX Update Network Error:", textStatus, errorThrown);
                    showAjaxFeedback(statusDiv, 'Network Error', false);
                },
                complete: function() {
                    spinner.css('visibility', 'hidden');
                    inputElement.prop('disabled', false); // Re-enable input
                }
            });
        }

        // AJAX Handler for Enabled Checkbox Change
        sourcesTableBody.on('change', 'input.dsp-source-input-enabled', function() {
            const checkbox = $(this);
            const sourceRow = checkbox.closest('tr.dsp-source-row');
            const newValue = checkbox.is(':checked'); // true or false
            handleSourceSettingUpdate(sourceRow, 'enabled', newValue, checkbox);
        });

        // AJAX Handler for Parser Select Change
        sourcesTableBody.on('change', 'select.dsp-source-input-parser', function() {
            const select = $(this);
            const sourceRow = select.closest('tr.dsp-source-row');
            const newValue = select.val();
             // Store original value in case we need to revert on error
            // select.data('original-value', select.data('original-value') || select.val()); // Store only once initially
            handleSourceSettingUpdate(sourceRow, 'parser_file', newValue, select);
        });

        // AJAX Handler for Delete Button Click
        sourcesTableBody.on('click', '.dsp-delete-source-button', function(e) {
            e.preventDefault();
            const deleteButton = $(this);
            const sourceRow = deleteButton.closest('tr.dsp-source-row');
            const rowIndex = sourceRow.data('index');
            const spinner = sourceRow.find('.dsp-delete-spinner'); // Use dedicated delete spinner

            // Prevent deletion if index is invalid (e.g., newly added unsaved row)
            if (typeof rowIndex === 'undefined' || rowIndex === '__INDEX__') {
                 sourceRow.fadeOut(300, function() { $(this).remove(); }); // Just remove visually if not saved yet
                 return;
            }

            // Confirmation dialog
            const confirmMsg = typeof dsp_admin_ajax_obj !== 'undefined' && dsp_admin_ajax_obj.delete_confirm_text ? dsp_admin_ajax_obj.delete_confirm_text : 'Are you sure you want to delete this source? This cannot be undone.';
            if (!confirm(confirmMsg)) {
                return;
            }

            console.log(`DSP AJAX Delete: Row ${rowIndex}`);
            spinner.css('visibility', 'visible');
            deleteButton.prop('disabled', true); // Disable delete button
            sourceRow.addClass('dsp-deleting'); // Visual indicator

            $.ajax({
                url: dsp_admin_ajax_obj.ajax_url, type: 'POST',
                data: {
                    action: 'dsp_delete_source', // Different action for delete
                    nonce: dsp_admin_ajax_obj.nonce,
                    source_index: rowIndex
                },
                success: function(response) {
                    if (response.success) {
                        // Success: Remove the row visually
                        sourceRow.fadeOut(300, function() {
                            $(this).remove();
                            // Check if table is now empty
                            if (sourcesTableBody.find('.dsp-source-row').length === 0) {
                                const noSourcesText = typeof dsp_admin_ajax_obj !== 'undefined' && dsp_admin_ajax_obj.no_sources_text ? dsp_admin_ajax_obj.no_sources_text : 'No sources configured yet. Click "Add Source" below.';
                                const noSourcesRowHtml = '<tr class="dsp-no-sources-row"><td colspan="6">' + noSourcesText + '</td></tr>'; // Colspan 6 now
                                sourcesTableBody.append(noSourcesRowHtml);
                            }
                        });
                    } else {
                        // Error: Show message near the delete button
                        alert('Error deleting source: ' + (response.data?.message || 'Unknown error')); // Simple alert for delete error
                        console.error("DSP AJAX Delete Error:", response);
                        spinner.css('visibility', 'hidden');
                        deleteButton.prop('disabled', false);
                        sourceRow.removeClass('dsp-deleting');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("DSP AJAX Delete Network Error:", textStatus, errorThrown);
                    alert('Network error while trying to delete source.');
                    spinner.css('visibility', 'hidden');
                    deleteButton.prop('disabled', false);
                    sourceRow.removeClass('dsp-deleting');
                }
                // No 'complete' needed here as row is removed on success
            });
        });

        // Test Source Handler (Existing)
        sourcesTableBody.on('click', '.dsp-test-source-button', function(e) {
            e.preventDefault();
            const testButton = $(this);
            const sourceRow = testButton.closest('tr.dsp-source-row');
            const spinner = sourceRow.find('.dsp-test-source-spinner');
            const resultDiv = sourceRow.find('.dsp-test-source-result');
            const url = sourceRow.find('.dsp-source-input-url').val();
            const parserFile = sourceRow.find('.dsp-source-input-parser').val();
            const siteName = sourceRow.find('.dsp-source-input-name').val();
            if (!url || !parserFile) { resultDiv.text(dsp_admin_ajax_obj.test_error_text || 'URL and Parser File selection required.').removeClass('success').addClass('error'); return; }
            resultDiv.text(dsp_admin_ajax_obj.testing_text || 'Testing...').removeClass('success error'); spinner.css('visibility', 'visible'); testButton.prop('disabled', true);
            $.ajax({
                url: dsp_admin_ajax_obj.ajax_url, type: 'POST',
                data: { action: 'dsp_test_parser', nonce: dsp_admin_ajax_obj.nonce, url: url, parser_file: parserFile, site_name: siteName },
                success: function(response) {
                    if (response.success && response.data?.message) { resultDiv.html(response.data.message).removeClass('error').addClass('success'); }
                    else { resultDiv.html(response.data?.message || dsp_admin_ajax_obj.error_text).removeClass('success').addClass('error'); console.error("DSP Test Parser Error (Success False):", response); }
                },
                error: function(jqXHR, textStatus, errorThrown) { console.error("DSP Test Parser AJAX Error:", textStatus, errorThrown, jqXHR.responseJSON); const message = dsp_admin_ajax_obj.error_text + ' (AJAX: ' + textStatus + ')'; resultDiv.text(message).removeClass('success').addClass('error'); },
                complete: function() { spinner.css('visibility', 'hidden'); testButton.prop('disabled', false); }
            });
        });

    } // End if source management elements exist

}); // End document ready