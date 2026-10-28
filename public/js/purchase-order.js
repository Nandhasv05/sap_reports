/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : Purchase Order report page:
 *               - List mode: condition-wise PO list (date range, plant, supplier, quick search)
 *               - Detail mode: single PO report (Hero, KPI cards, charts, items table)
 */
(function () {
    const P = window.PoReport;
    if (!P) return;

    let cfg = {};
    try {
        cfg = JSON.parse(document.getElementById('poConfig')?.textContent || '{}');
    } catch (e) {
        cfg = {};
    }

    const $ = (id) => document.getElementById(id);
    const spinner = $('rptSpinner');
    const isList = cfg.mode === 'list';
    const viewParam = String(cfg.view || '').trim();

    function poPageHref(params) {
        const qs = new URLSearchParams();
        if (viewParam) qs.set('view', viewParam);
        Object.entries(params || {}).forEach(([k, v]) => {
            if (v !== undefined && v !== null && String(v) !== '') qs.set(k, String(v));
        });
        const q = qs.toString();
        return q ? `${cfg.pageUrl}?${q}` : cfg.pageUrl;
    }

    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    const table = $('rptDataTable');
    const tbody = $('rptTableBody');
    if (!table || !tbody) return;

    const columns = Array.isArray(cfg.columns) ? cfg.columns : [];
    const noMatchRow = $('rptNoMatchRow');
    const searchInput = $('rptTableSearch');
    const searchClear = $('rptTableSearchClear');
    const countBadge = $('rptTableCount');
    const clearAllBtn = $('rptClearAllFilters');
    const resetTableBtn = $('rptResetFilterTableBtn');
    const colFilterResetBtn = $('rptColFilterReset');
    const toggleFiltersBtn = $('rptToggleColFilters');
    const activeFilterBadge = $('rptActiveFilterBadge');
    const filterRow = $('rptFilterRow');
    const footTotalLabel = $('footTotalLabel');
    const tableWrap = $('rptTableWrap');
    const toggleStickyBtn = $('rptToggleStickyScroll');
    const stickyScrollLabel = $('rptStickyScrollLabel');

    let items = [];
    let totals = {};
    let currentSort = { col: null, dir: null };
    let currentPage = 1;
    let pageSize = 50;
    try {
        const savedSize = parseInt(localStorage.getItem('po_table_page_size') || '50', 10);
        if ([25, 50, 100, 200].includes(savedSize)) pageSize = savedSize;
    } catch (e) {}

    function syncHeaderHeight() {
        const headerRow = table.querySelector('thead tr.rpt-header-row');
        if (headerRow && headerRow.offsetHeight > 0) {
            document.documentElement.style.setProperty('--rpt-header-h', headerRow.offsetHeight + 'px');
        }
    }

    function showMessage(html) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach((tr) => tr.remove());
        const tr = document.createElement('tr');
        tr.className = 'rpt-table-loading';
        tr.innerHTML = `<td colspan="${columns.length}" class="rpt-table-empty">${html}</td>`;
        tbody.insertBefore(tr, noMatchRow);
        if (countBadge) countBadge.textContent = isList ? 'Showing 0 purchase orders' : 'Showing 0 items';
    }

    const fmtRound = (n) => (P && typeof P.fmtRound === 'function')
        ? P.fmtRound(n)
        : Math.round(Number(n || 0)).toLocaleString('en-US');

    /* ==============================================================
     * LIST MODE HANDLING
     * ============================================================== */
    function bindDocTypeDropdown(form) {
        const dd = $('poDocTypeDropdown');
        if (!dd) return;

        const trigger = $('poDocTypeTrigger');
        const menu = $('poDocTypeMenu');
        const hiddenInput = $('poTypeInput');
        const labelEl = $('poDocTypeLabel');
        const badgeEl = $('poDocTypeBadge');
        const countInfoEl = $('poDocTypeCountInfo');
        const searchInput = $('poDocTypeSearch');
        const searchClear = $('poDocTypeSearchClear');
        const selectAllBtn = $('poDocTypeSelectAll');
        const clearAllBtn = $('poDocTypeClearAll');
        const applyBtn = $('poDocTypeApply');
        const cancelBtn = $('poDocTypeCancel');
        const items = Array.from(dd.querySelectorAll('.po-multi-item'));
        const checkboxes = Array.from(dd.querySelectorAll('.po-multi-checkbox'));

        let initialChecked = checkboxes.filter((c) => c.checked).map((c) => c.value);

        function updateItemHighlight() {
            items.forEach((item) => {
                const cb = item.querySelector('.po-multi-checkbox');
                item.classList.toggle('is-checked', !!cb?.checked);
            });
        }

        function updateCountInfo() {
            const checkedCount = checkboxes.filter((c) => c.checked).length;
            if (countInfoEl) {
                countInfoEl.textContent = `${checkedCount} of ${checkboxes.length} selected`;
            }
            updateItemHighlight();
        }

        function updateTriggerUI(selected) {
            if (selected.length === 0) {
                if (labelEl) labelEl.textContent = 'All Doc Types';
                if (badgeEl) { badgeEl.style.display = 'none'; badgeEl.textContent = '0'; }
            } else if (selected.length === 1) {
                const checkedCb = checkboxes.find((c) => c.value === selected[0]);
                const name = checkedCb ? checkedCb.closest('.po-multi-item')?.querySelector('.po-multi-item-name')?.textContent : selected[0];
                if (labelEl) labelEl.textContent = name || selected[0];
                if (badgeEl) { badgeEl.style.display = 'inline-flex'; badgeEl.textContent = '1'; }
            } else {
                if (labelEl) labelEl.textContent = `${selected.length} Doc Types Selected`;
                if (badgeEl) { badgeEl.style.display = 'inline-flex'; badgeEl.textContent = String(selected.length); }
            }
        }

        function openDropdown(open) {
            dd.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                initialChecked = checkboxes.filter((c) => c.checked).map((c) => c.value);
                if (searchInput) {
                    searchInput.value = '';
                    filterList('');
                    setTimeout(() => searchInput.focus(), 60);
                }
            }
        }

        function filterList(q) {
            const query = q.trim().toLowerCase();
            if (searchClear) searchClear.style.display = query ? 'flex' : 'none';
            items.forEach((item) => {
                const code = (item.getAttribute('data-code') || '').toLowerCase();
                const name = (item.getAttribute('data-name') || '').toLowerCase();
                const match = !query || code.includes(query) || name.includes(query);
                item.style.display = match ? 'flex' : 'none';
            });
        }

        trigger?.addEventListener('click', (e) => {
            e.stopPropagation();
            openDropdown(!dd.classList.contains('is-open'));
        });

        menu?.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        checkboxes.forEach((cb) => {
            cb.addEventListener('change', updateCountInfo);
        });

        searchInput?.addEventListener('input', () => {
            filterList(searchInput.value);
        });

        searchClear?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            filterList('');
            searchInput?.focus();
        });

        selectAllBtn?.addEventListener('click', () => {
            items.forEach((item) => {
                if (item.style.display !== 'none') {
                    const cb = item.querySelector('.po-multi-checkbox');
                    if (cb) cb.checked = true;
                }
            });
            updateCountInfo();
        });

        clearAllBtn?.addEventListener('click', () => {
            items.forEach((item) => {
                if (item.style.display !== 'none') {
                    const cb = item.querySelector('.po-multi-checkbox');
                    if (cb) cb.checked = false;
                }
            });
            updateCountInfo();
        });

        cancelBtn?.addEventListener('click', () => {
            checkboxes.forEach((c) => { c.checked = initialChecked.includes(c.value); });
            updateCountInfo();
            openDropdown(false);
        });

        applyBtn?.addEventListener('click', () => {
            const selected = checkboxes.filter((c) => c.checked).map((c) => c.value);
            const val = selected.join(',');
            const oldVal = hiddenInput ? hiddenInput.value : '';
            if (hiddenInput) hiddenInput.value = val;
            updateTriggerUI(selected);
            openDropdown(false);
            if (val !== oldVal) {
                showSpinner(true);
                form.requestSubmit();
            }
        });

        document.addEventListener('click', (e) => {
            if (dd.classList.contains('is-open') && !dd.contains(e.target)) {
                checkboxes.forEach((c) => { c.checked = initialChecked.includes(c.value); });
                updateCountInfo();
                openDropdown(false);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && dd.classList.contains('is-open')) {
                checkboxes.forEach((c) => { c.checked = initialChecked.includes(c.value); });
                updateCountInfo();
                openDropdown(false);
            }
        });

        updateCountInfo();
    }

    let supplierDropdownApi = null;

    function bindSupplierDropdown(form) {
        const dd = $('poSupplierDropdown');
        if (!dd) return;

        const trigger = $('poSupplierTrigger');
        const menu = $('poSupplierMenu');
        const hiddenInput = $('poSupplierInput');
        const labelEl = $('poSupplierLabel');
        const badgeEl = $('poSupplierBadge');
        const countInfoEl = $('poSupplierCountInfo');
        const searchInput = $('poSupplierSearch');
        const searchClear = $('poSupplierSearchClear');
        const selectAllBtn = $('poSupplierSelectAll');
        const clearAllBtn = $('poSupplierClearAll');
        const applyBtn = $('poSupplierApply');
        const cancelBtn = $('poSupplierCancel');
        const listEl = $('poSupplierList');

        let initialChecked = [];
        let supplierMap = {};

        function getCheckboxes() {
            return Array.from(dd.querySelectorAll('.po-multi-checkbox'));
        }

        function getItems() {
            return Array.from(dd.querySelectorAll('.po-multi-item'));
        }

        function updateItemHighlight() {
            getItems().forEach((item) => {
                const cb = item.querySelector('.po-multi-checkbox');
                item.classList.toggle('is-checked', !!cb?.checked);
            });
        }

        function updateCountInfo() {
            const cbs = getCheckboxes();
            const checkedCount = cbs.filter((c) => c.checked).length;
            if (countInfoEl) {
                countInfoEl.textContent = `${checkedCount} of ${cbs.length} selected`;
            }
            updateItemHighlight();
        }

        function updateTriggerUI(selected) {
            if (selected.length === 0) {
                if (labelEl) labelEl.textContent = 'All Suppliers';
                if (badgeEl) { badgeEl.style.display = 'none'; badgeEl.textContent = '0'; }
            } else if (selected.length === 1) {
                const name = supplierMap[selected[0]] || `Supplier ${selected[0]}`;
                if (labelEl) labelEl.textContent = name;
                if (badgeEl) { badgeEl.style.display = 'inline-flex'; badgeEl.textContent = '1'; }
            } else {
                if (labelEl) labelEl.textContent = `${selected.length} Suppliers Selected`;
                if (badgeEl) { badgeEl.style.display = 'inline-flex'; badgeEl.textContent = String(selected.length); }
            }
        }

        function openDropdown(open) {
            dd.classList.toggle('is-open', open);
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                initialChecked = getCheckboxes().filter((c) => c.checked).map((c) => c.value);
                if (searchInput) {
                    searchInput.value = '';
                    filterList('');
                    setTimeout(() => searchInput.focus(), 60);
                }
            }
        }

        function filterList(q) {
            const query = q.trim().toLowerCase();
            if (searchClear) searchClear.style.display = query ? 'flex' : 'none';
            getItems().forEach((item) => {
                const code = (item.getAttribute('data-code') || '').toLowerCase();
                const name = (item.getAttribute('data-name') || '').toLowerCase();
                const match = !query || code.includes(query) || name.includes(query);
                item.style.display = match ? 'flex' : 'none';
            });
        }

        trigger?.addEventListener('click', (e) => {
            e.stopPropagation();
            openDropdown(!dd.classList.contains('is-open'));
        });

        menu?.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        searchInput?.addEventListener('input', () => {
            filterList(searchInput.value);
        });

        searchClear?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            filterList('');
            searchInput?.focus();
        });

        selectAllBtn?.addEventListener('click', () => {
            getItems().forEach((item) => {
                if (item.style.display !== 'none') {
                    const cb = item.querySelector('.po-multi-checkbox');
                    if (cb) cb.checked = true;
                }
            });
            updateCountInfo();
        });

        clearAllBtn?.addEventListener('click', () => {
            getItems().forEach((item) => {
                if (item.style.display !== 'none') {
                    const cb = item.querySelector('.po-multi-checkbox');
                    if (cb) cb.checked = false;
                }
            });
            updateCountInfo();
        });

        cancelBtn?.addEventListener('click', () => {
            getCheckboxes().forEach((c) => { c.checked = initialChecked.includes(c.value); });
            updateCountInfo();
            openDropdown(false);
        });

        applyBtn?.addEventListener('click', () => {
            const selected = getCheckboxes().filter((c) => c.checked).map((c) => c.value);
            const val = selected.join(',');
            const oldVal = hiddenInput ? hiddenInput.value : '';
            if (hiddenInput) hiddenInput.value = val;
            updateTriggerUI(selected);
            openDropdown(false);
            if (val !== oldVal) {
                showSpinner(true);
                form.requestSubmit();
            }
        });

        document.addEventListener('click', (e) => {
            if (dd.classList.contains('is-open') && !dd.contains(e.target)) {
                getCheckboxes().forEach((c) => { c.checked = initialChecked.includes(c.value); });
                updateCountInfo();
                openDropdown(false);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && dd.classList.contains('is-open')) {
                getCheckboxes().forEach((c) => { c.checked = initialChecked.includes(c.value); });
                updateCountInfo();
                openDropdown(false);
            }
        });

        supplierDropdownApi = {
            populate(suppliersList) {
                if (!listEl) return;
                const activeCodes = (hiddenInput?.value || '').split(',').map((s) => s.trim()).filter(Boolean);
                supplierMap = {};
                if (!suppliersList || suppliersList.length === 0) {
                    listEl.innerHTML = '<div style="padding: 14px 10px; text-align: center; color: #64748b; font-size: 12px;">No suppliers found</div>';
                    return;
                }

                let html = '';
                suppliersList.forEach((s) => {
                    const code = String(s.code || '').trim();
                    const name = String(s.name || s.supplier_full_name || code).trim();
                    if (!code) return;
                    supplierMap[code] = name;
                    const isChecked = activeCodes.includes(code);
                    html += `
                        <label class="po-multi-item" data-code="${P.esc(code)}" data-name="${P.esc(name.toLowerCase())}">
                            <input type="checkbox" class="po-multi-checkbox" value="${P.esc(code)}"${isChecked ? ' checked' : ''}>
                            <span class="po-multi-item-name" title="${P.esc(name)}">${P.esc(name)}</span>
                            <span class="po-multi-item-code">${P.esc(code)}</span>
                        </label>
                    `;
                });
                listEl.innerHTML = html;

                getCheckboxes().forEach((cb) => {
                    cb.addEventListener('change', updateCountInfo);
                });

                const curSelected = getCheckboxes().filter((c) => c.checked).map((c) => c.value);
                updateTriggerUI(curSelected);
                updateCountInfo();
            }
        };
    }

    function initColumnDropdowns(records) {
        if (!isList || !records || !records.length) return;

        const supColIdx = columns.findIndex((c) => c.key === 'supplier');
        const supNameColIdx = columns.findIndex((c) => c.key === 'supplier_full_name');

        function setupDropdown(colIdx, getVal, label) {
            if (colIdx < 0) return;
            const input = table.querySelector(`.rpt-col-input[data-col="${colIdx}"]`);
            if (!input) return;
            const wrap = input.parentElement;
            if (!wrap || wrap.querySelector('.rpt-col-filter-menu')) return;

            const uniqueMap = {};
            records.forEach((r) => {
                const v = getVal(r);
                if (v && v !== '—' && v !== '-') uniqueMap[v] = true;
            });
            const list = Object.keys(uniqueMap).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
            if (list.length === 0) return;

            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.className = 'rpt-col-dropdown-toggle';
            toggleBtn.title = `Select ${label} from dropdown`;
            toggleBtn.setAttribute('tabindex', '-1');
            toggleBtn.innerHTML = '<span class="material-icons-round">expand_more</span>';

            const menu = document.createElement('div');
            menu.className = 'rpt-col-filter-menu';
            let menuHtml = `<div class="rpt-col-filter-opt is-all" data-val="">— All ${label}s (${list.length}) —</div>`;
            list.forEach((val) => {
                menuHtml += `<div class="rpt-col-filter-opt" data-val="${P.esc(val)}" title="${P.esc(val)}">${P.esc(val)}</div>`;
            });
            menu.innerHTML = menuHtml;

            wrap.style.position = 'relative';
            wrap.appendChild(toggleBtn);
            wrap.appendChild(menu);

            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('.rpt-col-filter-menu.is-open').forEach((m) => {
                    if (m !== menu) m.classList.remove('is-open');
                });
                menu.classList.toggle('is-open');
            });

            menu.addEventListener('click', (e) => {
                e.stopPropagation();
                const opt = e.target.closest('.rpt-col-filter-opt');
                if (!opt) return;
                const picked = opt.getAttribute('data-val') || '';
                input.value = picked;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                menu.classList.remove('is-open');
                applyFilterAndSearch();
            });
        }

        setupDropdown(supColIdx, (r) => r.supplier, 'Supplier');
        setupDropdown(supNameColIdx, (r) => r.supplier_full_name, 'Vendor');

        document.addEventListener('click', (e) => {
            if (!e.target.closest('.rpt-col-input-wrap')) {
                document.querySelectorAll('.rpt-col-filter-menu.is-open').forEach((m) => m.classList.remove('is-open'));
            }
        });
    }

    function bindListFilterControls() {
        const form = $('poFilterForm');
        if (!form) return;

        const rangeSelect = form.querySelector('[data-range-select]');
        const fromHidden = $('poFromHidden') || form.querySelector('[name="from"]');
        const toHidden = $('poToHidden') || form.querySelector('[name="to"]');
        const poSearchInput = form.querySelector('#po');
        const presetDates = cfg.presetDates || {};
        const modal = $('poCustomDateModal');
        const modalFrom = $('poCustomModalFrom');
        const modalTo = $('poCustomModalTo');
        const modalError = $('poCustomDateError');
        const modalApply = $('poCustomDateApply');
        const modalCancel = $('poCustomDateCancel');
        const modalClose = $('poCustomDateClose');
        let lastNonCustomRange = rangeSelect && rangeSelect.value !== 'custom'
            ? rangeSelect.value
            : 'this_month';

        function ymdDaysAgo(days) {
            const d = new Date();
            d.setHours(0, 0, 0, 0);
            d.setDate(d.getDate() - days);
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }

        function maxCustomDays() {
            const raw = parseInt(modal?.getAttribute('data-max-days') || '100', 10);
            return Number.isFinite(raw) && raw > 0 ? raw : 100;
        }

        function daysBetweenYmd(fromYmd, toYmd) {
            const a = new Date(`${fromYmd}T00:00:00`);
            const b = new Date(`${toYmd}T00:00:00`);
            if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return null;
            return Math.floor((b.getTime() - a.getTime()) / 86400000) + 1;
        }

        function setCustomDateError(message) {
            if (!modalError) return;
            if (!message) {
                modalError.hidden = true;
                modalError.textContent = '';
                return;
            }
            modalError.textContent = message;
            modalError.hidden = false;
        }

        function validateModalDates() {
            const fVal = modalFrom ? modalFrom.value.trim() : '';
            const tVal = modalTo ? modalTo.value.trim() : '';
            const maxDays = maxCustomDays();
            setCustomDateError('');
            if (!fVal || !tVal) {
                setCustomDateError(!fVal ? 'Select a From date.' : 'Select a To date.');
                (!fVal ? modalFrom : modalTo)?.focus();
                return false;
            }
            if (fVal > tVal) {
                setCustomDateError('To date must be on or after From date.');
                modalTo?.focus();
                return false;
            }
            const minYmd = ymdDaysAgo(maxDays);
            if (fVal < minYmd) {
                setCustomDateError(`Select dates within the last ${maxDays} days.`);
                modalFrom?.focus();
                return false;
            }
            const span = daysBetweenYmd(fVal, tVal);
            if (span !== null && span > maxDays) {
                setCustomDateError(`Custom Date allows a maximum of ${maxDays} days.`);
                modalTo?.focus();
                return false;
            }
            return true;
        }

        function setHiddenCustomDates(enabled, fromVal, toVal) {
            if (fromHidden) {
                fromHidden.disabled = !enabled;
                if (enabled && fromVal !== undefined) fromHidden.value = fromVal;
            }
            if (toHidden) {
                toHidden.disabled = !enabled;
                if (enabled && toVal !== undefined) toHidden.value = toVal;
            }
        }

        function openCustomDateModal() {
            if (!modal) return;
            setCustomDateError('');
            const today = ymdDaysAgo(0);
            if (modalFrom) modalFrom.value = (fromHidden?.value || modalFrom.value || today);
            if (modalTo) modalTo.value = (toHidden?.value || modalTo.value || today);
            modal.hidden = false;
            document.body.classList.add('po-modal-open');
            setTimeout(() => modalFrom?.focus(), 40);
        }

        function closeCustomDateModal({ revertSelect = false } = {}) {
            if (!modal) return;
            modal.hidden = true;
            document.body.classList.remove('po-modal-open');
            setCustomDateError('');
            if (revertSelect && rangeSelect && rangeSelect.value === 'custom') {
                rangeSelect.value = lastNonCustomRange || 'this_month';
            }
        }

        function applyCustomDatesAndLoad() {
            if (!validateModalDates()) return;
            const fVal = modalFrom.value.trim();
            const tVal = modalTo.value.trim();
            if (rangeSelect) rangeSelect.value = 'custom';
            setHiddenCustomDates(true, fVal, tVal);
            closeCustomDateModal({ revertSelect: false });
            showSpinner(true);
            form.requestSubmit();
        }

        if (rangeSelect) {
            if (rangeSelect.value !== 'custom') {
                lastNonCustomRange = rangeSelect.value;
                setHiddenCustomDates(false);
            } else {
                setHiddenCustomDates(true);
            }

            rangeSelect.addEventListener('change', () => {
                const val = rangeSelect.value;
                if (val === 'custom') {
                    openCustomDateModal();
                    return;
                }
                lastNonCustomRange = val;
                const dates = presetDates[val];
                if (dates) {
                    if (fromHidden) fromHidden.value = dates.from || '';
                    if (toHidden) toHidden.value = dates.to || '';
                }
                setHiddenCustomDates(false);
                closeCustomDateModal({ revertSelect: false });
                showSpinner(true);
                form.requestSubmit();
            });
        }

        modalApply?.addEventListener('click', applyCustomDatesAndLoad);
        modalCancel?.addEventListener('click', () => closeCustomDateModal({ revertSelect: true }));
        modalClose?.addEventListener('click', () => closeCustomDateModal({ revertSelect: true }));
        modal?.addEventListener('click', (e) => {
            if (e.target === modal) closeCustomDateModal({ revertSelect: true });
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal && !modal.hidden) {
                closeCustomDateModal({ revertSelect: true });
            }
        });
        modalFrom?.addEventListener('input', () => setCustomDateError(''));
        modalTo?.addEventListener('input', () => setCustomDateError(''));
        modalFrom?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); applyCustomDatesAndLoad(); }
        });
        modalTo?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); applyCustomDatesAndLoad(); }
        });

        const matCatSelect = form.querySelector('[data-mat-cat-select]');
        if (matCatSelect) {
            matCatSelect.addEventListener('change', () => {
                showSpinner(true);
                form.requestSubmit();
            });
        }

        bindDocTypeDropdown(form);
        bindSupplierDropdown(form);

        function openPoFromSearch() {
            if (!poSearchInput) return false;
            const typedPo = P.cleanPo(poSearchInput.value);
            poSearchInput.setCustomValidity('');
            if (typedPo === '') return false;
            if (!P.validPo(typedPo)) {
                poSearchInput.setCustomValidity('Purchase order must be a number of up to 10 digits.');
                poSearchInput.reportValidity();
                return true;
            }
            showSpinner(true);
            window.location.href = poPageHref({ po: typedPo });
            return true;
        }

        if (poSearchInput) {
            poSearchInput.addEventListener('input', () => {
                poSearchInput.setCustomValidity('');
            });
            poSearchInput.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                openPoFromSearch();
            });
        }

        window.addEventListener('pageshow', (e) => {
            if (fromHidden) fromHidden.disabled = rangeSelect?.value !== 'custom';
            if (toHidden) toHidden.disabled = rangeSelect?.value !== 'custom';
            if (poSearchInput) poSearchInput.disabled = false;
            if (e && e.persisted) showSpinner(false);
        });

        form.addEventListener('submit', (e) => {
            if (openPoFromSearch()) {
                e.preventDefault();
                showSpinner(false);
                return;
            }

            if (rangeSelect && rangeSelect.value === 'custom') {
                const fVal = fromHidden ? fromHidden.value.trim() : '';
                const tVal = toHidden ? toHidden.value.trim() : '';
                if (!fVal || !tVal) {
                    e.preventDefault();
                    showSpinner(false);
                    openCustomDateModal();
                    return;
                }
                setHiddenCustomDates(true);
            } else {
                setHiddenCustomDates(false);
            }

            if (poSearchInput && !String(poSearchInput.value || '').trim()) {
                poSearchInput.disabled = true;
            }

            showSpinner(true);
        });
    }

    function listChipCell(values, fallbackStr, chipClass) {
        const mats = Array.isArray(values)
            ? values.filter(Boolean)
            : String(fallbackStr || '').split(',').map((s) => s.trim()).filter(Boolean);
        if (!mats.length) return `<td>—</td>`;
        const shown = mats.slice(0, 3);
        const extra = mats.length - shown.length;
        const chips = shown.map((m) => `<span class="${chipClass}" title="${P.esc(m)}">${P.esc(m)}</span>`).join('');
        const more = extra > 0 ? `<span class="po-mat-more" title="${P.esc(mats.join(', '))}">+${extra}</span>` : '';
        return `<td class="po-col-materials"><div class="po-mats-inline">${chips}${more}</div></td>`;
    }

    function renderListCell(key, rec, index) {
        switch (key) {
            case 'sno':
                return `<td class="num sno">${index + 1}</td>`;
            case 'purchase_order':
                return `<td><a href="${P.esc(poPageHref({ po: rec.purchase_order }))}" class="po-num-link" data-po-open="${P.esc(rec.purchase_order)}" title="Open Purchase Order ${P.esc(rec.purchase_order)}"><span class="po-item-tag is-link"><i class="fas fa-file-invoice"></i> ${P.esc(rec.purchase_order)}</span></a></td>`;
            case 'date':
                return `<td>${P.fmtDate(rec.date)}</td>`;
            case 'plant':
                return `<td><span class="po-plant-badge">${P.dash(rec.plant)}</span></td>`;
            case 'supplier':
                return `<td>${P.dash(rec.supplier)}</td>`;
            case 'materials':
            case 'material':
                return listChipCell(rec.materials, rec.material, 'po-mat-chip');
            case 'material_type':
                return listChipCell(rec.material_types, rec.material_type, 'po-mat-chip is-type');
            case 'mat_type_desc':
                return listChipCell(rec.mat_type_descs, rec.mat_type_desc, 'po-mat-chip is-type-desc');
            case 'material_group':
                return listChipCell(rec.material_groups, rec.material_group, 'po-mat-chip is-group');
            case 'items_count':
                return `<td class="num"><span class="po-items-badge">${fmtRound(rec.items_count)}</span></td>`;
            case 'total_qty':
                return `<td class="num"><span class="qty-val">${fmtRound(rec.total_qty)}</span></td>`;
            case 'unit':
                return `<td>${P.dash(rec.unit)}</td>`;
            case 'total_value':
                return `<td class="num po-value"><span class="qty-val">${fmtRound(rec.total_value)}</span></td>`;
            case 'currency':
                return `<td>${P.dash(rec.currency)}</td>`;
            case 'sales_orders':
                return `<td class="po-col-sos"><div class="po-sos-inline">${P.soChips(rec.sales_orders, 3)}</div></td>`;
            case 'created_by':
                return `<td>${P.dash(rec.created_by)}</td>`;
            case 'validation_status':
                return `<td>${statusBadge(rec.validation_status, rec.status_tone, rec.workflow_stage)}</td>`;
            case 'esign_status':
                return `<td>${statusBadge(rec.esign_status, rec.status_tone, rec.workflow_stage)}</td>`;
            case 'workflow_stage':
                return `<td class="po-workflow-stage" title="${P.esc(rec.workflow_stage || '')}">${P.dash(rec.workflow_stage)}</td>`;
            case 'status_owner':
                return `<td>${P.dash(rec.status_owner)}</td>`;
            default:
                return `<td>${P.dash(rec[key])}</td>`;
        }
    }

    function statusBadge(label, tone, title) {
        const text = String(label || '').trim();
        if (!text || text === '—') return '—';
        const t = String(tone || 'muted');
        return `<span class="po-status-badge tone-${P.esc(t)}" title="${P.esc(title || text)}">${P.esc(text)}</span>`;
    }

    function renderListRows(records) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach((tr) => tr.remove());
        const frag = document.createDocumentFragment();
        items = records.map((rec, index) => {
            const tr = document.createElement('tr');
            tr.className = 'po-row';
            tr.setAttribute('data-po-open', rec.purchase_order);
            tr.setAttribute('title', `Click to open details for PO ${rec.purchase_order}`);
            tr.setAttribute('data-orig-sno', String(index + 1));
            tr.innerHTML = columns.map((c) => renderListCell(c.key, rec, index)).join('');
            frag.appendChild(tr);
            const texts = columns.map((c, i) => (c.key === 'sno' ? '' : tr.children[i].textContent.replace(/\s+/g, ' ').trim()));
            return { rec, tr, origIndex: index, texts, searchText: texts.join(' ').toLowerCase(), visible: true };
        });
        tbody.insertBefore(frag, noMatchRow);
    }

    function fillListStats(t) {
        if ($('poStat-pos')) $('poStat-pos').textContent = fmtRound(t.pos_count || 0);
        if ($('poStat-items')) $('poStat-items').textContent = fmtRound(t.items_count || 0);
        if ($('poStat-qty')) $('poStat-qty').textContent = fmtRound(t.total_qty || 0);
        if ($('poStat-value')) $('poStat-value').textContent = fmtRound(t.total_value || 0);
        if ($('poStatUnit-value')) $('poStatUnit-value').textContent = t.currency || '';
        if ($('poStat-suppliers')) $('poStat-suppliers').textContent = fmtRound(t.suppliers_count || 0);
        if ($('poStat-validated')) $('poStat-validated').textContent = fmtRound(t.status_validated || t.status_completed || 0);
        if ($('poStat-in_review')) $('poStat-in_review').textContent = fmtRound(t.status_in_review || 0);
        if ($('poStat-pending_val')) $('poStat-pending_val').textContent = fmtRound(t.status_pending || 0);
        if ($('poStat-not_started')) $('poStat-not_started').textContent = fmtRound(t.status_not_started || 0);
        if ($('poStat-rejected')) $('poStat-rejected').textContent = fmtRound(t.status_rejected || 0);
        if ($('poListTotalBadge')) {
            const validated = Number(t.status_validated || t.status_completed || 0);
            const pending = Number(t.status_pending || 0) + Number(t.status_in_review || 0);
            $('poListTotalBadge').textContent = cfg.isCostingView
                ? `${fmtRound(t.pos_count || 0)} POs · ${fmtRound(validated)} validated · ${fmtRound(pending)} pending`
                : `${fmtRound(t.pos_count || 0)} Purchase Orders found`;
        }
    }

    let listChartInstances = [];

    function fillListCharts(records, t) {
        if (typeof Chart === 'undefined') return;
        listChartInstances.forEach((c) => { try { c.destroy(); } catch (e) {} });
        listChartInstances = [];

        const topCanvas = $('poListTopChart');
        const plantCanvas = $('poListPlantChart');
        if (!topCanvas || !plantCanvas) return;

        const font = { family: 'Inter, sans-serif', size: 11 };

        // 1. Top Purchase Orders by Net Value (Top 8)
        const sorted = [...records].sort((a, b) => (Number(b.total_value) || 0) - (Number(a.total_value) || 0)).slice(0, 8);
        const topChart = new Chart(topCanvas, {
            type: 'bar',
            data: {
                labels: sorted.map((r) => `PO ${r.purchase_order}`),
                datasets: [{
                    label: `Net Value (${t.currency || 'INR'})`,
                    data: sorted.map((r) => Number(r.total_value) || 0),
                    backgroundColor: 'rgba(67, 56, 202, 0.85)',
                    hoverBackgroundColor: '#3730a3',
                    borderRadius: 6,
                    maxBarThickness: 42,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: (itemsList) => {
                                const r = sorted[itemsList[0].dataIndex];
                                return `PO ${r.purchase_order} · Supplier: ${r.supplier}`;
                            },
                            label: (c) => `Net Value: ${fmtRound(c.raw)} ${sorted[c.dataIndex].currency || t.currency || ''}`,
                            afterLabel: (c) => `Items: ${sorted[c.dataIndex].items_count} | Plant: ${sorted[c.dataIndex].plant || '—'}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font } },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font,
                            callback: (v) => {
                                if (v >= 1e9) return (v / 1e9).toFixed(1) + 'B';
                                if (v >= 1e7) return (v / 1e7).toFixed(1) + 'Cr';
                                if (v >= 1e5) return (v / 1e5).toFixed(1) + 'L';
                                if (v >= 1e3) return (v / 1e3).toFixed(0) + 'k';
                                return v;
                            },
                        },
                        title: { display: true, text: `Net Value (${t.currency || ''})`, font },
                    },
                },
                onClick: (e, elements) => {
                    if (elements && elements.length > 0) {
                        const idx = elements[0].index;
                        const po = sorted[idx]?.purchase_order;
                        if (po && P.openDrawer) P.openDrawer(po);
                    }
                },
            },
        });
        listChartInstances.push(topChart);

        // 2. Net Value by Plant Doughnut
        const byPlant = new Map();
        records.forEach((r) => {
            const p = r.plant || 'Unassigned';
            byPlant.set(p, (byPlant.get(p) || 0) + (Number(r.total_value) || 0));
        });
        const plantEntries = [...byPlant.entries()].sort((a, b) => b[1] - a[1]);
        const plantSum = plantEntries.reduce((s, [, v]) => s + v, 0);

        const plantChart = new Chart(plantCanvas, {
            type: 'doughnut',
            data: {
                labels: plantEntries.map(([p]) => p),
                datasets: [{
                    data: plantEntries.map(([, v]) => v),
                    backgroundColor: plantEntries.map((_, i) => palette[i % palette.length]),
                    borderWidth: 2,
                    borderColor: '#ffffff',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { font, boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            label: (c) => `${c.label}: ${fmtRound(c.raw)} ${t.currency || ''} (${plantSum > 0 ? ((c.raw / plantSum) * 100).toFixed(1) : 0}%)`,
                        },
                    },
                },
            },
        });
        listChartInstances.push(plantChart);
    }

    /* ==============================================================
     * DETAIL MODE HANDLING
     * ============================================================== */
    function bindDetailFilterControls() {
        document.querySelectorAll('#poFilterForm').forEach((form) => {
            const input = form.querySelector('[name="po"]');
            if (!input) return;
            form.addEventListener('submit', (e) => {
                const po = P.cleanPo(input.value);
                input.value = po;
                if (po === '') {
                    e.preventDefault();
                    input.setCustomValidity('Enter a purchase order number.');
                    input.reportValidity();
                    input.focus();
                    return;
                }
                if (!P.validPo(po)) {
                    e.preventDefault();
                    input.setCustomValidity('Purchase order must be a number of up to 10 digits.');
                    input.reportValidity();
                    input.focus();
                    return;
                }
                showSpinner(true);
            });
        });
    }

    function fillHero(h, t) {
        $('poHeroSub').textContent = [
            h.date ? `Dated ${P.fmtDate(h.date)}` : '',
            h.created_by ? `Created by ${h.created_by}` : '',
            `${P.qty(t.items)} item${t.items === 1 ? '' : 's'}`,
        ].filter(Boolean).join(' · ');
        const meta = P.infoItems(h).filter(([label]) => label !== 'Purchase Order');
        $('poHeroMeta').innerHTML = meta.map(([label, value]) => `<div><dt>${label}</dt><dd>${P.dash(value)}</dd></div>`).join('');
        $('poHeroSos').innerHTML = (h.sales_orders || []).length ? P.soChips(h.sales_orders) : '<span class="po-muted">None</span>';
    }

    function fillDetailStats(data) {
        P.statItems(data).forEach((s) => {
            const el = $(`poStat-${s.id}`);
            const unit = $(`poStatUnit-${s.id}`);
            if (el) el.textContent = s.value;
            if (unit) unit.textContent = s.unit;
        });
    }

    const palette = ['#4338ca', '#0f766e', '#0284c7', '#d97706', '#be123c', '#7c3aed', '#16a34a', '#64748b'];

    function fillCharts(records, t) {
        if (typeof Chart === 'undefined') return;
        const font = { family: 'Inter, sans-serif', size: 11 };
        new Chart($('poItemChart'), {
            data: {
                labels: records.map((r) => `Item ${r.item}`),
                datasets: [
                    { type: 'bar', label: `Order Qty${t.unit ? ` (${t.unit})` : ''}`, data: records.map((r) => r.order_qty), backgroundColor: 'rgba(67, 56, 202, 0.85)', borderRadius: 6, maxBarThickness: 46, yAxisID: 'y' },
                    { type: 'line', label: `Net Value${t.currency ? ` (${t.currency})` : ''}`, data: records.map((r) => r.net_value), borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.3, pointRadius: 4, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { font, boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            title: (list) => { const r = records[list[0].dataIndex]; return `Item ${r.item} · ${r.material}`; },
                            label: (c) => `${c.dataset.label}: ${c.dataset.yAxisID === 'y1' ? P.money(c.raw) : P.qty(c.raw)}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font } },
                    y: { beginAtZero: true, ticks: { font }, title: { display: true, text: 'Qty', font } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { font }, title: { display: true, text: 'Value', font } },
                },
            },
        });

        const byMaterial = new Map();
        records.forEach((r) => byMaterial.set(r.material, (byMaterial.get(r.material) || 0) + Number(r.net_value || 0)));
        const entries = [...byMaterial.entries()].sort((a, b) => b[1] - a[1]);
        const sum = entries.reduce((s, [, v]) => s + v, 0);
        new Chart($('poShareChart'), {
            type: 'doughnut',
            data: {
                labels: entries.map(([m]) => m || '—'),
                datasets: [{ data: entries.map(([, v]) => v), backgroundColor: entries.map((_, i) => palette[i % palette.length]), borderWidth: 2, borderColor: '#fff' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { font, boxWidth: 12 } },
                    tooltip: { callbacks: { label: (c) => `${c.label}: ${P.money(c.raw)} ${t.currency || ''} (${sum > 0 ? ((c.raw / sum) * 100).toFixed(1) : 0}%)` } },
                },
            },
        });
    }

    function sectionChip(section) {
        const s = String(section || '').toLowerCase();
        const tone = s === 'fabric' ? 'is-type' : (s === 'trim' || s === 'packing' ? 'is-group' : 'is-type-desc');
        return `<span class="po-mat-chip ${tone}">${P.dash(section)}</span>`;
    }

    function renderDetailCell(key, rec, index) {
        switch (key) {
            case 'sno': return `<td class="num sno">${index + 1}</td>`;
            case 'section': return `<td>${sectionChip(rec.section)}</td>`;
            case 'item': return `<td><span class="po-item-tag">${P.dash(rec.item)}</span></td>`;
            case 'material': return `<td class="rpt-mat">${P.dash(rec.material)}</td>`;
            case 'description': return `<td class="po-desc" title="${P.esc(rec.description)}">${P.dash(rec.description)}</td>`;
            case 'material_type': return `<td><span class="po-mat-chip is-type">${P.dash(rec.material_type)}</span></td>`;
            case 'mat_type_desc': return `<td>${P.dash(rec.mat_type_desc)}</td>`;
            case 'material_group': return `<td><span class="po-mat-chip is-group">${P.dash(rec.material_group)}</span></td>`;
            case 'order_qty':
            case 'net':
            case 'waste':
            case 'gross':
            case 'convr':
                return `<td class="num"><span class="qty-val">${P.qty(rec[key])}</span></td>`;
            case 'net_price':
            case 'purp':
            case 'freight':
            case 'duty':
            case 'fin_cost':
            case 'rate':
            case 'sell_price':
            case 'making':
            case 'overhead':
            case 'markup':
            case 'subtot':
            case 'total_fr':
                return `<td class="num"><span class="qty-val">${P.money(rec[key])}</span></td>`;
            case 'net_value':
            case 'total_inr':
                return `<td class="num po-value"><span class="qty-val">${P.money(rec[key])}</span></td>`;
            case 'sales_order':
                return `<td class="po-col-sos">${rec.sales_order ? P.soChips([rec.sales_order]) : '—'}</td>`;
            case 'sales_orders': return `<td class="po-col-sos">${P.soChips(rec.sales_orders)}</td>`;
            default: return `<td>${P.dash(rec[key])}</td>`;
        }
    }

    function renderDetailRows(records) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach((tr) => tr.remove());
        const frag = document.createDocumentFragment();
        items = records.map((rec, index) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-orig-sno', String(index + 1));
            tr.innerHTML = columns.map((c) => renderDetailCell(c.key, rec, index)).join('');
            frag.appendChild(tr);
            const texts = columns.map((c, i) => (c.key === 'sno' ? '' : tr.children[i].textContent.replace(/\s+/g, ' ').trim()));
            return { rec, tr, origIndex: index, texts, searchText: texts.join(' ').toLowerCase(), visible: true };
        });
        tbody.insertBefore(frag, noMatchRow);
    }

    /* ==============================================================
     * COMMON SORTING, FILTERING & CONTROLS
     * ============================================================== */
    function sortValue(rec, col) {
        if (col.key === 'sales_orders') return (rec.sales_orders || []).join(', ');
        return rec[col.key];
    }

    function sortItems() {
        if (currentSort.col === null) {
            items.sort((a, b) => a.origIndex - b.origIndex);
        } else {
            const col = columns[currentSort.col];
            const dir = currentSort.dir === 'desc' ? -1 : 1;
            items.sort((a, b) => {
                let res;
                if (col.key === 'sno') res = a.origIndex - b.origIndex;
                else if (col.type === 'num') res = (Number(sortValue(a.rec, col)) || 0) - (Number(sortValue(b.rec, col)) || 0);
                else res = String(sortValue(a.rec, col) ?? '').localeCompare(String(sortValue(b.rec, col) ?? ''), undefined, { numeric: true, sensitivity: 'base' });
                return (res || a.origIndex - b.origIndex) * dir;
            });
        }
        const frag = document.createDocumentFragment();
        items.forEach((item) => frag.appendChild(item.tr));
        frag.appendChild(noMatchRow);
        tbody.appendChild(frag);
    }

    function updateSortUI() {
        table.querySelectorAll('thead th.is-sortable').forEach((th) => {
            const col = parseInt(th.getAttribute('data-col'), 10);
            th.classList.toggle('is-sorted-asc', currentSort.col === col && currentSort.dir === 'asc');
            th.classList.toggle('is-sorted-desc', currentSort.col === col && currentSort.dir === 'desc');
        });
    }

    function parseNumCond(q) {
        const m = q.trim().match(/^([><]=?|=)\s*(-?\d+(?:\.\d+)?)$/);
        return m ? { op: m[1], val: parseFloat(m[2]) } : null;
    }

    function colFilters() {
        const filters = [];
        table.querySelectorAll('.rpt-col-input').forEach((input) => {
            const raw = input.value.trim();
            const cb = input.parentElement?.querySelector('.rpt-col-clear');
            input.classList.toggle('has-val', raw !== '');
            if (cb) cb.style.display = raw !== '' ? 'block' : 'none';
            if (raw === '') return;
            const col = parseInt(input.getAttribute('data-col'), 10);
            filters.push({ col, key: columns[col]?.key, lower: raw.toLowerCase(), cond: input.hasAttribute('data-numeric') ? parseNumCond(raw) : null });
        });
        return filters;
    }

    function matches(item, query, filters) {
        if (query !== '' && !item.searchText.includes(query)) return false;
        for (const f of filters) {
            if (f.cond) {
                const n = Number(item.rec[f.key]) || 0;
                const { op, val } = f.cond;
                if ((op === '>' && !(n > val)) || (op === '>=' && !(n >= val)) || (op === '<' && !(n < val)) || (op === '<=' && !(n <= val)) || (op === '=' && n !== val)) return false;
            } else if (!(item.texts[f.col] || '').toLowerCase().includes(f.lower)) {
                return false;
            }
        }
        return true;
    }

    function setFoot(key, html) {
        const th = table.querySelector(`tfoot [data-foot-key="${key}"]`);
        if (th) th.innerHTML = html;
    }

    function pageWindow(totalPages, page) {
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, (_, i) => i + 1);
        }
        const pages = new Set([1, totalPages, page, page - 1, page + 1, page - 2, page + 2]);
        const sorted = [...pages].filter((p) => p >= 1 && p <= totalPages).sort((a, b) => a - b);
        const out = [];
        let prev = 0;
        sorted.forEach((p) => {
            if (prev && p - prev > 1) out.push('…');
            out.push(p);
            prev = p;
        });
        return out;
    }

    function renderPagination(matchedCount) {
        const bar = $('rptPagination');
        const info = $('rptPageInfo');
        const nums = $('rptPageNumbers');
        const firstBtn = $('rptPageFirst');
        const prevBtn = $('rptPagePrev');
        const nextBtn = $('rptPageNext');
        const lastBtn = $('rptPageLast');
        const sizeSel = $('rptPageSize');
        if (!bar) return;

        if (sizeSel && String(pageSize) !== sizeSel.value) sizeSel.value = String(pageSize);

        const totalPages = Math.max(1, Math.ceil(matchedCount / pageSize) || 1);
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        bar.hidden = matchedCount === 0;
        const from = matchedCount === 0 ? 0 : ((currentPage - 1) * pageSize) + 1;
        const to = Math.min(matchedCount, currentPage * pageSize);
        if (info) {
            info.textContent = matchedCount === 0
                ? 'No rows'
                : `Page ${currentPage} of ${totalPages} · ${from}–${to} of ${matchedCount}`;
        }

        if (nums) {
            nums.innerHTML = pageWindow(totalPages, currentPage).map((p) => {
                if (p === '…') return '<span class="rpt-page-ellipsis">…</span>';
                const active = p === currentPage ? ' is-active' : '';
                return `<button type="button" class="rpt-page-btn${active}" data-page="${p}" aria-label="Page ${p}">${p}</button>`;
            }).join('');
        }

        if (firstBtn) firstBtn.disabled = currentPage <= 1;
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
        if (lastBtn) lastBtn.disabled = currentPage >= totalPages;
    }

    function applyFilterAndSearch(options = {}) {
        const resetPage = options.resetPage !== false;
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filters = colFilters();
        if (searchClear) searchClear.style.display = query !== '' ? 'flex' : 'none';

        if (resetPage) currentPage = 1;

        const matched = [];
        const sums = isList
            ? { items_count: 0, total_qty: 0, total_value: 0 }
            : { order_qty: 0, net_value: 0 };

        items.forEach((item) => {
            item.visible = matches(item, query, filters);
            if (!item.visible) {
                item.tr.style.display = 'none';
                return;
            }
            matched.push(item);
            Object.keys(sums).forEach((k) => { sums[k] += Number(item.rec[k]) || 0; });
        });

        const totalPages = Math.max(1, Math.ceil(matched.length / pageSize) || 1);
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;

        matched.forEach((item, idx) => {
            const onPage = idx >= start && idx < end;
            item.tr.style.display = onPage ? '' : 'none';
            if (!onPage) return;
            const sno = item.tr.querySelector('td.sno');
            if (sno) sno.textContent = String(idx + 1);
        });

        const visibleOnPage = Math.max(0, Math.min(pageSize, matched.length - start));
        const isFiltered = query !== '' || filters.length > 0;
        if (noMatchRow) noMatchRow.style.display = items.length > 0 && matched.length === 0 ? '' : 'none';
        if (countBadge) {
            countBadge.textContent = isList
                ? `Showing ${visibleOnPage} of ${matched.length} purchase orders` + (isFiltered ? ` (filtered from ${items.length})` : '')
                : `Showing ${visibleOnPage} of ${matched.length} items` + (isFiltered ? ` (filtered from ${items.length})` : '');
            countBadge.classList.toggle('is-filtered', isFiltered);
        }
        if (activeFilterBadge) {
            activeFilterBadge.style.display = filters.length > 0 ? 'inline-flex' : 'none';
            activeFilterBadge.textContent = String(filters.length);
        }
        if (clearAllBtn) clearAllBtn.style.display = isFiltered || currentSort.col !== null ? 'inline-flex' : 'none';
        if (footTotalLabel) {
            footTotalLabel.textContent = isFiltered
                ? `Total (${matched.length} of ${items.length})`
                : `Total (${matched.length})`;
        }

        if (isList) {
            setFoot('items_count', `<span class="foot-val">${P.qty(sums.items_count)}</span>`);
            setFoot('total_qty', `<span class="foot-val">${P.qty(sums.total_qty)}</span>`);
            setFoot('total_value', `<span class="foot-val">${P.money(sums.total_value)}</span>`);
        } else {
            const sameUnit = totals.order_qty !== null;
            const sameCurrency = totals.net_value !== null;
            setFoot('order_qty', sameUnit ? `<span class="foot-val">${P.qty(sums.order_qty)}</span>` : '');
            setFoot('unit', sameUnit ? P.esc(totals.unit || '') : '');
            setFoot('net_value', sameCurrency ? `<span class="foot-val">${P.money(sums.net_value)}</span>` : '');
            setFoot('currency', sameCurrency ? P.esc(totals.currency || '') : '');
        }

        renderPagination(matched.length);

        // Excel export must include all filtered rows, not only the current page
        table._getAllExportRows = (visibleCols, convert) => {
            const cellText = (cell) => {
                if (!cell) return '';
                const chips = cell.querySelectorAll('.so-chip');
                if (chips.length) {
                    return Array.from(chips)
                        .map((c) => (c.dataset.export || c.textContent).trim())
                        .filter(Boolean)
                        .join(', ');
                }
                const val = cell.querySelector('.qty-val, .bom-val, .foot-val');
                return (val ? val.textContent : cell.textContent).replace(/\s+/g, ' ').trim();
            };
            return matched.map((item) => visibleCols.map(({ i, th }) => {
                const numeric = !!(th && th.classList.contains('num'));
                return convert(cellText(item.tr.children[i]), numeric);
            }));
        };
    }

    function clearAllFilters() {
        if (searchInput) searchInput.value = '';
        table.querySelectorAll('.rpt-col-input').forEach((i) => { i.value = ''; });
        currentSort = { col: null, dir: null };
        updateSortUI();
        sortItems();
        applyFilterAndSearch();
    }

    function applyStickyMode(enabled) {
        if (!tableWrap) return;
        tableWrap.classList.toggle('is-expanded', !enabled);
        tableWrap.classList.toggle('is-scroll-body', enabled);
        toggleStickyBtn?.classList.toggle('is-active', enabled);
        if (stickyScrollLabel) stickyScrollLabel.textContent = enabled ? 'Scroll Body Only' : 'Expand Table';
        try { localStorage.setItem('rpt_sticky_scroll', enabled ? 'true' : 'false'); } catch (e) {}
        syncHeaderHeight();
    }

    function goToPage(page) {
        const matchedCount = items.filter((item) => item.visible).length;
        const totalPages = Math.max(1, Math.ceil(matchedCount / pageSize) || 1);
        currentPage = Math.min(totalPages, Math.max(1, page));
        applyFilterAndSearch({ resetPage: false });
        tableWrap?.scrollTo?.({ top: 0, behavior: 'smooth' });
    }

    function bindPaginationControls() {
        const sizeSel = $('rptPageSize');
        if (sizeSel) {
            sizeSel.value = String(pageSize);
            sizeSel.addEventListener('change', () => {
                const next = parseInt(sizeSel.value, 10);
                pageSize = [25, 50, 100, 200].includes(next) ? next : 50;
                try { localStorage.setItem('po_table_page_size', String(pageSize)); } catch (e) {}
                currentPage = 1;
                applyFilterAndSearch({ resetPage: false });
            });
        }
        $('rptPageFirst')?.addEventListener('click', () => goToPage(1));
        $('rptPagePrev')?.addEventListener('click', () => goToPage(currentPage - 1));
        $('rptPageNext')?.addEventListener('click', () => goToPage(currentPage + 1));
        $('rptPageLast')?.addEventListener('click', () => {
            const matchedCount = items.filter((item) => item.visible).length;
            goToPage(Math.max(1, Math.ceil(matchedCount / pageSize) || 1));
        });
        $('rptPageNumbers')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-page]');
            if (!btn) return;
            goToPage(parseInt(btn.getAttribute('data-page'), 10));
        });
    }

    let controlsBound = false;
    function bindControls() {
        if (controlsBound) return;
        controlsBound = true;
        let searchTimer = null;
        let filterTimer = null;
        bindPaginationControls();
        searchInput?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilterAndSearch, 80); });
        searchInput?.addEventListener('keydown', (e) => { if (e.key === 'Escape') { searchInput.value = ''; applyFilterAndSearch(); } else if (e.key === 'Enter') e.preventDefault(); });
        searchClear?.addEventListener('click', () => { searchInput.value = ''; searchInput.focus(); applyFilterAndSearch(); });

        table.querySelectorAll('.rpt-col-input').forEach((input) => {
            input.addEventListener('input', () => { clearTimeout(filterTimer); filterTimer = setTimeout(applyFilterAndSearch, 100); });
            input.addEventListener('keydown', (e) => { if (e.key === 'Escape') { input.value = ''; applyFilterAndSearch(); } else if (e.key === 'Enter') e.preventDefault(); });
            input.parentElement?.querySelector('.rpt-col-clear')?.addEventListener('click', (e) => { e.stopPropagation(); input.value = ''; input.focus(); applyFilterAndSearch(); });
        });

        table.querySelectorAll('thead th.is-sortable').forEach((th) => {
            th.addEventListener('click', () => {
                const col = parseInt(th.getAttribute('data-col'), 10);
                currentSort = currentSort.col === col
                    ? (currentSort.dir === 'asc' ? { col, dir: 'desc' } : { col: null, dir: null })
                    : { col, dir: 'asc' };
                updateSortUI();
                sortItems();
                applyFilterAndSearch();
            });
        });

        tbody.addEventListener('click', (e) => {
            const tr = e.target.closest('tr.po-row');
            if (!tr) return;
            const po = tr.getAttribute('data-po-open');
            if (po && P.openDrawer) {
                e.preventDefault();
                P.openDrawer(po);
            }
        });

        colFilterResetBtn?.addEventListener('click', () => { table.querySelectorAll('.rpt-col-input').forEach((i) => { i.value = ''; }); applyFilterAndSearch(); });
        toggleFiltersBtn?.addEventListener('click', () => {
            if (!filterRow) return;
            const show = filterRow.style.display === 'none';
            filterRow.style.display = show ? '' : 'none';
            toggleFiltersBtn.classList.toggle('is-active', show);
            syncHeaderHeight();
        });
        clearAllBtn?.addEventListener('click', clearAllFilters);
        resetTableBtn?.addEventListener('click', clearAllFilters);

        let sticky = true;
        try { const saved = localStorage.getItem('rpt_sticky_scroll'); if (saved !== null) sticky = saved === 'true'; } catch (e) {}
        applyStickyMode(sticky);
        toggleStickyBtn?.addEventListener('click', () => applyStickyMode(!tableWrap.classList.contains('is-scroll-body')));
        window.addEventListener('resize', syncHeaderHeight);
    }

    const columnFeatures = window.rptSetupColumnFeatures
        ? window.rptSetupColumnFeatures(table, { onChange: syncHeaderHeight })
        : { refresh() {} };

    /* ==============================================================
     * INITIAL LOAD: LIST OR DETAIL
     * ============================================================== */
    showSpinner(true);

    if (isList) {
        bindListFilterControls();

        if (cfg.rangeError) {
            const alertBox = $('poAlert');
            if (alertBox) {
                alertBox.textContent = String(cfg.rangeError);
                alertBox.hidden = false;
            }
            showMessage(P.esc(String(cfg.rangeError)));
            showSpinner(false);
            return;
        }

        const params = new URLSearchParams({
            list: '1',
            range: cfg.range?.preset || '',
            from: cfg.range?.from || '',
            to: cfg.range?.to || '',
            plant: cfg.plant || '',
            supplier: cfg.supplier || '',
            so: cfg.salesOrder || '',
            po_type: cfg.poType || '',
            mat_cat: cfg.matCat || 'all',
            _: String(Date.now()),
        });
        if (cfg.view) params.set('view', cfg.view);

        fetch(`${cfg.dataUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        })
            .then(async (res) => {
                const raw = await res.text();
                let json;
                try { json = JSON.parse(raw); } catch (e) {
                    throw new Error(`SAP data could not be read (HTTP ${res.status}).`);
                }
                if (!res.ok || json.success === false) {
                    throw new Error(json.message || json.error || 'Failed to load purchase orders.');
                }
                return json.data || {};
            })
            .then((data) => {
                const records = Array.isArray(data.records) ? data.records : [];
                bindControls();
                if (!records.length) {
                    showMessage('No purchase orders found matching the selected conditions.');
                    fillListStats({ pos_count: 0, items_count: 0, total_qty: 0, total_value: 0, suppliers_count: 0 });
                    return;
                }
                totals = data.totals || {};
                fillListStats(totals);
                const alertBox = $('poAlert');
                if (alertBox) {
                    if (data.truncated) {
                        const fetched = fmtRound(data.items_fetched || 0);
                        const cap = fmtRound(data.max_rows || 0);
                        alertBox.textContent = `Showing the latest ${fetched} PO line items (SAP page limit ${cap}). Narrow the date range if you need older rows.`;
                        alertBox.hidden = false;
                    } else {
                        alertBox.hidden = true;
                        alertBox.textContent = '';
                    }
                }
                if (supplierDropdownApi) {
                    const supList = (Array.isArray(data.suppliers) && data.suppliers.length > 0)
                        ? data.suppliers
                        : (() => {
                            const map = {};
                            records.forEach((r) => {
                                const c = String(r.supplier || '').trim();
                                if (c && c !== '—' && c !== '-') {
                                    const n = String(r.supplier_full_name || '').trim() || `Supplier ${c}`;
                                    if (!map[c]) map[c] = { code: c, name: n };
                                }
                            });
                            return Object.values(map).sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
                        })();
                    supplierDropdownApi.populate(supList);
                }
                renderListRows(records);
                initColumnDropdowns(records);
                columnFeatures.refresh();
                applyFilterAndSearch();
                fillListCharts(records, totals);
            })
            .catch((err) => {
                const message = err.message || 'Unable to load purchase orders from SAP.';
                showMessage(P.esc(message));
                const alertBox = $('poAlert');
                if (alertBox) { alertBox.textContent = message; alertBox.hidden = false; }
            })
            .finally(() => {
                showSpinner(false);
                syncHeaderHeight();
            });

    } else {
        // Detail Mode
        bindDetailFilterControls();
        let allDetailRecords = [];
        const detailMatCatSelect = document.querySelector('[data-detail-mat-cat]');
        function applyDetailCategory(cat) {
            const selected = cat || 'all';
            const filtered = selected === 'all'
                ? allDetailRecords
                : allDetailRecords.filter((r) => String(r.mat_category || '') === selected);
            renderDetailRows(filtered);
            columnFeatures.refresh();
            applyFilterAndSearch();
            fillCharts(filtered, {
                items: filtered.length,
                materials: new Set(filtered.map((r) => r.material).filter(Boolean)).size,
                order_qty: filtered.reduce((s, r) => s + (Number(r.order_qty) || 0), 0),
                net_value: filtered.reduce((s, r) => s + (Number(r.net_value) || 0), 0),
                currency: totals.currency || '',
                unit: totals.unit || '',
            });
            if ($('poStat-items')) $('poStat-items').textContent = fmtRound(filtered.length);
            if ($('poStat-materials')) $('poStat-materials').textContent = fmtRound(new Set(filtered.map((r) => r.material).filter(Boolean)).size);
            if ($('poStat-qty')) $('poStat-qty').textContent = fmtRound(filtered.reduce((s, r) => s + (Number(r.order_qty) || 0), 0));
            if ($('poStat-value')) $('poStat-value').textContent = fmtRound(filtered.reduce((s, r) => s + (Number(r.net_value) || 0), 0));
        }
        if (detailMatCatSelect) {
            detailMatCatSelect.value = cfg.matCat || 'all';
            detailMatCatSelect.addEventListener('change', () => applyDetailCategory(detailMatCatSelect.value));
        }
        P.fetchPo(cfg.po)
            .then((data) => {
                const records = Array.isArray(data.records) ? data.records : [];
                bindControls();
                if (!records.length) {
                    $('poHeroSub').textContent = 'Not found in SAP';
                    const costingHint = data.costing?.error
                        ? `<br><span class="po-muted">${P.esc(data.costing.error)}</span>`
                        : '';
                    showMessage(`No purchase order <b>${P.esc(cfg.po)}</b> was found in SAP.${costingHint}`);
                    return;
                }
                const h = data.header || {};
                totals = data.totals || {};
                allDetailRecords = records;
                const titlePrefix = cfg.isCostingView ? 'PO Costing' : 'Purchase Order';
                document.title = `EVOLV | ${titlePrefix} ${h.purchase_order || cfg.po}`;
                fillHero(h, totals);
                fillDetailStats(data);
                const alertBox = $('poAlert');
                if (cfg.isCostingView && data.costing?.error && !data.costing_mode) {
                    if (alertBox) {
                        alertBox.textContent = data.costing.error;
                        alertBox.hidden = false;
                    }
                } else if (cfg.isCostingView && data.costing_mode && alertBox) {
                    alertBox.hidden = true;
                }
                if (cfg.isCostingView && data.costing_mode && detailMatCatSelect) {
                    const fabricOpt = detailMatCatSelect.querySelector('option[value="fabric"]');
                    const trimsOpt = detailMatCatSelect.querySelector('option[value="trims"]');
                    if (fabricOpt) fabricOpt.textContent = 'Fabric costing';
                    if (trimsOpt) trimsOpt.textContent = 'Trim / Packing';
                }
                applyDetailCategory(detailMatCatSelect?.value || cfg.matCat || 'all');
            })
            .catch((err) => {
                const message = err.message || 'Unable to load SAP data.';
                $('poHeroSub').textContent = 'Could not be loaded';
                showMessage(P.esc(message));
                const alertBox = $('poAlert');
                if (alertBox) { alertBox.textContent = message; alertBox.hidden = false; }
            })
            .finally(() => {
                showSpinner(false);
                syncHeaderHeight();
            });
    }
})();
