// File: assets/js/deal-display.js (v1.1.17 - Improve Initial 'No Deals' Message)

jQuery(document).ready(function($) {
    const container = $('#dsp-deal-display-container');
    if (!container.length) return;

    // --- Selectors ---
    const table = container.find('#dsp-deals-table');
    const tableHead = table.find('thead');
    const tableBody = table.find('tbody');
    const searchInput = container.find('#dsp-search-input');
    const newOnlyCheckbox = container.find('#dsp-new-only-checkbox');
    const sourceCheckboxesContainer = container.find('#dsp-source-checkboxes');
    const statusMessage = container.find('#dsp-status-message');
    const lastUpdatedSpan = container.find('#dsp-last-updated');
    const lastUpdatedTimeSpan = container.find('#dsp-last-updated-time');
    const refreshButton = container.find('#dsp-refresh-button');
    const refreshSpinner = container.find('#dsp-refresh-spinner');
    const refreshMessage = container.find('#dsp-refresh-message');
    const toggleLogButton = container.find('#dsp-toggle-debug-log');
    const logContainer = container.find('#dsp-debug-log-container');
    const logPre = container.find('#dsp-debug-log');
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
    const paginationControlsContainer = container.find('#dsp-pagination-controls');
    const updateNoticeContainer = container.find('#dsp-background-update-notice');
    const ltdOnlyCheckbox = container.find('#dsp-ltd-only-checkbox');
    const minPriceInput = container.find('#dsp-min-price-input');
    const maxPriceInput = container.find('#dsp-max-price-input');

    // --- State ---
    let allDealsData = []; // Holds the deals data FOR THE CURRENT PAGE after fetch
    let currentSort = { key: 'first_seen', reverse: true };
    let isRefreshing = false; let isSubscribing = false; let isLoadingPage = false;
    let currentPage = 1; let totalItems = 0; let itemsPerPage = 25;
    let initialLoadComplete = false; // Flag to track if initial JS load/parse is done

    // --- Initialization ---
    function init() {
        itemsPerPage = parseInt(dsp_ajax_obj.items_per_page, 10) || 25;
        totalItems = parseInt(dsp_ajax_obj.total_items, 10) || 0; // Get initial total (might be 0)
        currentPage = 1;
        applyInitialDarkMode();
        createSourceCheckboxes();
        parseInitialTable(); // Parse deals rendered by PHP
        renderPagination(); // Render pagination based on initial totalItems
        bindEvents();
        updateStatusMessage(); // Update status based on initial parse
        if(updateNoticeContainer.length) updateNoticeContainer.hide();
        initialLoadComplete = true; // Mark initial setup as done
        console.log("DSP: Init complete. Event listeners bound."); // Add log to confirm init finishes
    }

    /** Apply dark mode based on settings */
    function applyInitialDarkMode() { const mode = dsp_ajax_obj.dark_mode_default || 'light'; if (mode === 'dark') container.addClass('dsp-dark-mode'); else if (mode === 'auto') applyAutoDarkMode(); else container.removeClass('dsp-dark-mode'); }
    /** Check time for 'auto' dark mode */
    function applyAutoDarkMode() { try { const h = new Date().getHours(); if (h >= 18 || h < 6) container.addClass('dsp-dark-mode'); else container.removeClass('dsp-dark-mode'); } catch (e) { console.error("DSP Auto Dark Mode Error:", e); container.removeClass('dsp-dark-mode'); } }
    /** Creates source filter checkboxes */
    function createSourceCheckboxes() { sourceCheckboxesContainer.empty(); const sources = dsp_ajax_obj.config_sources || []; if (sources.length > 0) { sources.forEach(s => { if (typeof s !== 'string' || s === '') return; const id = 'dsp-source-' + s.toLowerCase().replace(/[^a-z0-9]/g, ''); const esc = $('<div>').text(s).html(); const cb = `<label for="${id}" class="dsp-source-label"><input type="checkbox" id="${id}" class="dsp-source-filter-cb" value="${s}" checked> ${esc}</label>`; sourceCheckboxesContainer.append(cb); }); } else { /* Container remains empty if no sources */ } }

    /** Parses the initial HTML table (Page 1) to populate allDealsData */
    function parseInitialTable() {
        console.log("DSP: Parsing initial table data (Page 1)..."); allDealsData = [];
        tableBody.find('tr.dsp-deal-row').each(function() {
            const row = $(this); const ts = parseInt(row.data('first-seen'), 10) || 0;
            const deal = {
                link: row.data('link') || '#', title: row.data('title') || '', price: row.find('.dsp-cell-price').text() || '', source: row.data('source') || '', description: row.data('description') || '',
                first_seen_ts: ts, first_seen_formatted: row.find('.dsp-cell-date').text().trim() || 'N/A',
                is_new: row.data('is-new') == '1', is_lifetime: row.hasClass('dsp-lifetime-item'), // Get LTD status
             };
            row.attr('data-is-ltd', deal.is_lifetime ? '1' : '0'); // Ensure data attr is set
            if (deal.link !== '#' && deal.title && deal.first_seen_ts > 0) { allDealsData.push(deal); } else { console.warn("DSP: Skipped parsing row:", row, deal); }
        });
        // If PHP rendered loading row and we parsed 0 deals, keep loading row.
        // If PHP rendered no deals row, keep that. If PHP rendered deals, remove loading/no deals row.
        if (allDealsData.length > 0 && tableBody.find('.dsp-loading-row, .dsp-no-deals-row').length > 0) {
             tableBody.find('.dsp-loading-row, .dsp-no-deals-row').remove();
             console.log("DSP: Removed initial loading/no deals row.");
        } else if (allDealsData.length === 0 && tableBody.find('.dsp-loading-row').length > 0){
            console.log("DSP: Keeping initial loading row as no deals parsed.");
        } else if (allDealsData.length === 0 && tableBody.find('.dsp-no-deals-row').length > 0){
             console.log("DSP: Keeping initial 'no deals yet' row.");
        }
        console.log(`DSP: Parsed ${allDealsData.length} initial deals from PHP.`);
    }

    // Removed checkForNewDeals() function

    /** Fetches a specific page of deals via AJAX */
    function fetchDealsPage(pageNum) {
        if (isLoadingPage) { return; } isLoadingPage = true; currentPage = pageNum;
        let currentHeight = tableBody.height(); if (currentHeight < 100) { currentHeight = 100; } tableBody.css('min-height', currentHeight + 'px');
        tableBody.html('<tr class="dsp-loading-row"><td colspan="5">' + __(dsp_ajax_obj.loading_text) + '</td></tr>'); paginationControlsContainer.addClass('dsp-loading'); statusMessage.text(__(dsp_ajax_obj.loading_text));
        if(updateNoticeContainer.length) updateNoticeContainer.slideUp(100);
        const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(); const sortKey = currentSort.key; const sortReverse = currentSort.reverse; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPrice = minPriceInput.val(); const maxPrice = maxPriceInput.val();
        console.log(`DSP: Fetching page ${currentPage}... Sort: ${sortKey} ${sortReverse ? 'DESC' : 'ASC'}, Search: "${searchTerm}", New: ${showNew}, LTD: ${showLtdOnly}, Price: ${minPrice}-${maxPrice}, Sources: ${activeSources.join(',')}`);
        const requestData = { action: 'dsp_get_deals', nonce: dsp_ajax_obj.nonce, page: currentPage, items_per_page: itemsPerPage, orderby: sortKey, order: sortReverse ? 'DESC' : 'ASC', search: searchTerm, sources: activeSources, new_only: showNew ? 1 : 0, ltd_only: showLtdOnly ? 1 : 0, min_price: minPrice, max_price: maxPrice };
        $.ajax({
            url: dsp_ajax_obj.ajax_url, type: 'POST', data: requestData, timeout: 30000,
            success: function(response) {
                console.log("DSP: fetchDealsPage response:", response);
                if (response.success && response.data) {
                    allDealsData = response.data.deals || []; totalItems = parseInt(response.data.total_items, 10) || 0; itemsPerPage = parseInt(response.data.items_per_page, 10) || itemsPerPage; currentPage = parseInt(response.data.current_page, 10) || currentPage;
                    renderTable(allDealsData); renderPagination(); updateLastUpdated(response.data.last_fetch);
                } else {
                    console.error("DSP: Error fetching deals page:", response); tableBody.html('<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + '</td></tr>'); statusMessage.text(response.data?.message || __(dsp_ajax_obj.error_text)).addClass('dsp-error');
                    renderPagination();
                }
                 tableBody.css('min-height', '');
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("DSP: AJAX fetchDealsPage Error:", textStatus, errorThrown); tableBody.html('<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + ' (AJAX)</td></tr>'); statusMessage.text(__(dsp_ajax_obj.error_text) + ' (AJAX)').addClass('dsp-error');
                renderPagination();
                 tableBody.css('min-height', '');
            },
            complete: function() {
                isLoadingPage = false; paginationControlsContainer.removeClass('dsp-loading');
                // Update status message *after* potentially updating totalItems in success/error
                updateStatusMessage();
            }
        });
    }

    /** Updates the 'Last Updated' time display */
    function updateLastUpdated(dateTimeString) { const neverText = __(dsp_ajax_obj.never_text); let displayTime = neverText; if (dateTimeString && dateTimeString !== neverText) { try { displayTime = dateTimeString; } catch (e) { displayTime = dateTimeString + ' (Error)'; } } lastUpdatedTimeSpan.text(displayTime); lastUpdatedSpan.css('visibility', displayTime === neverText ? 'hidden' : 'visible'); }

    /** Creates a single <tr> jQuery element for a deal object */
    function createDealRowElement(deal) { if (!deal || !deal.title || !deal.link || !deal.first_seen_formatted) { console.warn("DSP: Cannot create row for invalid deal:", deal); return $(); } const isNew = deal.is_new || false; const isLifetime = deal.is_lifetime || false; const firstSeenTimestamp = parseInt(deal.first_seen_ts, 10) || 0; const row = $('<tr></tr>').addClass('dsp-deal-row').attr({ 'data-source': deal.source || '', 'data-title': deal.title || '', 'data-description': deal.description || '', 'data-is-new': isNew ? '1' : '0', 'data-is-ltd': isLifetime ? '1' : '0', 'data-first-seen': firstSeenTimestamp, 'data-price': parsePriceForSort(deal.price || ''), 'data-link': deal.link || '#' }); if (isNew) row.addClass('dsp-new-item'); if (isLifetime) row.addClass('dsp-lifetime-item'); row.append($('<td></td>').addClass('dsp-cell-new').text(isNew ? __(dsp_ajax_obj.yes_text) : __(dsp_ajax_obj.no_text))); const titleCell = $('<td></td>').addClass('dsp-cell-title'); const link = $('<a></a>').attr({ href: deal.link, target: '_blank', rel: 'noopener noreferrer' }).text(deal.title); titleCell.append(link); if (isLifetime) titleCell.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>'); if (deal.description) { titleCell.append($('<p></p>').addClass('dsp-description').text(deal.description)); } row.append(titleCell); row.append($('<td></td>').addClass('dsp-cell-price').text(deal.price || 'N/A')); row.append($('<td></td>').addClass('dsp-cell-source').text(deal.source || 'N/A')); row.append($('<td></td>').addClass('dsp-cell-date').attr('data-timestamp', firstSeenTimestamp).text(deal.first_seen_formatted || 'N/A')); return row; }

    /** Renders the table body based on the provided deals array */
    function renderTable(dealsToDisplay) { const noDealsMatchingText = __(dsp_ajax_obj.no_deals_found_text); tableBody.empty(); if (!dealsToDisplay || dealsToDisplay.length === 0) { const noDealsRow = $('<tr></tr>').addClass('dsp-no-deals-row').append( $('<td colspan="5"></td>').text(noDealsMatchingText) ); tableBody.html(noDealsRow); return; } dealsToDisplay.forEach((deal, index) => { const rowElement = createDealRowElement(deal); rowElement.addClass(index % 2 === 0 ? 'dsp-even-row' : 'dsp-odd-row'); tableBody.append(rowElement); }); }
    /** Renders the pagination controls */
    function renderPagination() { paginationControlsContainer.empty(); if (itemsPerPage <= 0 || totalItems <= itemsPerPage) return; const totalPages = Math.ceil(totalItems / itemsPerPage); if (totalPages <= 1) return; let paginationHTML = '<ul class="dsp-page-numbers">'; paginationHTML += `<li class="dsp-page-item ${currentPage === 1 ? 'dsp-disabled' : ''}">`; if (currentPage > 1) { paginationHTML += `<a href="#" class="dsp-page-link dsp-prev" data-page="${currentPage - 1}">« ${__('Previous')}</a>`; } else { paginationHTML += `<span class="dsp-page-link dsp-prev">« ${__('Previous')}</span>`; } paginationHTML += '</li>'; paginationHTML += `<li class="dsp-page-item dsp-current-page"><span class="dsp-page-link">${sprintf(__(dsp_ajax_obj.page_text), currentPage, totalPages)}</span></li>`; paginationHTML += `<li class="dsp-page-item ${currentPage === totalPages ? 'dsp-disabled' : ''}">`; if (currentPage < totalPages) { paginationHTML += `<a href="#" class="dsp-page-link dsp-next" data-page="${currentPage + 1}">${__('Next')} »</a>`; } else { paginationHTML += `<span class="dsp-page-link dsp-next">${__('Next')} »</span>`; } paginationHTML += '</li>'; paginationHTML += '</ul>'; paginationControlsContainer.html(paginationHTML); }
    /** Updates the debug log display */
    function updateDebugLog(logMessages) { const noLogText = __('No debug log available.'); if (!logContainer.length) return; if (logMessages && Array.isArray(logMessages) && logMessages.length > 0) { const escaped = logMessages.map(msg => $('<div>').text(msg).html()); logPre.html(escaped.join('\n')); } else { logPre.text(noLogText); } }

    /** Applies filters/sorting by fetching page 1 */
    function applyFiltersAndSort() { console.log("DSP: Filters/Sort changed, fetching Page 1..."); const sortKey = currentSort.key; const sortReverse = currentSort.reverse; tableHead.find('th').removeClass('dsp-sort-asc dsp-sort-desc'); tableHead.find(`th[data-sort-key="${sortKey}"]`).addClass(sortReverse ? 'dsp-sort-desc' : 'dsp-sort-asc'); fetchDealsPage(1); }

    /** Gets a comparable value for sorting */
    function getSortValue(deal, key) { if (!deal) return ''; switch (key) { case 'is_new': return deal.is_new ? 1 : 0; case 'title': return (deal.title || '').toLowerCase(); case 'price': return parsePriceForSort(deal.price || ''); case 'source': return (deal.source || '').toLowerCase(); case 'first_seen': return parseInt(deal.first_seen_ts, 10) || 0; default: return ''; } }
     /** Parses a price string into a sortable number */
    function parsePriceForSort(priceStr) { const inf = Infinity; if (priceStr === null || typeof priceStr === 'undefined') return inf; priceStr = String(priceStr).toLowerCase().trim(); if (priceStr === '' || priceStr === 'n/a') return inf; if (['free','freebie','0','0.00'].includes(priceStr) || priceStr.startsWith('$0') || priceStr.startsWith('€0') || priceStr.startsWith('£0')) return 0; const clean = priceStr.replace(/[^0-9.-]+/g,""); const match = clean.match(/^-?\d+(\.\d+)?/); if(match){return parseFloat(match[0]);} return inf; }
    /** Debounce utility */
    function debounce(func, wait) { let t; return function(...a){const l=()=>{clearTimeout(t);func.apply(this,a);};clearTimeout(t);t=setTimeout(l,wait);};}

    /** Simple translation placeholder - Improved Fallback */
     function __(text) { if (dsp_ajax_obj && typeof dsp_ajax_obj[text] !== 'undefined') { return dsp_ajax_obj[text]; } const key = text.toLowerCase().replace(/[^a-z0-9]/g, '_') + '_text'; if (dsp_ajax_obj && typeof dsp_ajax_obj[key] !== 'undefined') { return dsp_ajax_obj[key]; } console.warn("DSP Translation missing for:", text); return text; }
     /** Updates the main status message bar */
     function updateStatusMessage() {
         // Don't update if an action is in progress
         if (isLoadingPage || isRefreshing || isSubscribing) return;

         const totalDealCount = totalItems;
         const totalPages = itemsPerPage > 0 ? Math.ceil(totalDealCount / itemsPerPage) : 1;
         let statusText = '';

         // *** MODIFIED: Check totalItems first ***
         if (totalDealCount === 0 && initialLoadComplete) {
              // Use the new localized string for no deals found *yet*
             statusText = __(dsp_ajax_obj.no_deals_yet_text);
         } else if (totalDealCount > 0 && itemsPerPage > 0) {
             const firstItem = (currentPage - 1) * itemsPerPage + 1;
             const lastItem = Math.min(currentPage * itemsPerPage, totalDealCount);
             statusText = sprintf(__('Showing deals %d-%d of %d', 'deal-scraper-plugin'), firstItem, lastItem, totalDealCount);
             if (totalPages > 1) {
                 statusText += ` (${sprintf(__(dsp_ajax_obj.page_text), currentPage, totalPages)})`;
             }
         } else if (totalDealCount > 0 && itemsPerPage <= 0) { // Case for showing all items (itemsPerPage = -1)
             statusText = sprintf(__('Showing %d deals', 'deal-scraper-plugin'), totalDealCount);
         } else {
             // This only hits now if filters result in 0 matches from a non-empty dataset
             statusText = __(dsp_ajax_obj.no_deals_found_text);
         }
         // *** END MODIFICATION ***

         // Get current filter values
         const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(); const totalSources = dsp_ajax_obj.config_sources?.length || 0; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPrice = minPriceInput.val(); const maxPrice = maxPriceInput.val();
         // Append filter info
         const filterParts = []; if (searchTerm) filterParts.push(sprintf('%s: \'%s\'', __('Search'), searchTerm)); if (totalSources > 0 && activeSources.length < totalSources) filterParts.push(__('Sources filtered')); if (showNew) filterParts.push(__('New only')); if (showLtdOnly) filterParts.push(__('LTD Only')); if (minPrice !== '' || maxPrice !== '') { let priceText = __('Price', 'deal-scraper-plugin') + ': '; if (minPrice !== '' && maxPrice !== '') priceText += `$${minPrice}-$${maxPrice}`; else if (minPrice !== '') priceText += `$${minPrice}+`; else if (maxPrice !== '') priceText += `${__('Up to')} $${maxPrice}`; filterParts.push(priceText); }
         // Only add filter text if filters are active AND deals were found (or DB isn't empty)
         if (filterParts.length > 0 && totalDealCount > 0) {
             statusText += ` | ${__('Filters')}: ${filterParts.join(', ')}`;
         }

         statusMessage.text(statusText).removeClass('dsp-error dsp-success');
      }
     /** Basic sprintf implementation */
     function sprintf(format, ...args) { let i = 0; return format.replace(/%[sd]/g, (match) => (match === '%d' ? parseInt(args[i++], 10) : String(args[i++]))); }
    /** Handles submission of the subscription form */
    function handleSubscriptionSubmit(event) { event.preventDefault(); if (isSubscribing || !subscribeModal.length || !subscribeEmailInput.length) return; const email = subscribeEmailInput.val().trim(); const enterEmailText=__(dsp_ajax_obj.subscribe_enter_email); const invalidFormatText=__(dsp_ajax_obj.subscribe_invalid_email_format); if (!email) { subscribeMessage.text(enterEmailText).removeClass('dsp-success').addClass('dsp-error').show(); return; } if (!/^\S+@\S+\.\S+$/.test(email)) { subscribeMessage.text(invalidFormatText).removeClass('dsp-success').addClass('dsp-error').show(); return; } isSubscribing = true; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', true); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'visible'); if(subscribeMessage.length) subscribeMessage.text('').removeClass('dsp-success dsp-error').hide(); $.ajax({ url:dsp_ajax_obj.ajax_url, type:'POST', data:{action:'dsp_subscribe_email',nonce:dsp_ajax_obj.nonce,email:email}, timeout:15000, success:function(response) { if (response.success && response.data?.message) { if(subscribeMessage.length) subscribeMessage.text(response.data.message).removeClass('dsp-error').addClass('dsp-success').show(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); setTimeout(function(){if(subscribeModal.is(':visible'))subscribeModal.fadeOut(200);}, 3000); } else { const errorMsg = response.data?.message || __(dsp_ajax_obj.subscribe_error_generic); if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show(); } }, error:function(jqXHR,textStatus,errorThrown) { console.error("DSP Sub AJAX Error:", textStatus, errorThrown); let errorMsg = __(dsp_ajax_obj.subscribe_error_network); if (jqXHR.responseJSON?.data?.message) errorMsg=jqXHR.responseJSON.data.message; if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show(); }, complete:function() { isSubscribing = false; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', false); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'hidden'); } }); }

    /** Binds all event listeners */
    function bindEvents() {
        searchInput.on('keyup', debounce(applyFiltersAndSort, 300)); newOnlyCheckbox.on('change', applyFiltersAndSort); sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', applyFiltersAndSort);
        ltdOnlyCheckbox.on('change', applyFiltersAndSort); minPriceInput.on('keyup', debounce(applyFiltersAndSort, 400)); maxPriceInput.on('keyup', debounce(applyFiltersAndSort, 400)); minPriceInput.on('change', applyFiltersAndSort); maxPriceInput.on('change', applyFiltersAndSort);
        tableHead.find('th[data-sort-key]').on('click', function () { const th = $(this); const newSortKey = th.data('sort-key'); if(!newSortKey) return; if (currentSort.key === newSortKey) { currentSort.reverse = !currentSort.reverse; } else { currentSort.key = newSortKey; currentSort.reverse = ['first_seen', 'is_new'].includes(newSortKey); } applyFiltersAndSort(); });
        if (toggleLogButton.length && logContainer.length) { toggleLogButton.on('click', function () { const button=$(this); const showTxt=__(dsp_ajax_obj.show_log_text); const hideTxt=__(dsp_ajax_obj.hide_log_text); logContainer.slideToggle(200, function() { button.text(logContainer.is(':visible') ? hideTxt : showTxt); }); }); } else { if(logContainer.length) logContainer.hide(); }
        if (refreshButton.length) { refreshButton.on('click', function () { if (isRefreshing) return; isRefreshing = true; const refreshingText = __(dsp_ajax_obj.refreshing_text); const refreshFinishedText = __(dsp_ajax_obj.refresh_finished_text); const refreshFailedInvalidResp = __(dsp_ajax_obj.error_refresh_invalid_resp_text); const refreshFailedAjax = __(dsp_ajax_obj.error_refresh_ajax_text); const button = $(this); button.prop('disabled', true); if(refreshSpinner.length) refreshSpinner.css('visibility', 'visible'); if(refreshMessage.length) refreshMessage.text(refreshingText).removeClass('dsp-error dsp-success'); if(logPre.length) logPre.text('Running manual refresh...'); statusMessage.text(refreshingText).removeClass('dsp-success dsp-error'); $.ajax({ url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce }, timeout: 180000, success: function (response) { console.log("DSP: Manual refresh response:", response); let message = refreshFailedInvalidResp; let messageType = 'dsp-error'; if (response.success && response.data) { totalItems = parseInt(response.data.total_items, 10) || 0; currentPage = 1; const allDealsFromServer = response.data.deals || []; updateLastUpdated(response.data.last_fetch); if(logPre.length) updateDebugLog(response.data.log); message = response.data.message || refreshFinishedText; messageType = message.toLowerCase().includes('error') || message.toLowerCase().includes('fail') ? 'dsp-error' : 'dsp-success'; allDealsData = allDealsFromServer.slice(0, itemsPerPage); renderTable(allDealsData); renderPagination(); } else { console.error("DSP Refresh Error:", response); const logData = response.data?.log || [message]; if(logPre.length) updateDebugLog(logData); message = response.data?.message || message; statusMessage.text(message).addClass('dsp-error'); renderTable([]); renderPagination(); } if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-error dsp-success').addClass(messageType); }, error: function (jqXHR, textStatus, errorThrown) { console.error("DSP AJAX Refresh Error:", textStatus, errorThrown, jqXHR.responseJSON); let errorMsg = refreshFailedAjax; let logData = [errorMsg, `Status: ${textStatus}`, `Error: ${errorThrown}`]; if (jqXHR.responseJSON?.data) { errorMsg = jqXHR.responseJSON.data.message || errorMsg; logData = jqXHR.responseJSON.data.log || logData; } if (jqXHR.responseText) { logData.push("Raw Response Snippet: " + jqXHR.responseText.substring(0, 500)); } if(logPre.length) updateDebugLog(logData); if(refreshMessage.length) refreshMessage.text(errorMsg).addClass('dsp-error'); statusMessage.text(errorMsg).addClass('dsp-error'); renderTable([]); renderPagination(); }, complete: function () { isRefreshing = false; button.prop('disabled', false); if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden'); if(refreshMessage.length) { const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000; setTimeout(() => { refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay); } updateStatusMessage(); } }); }); }
        if (donateButton.length && donateModal.length) { donateButton.on('click', (e)=>{e.preventDefault(); donateModal.fadeIn(200);}); donateModalClose.on('click', (e)=>{e.preventDefault(); donateModal.fadeOut(200);}); donateModal.on('click', (e)=>{if($(e.target).is(donateModal))donateModal.fadeOut(200);}); $(document).on('keydown', (e)=>{if(e.key==="Escape" && donateModal.is(':visible'))donateModal.fadeOut(200);}); }
        if (subscribeButton.length && subscribeModal.length) { subscribeButton.on('click', (e)=>{e.preventDefault(); if(subscribeMessage.length) subscribeMessage.text('').hide(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); subscribeModal.fadeIn(200); if(subscribeEmailInput.length) subscribeEmailInput.focus(); }); if(subscribeSubmitButton.length) subscribeSubmitButton.on('click', handleSubscriptionSubmit); if(subscribeEmailInput.length) subscribeEmailInput.on('keypress', (e)=>{if(e.which===13)handleSubscriptionSubmit(e);}); if(subscribeModalClose.length) subscribeModalClose.on('click', (e)=>{e.preventDefault(); subscribeModal.fadeOut(200);}); subscribeModal.on('click', (e)=>{if($(e.target).is(subscribeModal))subscribeModal.fadeOut(200);}); $(document).on('keydown', (e)=>{if(e.key==="Escape" && subscribeModal.is(':visible') && !donateModal.is(':visible'))subscribeModal.fadeOut(200);}); }
        paginationControlsContainer.on('click', 'a.dsp-page-link', function(e) { e.preventDefault(); const link = $(this); if (link.parent().hasClass('dsp-disabled') || isLoadingPage) { return; } const pageNum = parseInt(link.data('page'), 10); if (!isNaN(pageNum) && pageNum !== currentPage) { fetchDealsPage(pageNum); } });
        if(updateNoticeContainer.length) { updateNoticeContainer.on('click', '.dsp-dismiss-notice', function(e){ e.preventDefault(); updateNoticeContainer.slideUp(200, function(){ $(this).empty(); }); }); }
    } // End bindEvents

    // --- Run ---
    init(); // Start the process

}); // End jQuery Ready