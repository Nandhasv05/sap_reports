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
    function bindListFilterControls() {
        const form = $('poFilterForm');
        if (!form) return;

        const rangeSelect = form.querySelector('[data-range-select]');
        const customDateBlock = form.querySelector('[data-date-custom]');
        const fromInput = form.querySelector('[name="from"]');
        const toInput = form.querySelector('[name="to"]');
        const poSearchInput = form.querySelector('#po');

        if (rangeSelect && customDateBlock) {
            rangeSelect.addEventListener('change', () => {
                const val = rangeSelect.value;
                const isCustom = val === 'custom';
                customDateBlock.style.display = isCustom ? 'flex' : 'none';
                customDateBlock.hidden = !isCustom;
                if (!isCustom && cfg.presetDates && cfg.presetDates[val]) {
                    if (fromInput) fromInput.value = cfg.presetDates[val].from;
                    if (toInput) toInput.value = cfg.presetDates[val].to;
                    showSpinner(true);
                    form.submit();
                }
            });
        }

        form.addEventListener('submit', (e) => {
            const typedPo = P.cleanPo(poSearchInput ? poSearchInput.value : '');
            if (typedPo !== '') {
                e.preventDefault();
                if (!P.validPo(typedPo)) {
                    poSearchInput.setCustomValidity('Purchase order must be a number of up to 10 digits.');
                    poSearchInput.reportValidity();
                    return;
                }
                showSpinner(true);
                window.location.href = `${cfg.pageUrl}?po=${encodeURIComponent(typedPo)}`;
                return;
            }

            if (rangeSelect && rangeSelect.value === 'custom') {
                const fVal = fromInput ? fromInput.value.trim() : '';
                const tVal = toInput ? toInput.value.trim() : '';
                if (!fVal || !tVal) {
                    e.preventDefault();
                    if (!fVal && fromInput) {
                        fromInput.setCustomValidity('Select a From date.');
                        fromInput.reportValidity();
                        fromInput.focus();
                    } else if (toInput) {
                        toInput.setCustomValidity('Select a To date.');
                        toInput.reportValidity();
                        toInput.focus();
                    }
                    return;
                }
                const minAllowed = new Date();
                minAllowed.setDate(minAllowed.getDate() - 186);
                minAllowed.setHours(0, 0, 0, 0);
                const fromD = new Date(fVal);
                if (fromD < minAllowed) {
                    e.preventDefault();
                    fromInput.setCustomValidity('Select dates within the last 6 months.');
                    fromInput.reportValidity();
                    fromInput.focus();
                    return;
                }
                const toD = new Date(tVal);
                if (fromD > toD) {
                    e.preventDefault();
                    toInput.setCustomValidity('To date must be on or after From date.');
                    toInput.reportValidity();
                    toInput.focus();
                    return;
                }
            }

            showSpinner(true);
        });
    }

    function renderListCell(key, rec, index) {
        switch (key) {
            case 'sno':
                return `<td class="num sno">${index + 1}</td>`;
            case 'purchase_order':
                return `<td><a href="${cfg.pageUrl}?po=${P.esc(rec.purchase_order)}" class="po-num-link" data-po-open="${P.esc(rec.purchase_order)}" title="Open Purchase Order ${P.esc(rec.purchase_order)}"><span class="po-item-tag is-link"><i class="fas fa-file-invoice"></i> ${P.esc(rec.purchase_order)}</span></a></td>`;
            case 'date':
                return `<td>${P.fmtDate(rec.date)}</td>`;
            case 'plant':
                return `<td><span class="po-plant-badge">${P.dash(rec.plant)}</span></td>`;
            case 'supplier':
                return `<td>${P.dash(rec.supplier)}</td>`;
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
            default:
                return `<td>${P.dash(rec[key])}</td>`;
        }
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
        if ($('poListTotalBadge')) $('poListTotalBadge').textContent = `${fmtRound(t.pos_count || 0)} Purchase Orders found`;
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

    function renderDetailCell(key, rec, index) {
        switch (key) {
            case 'sno': return `<td class="num sno">${index + 1}</td>`;
            case 'item': return `<td><span class="po-item-tag">${P.dash(rec.item)}</span></td>`;
            case 'material': return `<td class="rpt-mat">${P.dash(rec.material)}</td>`;
            case 'description': return `<td class="po-desc" title="${P.esc(rec.description)}">${P.dash(rec.description)}</td>`;
            case 'order_qty': return `<td class="num"><span class="qty-val">${P.qty(rec.order_qty)}</span></td>`;
            case 'net_price': return `<td class="num"><span class="qty-val">${P.money(rec.net_price)}</span></td>`;
            case 'net_value': return `<td class="num po-value"><span class="qty-val">${P.money(rec.net_value)}</span></td>`;
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

    function applyFilterAndSearch() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const filters = colFilters();
        if (searchClear) searchClear.style.display = query !== '' ? 'flex' : 'none';

        let visible = 0;
        const sums = isList
            ? { items_count: 0, total_qty: 0, total_value: 0 }
            : { order_qty: 0, net_value: 0 };

        items.forEach((item) => {
            item.visible = matches(item, query, filters);
            item.tr.style.display = item.visible ? '' : 'none';
            if (!item.visible) return;
            visible++;
            const sno = item.tr.querySelector('td.sno');
            if (sno) sno.textContent = String(visible);
            Object.keys(sums).forEach((k) => { sums[k] += Number(item.rec[k]) || 0; });
        });

        const isFiltered = query !== '' || filters.length > 0;
        if (noMatchRow) noMatchRow.style.display = items.length > 0 && visible === 0 ? '' : 'none';
        if (countBadge) {
            countBadge.textContent = isList
                ? `Showing ${visible} of ${items.length} purchase orders`
                : `Showing ${visible} of ${items.length} items`;
            countBadge.classList.toggle('is-filtered', isFiltered);
        }
        if (activeFilterBadge) {
            activeFilterBadge.style.display = filters.length > 0 ? 'inline-flex' : 'none';
            activeFilterBadge.textContent = String(filters.length);
        }
        if (clearAllBtn) clearAllBtn.style.display = isFiltered || currentSort.col !== null ? 'inline-flex' : 'none';
        if (footTotalLabel) {
            footTotalLabel.textContent = isFiltered
                ? `Total (${visible} of ${items.length})`
                : `Total (${items.length})`;
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

    function bindControls() {
        let searchTimer = null;
        let filterTimer = null;
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
        const params = new URLSearchParams({
            list: '1',
            range: cfg.range?.preset || '',
            from: cfg.range?.from || '',
            to: cfg.range?.to || '',
            plant: cfg.plant || '',
            supplier: cfg.supplier || '',
            so: cfg.salesOrder || '',
            _: String(Date.now()),
        });

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
                renderListRows(records);
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
        P.fetchPo(cfg.po)
            .then((data) => {
                const records = Array.isArray(data.records) ? data.records : [];
                bindControls();
                if (!records.length) {
                    $('poHeroSub').textContent = 'Not found in SAP';
                    showMessage(`No purchase order <b>${P.esc(cfg.po)}</b> was found in SAP.`);
                    return;
                }
                const h = data.header || {};
                totals = data.totals || {};
                document.title = `EVOLV | Purchase Order ${h.purchase_order}`;
                fillHero(h, totals);
                fillDetailStats(data);
                renderDetailRows(records);
                columnFeatures.refresh();
                applyFilterAndSearch();
                fillCharts(records, totals);
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
