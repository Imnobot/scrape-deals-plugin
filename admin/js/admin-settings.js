// File: admin/js/admin-settings.js (v1.3.1 - Add Clear Deals Action)
jQuery(document).ready(function($) {

    const tableBody = $('#dsp-managed-sites-body');
    const addBtn = $('#dsp-add-site-button');
    const templateRow = $('#dsp-site-row-template');
    // *** NEW: Clear Deals Selectors ***
    const clearBtn = $('#dsp-clear-deals-button');
    const clearSpinner = $('#dsp-clear-deals-spinner');
    const clearStatus = $('#dsp-clear-deals-status');


    if (!tableBody.length || !addBtn.length || !templateRow.length) {
        console.warn('DSP Admin: Required elements for site management not found.');
        // Don't return if only clear button missing, maybe other JS needed
    }

    // --- Add New Row ---
    addBtn.on('click', function() { /* ... same as before ... */
        $('#dsp-no-sites-row').remove(); const newKey = 'new_' + Date.now() + '_' + Math.floor(Math.random() * 1000); const newRow = templateRow.clone(); newRow .attr('id', '') .attr('data-key', newKey) .css('display', ''); newRow.find('[name]').each(function() { const currentName = $(this).attr('name'); if (currentName) { $(this).attr('name', currentName.replace(/__INDEX__/g, newKey)); } }); newRow.find('.dsp-test-parser-button').attr('data-key', newKey); newRow.find('input[name$="[name]"]').attr('placeholder', dsp_admin_vars.text.placeholder_name || 'e.g., New Site'); newRow.find('input[name$="[url]"]').attr('placeholder', dsp_admin_vars.text.placeholder_url || 'https://...'); newRow.find('input[name$="[parser]"]').attr('placeholder', dsp_admin_vars.text.placeholder_parser || 'e.g., parse_newsite_php'); tableBody.append(newRow);
    });

    // --- Remove Row (Mark for Deletion) ---
    tableBody.on('click', '.dsp-remove-site-button', function() { /* ... same as before ... */
        const button = $(this); const row = button.closest('tr.dsp-site-row'); const deleteFlagInput = row.find('.dsp-delete-flag'); const isMarked = deleteFlagInput.val() === '1'; if (isMarked) { deleteFlagInput.val('0'); row.removeClass('dsp-marked-for-removal').fadeTo(200, 1.0); button.attr('title', dsp_admin_vars.text.remove_mark_title || 'Mark for deletion on save'); row.find('input, select, button').not(this).prop('disabled', false); } else { deleteFlagInput.val('1'); row.addClass('dsp-marked-for-removal').fadeTo(200, 0.6); button.attr('title', dsp_admin_vars.text.remove_undo_title || 'Undo deletion mark'); }
    });

    // --- Test Parser AJAX ---
    tableBody.on('click', '.dsp-test-parser-button', function() { /* ... same as before ... */
        const button = $(this); const row = button.closest('tr.dsp-site-row'); const spinner = row.find('.spinner'); const resultSpan = row.find('.dsp-test-result'); const urlInput = row.find('input[name$="[url]"]'); const parserInput = row.find('input[name$="[parser]"]'); const url = urlInput.val().trim(); const parser = parserInput.val().trim(); if (!url || !parser) { resultSpan.text(dsp_admin_vars.text.error_url_parser_missing || 'Error: URL or Parser missing.').removeClass('dsp-test-success').addClass('dsp-test-error'); return; } resultSpan.text('').removeClass('dsp-test-success dsp-test-error'); spinner.addClass('is-active'); button.prop('disabled', true); row.find('.dsp-remove-site-button').prop('disabled', true); $.ajax({ url: dsp_admin_vars.ajax_url, type: 'POST', data: { action: 'dsp_test_parser', _ajax_nonce: dsp_admin_vars.nonce, url: url, parser: parser }, success: function(response) { if (response.success && response.data?.message) { resultSpan.text(response.data.message).removeClass('dsp-test-error').addClass('dsp-test-success'); } else { const errorMsg = response.data?.message || dsp_admin_vars.text.error_unknown || 'Unknown error occurred.'; resultSpan.text((dsp_admin_vars.text.error_prefix || 'Error:') + ' ' + errorMsg).removeClass('dsp-test-success').addClass('dsp-test-error'); } }, error: function(jqXHR, textStatus, errorThrown) { console.error("DSP Test AJAX Error:", textStatus, errorThrown); resultSpan.text(dsp_admin_vars.text.error_ajax || 'AJAX Error: Request failed.').removeClass('dsp-test-success').addClass('dsp-test-error'); }, complete: function() { spinner.removeClass('is-active'); button.prop('disabled', false); row.find('.dsp-remove-site-button').prop('disabled', false); } });
    });

    // --- Check All functionality ---
    $('#dsp_enable_all, #dsp_enable_all_foot').on('change', function(){ /* ... same as before ... */
        const isChecked = $(this).prop('checked'); tableBody.find('tr:not(.dsp-marked-for-removal) input[type="checkbox"][name$="[enabled]"]').prop('checked', isChecked); $('#dsp_enable_all, #dsp_enable_all_foot').not(this).prop('checked', isChecked);
    });
    tableBody.on('change', 'input[type="checkbox"][name$="[enabled]"]', function() { /* ... same as before ... */
        if (!$(this).prop('checked')) { $('#dsp_enable_all, #dsp_enable_all_foot').prop('checked', false); }
    });

    // --- *** NEW: Clear All Deals Action *** ---
    if (clearBtn.length) {
        clearBtn.on('click', function() {
            const confirmMsg = dsp_admin_vars.text.clear_confirm || 'Are you absolutely sure you want to delete ALL stored deals? This cannot be undone.';
            // Show confirmation dialog
            if (window.confirm(confirmMsg)) {
                // User confirmed
                clearStatus.text(dsp_admin_vars.text.clearing || 'Clearing...').removeClass('dsp-clear-success dsp-clear-error').show();
                clearSpinner.addClass('is-active');
                clearBtn.prop('disabled', true);

                $.ajax({
                    url: dsp_admin_vars.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dsp_clear_all_deals',
                        _ajax_nonce: dsp_admin_vars.nonce // Use the same admin nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            clearStatus.text(response.data?.message || dsp_admin_vars.text.clear_success || 'All deals cleared successfully.')
                                .removeClass('dsp-clear-error').addClass('dsp-clear-success');
                            // Optionally fade out success message after a delay
                            setTimeout(() => { clearStatus.fadeOut(); }, 5000);
                        } else {
                            clearStatus.text((dsp_admin_vars.text.error_prefix || 'Error:') + ' ' + (response.data?.message || dsp_admin_vars.text.clear_error || 'Error clearing deals.'))
                                .removeClass('dsp-clear-success').addClass('dsp-clear-error');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error("DSP Clear Deals AJAX Error:", textStatus, errorThrown);
                        clearStatus.text((dsp_admin_vars.text.error_prefix || 'Error:') + ' ' + (dsp_admin_vars.text.error_ajax || 'AJAX Error.'))
                            .removeClass('dsp-clear-success').addClass('dsp-clear-error');
                    },
                    complete: function() {
                        clearSpinner.removeClass('is-active');
                        clearBtn.prop('disabled', false);
                    }
                });
            } else {
                // User cancelled
                clearStatus.text(''); // Clear any previous status
            }
        });
    }

}); // End jQuery Ready