/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Production report - live SAP rows, search, column filters, sorting, totals and charts
 */
(function () {
    const cfgNode = document.getElementById('rptPageConfig');
    let cfg = {};
    try { cfg = JSON.parse(cfgNode?.textContent || '{}'); } catch (e) { cfg = {}; }
    const columns = Array.isArray(cfg.columns) ? cfg.columns : [];
    const isQty = (c) => c.type === 'num' && c.stage !== '';

    const table = document.getElementById('rptDataTable');
    const tbody = document.getElementById('rptTableBody');
    const spinner = document.getElementById('rptSpinner');
    const noMatchRow = document.getElementById('rptNoMatchRow');
    const searchInput = document.getElementById('rptTableSearch');
    const searchClear = document.getElementById('rptTableSearchClear');
    const countBadge = document.getElementById('rptTableCount');
    const clearAllBtn = document.getElementById('rptClearAllFilters');
    const resetTableBtn = document.getElementById('rptResetFilterTableBtn');
    const colFilterResetBtn = document.getElementById('rptColFilterReset');
    const toggleFiltersBtn = document.getElementById('rptToggleColFilters');
    const activeFilterBadge = document.getElementById('rptActiveFilterBadge');
    const filterRow = document.getElementById('rptFilterRow');
    const footTotalLabel = document.getElementById('footTotalLabel');
    const tableWrap = document.getElementById('rptTableWrap');
    const toggleStickyBtn = document.getElementById('rptToggleStickyScroll');
    const stickyScrollLabel = document.getElementById('rptStickyScrollLabel');
    const range = cfg.range || null;
    const maxRangeDays = Number(cfg.maxRangeDays) || 62;

    /*
     * Filters: Plant (required) + SO creation date preset and / or sales order (optional).
     * From / To inputs are shown only for Custom Date; other presets show their dates as text.
     */
    function bindDateFilters() {
        const presetDates = cfg.presetDates || {};
        const dmy = (ymd) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || ''); return m ? `${m[3]}/${m[2]}/${m[1]}` : ''; };
        const filterError = document.querySelector('[data-filter-error]');

        document.querySelectorAll('[data-date-filter]').forEach(form => {
            const plantSelect = form.querySelector('[data-plant-select]');
            const select = form.querySelector('[data-range-select]');
            const radios = Array.from(form.querySelectorAll('input[type="radio"][name="range"]'));
            const dateInputs = Array.from(form.querySelectorAll('input[type="date"]'));
            const [fromInput, toInput] = dateInputs;
            const soInput = form.querySelector('input[name="so"]');
            const customBlocks = Array.from(form.querySelectorAll('[data-date-custom]'));
            const summary = form.querySelector('[data-date-summary]');
            const isHeader = !!select;
            const chips = form.querySelector('[data-date-chips]');
            const fields = [plantSelect, soInput, select, ...dateInputs].filter(Boolean);
            const currentPreset = () => (select ? select.value : (radios.find(r => r.checked)?.value || ''));
            const boxOf = (field) => field?.closest('label');

            function clearErrors() {
                fields.forEach(field => { field.setCustomValidity(''); boxOf(field)?.classList.remove('is-invalid'); });
                chips?.classList.remove('is-invalid');
                if (filterError) filterError.hidden = true;
            }

            function showError(message, target, alsoMark = []) {
                [target, ...alsoMark].forEach(field => {
                    if (field === chips) chips?.classList.add('is-invalid');
                    else boxOf(field)?.classList.add('is-invalid');
                });
                if (filterError) {
                    filterError.textContent = message;
                    filterError.hidden = false;
                }
                if (target && target !== chips) {
                    target.setCustomValidity(message);
                    target.reportValidity();
                }
            }

            function setPreset(key) {
                if (select) select.value = key;
                radios.forEach(r => { r.checked = r.value === key; r.closest('.date-chip')?.classList.toggle('on', r.checked); });
            }

            function showCustom(on) {
                customBlocks.forEach(el => { el.hidden = !on; });
                dateInputs.forEach(input => { input.disabled = !on; });
            }

            function applyPreset(key) {
                clearErrors();
                const dates = presetDates[key];
                if (dates) {
                    if (fromInput) fromInput.value = dates.from;
                    if (toInput) toInput.value = dates.to;
                } else if (key === '') {
                    dateInputs.forEach(input => { input.value = ''; });
                }
                showCustom(key === 'custom');
                if (summary) {
                    summary.hidden = !dates;
                    const sumFrom = summary.querySelector('[data-sum-from]');
                    const sumTo = summary.querySelector('[data-sum-to]');
                    if (dates && sumFrom) sumFrom.textContent = dmy(dates.from);
                    if (dates && sumTo) sumTo.textContent = dmy(dates.to);
                }
                if (key === 'custom') fromInput?.focus();
            }

            const canSubmit = () => {
                const preset = currentPreset();
                if (!plantSelect?.value) return false;
                if (!preset) return !!(soInput?.value || '').trim();
                return preset !== 'custom' || !!(fromInput?.value && toInput?.value);
            };

            select?.addEventListener('change', () => {
                applyPreset(select.value);
                if (select.value !== 'custom' && canSubmit()) form.requestSubmit();
            });
            // Clicking the selected chip again clears the date (sales order only)
            let activePreset = currentPreset();
            radios.forEach(radio => {
                radio.addEventListener('click', () => {
                    const key = radio.value === activePreset ? '' : radio.value;
                    activePreset = key;
                    setPreset(key);
                    applyPreset(key);
                });
            });
            dateInputs.forEach(input => {
                input.addEventListener('input', clearErrors);
                if (isHeader) input.addEventListener('change', () => { if (fromInput?.value && toInput?.value && canSubmit()) form.requestSubmit(); });
            });
            plantSelect?.addEventListener('change', () => {
                clearErrors();
                if (isHeader && canSubmit()) form.requestSubmit();
            });
            soInput?.addEventListener('input', () => {
                soInput.value = soInput.value.replace(/\D/g, '');
                clearErrors();
            });

            // Back / forward cache restores the fields disabled on submit
            window.addEventListener('pageshow', () => {
                [plantSelect, select, soInput, ...radios].forEach(field => { if (field) field.disabled = false; });
                showCustom(currentPreset() === 'custom');
                showSpinner(false);
            });

            form.addEventListener('submit', (e) => {
                clearErrors();
                const preset = currentPreset();
                const so = (soInput?.value || '').trim();
                const from = fromInput?.value || '';
                const to = toInput?.value || '';
                let message = '';
                let target = null;
                let alsoMark = [];
                if (plantSelect && !plantSelect.value) {
                    message = 'Plant is required.';
                    target = plantSelect;
                } else if (!preset && !so) {
                    message = 'Choose an SO created date (From / To) or enter a sales order.';
                    target = soInput;
                    alsoMark = [chips || select];
                } else if (preset === 'custom') {
                    if (!from || !to) message = 'Choose both a From and a To date.';
                    else if (from > to) message = 'The From date must be on or before the To date.';
                    else if ((Date.parse(to) - Date.parse(from)) / 86400000 + 1 > maxRangeDays) message = `Choose a range of ${maxRangeDays} days or less.`;
                    target = !from ? fromInput : toInput;
                }
                if (message) {
                    e.preventDefault();
                    showError(message, target, alsoMark);
                    return;
                }
                // Leave unused fields out of the URL (presets resolve their own dates on the server)
                if (preset !== 'custom') dateInputs.forEach(input => { input.disabled = true; });
                if (!preset) {
                    if (select) select.disabled = true;
                    radios.forEach(r => { r.disabled = true; });
                }
                if (soInput && !so) soInput.disabled = true;
                showSpinner(true);
            });
        });
    }
    bindDateFilters();

    if (!table || !tbody || !cfg.live) return;

    const mixColors = ['#6d28d9', '#0f766e', '#0284c7', '#d97706', '#16a34a', '#e11d48', '#0ea5e9', '#94a3b8'];
    const stageBars = [
        ['Total SO', 'total_so_qty', '#0f766e'],
        ['Cut Made', 'cut_made', '#0284c7'],
        ['Sew Made', 'sew_made', '#4f46e5'],
        ['Wash Received', 'wash_received', '#16a34a'],
        ['Finished', 'fin_made', '#d97706'],
        ['Shipped', 'shipment', '#7c3aed'],
    ];
    const statKeys = ['total_so_qty', 'cut_made', 'sew_made', 'wash_received', 'fin_made', 'shipment', 'pending_delivery'];

    let items = [];
    let currentSort = { col: null, dir: null };
    let totalsChart = null;
    let mixChart = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtQty(n) {
        const v = Number(n) || 0;
        if (Math.abs(v) < 0.0005) return '—';
        if (Math.abs(v - Math.round(v)) < 0.0005) return Math.round(v).toLocaleString('en-US');
        return v.toLocaleString('en-US', { maximumFractionDigits: 3 });
    }

    function fmtTotal(n) {
        const v = Number(n) || 0;
        if (Math.abs(v) < 0.0005) return '—';
        return (Math.round(v) || 0).toLocaleString('en-US');
    }

    function fmtDate(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        return m ? `${m[3]}-${m[2]}-${m[1]}` : '—';
    }

    function displayValue(col, rec, index) {
        if (col.key === 'sno') return String(index + 1);
        const raw = rec[col.key];
        if (col.type === 'date') return fmtDate(raw);
        if (isQty(col)) return fmtQty(raw);
        const text = String(raw ?? '').trim();
        return text === '' ? '—' : text;
    }

    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    function syncHeaderHeight() {
        const headerRow = table.querySelector('thead tr.rpt-header-row');
        if (headerRow && headerRow.offsetHeight > 0) {
            document.documentElement.style.setProperty('--rpt-header-h', headerRow.offsetHeight + 'px');
        }
    }

    function renderRows(records) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach(tr => tr.remove());
        const frag = document.createDocumentFragment();
        items = records.map((rec, index) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-orig-sno', String(index + 1));
            const texts = [];
            tr.innerHTML = columns.map(col => {
                const text = displayValue(col, rec, index);
                texts.push(text);
                if (col.key === 'sno') return `<td class="num sno">${text}</td>`;
                if (col.type === 'num') {
                    const neg = isQty(col) && Number(rec[col.key]) < 0 ? ' is-neg' : '';
                    return `<td class="num${neg}">${escapeHtml(text)}</td>`;
                }
                return `<td title="${escapeHtml(text)}">${escapeHtml(text)}</td>`;
            }).join('');
            frag.appendChild(tr);
            return { rec, tr, origIndex: index, texts, searchText: texts.slice(1).join(' ').toLowerCase(), visible: true };
        });
        tbody.insertBefore(frag, noMatchRow);
    }

    function showEmpty(message) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach(tr => tr.remove());
        const tr = document.createElement('tr');
        tr.className = 'rpt-table-loading';
        tr.innerHTML = `<td colspan="${columns.length}" class="rpt-table-empty">${escapeHtml(message)}</td>`;
        tbody.insertBefore(tr, noMatchRow);
        if (countBadge) countBadge.textContent = 'Showing 0 lines';
    }

    function showNotice(message) {
        const notice = document.getElementById('prodNotice');
        const noticeText = document.getElementById('prodNoticeText');
        if (!notice || !noticeText) return;
        noticeText.textContent = message;
        notice.hidden = false;
    }

    function fillInfo(data) {
        const info = data.info || {};
        const box = document.getElementById('prodInfo');
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value || '—'; };
        if (range) {
            const found = Number(data.orders_found) || 0;
            const withData = Number(data.sales_orders) || 0;
            set('prodInfoOrders', found === withData ? withData.toLocaleString('en-US') : `${withData.toLocaleString('en-US')} of ${found.toLocaleString('en-US')} with production`);
            set('prodInfoPlant', (data.plants || []).join(', '));
            set('prodInfoCustomers', info.customers ? Number(info.customers).toLocaleString('en-US') : '');
            set('prodInfoLines', (Number(data.total) || 0).toLocaleString('en-US'));
            if (box) box.hidden = false;
            const notice = document.getElementById('prodNotice');
            const noticeText = document.getElementById('prodNoticeText');
            if (notice && noticeText && data.warning) {
                noticeText.textContent = data.warning;
                notice.hidden = false;
            }
            return;
        }
        set('prodInfoSo', data.sales_order || cfg.so);
        set('prodInfoPlant', (data.plants || []).join(', '));
        set('prodInfoCustomer', [info.customer_name, info.customer ? `(${info.customer})` : ''].filter(Boolean).join(' '));
        set('prodInfoMaterial', info.header_material);
        set('prodInfoDelivery', info.delivery_date ? fmtDate(info.delivery_date) : '');
        set('prodInfoTolerance', info.tolerance);
        if (box) box.hidden = false;

        const notice = document.getElementById('prodNotice');
        const noticeText = document.getElementById('prodNoticeText');
        if (notice && noticeText && data.warning) {
            noticeText.textContent = data.warning;
            notice.hidden = false;
        }
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
                else if (col.type === 'num') res = (Number(a.rec[col.key]) || 0) - (Number(b.rec[col.key]) || 0);
                else res = String(a.rec[col.key] ?? '').localeCompare(String(b.rec[col.key] ?? ''), undefined, { numeric: true, sensitivity: 'base' });
                return (res || a.origIndex - b.origIndex) * dir;
            });
        }
        const frag = document.createDocumentFragment();
        items.forEach(item => frag.appendChild(item.tr));
        frag.appendChild(noMatchRow);
        tbody.appendChild(frag);
    }

    function updateSortUI() {
        table.querySelectorAll('thead th.is-sortable').forEach(th => {
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
        table.querySelectorAll('.rpt-col-input').forEach(input => {
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

    function applyFilterAndSearch() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filters = colFilters();
        if (searchClear) searchClear.style.display = query !== '' ? 'flex' : 'none';

        const sums = {};
        columns.forEach(c => { if (isQty(c)) sums[c.key] = 0; });
        const colourTotals = {};
        let visible = 0;
        items.forEach(item => {
            item.visible = matches(item, query, filters);
            item.tr.style.display = item.visible ? '' : 'none';
            if (!item.visible) return;
            visible++;
            const sno = item.tr.querySelector('td.sno');
            if (sno) sno.textContent = String(visible);
            Object.keys(sums).forEach(k => { sums[k] += Number(item.rec[k]) || 0; });
            const colour = String(item.rec.colour || '').trim() || 'Unspecified';
            colourTotals[colour] = (colourTotals[colour] || 0) + (Number(item.rec.total_so_qty) || 0);
        });

        const isFiltered = query !== '' || filters.length > 0;
        if (noMatchRow) noMatchRow.style.display = items.length > 0 && visible === 0 ? '' : 'none';
        if (countBadge) {
            countBadge.textContent = `Showing ${visible.toLocaleString('en-US')} of ${items.length.toLocaleString('en-US')} lines`;
            countBadge.classList.toggle('is-filtered', isFiltered);
        }
        if (activeFilterBadge) {
            activeFilterBadge.style.display = filters.length > 0 ? 'inline-flex' : 'none';
            activeFilterBadge.textContent = String(filters.length);
        }
        if (clearAllBtn) clearAllBtn.style.display = (isFiltered || currentSort.col !== null) ? 'inline-flex' : 'none';
        if (footTotalLabel) {
            footTotalLabel.textContent = isFiltered
                ? `Total (${visible} of ${items.length} lines)`
                : `Total (${items.length} lines)`;
        }
        table.querySelectorAll('tfoot [data-foot-col]').forEach(th => {
            const col = columns[parseInt(th.getAttribute('data-foot-col'), 10)];
            if (!col || !isQty(col)) return;
            th.innerHTML = `<span class="foot-val">${fmtTotal(sums[col.key])}</span>`;
            th.classList.toggle('is-neg', sums[col.key] < 0);
        });
        statKeys.forEach(k => {
            const el = document.getElementById('prodStat-' + k);
            if (el) {
                el.textContent = fmtQty(sums[k]);
                el.classList.toggle('is-neg', (sums[k] || 0) < 0);
            }
        });
        updateCharts(sums, colourTotals);
    }

    function updateCharts(sums, colourTotals) {
        if (typeof Chart === 'undefined') return;
        const barValues = stageBars.map(([, key]) => Math.round((sums[key] || 0) * 1000) / 1000);
        const sorted = Object.entries(colourTotals).sort((a, b) => b[1] - a[1]);
        const top = sorted.slice(0, 7);
        const rest = sorted.slice(7).reduce((s, [, v]) => s + v, 0);
        if (rest > 0) top.push(['Other', rest]);
        const labels = top.map(([k]) => k);
        const values = top.map(([, v]) => Math.round(v * 1000) / 1000);

        const totalsEl = document.getElementById('rptTotalsChart');
        const mixEl = document.getElementById('rptMixChart');
        if (!totalsChart && totalsEl) {
            totalsChart = new Chart(totalsEl, {
                type: 'bar',
                data: { labels: stageBars.map(([label]) => label), datasets: [{ label: 'Quantity', data: barValues, backgroundColor: stageBars.map(([, , color]) => color), borderRadius: 8, maxBarThickness: 42 }] },
                options: { responsive: true, maintainAspectRatio: false, animation: { duration: 500 }, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true } } },
            });
        } else if (totalsChart) {
            totalsChart.data.datasets[0].data = barValues;
            totalsChart.update('none');
        }
        const mixData = {
            labels: labels.length ? labels : ['No data'],
            data: values.length ? values : [1],
            colors: values.length ? mixColors.slice(0, values.length) : ['#e2e8e0'],
        };
        if (!mixChart && mixEl) {
            mixChart = new Chart(mixEl, {
                type: 'doughnut',
                data: { labels: mixData.labels, datasets: [{ data: mixData.data, backgroundColor: mixData.colors, borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%', animation: { duration: 500 }, plugins: { legend: { display: values.length > 0, position: 'right', labels: { boxWidth: 10, font: { size: 11 } } } } },
            });
        } else if (mixChart) {
            mixChart.data.labels = mixData.labels;
            mixChart.data.datasets[0].data = mixData.data;
            mixChart.data.datasets[0].backgroundColor = mixData.colors;
            mixChart.options.plugins.legend.display = values.length > 0;
            mixChart.update('none');
        }
    }

    function clearAllFilters() {
        if (searchInput) searchInput.value = '';
        table.querySelectorAll('.rpt-col-input').forEach(i => { i.value = ''; });
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

    function bindControls() {
        let searchTimer = null;
        let filterTimer = null;
        searchInput?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilterAndSearch, 80); });
        searchInput?.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; applyFilterAndSearch(); } else if (e.key === 'Enter') e.preventDefault(); });
        searchClear?.addEventListener('click', () => { if (searchInput) { searchInput.value = ''; searchInput.focus(); } applyFilterAndSearch(); });

        table.querySelectorAll('.rpt-col-input').forEach(input => {
            input.addEventListener('input', () => { clearTimeout(filterTimer); filterTimer = setTimeout(applyFilterAndSearch, 100); });
            input.addEventListener('keydown', e => { if (e.key === 'Escape') { input.value = ''; applyFilterAndSearch(); } else if (e.key === 'Enter') e.preventDefault(); });
            input.parentElement?.querySelector('.rpt-col-clear')?.addEventListener('click', e => { e.stopPropagation(); input.value = ''; input.focus(); applyFilterAndSearch(); });
        });

        table.querySelectorAll('thead th.is-sortable').forEach(th => {
            th.addEventListener('click', () => {
                const col = parseInt(th.getAttribute('data-col'), 10);
                if (currentSort.col === col) {
                    currentSort = currentSort.dir === 'asc' ? { col, dir: 'desc' } : { col: null, dir: null };
                } else {
                    currentSort = { col, dir: 'asc' };
                }
                updateSortUI();
                sortItems();
                applyFilterAndSearch();
            });
        });

        colFilterResetBtn?.addEventListener('click', () => { table.querySelectorAll('.rpt-col-input').forEach(i => { i.value = ''; }); applyFilterAndSearch(); });
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
        toggleStickyBtn?.addEventListener('click', () => applyStickyMode(!(tableWrap && !tableWrap.classList.contains('is-expanded'))));
        window.addEventListener('resize', syncHeaderHeight);
    }

    const columnFeatures = window.rptSetupColumnFeatures
        ? window.rptSetupColumnFeatures(table, { onChange: syncHeaderHeight })
        : { refresh() {} };

    function getJson(params) {
        params.set('_', String(Date.now()));
        return fetch(`${cfg.dataUrl}?${params.toString()}`, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
            .then(async (res) => {
                const raw = await res.text();
                let json;
                try { json = JSON.parse(raw); } catch (e) {
                    const type = res.headers.get('Content-Type') || '';
                    if (res.redirected || /login/i.test(res.url) || (res.ok && /text\/html/i.test(type))) {
                        throw Object.assign(new Error('Your portal session has expired. Please log in again and reopen the report.'), { fatal: true });
                    }
                    throw new Error(`SAP data could not be read (HTTP ${res.status}). Please retry.`);
                }
                if (!res.ok || json.success === false) {
                    throw Object.assign(new Error(json.error || json.message || `SAP API failed (${res.status})`), { fatal: res.status === 401 || res.status === 422 });
                }
                return json.data || {};
            });
    }

    function showLoadError(err) {
        showEmpty(err.message || 'Unable to load SAP data.');
        const alertBox = document.createElement('div');
        alertBox.className = 'rpt-alert';
        alertBox.textContent = err.message || 'Unable to load SAP data.';
        document.querySelector('.rpt-main')?.prepend(alertBox);
    }

    function loadSalesOrder() {
        showSpinner(true);
        getJson(new URLSearchParams({ so: cfg.so || '', plant: cfg.plant || '' }))
            .then((data) => {
                const records = Array.isArray(data.records) ? data.records : [];
                fillInfo(data);
                bindControls();
                if (!records.length) {
                    const plantText = (data.plants || []).length ? ` (plant ${data.plants.join(', ')})` : '';
                    showEmpty(`No production data for sales order ${cfg.so}${plantText}.`);
                    updateCharts({}, {});
                    return;
                }
                renderRows(records);
                columnFeatures.refresh();
                applyFilterAndSearch();
            })
            .catch(showLoadError)
            .finally(revealReport);
    }

    function revealReport() {
        showSpinner(false);
        document.getElementById('rptMain')?.classList.remove('is-pending');
        syncHeaderHeight();
        totalsChart?.resize();
        mixChart?.resize();
    }

    /*
     * Date-range mode: get the sales orders created in the range, then their production in small parallel batches
     * (SAP needs up to ~20 s per order without production). The dashboard stays behind the loader until every batch is in.
     */
    function loadRange() {
        const unique = (list) => Array.from(new Set(list.filter(Boolean)));
        const bySalesOrder = (a, b) => (Number(a.sales_order) - Number(b.sales_order)) || String(a.plant).localeCompare(String(b.plant)) || (Number(a.so_item) - Number(b.so_item));

        function setProgress(done, total) {
            window.rptLoader?.progress(done, total, done < total ? 'Fetching production from SAP…' : 'Preparing report…', 'sales orders');
        }

        function summary(records, found, warning) {
            return {
                orders_found: found,
                sales_orders: unique(records.map(r => r.sales_order)).length,
                plants: unique(records.map(r => r.plant)).sort(),
                total: records.length,
                info: { customers: unique(records.map(r => r.customer)).length },
                warning,
            };
        }

        showSpinner(true);
        getJson(new URLSearchParams({ range: range.preset, from: range.from, to: range.to, so: cfg.so || '', plant: cfg.plant || '' }))
            .then((plan) => {
                bindControls();
                const orders = Array.isArray(plan.orders) ? plan.orders : [];
                const found = Number(plan.orders_found) || 0;
                fillInfo(summary([], found, plan.warning || ''));
                if (!orders.length) {
                    const message = `No production sales orders for ${range.filter} created ${range.text}.`;
                    showEmpty(message);
                    showNotice(`${message} Try a wider date${cfg.so ? ', or clear the sales order' : ' such as This Week or This Month'}.`);
                    updateCharts({}, {});
                    return null;
                }

                const size = Math.max(1, Number(plan.batch_size) || 4);
                const batches = [];
                for (let i = 0; i < orders.length; i += size) batches.push(orders.slice(i, i + size));
                const records = [];
                const failed = [];
                let done = 0;
                let next = 0;
                let fatal = null;
                setProgress(0, orders.length);

                const worker = async () => {
                    while (next < batches.length && !fatal) {
                        const batch = batches[next++];
                        try {
                            const data = await getJson(new URLSearchParams({ pairs: batch.map(o => `${o.so}-${o.plant}`).join(',') }));
                            if (Array.isArray(data.records)) records.push(...data.records);
                            failed.push(...(Array.isArray(data.failed) ? data.failed : []));
                        } catch (err) {
                            if (err.fatal) { fatal = err; return; }
                            failed.push(...batch.map(o => o.so));
                        }
                        done += batch.length;
                        setProgress(Math.min(done, orders.length), orders.length);
                    }
                };

                return Promise.all(Array.from({ length: Math.min(Math.max(1, Number(plan.batch_parallel) || 3), batches.length) }, worker)).then(() => {
                    if (fatal) throw fatal;
                    const failedOrders = unique(failed.map(String));
                    const warnings = plan.warning ? [plan.warning] : [];
                    if (failedOrders.length) {
                        warnings.push(`Production data could not be loaded for ${failedOrders.length} sales order(s): ${failedOrders.slice(0, 10).join(', ')}${failedOrders.length > 10 ? '…' : ''}. Reload to retry.`);
                    }
                    fillInfo(summary(records, found, warnings.join(' ')));
                    if (records.length) {
                        records.sort(bySalesOrder);
                        renderRows(records);
                        columnFeatures.refresh();
                        sortItems();
                        applyFilterAndSearch();
                    } else {
                        showEmpty(`No production data yet for the ${found} sales order(s) of ${range.filter} created ${range.text}.`);
                        if (!failedOrders.length && !plan.warning) showNotice(`The ${found} sales order(s) of ${range.filter} created ${range.text} have no cutting, sewing or shipment entries in SAP yet.`);
                        updateCharts({}, {});
                    }
                });
            })
            .catch(showLoadError)
            .finally(revealReport);
    }

    if (range) loadRange();
    else loadSalesOrder();
})();
