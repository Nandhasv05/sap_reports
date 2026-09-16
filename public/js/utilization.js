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

    const COL = isTrims
        ? { bom: 6, plan: 7, prod: 8, po: 9, grn: 10, issue: 11 }
        : { bom: 5, plan: 6, prod: 7, po: 8, grn: 9, issue: 10 };

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
            : (Math.abs(n - Math.round(n)) < 0.001 ? Math.round(n).toLocaleString('en-US') : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

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
        const matColIndex = isTrims ? 3 : 2;

        visibleRows.forEach(r => {
            const key = useCategory ? (r.category || 'Other') : (r.colTexts[matColIndex] || 'Unknown');
            byKey[key] = (byKey[key] || 0) + r.colNums[COL.bom];
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

    initDataTable();

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
        const trimsPills = Array.from(document.querySelectorAll('.rpt-cat-dd-item, .rpt-trim-pill'));
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
            return { tr, origIndex: index, origSno: parseInt(tr.getAttribute('data-orig-sno') || (index + 1), 10), category: tr.getAttribute('data-category') || '', colTexts, colNums, fullSearchText: colTexts.slice(1).join(' ').toLowerCase(), visible: true };
        });

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
                    const bs = catItems.reduce((s, r) => s + r.colNums[COL.bom], 0);
                    const ps = catItems.reduce((s, r) => s + r.colNums[COL.plan], 0);
                    const pos = catItems.reduce((s, r) => s + r.colNums[COL.po], 0);
                    const gs = catItems.reduce((s, r) => s + r.colNums[COL.grn], 0);
                    const htr = document.createElement('tr');
                    htr.className = 'rpt-group-header-row';
                    htr.setAttribute('data-group-cat', cat);
                    htr.innerHTML = `<td colspan="${isTrims ? 13 : 12}"><div class="rpt-group-header-inner"><span class="rpt-group-cat-label">${cat}</span><span class="rpt-group-cat-count">${catItems.length} line${catItems.length !== 1 ? 's' : ''}</span><span class="rpt-group-subtotals">BOM&nbsp;<b>${formatQty(bs)}</b>&nbsp;&middot;&nbsp;Planned&nbsp;<b>${formatQty(ps)}</b>&nbsp;&middot;&nbsp;PO&nbsp;<b>${formatQty(pos)}</b>&nbsp;&middot;&nbsp;GRN&nbsp;<b>${formatQty(gs)}</b></span><span class="rpt-group-chevron material-icons-round">expand_less</span></div></td>`;
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
 if (match) { vc++; item.tr.style.display = ''; const sno = item.tr.firstElementChild; if (sno) sno.textContent = vc; sb += item.colNums[COL.bom]; sp += item.colNums[COL.plan]; spd += item.colNums[COL.prod]; spo += item.colNums[COL.po]; sg += item.colNums[COL.grn]; si += item.colNums[COL.issue]; }
 else { item.tr.style.display = 'none'; }
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
 if (catSelectedLabel) catSelectedLabel.innerHTML = 'Category: <b>All</b>';
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
 if (catSelectedLabel) catSelectedLabel.innerHTML = `Category: <b>${catName}</b>`;

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
 }
})();
