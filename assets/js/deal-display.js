// File: assets/js/deal-display.js (v1.1.36 - Infinite Scroll + Status Fix)

jQuery(document).ready(function($) {
    const container = $('#dsp-deal-display-container');
    if (!container.length) {
        console.error("DSP Error: Main container #dsp-deal-display-container not found.");
        return;
    }
    console.log("DSP Log: Container found.");

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
    const refreshMessage = container.find('#dsp-refresh-status');
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
    const infiniteScrollLoader = container.find('#dsp-infinite-scroll-loader'); // Loader Selector
    const updateNoticeContainer = container.find('#dsp-background-update-notice');
    const ltdOnlyCheckbox = container.find('#dsp-ltd-only-checkbox');
    const minPriceInput = container.find('#dsp-min-price-input');
    const maxPriceInput = container.find('#dsp-max-price-input');

    // --- State ---
    let currentDeals = []; // Holds deals currently rendered
    let currentSort = { key: 'first_seen', reverse: true };
    let isRefreshing = false;
    let isSubscribing = false;
    let isLoadingPage = false; // Flag for AJAX loading state
    let currentPage = 1;
    let totalItems = 0;
    let itemsPerPage = 25;
    let canLoadMore = true; // Flag to prevent unnecessary scroll checks
    let initialLoadComplete = false;
    let applyFiltersDebounced;
    const currentView = dsp_ajax_obj.view_mode || 'table';
    const scrollThreshold = 300; // Pixels from bottom to trigger load

    console.log("DSP Log: Initial State - View:", currentView, "ItemsPerPage:", dsp_ajax_obj.items_per_page);

    // --- Initialization ---
    function init() {
        console.log("DSP Log: Initializing...");
        itemsPerPage = parseInt(dsp_ajax_obj.items_per_page, 10) || 25;
        currentPage = 1;
        applyFiltersDebounced = debounce(applyFiltersAndSort, 400);

        createSourceCheckboxes();
        parseInitialContent(); // Remove initial loading message if PHP rendered deals
        bindEvents(); // Bind filters, buttons, scroll listener etc.

        if(updateNoticeContainer.length) updateNoticeContainer.hide(); else console.warn("DSP Warning: Update notice container not found.");
        if(infiniteScrollLoader.length) infiniteScrollLoader.hide(); else console.warn("DSP Warning: Infinite scroll loader container not found.");

        initialLoadComplete = true;
        console.log(`DSP Log: Init complete. Fetching page 1...`);
        loadDeals(1, false); // Fetch page 1 and replace content
    }

    /** Creates source filter checkboxes */
    function createSourceCheckboxes() {
        if (!sourceCheckboxesContainer.length) { console.error("DSP Error: Source checkboxes container not found."); return; }
        sourceCheckboxesContainer.empty();
        const sources = dsp_ajax_obj.config_sources || [];
        if (sources.length > 0) {
            console.log("DSP Log: Creating source checkboxes for:", sources);
            sources.forEach(s => {
                if (typeof s !== 'string' || s === '') return;
                const id = 'dsp-source-' + s.toLowerCase().replace(/[^a-z0-9-]/g, ''); // Allow hyphens
                const escapedSourceName = $('<div>').text(s).html();
                const checkboxHTML = `<label for="${id}" class="dsp-source-label"><input type="checkbox" id="${id}" class="dsp-source-filter-cb" value="${s}" checked> ${escapedSourceName}</label>`;
                sourceCheckboxesContainer.append(checkboxHTML);
            });
        } else {
             console.log("DSP Log: No sources configured to display.");
             sourceCheckboxesContainer.append($('<span>').text(__('No sources available'))); // Inform user
        }
    }

    /** Parses the initial HTML to potentially remove placeholders */
    function parseInitialContent() {
        console.log(`DSP Log: Parsing initial content (${currentView} view)...`);
        let placeholderSelector = '.dsp-loading-row, .dsp-loading-message, .dsp-no-deals-row, .dsp-no-deals-message';
        const containerToCheck = (currentView === 'table') ? tableBody : gridContainer;
        if (!containerToCheck.length) { console.error(`DSP Error: Cannot find container to check initial content: ${currentView === 'table' ? 'tbody' : '#dsp-deals-grid'}`); return; }

        const initialPlaceholder = containerToCheck.find(placeholderSelector);
        const hasActualDeals = (currentView === 'table')
            ? containerToCheck.find('tr.dsp-deal-row').length > 0
            : containerToCheck.find('div.dsp-grid-item').length > 0;

        console.log(`DSP Log: Has Actual Deals: ${hasActualDeals}, Initial Placeholder Found: ${initialPlaceholder.length > 0}`);

        if (hasActualDeals && initialPlaceholder.length > 0) {
             initialPlaceholder.remove();
             console.log("DSP Log: Removed initial placeholder as deals were found in HTML.");
        } else if (!hasActualDeals && initialPlaceholder.length === 0) {
            const loadingMsgHTML = (currentView === 'table')
                ? '<tr class="dsp-loading-row"><td colspan="5">' + __(dsp_ajax_obj.loading_text) + '</td></tr>'
                : '<div class="dsp-loading-message">' + __(dsp_ajax_obj.loading_text) + '</div>';
            containerToCheck.html(loadingMsgHTML);
            console.log("DSP Log: Added temporary loading message as no deals or placeholder found.");
        } else if (!hasActualDeals && initialPlaceholder.length > 0) {
             console.log("DSP Log: Placeholder found, no initial deals. Waiting for JS load.");
             // Ensure loading text is correct
             initialPlaceholder.find('td, div').text(__(dsp_ajax_obj.loading_text));
        } else {
             console.log("DSP Log: Initial placeholder status seems okay (deals present or placeholder exists).");
        }
    }

    /**
     * Fetches deals via AJAX.
     * @param {number} pageNum The page number to fetch.
     * @param {boolean} append If true, appends deals; otherwise, replaces content.
     */
    function loadDeals(pageNum, append = false) {
        if (isLoadingPage) { console.warn("DSP Load Deals Skipped: Already loading page."); return; }
        console.log(`DSP Log: Starting loadDeals for page ${pageNum}. Append = ${append}`);

        const allSourceCheckboxes = container.find('.dsp-source-filter-cb');
        const checkedSourceCheckboxes = container.find('.dsp-source-filter-cb:checked');
        const contentWrapper = (currentView === 'table') ? tableWrapper : gridContainer;
        const containerToModify = (currentView === 'table') ? tableBody : gridContainer;

        if (!containerToModify.length) { console.error("DSP Error: Cannot find container to modify: ", currentView === 'table' ? 'tbody' : '#dsp-deals-grid'); return; }


        // --- Handle "No Sources Selected" ---
        if (allSourceCheckboxes.length > 0 && checkedSourceCheckboxes.length === 0) {
            console.log("DSP Log: No sources selected, clearing content and stopping.");
            if (contentWrapper.length) contentWrapper.removeClass('dsp-loading-overlay');
            const noSourceMsg = '<div class="dsp-no-deals-message">' + __(dsp_ajax_obj.no_sources_selected_text) + '</div>';
            const noSourceRow = '<tr class="dsp-no-deals-row"><td colspan="5">' + __(dsp_ajax_obj.no_sources_selected_text) + '</td></tr>';
            containerToModify.empty().html(currentView === 'table' ? noSourceRow : noSourceMsg);
            if (infiniteScrollLoader.length) infiniteScrollLoader.hide();
            totalItems = 0; currentPage = 1; canLoadMore = false;
            statusMessage.text(__(dsp_ajax_obj.no_sources_selected_text));
            updateStatusMessage();
            return; // Stop execution
        }

        isLoadingPage = true;
        if (append) {
            if (infiniteScrollLoader.length) infiniteScrollLoader.show(); else console.warn("DSP Warning: Infinite scroll loader missing for append operation.");
        } else {
            // Replacing content (page 1 or filter/sort change)
            console.log("DSP Log: Replacing content for page 1 / filter change.");
            currentPage = 1; // Ensure page number is reset
            canLoadMore = true; // Reset load more flag
            const loadingMsgHTML = (currentView === 'table')
                ? '<tr class="dsp-loading-row"><td colspan="5">' + __(dsp_ajax_obj.loading_text) + '</td></tr>'
                : '<div class="dsp-loading-message">' + __(dsp_ajax_obj.loading_text) + '</div>';
            containerToModify.empty().html(loadingMsgHTML); // Clear and show loading
            if (contentWrapper.length) contentWrapper.addClass('dsp-loading-overlay');
        }
        statusMessage.text(__(dsp_ajax_obj.loading_text)); // Set status to loading
        if (updateNoticeContainer.length) updateNoticeContainer.slideUp(100);

        // Gather filter/sort parameters
        const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = checkedSourceCheckboxes.map(function() { return $(this).val(); }).get(); const sortKey = currentSort.key; const sortReverse = currentSort.reverse; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPrice = minPriceInput.val(); const maxPrice = maxPriceInput.val();

        const requestData = { action: 'dsp_get_deals', nonce: dsp_ajax_obj.nonce, page: pageNum, items_per_page: itemsPerPage, orderby: sortKey, order: sortReverse ? 'DESC' : 'ASC', search: searchTerm, sources: activeSources, new_only: showNew ? 1 : 0, ltd_only: showLtdOnly ? 1 : 0, min_price: minPrice, max_price: maxPrice };
        console.log("DSP Log: AJAX Request Data:", requestData);

        $.ajax({
            url: dsp_ajax_obj.ajax_url, type: 'POST', data: requestData, timeout: 30000,
            success: function(response) {
                console.log("DSP Log: AJAX Success Response:", response);
                if (response.success && response.data) {
                    const fetchedDeals = response.data.deals || [];
                    totalItems = parseInt(response.data.total_items, 10) || 0;
                    itemsPerPage = parseInt(response.data.items_per_page, 10) || itemsPerPage;
                    currentPage = parseInt(response.data.current_page, 10) || pageNum;

                    if (!append) {
                        console.log("DSP Log: Replacing content with fetched deals.");
                        currentDeals = fetchedDeals;
                        renderContent(currentDeals);
                    } else {
                        console.log("DSP Log: Appending fetched deals.");
                        currentDeals = currentDeals.concat(fetchedDeals);
                        appendContent(fetchedDeals);
                    }

                    canLoadMore = (currentPage * itemsPerPage) < totalItems;
                    console.log(`DSP Log: Load success. Current Page: ${currentPage}, Total Items: ${totalItems}, Can Load More: ${canLoadMore}`);

                    updateLastUpdated(response.data.last_fetch);
                } else {
                    console.error("DSP Error: AJAX response indicates failure.", response);
                    if (!append) {
                        const errorMsgHTML = (currentView === 'table')
                            ? '<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + '</td></tr>'
                            : '<div class="dsp-error-message">' + __(dsp_ajax_obj.error_text) + '</div>';
                        containerToModify.empty().html(errorMsgHTML);
                    } else {
                         console.log("DSP Log: Error occurred during append, not clearing previous content.");
                    }
                    statusMessage.text(response.data?.message || __(dsp_ajax_obj.error_text)).addClass('dsp-error');
                    canLoadMore = false;
                    totalItems = 0;
                    currentDeals = [];
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error(`DSP Error: AJAX request failed! Status: ${textStatus}, Error: ${errorThrown}`, jqXHR);
                 if (!append) {
                    const errorMsgHTMLAjax = (currentView === 'table')
                        ? '<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + ' (AJAX)</td></tr>'
                        : '<div class="dsp-error-message">' + __(dsp_ajax_obj.error_text) + ' (AJAX)</div>';
                    containerToModify.empty().html(errorMsgHTMLAjax);
                } else {
                     console.log("DSP Log: AJAX Error occurred during append, not clearing previous content.");
                }
                statusMessage.text(__(dsp_ajax_obj.error_text) + ' (AJAX)').addClass('dsp-error');
                canLoadMore = false;
                totalItems = 0;
                currentDeals = [];
            },
            complete: function() {
                console.log("DSP Log: AJAX loadDeals complete.");
                isLoadingPage = false;
                if (infiniteScrollLoader.length) infiniteScrollLoader.hide();
                if (contentWrapper.length) contentWrapper.removeClass('dsp-loading-overlay');
                updateStatusMessage(); // Update status AFTER potentially modifying totalItems etc.
                 if (!canLoadMore && totalItems > 0) {
                    console.log("DSP Log: Reached end of deals.");
                 }
            }
        });
    }

    /** Updates the 'Last Updated' time display */
    function updateLastUpdated(dateTimeString) {
        const neverText = __(dsp_ajax_obj.never_text); let displayTime = neverText; if (dateTimeString && dateTimeString !== neverText) { displayTime = dateTimeString; }
        if(lastUpdatedTimeSpan.length) lastUpdatedTimeSpan.text(displayTime);
        if(lastUpdatedSpan.length) lastUpdatedSpan.css('visibility', displayTime === neverText ? 'hidden' : 'visible');
    }

    /** Creates a single <tr> jQuery element for a deal object */
    function createDealRowElement(deal) {
        // Ensure deal object is valid
        if (!deal || typeof deal !== 'object' || !deal.title || !deal.link || !deal.first_seen_formatted) { console.warn("DSP Warning: Cannot create table row for invalid deal object:", deal); return null; }

        const isNew = deal.is_new || false; const isLifetime = deal.is_lifetime || false; const firstSeenTimestamp = parseInt(deal.first_seen_ts, 10) || 0;
        const sortablePrice = deal.price_numeric ?? Infinity; // Use nullish coalescing

        // Create row and set data attributes
        const row = $('<tr></tr>').addClass('dsp-deal-row').attr({
            'data-source': deal.source || '', 'data-title': deal.title || '', 'data-description': deal.description || '',
            'data-is-new': isNew ? '1' : '0', 'data-is-ltd': isLifetime ? '1' : '0',
            'data-first-seen': firstSeenTimestamp, 'data-price': sortablePrice,
            'data-link': deal.link || '#', 'data-image-url': deal.image_url || '',
            'data-local-image-src': deal.local_image_src || '', 'data-attachment-id': deal.image_attachment_id || 0
        });

        // Add classes
        if (isNew) row.addClass('dsp-new-item');
        if (isLifetime) row.addClass('dsp-lifetime-item');

        // Determine even/odd dynamically based on current count in table body
        const existingRowCount = tableBody.find('tr.dsp-deal-row').length;
        row.addClass((existingRowCount + 1) % 2 === 0 ? 'dsp-even-row' : 'dsp-odd-row');

        // Append cells
        row.append($('<td></td>').addClass('dsp-cell-new').text(isNew ? __(dsp_ajax_obj.yes_text) : __(dsp_ajax_obj.no_text)));
        const titleCell = $('<td></td>').addClass('dsp-cell-title');
        const link = $('<a></a>').attr({href: deal.link || '#', target: '_blank', rel: 'noopener noreferrer'}).text(deal.title || 'N/A');
        titleCell.append(link);
        if (isLifetime) { titleCell.append(' <span class="dsp-lifetime-badge" title="' + __('Lifetime Deal') + '">LTD</span>'); }
        if (deal.description) { titleCell.append($('<p></p>').addClass('dsp-description').text(deal.description)); }
        row.append(titleCell);
        row.append($('<td></td>').addClass('dsp-cell-price').text(deal.price || 'N/A'));
        row.append($('<td></td>').addClass('dsp-cell-source').text(deal.source || 'N/A'));
        row.append($('<td></td>').addClass('dsp-cell-date').attr('data-timestamp', firstSeenTimestamp).text(deal.first_seen_formatted || 'N/A'));

        return row;
    }


    /** Creates a single <div> jQuery element for a grid item */
    function createGridItemElement(deal) {
        // Ensure deal object is valid
        if (!deal || typeof deal !== 'object' || !deal.title || !deal.link || !deal.first_seen_formatted) { console.warn("DSP Warning: Cannot create grid item for invalid deal object:", deal); return null; }

        const isNew = deal.is_new || false; const isLifetime = deal.is_lifetime || false; const firstSeenTimestamp = parseInt(deal.first_seen_ts, 10) || 0;
        const externalImageUrl = deal.image_url || '';
        const localImageSrc = deal.local_image_src || '';
        const displayImageUrl = localImageSrc || externalImageUrl; // Prioritize local URL
        const sortablePrice = deal.price_numeric ?? Infinity; // Use nullish coalescing

        // Create grid item div and set data attributes
        const gridItem = $('<div></div>').addClass('dsp-grid-item').attr({
            'data-source': deal.source || '', 'data-title': deal.title || '', 'data-description': deal.description || '',
            'data-is-new': isNew ? '1' : '0', 'data-is-ltd': isLifetime ? '1' : '0',
            'data-first-seen': firstSeenTimestamp, 'data-price': sortablePrice,
            'data-link': deal.link || '#', 'data-image-url': externalImageUrl,
            'data-local-image-src': localImageSrc, 'data-attachment-id': deal.image_attachment_id || 0
        });

        // Add classes
        if (isNew) gridItem.addClass('dsp-new-item');
        if (isLifetime) gridItem.addClass('dsp-lifetime-item');

        // Image container
        const imageContainer = $('<div></div>').addClass('dsp-grid-item-image');
        const imageLink = $('<a></a>').attr({href: deal.link || '#', target: '_blank', rel: 'noopener noreferrer'});
        if (displayImageUrl) {
            imageLink.append($('<img>').attr({src: displayImageUrl, alt: deal.title || '', loading: 'lazy'}));
        } else {
            imageLink.append($('<span></span>').addClass('dsp-image-placeholder')); // Placeholder if no image
        }
        imageContainer.append(imageLink);
        gridItem.append(imageContainer);

        // Content container
        const contentContainer = $('<div></div>').addClass('dsp-grid-item-content');
        const titleH3 = $('<h3></h3>').addClass('dsp-grid-item-title');
        const titleLink = $('<a></a>').attr({href: deal.link || '#', target: '_blank', rel: 'noopener noreferrer'}).text(deal.title || 'N/A');
        titleH3.append(titleLink);
        if (isLifetime) { titleH3.append(' <span class="dsp-lifetime-badge" title="' + __('Lifetime Deal') + '">LTD</span>'); }
        contentContainer.append(titleH3);
        const metaDiv = $('<div></div>').addClass('dsp-grid-item-meta');
        metaDiv.append($('<span></span>').addClass('dsp-grid-item-price').text(deal.price || 'N/A'));
        metaDiv.append(' | ');
        metaDiv.append($('<span></span>').addClass('dsp-grid-item-source').text(deal.source || 'N/A'));
        metaDiv.append(' | ');
        metaDiv.append($('<span></span>').addClass('dsp-grid-item-date').text(deal.first_seen_formatted || 'N/A'));
        if (isNew) { metaDiv.append($('<span></span>').addClass('dsp-grid-item-new').text(__(' (New)'))); }
        contentContainer.append(metaDiv);
        if (deal.description) { contentContainer.append($('<p></p>').addClass('dsp-grid-item-description').text(deal.description.substring(0, 100) + (deal.description.length > 100 ? '...' : ''))); } // Trim description
        gridItem.append(contentContainer);

        return gridItem;
    }

    /** Renders the initial content (replacing existing) */
    function renderContent(dealsToDisplay) {
        console.log("DSP Log: renderContent called.");
        const noDealsMatchingText = __(dsp_ajax_obj.no_deals_found_text);
        const containerToRender = (currentView === 'table') ? tableBody : gridContainer;
        if (!containerToRender.length) { console.error("DSP Error: Container for renderContent not found."); return; }

        const noDealsHTML = (currentView === 'table')
            ? '<tr class="dsp-no-deals-row"><td colspan="5">' + noDealsMatchingText + '</td></tr>'
            : '<div class="dsp-no-deals-message">' + noDealsMatchingText + '</div>';

        containerToRender.empty(); // Clear previous content

        if (!dealsToDisplay || dealsToDisplay.length === 0) {
            console.log("DSP Log: No deals to display, showing message.");
            containerToRender.html(noDealsHTML);
        } else {
            console.log(`DSP Log: Rendering ${dealsToDisplay.length} deals.`);
            appendContent(dealsToDisplay); // Use append logic
        }
    }

    /** Appends deals to the container */
    function appendContent(dealsToAppend) {
         console.log(`DSP Log: appendContent called with ${dealsToAppend ? dealsToAppend.length : 0} deals.`);
         if (!dealsToAppend || dealsToAppend.length === 0) {
            return; // Nothing to append
        }
        const containerToAppendTo = (currentView === 'table') ? tableBody : gridContainer;
        if (!containerToAppendTo.length) { console.error("DSP Error: Container for appendContent not found."); return; }

         // Remove 'no deals' or 'loading' message if it exists before appending
        containerToAppendTo.find('.dsp-no-deals-row, .dsp-no-deals-message, .dsp-loading-row, .dsp-loading-message').remove();

         dealsToAppend.forEach((deal) => {
            const element = (currentView === 'table')
                ? createDealRowElement(deal)
                : createGridItemElement(deal);
            if (element) {
                containerToAppendTo.append(element);
            } else {
                 console.warn("DSP Warning: Failed to create element for deal:", deal);
            }
        });
        console.log("DSP Log: Finished appending deals.");
    }

    /** Updates the debug log display */
    function updateDebugLog(logMessages) { const noLogText = __(dsp_ajax_obj['No debug log available.']); if (!logContainer.length || !logPre.length) return; if (logMessages && Array.isArray(logMessages) && logMessages.length > 0) { const escaped = logMessages.map(msg => $('<div>').text(msg).html()); logPre.html(escaped.join('\n')); } else { logPre.text(noLogText); } }

    /** Applies filters/sorting by fetching page 1 and replacing content */
    function applyFiltersAndSort() {
        if (!initialLoadComplete) { console.log("DSP Log: applyFiltersAndSort called before init complete, skipping."); return; }
        console.log("DSP Log: Filters/Sort changed, resetting and fetching Page 1...");
        const sortKey = currentSort.key; const sortReverse = currentSort.reverse;
        // Update visual sort indicators (only if table view)
        if (currentView === 'table' && tableHead.length) {
            tableHead.find('th').removeClass('dsp-sort-asc dsp-sort-desc');
            tableHead.find(`th[data-sort-key="${sortKey}"]`).addClass(sortReverse ? 'dsp-sort-desc' : 'dsp-sort-asc');
        }
        // Reset state and fetch page 1, replacing content
        currentPage = 1; // Explicitly reset page number
        canLoadMore = true; // Reset load more flag
        loadDeals(1, false); // false = replace content
    }

    // --- Helper Functions (getSortValue, parsePriceForSort, debounce, __) ---
    // These functions remain unchanged from the previous version.
    function getSortValue(deal, key) { if (!deal) return ''; switch (key) { case 'is_new': return deal.is_new ? 1 : 0; case 'title': return (deal.title || '').toLowerCase(); case 'price': return parsePriceForSort(deal.price || ''); case 'price_numeric': return deal.price_numeric ?? Infinity; case 'source': return (deal.source || '').toLowerCase(); case 'first_seen': return parseInt(deal.first_seen_ts, 10) || 0; case 'is_ltd': return deal.is_lifetime ? 1: 0; default: return ''; } }
    function parsePriceForSort(priceStr) { const inf = Infinity; if (priceStr === null || typeof priceStr === 'undefined') return inf; priceStr = String(priceStr).toLowerCase().trim(); if (priceStr === '' || priceStr === 'n/a') return inf; if (['free', 'freebie', '0', '0.00'].includes(priceStr) || priceStr.startsWith('$0') || priceStr.startsWith('€0') || priceStr.startsWith('£0')) return 0; const clean = priceStr.replace(/[^0-9.-]+/g,""); const match = clean.match(/^-?\d+(\.\d+)?/); if(match){ return parseFloat(match[0]); } return inf; }
    function debounce(func, wait) { let timeoutId = null; return function(...args) { clearTimeout(timeoutId); timeoutId = setTimeout(() => { func.apply(this, args); }, wait); }; }

    /** Simple translation placeholder */
    function __(text) {
        // Find the most likely key in dsp_ajax_obj based on the English text
        const potentialKey = text.toLowerCase().replace(/[^a-z0-9]/g, '_') + '_text';
        if (dsp_ajax_obj && typeof dsp_ajax_obj[potentialKey] !== 'undefined') {
            return dsp_ajax_obj[potentialKey];
        }
        // Fallback check for exact text match (less common now)
        if (dsp_ajax_obj && typeof dsp_ajax_obj[text] !== 'undefined') {
             return dsp_ajax_obj[text];
        }
        // Specific fallback for dynamic strings not easily keyed
        if (text === 'Showing %d deals') {
             return dsp_ajax_obj['showing__d_deals_text'] || text; // Need to add showing__d_deals_text to localization if used
        }
         if (text === 'Lifetime Deal') { // Common title attribute text
             return dsp_ajax_obj['lifetime_deal_text'] || text; // Need to add lifetime_deal_text
        }

        // Add more specific fallbacks if needed...

        // If still not found, return original text and warn
        console.warn("DSP Translation missing for:", text, "(Potential key:", potentialKey + ")");
        return text;
    }


    /** Updates the main status message bar */
    function updateStatusMessage() {
        if (isLoadingPage || isRefreshing || isSubscribing) return; // Don't update during operations
        if (!statusMessage.length) { console.error("DSP Error: Status message element not found."); return; }

        const totalDealCount = totalItems;
        const displayedCount = currentDeals.length; // Use the count from the JS array
        let statusText = '';

        console.log(`DSP Log: updateStatusMessage - Total: ${totalDealCount}, Current Deals: ${displayedCount}, InitialLoad: ${initialLoadComplete}`);

        if (totalDealCount > 0) {
            // Simple approach for infinite scroll: show total found.
            statusText = __( "Showing %d deals" ).replace( '%d', totalDealCount ); // Use the direct replacement method
        } else if (initialLoadComplete) {
            const allSourceCB = container.find('.dsp-source-filter-cb');
            const checkedSourceCB = container.find('.dsp-source-filter-cb:checked');
             if (allSourceCB.length > 0 && checkedSourceCB.length === 0) {
                 statusText = __(dsp_ajax_obj.no_sources_selected_text);
             } else {
                statusText = __(dsp_ajax_obj.no_deals_found_text);
             }
        } else {
            statusText = __(dsp_ajax_obj.loading_text);
        }

        // Append filter info (same logic as before)
        const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(); const totalSources = dsp_ajax_obj.config_sources?.length || 0; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPrice = minPriceInput.val(); const maxPrice = maxPriceInput.val(); const filterParts = [];
        if (searchTerm) filterParts.push(sprintf('%s: \'%s\'', __('Search'), searchTerm)); // sprintf is fine for simple strings
        if (totalSources > 0 && activeSources.length < totalSources) filterParts.push(__('Sources filtered'));
        if (showNew) filterParts.push(__('New only'));
        if (showLtdOnly) filterParts.push(__('LTD Only'));
        if (minPrice !== '' || maxPrice !== '') { let priceText = __('Price') + ': '; if (minPrice !== '' && maxPrice !== '') priceText += `$${minPrice}-$${maxPrice}`; else if (minPrice !== '') priceText += `$${minPrice}+`; else if (maxPrice !== '') priceText += `${__('Up to')} $${maxPrice}`; filterParts.push(priceText); }
        if (filterParts.length > 0 && totalDealCount > 0) { statusText += ` | ${__('Filters')}: ${filterParts.join(', ')}`; }

        statusMessage.text(statusText).removeClass('dsp-error dsp-success');
        console.log("DSP Log: Status Message Updated:", statusText);
    }

    /** Basic sprintf implementation */
     function sprintf(format, ...args) {
         // Very basic implementation for %s and %d only
         let i = 0;
         return format.replace(/%[sd]/g, (match) => {
             const replacement = args[i++];
             if (match === '%d') {
                 return parseInt(replacement, 10);
             }
             return String(replacement);
         });
     }

    /** Handles submission of the subscription form */
    function handleSubscriptionSubmit(event) { event.preventDefault(); if (isSubscribing || !subscribeModal.length || !subscribeEmailInput.length) return; const email = subscribeEmailInput.val().trim(); const enterEmailText = __(dsp_ajax_obj.subscribe_enter_email); const invalidFormatText = __(dsp_ajax_obj.subscribe_invalid_email_format); if (!email) { subscribeMessage.text(enterEmailText).removeClass('dsp-success').addClass('dsp-error').show(); return; } if (!/^\S+@\S+\.\S+$/.test(email)) { subscribeMessage.text(invalidFormatText).removeClass('dsp-success').addClass('dsp-error').show(); return; } isSubscribing = true; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', true); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'visible'); if(subscribeMessage.length) subscribeMessage.text('').removeClass('dsp-success dsp-error').hide(); $.ajax({ url: dsp_ajax_obj.ajax_url, type:'POST', data: { action:'dsp_subscribe_email', nonce: dsp_ajax_obj.nonce, email: email }, timeout: 15000, success: function(response) { if (response.success && response.data?.message) { if(subscribeMessage.length) subscribeMessage.text(response.data.message).removeClass('dsp-error').addClass('dsp-success').show(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); setTimeout(function(){ if(subscribeModal.is(':visible')) subscribeModal.fadeOut(200); }, 3000); } else { const errorMsg = response.data?.message || __(dsp_ajax_obj.subscribe_error_generic); if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show(); } }, error: function(jqXHR, textStatus, errorThrown) { console.error("DSP Sub AJAX Error:", textStatus, errorThrown); let errorMsg = __(dsp_ajax_obj.subscribe_error_network); if (jqXHR.responseJSON?.data?.message) errorMsg = jqXHR.responseJSON.data.message; if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show(); }, complete: function() { isSubscribing = false; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', false); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'hidden'); } }); }

    /** Function to check scroll position and load more */
    function checkScrollAndLoad() {
        const containerToCheck = (currentView === 'table') ? tableBody : gridContainer;
        if (!canLoadMore || isLoadingPage || !containerToCheck.length || containerToCheck.children().length === 0) { // Added check for empty container
            // console.log(`DSP Scroll Check Skip: CanLoadMore: ${canLoadMore}, IsLoading: ${isLoadingPage}, Children: ${containerToCheck.children().length}`);
            return;
        }

        // Use document height for more reliable check across layouts
        const scrollPosition = $(window).scrollTop() + $(window).height();
        const documentHeight = $(document).height();

        if (scrollPosition >= documentHeight - scrollThreshold) {
            console.log("DSP Log: Reached scroll threshold. Loading next page...");
            loadDeals(currentPage + 1, true); // true = append content
        }
    }

    /** Binds all event listeners */
    function bindEvents() {
        console.log("DSP Log: Binding events...");
        // Filter Controls
        searchInput.on('input', applyFiltersDebounced); searchInput.on('keydown', function(e) { if (e.key === 'Enter' || e.keyCode === 13) { e.preventDefault(); applyFiltersAndSort(); } }); newOnlyCheckbox.on('change', applyFiltersAndSort); sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', applyFiltersAndSort); ltdOnlyCheckbox.on('change', applyFiltersAndSort); minPriceInput.on('keyup', debounce(applyFiltersAndSort, 500)); maxPriceInput.on('keyup', debounce(applyFiltersAndSort, 500)); minPriceInput.on('change', applyFiltersAndSort); maxPriceInput.on('change', applyFiltersAndSort);

        // Sorting Controls (Only if table view)
        if (currentView === 'table' && tableHead.length) {
            tableHead.find('th[data-sort-key]').on('click', function () { if (isLoadingPage || isRefreshing) return; const th = $(this); const newSortKey = th.data('sort-key'); if(!newSortKey) return; if (currentSort.key === newSortKey) { currentSort.reverse = !currentSort.reverse; } else { currentSort.key = newSortKey; currentSort.reverse = ['first_seen', 'is_new', 'is_ltd', 'price_numeric'].includes(newSortKey); } applyFiltersAndSort(); });
        } else { if(tableHead.length) tableHead.hide(); }

        // Debug Log Button
        if (toggleLogButton.length && logContainer.length && dsp_ajax_obj.show_debug_button && container.find('#dsp-toggle-debug-log').length > 0 ) {
            toggleLogButton.on('click', function () { const button = $(this); const showTxt = __(dsp_ajax_obj.show_log_text); const hideTxt = __(dsp_ajax_obj.hide_log_text); logContainer.slideToggle(200, function() { button.text(logContainer.is(':visible') ? hideTxt : showTxt); }); });
        } else { if(logContainer.length) logContainer.hide(); if(toggleLogButton.length) toggleLogButton.hide(); }

        // Refresh Button
        if (refreshButton.length && container.find('#dsp-refresh-button').length > 0) {
            refreshButton.on('click', function () { if (isRefreshing || isLoadingPage) return; isRefreshing = true; console.log("DSP Log: Refresh button clicked."); const refreshingText = __(dsp_ajax_obj.refreshing_text); const refreshFinishedText = __(dsp_ajax_obj.refresh_finished_text); const refreshFailedInvalidResp = __(dsp_ajax_obj.error_refresh_invalid_resp_text); const refreshFailedAjax = __(dsp_ajax_obj.error_refresh_ajax_text); const button = $(this); button.prop('disabled', true); if(refreshSpinner.length) refreshSpinner.css('visibility', 'visible'); if(refreshMessage.length) refreshMessage.text(refreshingText).removeClass('dsp-error dsp-success'); if(logPre.length && dsp_ajax_obj.show_debug_button) logPre.text('Running manual refresh...'); statusMessage.text(refreshingText).removeClass('dsp-success dsp-error'); const contentWrapper = (currentView === 'table') ? tableWrapper : gridContainer; if(contentWrapper.length) contentWrapper.addClass('dsp-loading-overlay'); $.ajax({ url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce }, timeout: 180000, success: function (response) { console.log("DSP Log: Manual refresh response:", response); let message = refreshFailedInvalidResp; let messageType = 'dsp-error'; if (response.success && response.data) { totalItems = parseInt(response.data.total_items, 10) || 0; const allDealsFromServer = response.data.deals || []; updateLastUpdated(response.data.last_fetch); if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(response.data.log); message = response.data.message || refreshFinishedText; messageType = message.toLowerCase().includes('error') || message.toLowerCase().includes('fail') ? 'dsp-error' : 'dsp-success'; currentPage = 1; canLoadMore = (itemsPerPage > 0 && totalItems > itemsPerPage); currentDeals = allDealsFromServer.slice(0, itemsPerPage); renderContent(currentDeals); } else { console.error("DSP Error: Refresh AJAX call failed.", response); const logData = response.data?.log || [message]; if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(logData); message = response.data?.message || message; statusMessage.text(message).addClass('dsp-error'); renderContent([]); totalItems = 0; currentDeals = []; canLoadMore = false; } if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-error dsp-success').addClass(messageType); }, error: function (jqXHR, textStatus, errorThrown) { console.error("DSP Error: AJAX Refresh Request Failed:", textStatus, errorThrown, jqXHR.responseJSON); let errorMsg = refreshFailedAjax; let logData = [errorMsg, `Status: ${textStatus}`, `Error: ${errorThrown}`]; if (jqXHR.responseJSON?.data) { errorMsg = jqXHR.responseJSON.data.message || errorMsg; logData = jqXHR.responseJSON.data.log || logData; } if (jqXHR.responseText) { logData.push("Raw Response Snippet: " + jqXHR.responseText.substring(0, 500)); } if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(logData); if(refreshMessage.length) refreshMessage.text(errorMsg).addClass('dsp-error'); statusMessage.text(errorMsg).addClass('dsp-error'); renderContent([]); totalItems = 0; currentDeals = []; canLoadMore = false; }, complete: function () { isRefreshing = false; button.prop('disabled', false); if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden'); if(refreshMessage.length) { const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000; setTimeout(() => { refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay); } if(contentWrapper.length) contentWrapper.removeClass('dsp-loading-overlay'); updateStatusMessage(); console.log("DSP Log: Refresh complete."); } }); });
        }

        // Donate Modal (Same as before)
        if (donateButton.length && donateModal.length) { donateButton.on('click', (e)=>{ e.preventDefault(); donateModal.fadeIn(200); }); donateModalClose.on('click', (e)=>{ e.preventDefault(); donateModal.fadeOut(200); }); donateModal.on('click', (e)=>{ if($(e.target).is(donateModal)) donateModal.fadeOut(200); }); $(document).on('keydown', (e)=>{ if(e.key==="Escape" && donateModal.is(':visible')) donateModal.fadeOut(200); }); donateModal.find('.dsp-copy-code').on('click', function() { const codeElement = $(this); const address = codeElement.text(); const feedback = donateModal.find('.dsp-copy-feedback'); navigator.clipboard.writeText(address).then(function() { feedback.text('Address copied!').fadeIn(); setTimeout(() => feedback.fadeOut(), 2000); }, function(err) { feedback.text('Failed to copy.').fadeIn(); setTimeout(() => feedback.fadeOut(), 2000); console.error('DSP Copy Failed: ', err); }); }); }

        // Subscribe Modal (Same as before)
        if (subscribeButton.length && subscribeModal.length && dsp_ajax_obj.email_notifications_enabled) { subscribeButton.show(); subscribeButton.on('click', (e)=>{ e.preventDefault(); if(subscribeMessage.length) subscribeMessage.text('').hide(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); subscribeModal.fadeIn(200); if(subscribeEmailInput.length) subscribeEmailInput.focus(); }); if(subscribeSubmitButton.length) subscribeSubmitButton.on('click', handleSubscriptionSubmit); if(subscribeEmailInput.length) subscribeEmailInput.on('keypress', (e)=>{ if(e.which === 13) handleSubscriptionSubmit(e); }); if(subscribeModalClose.length) subscribeModalClose.on('click', (e)=>{ e.preventDefault(); subscribeModal.fadeOut(200); }); subscribeModal.on('click', (e)=>{ if($(e.target).is(subscribeModal)) subscribeModal.fadeOut(200); }); $(document).on('keydown', (e)=>{ if(e.key==="Escape" && subscribeModal.is(':visible') && !donateModal.is(':visible')) subscribeModal.fadeOut(200); }); } else { if (subscribeButton.length) subscribeButton.hide(); }

        // --- Infinite Scroll Listener ---
        $(window).off('scroll.dspInfiniteScroll').on('scroll.dspInfiniteScroll', debounce(checkScrollAndLoad, 250)); // Ensure only one listener is bound
        console.log("DSP Log: Infinite scroll listener bound.");

        // Background Update Notice Dismiss (Same as before)
        if(updateNoticeContainer.length) { updateNoticeContainer.on('click', '.dsp-dismiss-notice', function(e){ e.preventDefault(); updateNoticeContainer.slideUp(200, function(){ $(this).empty(); }); }); }

        console.log("DSP Log: Event binding complete.");
    } // End bindEvents

    // --- Run ---
    init(); // Start the process

}); // End jQuery Ready