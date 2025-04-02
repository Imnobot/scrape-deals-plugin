jQuery(document).ready(function ($) {
    const container = $('#dsp-deal-display-container');
    if (!container.length) return;

    // --- Selectors ---
    const tableBody = container.find('#dsp-deals-table tbody');
    const searchInput = container.find('#dsp-search-input');
    const newOnlyCheckbox = container.find('#dsp-new-only-checkbox');
    const sourceCheckboxesContainer = container.find('#dsp-source-checkboxes');
    const statusMessage = container.find('#dsp-status-message');
    const lastUpdatedSpan = container.find('#dsp-last-updated');
    const refreshButton = container.find('#dsp-refresh-button');
    const refreshSpinner = container.find('#dsp-refresh-spinner');
    const refreshMessage = container.find('#dsp-refresh-message');
    const toggleLogButton = container.find('#dsp-toggle-debug-log');
    const logContainer = container.find('#dsp-debug-log-container');
    const logPre = container.find('#dsp-debug-log');
    // *** NEW Donate Modal Selectors ***
    const donateButton = container.find('#dsp-donate-button');
    const donateModal = container.find('#dsp-donate-modal');
    const donateModalClose = donateModal.find('.dsp-modal-close');

    // --- Constants & State ---
    const loadingRow = '<tr class="dsp-loading-row"><td colspan="5">' + dsp_ajax_obj.loading_text + '</td></tr>';
    const errorRow = '<tr class="dsp-error-row"><td colspan="5">' + dsp_ajax_obj.error_text + '</td></tr>';
    let allDealsData = [];
    let currentSort = { key: 'first_seen', reverse: true };

    // --- Initialization ---
    function init() {
        createSourceCheckboxes();
        fetchDeals();
        bindEvents();
    }

    // ... createSourceCheckboxes, fetchDeals, showLoading, showError, updateLastUpdated, renderTable, updateDebugLog, applyFiltersAndSort, getSortValue, parsePriceForSort, debounce, __ functions remain the same as the previous full version...

    function createSourceCheckboxes() {
        if (dsp_ajax_obj.config_sources && dsp_ajax_obj.config_sources.length > 0) {
            dsp_ajax_obj.config_sources.forEach(source => {
                const checkboxId = 'dsp-source-' + source.toLowerCase().replace(/[^a-z0-9]/g, ''); // Sanitize ID
                const checkbox = `
                    <label for="${checkboxId}" class="dsp-source-label">
                        <input type="checkbox" id="${checkboxId}" class="dsp-source-filter-cb" value="${source}" checked>
                        ${$('<div>').text(source).html()} ${/* Escape source name for safety */''}
                    </label>
                `;
                sourceCheckboxesContainer.append(checkbox);
            });
        } else {
            sourceCheckboxesContainer.append('<span>No sources configured.</span>');
        }
    }

    function fetchDeals() {
        showLoading();
        $.ajax({
            url: dsp_ajax_obj.ajax_url,
            type: 'POST', data: { action: 'dsp_get_deals', nonce: dsp_ajax_obj.nonce },
            success: function (response) {
                if (response.success && response.data?.deals) {
                    allDealsData = response.data.deals;
                    updateLastUpdated(response.data.last_fetch); applyFiltersAndSort();
                } else { console.error("DSP Error fetching deals:", response); showError(response.data?.message || dsp_ajax_obj.error_text); }
            },
            error: function (jqXHR, textStatus, errorThrown) { console.error("DSP AJAX Error:", textStatus, errorThrown); showError(dsp_ajax_obj.error_text + ' (AJAX)'); }
        });
    }

    function showLoading() { tableBody.html(loadingRow); statusMessage.text(dsp_ajax_obj.loading_text); }
    function showError(message) { tableBody.html(errorRow); statusMessage.text(message || dsp_ajax_obj.error_text); }

    function updateLastUpdated(dateTimeString) {
        if (dateTimeString && dateTimeString !== 'Never' && dateTimeString !== dsp_ajax_obj.never_text) {
            try {
                const date = new Date(dateTimeString.replace(' ', 'T') + 'Z'); // Assume UTC
                if (!isNaN(date)) { lastUpdatedSpan.text(dsp_ajax_obj.last_updated_text + ' ' + date.toLocaleString()); }
                else { lastUpdatedSpan.text(dsp_ajax_obj.last_updated_text + ' ' + dateTimeString + ' (Parse Failed)'); }
            } catch (e) { lastUpdatedSpan.text(dsp_ajax_obj.last_updated_text + ' ' + dateTimeString + ' (Error)'); }
        } else { lastUpdatedSpan.text(dsp_ajax_obj.last_updated_text + ' ' + dsp_ajax_obj.never_text); }
    }

    function renderTable(dealsToDisplay) {
        tableBody.empty();
        if (!dealsToDisplay || dealsToDisplay.length === 0) { tableBody.html('<tr><td colspan="5">' + __('No deals found matching criteria.', 'deal-scraper-plugin') + '</td></tr>'); return; }
        dealsToDisplay.forEach((deal, index) => {
            const row = $('<tr></tr>'); const isNew = deal.is_new || false; const isLifetime = deal.is_lifetime || false;
            row.addClass(index % 2 === 0 ? 'dsp-even-row' : 'dsp-odd-row'); if (isNew) row.addClass('dsp-new-item'); if (isLifetime) row.addClass('dsp-lifetime-item');
            row.append($('<td></td>').text(isNew ? __('Yes', 'deal-scraper-plugin') : __('No', 'deal-scraper-plugin')));
            const titleDescCell = $('<td></td>'); const link = $('<a></a>').attr('href', deal.link).attr('target', '_blank').text(deal.title); titleDescCell.append(link);
            if (deal.description) { const descText = $('<div>').text(' - ' + deal.description).html(); titleDescCell.append($('<span class="dsp-description"></span>').html(descText)); }
            row.append(titleDescCell);
            row.append($('<td></td>').text(deal.price || 'N/A')); row.append($('<td></td>').text(deal.source || 'N/A')); row.append($('<td></td>').text(deal.first_seen_formatted || 'N/A'));
            tableBody.append(row);
        });
    }

    function updateDebugLog(logMessages) {
        if (logMessages && Array.isArray(logMessages)) { const escapedLogs = logMessages.map(msg => $('<div>').text(msg).html()); logPre.html(escapedLogs.join('\n')); }
        else { logPre.text('No debug log available or log format incorrect.'); }
    }

    function applyFiltersAndSort() {
        const searchTerm = searchInput.val().toLowerCase().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = []; container.find('.dsp-source-filter-cb:checked').each(function () { activeSources.push($(this).val()); });
        let filteredData = allDealsData.filter(deal => { if (activeSources.length > 0 && !activeSources.includes(deal.source)) { return false; } if (showNew && !deal.is_new) { return false; } if (searchTerm) { const searchableText = `${deal.title || ''} ${deal.description || ''} ${deal.source || ''}`.toLowerCase(); if (!searchableText.includes(searchTerm)) { return false; } } return true; });
        const sortKey = currentSort.key; const sortReverse = currentSort.reverse;
        filteredData.sort((a, b) => { let valA = getSortValue(a, sortKey); let valB = getSortValue(b, sortKey); const isALifetime = a.is_lifetime || false; const isBLifetime = b.is_lifetime || false; if (isALifetime && !isBLifetime) return -1; if (!isALifetime && isBLifetime) return 1; let comparison = 0; if (typeof valA === 'string' && typeof valB === 'string') { comparison = valA.localeCompare(valB); } else { if (valA < valB) comparison = -1; else if (valA > valB) comparison = 1; } return sortReverse ? (comparison * -1) : comparison; });
        renderTable(filteredData);
        let statusText = `Showing ${filteredData.length} of ${allDealsData.length} deals.`; const filterParts = []; if (searchTerm) filterParts.push(`Search: '${searchInput.val()}'`); if (activeSources.length < dsp_ajax_obj.config_sources.length) filterParts.push('Sources filtered'); if (showNew) filterParts.push('New only'); if (filterParts.length > 0) statusText += ` | Filters: ${filterParts.join(', ')}`; statusMessage.text(statusText);
    }

    function getSortValue(deal, key) { /* ... Same as before ... */
        switch (key) { case 'is_new': return deal.is_new ? 1 : 0; case 'title': return (deal.title || '').toLowerCase(); case 'price': return parsePriceForSort(deal.price || ''); case 'source': return (deal.source || '').toLowerCase(); case 'first_seen': if (deal.first_seen) { try { return Date.parse(deal.first_seen.replace(' ', 'T') + 'Z'); } catch (e) { return 0; } } return 0; default: return ''; }
    }
    function parsePriceForSort(priceStr) { /* ... Same as before ... */
        if (!priceStr) return Infinity; priceStr = String(priceStr).toLowerCase(); if (priceStr.includes('free')) return 0; const match = priceStr.replace(/[,]/g, '').match(/(\d+(\.\d+)?)/); if (match) { return parseFloat(match[1]); } return Infinity;
    }
    function debounce(func, wait) { /* ... Same as before ... */
        let timeout; return function executedFunction(...args) { const later = () => { clearTimeout(timeout); func.apply(this, args); }; clearTimeout(timeout); timeout = setTimeout(later, wait); };
    }
    function __(text, domain) { return text; }


    // --- Event Binding ---
    function bindEvents() {
        // Filters
        searchInput.on('keyup', debounce(applyFiltersAndSort, 300));
        newOnlyCheckbox.on('change', applyFiltersAndSort);
        sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', applyFiltersAndSort);

        // Sorting
        container.find('#dsp-deals-table thead th').on('click', function () {
            const th = $(this); const newSortKey = th.data('sort-key'); if (!newSortKey) return;
            if (currentSort.key === newSortKey) { currentSort.reverse = !currentSort.reverse; } else { currentSort.key = newSortKey; currentSort.reverse = ['first_seen', 'is_new'].includes(newSortKey); }
            container.find('#dsp-deals-table thead th').removeClass('dsp-sort-asc dsp-sort-desc'); th.addClass(currentSort.reverse ? 'dsp-sort-desc' : 'dsp-sort-asc');
            applyFiltersAndSort();
        });

        // Toggle Debug Log Button
        toggleLogButton.on('click', function () {
            if (logContainer.is(':visible')) { logContainer.slideUp(); $(this).text(dsp_ajax_obj.show_log_text); }
            else { logContainer.slideDown(); $(this).text(dsp_ajax_obj.hide_log_text); }
        });

        // Refresh Button
        refreshButton.on('click', function () {
            $(this).prop('disabled', true); refreshSpinner.css('visibility', 'visible'); refreshMessage.text(dsp_ajax_obj.refreshing_text).removeClass('dsp-error dsp-success'); logPre.text('Running refresh...');
            if (!logContainer.is(':visible')) { /* Optional: Auto-show log */ }
            $.ajax({
                url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce },
                success: function (response) {
                    if (response.success && response.data) { refreshMessage.text(response.data.message || __('Refresh finished.', 'deal-scraper-plugin')).removeClass('dsp-error dsp-success').addClass(response.data.message && response.data.message.toLowerCase().includes('error') ? 'dsp-error' : 'dsp-success'); allDealsData = response.data.deals || []; updateLastUpdated(response.data.last_fetch); updateDebugLog(response.data.log); applyFiltersAndSort(); }
                    else { console.error("DSP Refresh Error (Success False/Bad Data):", response); const logData = response.data?.log || ['Refresh failed. Invalid response.']; updateDebugLog(logData); refreshMessage.text(response.data?.message || __('Refresh failed (Invalid Response).', 'deal-scraper-plugin')).addClass('dsp-error'); showError(response.data?.message || __('Refresh failed.', 'deal-scraper-plugin')); }
                },
                error: function (jqXHR, textStatus, errorThrown) {
                    console.error("DSP AJAX Refresh Error:", textStatus, errorThrown, jqXHR.responseJSON); let errorMsg = __('Refresh failed (AJAX Error).', 'deal-scraper-plugin'); let logData = ['AJAX request failed.', `Status: ${textStatus}`, `Error: ${errorThrown}`];
                    if (jqXHR.responseJSON?.data) { errorMsg = jqXHR.responseJSON.data.message || errorMsg; logData = jqXHR.responseJSON.data.log || logData; if (jqXHR.responseText) { logData.push("Raw Response: " + jqXHR.responseText.substring(0, 500)); } }
                    else if (jqXHR.responseText) { logData.push("Raw Response: " + jqXHR.responseText.substring(0, 500)); }
                    updateDebugLog(logData); refreshMessage.text(errorMsg).addClass('dsp-error'); showError(errorMsg);
                },
                complete: function () { refreshButton.prop('disabled', false); refreshSpinner.css('visibility', 'hidden'); const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000; setTimeout(() => { refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay); }
            });
        });

        // *** NEW Donate Modal Event Listeners ***

        // Open modal on Donate button click
        donateButton.on('click', function (e) {
            e.preventDefault(); // Prevent potential form submission if it were inside one
            donateModal.fadeIn(200); // Use fadeIn for smooth appearance
        });

        // Close modal on close button (X) click
        donateModalClose.on('click', function (e) {
            e.preventDefault();
            donateModal.fadeOut(200); // Use fadeOut for smooth disappearance
        });

        // Close modal on background click (clicking outside the content)
        donateModal.on('click', function (e) {
            // Check if the direct click target is the modal background itself
            if ($(e.target).is(donateModal)) {
                donateModal.fadeOut(200);
            }
        });

        // Optional: Close modal on Escape key press
        $(document).on('keydown', function (e) {
            if (e.key === "Escape" && donateModal.is(':visible')) {
                donateModal.fadeOut(200);
            }
        });

    } // End bindEvents

    // --- Run ---
    init();

}); // End jQuery Ready