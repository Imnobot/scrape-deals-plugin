// File: assets/js/deal-display.js (v1.0.5 - Modified to not auto-open log on refresh)

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
    const lastUpdatedTimeSpan = container.find('#dsp-last-updated-time'); // Specific span for time
    const refreshButton = container.find('#dsp-refresh-button'); // Might not exist
    const refreshSpinner = container.find('#dsp-refresh-spinner'); // Might not exist
    const refreshMessage = container.find('#dsp-refresh-message'); // Might not exist
    const toggleLogButton = container.find('#dsp-toggle-debug-log'); // Might not exist
    const logContainer = container.find('#dsp-debug-log-container');
    const logPre = container.find('#dsp-debug-log');
    const donateButton = container.find('#dsp-donate-button');
    const donateModal = container.find('#dsp-donate-modal');
    const donateModalClose = donateModal.find('.dsp-modal-close');
    // --- Subscribe Selectors ---
    const subscribeButton = container.find('#dsp-subscribe-button'); // Might not exist
    const subscribeModal = container.find('#dsp-subscribe-modal'); // Might not exist
    const subscribeModalClose = subscribeModal.find('.dsp-subscribe-modal-close'); // Might not exist
    const subscribeEmailInput = subscribeModal.find('#dsp-subscribe-email-input'); // Might not exist
    const subscribeSubmitButton = subscribeModal.find('#dsp-subscribe-submit-button'); // Might not exist
    const subscribeSpinner = subscribeModal.find('#dsp-subscribe-spinner'); // Might not exist
    const subscribeMessage = subscribeModal.find('#dsp-subscribe-message'); // Might not exist

    // --- State ---
    let allDealsData = [];
    let currentSort = { key: 'first_seen', reverse: true }; // Default sort
    let isRefreshing = false; // Track manual refresh state
    let isSubscribing = false; // Track subscribe state

    // --- Initialization ---
    function init() {
        applyInitialDarkMode(); // Apply dark mode FIRST
        createSourceCheckboxes();
        fetchDeals(); // Original behavior: fetch all deals via AJAX
        bindEvents();
    }

    /**
     * Apply dark mode based on settings passed from PHP.
     */
    function applyInitialDarkMode() {
        const mode = dsp_ajax_obj.dark_mode_default || 'light'; // Default to light
        if (mode === 'dark') { container.addClass('dsp-dark-mode'); }
        else if (mode === 'auto') { applyAutoDarkMode(); } // Check time
        else { container.removeClass('dsp-dark-mode'); } // Ensure light mode
    }

    /**
     * Check time and apply dark mode class if needed for 'auto' mode.
     */
    function applyAutoDarkMode() {
        try {
            const currentHour = new Date().getHours();
            const isNight = currentHour >= 18 || currentHour < 6; // 6 PM to 6 AM
            if (isNight) { container.addClass('dsp-dark-mode'); }
            else { container.removeClass('dsp-dark-mode'); }
        } catch (e) {
             console.error("DSP: Error checking time for auto dark mode:", e);
             container.removeClass('dsp-dark-mode'); // Fallback to light
        }
    }

    /**
     * Creates source filter checkboxes.
     */
    function createSourceCheckboxes() {
        sourceCheckboxesContainer.empty(); // Clear first
        if (dsp_ajax_obj.config_sources && dsp_ajax_obj.config_sources.length > 0) {
            dsp_ajax_obj.config_sources.forEach(source => {
                if (typeof source !== 'string') return;
                const checkboxId = 'dsp-source-' + source.toLowerCase().replace(/[^a-z0-9]/g, '');
                const escapedSource = $('<div>').text(source).html();
                const checkbox = `
                    <label for="${checkboxId}" class="dsp-source-label">
                        <input type="checkbox" id="${checkboxId}" class="dsp-source-filter-cb" value="${source}" checked>
                        ${escapedSource}
                    </label>
                `;
                sourceCheckboxesContainer.append(checkbox);
            });
        } else {
            sourceCheckboxesContainer.append($('<span></span>').text('No sources configured.'));
        }
    }

    /**
     * Fetches ALL deals via AJAX (original v1.0.x behavior).
     */
    function fetchDeals() {
        showLoading(); // Show loading message in table body
        $.ajax({
            url: dsp_ajax_obj.ajax_url,
            type: 'POST',
            data: { action: 'dsp_get_deals', nonce: dsp_ajax_obj.nonce },
            timeout: 60000, // 60 second timeout
            success: function (response) {
                if (response.success && response.data?.deals) {
                    allDealsData = response.data.deals;
                    updateLastUpdated(response.data.last_fetch);
                    applyFiltersAndSort(); // Filter, sort, and render
                } else {
                    console.error("DSP Error fetching deals:", response);
                    showError(response.data?.message || __(dsp_ajax_obj.error_text));
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error("DSP AJAX Error:", textStatus, errorThrown);
                showError(__(dsp_ajax_obj.error_text) + ' (AJAX)');
            },
            complete: function() {
                 updateStatusMessage(); // Update status after load attempt
            }
        });
    }

    /**
     * Displays the "Loading..." row in the table body.
     */
    function showLoading() {
        const loadingText = __(dsp_ajax_obj.loading_text);
        const loadingRowHtml = `<tr class="dsp-loading-row"><td colspan="5">${loadingText}</td></tr>`;
        tableBody.html(loadingRowHtml);
        statusMessage.text(loadingText);
    }

    /**
     * Displays an error message row in the table body.
     */
    function showError(message) {
        const errorText = message || __(dsp_ajax_obj.error_text);
        const errorRowHtml = `<tr class="dsp-error-row"><td colspan="5">${$('<div>').text(errorText).html()}</td></tr>`; // Escape message
        tableBody.html(errorRowHtml);
        statusMessage.text(errorText);
    }

    /**
     * Updates the 'Last Updated' time display.
     */
    function updateLastUpdated(dateTimeString) {
        const neverText = __(dsp_ajax_obj.never_text);
        const updatedTextPrefix = __(dsp_ajax_obj.last_updated_text);
        let displayTime = neverText;

        if (dateTimeString && dateTimeString !== neverText) {
            try {
                const date = new Date(dateTimeString.replace(' ', 'T') + 'Z'); // Assume UTC
                if (!isNaN(date)) { displayTime = date.toLocaleString(); }
                else { displayTime = dateTimeString + ' (Parse Failed)'; }
            } catch (e) { displayTime = dateTimeString + ' (Error)'; }
        }
        lastUpdatedTimeSpan.text(displayTime);
        lastUpdatedSpan.css('visibility', displayTime === neverText ? 'hidden' : 'visible');
    }

    /**
     * Renders the table body based on the provided deals array.
     */
    function renderTable(dealsToDisplay) {
        const noDealsText = __(dsp_ajax_obj.no_deals_found_text);
        tableBody.empty(); // Clear previous rows

        if (!dealsToDisplay || dealsToDisplay.length === 0) {
            tableBody.html(`<tr><td colspan="5">${$('<div>').text(noDealsText).html()}</td></tr>`);
            return;
        }

        dealsToDisplay.forEach((deal, index) => {
            const rowElement = createDealRowElement(deal);
            rowElement.addClass(index % 2 === 0 ? 'dsp-even-row' : 'dsp-odd-row');
            tableBody.append(rowElement);
        });
    }

     /**
     * Creates a single <tr> jQuery element for a deal object. Corrected for Yes/No.
     */
    function createDealRowElement(deal) {
        if (!deal || !deal.title || !deal.link) { return $(); }

        const isNew = deal.is_new || false;
        const isLifetime = deal.is_lifetime || false;
        let firstSeenTimestamp = 0;
        if (deal.first_seen) { try { firstSeenTimestamp = Date.parse(deal.first_seen.replace(' ', 'T') + 'Z') / 1000; if (isNaN(firstSeenTimestamp)) firstSeenTimestamp = 0; } catch(e) { firstSeenTimestamp = 0; } }

        const row = $('<tr></tr>')
            .addClass('dsp-deal-row')
            .attr({
                'data-source': deal.source || '',
                'data-title': deal.title || '',
                'data-description': deal.description || '',
                'data-is-new': isNew ? '1' : '0',
                'data-first-seen': firstSeenTimestamp,
                'data-price': parsePriceForSort(deal.price || '')
             });

        if (isNew) row.addClass('dsp-new-item');
        if (isLifetime) row.addClass('dsp-lifetime-item');

        // --- Cells ---
        row.append($('<td></td>').addClass('dsp-cell-new').text(isNew ? __('Yes') : __('No'))); // Correct Yes/No text

        const titleCell = $('<td></td>').addClass('dsp-cell-title');
        const link = $('<a></a>').attr({ href: deal.link, target: '_blank', rel: 'noopener noreferrer' }).text(deal.title);
        titleCell.append(link);
        if (isLifetime) titleCell.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>');
        if (deal.description) { titleCell.append($('<p></p>').addClass('dsp-description').text(deal.description)); }
        row.append(titleCell);

        row.append($('<td></td>').addClass('dsp-cell-price').text(deal.price || 'N/A'));
        row.append($('<td></td>').addClass('dsp-cell-source').text(deal.source || 'N/A'));
        row.append($('<td></td>').addClass('dsp-cell-date').attr('data-timestamp', firstSeenTimestamp).text(deal.first_seen_formatted || 'N/A'));

        return row;
    }

    /** Updates the debug log display. */
    function updateDebugLog(logMessages) {
        const noLogText = 'No debug log available.';
        if (logContainer.length === 0) return; // Don't proceed if log elements don't exist
        if (logMessages && Array.isArray(logMessages) && logMessages.length > 0) {
            const escapedLogs = logMessages.map(msg => $('<div>').text(msg).html());
            logPre.html(escapedLogs.join('\n'));
        } else {
            logPre.text(noLogText);
        }
    }

    /** Applies current filters and sorting to allDealsData and renders the table. */
    function applyFiltersAndSort() {
        console.log("DSP: Applying filters and sort...");
        const searchTerm = searchInput.val().toLowerCase().trim();
        const showNew = newOnlyCheckbox.is(':checked');
        const activeSources = [];
        container.find('.dsp-source-filter-cb:checked').each(function () { activeSources.push($(this).val()); });

        let filteredData = allDealsData.filter(deal => {
             if (!deal) return false;
            if (activeSources.length > 0 && !activeSources.includes(deal.source)) return false;
            if (showNew && !deal.is_new) return false;
            if (searchTerm) { const text = `${deal.title||''} ${deal.description||''} ${deal.source||''}`.toLowerCase(); if (!text.includes(searchTerm)) return false; }
            return true;
        });

        const sortKey = currentSort.key; const sortReverse = currentSort.reverse;
        container.find('#dsp-deals-table thead th').removeClass('dsp-sort-asc dsp-sort-desc');
        container.find(`#dsp-deals-table thead th[data-sort-key="${sortKey}"]`).addClass(sortReverse ? 'dsp-sort-desc' : 'dsp-sort-asc');

        filteredData.sort((a, b) => {
            if (!a || !b) return 0;
            let valA = getSortValue(a, sortKey); let valB = getSortValue(b, sortKey);
            const isAL = a.is_lifetime||false; const isBL = b.is_lifetime||false;
            if (!(sortKey === 'price' && !sortReverse)) { if (isAL && !isBL) return -1; if (!isAL && isBL) return 1; }
            if ((sortKey === 'first_seen' || sortKey === 'is_new') && sortReverse) { if (a.is_new && !b.is_new) return -1; if (!a.is_new && b.is_new) return 1; }
            let comp = 0;
            if (typeof valA === 'string' && typeof valB === 'string') { comp = valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' }); }
            else { if (valA < valB) comp = -1; else if (valA > valB) comp = 1; }
            return sortReverse ? (comp * -1) : comp;
        });

        renderTable(filteredData);
        updateStatusMessage();
    }

    /** Gets a comparable value for sorting. */
    function getSortValue(deal, key) {
        if (!deal) return '';
        switch (key) {
            case 'is_new': return deal.is_new ? 1 : 0;
            case 'title': return (deal.title || '').toLowerCase();
            case 'price': return parsePriceForSort(deal.price || '');
            case 'source': return (deal.source || '').toLowerCase();
            case 'first_seen': try { return Date.parse(deal.first_seen.replace(' ', 'T') + 'Z'); } catch (e) { return 0; }
            default: return '';
        }
    }

     /** Parses a price string into a sortable number. */
    function parsePriceForSort(priceStr) {
        const inf = Infinity; if (priceStr === null || typeof priceStr === 'undefined') return inf;
        priceStr = String(priceStr).toLowerCase().trim(); if (priceStr === '' || priceStr === 'n/a') return inf;
        if (['free', 'freebie', '0', '0.00'].includes(priceStr) || priceStr.startsWith('$0') || priceStr.startsWith('€0') || priceStr.startsWith('£0')) return 0;
        const clean = priceStr.replace(/[^0-9.-]+/g, ""); const match = clean.match(/^-?\d+(\.\d+)?/);
        if (match) { return parseFloat(match[0]); } return inf;
    }

    /** Debounce utility */
    function debounce(func, wait) { let t; return function(...a){ const l=()=> {clearTimeout(t); func.apply(this,a);}; clearTimeout(t); t=setTimeout(l,wait);}; }

    /** Simple translation placeholder - UPDATED for Yes/No */
    function __(text) {
         if (text === 'Yes') { return dsp_ajax_obj?.yes_text || 'Yes'; }
         if (text === 'No') { return dsp_ajax_obj?.no_text || 'No'; }
         // Generic lookup (can be expanded if more specific keys needed)
         const key = text.toLowerCase().replace(/[^a-z0-9]/g, '_') + '_text';
         return dsp_ajax_obj && dsp_ajax_obj[key] ? dsp_ajax_obj[key] : text;
     }

     /** Updates the main status message bar */
      function updateStatusMessage() {
         if (isRefreshing || isSubscribing) return; // Don't overwrite active messages
         const visibleRowCount = tableBody.find('tr.dsp-deal-row').length;
         const totalDealCount = allDealsData.length;
         let statusText = `Showing ${visibleRowCount} of ${totalDealCount} deals.`;
         const searchTerm = searchInput.val().trim();
         const showNew = newOnlyCheckbox.is(':checked');
         const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get();
         const totalSources = dsp_ajax_obj.config_sources?.length || 0;
         const filterParts = [];
         if (searchTerm) filterParts.push(`Search: '${searchTerm}'`);
         if (totalSources > 0 && activeSources.length < totalSources) filterParts.push('Sources filtered');
         if (showNew) filterParts.push('New only');
         if (filterParts.length > 0) { statusText += ` | Filters: ${filterParts.join(', ')}`; }
         statusMessage.text(statusText).removeClass('dsp-error');
     }

    /** NEW: Handles submission of the subscription form */
    function handleSubscriptionSubmit(event) {
        event.preventDefault();
        // Check if elements exist before proceeding
        if (isSubscribing || !subscribeModal.length || !subscribeEmailInput.length || !subscribeSubmitButton.length || !subscribeMessage.length) return;

        const email = subscribeEmailInput.val().trim();
        const enterEmailText = __(dsp_ajax_obj.subscribe_enter_email);
        const invalidFormatText = __(dsp_ajax_obj.subscribe_invalid_email_format);

        if (!email) { subscribeMessage.text(enterEmailText).removeClass('dsp-success').addClass('dsp-error').show(); return; }
        if (!/^\S+@\S+\.\S+$/.test(email)) { subscribeMessage.text(invalidFormatText).removeClass('dsp-success').addClass('dsp-error').show(); return; }

        isSubscribing = true;
        subscribeSubmitButton.prop('disabled', true);
        if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'visible');
        subscribeMessage.text('').removeClass('dsp-success dsp-error').hide();

        $.ajax({
            url: dsp_ajax_obj.ajax_url, type: 'POST',
            data: { action: 'dsp_subscribe_email', nonce: dsp_ajax_obj.nonce, email: email },
            timeout: 15000,
            success: function(response) {
                if (response.success && response.data?.message) {
                    subscribeMessage.text(response.data.message).removeClass('dsp-error').addClass('dsp-success').show();
                    subscribeEmailInput.val('');
                    setTimeout(function() { if (subscribeModal.is(':visible')) { subscribeModal.fadeOut(200); } }, 3000);
                } else {
                    const errorMsg = response.data?.message || __(dsp_ajax_obj.subscribe_error_generic);
                    subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("DSP Subscribe AJAX Error:", textStatus, errorThrown, jqXHR.responseJSON);
                let errorMsg = __(dsp_ajax_obj.subscribe_error_network);
                if (jqXHR.responseJSON?.data?.message) { errorMsg = jqXHR.responseJSON.data.message; }
                subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show();
            },
            complete: function() {
                isSubscribing = false;
                subscribeSubmitButton.prop('disabled', false);
                 if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'hidden');
            }
        });
    }

    /** Binds all event listeners - UPDATED */
    function bindEvents() {
        // --- Filters ---
        searchInput.on('keyup', debounce(applyFiltersAndSort, 300));
        newOnlyCheckbox.on('change', applyFiltersAndSort);
        sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', applyFiltersAndSort);

        // --- Sorting ---
        container.find('#dsp-deals-table thead th[data-sort-key]').on('click', function () {
            const th = $(this); const newSortKey = th.data('sort-key');
            if(!newSortKey) return; // Ignore if no sort key
            if (currentSort.key === newSortKey) { currentSort.reverse = !currentSort.reverse; }
            else { currentSort.key = newSortKey; currentSort.reverse = ['first_seen', 'is_new'].includes(newSortKey); }
            applyFiltersAndSort();
        });

        // --- Debug Log Toggle (Conditional) ---
        if (toggleLogButton.length) {
            toggleLogButton.on('click', function () {
                 const button = $(this); const showText = __(dsp_ajax_obj.show_log_text); const hideText = __(dsp_ajax_obj.hide_log_text);
                 logContainer.slideToggle(200, function() { button.text(logContainer.is(':visible') ? hideText : showText); });
            });
        } else { if (logContainer.length) logContainer.hide(); }

        // --- Manual Refresh (Conditional) ---
        if (refreshButton.length) {
            refreshButton.on('click', function () {
                if (isRefreshing) return; isRefreshing = true;
                 const refreshingText = __(dsp_ajax_obj.refreshing_text);
                 const refreshFinishedText = __(dsp_ajax_obj.refresh_finished_text);
                 const refreshFailedInvalidResp = __(dsp_ajax_obj.error_refresh_invalid_resp_text);
                 const refreshFailedAjax = __(dsp_ajax_obj.error_refresh_ajax_text);

                const button = $(this); button.prop('disabled', true);
                 if(refreshSpinner.length) refreshSpinner.css('visibility', 'visible');
                 if(refreshMessage.length) refreshMessage.text(refreshingText).removeClass('dsp-error dsp-success');
                 if(logPre.length) logPre.text('Running refresh...'); // Update log content even if hidden
                 statusMessage.text(refreshingText);

                // ***** THE CODE THAT AUTOMATICALLY OPENED THE LOG WAS REMOVED FROM HERE *****

                $.ajax({ // Refresh AJAX call
                    url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce }, timeout: 180000,
                    success: function (response) {
                        console.log("DSP: Manual refresh response:", response);
                        let message = refreshFailedInvalidResp; let messageType = 'dsp-error';
                        if (response.success && response.data) {
                            allDealsData = response.data.deals || []; updateLastUpdated(response.data.last_fetch);
                            if(logPre.length) updateDebugLog(response.data.log); // Update log content
                            message = response.data.message || refreshFinishedText;
                            messageType = message.toLowerCase().includes('error') || message.toLowerCase().includes('fail') ? 'dsp-error' : 'dsp-success';
                            applyFiltersAndSort();
                        } else {
                            console.error("DSP Refresh Error:", response); const logData = response.data?.log || [message];
                             if(logPre.length) updateDebugLog(logData); // Update log content
                            message = response.data?.message || message;
                            showError(message); // Show error in table body
                        }
                        if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-error dsp-success').addClass(messageType);
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                         console.error("DSP AJAX Refresh Error:", textStatus, errorThrown, jqXHR.responseJSON);
                         let errorMsg = refreshFailedAjax; let logData = [errorMsg, `Status: ${textStatus}`, `Error: ${errorThrown}`];
                         if (jqXHR.responseJSON?.data) { errorMsg = jqXHR.responseJSON.data.message || errorMsg; logData = jqXHR.responseJSON.data.log || logData; }
                         if (jqXHR.responseText) { logData.push("Raw Response Snippet: " + jqXHR.responseText.substring(0, 500)); }
                         if(logPre.length) updateDebugLog(logData); // Update log content
                         if(refreshMessage.length) refreshMessage.text(errorMsg).addClass('dsp-error');
                         showError(errorMsg); // Show error in table body
                    },
                    complete: function () {
                        isRefreshing = false; button.prop('disabled', false);
                         if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden');
                         if(refreshMessage.length) { const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000; setTimeout(() => { refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay); }
                         updateStatusMessage(); // Update main status message
                    }
                });
            });
        } // End if refreshButton exists

        // --- Donate Modal ---
        if (donateButton.length && donateModal.length) {
            donateButton.on('click', function (e) { e.preventDefault(); donateModal.fadeIn(200); });
            donateModalClose.on('click', function (e) { e.preventDefault(); donateModal.fadeOut(200); });
            donateModal.on('click', function (e) { if ($(e.target).is(donateModal)) { donateModal.fadeOut(200); } });
            $(document).on('keydown', function (e) { if (e.key === "Escape" && donateModal.is(':visible')) { donateModal.fadeOut(200); } });
        }

        // --- Subscribe Modal (Conditional) ---
        if (subscribeButton.length && subscribeModal.length) {
             // Show modal
            subscribeButton.on('click', function(e) {
                e.preventDefault();
                if(subscribeMessage.length) subscribeMessage.text('').hide();
                if(subscribeEmailInput.length) subscribeEmailInput.val('');
                subscribeModal.fadeIn(200);
                if(subscribeEmailInput.length) subscribeEmailInput.focus();
            });

            // Handle form submission inside modal
            if(subscribeSubmitButton.length) subscribeSubmitButton.on('click', handleSubscriptionSubmit);
            if(subscribeEmailInput.length) subscribeEmailInput.on('keypress', function(e) { if (e.which === 13) { handleSubscriptionSubmit(e); } });

            // Hide modal listeners
            if(subscribeModalClose.length) subscribeModalClose.on('click', function(e) { e.preventDefault(); subscribeModal.fadeOut(200); });
            subscribeModal.on('click', function(e) { if ($(e.target).is(subscribeModal)) { subscribeModal.fadeOut(200); } });
            $(document).on('keydown', function (e) { if (e.key === "Escape" && subscribeModal.is(':visible') && !donateModal.is(':visible')) { subscribeModal.fadeOut(200); } });
        }

    } // End bindEvents

    // --- Run ---
    init(); // Start the process

}); // End jQuery Ready