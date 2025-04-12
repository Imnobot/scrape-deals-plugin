// File: assets/js/deal-display.js (v1.1.26 - Remove JS dark mode init)

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
        // REMOVED: applyInitialDarkMode(); // Removed - Handled by inline script in <head>
        createSourceCheckboxes();
        parseInitialTable(); // Parse deals rendered by PHP
        renderPagination(); // Render pagination based on initial totalItems
        bindEvents();
        updateStatusMessage(); // Update status based on initial parse
        if(updateNoticeContainer.length) updateNoticeContainer.hide();
        initialLoadComplete = true; // Mark initial setup as done
        console.log("DSP: Init complete. Event listeners bound.");
    }

    // REMOVED: applyInitialDarkMode() function
    // REMOVED: applyAutoDarkMode() function

    /** Creates source filter checkboxes */
    function createSourceCheckboxes() {
        sourceCheckboxesContainer.empty(); // Clear existing
        const sources = dsp_ajax_obj.config_sources || [];
        if (sources.length > 0) {
            sources.forEach(s => {
                if (typeof s !== 'string' || s === '') return; // Skip invalid sources
                const id = 'dsp-source-' + s.toLowerCase().replace(/[^a-z0-9]/g, ''); // Basic sanitize for ID
                const escapedSourceName = $('<div>').text(s).html(); // Escape name for HTML
                const checkboxHTML = `<label for="${id}" class="dsp-source-label"><input type="checkbox" id="${id}" class="dsp-source-filter-cb" value="${s}" checked> ${escapedSourceName}</label>`;
                sourceCheckboxesContainer.append(checkboxHTML);
            });
        } else {
             // Optionally add a message if no sources are configured/enabled
             // sourceCheckboxesContainer.append('<span class="dsp-no-sources-configured">' + __('No sources available.') + '</span>');
        }
    }

    /** Parses the initial HTML table (Page 1) to populate allDealsData */
    function parseInitialTable() {
        console.log("DSP: Parsing initial table data (Page 1)...");
        allDealsData = []; // Reset
        tableBody.find('tr.dsp-deal-row').each(function() {
            const row = $(this);
            const firstSeenTimestamp = parseInt(row.data('first-seen'), 10) || 0;
            const deal = {
                link: row.data('link') || '#',
                title: row.data('title') || '',
                price: row.find('.dsp-cell-price').text() || '',
                source: row.data('source') || '',
                description: row.data('description') || '',
                first_seen_ts: firstSeenTimestamp,
                first_seen_formatted: row.find('.dsp-cell-date').text().trim() || 'N/A',
                is_new: row.data('is-new') == '1',
                is_lifetime: row.hasClass('dsp-lifetime-item'), // Get LTD status from class initially
            };
            // Ensure data attribute for LTD is set for filtering consistency
            row.attr('data-is-ltd', deal.is_lifetime ? '1' : '0');
            // Basic validation
            if (deal.link !== '#' && deal.title && deal.first_seen_ts > 0) {
                allDealsData.push(deal);
            } else {
                console.warn("DSP: Skipped parsing initial row due to missing data:", row, deal);
            }
        });

        // Handle removing initial loading/no deals row IF deals were actually parsed
        if (allDealsData.length > 0) {
            const initialPlaceholder = tableBody.find('.dsp-loading-row, .dsp-no-deals-row');
            if (initialPlaceholder.length > 0) {
                initialPlaceholder.remove();
                console.log("DSP: Removed initial loading/no deals row as deals were parsed.");
            }
        } else {
            // If no deals parsed, check what PHP rendered
             if (tableBody.find('.dsp-loading-row').length > 0){
                 console.log("DSP: Keeping initial loading row as no deals parsed.");
             } else if (tableBody.find('.dsp-no-deals-row').length > 0){
                  console.log("DSP: Keeping initial 'no deals yet' row.");
             }
        }
        console.log(`DSP: Parsed ${allDealsData.length} initial deals from PHP.`);
    }

    /** Fetches a specific page of deals via AJAX */
    function fetchDealsPage(pageNum) {
        if (isLoadingPage) { return; }

        // Check if source checkboxes exist and none are checked
        const allSourceCheckboxes = container.find('.dsp-source-filter-cb');
        const checkedSourceCheckboxes = container.find('.dsp-source-filter-cb:checked');

        if (allSourceCheckboxes.length > 0 && checkedSourceCheckboxes.length === 0) {
            console.log("DSP: No sources selected, skipping AJAX fetch.");
            isLoadingPage = true; // Prevent overlaps while we update UI
            tableBody.empty().html('<tr class="dsp-no-deals-row"><td colspan="5">' + __(dsp_ajax_obj.no_sources_selected_text) + '</td></tr>');
            paginationControlsContainer.empty(); // Clear pagination
            totalItems = 0; // Reset total items count
            currentPage = 1; // Reset page number
            statusMessage.text(__(dsp_ajax_obj.no_sources_selected_text)); // Update status message
            isLoadingPage = false; // Allow future actions
            return; // Exit the function, don't make the AJAX call
        }

        // Proceed with fetching if the above condition wasn't met
        isLoadingPage = true;
        currentPage = pageNum;

        // Preserve scroll or set min-height for smoother loading
        let currentHeight = tableBody.height();
        if (currentHeight < 100) { currentHeight = 100; } // Minimum height
        tableBody.css('min-height', currentHeight + 'px');

        // Show loading state
        tableBody.html('<tr class="dsp-loading-row"><td colspan="5">' + __(dsp_ajax_obj.loading_text) + '</td></tr>');
        paginationControlsContainer.addClass('dsp-loading');
        statusMessage.text(__(dsp_ajax_obj.loading_text));
        if(updateNoticeContainer.length) updateNoticeContainer.slideUp(100); // Hide background update notice

        // Gather filter/sort parameters
        const searchTerm = searchInput.val().trim();
        const showNew = newOnlyCheckbox.is(':checked');
        const activeSources = checkedSourceCheckboxes.map(function() { return $(this).val(); }).get(); // Use already queried checked boxes
        const sortKey = currentSort.key;
        const sortReverse = currentSort.reverse;
        const showLtdOnly = ltdOnlyCheckbox.is(':checked');
        const minPrice = minPriceInput.val();
        const maxPrice = maxPriceInput.val();

        console.log(`DSP: Fetching page ${currentPage}... Sort: ${sortKey} ${sortReverse ? 'DESC' : 'ASC'}, Search: "${searchTerm}", New: ${showNew}, LTD: ${showLtdOnly}, Price: ${minPrice}-${maxPrice}, Sources: ${activeSources.join(',')}`);

        // Prepare AJAX request data
        const requestData = {
            action: 'dsp_get_deals', nonce: dsp_ajax_obj.nonce,
            page: currentPage, items_per_page: itemsPerPage,
            orderby: sortKey, order: sortReverse ? 'DESC' : 'ASC',
            search: searchTerm,
            sources: activeSources, // Send the array of selected source names
            new_only: showNew ? 1 : 0, ltd_only: showLtdOnly ? 1 : 0,
            min_price: minPrice, max_price: maxPrice
        };

        // Make AJAX request
        $.ajax({
            url: dsp_ajax_obj.ajax_url, type: 'POST', data: requestData, timeout: 30000, // 30 second timeout
            success: function(response) {
                console.log("DSP: fetchDealsPage response:", response);
                if (response.success && response.data) {
                    // Update state from successful response
                    allDealsData = response.data.deals || []; // Deals for the current page
                    totalItems = parseInt(response.data.total_items, 10) || 0;
                    itemsPerPage = parseInt(response.data.items_per_page, 10) || itemsPerPage;
                    currentPage = parseInt(response.data.current_page, 10) || currentPage;

                    // Render UI elements
                    renderTable(allDealsData);
                    renderPagination();
                    updateLastUpdated(response.data.last_fetch);
                } else {
                    // Handle non-success response from server
                    console.error("DSP: Error fetching deals page:", response);
                    tableBody.html('<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + '</td></tr>');
                    statusMessage.text(response.data?.message || __(dsp_ajax_obj.error_text)).addClass('dsp-error');
                    totalItems = 0; // Reset total if fetch failed
                    renderPagination(); // Clear pagination if total is 0
                }
                tableBody.css('min-height', ''); // Remove min-height after rendering
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Handle AJAX communication errors
                console.error("DSP: AJAX fetchDealsPage Error:", textStatus, errorThrown);
                tableBody.html('<tr class="dsp-error-row"><td colspan="5">' + __(dsp_ajax_obj.error_text) + ' (AJAX)</td></tr>');
                statusMessage.text(__(dsp_ajax_obj.error_text) + ' (AJAX)').addClass('dsp-error');
                totalItems = 0; // Reset total on AJAX error
                renderPagination(); // Clear pagination
                tableBody.css('min-height', ''); // Remove min-height
            },
            complete: function() {
                // Runs after success or error
                isLoadingPage = false;
                paginationControlsContainer.removeClass('dsp-loading');
                // Update status message *after* potentially updating totalItems
                updateStatusMessage();
            }
        });
    }

    /** Updates the 'Last Updated' time display */
    function updateLastUpdated(dateTimeString) {
        const neverText = __(dsp_ajax_obj.never_text);
        let displayTime = neverText;
        if (dateTimeString && dateTimeString !== neverText) {
            // Assuming dateTimeString is already formatted by PHP's date_i18n
            displayTime = dateTimeString;
        }
        lastUpdatedTimeSpan.text(displayTime);
        lastUpdatedSpan.css('visibility', displayTime === neverText ? 'hidden' : 'visible');
    }

    /** Creates a single <tr> jQuery element for a deal object */
    function createDealRowElement(deal) {
        // Basic validation of the deal object
        if (!deal || !deal.title || !deal.link || !deal.first_seen_formatted) {
            console.warn("DSP: Cannot create row for invalid deal object:", deal);
            return $(); // Return empty jQuery object
        }

        const isNew = deal.is_new || false;
        const isLifetime = deal.is_lifetime || false; // is_lifetime determined by PHP
        const firstSeenTimestamp = parseInt(deal.first_seen_ts, 10) || 0;

        const row = $('<tr></tr>')
            .addClass('dsp-deal-row')
            // Add data attributes for potential filtering/sorting or debugging
            .attr({
                'data-source': deal.source || '',
                'data-title': deal.title || '',
                'data-description': deal.description || '',
                'data-is-new': isNew ? '1' : '0',
                'data-is-ltd': isLifetime ? '1' : '0',
                'data-first-seen': firstSeenTimestamp,
                'data-price': parsePriceForSort(deal.price || ''), // Store sortable price
                'data-link': deal.link || '#' // Store link for reference
            });

        // Add conditional classes
        if (isNew) row.addClass('dsp-new-item');
        if (isLifetime) row.addClass('dsp-lifetime-item');

        // Create cells
        // New cell
        row.append($('<td></td>').addClass('dsp-cell-new').text(isNew ? __(dsp_ajax_obj.yes_text) : __(dsp_ajax_obj.no_text)));

        // Title cell
        const titleCell = $('<td></td>').addClass('dsp-cell-title');
        const link = $('<a></a>')
            .attr({
                href: deal.link,
                target: '_blank',
                rel: 'noopener noreferrer'
            })
            .text(deal.title); // Use text() for security
        titleCell.append(link);

        // Add LTD badge if applicable
        if (isLifetime) {
            titleCell.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>');
        }

        // Add description if present
        if (deal.description) {
            titleCell.append($('<p></p>').addClass('dsp-description').text(deal.description)); // Use text()
        }
        row.append(titleCell);

        // Price cell
        row.append($('<td></td>').addClass('dsp-cell-price').text(deal.price || 'N/A'));

        // Source cell
        row.append($('<td></td>').addClass('dsp-cell-source').text(deal.source || 'N/A'));

        // Date cell
        row.append($('<td></td>')
            .addClass('dsp-cell-date')
            .attr('data-timestamp', firstSeenTimestamp) // Store timestamp for potential JS use
            .text(deal.first_seen_formatted || 'N/A')
        );

        return row;
    }

    /** Renders the table body based on the provided deals array */
    function renderTable(dealsToDisplay) {
        const noDealsMatchingText = __(dsp_ajax_obj.no_deals_found_text);
        tableBody.empty(); // Clear previous content

        if (!dealsToDisplay || dealsToDisplay.length === 0) {
            // Display a "No deals found" message if the array is empty after filtering/fetching
            const noDealsRow = $('<tr></tr>')
                .addClass('dsp-no-deals-row') // Use a specific class
                .append( $('<td colspan="5"></td>').text(noDealsMatchingText) );
            tableBody.html(noDealsRow); // Replace content with the message row
            return; // Exit
        }

        // If deals exist, render them
        dealsToDisplay.forEach((deal, index) => {
            const rowElement = createDealRowElement(deal);
            // Add even/odd class for striping
            rowElement.addClass(index % 2 === 0 ? 'dsp-even-row' : 'dsp-odd-row');
            tableBody.append(rowElement);
        });
    }
    /** Renders the pagination controls */
    function renderPagination() {
        paginationControlsContainer.empty(); // Clear previous controls

        // Exit if pagination is not needed
        if (itemsPerPage <= 0 || totalItems <= itemsPerPage) {
            return;
        }

        const totalPages = Math.ceil(totalItems / itemsPerPage);
        if (totalPages <= 1) {
            return; // No need for pagination if only one page
        }

        let paginationHTML = '<ul class="dsp-page-numbers">';

        // Previous button
        paginationHTML += `<li class="dsp-page-item ${currentPage === 1 ? 'dsp-disabled' : ''}">`;
        if (currentPage > 1) {
            paginationHTML += `<a href="#" class="dsp-page-link dsp-prev" data-page="${currentPage - 1}">« ${__('Previous')}</a>`;
        } else {
            paginationHTML += `<span class="dsp-page-link dsp-prev">« ${__('Previous')}</span>`;
        }
        paginationHTML += '</li>';

        // Current page indicator
        paginationHTML += `<li class="dsp-page-item dsp-current-page"><span class="dsp-page-link">${sprintf(__(dsp_ajax_obj.page_text), currentPage, totalPages)}</span></li>`;

        // Next button
        paginationHTML += `<li class="dsp-page-item ${currentPage === totalPages ? 'dsp-disabled' : ''}">`;
        if (currentPage < totalPages) {
            paginationHTML += `<a href="#" class="dsp-page-link dsp-next" data-page="${currentPage + 1}">${__('Next')} »</a>`;
        } else {
            paginationHTML += `<span class="dsp-page-link dsp-next">${__('Next')} »</span>`;
        }
        paginationHTML += '</li>';

        paginationHTML += '</ul>';
        paginationControlsContainer.html(paginationHTML);
    }
    /** Updates the debug log display */
    function updateDebugLog(logMessages) {
        const noLogText = __('No debug log available.');
        if (!logContainer.length || !logPre.length) return; // Ensure elements exist

        if (logMessages && Array.isArray(logMessages) && logMessages.length > 0) {
            const escaped = logMessages.map(msg => $('<div>').text(msg).html()); // Escape each line
            logPre.html(escaped.join('\n')); // Join with newlines for <pre>
        } else {
            logPre.text(noLogText);
        }
    }

    /** Applies filters/sorting by fetching page 1 */
    function applyFiltersAndSort() {
        // Only proceed if initial load is complete to avoid race conditions
        if (!initialLoadComplete) {
            console.log("DSP: applyFiltersAndSort called before init complete, skipping.");
            return;
        }
        console.log("DSP: Filters/Sort changed, fetching Page 1...");

        // Update visual sort indicators on headers
        const sortKey = currentSort.key;
        const sortReverse = currentSort.reverse;
        tableHead.find('th').removeClass('dsp-sort-asc dsp-sort-desc');
        tableHead.find(`th[data-sort-key="${sortKey}"]`).addClass(sortReverse ? 'dsp-sort-desc' : 'dsp-asc'); // Fixed: dsp-sort-asc

        // Trigger fetch for the first page with new settings
        fetchDealsPage(1);
    }

    /** Gets a comparable value for sorting - Remains the same */
    function getSortValue(deal, key) {
        if (!deal) return '';
        switch (key) {
            case 'is_new': return deal.is_new ? 1 : 0;
            case 'title': return (deal.title || '').toLowerCase();
            case 'price': return parsePriceForSort(deal.price || '');
            case 'source': return (deal.source || '').toLowerCase();
            case 'first_seen': return parseInt(deal.first_seen_ts, 10) || 0;
            default: return '';
        }
    }
    /** Parses a price string into a sortable number - Remains the same */
    function parsePriceForSort(priceStr) {
        const inf = Infinity;
        if (priceStr === null || typeof priceStr === 'undefined') return inf;
        priceStr = String(priceStr).toLowerCase().trim();
        if (priceStr === '' || priceStr === 'n/a') return inf;
        // Handle 'free' variations
        if (['free', 'freebie', '0', '0.00'].includes(priceStr) || priceStr.startsWith('$0') || priceStr.startsWith('€0') || priceStr.startsWith('£0')) return 0;
        // Extract number
        const clean = priceStr.replace(/[^0-9.-]+/g,""); // Remove currency, commas etc.
        const match = clean.match(/^-?\d+(\.\d+)?/); // Find number
        if(match){ return parseFloat(match[0]); }
        return inf; // Return infinity if no number found
    }
    /** Debounce utility - Remains the same */
    function debounce(func, wait) {
        let t; return function(...a){ const l = () => { clearTimeout(t); func.apply(this,a); }; clearTimeout(t); t = setTimeout(l,wait); };
    }

    /** Simple translation placeholder - Improved Fallback */
     function __(text) {
         // Prefer direct key match if available
         if (dsp_ajax_obj && typeof dsp_ajax_obj[text] !== 'undefined') { return dsp_ajax_obj[text]; }
         // Fallback to constructing key (e.g., 'Page %d of %d' -> page_text)
         const key = text.toLowerCase().replace(/[^a-z0-9]/g, '_') + '_text';
         if (dsp_ajax_obj && typeof dsp_ajax_obj[key] !== 'undefined') { return dsp_ajax_obj[key]; }
         // If still not found, return original text and warn
         console.warn("DSP Translation missing for:", text);
         return text;
     }
     /** Updates the main status message bar */
     function updateStatusMessage() {
         // Don't update if an action is in progress and displaying its own status
         if (isLoadingPage || isRefreshing || isSubscribing) return;

         const totalDealCount = totalItems;
         const totalPages = itemsPerPage > 0 ? Math.ceil(totalDealCount / itemsPerPage) : 1;
         let statusText = '';

         // Determine the message based on total items
         if (totalDealCount === 0 && initialLoadComplete) {
             // Check if it's because no sources are selected
             const allSourceCB = container.find('.dsp-source-filter-cb');
             const checkedSourceCB = container.find('.dsp-source-filter-cb:checked');
             if (allSourceCB.length > 0 && checkedSourceCB.length === 0) {
                 statusText = __(dsp_ajax_obj.no_sources_selected_text);
             } else {
                 // It's 0 due to filters or empty database
                 statusText = __(dsp_ajax_obj.no_deals_found_text);
             }
         } else if (totalDealCount === 0 && !initialLoadComplete) {
             // Initial load state, PHP might have rendered loading/no deals
             statusText = __(dsp_ajax_obj.loading_text); // Or check if PHP rendered "no deals yet"
             if (tableBody.find('.dsp-no-deals-row').length > 0) {
                  statusText = __(dsp_ajax_obj.no_deals_yet_text);
             }
         } else if (totalDealCount > 0 && itemsPerPage > 0) {
             // Deals exist, show range and page info
             const firstItem = (currentPage - 1) * itemsPerPage + 1;
             const lastItem = Math.min(currentPage * itemsPerPage, totalDealCount);
             statusText = sprintf(__('Showing deals %d-%d of %d'), firstItem, lastItem, totalDealCount);
             if (totalPages > 1) {
                 statusText += ` (${sprintf(__(dsp_ajax_obj.page_text), currentPage, totalPages)})`;
             }
         } else if (totalDealCount > 0 && itemsPerPage <= 0) { // Case for showing all items
             statusText = sprintf(__('Showing %d deals'), totalDealCount);
         }

         // Gather current filter values
         const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(); const totalSources = dsp_ajax_obj.config_sources?.length || 0; const showLtdOnly = ltdOnlyCheckbox.is(':checked'); const minPrice = minPriceInput.val(); const maxPrice = maxPriceInput.val();

         // Append filter info if filters are active
         const filterParts = []; if (searchTerm) filterParts.push(sprintf('%s: \'%s\'', __('Search'), searchTerm)); if (totalSources > 0 && activeSources.length < totalSources) filterParts.push(__('Sources filtered')); if (showNew) filterParts.push(__('New only')); if (showLtdOnly) filterParts.push(__('LTD Only')); if (minPrice !== '' || maxPrice !== '') { let priceText = __('Price') + ': '; if (minPrice !== '' && maxPrice !== '') priceText += `$${minPrice}-$${maxPrice}`; else if (minPrice !== '') priceText += `$${minPrice}+`; else if (maxPrice !== '') priceText += `${__('Up to')} $${maxPrice}`; filterParts.push(priceText); }

         // Only add filter text if filters are active AND deals were found (or DB isn't empty)
         if (filterParts.length > 0 && totalDealCount > 0) {
             statusText += ` | ${__('Filters')}: ${filterParts.join(', ')}`;
         }

         statusMessage.text(statusText).removeClass('dsp-error dsp-success'); // Update text, clear status classes
      }
     /** Basic sprintf implementation */
     function sprintf(format, ...args) {
         let i = 0; return format.replace(/%[sd]/g, (match) => (match === '%d' ? parseInt(args[i++], 10) : String(args[i++])));
     }
    /** Handles submission of the subscription form */
    function handleSubscriptionSubmit(event) {
        event.preventDefault();
        if (isSubscribing || !subscribeModal.length || !subscribeEmailInput.length) return;

        const email = subscribeEmailInput.val().trim();
        const enterEmailText = __(dsp_ajax_obj.subscribe_enter_email);
        const invalidFormatText = __(dsp_ajax_obj.subscribe_invalid_email_format);

        // Basic validation
        if (!email) { subscribeMessage.text(enterEmailText).removeClass('dsp-success').addClass('dsp-error').show(); return; }
        if (!/^\S+@\S+\.\S+$/.test(email)) { subscribeMessage.text(invalidFormatText).removeClass('dsp-success').addClass('dsp-error').show(); return; }

        // UI feedback for submission start
        isSubscribing = true;
        if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', true);
        if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'visible');
        if(subscribeMessage.length) subscribeMessage.text('').removeClass('dsp-success dsp-error').hide();

        // AJAX call
        $.ajax({
            url: dsp_ajax_obj.ajax_url, type:'POST',
            data: { action:'dsp_subscribe_email', nonce: dsp_ajax_obj.nonce, email: email },
            timeout: 15000, // 15 second timeout
            success: function(response) {
                if (response.success && response.data?.message) {
                    if(subscribeMessage.length) subscribeMessage.text(response.data.message).removeClass('dsp-error').addClass('dsp-success').show();
                    if(subscribeEmailInput.length) subscribeEmailInput.val(''); // Clear input on success
                    // Auto-close modal after a delay
                    setTimeout(function(){ if(subscribeModal.is(':visible')) subscribeModal.fadeOut(200); }, 3000);
                } else {
                    // Handle server-side validation errors or other failures
                    const errorMsg = response.data?.message || __(dsp_ajax_obj.subscribe_error_generic);
                    if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                // Handle AJAX communication errors
                console.error("DSP Sub AJAX Error:", textStatus, errorThrown);
                let errorMsg = __(dsp_ajax_obj.subscribe_error_network);
                if (jqXHR.responseJSON?.data?.message) errorMsg = jqXHR.responseJSON.data.message; // Use specific error if available
                if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show();
            },
            complete: function() {
                // Reset UI state after success or error
                isSubscribing = false;
                if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', false);
                if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'hidden');
            }
        });
    }

    /** Binds all event listeners */
    function bindEvents() {
        // Filter Controls
        searchInput.on('keyup', debounce(applyFiltersAndSort, 400)); // Increased debounce slightly
        newOnlyCheckbox.on('change', applyFiltersAndSort);
        sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', applyFiltersAndSort);
        ltdOnlyCheckbox.on('change', applyFiltersAndSort);
        minPriceInput.on('keyup', debounce(applyFiltersAndSort, 500)); // Longer debounce for price typing
        maxPriceInput.on('keyup', debounce(applyFiltersAndSort, 500));
        minPriceInput.on('change', applyFiltersAndSort); // Trigger on blur/enter too
        maxPriceInput.on('change', applyFiltersAndSort);

        // Sorting Controls
        tableHead.find('th[data-sort-key]').on('click', function () {
            if (isLoadingPage || isRefreshing) return; // Prevent sorting during load/refresh
            const th = $(this);
            const newSortKey = th.data('sort-key');
            if(!newSortKey) return; // Ignore clicks if no sort key

            if (currentSort.key === newSortKey) {
                currentSort.reverse = !currentSort.reverse; // Toggle direction
            } else {
                currentSort.key = newSortKey;
                // Default sort direction (DESC for date/new, ASC otherwise)
                currentSort.reverse = ['first_seen', 'is_new'].includes(newSortKey);
            }
            applyFiltersAndSort(); // Fetch page 1 with new sort order
        });

        // Debug Log Button
        if (toggleLogButton.length && logContainer.length && dsp_ajax_obj.show_debug_button) {
            toggleLogButton.on('click', function () {
                const button = $(this);
                const showTxt = __(dsp_ajax_obj.show_log_text);
                const hideTxt = __(dsp_ajax_obj.hide_log_text);
                logContainer.slideToggle(200, function() { // Use callback for text change
                    button.text(logContainer.is(':visible') ? hideTxt : showTxt);
                });
            });
        } else {
             if(logContainer.length) logContainer.hide(); // Ensure hidden if button disabled
             if(toggleLogButton.length) toggleLogButton.hide(); // Hide button if disabled
        }

        // Refresh Button
        if (refreshButton.length) {
            refreshButton.on('click', function () {
                if (isRefreshing) return; // Prevent multiple clicks
                isRefreshing = true;
                // UI feedback
                const refreshingText = __(dsp_ajax_obj.refreshing_text);
                const refreshFinishedText = __(dsp_ajax_obj.refresh_finished_text);
                const refreshFailedInvalidResp = __(dsp_ajax_obj.error_refresh_invalid_resp_text);
                const refreshFailedAjax = __(dsp_ajax_obj.error_refresh_ajax_text);
                const button = $(this);
                button.prop('disabled', true);
                if(refreshSpinner.length) refreshSpinner.css('visibility', 'visible');
                if(refreshMessage.length) refreshMessage.text(refreshingText).removeClass('dsp-error dsp-success');
                if(logPre.length && dsp_ajax_obj.show_debug_button) logPre.text('Running manual refresh...'); // Only update log if visible
                statusMessage.text(refreshingText).removeClass('dsp-success dsp-error'); // Update main status

                // AJAX call
                $.ajax({
                    url: dsp_ajax_obj.ajax_url, type: 'POST',
                    data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce },
                    timeout: 180000, // 3 minute timeout for potentially long cron run
                    success: function (response) {
                        console.log("DSP: Manual refresh response:", response);
                        let message = refreshFailedInvalidResp; let messageType = 'dsp-error';
                        if (response.success && response.data) {
                            // Update state with full data set
                            totalItems = parseInt(response.data.total_items, 10) || 0;
                            currentPage = 1; // Reset to page 1 after refresh
                            const allDealsFromServer = response.data.deals || [];
                            updateLastUpdated(response.data.last_fetch); // Update last fetched time
                            if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(response.data.log); // Update log display

                            message = response.data.message || refreshFinishedText;
                            // Determine message type based on content (crude check)
                            messageType = message.toLowerCase().includes('error') || message.toLowerCase().includes('fail') ? 'dsp-error' : 'dsp-success';

                            // Update local data with the first page slice and render
                            allDealsData = allDealsFromServer.slice(0, itemsPerPage);
                            renderTable(allDealsData);
                            renderPagination(); // Re-render pagination based on new totalItems
                        } else {
                            // Handle non-success response
                            console.error("DSP Refresh Error:", response);
                            const logData = response.data?.log || [message];
                            if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(logData);
                            message = response.data?.message || message; // Use specific error if available
                            statusMessage.text(message).addClass('dsp-error');
                            renderTable([]); // Clear table on error
                            totalItems = 0; // Reset total
                            renderPagination(); // Clear pagination
                        }
                        // Update refresh message
                        if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-error dsp-success').addClass(messageType);
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                        // Handle AJAX communication error
                        console.error("DSP AJAX Refresh Error:", textStatus, errorThrown, jqXHR.responseJSON);
                        let errorMsg = refreshFailedAjax; let logData = [errorMsg, `Status: ${textStatus}`, `Error: ${errorThrown}`];
                        if (jqXHR.responseJSON?.data) { errorMsg = jqXHR.responseJSON.data.message || errorMsg; logData = jqXHR.responseJSON.data.log || logData; }
                        if (jqXHR.responseText) { logData.push("Raw Response Snippet: " + jqXHR.responseText.substring(0, 500)); }
                        // Update UI
                        if(logPre.length && dsp_ajax_obj.show_debug_button) updateDebugLog(logData);
                        if(refreshMessage.length) refreshMessage.text(errorMsg).addClass('dsp-error');
                        statusMessage.text(errorMsg).addClass('dsp-error');
                        renderTable([]); totalItems = 0; renderPagination(); // Clear table/pagination
                    },
                    complete: function () {
                        // Reset UI state
                        isRefreshing = false;
                        button.prop('disabled', false);
                        if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden');
                        if(refreshMessage.length) { // Clear refresh message after delay
                            const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000;
                            setTimeout(() => { refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay);
                        }
                        // Update main status message (important after potentially changing totalItems)
                        updateStatusMessage();
                    }
                });
            });
        }

        // Donate Modal
        if (donateButton.length && donateModal.length) {
            donateButton.on('click', (e)=>{ e.preventDefault(); donateModal.fadeIn(200); });
            donateModalClose.on('click', (e)=>{ e.preventDefault(); donateModal.fadeOut(200); });
            donateModal.on('click', (e)=>{ if($(e.target).is(donateModal)) donateModal.fadeOut(200); });
            $(document).on('keydown', (e)=>{ if(e.key==="Escape" && donateModal.is(':visible')) donateModal.fadeOut(200); });
            // Copy code functionality
            donateModal.find('.dsp-copy-code').on('click', function() {
                const codeElement = $(this);
                const address = codeElement.text();
                const feedback = donateModal.find('.dsp-copy-feedback');
                navigator.clipboard.writeText(address).then(function() {
                    feedback.text('Address copied!').fadeIn();
                    setTimeout(() => feedback.fadeOut(), 2000);
                }, function(err) {
                    feedback.text('Failed to copy.').fadeIn();
                     setTimeout(() => feedback.fadeOut(), 2000);
                    console.error('DSP Copy Failed: ', err);
                });
            });
        }

        // Subscribe Modal
        if (subscribeButton.length && subscribeModal.length && dsp_ajax_obj.email_notifications_enabled) {
            // Show subscribe button if feature enabled
            subscribeButton.show();
            // Open modal
            subscribeButton.on('click', (e)=>{ e.preventDefault(); if(subscribeMessage.length) subscribeMessage.text('').hide(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); subscribeModal.fadeIn(200); if(subscribeEmailInput.length) subscribeEmailInput.focus(); });
            // Handle submit button click
            if(subscribeSubmitButton.length) subscribeSubmitButton.on('click', handleSubscriptionSubmit);
            // Handle enter key in email input
            if(subscribeEmailInput.length) subscribeEmailInput.on('keypress', (e)=>{ if(e.which === 13) handleSubscriptionSubmit(e); });
            // Close modal button
            if(subscribeModalClose.length) subscribeModalClose.on('click', (e)=>{ e.preventDefault(); subscribeModal.fadeOut(200); });
            // Close on background click
            subscribeModal.on('click', (e)=>{ if($(e.target).is(subscribeModal)) subscribeModal.fadeOut(200); });
            // Close on escape key (only if subscribe modal is top-most)
            $(document).on('keydown', (e)=>{ if(e.key==="Escape" && subscribeModal.is(':visible') && !donateModal.is(':visible')) subscribeModal.fadeOut(200); });
        } else {
            // Hide subscribe button if feature disabled
             if (subscribeButton.length) subscribeButton.hide();
        }

        // Pagination Controls
        paginationControlsContainer.on('click', 'a.dsp-page-link', function(e) {
            e.preventDefault();
            const link = $(this);
            // Prevent action if disabled or already loading
            if (link.parent().hasClass('dsp-disabled') || isLoadingPage) { return; }
            const pageNum = parseInt(link.data('page'), 10);
            if (!isNaN(pageNum) && pageNum !== currentPage) {
                fetchDealsPage(pageNum); // Fetch the requested page
            }
        });

        // Background Update Notice Dismiss
        if(updateNoticeContainer.length) {
            updateNoticeContainer.on('click', '.dsp-dismiss-notice', function(e){
                e.preventDefault();
                updateNoticeContainer.slideUp(200, function(){ $(this).empty(); }); // Slide up and remove content
            });
        }
    } // End bindEvents

    // --- Run ---
    init(); // Start the process

}); // End jQuery Ready