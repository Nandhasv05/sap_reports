/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Utilization JavaScript
 */
(function () {
    const app = document.querySelector('.rpt-app');
    const boot = document.getElementById('rptBoot');
    const spinner = document.getElementById('rptSpinner');
    const dataNode = document.getElementById('rptChartData');
    const isTrims = app ? app.classList.contains('is-trims') : false;

    /*
    *  COLOR PLAN - GET THE COLUMN INDEX BASED ON THE MATERIAL TYPE
    */
    const COL = isTrims
        ? { bom: 11, plan: 12, prod: 13, po: 14, grn: 15, issue: 16 }
        : { bom: 7, plan: 8, prod: 9, po: 10, grn: 11, issue: 12 };
    const TABLE_COLUMNS = 18;
    const MAT_COL = 2;

    /*
    *  SHOW SPINNER 
    */
    function showSpinner() { if (!spinner) return; spinner.hidden = false; document.body.classList.add('rpt-loading'); }

    
    function hideBoot() { if (app) app.classList.remove('is-first'); if (boot) boot.hidden = true; drawCharts(); }

    const form = document.getElementById('rptFilterForm');
    form?.addEventListener('submit', function () { const so = (document.getElementById('so')?.value || '').trim(); if (so === '') return; showSpinner(); });
    document.querySelectorAll('.rpt-tab, .rpt-pager a, .lookup-hint a, .lookup-switch a').forEach(function (el) { el.addEventListener('click', function () { if (el.classList.contains('active') || el.classList.contains('on')) return; showSpinner(); }); });
    document.addEventListener('click', function (e) { const soTarget = e.target.closest('.so-chip, .so-main-link'); if (soTarget) showSpinner(); });

    let chartsDrawn = false;
    let totalsChart = null;
    let mixChart = null;
    const mixColors = ['#0f766e','#0284c7','#7c3aed','#d97706','#16a34a','#e11d48','#0ea5e9','#94a3b8','#f59e0b','#10b981'];
    function drawCharts() {
        if (chartsDrawn || !dataNode || typeof Chart === 'undefined') return;
        chartsDrawn = true;
        let data = {};
        try { data = JSON.parse(dataNode.textContent || '{}'); } catch (e) { return; }
        const totalsEl = document.getElementById('rptTotalsChart');
        const mixEl = document.getElementById('rptMixChart');
        if (totalsEl) {
            totalsChart = new Chart(totalsEl, { type: 'bar', data: { labels: data.totals_labels || [], datasets: [{ label: 'Quantity', data: data.totals || [], backgroundColor: ['#0f766e','#0284c7','#16a34a','#d97706','#7c3aed','#e11d48'], borderRadius: 8, maxBarThickness: 42 }] }, options: { responsive: true, maintainAspectRatio: false, animation: { duration: 600 }, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true } } } });
        }
        if (mixEl) {
            const hasCat = isTrims && (data.cat_labels || []).length > 0;
            const mixLabels = hasCat ? data.cat_labels : (data.mix_labels || []);
            const mixValues = hasCat ? data.cat_values : (data.mix_values || []);
            mixChart = new Chart(mixEl, { type: 'doughnut', data: { labels: mixLabels.length ? mixLabels : ['No data'], datasets: [{ data: mixValues.length ? mixValues : [1], backgroundColor: mixValues.length ? mixColors.slice(0, mixValues.length) : ['#e2e8e0'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '62%', animation: { duration: 600 }, plugins: { legend: { display: mixValues.length > 0, position: 'right', labels: { boxWidth: 10, font: { size: 11 } } } } } });
        }
    }

    function getTrend(val, bom) {
        if (!bom || bom <= 0) {
            if (val > 0) return { status: 'up', icon: 'fa-arrow-trend-up', pct: 100, label: 'Exceeds (BOM 0)' };
            return { status: 'neutral', icon: 'fa-minus', pct: 0, label: 'No data' };
        }
        if (!val || val <= 0) {
            return { status: 'neutral', icon: 'fa-minus', pct: 0, label: '0% vs BOM (No data)' };
        }
        const pct = Math.round((val / bom) * 1000) / 10;
        if (val >= bom) {
            return { status: 'up', icon: 'fa-arrow-trend-up', pct: pct, label: pct + '% vs BOM (Meets/Exceeds BOM)' };
        }
        return { status: 'down', icon: 'fa-arrow-trend-down', pct: pct, label: pct + '% vs BOM (Below BOM)' };
    }

    function updateStatCards(vc, sb, sp, spd, spo, sg, si) {
        const cards = document.querySelectorAll('.rpt-stat b');
        if (cards.length < 7) return;
        const fmt = n => (n === 0 || n === null || n === undefined)
            ? '—'
            : Math.round(n).toLocaleString('en-US');

        if (cards[0]) cards[0].textContent = vc === 0 ? '0' : vc.toLocaleString('en-US');
        if (cards[1]) cards[1].textContent = fmt(sb);
        if (cards[2]) cards[2].textContent = fmt(sp);
        if (cards[3]) cards[3].textContent = fmt(spd);
        if (cards[4]) cards[4].textContent = fmt(spo);
        if (cards[5]) cards[5].textContent = fmt(sg);
        if (cards[6]) cards[6].textContent = fmt(si);

        const plnTr = getTrend(sp, sb);
        const prdTr = getTrend(spd, sb);
        const poTr  = getTrend(spo, sb);
        const grnTr = getTrend(sg, sb);
        const issTr = getTrend(si, sb);

        function updateCardIco(id, tr, name, val) {
            const el = document.getElementById(id);
            if (!el) return;
            // Preserve bom class for BOM card; else set status class
            const cls = el.classList.contains('is-bom') ? 'is-bom' : `is-${tr.status}`;
            el.className = `card-trend-ico ${cls}`;
            el.title = `${name}: ${fmt(val)} vs BOM: ${fmt(sb)} (${tr.label})`;
            el.innerHTML = `<i class="fas ${tr.icon}"></i>`;
        }

        updateCardIco('statTrendIco-planned',    plnTr, 'Planned', sp);
        updateCardIco('statTrendIco-production', prdTr, 'Production', spd);
        updateCardIco('statTrendIco-po',         poTr,  'PO Qty', spo);
        updateCardIco('statTrendIco-grn',        grnTr, 'GRN Qty', sg);
        updateCardIco('statTrendIco-issue',      issTr, 'Issue Qty', si);
    }

    function updateCharts(visibleRows, activeCat) {
        if (!totalsChart || !mixChart) return;

        const sb = visibleRows.reduce((sum, r) => sum + r.colNums[COL.bom], 0);
        const sp = visibleRows.reduce((sum, r) => sum + r.colNums[COL.plan], 0);
        const spd = visibleRows.reduce((sum, r) => sum + r.colNums[COL.prod], 0);
        const spo = visibleRows.reduce((sum, r) => sum + r.colNums[COL.po], 0);
        const sg = visibleRows.reduce((sum, r) => sum + r.colNums[COL.grn], 0);
        const si = visibleRows.reduce((sum, r) => sum + r.colNums[COL.issue], 0);

        totalsChart.data.datasets[0].data = [sb, sp, spd, spo, sg, si].map(v => Math.round(v * 100) / 100);
        totalsChart.update('none');

        const byKey = {};
        const useCategory = isTrims && (activeCat === '' || activeCat === undefined);
        const matColIndex = MAT_COL;

        const seenKeys = new Set();
        visibleRows.forEach(r => {
            const mat = r.colTexts[matColIndex] || 'Unknown';
            if (!seenKeys.has(mat)) {
                seenKeys.add(mat);
                const key = useCategory ? (r.category || 'Other') : mat;
                byKey[key] = (byKey[key] || 0) + r.colNums[COL.bom];
            }
        });

        const sorted = Object.entries(byKey).sort((a, b) => b[1] - a[1]);
        const top = sorted.slice(0, 7);
        const restSum = sorted.slice(7).reduce((sum, [, val]) => sum + val, 0);
        if (restSum > 0) {
            top.push(['Other', restSum]);
        }

        const labels = top.map(([k]) => k);
        const values = top.map(([, v]) => Math.round(v * 100) / 100);

        mixChart.data.labels = labels.length ? labels : ['No data'];
        mixChart.data.datasets[0].data = values.length ? values : [1];
        mixChart.data.datasets[0].backgroundColor = values.length ? mixColors.slice(0, values.length) : ['#e2e8e0'];
        if (mixChart.options && mixChart.options.plugins && mixChart.options.plugins.legend) {
            mixChart.options.plugins.legend.display = values.length > 0;
        }
        mixChart.update('none');
    }

    if (app && app.classList.contains('is-first')) { window.setTimeout(hideBoot, 1400); } else { drawCharts(); }

    const pageCfg = readPageConfig();
    if (pageCfg.live && pageCfg.dataUrl && pageCfg.so) {
        loadLiveSapData(pageCfg);
    } else {
        initDataTable();
    }

    function readPageConfig() {
        const node = document.getElementById('rptPageConfig');
        if (!node) return {};
        try { return JSON.parse(node.textContent || '{}'); } catch (e) { return {}; }
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function dashCell(n) {
        if (n === null || n === undefined || n === '' || n === '-') return '—';
        const text = String(n).trim();
        if (text === '') return '—';
        const cleaned = text.replace(/,/g, '');
        if (!/^-?\d+(\.\d+)?$/.test(cleaned)) {
            return text;
        }
        const num = parseFloat(cleaned);
        if (!isNaN(num) && Math.abs(num) < 0.0000001) return '—';
        if (Math.abs(num - Math.round(num)) < 0.0005) return String(Math.round(num));
        return String(num);
    }

    function displayCode(n) {
        const text = String(n ?? '').trim();
        return (text === '' || text === '-') ? '—' : text;
    }

    function soListFromRow(row) {
        if (Array.isArray(row.grn_so_list) && row.grn_so_list.length) return row.grn_so_list;
        if (row.grn_sales_orders) {
            return String(row.grn_sales_orders).replace(/ /g, '').split(',').map(s => s.trim()).filter(Boolean);
        }
        return [];
    }

    function renderLiveRows(cfg, records) {
        const tbody = document.getElementById('rptTableBody');
        if (!tbody) return;
        const isFabric = !!cfg.isFabric;
        const colSpan = TABLE_COLUMNS;
        const pageUrl = cfg.pageUrl || '';
        const noMatch = document.getElementById('rptNoMatchRow');
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach(tr => tr.remove());

        if (!records.length) {
            const empty = document.createElement('tr');
            empty.innerHTML = `<td colspan="${colSpan}" class="rpt-table-empty">No ${isFabric ? 'fabric' : 'trims'} utilization for sales order ${escapeHtml(cfg.so)}.</td>`;
            tbody.prepend(empty);
            return;
        }

        const frag = document.createDocumentFragment();
        records.forEach((row, i) => {
            const bom = parseFloat(String(row.bom_qty ?? 0).replace(/,/g, '')) || 0;
            const plan = parseFloat(String(row.planned_qty ?? 0).replace(/,/g, '')) || 0;
            const prod = parseFloat(String(row.production_qty ?? 0).replace(/,/g, '')) || 0;
            const po = parseFloat(String(row.po_qty ?? 0).replace(/,/g, '')) || 0;
            const grn = parseFloat(String(row.grn_qty ?? 0).replace(/,/g, '')) || 0;
            const iss = parseFloat(String(row.issue_qty ?? 0).replace(/,/g, '')) || 0;
            const soNum = String(row.sales_order || '').replace(/,/g, '').trim();
            const soHtml = soNum && soNum !== '-'
                ? `<a href="${escapeHtml(pageUrl)}?so=${encodeURIComponent(soNum)}" class="so-main-link" title="Direct API call for Sales Order ${escapeHtml(soNum)}">${escapeHtml(soNum)}</a>`
                : '-';
            const sos = soListFromRow(row);
            let extraSo = '<span class="grn-so-empty">-</span>';
            if (sos.length) {
                extraSo = '<div class="grn-so-tags">' + sos.map(item => {
                    const clean = String(item).replace(/,/g, '').trim();
                    if (!clean) return '';
                    return `<a href="${escapeHtml(pageUrl)}?so=${encodeURIComponent(clean)}" class="so-chip" title="Direct API call for Sales Order ${escapeHtml(clean)}">${escapeHtml(clean)}</a>`;
                }).join('') + '</div>';
            }
            const tr = document.createElement('tr');
            tr.setAttribute('data-orig-sno', String(i + 1));
            tr.setAttribute('data-r-bom', String(bom));
            tr.setAttribute('data-r-plan', String(plan));
            tr.setAttribute('data-r-prod', String(prod));
            tr.setAttribute('data-r-po', String(po));
            tr.setAttribute('data-r-grn', String(grn));
            tr.setAttribute('data-r-iss', String(iss));
            const textCell = (value) => `<td title="${escapeHtml(displayCode(value))}">${escapeHtml(displayCode(value))}</td>`;
            const matInfoHtml = isFabric ? '' : [row.mat_type, row.mat_type_desc, row.mat_group, row.mat_group_desc].map(textCell).join('');
            const attrHtml = isFabric ? [row.attribute1_text, row.attribute2_text, row.attribute3_text, row.colour].map(textCell).join('') : '';
            // Cell order must match the <thead> in app/views/reports/material.php (18 columns for both reports).
            tr.innerHTML = `
                <td class="num sno">${i + 1}</td>
                <td>${soHtml}</td>
                <td class="rpt-mat" title="${escapeHtml(displayCode(row.material))}">${escapeHtml(displayCode(row.material))}</td>
                ${textCell(row.description)}
                ${matInfoHtml}
                <td>${escapeHtml(displayCode(row.purchase_order).replace(/,/g, ''))}</td>
                <td>${escapeHtml(displayCode(row.po_item).replace(/,/g, ''))}</td>
                <td class="num qty-cell" data-qty-type="so"><span class="qty-val">${escapeHtml(dashCell(row.so_qty))}</span></td>
                <td class="num bom-qty-cell"><span class="bom-val">${escapeHtml(dashCell(row.bom_qty))}</span></td>
                <td class="num qty-cell" data-qty-type="plan"><span class="qty-val">${escapeHtml(dashCell(row.planned_qty))}</span></td>
                <td class="num qty-cell" data-qty-type="prod"><span class="qty-val">${escapeHtml(dashCell(row.production_qty))}</span></td>
                <td class="num qty-cell" data-qty-type="po"><span class="qty-val">${escapeHtml(dashCell(row.po_qty))}</span></td>
                <td class="num qty-cell" data-qty-type="grn"><span class="qty-val">${escapeHtml(dashCell(row.grn_qty))}</span></td>
                <td class="num qty-cell" data-qty-type="issue"><span class="qty-val">${escapeHtml(dashCell(row.issue_qty))}</span></td>
                <td class="grn-sos">${extraSo}</td>
                ${attrHtml}
            `;
            frag.appendChild(tr);
        });
        if (noMatch) tbody.insertBefore(frag, noMatch);
        else tbody.appendChild(frag);
    }

    function applyLiveSummary(summary) {
        const s = summary || {};
        const lines = Number(s.lines || 0);
        const bom = Number(s.bom_qty || 0);
        const plan = Number(s.planned_qty || 0);
        const prod = Number(s.production_qty || 0);
        const po = Number(s.po_qty || 0);
        const grn = Number(s.grn_qty || 0);
        const issue = Number(s.issue_qty || 0);
        updateStatCards(lines, bom, plan, prod, po, grn, issue);
        const setFoot = (id, val) => {
            const el = document.getElementById(id);
            const span = el?.querySelector('.foot-val') || el;
            if (span) span.textContent = dashCell(val);
        };
        const label = document.getElementById('footTotalLabel');
        if (label) label.textContent = `Total (${lines.toLocaleString('en-US')} lines)`;
        setFoot('footBomQty', bom);
        setFoot('footPlannedQty', plan);
        setFoot('footProductionQty', prod);
        setFoot('footPoQty', po);
        setFoot('footGrnQty', grn);
        setFoot('footIssueQty', issue);
        const count = document.getElementById('rptTableCount');
        if (count) count.textContent = `Showing ${lines} of ${lines} lines`;
        const allCount = document.querySelector('.rpt-trim-pill-all .rpt-cat-dd-item-count');
        if (allCount) allCount.textContent = String(lines);
        const selectedCount = document.getElementById('rptCatSelectedCount');
        if (selectedCount) selectedCount.textContent = String(lines);
        
    }

    function fillMaterialGroupMenu(rowData) {
        const menu = document.getElementById('rptCatDropdownMenu');
        if (!menu || !isTrims) return;
        const groups = new Map();
        rowData.forEach(item => {
            const g = groups.get(item.category) || { code: item.groupCode, count: 0 };
            g.count++;
            groups.set(item.category, g);
        });
        menu.querySelectorAll('.rpt-cat-dd-item:not(.rpt-trim-pill-all)').forEach(el => el.remove());
        const allCount = menu.querySelector('.rpt-trim-pill-all .rpt-cat-dd-item-count');
        if (allCount) allCount.textContent = String(rowData.length);
        Array.from(groups.entries())
            .sort((a, b) => a[0].localeCompare(b[0], undefined, { numeric: true, sensitivity: 'base' }))
            .forEach(([label, g]) => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'rpt-cat-dd-item rpt-trim-pill';
                btn.setAttribute('data-cat', label);
                btn.setAttribute('role', 'menuitem');
                const code = g.code && g.code !== label ? ` <small style="color:#94a3b8;">${escapeHtml(g.code)}</small>` : '';
                btn.innerHTML = `<span class="material-icons-round">label</span><span class="rpt-cat-dd-item-text">${escapeHtml(label)}${code}</span><span class="rpt-cat-dd-item-count">${g.count}</span>`;
                menu.appendChild(btn);
            });
    }

    function setupColumnFeatures(table, onChange) {
        const headerRow = table.querySelector('thead tr.rpt-header-row');
        const menu = document.getElementById('rptColumnToggleMenu');
        const dropdown = document.getElementById('rptColumnToggleDropdown');
        const toggleBtn = document.getElementById('rptColumnToggleBtn');
        if (!headerRow) return;
        const headers = Array.from(headerRow.children);
        const count = headers.length;
        const names = headers.map(th => (th.textContent || '').replace(/\s+/g, ' ').trim());
        const storeKey = (isTrims ? 'trims' : 'fabric') + '_v2';
        const hiddenKey = 'rpt_hidden_cols_' + storeKey;
        const orderKey = 'rpt_col_order_' + storeKey;

        const readJson = (key) => { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } };
        const writeJson = (key, val) => { try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {} };
        const defaultOrder = () => headers.map((_, i) => i);
        const validOrder = (o) => Array.isArray(o) && o.length === count && new Set(o).size === count && o.every(i => Number.isInteger(i) && i >= 0 && i < count);

        let order = readJson(orderKey);
        if (!validOrder(order)) order = defaultOrder();
        let hidden = new Set((readJson(hiddenKey) || []).filter(i => Number.isInteger(i) && i > 0 && i < count));

        // Tag every full-width row cell with its original column index so hide/reorder work whatever the current order.
        function tableRows() {
            return Array.from(table.querySelectorAll('thead tr, tbody tr, tfoot tr')).filter(tr => tr.children.length === count);
        }
        tableRows().forEach(tr => Array.from(tr.children).forEach((cell, i) => {
            if (!cell.hasAttribute('data-col-id')) cell.setAttribute('data-col-id', String(i));
        }));

        const style = document.createElement('style');
        style.id = 'rptHiddenColsStyle';
        document.head.appendChild(style);

        function applyHidden() {
            style.textContent = Array.from(hidden)
                .map(i => `#${table.id} [data-col-id="${i}"]{display:none !important;}`)
                .join('\n');
            menu?.querySelectorAll('input[data-col-toggle]').forEach(cb => {
                cb.checked = !hidden.has(parseInt(cb.getAttribute('data-col-toggle'), 10));
            });
            toggleBtn?.classList.toggle('is-active', hidden.size > 0);
        }

        function applyOrder() {
            tableRows().forEach(tr => {
                const byId = {};
                Array.from(tr.children).forEach(cell => { byId[cell.getAttribute('data-col-id')] = cell; });
                const frag = document.createDocumentFragment();
                order.forEach(i => { if (byId[i]) frag.appendChild(byId[i]); });
                tr.appendChild(frag);
            });
        }

        function buildMenu() {
            if (!menu) return;
            const items = order.map(i => `
                <label class="rpt-cat-dd-item" style="cursor:${i === 0 ? 'default' : 'pointer'};">
                    <input type="checkbox" data-col-toggle="${i}" ${hidden.has(i) ? '' : 'checked'} ${i === 0 ? 'disabled' : ''} style="margin:0;">
                    <span class="rpt-cat-dd-item-text">${escapeHtml(names[i])}</span>
                </label>`).join('');
            menu.innerHTML = `
                <div class="rpt-cat-dd-header">Show / hide columns</div>
                ${items}
                <div class="rpt-cat-dd-divider"></div>
                <button type="button" class="rpt-cat-dd-item" data-col-reset>
                    <span class="material-icons-round">restart_alt</span>
                    <span class="rpt-cat-dd-item-text">Reset columns</span>
                </button>`;
        }

        function changed() {
            applyHidden();
            if (typeof onChange === 'function') onChange();
        }

        toggleBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown?.classList.toggle('is-open');
        });
        document.addEventListener('click', (e) => {
            if (dropdown && !dropdown.contains(e.target)) dropdown.classList.remove('is-open');
        });
        menu?.addEventListener('click', (e) => e.stopPropagation());
        menu?.addEventListener('change', (e) => {
            const cb = e.target.closest('input[data-col-toggle]');
            if (!cb) return;
            const i = parseInt(cb.getAttribute('data-col-toggle'), 10);
            if (cb.checked) hidden.delete(i); else hidden.add(i);
            writeJson(hiddenKey, Array.from(hidden));
            changed();
        });
        menu?.addEventListener('click', (e) => {
            if (!e.target.closest('[data-col-reset]')) return;
            hidden = new Set();
            order = defaultOrder();
            writeJson(hiddenKey, []);
            writeJson(orderKey, order);
            applyOrder();
            buildMenu();
            changed();
        });

        let dragFrom = null;
        const clearDragMarks = () => headerRow.querySelectorAll('.is-drag-over, .is-dragging').forEach(th => th.classList.remove('is-drag-over', 'is-dragging'));
        headers.forEach(th => {
            th.setAttribute('draggable', 'true');
            th.title = (th.title ? th.title + ' — ' : '') + 'drag to move column';
            th.addEventListener('dragstart', (e) => {
                dragFrom = th.getAttribute('data-col-id');
                th.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', dragFrom); } catch (err) {}
                }
            });
            th.addEventListener('dragover', (e) => {
                if (dragFrom === null) return;
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                if (th.getAttribute('data-col-id') !== dragFrom) th.classList.add('is-drag-over');
            });
            th.addEventListener('dragleave', () => th.classList.remove('is-drag-over'));
            th.addEventListener('drop', (e) => {
                e.preventDefault();
                const to = th.getAttribute('data-col-id');
                const from = dragFrom;
                dragFrom = null;
                clearDragMarks();
                if (from === null || to === null || from === to) return;
                const fromId = parseInt(from, 10);
                const toId = parseInt(to, 10);
                const fromPos = order.indexOf(fromId);
                const next = order.filter(i => i !== fromId);
                const toPos = next.indexOf(toId);
                next.splice(fromPos <= toPos ? toPos + 1 : toPos, 0, fromId);
                order = next;
                writeJson(orderKey, order);
                applyOrder();
                buildMenu();
                changed();
            });
            th.addEventListener('dragend', () => { dragFrom = null; clearDragMarks(); });
        });

        applyOrder();
        buildMenu();
        applyHidden();
    }

    function loadLiveSapData(cfg) {
        showSpinner();
        const params = new URLSearchParams({
            so: cfg.so,
            q: cfg.search || '',
            _: String(Date.now()),
        });
        fetch(`${cfg.dataUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin',
        })
            .then(async (res) => {
                const raw = await res.text();
                let json;
                try { json = JSON.parse(raw); } catch (e) {
                    const type = res.headers.get('Content-Type') || '';
                    console.error('SAP report data: non-JSON response', res.status, res.url, raw.slice(0, 300));
                    if (res.redirected || /login/i.test(res.url) || (res.ok && /text\/html/i.test(type))) {
                        throw new Error('Your portal session has expired. Please log in again and reopen the report.');
                    }
                    throw new Error(`SAP data could not be read (HTTP ${res.status}). Please retry.`);
                }
                if (!res.ok || json.success === false) {
                    throw new Error(json.message || json.error || `SAP API failed (${res.status})`);
                }
                return json;
            })
            .then((json) => {
                const data = json.data || {};
                renderLiveRows(cfg, data.records || []);
                applyLiveSummary(data.summary || {});
                if (dataNode) dataNode.textContent = JSON.stringify(data.chart || {});
                chartsDrawn = false;
                if (totalsChart) { totalsChart.destroy(); totalsChart = null; }
                if (mixChart) { mixChart.destroy(); mixChart = null; }
                drawCharts();
                initDataTable();
            })
            .catch((err) => {
                const tbody = document.getElementById('rptTableBody');
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="${TABLE_COLUMNS}" class="rpt-table-empty">${escapeHtml(err.message || 'Unable to load SAP data.')}</td></tr>`;
                }
                const alertBox = document.createElement('div');
                alertBox.className = 'rpt-alert';
                alertBox.textContent = err.message || 'Unable to load SAP data.';
                document.querySelector('.rpt-main')?.prepend(alertBox);
            })
            .finally(() => {
                if (spinner) spinner.hidden = true;
                document.body.classList.remove('rpt-loading');
            });
    }

    function initDataTable() {
        const table = document.getElementById('rptDataTable');
        const tbody = document.getElementById('rptTableBody');
        if (!table || !tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr[data-orig-sno]'));
        if (rows.length === 0) return;

        const noMatchRow = document.getElementById('rptNoMatchRow');
        const searchInput = document.getElementById('rptTableSearch');
        const searchClear = document.getElementById('rptTableSearchClear');
        const countBadge = document.getElementById('rptTableCount');
        const toggleFiltersBtn = document.getElementById('rptToggleColFilters');
        const activeFilterBadge = document.getElementById('rptActiveFilterBadge');
        const clearAllBtn = document.getElementById('rptClearAllFilters');
        const resetTableBtn = document.getElementById('rptResetFilterTableBtn');
        const colFilterResetBtn = document.getElementById('rptColFilterReset');
        const filterRow = document.getElementById('rptFilterRow');
        const sortableHeaders = Array.from(table.querySelectorAll('thead th.is-sortable'));
        const colInputs = Array.from(table.querySelectorAll('.rpt-col-input'));
        const catDropdown = document.getElementById('rptCatDropdown');
        const catDropdownBtn = document.getElementById('rptCatDropdownBtn');
        const catSelectedLabel = document.getElementById('rptCatSelectedLabel');
        const catSelectedCount = document.getElementById('rptCatSelectedCount');
        const groupByBtn = document.getElementById('rptGroupByCategory');

        catDropdownBtn?.addEventListener('click', function (e) {
            e.stopPropagation();
            catDropdown?.classList.toggle('is-open');
        });
        document.addEventListener('click', function (e) {
            if (catDropdown && !catDropdown.contains(e.target)) {
                catDropdown.classList.remove('is-open');
            }
        });
        const footTotalLabel = document.getElementById('footTotalLabel');
        const footBomQty = document.getElementById('footBomQty');
        const footPlannedQty = document.getElementById('footPlannedQty');
        const footProductionQty = document.getElementById('footProductionQty');
        const footPoQty = document.getElementById('footPoQty');
        const footGrnQty = document.getElementById('footGrnQty');
        const footIssueQty = document.getElementById('footIssueQty');

        function parseNum(v) {
            if (!v || v === '\u2014' || v === '-' || v.trim() === '') return 0;
            const clean = v.replace(/,/g, '').trim();
            const match = clean.match(/-?\d+(?:\.\d+)?/);
            if (!match) return 0;
            const n = parseFloat(match[0]);
            return isNaN(n) ? 0 : n;
        }
        function formatQty(n) { if (n === 0) return '\u2014'; if (Math.abs(n - Math.round(n)) < 0.001) return Math.round(n).toLocaleString('en-US'); return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

        const rowData = rows.map((tr, index) => {
            const cells = Array.from(tr.children);
            const colTexts = cells.map(td => {
                const valSpan = td.querySelector('.qty-val, .bom-val, .foot-val');
                return valSpan ? valSpan.textContent.trim() : td.textContent.trim();
            });
            const colNums = colTexts.map(val => parseNum(val));
            const clean = (v) => (v && v !== '\u2014' && v !== '-') ? v : '';
            const groupCode = isTrims ? clean(colTexts[6]) : '';
            const category = isTrims ? (clean(colTexts[7]) || groupCode || 'Unassigned') : '';
            return { tr, origIndex: index, origSno: parseInt(tr.getAttribute('data-orig-sno') || (index + 1), 10),  colTexts, colNums, category, groupCode, fullSearchText: colTexts.slice(1).join(' ').toLowerCase(), visible: true };
        });

        fillMaterialGroupMenu(rowData);
        const trimsPills = Array.from(document.querySelectorAll('#rptCatDropdownMenu .rpt-cat-dd-item'));
        if (catSelectedCount) catSelectedCount.textContent = rowData.length.toLocaleString('en-US');

        let currentSort = { col: null, dir: null, type: null };
        let activeCatFilter = '';
        let groupByActive = false;

        sortableHeaders.forEach(th => { th.addEventListener('click', function () { const col = parseInt(th.getAttribute('data-col'), 10); const type = th.getAttribute('data-type') || 'text'; if (currentSort.col === col) { if (currentSort.dir === 'asc') currentSort.dir = 'desc'; else if (currentSort.dir === 'desc') currentSort = { col: null, dir: null, type: null }; } else { currentSort = { col, dir: 'asc', type }; } updateSortUI(); applySorting(); applyFilterAndSearch(); }); });

        function updateSortUI() { sortableHeaders.forEach(th => { const col = parseInt(th.getAttribute('data-col'), 10); th.classList.remove('is-sorted-asc', 'is-sorted-desc'); if (currentSort.col === col) { if (currentSort.dir === 'asc') th.classList.add('is-sorted-asc'); else if (currentSort.dir === 'desc') th.classList.add('is-sorted-desc'); } }); }

        function applySorting() {
            if (currentSort.col === null) { rowData.sort((a, b) => a.origIndex - b.origIndex); }
            else { const { col, dir, type } = currentSort; rowData.sort((a, b) => { let res = type === 'num' ? a.colNums[col] - b.colNums[col] : a.colTexts[col].localeCompare(b.colTexts[col], undefined, { numeric: true, sensitivity: 'base' }); if (res === 0) res = a.origIndex - b.origIndex; return dir === 'asc' ? res : -res; }); }
            const frag = document.createDocumentFragment();
            rowData.forEach(item => frag.appendChild(item.tr));
            if (noMatchRow) frag.appendChild(noMatchRow);
            tbody.appendChild(frag);
        }

        function parseNumCond(q) { const m = q.trim().match(/^([><]=?|=)\s*(-?\d+(?:\.\d+)?)$/); return m ? { op: m[1], val: parseFloat(m[2]) } : null; }

        function removeGroupHeaders() { Array.from(tbody.querySelectorAll('.rpt-group-header-row')).forEach(r => r.remove()); }

        function insertGroupHeaders() {
            removeGroupHeaders();
            let lastCat = null;
            const vis = rowData.filter(item => item.visible);
            vis.forEach(item => {
                const cat = item.category || 'Other';
                if (cat !== lastCat) {
                    lastCat = cat;
                    const catItems = vis.filter(r => (r.category || 'Other') === cat);
                    let bs = 0, ps = 0, pos = 0, gs = 0;
                    const catSeenMats = new Set();
                    const matColIndex = MAT_COL;
                    catItems.forEach(r => {
                        pos += r.colNums[COL.po];
                        gs += r.colNums[COL.grn];
                        const mat = r.colTexts[matColIndex] || '';
                        if (mat !== '' && !catSeenMats.has(mat)) {
                            catSeenMats.add(mat);
                            bs += r.colNums[COL.bom];
                            ps += r.colNums[COL.plan];
                        }
                    });
                    const htr = document.createElement('tr');
                    htr.className = 'rpt-group-header-row';
                    htr.setAttribute('data-group-cat', cat);
                                htr.innerHTML = `<td colspan="${TABLE_COLUMNS}"><div class="rpt-group-header-inner"><span class="rpt-group-cat-label">${escapeHtml(cat)}</span><span class="rpt-group-cat-count">${catItems.length} line${catItems.length !== 1 ? 's' : ''}</span><span class="rpt-group-subtotals">BOM&nbsp;<b>${formatQty(bs)}</b>&nbsp;&middot;&nbsp;Planned&nbsp;<b>${formatQty(ps)}</b>&nbsp;&middot;&nbsp;PO&nbsp;<b>${formatQty(pos)}</b>&nbsp;&middot;&nbsp;GRN&nbsp;<b>${formatQty(gs)}</b></span><span class="rpt-group-chevron material-icons-round">expand_less</span></div></td>`;
            htr.addEventListener('click', function () { const gc = htr.getAttribute('data-group-cat'); const ch = htr.querySelector('.rpt-group-chevron'); const collapsed = htr.classList.toggle('is-collapsed'); if (ch) ch.textContent = collapsed ? 'expand_more' : 'expand_less'; rowData.forEach(it => { if ((it.category || 'Other') === gc && it.visible) it.tr.style.display = collapsed ? 'none' : ''; }); });
            item.tr.before(htr);
            }
            });
      }

 function applyFilterAndSearch() {
 const query = (searchInput?.value || '').trim().toLowerCase();
 const colFilters = [];
 colInputs.forEach(input => {
 const col = parseInt(input.getAttribute('data-col'), 10);
 const rawVal = input.value.trim();
 const isNum = input.hasAttribute('data-numeric');
 const cb = input.parentElement?.querySelector('.rpt-col-clear');
 if (rawVal !== '') { input.classList.add('has-val'); if (cb) cb.style.display = 'block'; colFilters.push({ col, lower: rawVal.toLowerCase(), isNum, cond: isNum ? parseNumCond(rawVal) : null }); }
 else { input.classList.remove('has-val'); if (cb) cb.style.display = 'none'; }
 });
 if (searchClear) searchClear.style.display = query !== '' ? 'flex' : 'none';

        let vc = 0, sb = 0, sp = 0, spd = 0, spo = 0, sg = 0, si = 0;
        const seenMats = new Set();
        const matColIndex = MAT_COL;

        rowData.forEach(item => {
            let match = true;
            if (activeCatFilter !== '' && item.category !== activeCatFilter) match = false;
            if (match && query !== '' && !item.fullSearchText.includes(query)) match = false;
            if (match && colFilters.length > 0) {
                for (const f of colFilters) {
                    const ct = item.colTexts[f.col] || ''; const cn = item.colNums[f.col];
                    if (f.cond) { const {op, val} = f.cond; if (op === '>' && !(cn > val)) { match=false; break; } if (op === '>=' && !(cn >= val)) { match=false; break; } if (op === '<' && !(cn < val)) { match=false; break; } if (op === '<=' && !(cn <= val)) { match=false; break; } if (op === '=' && cn !== val) { match=false; break; } }
                    else { if (!ct.toLowerCase().includes(f.lower)) { match=false; break; } }
                }
            }
            item.visible = match;
            if (match) { 
                vc++; 
                item.tr.style.display = ''; 
                const sno = item.tr.querySelector('td.sno'); 
                if (sno) sno.textContent = vc; 
                
                spo += item.colNums[COL.po]; 
                sg += item.colNums[COL.grn]; 
                
                const mat = item.colTexts[matColIndex] || '';
                if (mat !== '' && !seenMats.has(mat)) {
                    seenMats.add(mat);
                    sb += item.colNums[COL.bom]; 
                    sp += item.colNums[COL.plan]; 
                    spd += item.colNums[COL.prod]; 
                    si += item.colNums[COL.issue]; 
                }
            } else { 
                item.tr.style.display = 'none'; 
            }
        });

 if (groupByActive) insertGroupHeaders(); else removeGroupHeaders();
 if (noMatchRow) noMatchRow.style.display = vc === 0 ? '' : 'none';

 const isFiltered = query !== '' || colFilters.length > 0 || activeCatFilter !== '';
 if (countBadge) { countBadge.textContent = isFiltered ? `Showing ${vc.toLocaleString()} of ${rowData.length.toLocaleString()} lines` : `Showing ${rowData.length.toLocaleString()} of ${rowData.length.toLocaleString()} lines`; countBadge.classList.toggle('is-filtered', isFiltered); }
 if (activeFilterBadge) { activeFilterBadge.style.display = colFilters.length > 0 ? 'inline-flex' : 'none'; activeFilterBadge.textContent = colFilters.length; }
 if (clearAllBtn) clearAllBtn.style.display = (isFiltered || currentSort.col !== null) ? 'inline-flex' : 'none';
        if (footTotalLabel) footTotalLabel.textContent = isFiltered ? `Total (${vc.toLocaleString()} of ${rowData.length.toLocaleString()} lines)` : `Total (${rowData.length.toLocaleString()} lines)`;
        if (footBomQty) footBomQty.innerHTML = `<span class="foot-val">${formatQty(sb)}</span>`;
        if (footPlannedQty) {
            const tr = getTrend(sp, sb);
            footPlannedQty.innerHTML = `<span class="foot-val">${formatQty(sp)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total Planned vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footProductionQty) {
            const tr = getTrend(spd, sb);
            footProductionQty.innerHTML = `<span class="foot-val">${formatQty(spd)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total Production vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footPoQty) {
            const tr = getTrend(spo, sb);
            footPoQty.innerHTML = `<span class="foot-val">${formatQty(spo)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total PO Qty vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footGrnQty) {
            const tr = getTrend(sg, sb);
            footGrnQty.innerHTML = `<span class="foot-val">${formatQty(sg)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total GRN Qty vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footIssueQty) {
            const tr = getTrend(si, sb);
            footIssueQty.innerHTML = `<span class="foot-val">${formatQty(si)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total Issue Qty vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
 // Update stat cards and charts
 updateStatCards(vc, sb, sp, spd, spo, sg, si);
 updateCharts(rowData.filter(item => item.visible), activeCatFilter);
 }

 let sd = null, cfd = null;
 searchInput?.addEventListener('input', () => { clearTimeout(sd); sd = setTimeout(applyFilterAndSearch, 80); });
 searchInput?.addEventListener('keydown', e => { if (e.key === 'Escape') { searchInput.value = ''; applyFilterAndSearch(); } else if (e.key === 'Enter') e.preventDefault(); });
 searchClear?.addEventListener('click', () => { if (searchInput) { searchInput.value = ''; searchInput.focus(); } applyFilterAndSearch(); });

 colInputs.forEach(input => {
 input.addEventListener('input', () => { clearTimeout(cfd); cfd = setTimeout(applyFilterAndSearch, 100); });
 input.addEventListener('keydown', e => { if (e.key === 'Escape') { input.value = ''; applyFilterAndSearch(); } else if (e.key === 'Enter') e.preventDefault(); });
 const cb = input.parentElement?.querySelector('.rpt-col-clear');
 cb?.addEventListener('click', e => { e.stopPropagation(); input.value = ''; input.focus(); applyFilterAndSearch(); });
 });

        const tableWrap = document.getElementById('rptTableWrap') || table.closest('.rpt-table-wrap');
        const toggleStickyBtn = document.getElementById('rptToggleStickyScroll');
        const stickyScrollLabel = document.getElementById('rptStickyScrollLabel');

        function syncHeaderHeight() {
            const headerRow = table.querySelector('thead tr.rpt-header-row');
            if (headerRow) {
                const h = headerRow.offsetHeight;
                if (h > 0) {
                    document.documentElement.style.setProperty('--rpt-header-h', h + 'px');
                }
            }
        }
        syncHeaderHeight();
        window.addEventListener('resize', syncHeaderHeight);

        function applyStickyMode(enabled) {
            if (!tableWrap) return;
            if (enabled) {
                tableWrap.classList.remove('is-expanded');
                tableWrap.classList.add('is-scroll-body');
                if (toggleStickyBtn) toggleStickyBtn.classList.add('is-active');
                if (stickyScrollLabel) stickyScrollLabel.textContent = 'Scroll Body Only';
                try { localStorage.setItem('rpt_sticky_scroll', 'true'); } catch (e) {}
            } else {
                tableWrap.classList.add('is-expanded');
                tableWrap.classList.remove('is-scroll-body');
                if (toggleStickyBtn) toggleStickyBtn.classList.remove('is-active');
                if (stickyScrollLabel) stickyScrollLabel.textContent = 'Expand Table';
                try { localStorage.setItem('rpt_sticky_scroll', 'false'); } catch (e) {}
            }
            syncHeaderHeight();
        }

        let isSticky = true;
        try {
            const saved = localStorage.getItem('rpt_sticky_scroll');
            if (saved !== null) isSticky = saved === 'true';
        } catch (e) {}
        applyStickyMode(isSticky);

        toggleStickyBtn?.addEventListener('click', function () {
            const currentActive = tableWrap ? !tableWrap.classList.contains('is-expanded') : true;
            applyStickyMode(!currentActive);
        });

        colFilterResetBtn?.addEventListener('click', () => { colInputs.forEach(i => { i.value = ''; }); applyFilterAndSearch(); });
        toggleFiltersBtn?.addEventListener('click', () => {
            if (!filterRow) return;
            const ih = filterRow.style.display === 'none';
            filterRow.style.display = ih ? '' : 'none';
            toggleFiltersBtn.classList.toggle('is-active', ih);
            syncHeaderHeight();
        });

 function clearAllFilters() {
 if (searchInput) searchInput.value = '';
 colInputs.forEach(i => { i.value = ''; });
 currentSort = { col: null, dir: null, type: null };
 activeCatFilter = '';
 updateSortUI();
 trimsPills.forEach(p => p.classList.remove('is-active'));
 const allPill = document.querySelector('.rpt-trim-pill-all');
 if (allPill) allPill.classList.add('is-active');
 if (catSelectedLabel) catSelectedLabel.innerHTML = 'Material Group: <b>All</b>';
 if (catSelectedCount) catSelectedCount.textContent = rowData.length.toLocaleString('en-US');
 applySorting();
 applyFilterAndSearch();
 }
 clearAllBtn?.addEventListener('click', clearAllFilters);
 resetTableBtn?.addEventListener('click', clearAllFilters);

 trimsPills.forEach(pill => {
 pill.addEventListener('click', () => {
 activeCatFilter = pill.getAttribute('data-cat') || '';
 trimsPills.forEach(p => p.classList.remove('is-active'));
 pill.classList.add('is-active');

 const catName = activeCatFilter !== '' ? activeCatFilter : 'All';
 if (catSelectedLabel) catSelectedLabel.innerHTML = `Material Group: <b>${escapeHtml(catName)}</b>`;

 const cntEl = pill.querySelector('.rpt-cat-dd-item-count, .rpt-trim-pill-count');
 if (catSelectedCount && cntEl) catSelectedCount.textContent = cntEl.textContent;

 catDropdown?.classList.remove('is-open');
 applyFilterAndSearch();
 });
 });

 groupByBtn?.addEventListener('click', () => {
 groupByActive = !groupByActive;
 groupByBtn.classList.toggle('is-active', groupByActive);
 if (groupByActive) {
 rowData.sort((a, b) => { const ca = a.category || 'Other'; const cb2 = b.category || 'Other'; if (ca !== cb2) return ca.localeCompare(cb2); return a.origIndex - b.origIndex; });
 const frag = document.createDocumentFragment();
 rowData.forEach(item => frag.appendChild(item.tr));
 if (noMatchRow) frag.appendChild(noMatchRow);
 tbody.appendChild(frag);
 } else { applySorting(); }
 applyFilterAndSearch();
 });

        setupColumnFeatures(table, syncHeaderHeight);
 }
})();
