// File: assets/js/deal-display.js (v1.1.3 - Instant Load Implementation)

jQuery(document).ready(function ($) {
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
    let allDealsData = []; // Populated by parsing, then updated by AJAX
    let currentSort = { key: 'first_seen', reverse: true }; // Default sort (matches initial PHP render)
    let isCheckingForDeals = false; // Prevent concurrent checks
    let isRefreshing = false; // Track manual refresh state
    let isSubscribing = false; // Track subscribe state

    // --- Initialization ---
    function init() {
        applyInitialDarkMode();   // Apply dark mode FIRST
        createSourceCheckboxes(); // Create filter UI
        parseInitialTable();      // Read PHP-rendered deals into memory
        applyFiltersAndSort();    // Apply initial sort/filter to parsed data & render
        bindEvents();             // Set up interactions
        checkForNewDeals();       // Trigger background check for new deals
    }

    /** Apply dark mode based on settings */
    function applyInitialDarkMode() {
        const mode = dsp_ajax_obj.dark_mode_default || 'light';
        if (mode === 'dark') { container.addClass('dsp-dark-mode'); }
        else if (mode === 'auto') { applyAutoDarkMode(); }
        else { container.removeClass('dsp-dark-mode'); }
    }

    /** Check time for 'auto' dark mode */
    function applyAutoDarkMode() {
        try {
            const currentHour = new Date().getHours(); const isNight = currentHour >= 18 || currentHour < 6;
            if (isNight) { container.addClass('dsp-dark-mode'); } else { container.removeClass('dsp-dark-mode'); }
        } catch (e) { console.error("DSP Auto Dark Mode Error:", e); container.removeClass('dsp-dark-mode'); }
    }

    /** Creates source filter checkboxes */
    function createSourceCheckboxes() {
        sourceCheckboxesContainer.empty();
        const sources = dsp_ajax_obj.config_sources || [];
        if (sources.length > 0) {
            sources.forEach(source => {
                if (typeof source !== 'string' || source === '') return;
                const checkboxId = 'dsp-source-' + source.toLowerCase().replace(/[^a-z0-9]/g, '');
                const escapedSource = $('<div>').text(source).html();
                const checkbox = `<label for="${checkboxId}" class="dsp-source-label"><input type="checkbox" id="${checkboxId}" class="dsp-source-filter-cb" value="${source}" checked> ${escapedSource}</label>`;
                sourceCheckboxesContainer.append(checkbox);
            });
        } else { sourceCheckboxesContainer.append($('<span></span>').text(__('No sources configured.'))); }
    }

    /**
     * Parses the initial HTML table rendered by PHP to populate allDealsData.
     */
    function parseInitialTable() {
        console.log("DSP: Parsing initial table data...");
        allDealsData = []; // Reset
        // Find rows rendered by PHP (they should have the class 'dsp-deal-row')
        tableBody.find('tr.dsp-deal-row').each(function() {
            const row = $(this);
            const deal = {
                // Extract data using data-* attributes set in PHP shortcode-handler
                link: row.find('.dsp-cell-title a').attr('href') || '#',
                title: row.data('title') || '',
                price: row.find('.dsp-cell-price').text() || '',
                source: row.data('source') || '',
                description: row.data('description') || '',
                // Convert stored timestamp back to ISO string for consistency (Date.parse needs ms)
                first_seen: row.data('first-seen') ? new Date(row.data('first-seen') * 1000).toISOString() : null,
                // Get pre-formatted date from cell text
                first_seen_formatted: row.find('.dsp-cell-date').text().trim() || 'N/A',
                // Get boolean flags
                is_new: row.data('is-new') == '1', // Compare against string '1'
                is_lifetime: row.hasClass('dsp-lifetime-item'), // Check class
            };
            // Basic validation - ensure essential data exists
            if (deal.link && deal.link !== '#' && deal.title && deal.first_seen) {
                allDealsData.push(deal);
            } else {
                console.warn("DSP: Skipped parsing a row due to missing data:", row, deal);
            }
        });
        // Remove the initial "No deals found..." row if it exists and we found deals
        if (allDealsData.length > 0) {
            tableBody.find('.dsp-no-deals-row').remove();
        }
        console.log(`DSP: Parsed ${allDealsData.length} initial deals from PHP.`);
        updateStatusMessage(); // Update status after parsing
    }


    /**
     * AJAX call to check for new deals in the background.
     * Called once on page load after initial render.
     */
    function checkForNewDeals() {
        if (isCheckingForDeals || isRefreshing) { console.log("DSP: Check/Refresh already in progress."); return; }

        isCheckingForDeals = true;
        statusMessage.text(__(dsp_ajax_obj.checking_text)).removeClass('dsp-error dsp-success');
        console.log("DSP: Starting background check for new deals...");

        $.ajax({
            url: dsp_ajax_obj.ajax_url,
            type: 'POST',
            data: {
                action: 'dsp_check_for_new_deals', // *** NEW AJAX ACTION ***
                nonce: dsp_ajax_obj.nonce
            },
            timeout: 180000, // 3 minute timeout for potentially long cron run
            success: function (response) {
                console.log("DSP: Background check response:", response);
                if (response.success && response.data) {
                    const newDeals = response.data.new_deals || [];
                    const allDeals = response.data.all_deals || []; // Get the full updated list

                    // Update the master data list
                    allDealsData = allDeals;

                    // Prepend only the new deals visually
                    if (newDeals.length > 0) {
                         console.log(`DSP: Found ${newDeals.length} new deals. Prepending...`);
                         // Prepend rows in reverse order so the newest appears first at the top
                         $(newDeals.reverse()).each(function() {
                             // Mark these specifically as new for the prepending logic
                             this.is_new = true; // Ensure the 'is_new' flag is true for rendering
                            const dealRow = createDealRowElement(this);
                            tableBody.prepend(dealRow);
                         });
                         // Highlight the newly added rows
                         tableBody.find('.dsp-deal-row:lt(' + newDeals.length + ')').addClass('dsp-new-item dsp-just-added');
                         setTimeout(() => tableBody.find('.dsp-just-added').removeClass('dsp-just-added'), 5000); // Remove highlight after 5s
                    } else {
                        console.log("DSP: No new deals found in background check.");
                    }

                    updateLastUpdated(response.data.last_fetch); // Update the displayed time
                    statusMessage.text(response.data.message || __(dsp_ajax_obj.check_complete_text)).removeClass('dsp-error').addClass('dsp-success'); // Use message from backend
                    applyFiltersAndSort(); // Re-apply filters/sort to the updated table

                } else {
                    // Handle case where success is false or data is missing
                    console.error("DSP Error checking deals:", response);
                    const errorMsg = response.data?.message || __(dsp_ajax_obj.error_check_text);
                    statusMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error');
                    // Don't call applyFiltersAndSort here, keep the initial data visible
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                console.error("DSP AJAX Check Error:", textStatus, errorThrown, jqXHR.responseJSON);
                let errorMsg = __(dsp_ajax_obj.error_check_ajax_text);
                 if (jqXHR.responseJSON?.data?.message) { errorMsg = jqXHR.responseJSON.data.message; }
                statusMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error');
            },
            complete: function () {
                isCheckingForDeals = false;
                console.log("DSP: Background check complete.");
                 // Update status message again after completion (unless an error occurred)
                if (!statusMessage.hasClass('dsp-error')) {
                     updateStatusMessage();
                 }
            }
        });
    }


    /** Updates the 'Last Updated' time display. */
    function updateLastUpdated(dateTimeString) {
        const neverText = __(dsp_ajax_obj.never_text); const prefix = __(dsp_ajax_obj.last_updated_text); let displayTime = neverText;
        if (dateTimeString && dateTimeString !== neverText) {
             try {
                // Attempt to parse potentially localized date string from PHP (using date_i18n)
                // This is tricky; direct parsing might fail. Best if PHP sent timestamp or ISO.
                // For now, just display the string PHP sent.
                displayTime = dateTimeString;
                // Example alternative if PHP sent timestamp:
                // const date = new Date(dateTimeString * 1000); // If PHP sent Unix timestamp
                // if (!isNaN(date)) displayTime = date.toLocaleString();
             } catch (e) { displayTime = dateTimeString + ' (Error)'; }
        }
        lastUpdatedTimeSpan.text(displayTime);
        lastUpdatedSpan.css('visibility', displayTime === neverText ? 'hidden' : 'visible');
    }

    /** Creates a single <tr> jQuery element for a deal object. */
    function createDealRowElement(deal) {
        // Basic validation
        if (!deal || !deal.title || !deal.link || !deal.first_seen_formatted) {
             console.warn("DSP: Cannot create row for invalid deal object:", deal);
             return $(); // Return empty jQuery object
        }

        const isNew = deal.is_new || false; // is_new here reflects if it was new in *this check* or parsed initially
        const isLifetime = deal.is_lifetime || false;
        let firstSeenTimestamp = 0;
        if (deal.first_seen) { try { firstSeenTimestamp = Date.parse(deal.first_seen) / 1000; if (isNaN(firstSeenTimestamp)) firstSeenTimestamp = 0; } catch(e){ firstSeenTimestamp = 0; } }

        const row = $('<tr></tr>')
            .addClass('dsp-deal-row') // Base class
            .attr({ // Use data attributes consistent with PHP output
                'data-source': deal.source || '',
                'data-title': deal.title || '',
                'data-description': deal.description || '',
                'data-is-new': isNew ? '1' : '0', // Store 1/0
                'data-first-seen': firstSeenTimestamp,
                'data-price': parsePriceForSort(deal.price || '')
             });

        // Add conditional classes
        if (isNew) row.addClass('dsp-new-item');
        if (isLifetime) row.addClass('dsp-lifetime-item');

        // --- Cells ---
        // New cell: Use Yes/No text from localization
        row.append($('<td></td>').addClass('dsp-cell-new').text(isNew ? __(dsp_ajax_obj.yes_text) : __(dsp_ajax_obj.no_text)));

        // Title/Desc cell
        const titleCell = $('<td></td>').addClass('dsp-cell-title');
        const link = $('<a></a>').attr({ href: deal.link, target: '_blank', rel: 'noopener noreferrer' }).text(deal.title);
        titleCell.append(link);
        if (isLifetime) titleCell.append(' <span class="dsp-lifetime-badge" title="Lifetime Deal">LTD</span>');
        if (deal.description) { titleCell.append($('<p></p>').addClass('dsp-description').text(deal.description)); }
        row.append(titleCell);

        // Price cell
        row.append($('<td></td>').addClass('dsp-cell-price').text(deal.price || 'N/A'));

        // Source cell
        row.append($('<td></td>').addClass('dsp-cell-source').text(deal.source || 'N/A'));

        // Date cell
        row.append($('<td></td>').addClass('dsp-cell-date').attr('data-timestamp', firstSeenTimestamp).text(deal.first_seen_formatted || 'N/A'));

        return row;
    }

    /** Renders the table body based on the provided deals array. */
    function renderTable(dealsToDisplay) {
        const noDealsText = __(dsp_ajax_obj.no_deals_found_text);
        tableBody.empty(); // Clear previous rows

        if (!dealsToDisplay || dealsToDisplay.length === 0) {
            // Use helper to safely create the "no deals" row
            const noDealsRow = $('<tr></tr>').addClass('dsp-no-deals-row').append(
                $('<td colspan="5"></td>').text(noDealsText) // Use text() for safety
            );
            tableBody.html(noDealsRow);
            return;
        }

        // Loop and append rows created by helper function
        dealsToDisplay.forEach((deal, index) => {
            const rowElement = createDealRowElement(deal);
            // Add even/odd class based on index in the currently displayed set
            rowElement.addClass(index % 2 === 0 ? 'dsp-even-row' : 'dsp-odd-row');
            tableBody.append(rowElement);
        });
    }

    /** Updates the debug log display */
    function updateDebugLog(logMessages) { /* ... same as before ... */
        const noLogText = 'No debug log available.'; if (!logContainer.length) return;
        if (logMessages && Array.isArray(logMessages) && logMessages.length > 0) { const escaped = logMessages.map(msg => $('<div>').text(msg).html()); logPre.html(escaped.join('\n')); }
        else { logPre.text(noLogText); }
    }

    /** Applies current filters and sorting to allDealsData and renders the table. */
    function applyFiltersAndSort() {
        console.log("DSP: Applying filters and sort...");
        const searchTerm = searchInput.val().toLowerCase().trim();
        const showNew = newOnlyCheckbox.is(':checked');
        const activeSources = [];
        container.find('.dsp-source-filter-cb:checked').each(function () { activeSources.push($(this).val()); });

        // --- Filtering ---
        let filteredData = allDealsData.filter(deal => {
             if (!deal) return false; // Safety check
             // Source Filter
            if (activeSources.length > 0 && !activeSources.includes(deal.source)) return false;
            // New Only Filter (checks the is_new flag derived from last fetch time)
            if (showNew && !deal.is_new) return false; // Note: This 'is_new' comes from the LATEST fetch time comparison
            // Search Filter
            if (searchTerm) { const text = `${deal.title||''} ${deal.description||''} ${deal.source||''}`.toLowerCase(); if (!text.includes(searchTerm)) return false; }
            return true;
        });

        // --- Sorting ---
        const sortKey = currentSort.key; const sortReverse = currentSort.reverse;
        // Update visual indicators
        tableHead.find('th').removeClass('dsp-sort-asc dsp-sort-desc');
        tableHead.find(`th[data-sort-key="${sortKey}"]`).addClass(sortReverse ? 'dsp-sort-desc' : 'dsp-sort-asc');

        filteredData.sort((a, b) => {
            if (!a || !b) return 0; // Safety check
            let valA = getSortValue(a, sortKey); let valB = getSortValue(b, sortKey);
            const isAL = a.is_lifetime||false; const isBL = b.is_lifetime||false;
            // Prioritize LTD unless sorting price ASC
            if (!(sortKey === 'price' && !sortReverse)) { if (isAL && !isBL) return -1; if (!isAL && isBL) return 1; }
            // Prioritize New if sorting by Date/New Desc
            if ((sortKey === 'first_seen' || sortKey === 'is_new') && sortReverse) { if (a.is_new && !b.is_new) return -1; if (!a.is_new && b.is_new) return 1; }
            // Standard comparison
            let comp = 0;
            if (typeof valA === 'string' && typeof valB === 'string') { comp = valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' }); }
            else { if (valA < valB) comp = -1; else if (valA > valB) comp = 1; }
            return sortReverse ? (comp * -1) : comp;
        });

        // Render the filtered and sorted data
        renderTable(filteredData);
        // Update status AFTER rendering
        updateStatusMessage();
    }

    /** Gets a comparable value for sorting. */
    function getSortValue(deal, key) { /* ... same as before ... */
        if (!deal) return '';
        switch (key) {
            case 'is_new': return deal.is_new ? 1 : 0;
            case 'title': return (deal.title || '').toLowerCase();
            case 'price': return parsePriceForSort(deal.price || '');
            case 'source': return (deal.source || '').toLowerCase();
            case 'first_seen': try { return Date.parse(deal.first_seen); } catch (e) { return 0; } // Use ISO string
            default: return '';
        }
    }

     /** Parses a price string into a sortable number. */
    function parsePriceForSort(priceStr) { /* ... same as before ... */
        const inf = Infinity; if (priceStr === null || typeof priceStr === 'undefined') return inf;
        priceStr = String(priceStr).toLowerCase().trim(); if (priceStr === '' || priceStr === 'n/a') return inf;
        if (['free','freebie','0','0.00'].includes(priceStr) || priceStr.startsWith('$0') || priceStr.startsWith('€0') || priceStr.startsWith('£0')) return 0;
        const clean = priceStr.replace(/[^0-9.-]+/g,""); const match = clean.match(/^-?\d+(\.\d+)?/); if(match){return parseFloat(match[0]);} return inf;
    }

    /** Debounce utility */
    function debounce(func, wait) { let t; return function(...a){const l=()=>{clearTimeout(t);func.apply(this,a);};clearTimeout(t);t=setTimeout(l,wait);};}

    /** Simple translation placeholder */
     function __(text) { // Use provided text object key or fallback
         const key = text.toLowerCase().replace(/[^a-z0-9]/g, '_') + '_text';
         return dsp_ajax_obj && dsp_ajax_obj[key] ? dsp_ajax_obj[key] : text;
     }

     /** Updates the main status message bar */
      function updateStatusMessage() {
         if (isCheckingForDeals || isRefreshing || isSubscribing) return; // Don't overwrite active messages
         const visibleRowCount = tableBody.find('tr.dsp-deal-row').length;
         const totalDealCount = allDealsData.length;
         let statusText = sprintf(__('Showing %d of %d deals.', 'deal-scraper-plugin'), visibleRowCount, totalDealCount);
         // Add filter info
         const searchTerm = searchInput.val().trim(); const showNew = newOnlyCheckbox.is(':checked'); const activeSources = container.find('.dsp-source-filter-cb:checked').map(function() { return $(this).val(); }).get(); const totalSources = dsp_ajax_obj.config_sources?.length || 0; const filterParts = [];
         if (searchTerm) filterParts.push(sprintf('%s: \'%s\'', __('Search'), searchTerm));
         if (totalSources > 0 && activeSources.length < totalSources) filterParts.push(__('Sources filtered'));
         if (showNew) filterParts.push(__('New only'));
         if (filterParts.length > 0) { statusText += ` | ${__('Filters')}: ${filterParts.join(', ')}`; }
         statusMessage.text(statusText).removeClass('dsp-error dsp-success');
     }

     /** Basic sprintf implementation */
     function sprintf(format, ...args) {
        let i = 0;
        return format.replace(/%[sd]/g, (match) => (match === '%d' ? parseInt(args[i++]) : String(args[i++])));
     }

    /** Handles submission of the subscription form */
    function handleSubscriptionSubmit(event) { /* ... same as before ... */
        event.preventDefault(); if (isSubscribing || !subscribeModal.length || !subscribeEmailInput.length) return;
        const email = subscribeEmailInput.val().trim(); const enterEmailText=__(dsp_ajax_obj.subscribe_enter_email); const invalidFormatText=__(dsp_ajax_obj.subscribe_invalid_email_format);
        if (!email) { subscribeMessage.text(enterEmailText).removeClass('dsp-success').addClass('dsp-error').show(); return; }
        if (!/^\S+@\S+\.\S+$/.test(email)) { subscribeMessage.text(invalidFormatText).removeClass('dsp-success').addClass('dsp-error').show(); return; }
        isSubscribing = true; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', true); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'visible'); if(subscribeMessage.length) subscribeMessage.text('').removeClass('dsp-success dsp-error').hide();
        $.ajax({
            url:dsp_ajax_obj.ajax_url, type:'POST', data:{action:'dsp_subscribe_email',nonce:dsp_ajax_obj.nonce,email:email}, timeout:15000,
            success:function(response) {
                if (response.success && response.data?.message) { if(subscribeMessage.length) subscribeMessage.text(response.data.message).removeClass('dsp-error').addClass('dsp-success').show(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); setTimeout(function(){if(subscribeModal.is(':visible'))subscribeModal.fadeOut(200);}, 3000); }
                else { const errorMsg = response.data?.message || __(dsp_ajax_obj.subscribe_error_generic); if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show(); }
            }, error:function(jqXHR,textStatus,errorThrown) {
                console.error("DSP Sub AJAX Error:", textStatus, errorThrown); let errorMsg = __(dsp_ajax_obj.subscribe_error_network); if (jqXHR.responseJSON?.data?.message) errorMsg=jqXHR.responseJSON.data.message; if(subscribeMessage.length) subscribeMessage.text(errorMsg).removeClass('dsp-success').addClass('dsp-error').show();
            }, complete:function() { isSubscribing = false; if(subscribeSubmitButton.length) subscribeSubmitButton.prop('disabled', false); if(subscribeSpinner.length) subscribeSpinner.css('visibility', 'hidden'); }
        });
    }

    /** Binds all event listeners */
    function bindEvents() {
        // --- Filters ---
        searchInput.on('keyup', debounce(applyFiltersAndSort, 300));
        newOnlyCheckbox.on('change', applyFiltersAndSort);
        sourceCheckboxesContainer.on('change', '.dsp-source-filter-cb', applyFiltersAndSort);

        // --- Sorting ---
        tableHead.find('th[data-sort-key]').on('click', function () {
            const th = $(this); const newSortKey = th.data('sort-key'); if(!newSortKey) return;
            if (currentSort.key === newSortKey) { currentSort.reverse = !currentSort.reverse; }
            else { currentSort.key = newSortKey; currentSort.reverse = ['first_seen', 'is_new'].includes(newSortKey); }
            applyFiltersAndSort(); // This will update indicators and render
        });

        // --- Debug Log Toggle (Conditional) ---
        if (toggleLogButton.length && logContainer.length) {
            toggleLogButton.on('click', function () {
                 const button=$(this); const showTxt=__(dsp_ajax_obj.show_log_text); const hideTxt=__(dsp_ajax_obj.hide_log_text);
                 logContainer.slideToggle(200, function() { button.text(logContainer.is(':visible') ? hideTxt : showTxt); });
            });
        } else { if(logContainer.length) logContainer.hide(); } // Ensure hidden if button doesn't exist

        // --- Manual Refresh (Conditional) ---
        if (refreshButton.length) {
            refreshButton.on('click', function () {
                if (isRefreshing || isCheckingForDeals) return; // Prevent concurrent runs
                isRefreshing = true;
                 const refreshingText = __(dsp_ajax_obj.refreshing_text);
                 const refreshFinishedText = __(dsp_ajax_obj.refresh_finished_text);
                 const refreshFailedInvalidResp = __(dsp_ajax_obj.error_refresh_invalid_resp_text);
                 const refreshFailedAjax = __(dsp_ajax_obj.error_refresh_ajax_text);

                const button = $(this); button.prop('disabled', true);
                 if(refreshSpinner.length) refreshSpinner.css('visibility', 'visible');
                 if(refreshMessage.length) refreshMessage.text(refreshingText).removeClass('dsp-error dsp-success');
                 if(logPre.length) logPre.text('Running manual refresh...'); // Clear log temporarily
                 statusMessage.text(refreshingText).removeClass('dsp-success dsp-error'); // Update main status

                $.ajax({ // Refresh AJAX call (uses the original dsp_refresh_deals which returns ALL deals)
                    url: dsp_ajax_obj.ajax_url, type: 'POST', data: { action: 'dsp_refresh_deals', nonce: dsp_ajax_obj.nonce }, timeout: 180000,
                    success: function (response) {
                        console.log("DSP: Manual refresh response:", response);
                        let message = refreshFailedInvalidResp; let messageType = 'dsp-error';
                        if (response.success && response.data) {
                            // Manual refresh replaces ALL data
                            allDealsData = response.data.deals || [];
                            updateLastUpdated(response.data.last_fetch);
                            if(logPre.length) updateDebugLog(response.data.log);
                            message = response.data.message || refreshFinishedText;
                            messageType = message.toLowerCase().includes('error') || message.toLowerCase().includes('fail') ? 'dsp-error' : 'dsp-success';
                            applyFiltersAndSort(); // Re-render the full new dataset
                        } else {
                             console.error("DSP Refresh Error:", response); const logData = response.data?.log || [message];
                             if(logPre.length) updateDebugLog(logData);
                             message = response.data?.message || message;
                             if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-success').addClass(messageType); // Show error in refresh area
                             statusMessage.text(message).addClass('dsp-error'); // Also show error in main status
                             // Don't call applyFiltersAndSort - keep old data visible on error
                        }
                         if(refreshMessage.length) refreshMessage.text(message).removeClass('dsp-error dsp-success').addClass(messageType);
                    },
                    error: function (jqXHR, textStatus, errorThrown) {
                         console.error("DSP AJAX Refresh Error:", textStatus, errorThrown, jqXHR.responseJSON);
                         let errorMsg = refreshFailedAjax; let logData = [errorMsg, `Status: ${textStatus}`, `Error: ${errorThrown}`];
                         if (jqXHR.responseJSON?.data) { errorMsg = jqXHR.responseJSON.data.message || errorMsg; logData = jqXHR.responseJSON.data.log || logData; }
                         if (jqXHR.responseText) { logData.push("Raw Response Snippet: " + jqXHR.responseText.substring(0, 500)); }
                         if(logPre.length) updateDebugLog(logData);
                         if(refreshMessage.length) refreshMessage.text(errorMsg).addClass('dsp-error');
                         statusMessage.text(errorMsg).addClass('dsp-error'); // Show error in main status
                    },
                    complete: function () {
                        isRefreshing = false; button.prop('disabled', false);
                         if(refreshSpinner.length) refreshSpinner.css('visibility', 'hidden');
                         if(refreshMessage.length) { const delay = refreshMessage.hasClass('dsp-error') ? 10000 : 5000; setTimeout(() => { refreshMessage.text('').removeClass('dsp-error dsp-success'); }, delay); }
                         updateStatusMessage(); // Update main status message now refresh is done
                    }
                });
            });
        } // End if refreshButton exists

        // --- Donate Modal ---
        if (donateButton.length && donateModal.length) {
            donateButton.on('click', (e)=>{e.preventDefault(); donateModal.fadeIn(200);});
            donateModalClose.on('click', (e)=>{e.preventDefault(); donateModal.fadeOut(200);});
            donateModal.on('click', (e)=>{if($(e.target).is(donateModal))donateModal.fadeOut(200);});
            $(document).on('keydown', (e)=>{if(e.key==="Escape" && donateModal.is(':visible'))donateModal.fadeOut(200);});
        }

        // --- Subscribe Modal (Conditional) ---
        if (subscribeButton.length && subscribeModal.length) {
            subscribeButton.on('click', (e)=>{e.preventDefault(); if(subscribeMessage.length) subscribeMessage.text('').hide(); if(subscribeEmailInput.length) subscribeEmailInput.val(''); subscribeModal.fadeIn(200); if(subscribeEmailInput.length) subscribeEmailInput.focus(); });
            if(subscribeSubmitButton.length) subscribeSubmitButton.on('click', handleSubscriptionSubmit);
            if(subscribeEmailInput.length) subscribeEmailInput.on('keypress', (e)=>{if(e.which===13)handleSubscriptionSubmit(e);});
            if(subscribeModalClose.length) subscribeModalClose.on('click', (e)=>{e.preventDefault(); subscribeModal.fadeOut(200);});
            subscribeModal.on('click', (e)=>{if($(e.target).is(subscribeModal))subscribeModal.fadeOut(200);});
            $(document).on('keydown', (e)=>{if(e.key==="Escape" && subscribeModal.is(':visible') && !donateModal.is(':visible'))subscribeModal.fadeOut(200);});
        }

    } // End bindEvents

    // --- Run ---
    init(); // Start the process

}); // End jQuery Ready