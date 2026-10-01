/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Procurement report - live SAP components, PR line drill-down, search, column filters, sorting, totals and charts
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
    const toggleAllPrBtn = document.getElementById('rptToggleAllPr');
    const toggleAllPrLabel = document.getElementById('rptToggleAllPrLabel');
    if (!table || !tbody || !cfg.live) return;

    const mixColors = ['#be123c', '#0f766e', '#0284c7', '#d97706', '#6d28d9', '#16a34a', '#0ea5e9', '#94a3b8'];
    const groupBars = [
        ['Total PR', 'total_pr_qty', '#4f46e5'],
        ['Total PO', 'total_po_qty', '#16a34a'],
        ['Balance PR', 'balance_pr_qty', '#e11d48'],
    ];

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

    const today = (() => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    })();

    const prStatuses = [
        ['open', 'Open'],
        ['overdue', 'Overdue'],
        ['done', 'Fully ordered'],
        ['over', 'Over-ordered'],
    ];
    
    const prStatusLabel = Object.fromEntries(prStatuses);

    function prStatus(pr) {
        const balance = Number(pr.balance_pr_qty) || 0;
        if (balance < -0.0005) return 'over';
        if (balance <= 0.0005) return 'done';
        return pr.delivery_date && pr.delivery_date < today ? 'overdue' : 'open';
    }

    function poPercent(pr) {
        const prQty = Number(pr.pr_qty) || 0;
        return prQty > 0 ? ((Number(pr.po_qty) || 0) / prQty) * 100 : 0;
    }

    function fmtPct(p) {
        return `${p >= 99.95 || p === 0 ? Math.round(p) : p.toFixed(1)}%`;
    }

    function progressBar(p) {
        const cls = p > 100.05 ? ' is-over' : (p >= 99.95 ? ' is-done' : '');
        return `<span class="proc-bar${cls}"><i style="width:${Math.min(100, Math.max(0, p)).toFixed(1)}%"></i></span>`;
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

    function prCell(rec, index) {
        const count = Number(rec.pr_count) || 0;
        if (count === 0) return '<td class="num">—</td>';
        return `<td class="num"><button type="button" class="proc-pr-toggle" data-item="${index}" aria-expanded="false" title="Show the ${count} PR lines of this component">`
            + `<span class="material-icons-round" aria-hidden="true">expand_more</span><span class="qty-val">${count.toLocaleString('en-US')}</span></button></td>`;
    }

    function renderRows(records) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno], tr.proc-detail').forEach(tr => tr.remove());
        const frag = document.createDocumentFragment();
        items = records.map((rec, index) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-orig-sno', String(index + 1));
            const texts = [];
            tr.innerHTML = columns.map(col => {
                const text = displayValue(col, rec, index);
                texts.push(text);
                if (col.key === 'sno') return `<td class="num sno">${text}</td>`;
                if (col.key === 'pr_count') return prCell(rec, index);
                if (col.type === 'num') {
                    const neg = isQty(col) && Number(rec[col.key]) < 0 ? ' is-neg' : '';
                    return `<td class="num${neg}">${escapeHtml(text)}</td>`;
                }
                return `<td title="${escapeHtml(text)}">${escapeHtml(text)}</td>`;
            }).join('');
            frag.appendChild(tr);
            const prNumbers = (rec.pr_lines || []).map(pr => pr.pr_number).join(' ');
            return {
                rec, tr, origIndex: index, texts,
                searchText: (texts.slice(1).join(' ') + ' ' + prNumbers).toLowerCase(),
                visible: true, open: false, detailTr: null,
            };
        });
        tbody.insertBefore(frag, noMatchRow);
    }

    /*
     * PR line panel columns. Requirement Qty is the component requirement repeated on every PR line,
     * so it only gets its own column when the lines disagree.
     */
    function prPanelColumns(showRequirement) {
        return [
            { key: 'idx', label: '#', num: true, sort: false },
            { key: 'pr_number', label: 'PR Number' },
            { key: 'pr_item', label: 'Item', num: true },
            ...(showRequirement ? [{ key: 'requirement_qty', label: 'Requirement', num: true }] : []),
            { key: 'pr_qty', label: 'PR Qty', num: true },
            { key: 'po_qty', label: 'PO Qty', num: true },
            { key: 'balance_pr_qty', label: 'Balance', num: true },
            { key: 'po_pct', label: 'PO Progress', num: true },
            { key: 'delivery_date', label: 'Delivery Date' },
            { key: 'status', label: 'Status' },
        ];
    }

    function buildDetail(item) {
        const rec = item.rec;
        const lines = (Array.isArray(rec.pr_lines) ? rec.pr_lines : []).map((pr, i) => ({
            ...pr, idx: i, po_pct: poPercent(pr), status: prStatus(pr),
        }));
        const reqValues = new Set(lines.map(l => Number(l.requirement_qty) || 0));
        const panel = {
            lines,
            cols: prPanelColumns(reqValues.size > 1),
            filter: 'all',
            query: '',
            sort: { key: null, dir: 1 },
        };
        item.panel = panel;

        const totals = lines.reduce((s, l) => {
            s.pr += Number(l.pr_qty) || 0;
            s.po += Number(l.po_qty) || 0;
            s.bal += Number(l.balance_pr_qty) || 0;
            return s;
        }, { pr: 0, po: 0, bal: 0 });
        const coverage = totals.pr > 0 ? (totals.po / totals.pr) * 100 : 0;
        const counts = lines.reduce((c, l) => { c[l.status] = (c[l.status] || 0) + 1; return c; }, {});
        const metric = (label, value, cls = '') => `<div class="proc-metric${cls}"><small>${label}</small><b>${value}</b></div>`;
        const meta = [rec.material_group, rec.material_type, rec.shade_text1].filter(Boolean).map(escapeHtml).join(' · ');
        const chips = [['all', 'All', lines.length], ...prStatuses.map(([k, label]) => [k, label, counts[k] || 0]).filter(([, , n]) => n > 0)]
            .map(([k, label, n]) => `<button type="button" class="proc-chip is-${k}${k === 'all' ? ' is-active' : ''}" data-pr-filter="${k}">${label}<span>${n.toLocaleString('en-US')}</span></button>`)
            .join('');

        const tr = document.createElement('tr');
        tr.className = 'proc-detail';
        tr.setAttribute('data-item', String(item.origIndex));
        tr.innerHTML = `<td colspan="${columns.length}"><div class="proc-detail-inner">
            <div class="proc-panel">
                <div class="proc-dh">
                    <div class="proc-dh-title">
                        <span class="proc-dh-icon material-icons-round">receipt_long</span>
                        <div>
                            <b>${escapeHtml(rec.component_material)}</b>
                            <span>${escapeHtml(rec.component_description)}</span>
                            ${meta ? `<small>${meta}</small>` : ''}
                        </div>
                    </div>
                    <div class="proc-dh-metrics">
                        ${reqValues.size === 1 ? metric('Requirement', fmtQty([...reqValues][0])) : ''}
                        ${metric('PR Lines', lines.length.toLocaleString('en-US'))}
                        ${metric('PR Qty', fmtQty(totals.pr))}
                        ${metric('PO Qty', fmtQty(totals.po))}
                        ${metric('Balance', fmtQty(totals.bal), totals.bal < 0 ? ' is-neg' : '')}
                        <div class="proc-metric proc-coverage">
                            <small>PO vs PR <b>${fmtPct(coverage)}</b></small>
                            ${progressBar(coverage)}
                        </div>
                    </div>
                    <button type="button" class="proc-dh-close" data-pr-close title="Hide PR lines" aria-label="Hide PR lines">
                        <span class="material-icons-round">close</span>
                    </button>
                </div>
                <div class="proc-dt">
                    <div class="proc-chips">${chips}</div>
                    <label class="proc-dt-search">
                        <span class="material-icons-round">search</span>
                        <input type="search" data-pr-search placeholder="Search PR number or date…" autocomplete="off" spellcheck="false">
                    </label>
                    <span class="proc-dt-count" data-pr-count></span>
                </div>
                <div class="proc-detail-scroll">
                    <table class="proc-pr-table">
                        <thead><tr>${panel.cols.map(c => `<th class="${c.num ? 'num' : ''}${c.sort === false ? '' : ' is-sortable'}" data-pr-sort="${c.key}">${c.label}</th>`).join('')}</tr></thead>
                        <tbody></tbody>
                        <tfoot></tfoot>
                    </table>
                </div>
            </div>
        </div></td>`;
        return tr;
    }

    function renderPanel(item) {
        const panel = item.panel;
        const root = item.detailTr;
        if (!panel || !root) return;
        const q = panel.query.toLowerCase();
        let rows = panel.lines.filter(l => (panel.filter === 'all' || l.status === panel.filter)
            && (q === '' || l.pr_number.toLowerCase().includes(q) || fmtDate(l.delivery_date).includes(q)));
        if (panel.sort.key) {
            const { key, dir } = panel.sort;
            rows = rows.slice().sort((a, b) => {
                const av = a[key];
                const bv = b[key];
                const res = typeof av === 'number' && typeof bv === 'number'
                    ? av - bv
                    : String(av ?? '').localeCompare(String(bv ?? ''), undefined, { numeric: true });
                return (res || a.idx - b.idx) * dir;
            });
        }

        const sums = { requirement_qty: 0, pr_qty: 0, po_qty: 0, balance_pr_qty: 0 };
        const body = rows.map((l, i) => {
            Object.keys(sums).forEach(k => { sums[k] += Number(l[k]) || 0; });
            return '<tr>' + panel.cols.map(c => {
                switch (c.key) {
                    case 'idx': return `<td class="num proc-idx">${i + 1}</td>`;
                    case 'pr_number': return `<td class="proc-prno">${escapeHtml(l.pr_number || '—')}</td>`;
                    case 'pr_item': return `<td class="num">${escapeHtml(l.pr_item || '—')}</td>`;
                    case 'po_pct': return `<td class="num"><div class="proc-progress">${progressBar(l.po_pct)}<span>${fmtPct(l.po_pct)}</span></div></td>`;
                    case 'delivery_date': return `<td class="${l.status === 'overdue' ? 'is-overdue' : ''}">${fmtDate(l.delivery_date)}</td>`;
                    case 'status': return `<td><span class="proc-status is-${l.status}">${prStatusLabel[l.status]}</span></td>`;
                    default: return `<td class="num${Number(l[c.key]) < 0 ? ' is-neg' : ''}">${fmtQty(l[c.key])}</td>`;
                }
            }).join('') + '</tr>';
        }).join('');

        const tbodyEl = root.querySelector('.proc-pr-table tbody');
        tbodyEl.innerHTML = body || `<tr><td class="proc-empty" colspan="${panel.cols.length}">No PR lines match this filter.</td></tr>`;
        const pct = sums.pr_qty > 0 ? (sums.po_qty / sums.pr_qty) * 100 : 0;
        root.querySelector('.proc-pr-table tfoot').innerHTML = rows.length ? '<tr>' + panel.cols.map((c, i) => {
            if (i === 0) return '<th></th>';
            if (c.key === 'pr_number') return `<th>Total · ${rows.length.toLocaleString('en-US')} lines</th>`;
            if (c.key === 'po_pct') return `<th class="num"><div class="proc-progress">${progressBar(pct)}<span>${fmtPct(pct)}</span></div></th>`;
            if (c.key in sums) return `<th class="num${sums[c.key] < 0 ? ' is-neg' : ''}">${fmtTotal(sums[c.key])}</th>`;
            return '<th></th>';
        }).join('') + '</tr>' : '';

        root.querySelectorAll('[data-pr-filter]').forEach(b => b.classList.toggle('is-active', b.getAttribute('data-pr-filter') === panel.filter));
        root.querySelectorAll('th[data-pr-sort]').forEach(th => {
            const on = panel.sort.key === th.getAttribute('data-pr-sort');
            th.classList.toggle('is-sorted-asc', on && panel.sort.dir === 1);
            th.classList.toggle('is-sorted-desc', on && panel.sort.dir === -1);
        });
        const countEl = root.querySelector('[data-pr-count]');
        if (countEl) countEl.textContent = `Showing ${rows.length.toLocaleString('en-US')} of ${panel.lines.length.toLocaleString('en-US')} PR lines`;
    }

    function syncDetailWidth() {
        if (tableWrap) tableWrap.style.setProperty('--proc-detail-w', tableWrap.clientWidth + 'px');
    }

    function setOpen(item, open) {
        if (!item || (Number(item.rec.pr_count) || 0) === 0) return;
        item.open = open;
        if (open && !item.detailTr) {
            syncDetailWidth();
            item.detailTr = buildDetail(item);
            item.tr.after(item.detailTr);
            renderPanel(item);
        }
        if (item.detailTr) item.detailTr.style.display = open && item.visible ? '' : 'none';
        item.tr.classList.toggle('is-open', open);
        const btn = item.tr.querySelector('.proc-pr-toggle');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function updateToggleAllLabel() {
        const anyClosed = items.some(i => i.visible && !i.open && (Number(i.rec.pr_count) || 0) > 0);
        if (toggleAllPrLabel) toggleAllPrLabel.textContent = anyClosed ? 'Expand PR Lines' : 'Collapse PR Lines';
        toggleAllPrBtn?.classList.toggle('is-active', !anyClosed && items.some(i => i.open));
    }

    function showEmpty(message) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno], tr.proc-detail').forEach(tr => tr.remove());
        const tr = document.createElement('tr');
        tr.className = 'rpt-table-loading';
        tr.innerHTML = `<td colspan="${columns.length}" class="rpt-table-empty">${escapeHtml(message)}</td>`;
        tbody.insertBefore(tr, noMatchRow);
        if (countBadge) countBadge.textContent = 'Showing 0 components';
    }

    function fillInfo(data) {
        const info = data.info || {};
        const box = document.getElementById('procInfo');
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value || '—'; };
        set('procInfoSo', data.sales_order || cfg.so);
        set('procInfoStyle', info.style);
        set('procInfoStyleDesc', info.style_description);
        set('procInfoTypes', (info.material_types || []).join(', '));
        if (box) box.hidden = false;
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
        items.forEach(item => {
            frag.appendChild(item.tr);
            if (item.detailTr) frag.appendChild(item.detailTr);
        });
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
        const groups = {};
        let visible = 0;
        items.forEach(item => {
            item.visible = matches(item, query, filters);
            item.tr.style.display = item.visible ? '' : 'none';
            if (item.detailTr) item.detailTr.style.display = item.visible && item.open ? '' : 'none';
            if (!item.visible) return;
            visible++;
            const sno = item.tr.querySelector('td.sno');
            if (sno) sno.textContent = String(visible);
            Object.keys(sums).forEach(k => { sums[k] += Number(item.rec[k]) || 0; });
            const group = String(item.rec.material_group || '').trim() || 'Unspecified';
            if (!groups[group]) groups[group] = { count: 0, total_pr_qty: 0, total_po_qty: 0, balance_pr_qty: 0 };
            groups[group].count++;
            groupBars.forEach(([, key]) => { groups[group][key] += Number(item.rec[key]) || 0; });
        });

        const isFiltered = query !== '' || filters.length > 0;
        if (noMatchRow) noMatchRow.style.display = items.length > 0 && visible === 0 ? '' : 'none';
        if (countBadge) {
            countBadge.textContent = `Showing ${visible.toLocaleString('en-US')} of ${items.length.toLocaleString('en-US')} components`;
            countBadge.classList.toggle('is-filtered', isFiltered);
        }
        if (activeFilterBadge) {
            activeFilterBadge.style.display = filters.length > 0 ? 'inline-flex' : 'none';
            activeFilterBadge.textContent = String(filters.length);
        }
        if (clearAllBtn) clearAllBtn.style.display = (isFiltered || currentSort.col !== null) ? 'inline-flex' : 'none';
        if (footTotalLabel) {
            footTotalLabel.textContent = isFiltered
                ? `Total (${visible} of ${items.length} components)`
                : `Total (${items.length} components)`;
        }
        table.querySelectorAll('tfoot [data-foot-col]').forEach(th => {
            const col = columns[parseInt(th.getAttribute('data-foot-col'), 10)];
            if (!col || !isQty(col)) return;
            th.innerHTML = `<span class="foot-val">${fmtTotal(sums[col.key])}</span>`;
            th.classList.toggle('is-neg', sums[col.key] < 0);
        });

        const pr = sums.total_pr_qty || 0;
        const stats = {
            components: visible.toLocaleString('en-US'),
            pr_count: fmtQty(sums.pr_count),
            total_pr_qty: fmtQty(sums.total_pr_qty),
            total_po_qty: fmtQty(sums.total_po_qty),
            balance_pr_qty: fmtQty(sums.balance_pr_qty),
            stock_qty: fmtQty(sums.stock_qty),
            po_coverage: pr > 0 ? `${Math.round(((sums.total_po_qty || 0) / pr) * 1000) / 10}%` : '—',
        };
        Object.entries(stats).forEach(([k, text]) => {
            const el = document.getElementById('procStat-' + k);
            if (!el) return;
            el.textContent = text;
            el.classList.toggle('is-neg', k in sums && (sums[k] || 0) < 0);
        });
        updateCharts(groups);
        updateToggleAllLabel();
    }

    function updateCharts(groups) {
        if (typeof Chart === 'undefined') return;
        const entries = Object.entries(groups).sort((a, b) => b[1].total_pr_qty - a[1].total_pr_qty);
        const barLabels = entries.map(([g]) => g);
        const round = (v) => Math.round(v * 1000) / 1000;

        const byCount = Object.entries(groups).map(([g, v]) => [g, v.count]).sort((a, b) => b[1] - a[1]);
        const top = byCount.slice(0, 7);
        const rest = byCount.slice(7).reduce((s, [, v]) => s + v, 0);
        if (rest > 0) top.push(['Other', rest]);
        const labels = top.map(([k]) => k);
        const values = top.map(([, v]) => v);

        const totalsEl = document.getElementById('rptTotalsChart');
        const mixEl = document.getElementById('rptMixChart');
        const datasets = groupBars.map(([label, key, color]) => ({
            label, data: entries.map(([, v]) => round(v[key])), backgroundColor: color, borderRadius: 6, maxBarThickness: 28,
        }));
        if (!totalsChart && totalsEl) {
            totalsChart = new Chart(totalsEl, {
                type: 'bar',
                data: { labels: barLabels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: { duration: 500 },
                    plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 11 } } } },
                    scales: { x: { grid: { display: false } }, y: { beginAtZero: true } },
                },
            });
        } else if (totalsChart) {
            totalsChart.data.labels = barLabels;
            datasets.forEach((ds, i) => { totalsChart.data.datasets[i].data = ds.data; });
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

        const panelItem = (el) => {
            const row = el.closest('tr.proc-detail');
            return row ? items.find(i => String(i.origIndex) === row.getAttribute('data-item')) : null;
        };
        tbody.addEventListener('click', (e) => {
            const btn = e.target.closest('.proc-pr-toggle');
            if (btn) {
                const item = items.find(i => String(i.origIndex) === btn.getAttribute('data-item'));
                setOpen(item, !item?.open);
                updateToggleAllLabel();
                return;
            }
            const item = panelItem(e.target);
            if (!item?.panel) return;
            const chip = e.target.closest('[data-pr-filter]');
            const sortTh = e.target.closest('th.is-sortable[data-pr-sort]');
            if (e.target.closest('[data-pr-close]')) {
                setOpen(item, false);
                updateToggleAllLabel();
                item.tr.scrollIntoView({ block: 'nearest' });
            } else if (chip) {
                item.panel.filter = chip.getAttribute('data-pr-filter');
                renderPanel(item);
            } else if (sortTh) {
                const key = sortTh.getAttribute('data-pr-sort');
                const s = item.panel.sort;
                item.panel.sort = s.key !== key ? { key, dir: 1 } : (s.dir === 1 ? { key, dir: -1 } : { key: null, dir: 1 });
                renderPanel(item);
            }
        });
        tbody.addEventListener('input', (e) => {
            const input = e.target.closest('[data-pr-search]');
            const item = input ? panelItem(input) : null;
            if (!item?.panel) return;
            item.panel.query = input.value.trim();
            renderPanel(item);
        });
        tbody.addEventListener('keydown', (e) => {
            if (e.target.closest('[data-pr-search]') && e.key === 'Enter') e.preventDefault();
        });
        toggleAllPrBtn?.addEventListener('click', () => {
            const targets = items.filter(i => i.visible && (Number(i.rec.pr_count) || 0) > 0);
            const open = targets.some(i => !i.open);
            targets.forEach(i => setOpen(i, open));
            updateToggleAllLabel();
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
        window.addEventListener('resize', () => { syncHeaderHeight(); syncDetailWidth(); });
    }

    const columnFeatures = window.rptSetupColumnFeatures
        ? window.rptSetupColumnFeatures(table, { onChange: syncHeaderHeight })
        : { refresh() {} };

    document.getElementById('rptFilterForm')?.addEventListener('submit', () => {
        const so = (document.getElementById('so')?.value || '').trim();
        if (so !== '') showSpinner(true);
    });

    showSpinner(true);
    const params = new URLSearchParams({ so: cfg.so || '', _: String(Date.now()) });
    fetch(`${cfg.dataUrl}?${params.toString()}`, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
        .then(async (res) => {
            const raw = await res.text();
            let json;
            try { json = JSON.parse(raw); } catch (e) {
                const type = res.headers.get('Content-Type') || '';
                if (res.redirected || /login/i.test(res.url) || (res.ok && /text\/html/i.test(type))) {
                    throw new Error('Your portal session has expired. Please log in again and reopen the report.');
                }
                throw new Error(`SAP data could not be read (HTTP ${res.status}). Please retry.`);
            }
            if (!res.ok || json.success === false) throw new Error(json.error || json.message || `SAP API failed (${res.status})`);
            return json.data || {};
        })
        .then((data) => {
            const records = Array.isArray(data.records) ? data.records : [];
            fillInfo(data);
            if (!records.length) {
                showEmpty(`No procurement data for sales order ${cfg.so}.`);
                updateCharts({});
                bindControls();
                return;
            }
            renderRows(records);
            columnFeatures.refresh();
            bindControls();
            applyFilterAndSearch();
        })
        .catch((err) => {
            showEmpty(err.message || 'Unable to load SAP data.');
            const alertBox = document.createElement('div');
            alertBox.className = 'rpt-alert';
            alertBox.textContent = err.message || 'Unable to load SAP data.';
            document.querySelector('.rpt-main')?.prepend(alertBox);
        })
        .finally(() => {
            showSpinner(false);
            syncHeaderHeight();
        });
})();
