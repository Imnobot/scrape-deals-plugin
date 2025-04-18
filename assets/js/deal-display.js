// File: assets/js/deal-display.js (v1.1.35 - Fix Pagination Init)

jQuery(document).ready(function($) {
    const container = $('#dsp-deal-display-container');
    if (!container.length) return;

    // --- Selectors ---
    const contentArea = container.find('#dsp-deals-content');
    const tableWrapper = contentArea.find('.dsp-table-wrapper');
    const table = tableWrapper.find('#dsp-deals-table');
    const tableHead = table.find('thead');
    const tableBody = table.find('tbody');
    const gridContainer = contentArea.find('#dsp-deals-grid');

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
    let isRefreshing = false;
    let isSubscribing = false;
    let isLoadingPage = false;
    let currentPage = 1;
    let totalItems = 0; // Initialize to 0, will be set by first fetch
    let itemsPerPage = 25;
    let initialLoadComplete = false;
    let applyFiltersDebounced;
    const currentView = dsp_ajax_obj.view_mode || 'table';

    // --- Initialization ---
    function init() {
        itemsPerPage = parseInt(dsp_ajax_obj.items_per_page, 10) || 25;
        // totalItems = parseInt(dsp_ajax_obj.total_items, 10) || 0; // Don't rely on localized total
        currentPage = 1;
        applyFiltersDebounced = debounce(applyFiltersAndSort, 400);
        createSourceCheckboxes();
        parseInitialContent(); // Parse initial HTML (may remove placeholders)
        // renderPagination(); // *** REMOVED: Don't render pagination initially ***
        bindEvents();
        // updateStatusMessage(); // Let fetchDealsPage update status on completion
        if(updateNoticeContainer.length) updateNoticeContainer.hide();
        initialLoadComplete = true; // Mark basic init done
        console.log(`DSP: Init complete. View Mode: ${currentView}. Fetching page 1...`);
        fetchDealsPage(1); // *** ADDED: Fetch page 1 immediately ***
    }

    /** Creates source filter checkboxes */
    function createSourceCheckboxes() {
        sourceCheckboxesContainer.empty();
        const sources = dsp_ajax_obj.config_sources || [];
        if (sources.length > 0) {
            sources.forEach(s => {
                if (typeof s !== 'string' || s === '') return;
                const id = 'dsp-source-' + s.toLowerCase().replace(/[^a-z0-9]/g, '');
                const escapedSourceName = $('<div>').text(s).html();
                const checkboxHTML = `<label for="${id}" class="dsp-source-label"><input type="checkbox" id="${id}" class="dsp-source-filter-cb" value="${s}" checked> ${escapedSourceName}</label>`;
                sourceCheckboxesContainer.append(checkboxHTML);
            });
        }
    }

    /** Parses the initial HTML (table or grid) to potentially remove placeholders */
    function parseInitialContent() {
        console.log(`DSP: Parsing initial content (${currentView} view)...`);
        // We don't need to populate allDealsData here anymore, fetchDealsPage will do it.
        // We just need to potentially remove the initial loading/no deals message
        // if the PHP happened to render actual deals (which it might not anymore).
        let placeholderSelector = '.dsp-loading-row, .dsp-loading-message, .dsp-no-deals-row, .dsp-no-deals-message';
        const containerToCheck = (currentView === 'table') ? tableBody : gridContainer;
        const initialPlaceholder = containerToCheck.find(placeholderSelector);
        const hasActualDeals = (currentView === 'table')
            ? containerToCheck.find('tr.dsp-deal-row').length > 0
            : containerToCheck.find('div.dsp-grid-item').length > 0;

        if (hasActualDeals && initialPlaceholder.length > 0) {
             initialPlaceholder.remove();
             console.log("DSP: Removed initial placeholder as initial deals were found in HTML.");
        } else if (!hasActualDeals && initialPlaceholder.length === 0) {
            // If no deals and no placeholder, add a loading message temporarily
            const loadingMsgHTML = (currentView === 'table')
                ? '<tr class="dsp-loading-row"><td colspan="5">' + __(dsp_ajax_obj.loading_text) + '</td></tr>'
                : '<div class="dsp-loading-message">' + __(dsp_ajax_obj.loading_text) + '</div>';
            containerToCheck.html(loadingMsgHTML);
            console.log("DSP: Added temporary loading message as no deals or placeholder found.");
        } else {
             console.log("DSP: Initial placeholder status seems okay.");
        }
    }

    /** Parses data-* attributes from a jQuery element (row or grid item) - Kept for potential future use */
    function parseDealDataFromElement($element) {
        if (!$element || $element.length === 0) return null;
        const firstSeenTimestamp = parseInt($element.data('first-seen'), 10) || 0;
        const deal = {
            link: $element.data('link') || '#',
            title: $element.data('title') || '',
            price: (currentView === 'table') ? $element.find('.dsp-cell-price').text() : $element.find('.dsp-grid-item-price').text(),
            source: $element.data('source') || '',
            description: $element.data('description') || '',
            first_seen_ts: firstSeenTimestamp,
            first_seen_formatted: (currentView === 'table') ? $element.find('.dsp-cell-date').text().trim() : $element.find('.dsp-grid-item-date').text().trim(),
            is_new: $element.data('is-new') == '1',
            is_lifetime: $element.data('is-ltd') == '1',
            price_numeric: $element.data('price') ?? Infinity,
            image_url: $element.data('image-url') || '',
            local_image_src: $element.data('local-image-src') || '',
            image_attachment_id: parseInt($element.data('attachment-id'), 10) || 0
        };
        if (deal.link !== '#' && deal.title && deal.first_seen_ts > 0) { return deal; }
        else { console.warn("DSP: Skipped parsing element:", $element, deal); return null; }
    }


    /** Fetches a specific page of deals via AJAX */
    function fetchDealsPage(pageNum) {
        if (isLoadingPage) { console.log("DSP Fetch Skipped: Already loading."); return; }

        const allSourceCheckboxes = container.find('.dsp-source-filter-cb');
        const checkedSourceCheckboxes = container.find('.dsp-source-filter-cb:checked');
        const contentWrapper = (currentView === 'table') ? tableWrapper : gridContainer;

        // Clear content and show loading before check (handles no sources case implicitly)
        isLoadingPage = true;
        currentPage = pageNum;
        const loadingMsgHTML = (currentView === 'table')
            ? '<tr class="dsp-loading-row"><td colspan="5">' + __(dsp_ajax_obj.loading_text) + '</td></tr>'
            : '<div class="dsp-loading-message">' + __(dsp_ajax_obj.loading_text) + '</div>';
        if (currentView === 'table') tableBody.empty().html(loadingMsgHTML);
        else gridContainer.empty().html(loadingMsgHTML);

        if(contentWrapper.length) contentWrapper.addClass('dsp-loading-overlay');
        paginationControlsContainer.addClass('dsp-loading');
        statusMessage.text(__(dsp_ajax_obj.loading_text));
        if(updateNoticeContainer.length) updateNoticeContainer.slideUp(100);


        // Check if sources are selected *after* showing loading
        if (allSourceCheckboxes.length > 0 && checkedSourceCheckboxes.length === 0) {
            console.log("DSP: No sources selected, skipping AJAX fetch.");
            if(contentWrapper.length) contentWrapper.removeClass('dsp-loading-overlay');
            const noSourceMsg = '<div class="dsp-no-deals-message">' + __(dsp_ajax_obj.no_sources_selected_text) + '</div>';
            const noSourceRow = '<tr class="dsp-no-deals-row"><td colspan="5">' + __(dsp_ajax_obj.no_sources_selected_text) + '</td></tr>';
            if (currentView === 'table') tableBody.empty().html(noSourceRow);
            else gridContainer.empty().html(noSourceMsg);
            paginationControlsContainer.empty().removeClass('dsp-loading');
            totalItems = 0; currentPage = 1;
            statusMessage.text(__(dsp_ajax_obj.no_sources_selected_text));
            isLoadingPage = false;
            return;
        }

        // Gather filter/sort parameters
        const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = checkedSourceCheckboxes.map(function() { return $(this).val(); }).get(); const sortKey = currentSort.key; const sortReverse = currentSort.reverse; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPrice = minPriceInput.val(); const maxPrice = maxPriceInput.val();

        console.log(`DSP: Fetching page ${currentPage}... View: ${currentView}, Sort: ${sortKey} ${sortReverse ? 'DESC' : 'ASC'}, Search: "${searchTerm}", New: ${showNew}, LTD: ${showLtdOnly}, Price: ${minPrice}-${maxPrice}, Sources: ${activeSources.join(',')}`);

        const requestData = { action: 'dsp_get_deals', nonce: dsp_ajax_obj.nonce, page: currentPage, items_per_page: itemsPerPage, orderby: sortKey, order: sortReverse ? 'DESC' : 'ASC', search: searchTerm, sources: activeSources, new_only: showNew ? 1 : 0, ltd_only: showLtdOnly ? 1 : 0, min_price: minPrice, max_price: maxPrice };

        $.ajax({
            url: dsp_ajax_obj.ajax_url, type: 'POST', data: requestData, timeout: 30000,
            success: function(response) {
                console.log("DSP: fetchDealsPage response:", response);
                const errorMsgRow = '<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + '</td></tr>';
                const errorMsgDiv = '<div class="dsp-error-message">' + __(dsp_ajax_obj.error_text) + '</div>';
                if (response.success && response.data) {
                    allDealsData = response.data.deals || []; // Update with processed deals
                    totalItems = parseInt(response.data.total_items, 10) || 0; // Update total count
                    itemsPerPage = parseInt(response.data.items_per_page, 10) || itemsPerPage;
                    currentPage = parseInt(response.data.current_page, 10) || currentPage;
                    renderContent(allDealsData); // Render the content
                    renderPagination(); // Render pagination based on updated totalItems
                    updateLastUpdated(response.data.last_fetch);
                } else {
                    console.error("DSP: Error fetching deals page:", response);
                    if (currentView === 'table') tableBody.empty().html(errorMsgRow); else gridContainer.empty().html(errorMsgDiv);
                    statusMessage.text(response.data?.message || __(dsp_ajax_obj.error_text)).addClass('dsp-error');
                    totalItems = 0; renderPagination(); // Clear pagination on error
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("DSP: AJAX fetchDealsPage Error:", textStatus, errorThrown);
                const errorMsgRowAjax = '<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + ' (AJAX)</td></tr>';
                const errorMsgDivAjax = '<div class="dsp-error-message">' + __(dsp_ajax_obj.error_text) + ' (AJAX)</div>';
                if (currentView === 'table') tableBody.empty().html(errorMsgRowAjax); else gridContainer.empty().html(errorMsgDivAjax);
                statusMessage.text(__(dsp_ajax_obj.error_text) + ' (AJAX)').addClass('dsp-error');
                totalItems = 0; renderPagination(); // Clear pagination on error
            },
            complete: function() {
                isLoadingPage = false;
                paginationControlsContainer.removeClass('dsp-loading');
                if(contentWrapper.length) contentWrapper.removeClass('dsp-loading-overlay');
                updateStatusMessage(); // Update status based on fetched data
            }
        });
    }

    /** Updates the 'Last Updated' time display */
    function updateLastUpdated(dateTimeString) {
        const neverText = __(dsp_ajax_obj.never_text); let displayTime = neverText; if (dateTimeString && dateTimeString !== neverText) { displayTime = dateTimeString; } lastUpdatedTimeSpan.text(displayTime); lastUpdatedSpan.css('visibility', displayTime === neverText ? 'hidden' : 'visible');
    }

    /** Creates a single <tr> jQuery element for a deal object */
    function createDealRowElement(deal) {
        if (!deal || !deal.title || !deal.link || !deal.first_seen_formatted) { console.warn("DSP: Cannot create row for invalid deal object:", deal); return $(); }
        const isNew = deal.is_new || false; const isLifetime = deal.is_lifetime || false; const firstSeenTimestamp = parseInt(deal.first_seen_ts, 10) || 0;
        const sortablePrice = deal.price_numeric ?? Infinity;
        const row = $('<tr></tr>').addClass('dsp-deal-row').attr({'data-source': deal.source || '', 'data-title': deal.title || '', 'data-description': deal.description || '', 'data-is-new': isNew ? '1' : '0', 'data-is-ltd': isLifetime ? '1' : '0', 'data-first-seen': firstSeenTimestamp, 'data-price': sortablePrice, 'data-link': deal.link || '#', 'data-image-url': deal.image_url || '', 'data-local-image-src': deal.local_image_src || '', 'data-attachment-id': deal.image_attachment_id || 0 });
        if (isNew) row.addClass('dsp-new-item'); if (isLifetime) row.addClass('dsp-lifetime-item');
        row.append($('<td></td>').addClass('dsp-cell-new').text(isNew ? __(dsp_ajax_obj.yes_text) : __(dsp_ajax_obj.no_text)));
        const titleCell = $('<td></td>').addClass('dsp-cell-title'); const link = $('<a></a>').attr({href: deal.link, target: '_blank', rel: 'noopener noreferrer'}).text(deal.title); titleCell.append(link); if (isLifetime) { titleCell.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>'); } if (deal.description) { titleCell.append($('<p></p>').addClass('dsp-description').text(deal.description)); } row.append(titleCell);
        row.append($('<td></td>').addClass('dsp-cell-price').text(deal.price || 'N/A')); row.append($('<td></td>').addClass('dsp-cell-source').text(deal.source || 'N/A')); row.append($('<td></td>').addClass('dsp-cell-date').attr('data-timestamp', firstSeenTimestamp).text(deal.first_seen_formatted || 'N/A'));
        return row;
    }


    /** Creates a single <div> jQuery element for a grid item */
    function createGridItemElement(deal) {
        if (!deal || !deal.title || !deal.link || !deal.first_seen_formatted) { console.warn("DSP: Cannot create grid item for invalid deal object:", deal); return $(); }
        const isNew = deal.is_new || false; const isLifetime = deal.is_lifetime || false; const firstSeenTimestamp = parseInt(deal.first_seen_ts, 10) || 0;
        const externalImageUrl = deal.image_url || '';
        const localImageSrc = deal.local_image_src || ''; // Get local src from processed data
        const displayImageUrl = localImageSrc || externalImageUrl; // Prioritize local
        const sortablePrice = deal.price_numeric ?? Infinity;

        const gridItem = $('<div></div>')
            .addClass('dsp-grid-item')
            .attr({'data-source': deal.source || '', 'data-title': deal.title || '', 'data-description': deal.description || '', 'data-is-new': isNew ? '1' : '0', 'data-is-ltd': isLifetime ? '1' : '0', 'data-first-seen': firstSeenTimestamp, 'data-price': sortablePrice, 'data-link': deal.link || '#', 'data-image-url': externalImageUrl, 'data-local-image-src': localImageSrc, 'data-attachment-id': deal.image_attachment_id || 0 });

        if (isNew) gridItem.addClass('dsp-new-item');
        if (isLifetime) gridItem.addClass('dsp-lifetime-item');

        // Image container
        const imageContainer = $('<div></div>').addClass('dsp-grid-item-image');
        const imageLink = $('<a></a>').attr({href: deal.link, target: '_blank', rel: 'noopener noreferrer'});

        // Use displayImageUrl for src
        if (displayImageUrl) {
            imageLink.append($('<img>').attr({src: displayImageUrl, alt: deal.title, loading: 'lazy'}));
        } else {
            imageLink.append($('<span></span>').addClass('dsp-image-placeholder')); // Placeholder
        }

        imageContainer.append(imageLink);
        gridItem.append(imageContainer);

        // Content container (remains the same)
        const contentContainer = $('<div></div>').addClass('dsp-grid-item-content');
        const titleH3 = $('<h3></h3>').addClass('dsp-grid-item-title');
        const titleLink = $('<a></a>').attr({href: deal.link, target: '_blank', rel: 'noopener noreferrer'}).text(deal.title);
        titleH3.append(titleLink);
        if (isLifetime) { titleH3.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>'); }
        contentContainer.append(titleH3);
        const metaDiv = $('<div></div>').addClass('dsp-grid-item-meta');
        metaDiv.append($('<span></span>').addClass('dsp-grid-item-price').text(deal.price || 'N/A'));
        metaDiv.append(' | ');
        metaDiv.append($('<span></span>').addClass('dsp-grid-item-source').text(deal.source || 'N/A'));
        metaDiv.append(' | ');
        metaDiv.append($('<span></span>').addClass('dsp-grid-item-date').text(deal.first_seen_formatted || 'N/A'));
        if (isNew) { metaDiv.append($('<span></span>').addClass('dsp-grid-item-new').text(__(' (New)'))); }
        contentContainer.append(metaDiv);
        if (deal.description) { contentContainer.append($('<p></p>').addClass('dsp-grid-item-description').text(deal.description.substring(0, 100) + (deal.description.length > 100 ? '...' : ''))); }
        gridItem.append(contentContainer);

        return gridItem;
    }


    /** Renders the main content area (Table or Grid) */
    function renderContent(dealsToDisplay) {
        const noDealsMatchingText = __(dsp_ajax_obj.no_deals_found_text);
        const containerToRender = (currentView === 'table') ? tableBody : gridContainer;
        const noDealsHTML = (currentView === 'table')
            ? '<tr class="dsp-no-deals-row"><td colspan="5">' + noDealsMatchingText + '</td></tr>'
            : '<div class="dsp-no-deals-message">' + noDealsMatchingText + '</div>';

        containerToRender.empty(); // Clear previous content

        if (!dealsToDisplay || dealsToDisplay.length === 0) {
            containerToRender.html(noDealsHTML); // Show 'no deals' message
            return;
        }

        // If deals exist, render them
        dealsToDisplay.forEach((deal, index) => {
            const element = (currentView === 'table')
                ? createDealRowElement(deal)
                : createGridItemElement(deal);

            // Add even/odd class for table rows only
            if (currentView === 'table' && element) {
                element.addClass(index % 2 === 0 ? 'dsp-even-row' : 'dsp-odd-row');
            }

            if(element) containerToRender.append(element);
        });
    }

    /** Renders the pagination controls */
    function renderPagination() {
        paginationControlsContainer.empty();
        if (itemsPerPage <= 0 || totalItems <= itemsPerPage) {
             console.log(`DSP: Hiding pagination. Total: ${totalItems}, PerPage: ${itemsPerPage}`);
            return; // Hide if not needed
        }
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        if (totalPages <= 1) {
             console.log("DSP: Hiding pagination. Only 1 page.");
             return; // Hide if only one page
        }

        console.log(`DSP: Rendering pagination. Current: ${currentPage}, Total: ${totalPages}`);
        let paginationHTML = '<ul class="dsp-page-numbers">';
        paginationHTML += `<li class="dsp-page-item ${currentPage === 1 ? 'dsp-disabled' : ''}">`;
        paginationHTML += currentPage > 1 ? `<a href="#" class="dsp-page-link dsp-prev" data-page="${currentPage - 1}">« ${__('Previous')}</a>` : `<span class="dsp-page-link dsp-prev">« ${__('Previous')}</span>`;
        paginationHTML += '</li>';
        paginationHTML += `<li class="dsp-page-item dsp-current-page"><span class="dsp-page-link">${sprintf(__(dsp_ajax_obj.page_text), currentPage, totalPages)}</span></li>`;
        paginationHTML += `<li class="dsp-page-item ${currentPage === totalPages ? 'dsp-disabled' : ''}">`;
        paginationHTML += currentPage < totalPages ? `<a href="#" class="dsp-page-link dsp-next" data-page="${currentPage + 1}">${__('Next')} »</a>` : `<span class="dsp-page-link dsp-next">${__('Next')} »</span>`;
        paginationHTML += '</li>';
        paginationHTML += '</ul>';
        paginationControlsContainer.html(paginationHTML);
    }

    /** Updates the debug log display */
    function updateDebugLog(logMessages) { const noLogText = __('No debug log available.'); if (!logContainer.length || !logPre.length) return; if (logMessages && Array.isArray(logMessages) && logMessages.length > 0) { const escaped = logMessages.map(msg => $('<div>').text(msg).html()); logPre.html(escaped.join('\n')); } else { logPre.text(noLogText); } }

    /** Applies filters/sorting by fetching page 1 */
    function applyFiltersAndSort() {
        if (!initialLoadComplete) { console.log("DSP: applyFiltersAndSort called before init complete, skipping."); return; }
        console.log("DSP: Filters/Sort changed, fetching Page 1...");
        const sortKey = currentSort.key; const sortReverse = currentSort.reverse;
        // Update visual sort indicators
        tableHead.find('th').removeClass('dsp-sort-asc dsp-sort-desc');
        tableHead.find(`th[data-sort-key="${sortKey}"]`).addClass(sortReverse ? 'dsp-sort-desc' : 'dsp-sort-asc');
        // Fetch page 1 with new filters/sort
        fetchDealsPage(1);
    }

    /** Gets a comparable value for sorting */
    function getSortValue(deal, key) { if (!deal) return ''; switch (key) { case 'is_new': return deal.is_new ? 1 : 0; case 'title': return (deal.title || '').toLowerCase(); case 'price': return parsePriceForSort(deal.price || ''); case 'price_numeric': return deal.price_numeric ?? Infinity; case 'source': return (deal.source || '').toLowerCase(); case 'first_seen': return parseInt(deal.first_seen_ts, 10) || 0; case 'is_ltd': return deal.is_lifetime ? 1: 0; default: return ''; } }

    /** Parses a price string into a sortable number */
    function parsePriceForSort(priceStr) { const inf = Infinity; if (priceStr === null || typeof priceStr === 'undefined') return inf; priceStr = String(priceStr).toLowerCase().trim(); if (priceStr === '' || priceStr === 'n/a') return inf; if (['free', 'freebie', '0', '0.00'].includes(priceStr) || priceStr.startsWith('$0') || priceStr.startsWith('€0') || priceStr.startsWith('£0')) return 0; const clean = priceStr.replace(/[^0-9.-]+/g,""); const match = clean.match(/^-?\d+(\.\d+)?/); if(match){ return parseFloat(match[0]); } return inf; }

    /** Debounce utility */
    function debounce(func, wait) { let timeoutId = null; return function(...args) { clearTimeout(timeoutId); timeoutId = setTimeout(() => { func.apply(this, args); }, wait); }; }

    /** Simple translation placeholder */
     function __(text) { const key = text.toLowerCase().replace(/[^a-z0-9]/g, '_') + '_text'; if (dsp_ajax_obj && typeof dsp_ajax_obj[key] !== 'undefined') { return dsp_ajax_obj[key]; } if (dsp_ajax_obj && typeof dsp_ajax_obj[text] !== 'undefined') { return dsp_ajax_obj[text]; } console.warn("DSP Translation missing for:", text); return text; }

    /** Updates the main status message bar */
     function updateStatusMessage() { if (isLoadingPage || isRefreshing || isSubscribing) return; const totalDealCount = totalItems; const totalPages = itemsPerPage > 0 ? Math.ceil(totalDealCount / itemsPerPage) : 1; let statusText = ''; if (totalDealCount === 0 && initialLoadComplete) { const allSourceCB = container.find('.dsp-source-filter-cb'); const checkedSourceCB = container.find('.dsp-source-filter-cb:checked'); statusText = (allSourceCB.length > 0 && checkedSourceCB.length === 0) ? __(dsp_ajax_obj.no_sources_selected_text) : __(dsp_ajax_obj.no_deals_found_text); } else if (totalDealCount === 0 && !initialLoadComplete) { statusText = (currentView === 'table' && tableBody.find('.dsp-no-deals-row').length > 0) || (currentView === 'grid' && gridContainer.find('.dsp-no-deals-message').length > 0) ? __(dsp_ajax_obj.no_deals_yet_text) : __(dsp_ajax_obj.loading_text); } else if (totalDealCount > 0 && itemsPerPage > 0 && totalPages >= currentPage) { const firstItem = (currentPage - 1) * itemsPerPage + 1; const lastItem = Math.min(currentPage * itemsPerPage, totalDealCount); statusText = sprintf(__('Showing deals %d-%d of %d'), firstItem, lastItem, totalDealCount); } else if (totalDealCount > 0 && itemsPerPage <= 0) { statusText = sprintf(__('Showing %d deals'), totalDealCount); } const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(); const totalSources = dsp_ajax_obj.config_sources?.length || 0; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPrice = minPriceInput.val(); const maxPrice = maxPriceInput.val(); const filterParts = []; if (searchTerm) filterParts.push(sprintf('%s: \'%s\'', __('Search'), searchTerm)); if (totalSources > 0 && activeSources.length < totalSources) filterParts.push(__('Sources filtered')); if (showNew) filterParts.push(__('New only')); if (showLtdOnly) filterParts.push(__('LTD Only')); if (minPrice !== '' || maxPrice !== '') { let priceText = __('Price') + ': '; if (minPrice !== '' && maxPrice !== '') priceText += `$${minPrice}-$${maxPrice}`; else if (minPrice !== '') priceText += `$${minPrice}+`; else if (maxPrice !== '') priceText += `${__('Up to')} $${maxPrice}`; filterParts.push(priceText); } if (filterParts.length > 0 && totalDealCount > 0) { statusText += ` | ${__('Filters')}: ${filterParts.join(', ')}`; } statusMessage.text(statusText).removeClass('dsp-error dsp-success'); }

     /** Basic sprintf implementation */
     function sprintf(format, ...args) { let i = 0; return format.replace(/%[sd]/g, (match) => (match === '%d' ? parseInt(args[i++], 10) : String(args[i++]))); }

    /** Handles submission of the subscription form */
    function handleSubscriptionSubmit(event) { event.preventDefault(); if (isSubscribing || !subscribeModal.length || !subscribeEmailInput.length) return; const email = subscribeEmailInput.val().trim(); const enterEmailText = __(dsp_ajax_obj.subscribe_enter_email); const invalidFormatText = __(dsp_ajax_obj.subscribe_invalid_email_format); if (!email) { subscribeMessage.text(enterEmailText).removeClass('dsp-success').addClass('dsp-error').show(); return; } if (!/^\S+@\S+\.\S+$/.test(email)) { subscribeMessage.text(invalidFormatText).removeClass('dsp-success').addClass('dsp-error').show(); return; } isSubscribing = true; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', true); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'visible'); if(subscribeMessage.length) subscribeMessage.text('').removeClass('dsp-success dsp-error').hide(); $.ajax({ url: dsp_ajax_obj.ajax_url, type:'POST', data: { action:'dsp_subscribe_email', nonce: dsp_ajax_obj.nonce, email: email }, timeout: 15000, success: function(response) { if (response.success && response.data?.message) { if(subscribeMessage.length) subscribeMessage.text(response.data.message).removeClass('dsp-error').addClass('dsp-success').show(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); setTimeout(function(){ if(subscribeModal.is(':visible')) subscribeModal.fadeOut(200); }, 3000); } else { const errorMsg = response.data?.message || __(dsp_ajax_obj.subscribe_error_generic); if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show(); } }, error: function(jqXHR, textStatus, errorThrown) { console.error("DSP Sub AJAX Error:", textStatus, errorThrown); let errorMsg = __(dsp_ajax_obj.subscribe_error_network); if (jqXHR.responseJSON?.data?.message) errorMsg = jqXHR.responseJSON.data.message; if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show(); }, complete: function() { isSubscribing = false; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', false); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'hidden'); } }); }

    /** Binds all event listeners */
    function bindEvents() {
        // Filter Controls
        searchInput.on('input', applyFiltersDebounced); searchInput.on('keydown', function(e) { if (e.key === 'Enter' || e.keyCode === 13) { e.preventDefault(); console.log("DSP: Enter key pressed in search, triggering filter immediately."); applyFiltersAndSort(); } }); newOnlyCheckbox.on('change', applyFiltersAndSort); sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', applyFiltersAndSort); ltdOnlyCheckbox.on('change', applyFiltersAndSort); minPriceInput.on('keyup', debounce(applyFiltersAndSort, 500)); maxPriceInput.on('keyup', debounce(applyFiltersAndSort, 500)); minPriceInput.on('change', applyFiltersAndSort); maxPriceInput.on('change', applyFiltersAndSort);

        // Sorting Controls (Only show/bind if in table view)
        if (currentView === 'table') {
            tableHead.find('th[data-sort-key]').on('click', function () { if (isLoadingPage || isRefreshing) return; const th = $(this); const newSortKey = th.data('sort-key'); if(!newSortKey) return; if (currentSort.key === newSortKey) { currentSort.reverse = !currentSort.reverse; } else { currentSort.key = newSortKey; currentSort.reverse = ['first_seen', 'is_new'].includes(newSortKey); } applyFiltersAndSort(); });
        } else {
            // Hide table head if in grid view
            if(tableHead.length) tableHead.hide();
        }

        // Debug Log Button
        if (toggleLogButton.length && logContainer.length && dsp_ajax_obj.show_debug_button && container.find('#dsp-toggle-debug-log').length > 0 ) {
            toggleLogButton.on('click', function () { const button = $(this); const showTxt = __(dsp_ajax_obj.show_log_text); const hideTxt = __(dsp_ajax_obj.hide_log_text); logContainer.slideToggle(200, function() { button.text(logContainer.is(':visible') ? hideTxt : showTxt); }); });
        } else { if(logContainer.length) logContainer.hide(); if(toggleLogButton.length) toggleLogButton.hide(); }

        // Refresh Button
        if (refreshButton.length && container.find('#dsp-refresh-button').length > 0) {
            refreshButton.on('click', function () { if (isRefreshing || isLoadingPage) return; isRefreshing = true; const refreshingText = __(dsp_ajax_obj.refreshing_text); const refreshFinishedText = __(dsp_ajax_obj.refresh_finished_text); const refreshFailedInvalidResp = __(dsp_ajax_obj.error_refresh_invalid_resp_text); const refreshFailedAjax = __(dsp_ajax_obj.error_refresh_ajax_text); const button = $(this); button.prop('disabled', true); if(refreshSpinner.length) refreshSpinner.css('visibility', 'visible'); if(refreshMessage.length) refreshMessage.text(refreshingText).removeClass('dsp-error dsp-success'); if(logPre.length && dsp_ajax_obj.show_debug_button) logPre.text('Running manual refresh...'); statusMessage.text(refreshingText).removeClass('dsp-success dsp-error'); const contentWrapper = (currentView === 'table') ? tableWrapper : gridContainer; if(contentWrapper.length) contentWrapper.addClass('dsp-loading-overlay'); $.ajax({ url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce }, timeout: 180000, success: function (response) { console.log("DSP: Manual refresh response:", response); let message = refreshFailedInvalidResp; let messageType = 'dsp-error'; if (response.success && response.data) { totalItems = parseInt(response.data.total_items, 10) || 0; currentPage = 1; const allDealsFromServer = response.data.deals || []; updateLastUpdated(response.data.last_fetch); if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(response.data.log); message = response.data.message || refreshFinishedText; messageType = message.toLowerCase().includes('error') || message.toLowerCase().includes('fail') ? 'dsp-error' : 'dsp-success'; allDealsData = allDealsFromServer.slice(0, itemsPerPage); // Show first page after refresh
                renderContent(allDealsData); renderPagination(); } else { console.error("DSP Refresh Error:", response); const logData = response.data?.log || [message]; if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(logData); message = response.data?.message || message; statusMessage.text(message).addClass('dsp-error'); renderContent([]); totalItems = 0; renderPagination(); } if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-error dsp-success').addClass(messageType); }, error: function (jqXHR, textStatus, errorThrown) { console.error("DSP AJAX Refresh Error:", textStatus, errorThrown, jqXHR.responseJSON); let errorMsg = refreshFailedAjax; let logData = [errorMsg, `Status: ${textStatus}`, `Error: ${errorThrown}`]; if (jqXHR.responseJSON?.data) { errorMsg = jqXHR.responseJSON.data.message || errorMsg; logData = jqXHR.responseJSON.data.log || logData; } if (jqXHR.responseText) { logData.push("Raw Response Snippet: " + jqXHR.responseText.substring(0, 500)); } if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(logData); if(refreshMessage.length) refreshMessage.text(errorMsg).addClass('dsp-error'); statusMessage.text(errorMsg).addClass('dsp-error'); renderContent([]); totalItems = 0; renderPagination(); }, complete: function () { isRefreshing = false; button.prop('disabled', false); if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden'); if(refreshMessage.length) { const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000; setTimeout(() => { refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay); } if(contentWrapper.length) contentWrapper.removeClass('dsp-loading-overlay'); updateStatusMessage(); } }); });
        }

        // Donate Modal
        if (donateButton.length && donateModal.length) { donateButton.on('click', (e)=>{ e.preventDefault(); donateModal.fadeIn(200); }); donateModalClose.on('click', (e)=>{ e.preventDefault(); donateModal.fadeOut(200); }); donateModal.on('click', (e)=>{ if($(e.target).is(donateModal)) donateModal.fadeOut(200); }); $(document).on('keydown', (e)=>{ if(e.key==="Escape" && donateModal.is(':visible')) donateModal.fadeOut(200); }); donateModal.find('.dsp-copy-code').on('click', function() { const codeElement = $(this); const address = codeElement.text(); const feedback = donateModal.find('.dsp-copy-feedback'); navigator.clipboard.writeText(address).then(function() { feedback.text('Address copied!').fadeIn(); setTimeout(() => feedback.fadeOut(), 2000); }, function(err) { feedback.text('Failed to copy.').fadeIn(); setTimeout(() => feedback.fadeOut(), 2000); console.error('DSP Copy Failed: ', err); }); }); }

        // Subscribe Modal
        if (subscribeButton.length && subscribeModal.length && dsp_ajax_obj.email_notifications_enabled) { subscribeButton.show(); subscribeButton.on('click', (e)=>{ e.preventDefault(); if(subscribeMessage.length) subscribeMessage.text('').hide(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); subscribeModal.fadeIn(200); if(subscribeEmailInput.length) subscribeEmailInput.focus(); }); if(subscribeSubmitButton.length) subscribeSubmitButton.on('click', handleSubscriptionSubmit); if(subscribeEmailInput.length) subscribeEmailInput.on('keypress', (e)=>{ if(e.which === 13) handleSubscriptionSubmit(e); }); if(subscribeModalClose.length) subscribeModalClose.on('click', (e)=>{ e.preventDefault(); subscribeModal.fadeOut(200); }); subscribeModal.on('click', (e)=>{ if($(e.target).is(subscribeModal)) subscribeModal.fadeOut(200); }); $(document).on('keydown', (e)=>{ if(e.key==="Escape" && subscribeModal.is(':visible') && !donateModal.is(':visible')) subscribeModal.fadeOut(200); }); } else { if (subscribeButton.length) subscribeButton.hide(); }

        // Pagination Controls
        paginationControlsContainer.on('click', 'a.dsp-page-link', function(e) { e.preventDefault(); const link = $(this); if (link.parent().hasClass('dsp-disabled') || isLoadingPage || isRefreshing) { return; } const pageNum = parseInt(link.data('page'), 10); if (!isNaN(pageNum) && pageNum !== currentPage) { fetchDealsPage(pageNum); } });

        // Background Update Notice Dismiss
        if(updateNoticeContainer.length) { updateNoticeContainer.on('click', '.dsp-dismiss-notice', function(e){ e.preventDefault(); updateNoticeContainer.slideUp(200, function(){ $(this).empty(); }); }); }
    } // End bindEvents

    // --- Run ---
    init(); // Start the process

}); // End jQuery Ready