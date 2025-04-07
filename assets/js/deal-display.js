// File: assets/js/deal-display.js (v1.3.9 - Incorporate Pagination Fix)

jQuery(document).ready(function ($) {
    // console.log("DSP: Document ready. Initializing...");

    const container = $('#dsp-deal-display-container');
    if (!container.length) { return; }

    // --- Determine Layout ---
    const isGridMode = container.find('#dsp-deals-grid-container').length > 0;
    const currentLayout = isGridMode ? 'grid' : 'table';

    // --- Selectors ---
    const dealsContainer = isGridMode ? container.find('#dsp-deals-grid-container') : container.find('#dsp-deals-table tbody');
    if (!dealsContainer.length) { console.error("DSP Error: Deals container not found."); }
    const dealsWrapper = container.find('.dsp-deals-wrapper');
    const tableHead = container.find('#dsp-deals-table thead');
    const searchInput = container.find('#dsp-search-input');
    const newOnlyCheckbox = container.find('#dsp-new-only-checkbox');
    const sourceCheckboxesContainer = container.find('#dsp-source-checkboxes');
    const ltdOnlyCheckbox = container.find('#dsp-ltd-only-checkbox');
    const minPriceInput = container.find('#dsp-min-price-input');
    const maxPriceInput = container.find('#dsp-max-price-input');
    const gridSortSelect = container.find('#dsp-grid-sort-select');
    const statusMessage = container.find('#dsp-status-message');
    const lastUpdatedSpan = container.find('#dsp-last-updated');
    const lastUpdatedTimeSpan = container.find('#dsp-last-updated-time');
    const refreshButton = container.find('#dsp-refresh-button');
    const refreshSpinner = container.find('#dsp-refresh-spinner');
    const refreshMessage = container.find('#dsp-refresh-message');
    const toggleLogButton = container.find('#dsp-toggle-debug-log');
    const logContainer = container.find('#dsp-debug-log-container');
    const logPre = container.find('#dsp-debug-log');
    // Modal Selectors
    const donateButton = container.find('#dsp-donate-button');
    const donateModal = container.find('#dsp-donate-modal');
    const donateModalClose = donateModal.find('.dsp-modal-close');
    const subscribeButton = container.find('#dsp-subscribe-button');
    const subscribeModal = container.find('#dsp-subscribe-modal');
    const subscribeModalClose = subscribeModal.find('.dsp-subscribe-modal-close');
    const subscribeEmailInput = subscribeModal.find('#dsp-subscribe-email-input');
    const subscribeSubmitButton = subscribeModal.find('#dsp-subscribe-submit-button');
    const subscribeSpinner = subscribeModal.find('#dsp-subscribe-spinner');
    const subscribeMessage = subscribeModal.find('#dsp-subscribe-message');
    // Pagination Selectors
    const paginationControls = container.find('#dsp-pagination-controls');
    const prevPageButton = container.find('#dsp-prev-page');
    const nextPageButton = container.find('#dsp-next-page');
    const pageIndicator = container.find('#dsp-page-indicator');

    // --- State ---
    let currentSort = { key: 'first_seen', reverse: true };
    let isRefreshing = false;
    let isSubscribing = false;
    let isLoadingDeals = false;
    const itemsPerPage = dsp_ajax_obj.items_per_page || 30;
    let currentPage = 1;
    let totalPages = 1;
    let totalItems = 0;

    // --- Initialization ---
    function init() { try { applyInitialDarkMode(); createSourceCheckboxes(); fetchAndRenderDeals(1); bindEvents(); if (isGridMode && gridSortSelect.length) { const initialSortValue = `${currentSort.key}|${currentSort.reverse ? 'desc' : 'asc'}`; gridSortSelect.val(initialSortValue); } } catch (e) { console.error("DSP Error during init():", e); showError("Initialization Error. Check console."); } }

    // --- Core Functions ---
    function applyInitialDarkMode() { try { const mode = dsp_ajax_obj.dark_mode_default || 'light'; if (mode === 'dark') { container.addClass('dsp-dark-mode'); } else if (mode === 'auto') { applyAutoDarkMode(); } else { container.removeClass('dsp-dark-mode'); } } catch(e){ console.error("DSP Error applying dark mode:", e); } }
    function applyAutoDarkMode() { try { const currentHour = new Date().getHours(); const isNight = currentHour >= 18 || currentHour < 6; if (isNight) { container.addClass('dsp-dark-mode'); } else { container.removeClass('dsp-dark-mode'); } } catch (e) { console.error("DSP: Error checking time for auto dark mode:", e); container.removeClass('dsp-dark-mode'); } }
    function createSourceCheckboxes() { try { sourceCheckboxesContainer.empty(); if (dsp_ajax_obj.config_sources && dsp_ajax_obj.config_sources.length > 0) { dsp_ajax_obj.config_sources.forEach(source => { if (typeof source !== 'string') return; const checkboxId = 'dsp-source-' + source.toLowerCase().replace(/[^a-z0-9]/g, ''); const escapedSource = $('<div>').text(source).html(); const checkbox = `<label for="${checkboxId}" class="dsp-source-label"><input type="checkbox" id="${checkboxId}" class="dsp-source-filter-cb" value="${source}" checked> ${escapedSource}</label>`; sourceCheckboxesContainer.append(checkbox); }); } else { sourceCheckboxesContainer.append($('<span></span>').text('No sources configured.')); } } catch(e){ console.error("DSP Error creating source checkboxes:", e); } }

    function fetchAndRenderDeals(pageNum, isRefresh = false) {
        if (isLoadingDeals) { return; } // Prevent stacking requests
        isLoadingDeals = true; // Set flag immediately
        // Update currentPage state ONLY when a fetch starts
        currentPage = pageNum;

        // Show loading state (overlay)
        if (dealsWrapper.length) dealsWrapper.addClass('is-loading');
        if (!isRefresh && statusMessage.length) statusMessage.text(__(dsp_ajax_obj.loading_text));
        if (paginationControls.length) paginationControls.hide();

        const filters = { search: searchInput.val().trim(), sources: container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(), is_new_only: newOnlyCheckbox.is(':checked') ? 'true' : 'false' };
        const sort = { orderby: currentSort.key, order: currentSort.reverse ? 'DESC' : 'ASC' };

        $.ajax({
            url: dsp_ajax_obj.ajax_url, type: 'POST',
            data: {
                action: 'dsp_get_deals', nonce: dsp_ajax_obj.nonce,
                page: currentPage, per_page: itemsPerPage, // Send the correct page number
                orderby: sort.orderby, order: sort.order,
                search: filters.search, sources: filters.sources,
                is_new_only: filters.is_new_only
            },
            timeout: 60000, dataType: 'json',
            success: function (response) {
                try {
                    if (response?.success && response?.data) {
                        const dealsReceived = Array.isArray(response.data.deals) ? response.data.deals : [];
                        totalItems = response.data.total_items ? parseInt(response.data.total_items, 10) : 0;
                        totalPages = response.data.total_pages ? parseInt(response.data.total_pages, 10) : 1;
                        if (totalPages < 1) totalPages = 1;
                        // Update currentPage state based on server response (though it should match what we sent)
                        currentPage = response.data.current_page ? parseInt(response.data.current_page, 10) : 1;
                        let dealsToRender = applyClientSideFilters(dealsReceived);
                        renderDeals(dealsToRender);
                        updateLastUpdated(response.data.last_fetch);
                        updatePaginationControls(); // Update based on received totals/page
                    } else {
                        console.error("DSP Error fetching deals page:", response?.data?.message); showError(response?.data?.message || __(dsp_ajax_obj.error_text));
                        totalItems = 0; totalPages = 1; currentPage = 1;
                        updatePaginationControls();
                    }
                } catch (e) {
                    console.error("DSP Error processing fetch success:", e); showError("Error processing deal data.");
                    totalItems = 0; totalPages = 1; currentPage = 1;
                    updatePaginationControls();
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error(`DSP AJAX Error fetching page ${pageNum}:`, textStatus, errorThrown); showError(__(dsp_ajax_obj.error_text) + ' (AJAX)');
                totalItems = 0; totalPages = 1; currentPage = 1;
                updatePaginationControls();
            },
            complete: function() {
                isLoadingDeals = false; // Reset flag here
                if (dealsWrapper.length) dealsWrapper.removeClass('is-loading'); // Hide overlay
                updateStatusMessage();
            }
        });
    }

    function applyClientSideFilters(dealsFromServer) { if (!Array.isArray(dealsFromServer)) return []; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPriceStr = minPriceInput.val().trim(); const maxPriceStr = maxPriceInput.val().trim(); const minPrice = (minPriceStr !== '' && !isNaN(parseFloat(minPriceStr))) ? parseFloat(minPriceStr) : -Infinity; const maxPrice = (maxPriceStr !== '' && !isNaN(parseFloat(maxPriceStr))) ? parseFloat(maxPriceStr) : Infinity; if (!showLtdOnly && minPrice === -Infinity && maxPrice === Infinity) { return dealsFromServer; } return dealsFromServer.filter(deal => { if (!deal) return false; if (showLtdOnly && !deal.is_lifetime) return false; const dealPrice = parsePriceForSort(deal.price || ''); if (dealPrice < minPrice) return false; if (dealPrice > maxPrice) return false; return true; }); }
    function showLoading() { const loadingText = __(dsp_ajax_obj.loading_text); if(dealsWrapper.length) dealsWrapper.addClass('is-loading'); if(statusMessage.length) statusMessage.text(loadingText); if(paginationControls.length) paginationControls.hide(); }
    function showError(message) { const errorText = message || __(dsp_ajax_obj.error_text); let errorHtml; if (currentLayout === 'grid') { errorHtml = `<div class="dsp-error-message">${$('<div>').text(errorText).html()}</div>`; } else { errorHtml = `<tr class="dsp-error-row"><td colspan="5">${$('<div>').text(errorText).html()}</td></tr>`; } if(dealsContainer.length) dealsContainer.html(errorHtml); if(statusMessage.length) statusMessage.text(errorText); if(paginationControls.length) paginationControls.hide(); if (dealsWrapper.length) dealsWrapper.removeClass('is-loading'); }
    function updateLastUpdated(dateTimeString) { try { const neverText = __(dsp_ajax_obj.never_text); const updatedTextPrefix = __(dsp_ajax_obj.last_updated_text); let displayTime = neverText; if (dateTimeString && dateTimeString !== neverText) { try { const date = new Date(dateTimeString.replace(' ', 'T') + 'Z'); if (!isNaN(date)) { displayTime = date.toLocaleString(); } else { displayTime = dateTimeString + ' (Parse Failed)'; } } catch (e) { displayTime = dateTimeString + ' (Error)'; } } if(lastUpdatedTimeSpan.length) lastUpdatedTimeSpan.text(displayTime); if(lastUpdatedSpan.length) lastUpdatedSpan.css('visibility', displayTime === neverText ? 'hidden' : 'visible'); } catch(e){ console.error("DSP Error updating last updated time:", e); } }
    function renderDeals(dealsToRender) { if (!dealsContainer.length) return; dealsContainer.empty(); if (!dealsToRender || dealsToRender.length === 0) { const noDealsText = __(dsp_ajax_obj.no_deals_found_text); let noDealsHtml; if (currentLayout === 'grid') { noDealsHtml = `<div class="dsp-no-deals">${$('<div>').text(noDealsText).html()}</div>`; } else { noDealsHtml = `<tr><td colspan="5">${$('<div>').text(noDealsText).html()}</td></tr>`; } dealsContainer.html(noDealsHtml); } else { try { dealsToRender.forEach((deal) => { const dealElement = createDealElement(deal); if (dealElement && dealElement.length) { dealsContainer.append(dealElement); } else { console.warn("DSP: Failed to create element for deal:", deal); } }); } catch (e) { console.error("DSP Error during renderDeals loop:", e); showError("Error displaying deals. Check console."); } } }
    function createDealElement(deal) { try { if (!deal || !deal.title || !deal.link) { return $(); } const isNew = deal.is_new === true || deal.is_new === '1' || deal.is_new === 1; const isLifetime = deal.is_lifetime === true || deal.is_lifetime === '1' || deal.is_lifetime === 1; let firstSeenTimestamp = 0; if (deal.first_seen) { try { firstSeenTimestamp = Date.parse(deal.first_seen.replace(' ', 'T') + 'Z'); if (isNaN(firstSeenTimestamp)) firstSeenTimestamp = 0; } catch(e) { firstSeenTimestamp = 0; } } const dataAttrs = { 'data-source': deal.source || '', 'data-title': deal.title || '', 'data-description': deal.description || '', 'data-is-new': isNew ? '1' : '0', 'data-is-lifetime': isLifetime ? '1' : '0', 'data-first-seen': firstSeenTimestamp / 1000, 'data-price': parsePriceForSort(deal.price || '') }; const yesText = __(dsp_ajax_obj.yes_text) || 'Yes'; const noText = __(dsp_ajax_obj.no_text) || 'No'; if (currentLayout === 'grid') { const card = $('<div></div>').addClass('dsp-deal-card').attr(dataAttrs); if (isNew) card.addClass('dsp-new-item'); if (isLifetime) card.addClass('dsp-lifetime-item'); const titleLink = $('<a></a>').attr({ href: deal.link, target: '_blank', rel: 'noopener noreferrer' }).text(deal.title); const titleElement = $('<h3></h3>').addClass('dsp-card-title').append(titleLink); if (isLifetime) titleElement.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>'); const priceElement = $('<p></p>').addClass('dsp-card-price').text(deal.price || 'N/A'); const sourceElement = $('<p></p>').addClass('dsp-card-source').text(`Source: ${deal.source || 'N/A'}`); const dateElement = $('<p></p>').addClass('dsp-card-date').text(`Seen: ${deal.first_seen_formatted || 'N/A'}`); const newElement = $('<p></p>').addClass('dsp-card-new').text(`New: ${isNew ? yesText : noText}`); const descriptionElement = deal.description ? $('<p></p>').addClass('dsp-card-description').text(deal.description) : $(); card.append(titleElement, priceElement, descriptionElement, sourceElement, dateElement, newElement); return card; } else { const row = $('<tr></tr>').addClass('dsp-deal-row').attr(dataAttrs); if (isNew) row.addClass('dsp-new-item'); if (isLifetime) row.addClass('dsp-lifetime-item'); row.append($('<td></td>').addClass('dsp-cell-new').text(isNew ? yesText : noText)); const titleCell = $('<td></td>').addClass('dsp-cell-title'); const link = $('<a></a>').attr({ href: deal.link, target: '_blank', rel: 'noopener noreferrer' }).text(deal.title); titleCell.append(link); if (isLifetime) titleCell.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>'); if (deal.description) titleCell.append($('<p></p>').addClass('dsp-description').text(deal.description)); row.append(titleCell); row.append($('<td></td>').addClass('dsp-cell-price').text(deal.price || 'N/A')); row.append($('<td></td>').addClass('dsp-cell-source').text(deal.source || 'N/A')); row.append($('<td></td>').addClass('dsp-cell-date').attr('data-timestamp', firstSeenTimestamp).text(deal.first_seen_formatted || 'N/A')); return row; } } catch(e) { console.error("DSP Error in createDealElement:", e, "Deal:", deal); return $(); } }
    function updateDebugLog(logMessages) { try { const noLogText = 'No debug log available.'; if (logContainer.length === 0) return; if (logMessages && Array.isArray(logMessages) && logMessages.length > 0) { const escapedLogs = logMessages.map(msg => $('<div>').text(msg).html()); logPre.html(escapedLogs.join('\n')); } else { logPre.text(noLogText); } } catch(e) { console.error("DSP Error updating debug log:", e); } }
    function updateDealsDisplay() { fetchAndRenderDeals(1); } // Fetch page 1 for filter/sort changes
    // Removed displayCurrentPage() as it's not needed with the corrected pagination handlers

    function updatePaginationControls() { try { if (!paginationControls.length || !pageIndicator.length || !prevPageButton.length || !nextPageButton.length) { return; } if (totalPages <= 1) { paginationControls.hide(); } else { paginationControls.show(); pageIndicator.text(`Page ${currentPage} of ${totalPages}`); prevPageButton.prop('disabled', currentPage <= 1); nextPageButton.prop('disabled', currentPage >= totalPages); } } catch (e) { console.error("DSP Error during updatePaginationControls:", e); } }

    // --- Utility Functions ---
    function getSortValue(deal, key) { try { if (!deal) return ''; switch (key) { case 'is_new': return deal.is_new ? 1 : 0; case 'title': return (deal.title || '').toLowerCase(); case 'price': return parsePriceForSort(deal.price || ''); case 'source': return (deal.source || '').toLowerCase(); case 'first_seen': try { const ts = Date.parse(deal.first_seen.replace(' ', 'T') + 'Z'); return isNaN(ts) ? 0 : ts; } catch (e) { return 0; } default: return ''; } } catch(e){ console.error("DSP Error in getSortValue:", e); return ''; } }
    function parsePriceForSort(priceStr) { try { const inf = Infinity; if (priceStr === null || typeof priceStr === 'undefined') return inf; priceStr = String(priceStr).toLowerCase().trim(); if (priceStr === '' || priceStr === 'n/a') return inf; if (['free', 'freebie', '0', '0.00'].includes(priceStr) || priceStr.startsWith('$0') || priceStr.startsWith('€0') || priceStr.startsWith('£0')) return 0; const clean = priceStr.replace(/[^0-9.-]+/g, ""); const match = clean.match(/^-?\d+(\.\d+)?/); if (match) { return parseFloat(match[0]); } return inf; } catch(e){ console.error("DSP Error in parsePriceForSort:", e); return Infinity; } }
    function debounce(func, wait) { let t; return function(...a){ const l=()=> {clearTimeout(t); func.apply(this,a);}; clearTimeout(t); t=setTimeout(l,wait);}; }
    function __(text) { try { if (!dsp_ajax_obj) return text; if (text === 'Yes') return dsp_ajax_obj.yes_text || 'Yes'; if (text === 'No') return dsp_ajax_obj.no_text || 'No'; const key = text.toLowerCase().replace(/[^a-z0-9]/g, '_') + '_text'; return dsp_ajax_obj[key] || text; } catch(e){ console.error("DSP Error in __ translation helper:", e); return text; } }
    function updateStatusMessage() { try { if (isLoadingDeals || isRefreshing || isSubscribing) { return; } let statusText = ''; const startItem = totalItems > 0 ? (currentPage - 1) * itemsPerPage + 1 : 0; const endItem = totalItems > 0 ? Math.min(currentPage * itemsPerPage, totalItems) : 0; if (totalItems > 0) { statusText = `Showing ${startItem}-${endItem} of ${totalItems} deals.`; } else { statusText = `Showing 0 of 0 deals.`; } const filterParts = []; const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPriceStr = minPriceInput.val().trim(); const maxPriceStr = maxPriceInput.val().trim(); const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(); const totalSources = dsp_ajax_obj.config_sources?.length || 0; if (searchTerm) filterParts.push(`Search: '${searchTerm}'`); if (totalSources > 0 && activeSources.length < totalSources) filterParts.push('Sources filtered'); if (showNew) filterParts.push('New only'); if (showLtdOnly) filterParts.push('LTD only'); if (minPriceStr !== '' || maxPriceStr !== '') { let pricePart = 'Price: '; if (minPriceStr !== '') pricePart += minPriceStr; pricePart += '-'; if (maxPriceStr !== '') pricePart += maxPriceStr; filterParts.push(pricePart); } if (filterParts.length > 0) { statusText += ` | Filters: ${filterParts.join(', ')}`; } if(statusMessage.length) statusMessage.text(statusText).removeClass('dsp-error'); } catch(e) { console.error("DSP Error updating status message:", e); } }
    function handleSubscriptionSubmit() { if (isSubscribing || !subscribeEmailInput || !subscribeSubmitButton) return; const email = subscribeEmailInput.val().trim(); if (!email) { subscribeMessage.text(__(dsp_ajax_obj.subscribe_enter_email)).removeClass('dsp-success').addClass('dsp-error'); return; } const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/; if (!emailRegex.test(email)) { subscribeMessage.text(__(dsp_ajax_obj.subscribe_invalid_email_format)).removeClass('dsp-success').addClass('dsp-error'); return; } isSubscribing = true; subscribeSubmitButton.prop('disabled', true); subscribeSpinner.css('visibility', 'visible'); subscribeMessage.text('').removeClass('dsp-success dsp-error'); $.ajax({ url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_subscribe_email', nonce: dsp_ajax_obj.nonce, email: email }, dataType: 'json', success: function(response) { if (response?.success && response?.data?.message) { subscribeMessage.text(response.data.message).removeClass('dsp-error').addClass('dsp-success'); subscribeEmailInput.val(''); setTimeout(() => { if (subscribeModal && subscribeModal.is(':visible')) { subscribeModal.fadeOut(200); } }, 3000); } else { const errorMsg = response?.data?.message || __(dsp_ajax_obj.subscribe_error_generic); subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error'); } }, error: function(jqXHR, textStatus, errorThrown) { console.error("DSP Subscription AJAX Error:", textStatus, errorThrown); subscribeMessage.text(__(dsp_ajax_obj.subscribe_error_network)).removeClass('dsp-success').addClass('dsp-error'); }, complete: function() { isSubscribing = false; subscribeSubmitButton.prop('disabled', false); subscribeSpinner.css('visibility', 'hidden'); } }); }

    /**
     * Binds all event listeners.
     */
    function bindEvents() {
        try {
            // --- Filters ---
            if(searchInput.length) searchInput.on('keyup', debounce(updateDealsDisplay, 300));
            if(newOnlyCheckbox.length) newOnlyCheckbox.on('change', updateDealsDisplay);
            if(sourceCheckboxesContainer.length) sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', updateDealsDisplay);
            if(ltdOnlyCheckbox.length) ltdOnlyCheckbox.on('change', updateDealsDisplay);
            if(minPriceInput.length) minPriceInput.on('keyup input', debounce(updateDealsDisplay, 400));
            if(maxPriceInput.length) maxPriceInput.on('keyup input', debounce(updateDealsDisplay, 400));

            // --- Sorting ---
             if (currentLayout === 'table' && tableHead.length) { tableHead.find('th[data-sort-key]').on('click', function () { try { const th = $(this); const newSortKey = th.data('sort-key'); if (!newSortKey || isLoadingDeals) return; if (currentSort.key === newSortKey) { currentSort.reverse = !currentSort.reverse; } else { currentSort.key = newSortKey; currentSort.reverse = ['first_seen', 'is_new'].includes(newSortKey); } tableHead.find('th').removeClass('dsp-sort-asc dsp-sort-desc'); th.addClass(currentSort.reverse ? 'dsp-sort-desc' : 'dsp-sort-asc'); updateDealsDisplay(); } catch(e) { console.error("DSP Error handling table sort click:", e); } }); }
             else if (isGridMode && gridSortSelect.length) { gridSortSelect.on('change', function() { try { if (isLoadingDeals) return; const selectedValue = $(this).val(); const parts = selectedValue.split('|'); if (parts.length === 2) { currentSort.key = parts[0]; currentSort.reverse = (parts[1] === 'desc'); updateDealsDisplay(); } } catch(e) { console.error("DSP Error handling grid sort change:", e); } }); }


            // --- Pagination Controls ---
            // *** CORRECTED PAGINATION HANDLERS from previous step ***
            if (prevPageButton.length) {
                prevPageButton.on('click', function() {
                    try {
                        if (currentPage > 1 && !isLoadingDeals) {
                            // Pass the TARGET page number directly
                            fetchAndRenderDeals(currentPage - 1);
                        }
                    } catch(e) { console.error("DSP Error handling prev page click:", e); }
                });
            }
             if (nextPageButton.length) {
                 nextPageButton.on('click', function() {
                     try {
                         if (currentPage < totalPages && !isLoadingDeals) {
                            // Pass the TARGET page number directly
                             fetchAndRenderDeals(currentPage + 1);
                         }
                     } catch(e) { console.error("DSP Error handling next page click:", e); }
                 });
             }
             // *** END CORRECTIONS ***


            // --- Other Buttons/Modals ---
            if (toggleLogButton.length && logContainer.length) { toggleLogButton.on('click', function () { try { const button = $(this); const showText = __(dsp_ajax_obj.show_log_text); const hideText = __(dsp_ajax_obj.hide_log_text); logContainer.slideToggle(200, function() { button.text(logContainer.is(':visible') ? hideText : showText); }); } catch(e) { console.error("DSP Error handling toggle log click:", e); } }); } else { console.warn("DSP: Debug log button or container not found for binding."); }

             // Refresh Button (Keep the working version that fixed the spinner)
            if (refreshButton.length) {
                refreshButton.on('click', function () {
                    try {
                        if (isRefreshing || isLoadingDeals) return;
                        isRefreshing = true;

                        const button = $(this);
                        const refreshingText = __(dsp_ajax_obj.refreshing_text);
                        button.prop('disabled', true);
                        if(refreshSpinner.length) refreshSpinner.css('visibility', 'visible');
                        if(refreshMessage.length) refreshMessage.text(refreshingText).removeClass('dsp-error dsp-success');
                        if(logPre.length) logPre.text('Running refresh...');

                        $.ajax({
                            url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce }, timeout: 180000, dataType: 'json',
                            success: function (response) {
                                let message = ''; let messageType = '';
                                try {
                                    if (response?.success && response?.data) {
                                        updateLastUpdated(response.data.last_fetch);
                                        if(logPre.length) updateDebugLog(response.data.log);
                                        message = response.data.message || __(dsp_ajax_obj.refresh_finished_text);
                                        messageType = (response.data.message && (response.data.message.toLowerCase().includes('error') || response.data.message.toLowerCase().includes('fail'))) ? 'dsp-error' : 'dsp-success';
                                        fetchAndRenderDeals(1, true); // Trigger reload after refresh
                                    } else {
                                        console.error("DSP Refresh Success Error:", response); message = response?.data?.message || __(dsp_ajax_obj.error_refresh_invalid_resp_text); messageType = 'dsp-error'; showError(message);
                                    }
                                } catch (e) { console.error("DSP Error processing refresh success:", e); message = "Error processing refresh response."; messageType = 'dsp-error'; showError(message + " Check console."); }
                                finally { if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-error dsp-success').addClass(messageType); }
                            },
                            error: function (jqXHR, textStatus, errorThrown) {
                                console.error("DSP Refresh AJAX Error:", textStatus, errorThrown); const errorMsg = __(dsp_ajax_obj.error_refresh_ajax_text); showError(errorMsg); if(refreshMessage.length) refreshMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error');
                            },
                            complete: function (jqXHR, textStatus) {
                                isRefreshing = false;
                                if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden');
                                if(refreshButton.length) refreshButton.prop('disabled', false);
                                if(refreshMessage.length && refreshMessage.text() !== refreshingText && refreshMessage.text() !== '') { const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000; setTimeout(() => { if(refreshMessage.length) refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay); }
                                updateStatusMessage();
                            }
                        });
                    } catch(e) { console.error("DSP Error handling refresh click:", e); isRefreshing = false; if(refreshButton.length) refreshButton.prop('disabled', false); if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden'); }
                });
            }

            // Modal Bindings (Donate, Subscribe, Escape Key)
            if (donateButton.length && donateModal.length && donateModalClose.length) { donateButton.on('click', function (e) { e.preventDefault(); donateModal.fadeIn(200); }); donateModalClose.on('click', function (e) { e.preventDefault(); donateModal.fadeOut(200); }); donateModal.on('click', function (e) { if ($(e.target).is(donateModal)) { donateModal.fadeOut(200); } }); } else { console.warn("DSP: Donate button/modal/close not found."); }
            if (subscribeButton.length && subscribeModal.length && subscribeModalClose.length) { subscribeButton.on('click', function (e) { e.preventDefault(); subscribeMessage.text('').removeClass('dsp-success dsp-error'); subscribeEmailInput.val(''); subscribeModal.fadeIn(200); }); subscribeModalClose.on('click', function (e) { e.preventDefault(); subscribeModal.fadeOut(200); }); subscribeModal.on('click', function (e) { if ($(e.target).is(subscribeModal)) { subscribeModal.fadeOut(200); } }); if (subscribeSubmitButton.length && subscribeEmailInput.length) { subscribeSubmitButton.on('click', handleSubscriptionSubmit); subscribeEmailInput.on('keypress', function(e) { if (e.which === 13) { e.preventDefault(); handleSubscriptionSubmit(); } }); } } else { console.warn("DSP: Subscribe button/modal/close not found."); }
            $(document).on('keydown', function(e) { if (e.key === "Escape") { if (donateModal.is(':visible')) { donateModal.fadeOut(200); } if (subscribeModal.is(':visible')) { subscribeModal.fadeOut(200); } } });

        } catch (e) { console.error("DSP Error during bindEvents:", e); showError("Error setting up interactions. Check console."); }
    } // End bindEvents

    // --- Run ---
    init();

}); // End jQuery Ready