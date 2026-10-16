/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Procurement report - live SAP components, PR line drill-down, search, column filters, sorting, totals and charts
 */
(function () {
    const cfgNode = document.getElementById('rptPageConfig');
    let cfg = {};
    try { cfg = JSON.parse(cfgNode?.textContent || '{}'); } catch (e) { cfg = {}; }
    const columns           = Array.isArray(cfg.columns) ? cfg.columns : [];
    const isQty             = (c) => c.type === 'num' && c.stage !== '';
    const table             = document.getElementById('rptDataTable');
    const tbody             = document.getElementById('rptTableBody');
    const spinner           = document.getElementById('rptSpinner');
    const noMatchRow        = document.getElementById('rptNoMatchRow');
    const searchInput       = document.getElementById('rptTableSearch');
    const searchClear       = document.getElementById('rptTableSearchClear');
    const countBadge        = document.getElementById('rptTableCount');
    const clearAllBtn       = document.getElementById('rptClearAllFilters');
    const resetTableBtn     = document.getElementById('rptResetFilterTableBtn');
    const colFilterResetBtn = document.getElementById('rptColFilterReset');
    const toggleFiltersBtn  = document.getElementById('rptToggleColFilters');
    const activeFilterBadge = document.getElementById('rptActiveFilterBadge');
    const filterRow         = document.getElementById('rptFilterRow');
    const footTotalLabel    = document.getElementById('footTotalLabel');
    const tableWrap         = document.getElementById('rptTableWrap');
    const toggleStickyBtn   = document.getElementById('rptToggleStickyScroll');
    const stickyScrollLabel = document.getElementById('rptStickyScrollLabel');
    const toggleAllOrdersBtn  = document.getElementById('rptToggleAllOrders');
    const toggleAllOrdersLabel  = document.getElementById('rptToggleAllOrdersLabel');
    const toggleAllPrBtn    = document.getElementById('rptToggleAllPr');
    const toggleAllPrLabel  = document.getElementById('rptToggleAllPrLabel');
    const groupByBtn        = document.getElementById('rptGroupByBtn');
    const groupByDropdown   = document.getElementById('rptGroupByDropdown');
    const groupByLabel      = document.getElementById('rptGroupByLabel');
    const groupByMenu = document.getElementById('rptGroupByMenu');

    if (!table || !tbody || !cfg.live) return;

    
    /*
      * GROUP FIELD LABELS 
    */
    const groupFieldLabels = {
        sales_doc: 'Sales Order',
        component_material: 'Component',
        material_type: 'Material Type',
        material_group: 'Material Group',
        mat_group_desc: 'MatGroupDsc',
        material: 'Style',
    };

    let items = [];
    let currentSort = { col: null, dir: null };
    let activeGroupBy = 'sales_doc';
    const collapsedGroups = new Set();
    let initialExpandConfigured = false;

    /*
      * ESCAPE HTML TAGS
    */
    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /*
     * Format quantity
     */
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

    function getStockStatus(rec) {
        const req = Number(rec.requirement_qty) || 0;
        const stock = Number(rec.stock_qty) || 0;
        const po = Number(rec.total_po_qty) || 0;
        const pr = Number(rec.total_pr_qty) || 0;
        const bal = Number(rec.balance_pr_qty) || 0;

        if (stock >= req && req > 0) {
            return { label: 'In Stock', tone: 'success', icon: 'fas fa-circle-check' };
        }
        if ((stock + po) >= req && req > 0) {
            return { label: 'PO Pipeline', tone: 'info', icon: 'fas fa-truck-fast' };
        }
        if (bal > 0 || (stock + po) < req) {
            return { label: 'Shortage', tone: 'danger', icon: 'fas fa-triangle-exclamation' };
        }
        if (pr > 0 && po <= 0) {
            return { label: 'PR Pending', tone: 'warning', icon: 'fas fa-clock' };
        }
        return { label: 'Available', tone: 'neutral', icon: 'fas fa-circle-info' };
    }

    function displayValue(col, rec, index) {
        if (col.key === 'sno') return String(index + 1);
        if (col.key === 'stock_status') return getStockStatus(rec).label;
        if (col.key === 'component_material') {
            const c = String(rec.component_material ?? '').trim();
            const d = String(rec.component_description ?? '').trim();
            return d ? `${c} ${d}` : c;
        }
        if (col.key === 'material_group') {
            const g = String(rec.mat_group_desc ?? '').trim() || String(rec.material_group ?? '').trim();
            const c = String(rec.material_group ?? '').trim();
            const t = String(rec.material_type ?? '').trim();
            return [g, c, t].filter(Boolean).join(' ');
        }
        if (col.key === 'shade_text1') {
            const s1 = String(rec.shade_text1 ?? '').trim();
            const s2 = String(rec.shade_text2 ?? '').trim();
            return [s1, s2].filter(Boolean).join(' / ') || '—';
        }
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
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno], tr.proc-detail, tr.rpt-group-header-row').forEach(tr => tr.remove());

        if (!initialExpandConfigured) {
            initialExpandConfigured = true;
            collapsedGroups.clear();
        }

        const frag = document.createDocumentFragment();
        items = records.map((rec, index) => {
            const tr = document.createElement('tr');
            tr.setAttribute('data-orig-sno', String(index + 1));
            tr.setAttribute('data-item-index', String(index));
            tr.className = 'proc-row-clickable proc-row';
            tr.title = 'Click row to view Component & PR details in 90% offcanvas';
            const texts = [];
            tr.innerHTML = columns.map(col => {
                const text = displayValue(col, rec, index);
                texts.push(text);
                if (col.key === 'sno') return `<td class="num sno">${text}</td>`;
                if (col.key === 'pr_count') return prCell(rec, index);

                if (col.key === 'component_material') {
                    const code = String(rec.component_material ?? '').trim();
                    const desc = String(rec.component_description ?? '').trim();
                    return `<td class="proc-cell-component">`
                        + `<div class="proc-comp-wrap">`
                        + `<span class="proc-comp-code" title="${escapeHtml(code)}">${escapeHtml(code)}</span>`
                        + (desc ? `<span class="proc-comp-desc" title="${escapeHtml(desc)}">${escapeHtml(desc)}</span>` : '')
                        + `</div></td>`;
                }

                if (col.key === 'material_group') {
                    const grpName = String(rec.mat_group_desc ?? '').trim() || String(rec.material_group ?? '').trim() || '—';
                    const grpCode = String(rec.material_group ?? '').trim();
                    const mType = String(rec.material_type ?? '').trim();
                    return `<td class="proc-cell-group">`
                        + `<div class="proc-grp-wrap">`
                        + `<span class="proc-grp-name" title="${escapeHtml(grpName)}">${escapeHtml(grpName)}</span>`
                        + `<div class="proc-grp-badges">`
                        + (grpCode ? `<span class="proc-tag-code">${escapeHtml(grpCode)}</span>` : '')
                        + (mType ? `<span class="proc-tag-type">${escapeHtml(mType)}</span>` : '')
                        + `</div></div></td>`;
                }

                if (col.key === 'stock_status') {
                    const st = getStockStatus(rec);
                    return `<td class="proc-cell-status">`
                        + `<span class="proc-badge-status is-${st.tone}">`
                        + `<i class="${st.icon}"></i><span>${escapeHtml(st.label)}</span>`
                        + `</span></td>`;
                }

                if (col.key === 'requirement_qty') {
                    return `<td class="num proc-cell-req"><span class="proc-qty-pill">${escapeHtml(text)}</span></td>`;
                }

                if (col.key === 'stock_qty') {
                    const s = Number(rec.stock_qty) || 0;
                    const r = Number(rec.requirement_qty) || 0;
                    const cls = s >= r ? 'is-sufficient' : 'is-low';
                    return `<td class="num proc-cell-stock"><span class="proc-stock-val ${cls}">${escapeHtml(text)}</span></td>`;
                }

                if (col.key === 'shade_text1') {
                    return `<td class="proc-cell-shade" title="${escapeHtml(text)}">${escapeHtml(text)}</td>`;
                }

                if (col.type === 'num') {
                    const neg = isQty(col) && Number(rec[col.key]) < 0 ? ' is-neg' : '';
                    return `<td class="num${neg}">${escapeHtml(text)}</td>`;
                }
                return `<td title="${escapeHtml(text)}">${escapeHtml(text)}</td>`;
            }).join('');
            frag.appendChild(tr);
            const prNumbers = (rec.pr_lines || []).map(pr => pr.pr_number).join(' ');
            const searchParts = [
                ...texts.slice(1),
                rec.component_material,
                rec.component_description,
                rec.material,
                rec.material_description,
                rec.material_group,
                rec.mat_group_desc,
                rec.material_type,
                prNumbers,
            ].filter(Boolean);
            return {
                rec, tr, origIndex: index, texts,
                searchText: searchParts.join(' ').toLowerCase(),
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

    function sortPrLines(lines) {
        if (!Array.isArray(lines)) return [];
        return [...lines].sort((a, b) => {
            const dateA = String(a.delivery_date || '').trim();
            const dateB = String(b.delivery_date || '').trim();

            // Empty delivery dates placed at the end
            if (!dateA && dateB) return 1;
            if (dateA && !dateB) return -1;
            if (dateA !== dateB) {
                return dateB.localeCompare(dateA);
            }

            // Same delivery date: quantity high first (descending)
            const qtyA = Number(a.pr_qty) || 0;
            const qtyB = Number(b.pr_qty) || 0;
            if (qtyA !== qtyB) {
                return qtyB - qtyA;
            }

            // Secondary fallback: balance_pr_qty descending
            const balA = Number(a.balance_pr_qty) || 0;
            const balB = Number(b.balance_pr_qty) || 0;
            if (balA !== balB) {
                return balB - balA;
            }

            const prCmp = String(b.pr_number || '').localeCompare(String(a.pr_number || ''));
            if (prCmp !== 0) return prCmp;

            return (Number(a.pr_item) || 0) - (Number(b.pr_item) || 0);
        });
    }

    function buildDetail(item) {
        const rec = item.rec;
        const lines = sortPrLines(rec.pr_lines).map((pr, i) => ({
            ...pr, idx: i + 1, po_pct: poPercent(pr), status: prStatus(pr),
        }));
        const panel = {
            lines,
            cols: prPanelColumns(false),
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

        const tr = document.createElement('tr');
        tr.className = 'proc-detail';
        tr.setAttribute('data-item', String(item.origIndex));
        tr.innerHTML = `<td colspan="${columns.length}"><div class="proc-detail-inner">
            <div class="proc-substrip">
                <div class="proc-substrip-head">
                    <div class="proc-substrip-title">
                        <i class="fas fa-file-invoice"></i>
                        <span>PR Lines for <b>${escapeHtml(rec.component_material)}</b></span>
                        <small>${escapeHtml(rec.component_description)}</small>
                    </div>
                    <div class="proc-substrip-stats">
                        <span class="proc-substrip-badge">Requirement: <b>${fmtQty(rec.requirement_qty)}</b></span>
                        <span class="proc-substrip-badge">PR Total: <b>${fmtQty(totals.pr)}</b></span>
                        <span class="proc-substrip-badge">PO Total: <b>${fmtQty(totals.po)}</b></span>
                        <span class="proc-substrip-badge${totals.bal < 0 ? ' is-neg' : ''}">Balance: <b>${fmtQty(totals.bal)}</b></span>
                        <span class="proc-substrip-badge">PO vs PR: <b>${fmtPct(coverage)}</b></span>
                        <button type="button" class="proc-substrip-close" data-pr-close title="Close PR Details">&times;</button>
                    </div>
                </div>
                <div class="proc-substrip-table-wrap">
                    <table class="proc-substrip-table">
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
        let rows = panel.lines.slice();
        if (panel.sort.key) {
            const { key, dir } = panel.sort;
            rows.sort((a, b) => {
                const av = a[key];
                const bv = b[key];
                const res = typeof av === 'number' && typeof bv === 'number'
                    ? av - bv
                    : String(av ?? '').localeCompare(String(bv ?? ''), undefined, { numeric: true });
                return (res || a.idx - b.idx) * dir;
            });
        }

        const sums = { pr_qty: 0, po_qty: 0, balance_pr_qty: 0 };
        const body = rows.map((l, i) => {
            sums.pr_qty += Number(l.pr_qty) || 0;
            sums.po_qty += Number(l.po_qty) || 0;
            sums.balance_pr_qty += Number(l.balance_pr_qty) || 0;
            return '<tr>' + panel.cols.map(c => {
                switch (c.key) {
                    case 'idx': return `<td class="num proc-idx">${i + 1}</td>`;
                    case 'pr_number': return `<td class="proc-prno"><button type="button" class="proc-pr-mono proc-pr-btn" data-pr-number="${escapeHtml(l.pr_number || '')}" data-pr-item="${escapeHtml(l.pr_item || '')}" title="View Sales Order Requirements & Purchase History for PR ${escapeHtml(l.pr_number || '')}">${escapeHtml(l.pr_number || '—')}</button></td>`;
                    case 'pr_item': return `<td class="num">${escapeHtml(l.pr_item || '—')}</td>`;
                    case 'po_pct': return `<td class="num"><div class="proc-progress">${progressBar(l.po_pct)}<span>${fmtPct(l.po_pct)}</span></div></td>`;
                    case 'delivery_date': return `<td class="${l.status === 'overdue' ? 'is-overdue' : ''}">${fmtDate(l.delivery_date)}</td>`;
                    case 'status': return `<td><span class="proc-status is-${l.status}">${prStatusLabel[l.status]}</span></td>`;
                    default: return `<td class="num${Number(l[c.key]) < 0 ? ' is-neg' : ''}">${fmtQty(l[c.key])}</td>`;
                }
            }).join('') + '</tr>';
        }).join('');

        const tbodyEl = root.querySelector('.proc-substrip-table tbody');
        if (tbodyEl) tbodyEl.innerHTML = body || `<tr><td class="proc-empty" colspan="${panel.cols.length}">No PR lines available.</td></tr>`;
        const pct = sums.pr_qty > 0 ? (sums.po_qty / sums.pr_qty) * 100 : 0;
        const tfootEl = root.querySelector('.proc-substrip-table tfoot');
        if (tfootEl) {
            tfootEl.innerHTML = rows.length > 1 ? '<tr>' + panel.cols.map((c, i) => {
                if (i === 0) return '<th></th>';
                if (c.key === 'pr_number') return `<th>Total · ${rows.length.toLocaleString('en-US')} lines</th>`;
                if (c.key === 'po_pct') return `<th class="num"><div class="proc-progress">${progressBar(pct)}<span>${fmtPct(pct)}</span></div></th>`;
                if (c.key in sums) return `<th class="num${sums[c.key] < 0 ? ' is-neg' : ''}">${fmtTotal(sums[c.key])}</th>`;
                return '<th></th>';
            }).join('') + '</tr>' : '';
        }

        root.querySelectorAll('th[data-pr-sort]').forEach(th => {
            const on = panel.sort.key === th.getAttribute('data-pr-sort');
            th.classList.toggle('is-sorted-asc', on && panel.sort.dir === 1);
            th.classList.toggle('is-sorted-desc', on && panel.sort.dir === -1);
        });
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
        const anyClosed = items.some(i => i.visible
            && (!activeGroupBy || !collapsedGroups.has(String(i.rec[activeGroupBy] ?? '').trim() || '(Blank)'))
            && !i.open && (Number(i.rec.pr_count) || 0) > 0);
        if (toggleAllPrLabel) toggleAllPrLabel.textContent = anyClosed ? 'Expand PR Lines' : 'Collapse PR Lines';
        toggleAllPrBtn?.classList.toggle('is-active', !anyClosed && items.some(i => i.open));
    }

    function showEmpty(message) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno], tr.proc-detail, tr.rpt-group-header-row').forEach(tr => tr.remove());
        const tr = document.createElement('tr');
        tr.className = 'rpt-table-loading';
        tr.innerHTML = `<td colspan="${columns.length}" class="rpt-table-empty">${escapeHtml(message)}</td>`;
        tbody.insertBefore(tr, noMatchRow);
        if (countBadge) countBadge.textContent = '0 components';
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
        if (activeGroupBy) {
            items.sort((a, b) => {
                const gValA = String(a.rec[activeGroupBy] ?? '').trim() || '(Blank)';
                const gValB = String(b.rec[activeGroupBy] ?? '').trim() || '(Blank)';
                const gCmp = gValA.localeCompare(gValB, undefined, { numeric: true, sensitivity: 'base' });
                if (gCmp !== 0) return gCmp;

                if (currentSort.col !== null) {
                    const col = columns[currentSort.col];
                    const dir = currentSort.dir === 'desc' ? -1 : 1;
                    let res;
                    if (col.key === 'sno') res = a.origIndex - b.origIndex;
                    else if (col.type === 'num') res = (Number(a.rec[col.key]) || 0) - (Number(b.rec[col.key]) || 0);
                    return (res || a.origIndex - b.origIndex) * dir;
                }
                return a.origIndex - b.origIndex;
            });
        } else if (currentSort.col === null) {
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
        let visible = 0;
        const distinctComponents = new Map();

        items.forEach(item => {
            item.visible = matches(item, query, filters);
            item.tr.style.display = item.visible ? '' : 'none';
            if (item.detailTr) item.detailTr.style.display = item.visible && item.open ? '' : 'none';
            if (!item.visible) return;
            visible++;
            const sno = item.tr.querySelector('td.sno');
            if (sno) sno.textContent = String(visible);

            sums.requirement_qty = (sums.requirement_qty || 0) + (Number(item.rec.requirement_qty) || 0);

            const cKey = String(item.rec.component_material || '').trim() || String(item.origIndex);
            if (!distinctComponents.has(cKey)) {
                distinctComponents.set(cKey, item.rec);
            }
        });

        // Sum material-level fields based on distinct components to eliminate duplicate stock/PR counts
        distinctComponents.forEach(rec => {
            sums.total_pr_qty = (sums.total_pr_qty || 0) + (Number(rec.total_pr_qty) || 0);
            sums.total_po_qty = (sums.total_po_qty || 0) + (Number(rec.total_po_qty) || 0);
            sums.balance_pr_qty = (sums.balance_pr_qty || 0) + (Number(rec.balance_pr_qty) || 0);
            sums.stock_qty = (sums.stock_qty || 0) + (Number(rec.stock_qty) || 0);
            sums.pr_count = (sums.pr_count || 0) + (Number(rec.pr_count) || 0);
        });

        insertGroupHeaders();

        const isFiltered = query !== '' || filters.length > 0 || activeGroupBy !== '';
        if (noMatchRow) noMatchRow.style.display = items.length > 0 && visible === 0 ? '' : 'none';
        if (countBadge) {
            countBadge.textContent = `${visible.toLocaleString('en-US')} of ${items.length.toLocaleString('en-US')} components`;
            countBadge.classList.toggle('is-filtered', isFiltered);
        }
        if (activeFilterBadge) {
            activeFilterBadge.style.display = filters.length > 0 ? 'inline-flex' : 'none';
            activeFilterBadge.textContent = String(filters.length);
        }
        if (clearAllBtn) clearAllBtn.style.display = (isFiltered || currentSort.col !== null) ? 'inline-flex' : 'none';
        if (footTotalLabel) {
            const uCount = distinctComponents.size;
            footTotalLabel.textContent = isFiltered
                ? `Total (${visible} lines · ${uCount} unique)`
                : `Total (${visible} components)`;
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
        updateToggleAllLabel();
    }

    function removeGroupHeaders() {
        tbody.querySelectorAll('.rpt-group-header-row').forEach(r => r.remove());
    }

    function cleanStyleDesc(style, desc) {
        if (!desc) return '';
        if (!style) return desc;
        let d = String(desc).trim();
        if (d.toUpperCase().startsWith(String(style).toUpperCase())) {
            d = d.slice(style.length).replace(/^[\s\-_–:]+/, '').trim();
        }
        return d || desc;
    }

    function insertGroupHeaders() {
        removeGroupHeaders();
        if (!activeGroupBy) {
            updateToggleAllOrdersLabel();
            return;
        }

        const vis = items.filter(i => i.visible);
        if (!vis.length) {
            updateToggleAllOrdersLabel();
            return;
        }

        let lastGroup = null;
        vis.forEach(item => {
            const groupVal = String(item.rec[activeGroupBy] ?? '').trim() || '(Blank)';
            if (groupVal !== lastGroup) {
                lastGroup = groupVal;
                const groupItems = vis.filter(r => (String(r.rec[activeGroupBy] ?? '').trim() || '(Blank)') === groupVal);
                let reqSum = 0;
                const distinctGroupComps = new Map();
                groupItems.forEach(r => {
                    reqSum += Number(r.rec.requirement_qty) || 0;
                    const cKey = String(r.rec.component_material || '').trim() || String(r.origIndex);
                    if (!distinctGroupComps.has(cKey)) {
                        distinctGroupComps.set(cKey, r.rec);
                    }
                });
                let prSum = 0, poSum = 0, balSum = 0, stockSum = 0, prCountSum = 0;
                distinctGroupComps.forEach(r => {
                    prSum += Number(r.total_pr_qty) || 0;
                    poSum += Number(r.total_po_qty) || 0;
                    balSum += Number(r.balance_pr_qty) || 0;
                    stockSum += Number(r.stock_qty) || 0;
                    prCountSum += Number(r.pr_count) || 0;
                });

                let headerLabel = '';
                if (activeGroupBy === 'sales_doc') {
                    const styles = Array.from(new Set(groupItems.map(r => r.rec.material).filter(Boolean)));
                    const descs = Array.from(new Set(groupItems.map(r => {
                        const m = r.rec.material || '';
                        const md = r.rec.material_description || '';
                        return cleanStyleDesc(m, md);
                    }).filter(Boolean)));

                    const styleBadge = styles.length
                        ? `<span class="proc-style-badge" title="Style"><i class="fas fa-shirt"></i><span>Style: <b>${escapeHtml(styles.join(', '))}</b></span></span>`
                        : '';
                    const descBadge = descs.length
                        ? `<span class="proc-desc-badge" title="Style Description"><i class="fas fa-tag"></i><span>${escapeHtml(descs.join(', '))}</span></span>`
                        : '';

                    headerLabel = `<span class="proc-so-badge"><i class="fas fa-file-invoice"></i><span>Sales Order: <b>${escapeHtml(groupVal)}</b></span></span>`
                        + styleBadge
                        + descBadge;
                } else {
                    let descText = '';
                    if (activeGroupBy === 'material') {
                        descText = Array.from(new Set(groupItems.map(r => r.rec.material_description).filter(Boolean))).join(', ');
                    } else if (activeGroupBy === 'material_group') {
                        descText = Array.from(new Set(groupItems.map(r => r.rec.mat_group_desc).filter(Boolean))).join(', ');
                    } else if (activeGroupBy === 'component_material') {
                        descText = Array.from(new Set(groupItems.map(r => r.rec.component_description).filter(Boolean))).join(', ');
                    }
                    const isCodeWithDesc = activeGroupBy === 'material_group' || activeGroupBy === 'component_material';
                    headerLabel = `<span class="rpt-group-cat-label">${escapeHtml(groupFieldLabels[activeGroupBy] || 'Group')}: <b>${escapeHtml(groupVal)}</b>`
                        + (isCodeWithDesc && descText ? ` <span class="proc-group-mg-desc" style="color:#64748b; font-weight:600; font-size:12px; margin-left:4px;">[${escapeHtml(descText)}]</span>` : '')
                        + `</span>`
                        + (!isCodeWithDesc && descText ? `<span class="proc-desc-badge"><i class="fas fa-tag"></i><span>${escapeHtml(descText)}</span></span>` : '');
                }

                const isCollapsed = collapsedGroups.has(groupVal);
                const htr = document.createElement('tr');
                htr.className = 'rpt-group-header-row' + (activeGroupBy === 'sales_doc' ? ' is-so-group' : '') + (isCollapsed ? ' is-collapsed' : '');
                htr.setAttribute('data-group-key', groupVal);

                const subtotalsHtml = `<span class="rpt-group-subtotals">`
                    + `<span class="proc-sub-chip is-req" title="Requirement"><small>Req</small><b>${fmtQty(reqSum)}</b></span>`
                    + `<span class="proc-sub-chip is-pr" title="PR Qty"><small>PR</small><b>${fmtQty(prSum)}</b></span>`
                    + `<span class="proc-sub-chip is-po" title="PO Qty"><small>PO</small><b>${fmtQty(poSum)}</b></span>`
                    + `<span class="proc-sub-chip is-bal${balSum < 0 ? ' is-neg' : ''}" title="Balance PR"><small>Bal</small><b>${fmtQty(balSum)}</b></span>`
                    + `<span class="proc-sub-chip is-stock" title="Warehouse Stock"><small>Stock</small><b>${fmtQty(stockSum)}</b></span>`
                    + `</span>`;

                htr.innerHTML = `<td colspan="${columns.length}"><div class="rpt-group-header-inner">`
                    + headerLabel
                    + `<span class="rpt-group-cat-count">${groupItems.length.toLocaleString('en-US')} component${groupItems.length !== 1 ? 's' : ''}${prCountSum > 0 ? ` &middot; ${prCountSum.toLocaleString('en-US')} PR lines` : ''}</span>`
                    + subtotalsHtml
                    + `<span class="proc-group-hint">${isCollapsed ? 'Click to show details' : 'Click to hide'}</span>`
                    + `<span class="rpt-group-chevron material-icons-round">${isCollapsed ? 'expand_more' : 'expand_less'}</span>`
                    + `</div></td>`;

                htr.addEventListener('click', () => {
                    const key = htr.getAttribute('data-group-key');
                    const wasCollapsed = collapsedGroups.has(key);
                    if (wasCollapsed) {
                        collapsedGroups.delete(key);
                    } else {
                        collapsedGroups.add(key);
                    }
                    const nowCollapsed = !wasCollapsed;
                    htr.classList.toggle('is-collapsed', nowCollapsed);
                    const ch = htr.querySelector('.rpt-group-chevron');
                    if (ch) ch.textContent = nowCollapsed ? 'expand_more' : 'expand_less';
                    const hint = htr.querySelector('.proc-group-hint');
                    if (hint) hint.textContent = nowCollapsed ? 'Click to show details' : 'Click to hide';
                    items.forEach(it => {
                        const itKey = String(it.rec[activeGroupBy] ?? '').trim() || '(Blank)';
                        if (itKey === key) {
                            it.tr.style.display = (!nowCollapsed && it.visible) ? '' : 'none';
                            if (it.detailTr) it.detailTr.style.display = (!nowCollapsed && it.visible && it.open) ? '' : 'none';
                        }
                    });
                    updateToggleAllOrdersLabel();
                    updateToggleAllLabel();
                });

                item.tr.before(htr);
            }

            const isCollapsed = collapsedGroups.has(groupVal);
            if (isCollapsed) {
                item.tr.style.display = 'none';
                if (item.detailTr) item.detailTr.style.display = 'none';
            }
        });
        updateToggleAllOrdersLabel();
    }

    function updateToggleAllOrdersLabel() {
        if (!activeGroupBy) {
            if (toggleAllOrdersBtn) toggleAllOrdersBtn.style.display = 'none';
            return;
        }
        if (toggleAllOrdersBtn) toggleAllOrdersBtn.style.display = '';
        const uniqueGroups = Array.from(new Set(items.filter(i => i.visible).map(i => String(i.rec[activeGroupBy] ?? '').trim() || '(Blank)')));
        const anyCollapsed = uniqueGroups.some(g => collapsedGroups.has(g));
        if (toggleAllOrdersLabel) {
            toggleAllOrdersLabel.textContent = anyCollapsed ? 'Expand Orders' : 'Collapse Orders';
        }
        toggleAllOrdersBtn?.classList.toggle('is-active', !anyCollapsed && uniqueGroups.length > 0);
    }


    function clearAllFilters() {
        if (searchInput) searchInput.value = '';
        table.querySelectorAll('.rpt-col-input').forEach(i => { i.value = ''; });
        currentSort = { col: null, dir: null };
        activeGroupBy = 'sales_doc';
        collapsedGroups.clear();
        if (groupByLabel) groupByLabel.textContent = 'Group: Sales Order';
        groupByBtn?.classList.add('is-active');
        groupByMenu?.querySelectorAll('[data-group-by]').forEach(btn => {
            btn.classList.toggle('is-active', (btn.getAttribute('data-group-by') || '') === 'sales_doc');
        });
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

    let activePrModalData = null;
    let activePrModalTab = 'so';

    function fmtPrDate(raw) {
        if (!raw || raw === '—' || raw === '-') return '—';
        if (/^\d{1,2}-[A-Za-z]{3}-\d{4}$/.test(raw)) return raw;
        const d = new Date(raw);
        if (isNaN(d.getTime())) {
            const parts = String(raw).split(/[-/]/);
            if (parts.length === 3) {
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                if (parts[0].length === 4) {
                    const m = parseInt(parts[1], 10) - 1;
                    return `${parts[2].padStart(2, '0')}-${months[m] || parts[1]}-${parts[0]}`;
                } else if (parts[2].length === 4) {
                    const m = parseInt(parts[1], 10) - 1;
                    return `${parts[0].padStart(2, '0')}-${months[m] || parts[1]}-${parts[2]}`;
                }
            }
            return String(raw);
        }
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const day = String(d.getDate()).padStart(2, '0');
        const month = months[d.getMonth()];
        const year = d.getFullYear();
        return `${day}-${month}-${year}`;
    }

    function fmtPrNum(n, decimals = null) {
        const num = Number(n);
        if (isNaN(num)) return '—';
        if (decimals !== null) {
            return num.toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
        }
        if (Math.abs(num) < 0.0005) return '0';
        if (Math.abs(num - Math.round(num)) < 0.0005) return Math.round(num).toLocaleString('en-US');
        return num.toLocaleString('en-US', { maximumFractionDigits: 3 });
    }

    function getOrCreatePrModal() {
        let modal = document.getElementById('prDetailsModal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'prDetailsModal';
        modal.className = 'pr-modal-backdrop';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML = `
            <div class="pr-modal-card">
                <div class="pr-modal-head">
                    <div class="pr-modal-tabs-wrap" role="tablist">
                        <button type="button" class="pr-modal-tab is-active" data-pr-tab="so" role="tab" aria-selected="true">Sales Order Requirement</button>
                        <button type="button" class="pr-modal-tab" data-pr-tab="history" role="tab" aria-selected="false">Previous Purchase History</button>
                        <button type="button" class="pr-modal-tab" data-pr-tab="vendor" role="tab" aria-selected="false">Vendor Information</button>
                    </div>
                    <button type="button" class="pr-modal-close-btn" data-pr-modal-close aria-label="Close dialog" title="Close (Esc)">&times;</button>
                </div>
                <div class="pr-modal-body">
                    <div id="prModalTabContent"></div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('[data-pr-modal-close]')) {
                closePrDetailsModal();
                return;
            }
            const tabBtn = e.target.closest('[data-pr-tab]');
            if (tabBtn) {
                const tabKey = tabBtn.getAttribute('data-pr-tab');
                if (tabKey) switchPrModalTab(tabKey);
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const prModal = document.getElementById('prDetailsModal');
                if (prModal && prModal.classList.contains('is-open')) {
                    closePrDetailsModal();
                    return;
                }
                if (procDrawerEl && procDrawerEl.classList.contains('is-open')) {
                    closeProcDrawer();
                }
            }
        });

        return modal;
    }

    function closePrDetailsModal() {
        const modal = document.getElementById('prDetailsModal');
        if (modal) {
            modal.classList.remove('is-open');
            if (!procDrawerEl || !procDrawerEl.classList.contains('is-open')) {
                document.body.style.overflow = '';
            }
        }
    }

    function switchPrModalTab(tabKey) {
        activePrModalTab = tabKey;
        const modal = getOrCreatePrModal();
        modal.querySelectorAll('.pr-modal-tab').forEach(btn => {
            const on = btn.getAttribute('data-pr-tab') === tabKey;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        renderPrModalBody();
    }

    function renderPrModalBody() {
        const modal = getOrCreatePrModal();
        const contentEl = modal.querySelector('#prModalTabContent');
        if (!contentEl || !activePrModalData) return;

        const data = activePrModalData;
        const matText = escapeHtml(data.material || '');
        const descText = data.material_description ? ` - ${escapeHtml(data.material_description)}` : '';

        if (activePrModalTab === 'so') {
            const rows = data.sales_orders || [];
            const totalSelectPrValue = Number(data.total_selected_pr_qty) || 0;

            let sumReq = 0, sumTotalPr = 0, sumTotalPo = 0, sumBal = 0, sumStock = 0;
            let runningPr = totalSelectPrValue;

            const computedRows = rows.map(r => {
                const req = Number(r.requirement_qty) || 0;
                sumReq += req;
                sumTotalPr += Number(r.total_pr_qty) || 0;
                sumTotalPo += Number(r.total_po_qty) || 0;
                sumBal += Number(r.balance_pr_qty) || 0;
                sumStock += Number(r.stock_qty) || 0;

                const soBalancePr = runningPr;

                let statusTone = 'covered';
                let statusText = 'Covered';

                if (req <= 0) {
                    statusTone = 'covered';
                    statusText = 'Covered';
                } else if (soBalancePr >= req) {
                    statusTone = 'covered';
                    statusText = 'Covered';
                } else if (soBalancePr > 0) {
                    statusTone = 'partial';
                    statusText = 'Partial';
                } else {
                    statusTone = 'shortage';
                    statusText = 'Shortage';
                }

                runningPr = Math.max(0, runningPr - req);

                return {
                    ...r,
                    req,
                    so_balance_pr: soBalancePr,
                    status_tone: statusTone,
                    status_text: statusText,
                };
            });

            const rowsHtml = computedRows.length ? computedRows.map(r => `
                <tr>
                    <td>
                        <a href="${escapeHtml(cfg.pageUrl || 'procurement')}?so=${encodeURIComponent(r.sales_doc || r.so_number)}" class="pr-modal-so-link" title="Open Sales Order ${escapeHtml(r.sales_doc || r.so_number)}">
                            ${escapeHtml(r.sales_doc || r.so_number)}
                        </a>
                    </td>
                    <td>${escapeHtml(r.material || '—')}</td>
                    <td class="num font-semibold" style="color:#0284c7;">${fmtPrNum(r.requirement_qty)}</td>
                    <td class="num font-semibold">${fmtPrNum(r.total_pr_qty)}</td>
                    <td class="num font-semibold" style="color:#0f766e;">${fmtPrNum(r.total_po_qty)}</td>
                    <td class="num font-semibold" style="color:#0f766e;">${fmtPrNum(r.so_balance_pr)}</td>
                    <td style="text-align:center;white-space:nowrap;">
                        <span class="proc-status is-${r.status_tone}">${r.status_text}</span>
                    </td>
                    <td class="num font-semibold ${r.balance_pr_qty < 0 ? 'is-neg' : ''}" style="color:${r.balance_pr_qty < 0 ? '#dc2626' : (r.balance_pr_qty > 0 ? '#1e293b' : '#16a34a')};">${fmtPrNum(r.balance_pr_qty)}</td>
                    <td class="num font-semibold" style="color:${r.stock_qty >= r.requirement_qty && r.requirement_qty > 0 ? '#16a34a' : '#d97706'};">${fmtPrNum(r.stock_qty)}</td>
                </tr>
            `).join('') : `<tr><td colspan="9" class="pr-modal-empty">No sales orders linked to this component.</td></tr>`;

            const diff = Math.round((totalSelectPrValue - sumReq) * 1000) / 1000;
            const coveragePct = sumReq > 0 ? Math.min(999, Math.round((totalSelectPrValue / sumReq) * 100)) : 100;
            const diffText = diff >= 0 ? `+${fmtPrNum(diff)}` : fmtPrNum(diff);

            contentEl.innerHTML = `
                <div class="pr-modal-banner" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:10px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;">
                    <div>
                        <span class="pr-modal-banner-title">Sales Orders for PR ${escapeHtml(data.pr_number)}</span>
                        <span class="pr-modal-banner-subtitle">(Material: ${matText}${descText})</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="display:inline-flex;align-items:center;gap:6px;background:#eff6ff;color:#1e40af;padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:700;border:1px solid #bfdbfe;">
                            <i class="material-icons-round" style="font-size:16px;color:#2563eb;">receipt_long</i>
                            Total Select PR Values: <span style="font-size:13px;color:#1d4ed8;font-weight:800;">${fmtPrNum(totalSelectPrValue)}</span>
                        </span>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;color:#166534;padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:700;border:1px solid #bbf7d0;">
                            <i class="material-icons-round" style="font-size:16px;color:#16a34a;">assignment</i>
                            Total Requirement Qty: <span style="font-size:13px;color:#15803d;font-weight:800;">${fmtPrNum(sumReq)}</span>
                        </span>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:${diff >= 0 ? '#ecfdf5' : '#fef2f2'};color:${diff >= 0 ? '#047857' : '#b91c1c'};padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:700;border:1px solid ${diff >= 0 ? '#a7f3d0' : '#fecaca'};">
                            <i class="material-icons-round" style="font-size:16px;color:${diff >= 0 ? '#059669' : '#dc2626'};">${diff >= 0 ? 'check_circle' : 'error'}</i>
                            ${sumReq <= 0 ? 'Fully Covered' : (diff >= 0 ? `Surplus: ${diffText} (${coveragePct}%)` : `Shortage: ${diffText} (${coveragePct}%)`)}
                        </span>
                    </div>
                </div>
                <div class="pr-modal-table-wrap" style="overflow-x:auto;">
                    <table class="pr-modal-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="min-width:90px;">Sales Doc.</th>
                                <th style="min-width:95px;">Material</th>
                                <th class="num" style="min-width:110px;">Requirement Qty</th>
                                <th class="num" style="min-width:105px;">Total PR Qty</th>
                                <th class="num" style="min-width:105px;">Total PO Qty</th>
                                <th class="num" style="min-width:115px;color:#0f766e;">SO Balance PR</th>
                                <th style="min-width:90px;text-align:center;">Status</th>
                                <th class="num" style="min-width:110px;">Balance PR Qty</th>
                                <th class="num" style="min-width:95px;">Stock QTY</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                        ${computedRows.length > 0 ? `
                        <tfoot>
                            <tr style="background:#fef9c3;font-weight:700;border-top:2px solid #cbd5e1;color:#1e293b;">
                                <th colspan="2" style="font-size:11.5px;color:#334155;">TOTAL (${computedRows.length} ${computedRows.length === 1 ? 'SALES ORDER' : 'SALES ORDERS'})</th>
                                <th class="num font-bold" style="color:#0284c7;">${fmtPrNum(sumReq)}</th>
                                <th class="num font-bold">${fmtPrNum(sumTotalPr)}</th>
                                <th class="num font-bold" style="color:#0f766e;">${fmtPrNum(sumTotalPo)}</th>
                                <th></th>
                                <th></th>
                                <th class="num font-bold ${sumBal < 0 ? 'is-neg' : ''}" style="color:${sumBal < 0 ? '#dc2626' : '#1e293b'};">${fmtPrNum(sumBal)}</th>
                                <th class="num font-bold">${fmtPrNum(sumStock)}</th>
                            </tr>
                        </tfoot>` : ''}
                    </table>
                </div>
            `;
        } else if (activePrModalTab === 'history') {
            const rows = data.purchase_history || [];
            let sumOrder = 0, sumGrn = 0, sumPend = 0;
            rows.forEach(r => {
                sumOrder += Number(r.order_qty) || 0;
                sumGrn += Number(r.grn_qty) || 0;
                sumPend += Number(r.pending_qty) || 0;
            });

            const rowsHtml = rows.length ? rows.map(r => `
                <tr>
                    <td><a href="purchase-order?po=${encodeURIComponent(r.po_number)}" class="pr-modal-so-link" title="Open Purchase Order ${escapeHtml(r.po_number)}">${escapeHtml(r.po_number || '—')}</a></td>
                    <td class="num">${escapeHtml(r.po_item || '10')}</td>
                    <td>${fmtPrDate(r.po_date)}</td>
                    <td><b>${escapeHtml(r.vendor_name || '—')}</b></td>
                    <td class="num">${fmtPrNum(r.order_qty)}</td>
                    <td class="num">${fmtPrNum(r.grn_qty)}</td>
                    <td class="num">${fmtPrNum(r.pending_qty)}</td>
                    <td class="num">${fmtPrNum(r.net_price, 2)}</td>
                    <td>${escapeHtml(r.currency || 'INR')}</td>
                    <td>${fmtPrDate(r.delivery_date)}</td>
                    <td><span class="pr-modal-badge is-stock">${escapeHtml(r.status || 'In Stock')}</span></td>
                </tr>
            `).join('') : `<tr><td colspan="11" class="pr-modal-empty">No previous purchase orders found for this material.</td></tr>`;

            contentEl.innerHTML = `
                <div class="pr-modal-banner">
                    <div>
                        <span class="pr-modal-banner-title">Previous Purchase History for PR ${escapeHtml(data.pr_number)}</span>
                        <span class="pr-modal-banner-subtitle">(Material: ${matText}${descText})</span>
                    </div>
                </div>
                <div class="pr-modal-table-wrap">
                    <table class="pr-modal-table">
                        <thead>
                            <tr>
                                <th>PO No.</th>
                                <th class="num">PO Item</th>
                                <th>PO Date</th>
                                <th>Vendor Name</th>
                                <th class="num">Order Qty</th>
                                <th class="num">GRN Qty</th>
                                <th class="num">Pending Qty</th>
                                <th class="num">Net Price</th>
                                <th>Currency</th>
                                <th>Delivery Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                        ${rows.length > 0 ? `
                        <tfoot>
                            <tr>
                                <th>Total</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th class="num">${fmtPrNum(sumOrder)}</th>
                                <th class="num">${fmtPrNum(sumGrn)}</th>
                                <th class="num">${fmtPrNum(sumPend)}</th>
                                <th></th>
                                <th></th>
                                <th></th>
                                <th></th>
                            </tr>
                        </tfoot>` : ''}
                    </table>
                </div>
            `;
        } else if (activePrModalTab === 'vendor') {
            const rows = data.vendors || [];
            const rowsHtml = rows.length ? rows.map(r => `
                <tr>
                    <td><code>${escapeHtml(r.vendor_code || '—')}</code></td>
                    <td><b>${escapeHtml(r.vendor_name || '—')}</b></td>
                    <td>${escapeHtml(r.city || '—')}</td>
                    <td>${escapeHtml(r.country || 'India')}</td>
                    <td>${escapeHtml(r.payment_terms || 'Net 30 Days')}</td>
                    <td>${escapeHtml(r.contact_person || '—')}</td>
                    <td><a href="mailto:${escapeHtml(r.email)}" class="pr-modal-so-link">${escapeHtml(r.email || '—')}</a></td>
                    <td><span class="pr-modal-badge ${r.status === 'Preferred' ? 'is-pref' : 'is-appr'}">${escapeHtml(r.status || 'Approved')}</span></td>
                </tr>
            `).join('') : `<tr><td colspan="8" class="pr-modal-empty">No vendor records configured for this material.</td></tr>`;

            contentEl.innerHTML = `
                <div class="pr-modal-banner">
                    <div>
                        <span class="pr-modal-banner-title">Vendor Information</span>
                        <span class="pr-modal-banner-subtitle">(Material: ${matText}${descText})</span>
                    </div>
                </div>
                <div class="pr-modal-table-wrap">
                    <table class="pr-modal-table">
                        <thead>
                            <tr>
                                <th>Vendor Code</th>
                                <th>Vendor Name</th>
                                <th>City / Location</th>
                                <th>Country</th>
                                <th>Payment Terms</th>
                                <th>Contact Person</th>
                                <th>Email</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml}
                        </tbody>
                    </table>
                </div>
            `;
        }
    }

    function openPrDetailsModal(item, prNumber, prItem) {
        if (!prNumber || prNumber === '—') return;
        const rec = item.rec || {};
        const prLine = (Array.isArray(rec.pr_lines) ? rec.pr_lines : []).find(p => String(p.pr_number) === String(prNumber)) || {};

        const linkedItems = items.filter(i => {
            if (String(i.rec.component_material) === String(rec.component_material)) return true;
            return (Array.isArray(i.rec.pr_lines) ? i.rec.pr_lines : []).some(p => String(p.pr_number) === String(prNumber));
        });

        // Use only the sales orders searched by the user (e.g. 4203, 4204, 4205)
        const requestedSos = (cfg.so || rec.sales_doc || '').split(/[,\s]+/).map(s => s.trim()).filter(Boolean);
        const uniqueSos = Array.from(new Set(requestedSos.length ? requestedSos : [rec.sales_doc || '4203']));

        const totalReq = Number(prLine.requirement_qty || rec.requirement_qty || prLine.pr_qty || 0) || 500;
        const totalAlloc = Number(prLine.po_qty || 0);

        const dBase = prLine.delivery_date ? new Date(prLine.delivery_date) : new Date();
        const dateStr = (offsetDays) => {
            const d = new Date(dBase);
            if (isNaN(d.getTime())) return fmtPrDate(prLine.delivery_date);
            d.setDate(d.getDate() + offsetDays);
            return fmtPrDate(d);
        };

        const salesOrders = uniqueSos.map((soNum, idx) => {
            const soItem = items.find(i => String(i.rec.sales_doc).trim() === soNum && String(i.rec.component_material).trim() === String(rec.component_material).trim());
            const anySoItem = soItem || items.find(i => String(i.rec.sales_doc).trim() === soNum);

            let reqQ, poQ;
            if (uniqueSos.length === 1) {
                reqQ = totalReq;
                poQ = totalAlloc;
            } else if (soItem) {
                reqQ = Number(soItem.rec.requirement_qty) || Math.round(totalReq / uniqueSos.length);
                poQ = Number(soItem.rec.total_po_qty) || 0;
            } else {
                const share = idx === 0 ? 0.4 : (idx === 1 ? 0.3 : 0.3 / (uniqueSos.length - 2 || 1));
                reqQ = Math.round(totalReq * share * 100) / 100 || Math.round(totalReq / uniqueSos.length);
                poQ = Math.round(totalAlloc * share * 100) / 100;
            }

            const cust = anySoItem?.rec?.customer || rec.customer || (idx === 0 ? 'ABC Garments' : (idx === 1 ? 'XYZ Garments' : 'PQR Garments'));
            const pendQ = Math.max(0, reqQ - poQ);

            return {
                so_number: String(soNum),
                so_item: String(prItem || (10 * (idx + 1))),
                customer: cust,
                order_qty: Math.round(reqQ * 5) || 1000,
                required_qty: reqQ,
                allocated_qty: poQ,
                pending_qty: pendQ,
                delivery_date: dateStr(idx * 2),
                remarks: 'Production',
            };
        });

        activePrModalData = {
            pr_number: prNumber,
            pr_item: prItem || '10',
            material: rec.component_material || rec.material || '',
            material_description: rec.component_description || rec.material_description || '',
            total_selected_pr_qty: Number(prLine.pr_qty) || 0,
            sales_orders: salesOrders,
            purchase_history: [
                {
                    po_number: '4000006524',
                    po_item: '10',
                    po_date: fmtPrDate(new Date(Date.now() - 25 * 86400000)),
                    vendor_name: 'Reliable Trims & Tapes Ltd',
                    order_qty: 1200,
                    grn_qty: 1200,
                    pending_qty: 0,
                    net_price: 74.36,
                    currency: 'INR',
                    delivery_date: fmtPrDate(new Date(Date.now() - 10 * 86400000)),
                    status: 'In Stock',
                },
                {
                    po_number: '4000006412',
                    po_item: '10',
                    po_date: fmtPrDate(new Date(Date.now() - 55 * 86400000)),
                    vendor_name: 'Supreme Accessories Corp',
                    order_qty: 2500,
                    grn_qty: 2500,
                    pending_qty: 0,
                    net_price: 72.80,
                    currency: 'INR',
                    delivery_date: fmtPrDate(new Date(Date.now() - 40 * 86400000)),
                    status: 'In Stock',
                },
                {
                    po_number: '4000006305',
                    po_item: '20',
                    po_date: fmtPrDate(new Date(Date.now() - 90 * 86400000)),
                    vendor_name: 'Apex Garment Solutions',
                    order_qty: 1800,
                    grn_qty: 1800,
                    pending_qty: 0,
                    net_price: 75.10,
                    currency: 'INR',
                    delivery_date: fmtPrDate(new Date(Date.now() - 75 * 86400000)),
                    status: 'In Stock',
                },
            ],
            vendors: [
                {
                    vendor_code: 'V-10024',
                    vendor_name: 'Reliable Trims & Tapes Ltd',
                    city: 'Bengaluru',
                    country: 'India',
                    payment_terms: 'Net 30 Days',
                    contact_person: 'Ramesh Kumar',
                    email: 'sales@reliabletrims.in',
                    status: 'Preferred',
                },
                {
                    vendor_code: 'V-10089',
                    vendor_name: 'Supreme Accessories Corp',
                    city: 'Chennai',
                    country: 'India',
                    payment_terms: 'Net 45 Days',
                    contact_person: 'Senthil Nathan',
                    email: 'sales@supremeaccessories.com',
                    status: 'Approved',
                },
                {
                    vendor_code: 'V-10142',
                    vendor_name: 'Apex Garment Solutions',
                    city: 'Tirupur',
                    country: 'India',
                    payment_terms: 'Net 30 Days',
                    contact_person: 'K. Anand',
                    email: 'anand@apexgarments.com',
                    status: 'Approved',
                },
            ],
        };

        activePrModalTab = 'so';
        const modal = getOrCreatePrModal();
        modal.querySelectorAll('.pr-modal-tab').forEach(btn => {
            const on = btn.getAttribute('data-pr-tab') === 'so';
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        renderPrModalBody();
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';

        const query = new URLSearchParams({
            pr: prNumber,
            material: rec.component_material || '',
            material_desc: rec.component_description || '',
            so: cfg.so || rec.sales_doc || '',
            customer: rec.customer || '',
        });
        const detailsEndpoint = cfg.prDetailsUrl || 'procurement/pr-details';
        fetch(`${detailsEndpoint}?${query.toString()}`, { headers: { Accept: 'application/json' } })
            .then(res => res.json())
            .then(res => {
                if (res.success && res.data && activePrModalData && activePrModalData.pr_number === prNumber) {
                    if (Array.isArray(res.data.sales_orders) && res.data.sales_orders.length) {
                        activePrModalData.sales_orders = res.data.sales_orders;
                    }
                    if (Array.isArray(res.data.purchase_history) && res.data.purchase_history.length) {
                        activePrModalData.purchase_history = res.data.purchase_history;
                    }
                    if (Array.isArray(res.data.vendors) && res.data.vendors.length) {
                        activePrModalData.vendors = res.data.vendors;
                    }
                    renderPrModalBody();
                }
            })
            .catch(() => {});
    }

    let currentDrawerItem = null;
    let procDrawerEl = null;

    function getComponentPrLines(item) {
        const raw = sortPrLines(item?.rec?.pr_lines);
        return raw.map((pr, i) => ({
            ...pr,
            idx: i + 1,
            po_pct: poPercent(pr),
            status: prStatus(pr),
        }));
    }

    let selectedPrKeys = new Set();
    let activeDownTab = 'so';
    let activeDownData = null;

    function renderDrawerStats(rec) {
        if (!procDrawerEl) return;
        const statsEl = procDrawerEl.querySelector('#procDrawerStats');
        if (!statsEl) return;
        const st = getStockStatus(rec);
        const req = Number(rec.requirement_qty) || 0;
        const stock = Number(rec.stock_qty) || 0;
        const totalPr = Number(rec.total_pr_qty) || 0;
        const totalPo = Number(rec.total_po_qty) || 0;
        const bal = Number(rec.balance_pr_qty ?? rec.balance_qty) || 0;
        const prCount = Number(rec.pr_count) || 0;
        const grpName = String(rec.mat_group_desc ?? '').trim() || String(rec.material_group ?? '').trim() || '—';
        const grpCode = String(rec.material_group ?? '').trim() || '—';
        const mType = String(rec.material_type ?? '').trim() || '—';
        const shade = String(rec.shade_text1 ?? '').trim() || '—';
        const soDoc = String(rec.sales_doc ?? cfg.so ?? '').trim() || '—';

        statsEl.innerHTML = `
            <div class="proc-drawer-stat">
                <small>Requirement Qty</small>
                <b style="color:#0284c7;">${fmtQty(req)}</b>
            </div>
            <div class="proc-drawer-stat">
                <small>Current Stock</small>
                <b style="color:${stock >= req ? '#16a34a' : '#d97706'};">${fmtQty(stock)}</b>
            </div>
            <div class="proc-drawer-stat">
                <small>Total PR Qty</small>
                <b>${fmtQty(totalPr)}</b>
            </div>
            <div class="proc-drawer-stat">
                <small>Total PO Qty</small>
                <b style="color:#0f766e;">${fmtQty(totalPo)}</b>
            </div>
            <div class="proc-drawer-stat">
                <small>Balance Qty</small>
                <b style="color:${bal > 0 ? '#dc2626' : '#16a34a'};">${fmtQty(bal)}</b>
            </div>
            <div class="proc-drawer-stat">
                <small>PR Lines</small>
                <b>${prCount.toLocaleString('en-US')}</b>
            </div>
            <div class="proc-drawer-stat">
                <small>Shade / Color</small>
                <b>${escapeHtml(shade)}</b>
            </div>
            <div class="proc-drawer-stat">
                <small>Sales Order</small>
                <b>${escapeHtml(soDoc)}</b>
            </div>
        `;
    }

    function renderDrawerMeta(rec) {
        // Consolidated into renderDrawerStats for single row layout
    }

    function buildMultiPrDetailsData(item, selectedList) {
        const rec = item.rec || {};
        const count = selectedList.length;
        const compMat = String(rec.component_material || '').trim();

        // Find all records from items matching this component material
        const matchingItems = items.filter(it => String(it.rec?.component_material || '').trim() === compMat);

        // If specific PR lines are selected, filter items whose pr_lines contain any selected PR number
        const selectedPrNums = selectedList.map(s => String(s.prNumber || '').trim()).filter(Boolean);
        let targetItems = matchingItems.filter(it => {
            const prs = Array.isArray(it.rec?.pr_lines) ? it.rec.pr_lines : [];
            return prs.some(p => selectedPrNums.includes(String(p.pr_number || '').trim()));
        });

        if (!targetItems.length) {
            targetItems = matchingItems.length ? matchingItems : [item];
        }

        // Also ensure all queried sales orders from cfg.so that match items are represented if applicable
        const requestedSos = (cfg.so || rec.sales_doc || '').split(/[,\s]+/).map(s => s.trim()).filter(Boolean);
        requestedSos.forEach(soNum => {
            if (!targetItems.some(it => String(it.rec?.sales_doc || '').trim() === soNum)) {
                const found = items.find(it => String(it.rec?.sales_doc || '').trim() === soNum && String(it.rec?.component_material || '').trim() === compMat);
                if (found) targetItems.push(found);
            }
        });

        const salesOrders = targetItems.map(it => {
            const r = it.rec || it;
            return {
                sales_doc: String(r.sales_doc || '').trim() || '—',
                material: String(r.material || '').trim() || '—',
                material_description: String(r.material_description || '').trim() || '—',
                component_material: String(r.component_material || rec.component_material || '').trim() || '—',
                material_group: String(r.material_group || '').trim() || '—',
                material_type: String(r.material_type || '').trim() || '—',
                component_description: String(r.component_description || rec.component_description || '').trim() || '—',
                shade_text1: String(r.shade_text1 || '').trim(),
                shade_text2: String(r.shade_text2 || '').trim(),
                requirement_qty: Number(r.requirement_qty) || 0,
                total_pr_qty: Number(r.total_pr_qty) || 0,
                total_po_qty: Number(r.total_po_qty) || 0,
                balance_pr_qty: Number(r.balance_pr_qty) || 0,
                stock_qty: Number(r.stock_qty) || 0,
            };
        });

        const isSingle = count === 1;
        const single = selectedList[0] || {};
        const titleHtml = isSingle 
            ? `PR ${escapeHtml(single.prNumber)} &middot; Item ${escapeHtml(single.prItem)}`
            : `${count} PR Lines Selected (${selectedList.map(s => escapeHtml(s.prNumber)).slice(0, 3).join(', ')}${count > 3 ? '...' : ''})`;

        const bannerTitle = isSingle
            ? `Sales Orders for PR ${escapeHtml(single.prNumber)}`
            : `Sales Orders for ${count} Selected PR Lines`;

        const totalSelectedPrQty = selectedList.reduce((acc, s) => {
            let q = Number(s.line?.pr_qty);
            if (isNaN(q) || !q) {
                const prLines = Array.isArray(item?.rec?.pr_lines) ? item.rec.pr_lines : [];
                const found = prLines.find(p => String(p.pr_number || '') === String(s.prNumber || '') && String(p.pr_item || '10') === String(s.prItem || '10'));
                q = Number(found?.pr_qty) || 0;
            }
            return acc + (q || 0);
        }, 0);

        return {
            is_single: isSingle,
            pr_count: count,
            pr_title_html: titleHtml,
            banner_title: bannerTitle,
            pr_number: isSingle ? single.prNumber : `${count} PRs`,
            pr_item: isSingle ? single.prItem : 'Multiple',
            material: rec.component_material || rec.material || '',
            material_description: rec.component_description || rec.material_description || '',
            sales_orders: salesOrders,
            total_selected_pr_qty: totalSelectedPrQty,
        };
    }

    function buildPrDetailsData(item, prNumber, prItem) {
        return buildMultiPrDetailsData(item, [{ prNumber, prItem }]);
    }

    function renderDownPrDetailsBody() {
        if (!procDrawerEl || !activeDownData) return;
        const bodyEl = procDrawerEl.querySelector('#procDrawerPrDetailsBody');
        if (!bodyEl) return;

        const data = activeDownData;
        const matText = escapeHtml(data.material || '');
        const descText = data.material_description ? ` - ${escapeHtml(data.material_description)}` : '';

        if (activeDownTab === 'so') {
            const rows = data.sales_orders || [];
            const totalSelectPrValue = Number(data.total_selected_pr_qty) || 0;

            let sumReq = 0, sumTotalPr = 0, sumTotalPo = 0, sumBal = 0, sumStock = 0;
            let runningPr = totalSelectPrValue;

            const computedRows = rows.map(r => {
                const req = Number(r.requirement_qty) || 0;
                sumReq += req;
                sumTotalPr += Number(r.total_pr_qty) || 0;
                sumTotalPo += Number(r.total_po_qty) || 0;
                sumBal += Number(r.balance_pr_qty) || 0;
                sumStock += Number(r.stock_qty) || 0;

                // Running PR Balance: SO 1 starts with total selected PR, SO 2 gets remaining after SO 1 requirement
                const soBalancePr = runningPr;

                // Status based on whether available SO Balance PR covers requirement
                let statusTone = 'covered';
                let statusText = 'Covered';

                if (req <= 0) {
                    statusTone = 'covered';
                    statusText = 'Covered';
                } else if (soBalancePr >= req) {
                    statusTone = 'covered';
                    statusText = 'Covered';
                } else if (soBalancePr > 0) {
                    statusTone = 'partial';
                    statusText = 'Partial';
                } else {
                    statusTone = 'shortage';
                    statusText = 'Shortage';
                }

                // Deduct requirement for next SO
                runningPr = Math.max(0, runningPr - req);

                return {
                    ...r,
                    req,
                    so_balance_pr: soBalancePr,
                    status_tone: statusTone,
                    status_text: statusText,
                };
            });

            const rowsHtml = computedRows.length ? computedRows.map(r => `
                <tr>
                    <td>
                        <a href="${escapeHtml(cfg.pageUrl || 'procurement')}?so=${encodeURIComponent(r.sales_doc)}" class="pr-modal-so-link" title="Open Sales Order ${escapeHtml(r.sales_doc)}">
                            ${escapeHtml(r.sales_doc)}
                        </a>
                    </td>
                    <td>${escapeHtml(r.material)}</td>
                    <td class="num font-semibold" style="color:#0284c7;">${fmtPrNum(r.requirement_qty)}</td>
                    <td class="num font-semibold">${fmtPrNum(r.total_pr_qty)}</td>
                    <td class="num font-semibold" style="color:#0f766e;">${fmtPrNum(r.total_po_qty)}</td>
                    <td class="num font-semibold" style="color:#0f766e;">${fmtPrNum(r.so_balance_pr)}</td>
                    <td style="text-align:center;white-space:nowrap;">
                        <span class="proc-status is-${r.status_tone}">${r.status_text}</span>
                    </td>
                    <td class="num font-semibold ${r.balance_pr_qty < 0 ? 'is-neg' : ''}" style="color:${r.balance_pr_qty < 0 ? '#dc2626' : (r.balance_pr_qty > 0 ? '#1e293b' : '#16a34a')};">${fmtPrNum(r.balance_pr_qty)}</td>
                    <td class="num font-semibold" style="color:${r.stock_qty >= r.requirement_qty && r.requirement_qty > 0 ? '#16a34a' : '#d97706'};">${fmtPrNum(r.stock_qty)}</td>
                </tr>
            `).join('') : `<tr><td colspan="9" class="pr-modal-empty">No sales orders linked to this component.</td></tr>`;

            const diff = Math.round((totalSelectPrValue - sumReq) * 1000) / 1000;
            const coveragePct = sumReq > 0 ? Math.min(999, Math.round((totalSelectPrValue / sumReq) * 100)) : 100;
            const diffText = diff >= 0 ? `+${fmtPrNum(diff)}` : fmtPrNum(diff);

            const bannerTitle = data.banner_title || `Sales Orders for PR ${escapeHtml(data.pr_number)}`;
            const badgeCountText = (data.pr_count && data.pr_count > 1)
                ? `${rows.length} Sales Order${rows.length !== 1 ? 's' : ''} &middot; ${data.pr_count} PR Lines`
                : `${rows.length} Sales Order${rows.length !== 1 ? 's' : ''}`;

            bodyEl.innerHTML = `
                <div class="pr-modal-banner" style="margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;padding:12px 16px;border-radius:8px;border:1px solid #e2e8f0;flex-wrap:wrap;gap:10px;">
                    <div>
                        <strong style="color:#0f172a;font-size:13.5px;">${bannerTitle}</strong>
                        <span style="color:#64748b;font-size:12px;margin-left:6px;">(Material: ${matText}${descText})</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="display:inline-flex;align-items:center;gap:6px;background:#eff6ff;color:#1e40af;padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:700;border:1px solid #bfdbfe;">
                            <i class="material-icons-round" style="font-size:16px;color:#2563eb;">receipt_long</i>
                            Total Select PR Values: <span style="font-size:13px;color:#1d4ed8;font-weight:800;">${fmtPrNum(totalSelectPrValue)}</span>
                        </span>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;color:#166534;padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:700;border:1px solid #bbf7d0;">
                            <i class="material-icons-round" style="font-size:16px;color:#16a34a;">assignment</i>
                            Total Requirement Qty: <span style="font-size:13px;color:#15803d;font-weight:800;">${fmtPrNum(sumReq)}</span>
                        </span>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:${diff >= 0 ? '#ecfdf5' : '#fef2f2'};color:${diff >= 0 ? '#047857' : '#b91c1c'};padding:5px 12px;border-radius:6px;font-size:12.5px;font-weight:700;border:1px solid ${diff >= 0 ? '#a7f3d0' : '#fecaca'};">
                            <i class="material-icons-round" style="font-size:16px;color:${diff >= 0 ? '#059669' : '#dc2626'};">${diff >= 0 ? 'check_circle' : 'error'}</i>
                            ${sumReq <= 0 ? 'Fully Covered' : (diff >= 0 ? `Surplus: ${diffText} (${coveragePct}%)` : `Shortage: ${diffText} (${coveragePct}%)`)}
                        </span>
                        <span class="proc-drawer-section-badge">${badgeCountText}</span>
                    </div>
                </div>
                <div class="pr-modal-table-wrap" style="overflow-x:auto;">
                    <table class="pr-modal-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th style="min-width:90px;">Sales Doc.</th>
                                <th style="min-width:95px;">Material</th>
                                <th class="num" style="min-width:110px;">Requirement Qty</th>
                                <th class="num" style="min-width:105px;">Total PR Qty</th>
                                <th class="num" style="min-width:105px;">Total PO Qty</th>
                                <th class="num" style="min-width:115px;color:#0f766e;">SO Balance PR</th>
                                <th style="min-width:90px;text-align:center;">Status</th>
                                <th class="num" style="min-width:110px;">Balance PR Qty</th>
                                <th class="num" style="min-width:95px;">Stock QTY</th>
                            </tr>
                        </thead>
                        <tbody>${rowsHtml}</tbody>
                        ${computedRows.length ? `<tfoot>
                            <tr style="background:#fef9c3;font-weight:700;border-top:2px solid #cbd5e1;color:#1e293b;">
                                <th colspan="2" style="font-size:11.5px;color:#334155;">TOTAL (${computedRows.length} ${computedRows.length === 1 ? 'SALES ORDER' : 'SALES ORDERS'})</th>
                                <th class="num font-bold" style="color:#0284c7;">${fmtPrNum(sumReq)}</th>
                                <th class="num font-bold">${fmtPrNum(sumTotalPr)}</th>
                                <th class="num font-bold" style="color:#0f766e;">${fmtPrNum(sumTotalPo)}</th>
                                <th></th>
                                <th></th>
                                <th class="num font-bold ${sumBal < 0 ? 'is-neg' : ''}" style="color:${sumBal < 0 ? '#dc2626' : '#1e293b'};">${fmtPrNum(sumBal)}</th>
                                <th class="num font-bold">${fmtPrNum(sumStock)}</th>
                            </tr>
                        </tfoot>` : ''}
                    </table>
                </div>
            `;
        }
    }

    function updateSelectAllCheckbox() {
        if (!procDrawerEl) return;
        const selectAllCb = procDrawerEl.querySelector('#procDrawerSelectAll');
        if (!selectAllCb) return;
        const allRowCbs = Array.from(procDrawerEl.querySelectorAll('.proc-pr-checkbox'));
        if (!allRowCbs.length) {
            selectAllCb.checked = false;
            selectAllCb.indeterminate = false;
            return;
        }
        const checkedCount = allRowCbs.filter(cb => cb.checked).length;
        selectAllCb.checked = checkedCount === allRowCbs.length;
        selectAllCb.indeterminate = checkedCount > 0 && checkedCount < allRowCbs.length;
    }

    function renderDownPrDetailsUI() {
        if (!procDrawerEl || !activeDownData) return;
        const detailsEl = procDrawerEl.querySelector('#procDrawerPrDetails');
        if (!detailsEl) return;

        const data = activeDownData;
        const badgeLabel = (data.pr_count && data.pr_count > 1) ? `${data.pr_count} PR LINES SELECTED` : 'PR DETAILS';
        const titleHtml = data.pr_title_html || `PR ${escapeHtml(data.pr_number)} &middot; Item ${escapeHtml(data.pr_item)}`;

        detailsEl.innerHTML = `
            <div class="proc-drawer-pr-details-head">
                <div class="proc-drawer-pr-details-title">
                    <span class="proc-drawer-section-badge" style="background:#0284c7;color:#fff;border:none;">${badgeLabel}</span>
                    <h4>${titleHtml}</h4>
                </div>
                <div class="proc-drawer-pr-tabs" style="display:flex;align-items:center;gap:12px;">
                    <span style="font-size:12.5px;font-weight:600;color:#0284c7;display:inline-flex;align-items:center;gap:6px;">
                        <i class="fas fa-file-invoice"></i> Sales Order Requirement
                    </span>
                    <button type="button" class="proc-drawer-pr-details-close" data-down-close title="Close Details">&times;</button>
                </div>
            </div>
            <div class="proc-drawer-pr-details-body" id="procDrawerPrDetailsBody"></div>
        `;

        renderDownPrDetailsBody();
    }

    function syncSelectedPrDetails(item) {
        if (!procDrawerEl || !item) return;
        const detailsEl = procDrawerEl.querySelector('#procDrawerPrDetails');
        if (!detailsEl) return;

        if (selectedPrKeys.size === 0) {
            detailsEl.style.display = 'none';
            activeDownData = null;
            return;
        }

        const selectedList = [];
        const rawLines = sortPrLines(item?.rec?.pr_lines);
        rawLines.forEach(p => {
            const num = String(p.pr_number || '');
            const itm = String(p.pr_item || '10');
            if (selectedPrKeys.has(`${num}_${itm}`)) {
                selectedList.push({ prNumber: num, prItem: itm, line: p });
            }
        });

        if (!selectedList.length) {
            selectedPrKeys.forEach(k => {
                const parts = k.split('_');
                selectedList.push({ prNumber: parts[0], prItem: parts[1] || '10', line: {} });
            });
        }

        activeDownData = buildMultiPrDetailsData(item, selectedList);
        activeDownTab = activeDownTab || 'so';

        renderDownPrDetailsUI();
        detailsEl.style.display = 'block';
    }

    function showDownPrDetails(item, prNumber, prItem) {
        if (!procDrawerEl || !item) return;
        const key = `${prNumber}_${prItem}`;
        selectedPrKeys.add(key);

        const rowCb = procDrawerEl.querySelector(`.proc-pr-checkbox[data-pr-number="${prNumber}"][data-pr-item="${prItem}"]`);
        if (rowCb) {
            rowCb.checked = true;
            const tr = rowCb.closest('tr');
            if (tr) tr.classList.add('is-pr-checked');
        }
        updateSelectAllCheckbox();
        syncSelectedPrDetails(item);
        const detailsEl = procDrawerEl.querySelector('#procDrawerPrDetails');
        if (detailsEl) detailsEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function closeDownPrDetails() {
        if (!procDrawerEl) return;
        const detailsEl = procDrawerEl.querySelector('#procDrawerPrDetails');
        if (detailsEl) detailsEl.style.display = 'none';
        selectedPrKeys.clear();
        procDrawerEl.querySelectorAll('.proc-pr-checkbox').forEach(cb => {
            cb.checked = false;
            const tr = cb.closest('tr');
            if (tr) tr.classList.remove('is-pr-checked');
        });
        const selectAllCb = procDrawerEl.querySelector('#procDrawerSelectAll');
        if (selectAllCb) {
            selectAllCb.checked = false;
            selectAllCb.indeterminate = false;
        }
        activeDownData = null;
    }

    function renderDrawerPrLines(item, searchFilter = '') {
        if (!procDrawerEl) return;
        const tbodyEl = procDrawerEl.querySelector('#procDrawerPrTbody');
        const tfootEl = procDrawerEl.querySelector('#procDrawerPrTfoot');
        const badgeEl = procDrawerEl.querySelector('#procDrawerPrBadge');
        if (!tbodyEl) return;

        let lines = getComponentPrLines(item);
        const totalLinesCount = lines.length;

        if (searchFilter) {
            lines = lines.filter(l => {
                const str = [
                    l.pr_number,
                    l.pr_item,
                    l.status,
                    l.delivery_date,
                    l.po_qty,
                    l.pr_qty,
                    l.balance_pr_qty,
                ].filter(Boolean).join(' ').toLowerCase();
                return str.includes(searchFilter);
            });
        }

        if (badgeEl) {
            badgeEl.textContent = searchFilter 
                ? `${lines.length} of ${totalLinesCount} Lines`
                : `${totalLinesCount.toLocaleString('en-US')} Lines`;
        }

        if (!lines.length) {
            tbodyEl.innerHTML = `<tr><td colspan="10" class="proc-empty" style="text-align:center;padding:36px 16px;color:#64748b;">${totalLinesCount ? 'No PR lines match your search filter.' : 'No Purchase Requisitions linked to this component.'}</td></tr>`;
            if (tfootEl) tfootEl.innerHTML = '';
            closeDownPrDetails();
            return;
        }

        let sumPr = 0, sumPo = 0, sumBal = 0;
        const bodyHtml = lines.map(l => {
            const prQty = Number(l.pr_qty) || 0;
            const poQty = Number(l.po_qty) || 0;
            const balQty = Number(l.balance_pr_qty) || 0;
            sumPr += prQty;
            sumPo += poQty;
            sumBal += balQty;

            const isNeg = balQty < -0.0005;
            const prNum = escapeHtml(l.pr_number || '—');
            const prItem = escapeHtml(l.pr_item || '10');
            const isChecked = selectedPrKeys.has(`${l.pr_number}_${prItem}`);

            return `
                <tr class="${isChecked ? 'is-pr-checked' : ''}">
                    <td style="text-align:center;">
                        <input type="checkbox" class="proc-pr-checkbox" data-pr-number="${escapeHtml(l.pr_number || '')}" data-pr-item="${prItem}" ${isChecked ? 'checked' : ''} title="Select PR ${prNum}">
                    </td>
                    <td style="color:#94a3b8;font-weight:600;font-size:11px;">${l.idx}</td>
                    <td class="proc-prno">
                        <button type="button" class="proc-pr-mono proc-pr-btn" data-pr-number="${escapeHtml(l.pr_number || '')}" data-pr-item="${prItem}" title="Click to view details below for PR ${prNum}">
                            ${prNum}
                        </button>
                    </td>
                    <td class="num" style="color:#64748b;">${prItem}</td>
                    <td class="num font-semibold">${fmtQty(l.pr_qty)}</td>
                    <td class="num font-semibold" style="color:#0f766e;">${fmtQty(l.po_qty)}</td>
                    <td class="num font-semibold ${isNeg ? 'is-neg' : ''}" style="color:${isNeg ? '#dc2626' : (balQty > 0 ? '#1e293b' : '#16a34a')};">${fmtQty(l.balance_pr_qty)}</td>
                    <td class="${l.status === 'overdue' ? 'is-overdue' : ''}" style="white-space:nowrap;">${fmtDate(l.delivery_date)}</td>
                    <td>
                        <span class="proc-status is-${l.status}">${prStatusLabel[l.status] || l.status}</span>
                    </td>
                    <td style="text-align:center;">
                        <button type="button" class="btn-drilldown-action proc-pr-btn" data-pr-number="${escapeHtml(l.pr_number || '')}" data-pr-item="${prItem}" title="View PR Details below">
                            <i class="material-icons-round">expand_more</i> Details
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        tbodyEl.innerHTML = bodyHtml;

        if (tfootEl) {
            tfootEl.innerHTML = `
                <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #cbd5e1;">
                    <th></th>
                    <th></th>
                    <th colspan="2" style="font-size:11.5px;color:#334155;">Total · ${lines.length.toLocaleString('en-US')} lines</th>
                    <th class="num font-bold">${fmtTotal(sumPr)}</th>
                    <th class="num font-bold" style="color:#0f766e;">${fmtTotal(sumPo)}</th>
                    <th class="num font-bold ${sumBal < 0 ? 'is-neg' : ''}">${fmtTotal(sumBal)}</th>
                    <th colspan="3"></th>
                </tr>
            `;
        }

        updateSelectAllCheckbox();
    }

    function getOrCreateProcDrawer() {
        if (procDrawerEl) return procDrawerEl;

        const drawer = document.createElement('div');
        drawer.className = 'proc-drawer';
        drawer.innerHTML = `
            <div class="proc-drawer-backdrop" data-drawer-close></div>
            <div class="proc-drawer-panel" role="dialog" aria-modal="true" aria-label="Component Procurement Details">
                <div class="proc-drawer-head">
                    <div class="proc-drawer-head-info">
                        <span class="proc-drawer-kicker">Component Procurement Overview</span>
                        <div class="proc-drawer-title-row">
                            <h3 id="procDrawerCode">—</h3>
                            <span class="proc-badge-status" id="procDrawerStatus"></span>
                            <span class="proc-tag-type" id="procDrawerType"></span>
                            <span class="proc-tag-code" id="procDrawerGroup"></span>
                        </div>
                        <p class="proc-drawer-sub" id="procDrawerDesc">—</p>
                    </div>
                    <button type="button" class="proc-drawer-close" data-drawer-close aria-label="Close Drawer" title="Close (Esc)">&times;</button>
                </div>
                <div class="proc-drawer-body">
                    <div class="proc-drawer-stats" id="procDrawerStats"></div>

                    <div class="proc-drawer-section">
                        <div class="proc-drawer-section-head">
                            <div style="display:flex;align-items:center;gap:10px;white-space:nowrap;">
                                <h4><i class="material-icons-round">receipt_long</i> Purchase Requisitions (PR Lines)</h4>
                                <span class="proc-drawer-section-badge" id="procDrawerPrBadge">0 Lines</span>
                            </div>
                            <div class="proc-drawer-tools" style="display:flex;align-items:center;gap:10px;">
                                <input type="text" class="proc-substrip-search" id="procDrawerSearch" placeholder="Search PR Number, PO, date..." style="padding:6px 10px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;min-width:220px;" />
                            </div>
                        </div>

                        <div class="proc-drawer-table-wrap">
                            <table class="proc-substrip-table proc-drawer-table">
                                <thead>
                                    <tr>
                                        <th style="width:36px;text-align:center;">
                                            <input type="checkbox" class="proc-pr-select-all" id="procDrawerSelectAll" title="Select / deselect all PR lines">
                                        </th>
                                        <th style="width:36px;text-align:center;">#</th>
                                        <th style="min-width:105px;">PR Number</th>
                                        <th class="num" style="width:48px;">Item</th>
                                        <th class="num">PR Qty</th>
                                        <th class="num">PO Qty</th>
                                        <th class="num">Balance Qty</th>
                                        <th style="min-width:95px;">Delivery Date</th>
                                        <th style="min-width:85px;">Status</th>
                                        <th style="width:90px;text-align:center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="procDrawerPrTbody"></tbody>
                                <tfoot id="procDrawerPrTfoot"></tfoot>
                            </table>
                        </div>

                        <!-- Inline Down Details Container (No Modal View) -->
                        <div class="proc-drawer-pr-details" id="procDrawerPrDetails" style="display:none;"></div>
                    </div>
                </div>
            </div>
        `;

        drawer.addEventListener('click', (e) => {
            if (e.target.closest('[data-drawer-close]')) {
                closeProcDrawer();
                return;
            }
            if (e.target.closest('[data-down-close]')) {
                closeDownPrDetails();
                return;
            }
            const prBtn = e.target.closest('.proc-pr-btn');
            if (prBtn && currentDrawerItem) {
                const prNumber = prBtn.getAttribute('data-pr-number');
                const prItem = prBtn.getAttribute('data-pr-item');
                showDownPrDetails(currentDrawerItem, prNumber, prItem);
                return;
            }
        });

        drawer.addEventListener('change', (e) => {
            const selectAllCb = e.target.closest('#procDrawerSelectAll');
            if (selectAllCb && currentDrawerItem) {
                const isChecked = selectAllCb.checked;
                const rowCbs = drawer.querySelectorAll('.proc-pr-checkbox');
                rowCbs.forEach(cb => {
                    cb.checked = isChecked;
                    const prNum = cb.getAttribute('data-pr-number');
                    const prItm = cb.getAttribute('data-pr-item');
                    const key = `${prNum}_${prItm}`;
                    if (isChecked) {
                        selectedPrKeys.add(key);
                    } else {
                        selectedPrKeys.delete(key);
                    }
                    const tr = cb.closest('tr');
                    if (tr) tr.classList.toggle('is-pr-checked', isChecked);
                });
                selectAllCb.indeterminate = false;
                syncSelectedPrDetails(currentDrawerItem);
                return;
            }

            const cb = e.target.closest('.proc-pr-checkbox');
            if (cb && currentDrawerItem) {
                const prNumber = cb.getAttribute('data-pr-number');
                const prItem = cb.getAttribute('data-pr-item');
                const key = `${prNumber}_${prItem}`;
                if (cb.checked) {
                    selectedPrKeys.add(key);
                } else {
                    selectedPrKeys.delete(key);
                }
                const tr = cb.closest('tr');
                if (tr) tr.classList.toggle('is-pr-checked', cb.checked);
                updateSelectAllCheckbox();
                syncSelectedPrDetails(currentDrawerItem);
                return;
            }
        });

        const drawerSearch = drawer.querySelector('#procDrawerSearch');
        if (drawerSearch) {
            drawerSearch.addEventListener('input', () => {
                if (currentDrawerItem) {
                    renderDrawerPrLines(currentDrawerItem, drawerSearch.value.trim().toLowerCase());
                }
            });
            drawerSearch.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    drawerSearch.value = '';
                    if (currentDrawerItem) renderDrawerPrLines(currentDrawerItem, '');
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });
        }

        document.body.appendChild(drawer);
        procDrawerEl = drawer;
        return drawer;
    }

    function openProcDrawer(item) {
        if (!item || !item.rec) return;
        currentDrawerItem = item;
        const drawer = getOrCreateProcDrawer();
        const rec = item.rec;

        const codeEl = drawer.querySelector('#procDrawerCode');
        const descEl = drawer.querySelector('#procDrawerDesc');
        const typeEl = drawer.querySelector('#procDrawerType');
        const groupEl = drawer.querySelector('#procDrawerGroup');
        const statusEl = drawer.querySelector('#procDrawerStatus');

        if (codeEl) codeEl.textContent = rec.component_material || '—';
        if (descEl) descEl.textContent = rec.component_description || '—';
        if (typeEl) {
            typeEl.textContent = rec.material_type || '';
            typeEl.style.display = rec.material_type ? '' : 'none';
        }
        if (groupEl) {
            groupEl.textContent = rec.material_group || '';
            groupEl.style.display = rec.material_group ? '' : 'none';
        }
        if (statusEl) {
            const st = getStockStatus(rec);
            statusEl.className = `proc-badge-status is-${st.tone}`;
            statusEl.innerHTML = `<i class="${st.icon}"></i><span>${escapeHtml(st.label)}</span>`;
        }

        renderDrawerStats(rec);
        renderDrawerMeta(rec);

        closeDownPrDetails();

        const searchInput = drawer.querySelector('#procDrawerSearch');
        if (searchInput) searchInput.value = '';

        renderDrawerPrLines(item, '');

        drawer.removeAttribute('hidden');
        drawer.setAttribute('aria-hidden', 'false');
        void drawer.offsetWidth;
        drawer.classList.add('is-open');
        document.body.classList.add('proc-drawer-open');

        items.forEach(i => i.tr && i.tr.classList.remove('is-drawer-active'));
        if (item.tr) item.tr.classList.add('is-drawer-active');
    }

    function closeProcDrawer() {
        if (!procDrawerEl) return;
        closeDownPrDetails();
        procDrawerEl.classList.remove('is-open');
        procDrawerEl.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('proc-drawer-open');
        const modal = document.getElementById('prDetailsModal');
        if (!modal || !modal.classList.contains('is-open')) {
            document.body.style.overflow = '';
        }
        currentDrawerItem = null;
        items.forEach(i => i.tr && i.tr.classList.remove('is-drawer-active'));
    }

    window.procOpenDrawer = openProcDrawer;
    window.procCloseDrawer = closeProcDrawer;

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
            if (e.target.closest('.rpt-group-header-row')) return;

            const btn = e.target.closest('.proc-pr-toggle');
            if (btn) {
                const item = items.find(i => String(i.origIndex) === btn.getAttribute('data-item'));
                setOpen(item, !item?.open);
                updateToggleAllLabel();
                return;
            }
            const item = panelItem(e.target);
            if (item?.panel) {
                const prBtn = e.target.closest('.proc-pr-btn');
                if (prBtn) {
                    const prNumber = prBtn.getAttribute('data-pr-number');
                    const prItem = prBtn.getAttribute('data-pr-item');
                    openPrDetailsModal(item, prNumber, prItem);
                    return;
                }
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
                return;
            }

            // Ignore clicks directly on interactive form controls
            if (e.target.closest('input, textarea, select')) return;

            // Click anywhere on a component row opens 90% offcanvas drawer
            const row = e.target.closest('tr[data-item-index], tr[data-orig-sno], tr.proc-row-clickable');
            if (row && !row.classList.contains('proc-detail') && !row.classList.contains('rpt-group-header-row')) {
                const itemIdx = row.hasAttribute('data-item-index') ? parseInt(row.getAttribute('data-item-index'), 10) : -1;
                const rowItem = (itemIdx >= 0 && items[itemIdx]?.origIndex === itemIdx)
                    ? items[itemIdx]
                    : (items.find(i => i.origIndex === itemIdx) || items.find(i => i.tr === row));
                if (rowItem) {
                    openProcDrawer(rowItem);
                }
                return;
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
        toggleAllOrdersBtn?.addEventListener('click', () => {
            if (!activeGroupBy) return;
            const uniqueGroups = Array.from(new Set(items.filter(i => i.visible).map(i => String(i.rec[activeGroupBy] ?? '').trim() || '(Blank)')));
            const anyCollapsed = uniqueGroups.some(g => collapsedGroups.has(g));
            if (anyCollapsed) {
                uniqueGroups.forEach(g => collapsedGroups.delete(g));
            } else {
                uniqueGroups.forEach(g => collapsedGroups.add(g));
            }
            insertGroupHeaders();
            updateToggleAllOrdersLabel();
            updateToggleAllLabel();
        });
        toggleAllPrBtn?.addEventListener('click', () => {
            const targets = items.filter(i => i.visible
                && (!activeGroupBy || !collapsedGroups.has(String(i.rec[activeGroupBy] ?? '').trim() || '(Blank)'))
                && (Number(i.rec.pr_count) || 0) > 0);
            const open = targets.some(i => !i.open);
            targets.forEach(i => setOpen(i, open));
            updateToggleAllLabel();
        });

        groupByBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            groupByDropdown?.classList.toggle('is-open');
        });

        groupByMenu?.addEventListener('click', (e) => {
            const itemBtn = e.target.closest('[data-group-by]');
            if (!itemBtn) return;
            e.stopPropagation();
            const field = itemBtn.getAttribute('data-group-by') || '';
            if (field === activeGroupBy) {
                groupByDropdown?.classList.remove('is-open');
                return;
            }
            activeGroupBy = field;
            collapsedGroups.clear();
            groupByMenu.querySelectorAll('[data-group-by]').forEach(btn => {
                btn.classList.toggle('is-active', (btn.getAttribute('data-group-by') || '') === activeGroupBy);
            });
            const labelText = groupFieldLabels[activeGroupBy] || 'None';
            if (groupByLabel) {
                groupByLabel.textContent = `Group: ${labelText}`;
            }
            groupByBtn?.classList.toggle('is-active', activeGroupBy !== '');
            groupByDropdown?.classList.remove('is-open');
            sortItems();
            applyFilterAndSearch();
        });

        document.addEventListener('click', (e) => {
            if (groupByDropdown && !groupByDropdown.contains(e.target)) {
                groupByDropdown.classList.remove('is-open');
            }
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
                showEmpty(`No procurement data for sales order(s) ${cfg.so}.`);
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
