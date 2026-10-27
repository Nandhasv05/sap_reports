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
        ? { bom: 13, totalBom: 14, plan: 15, prod: 16, po: 17, grn: 18, issue: 19, sos: 20 }
        : { bom: 9, totalBom: 10, plan: 11, prod: 12, po: 13, grn: 14, issue: 15, sos: 16 };
    const TABLE_COLUMNS = isTrims ? 24 : 21;
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
            totalsChart = new Chart(totalsEl, {
                type: 'bar',
                data: {
                    labels: data.totals_labels || [],
                    datasets: [{
                        label: 'Quantity',
                        data: data.totals || [],
                        backgroundColor: ['#0f766e','#0284c7','#16a34a','#d97706','#7c3aed','#e11d48'],
                        borderRadius: 8,
                        barPercentage: 0.62,
                        categoryPercentage: 0.78,
                        maxBarThickness: 56,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600 },
                    layout: { padding: { top: 4, right: 8, bottom: 0, left: 4 } },
                    plugins: { legend: { display: false } },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { maxRotation: 0, autoSkip: false, padding: 6 },
                        },
                        y: { beginAtZero: true, grace: '4%' },
                    },
                },
            });
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
    bindDateRangeFilter(pageCfg);
    initOrdersListTable(pageCfg);

    function readPageConfig() {
        const node = document.getElementById('rptPageConfig');
        if (!node) return {};
        try { return JSON.parse(node.textContent || '{}'); } catch (e) { return {}; }
    }

    function bindDateRangeFilter(cfg) {
        const presetDates = cfg.presetDates || {};
        document.querySelectorAll('[data-date-filter]').forEach((form) => {
            const select = form.querySelector('[data-range-select]');
            const customBlocks = form.querySelectorAll('[data-date-custom]');
            const fromInput = form.querySelector('input[name="from"]');
            const toInput = form.querySelector('input[name="to"]');
            const soInput = form.querySelector('input[name="so"]');

            if (!select) return;

            function updateState(key) {
                const isCustom = key === 'custom';
                customBlocks.forEach((el) => { el.hidden = !isCustom; });
                if (!isCustom && presetDates[key]) {
                    if (fromInput) fromInput.value = presetDates[key].from || '';
                    if (toInput) toInput.value = presetDates[key].to || '';
                } else if (!isCustom && /^\d{4}-\d{2}-\d{2}$/.test(key)) {
                    if (fromInput) fromInput.value = key;
                    if (toInput) toInput.value = key;
                } else if (key === '') {
                    if (fromInput) fromInput.value = '';
                    if (toInput) toInput.value = '';
                }
                if (isCustom && fromInput) {
                    fromInput.focus();
                }
            }

            select.addEventListener('change', () => {
                updateState(select.value);
            });

            form.addEventListener('submit', (e) => {
                const soVal = (soInput?.value || '').trim();
                const rangeVal = select.value;
                if (soVal) {
                    select.disabled = true;
                    customBlocks.forEach((el) => el.querySelectorAll('input').forEach((input) => { input.disabled = true; }));
                    return;
                }
                if (!rangeVal) {
                    e.preventDefault();
                    select.focus();
                    return;
                }
                if (rangeVal === 'custom') {
                    const fromVal = fromInput?.value || '';
                    const toVal = toInput?.value || '';
                    if (!fromVal || !toVal) {
                        e.preventDefault();
                        fromInput?.focus();
                        return;
                    }
                    if (toVal < fromVal) {
                        e.preventDefault();
                        return;
                    }
                    const max = new Date(fromVal + 'T00:00:00');
                    max.setMonth(max.getMonth() + 6);
                    const end = new Date(toVal + 'T00:00:00');
                    if (end > max) {
                        e.preventDefault();
                        window.alert('A custom date range can cover at most 6 months.');
                        return;
                    }
                }
            });
            [fromInput, toInput].forEach((input) => {
                input?.addEventListener('change', () => {
                    if (!form.classList.contains('rpt-search') || select.value !== 'custom') return;
                    if (!fromInput?.value || !toInput?.value) return;
                    if (soInput) soInput.value = '';
                    if (form.requestSubmit) form.requestSubmit();
                    else form.submit();
                });
            });
            select.addEventListener('change', () => {
                if (select.value && select.value !== 'custom' && soInput && form.classList.contains('rpt-search')) {
                    soInput.value = '';
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            });
        });
    }

    function initOrdersListTable(cfg) {
        const table = document.getElementById('soOrdersTable');
        if (!table) return;

        const tableWrap = document.getElementById('ordersTableWrap');
        const searchInput = document.getElementById('ordersTableSearch');
        const searchClear = document.getElementById('ordersSearchClear');
        const countBadge = document.getElementById('ordersTableCount');
        const stickyScrollBtn = document.getElementById('ordersToggleStickyScroll');
        const colFilterBtn = document.getElementById('ordersBtnColFilter');
        const colFilterBadge = document.getElementById('ordersActiveFilterBadge');
        const clearAllBtn = document.getElementById('ordersClearAllFilters');
        const clearColFiltersBtn = document.getElementById('ordersBtnClearColFilters');
        const colToggleBtn = document.getElementById('ordersColumnToggleBtn');
        const colToggleDropdown = document.getElementById('ordersColumnToggleDropdown');
        const colToggleMenu = document.getElementById('ordersColumnToggleMenu');
        const canvas = document.getElementById('soCanvas');
        const frame = document.getElementById('soCanvasFrame');
        const title = document.getElementById('soCanvasTitle');

        const allRows = Array.from(table.querySelectorAll('tbody tr[data-so]'));
        const totalRows = allRows.length;
        const colFilterInputs = Array.from(table.querySelectorAll('.col-filter-input'));

        let currentSort = { col: -1, dir: 'asc' };

        // 1. Quick Global Search & Column Filtering
        function evaluateNumericFilter(valueStr, filterStr) {
            const num = parseFloat(String(valueStr).replace(/[^\d.-]/g, ''));
            if (isNaN(num)) return false;
            const trimmed = filterStr.trim();
            const matchComp = trimmed.match(/^([><]=?|=)\s*([-\d.]+)/);
            if (matchComp) {
                const op = matchComp[1];
                const target = parseFloat(matchComp[2]);
                if (op === '>') return num > target;
                if (op === '>=') return num >= target;
                if (op === '<') return num < target;
                if (op === '<=') return num <= target;
                if (op === '=') return num === target;
            }
            return String(num).includes(trimmed) || String(valueStr).toLowerCase().includes(trimmed.toLowerCase());
        }

        function applyFilters() {
            const query = (searchInput?.value || '').trim().toLowerCase();
            const activeColMap = {};
            let activeColCount = 0;

            colFilterInputs.forEach((input) => {
                const val = input.value.trim();
                const colIdx = parseInt(input.getAttribute('data-col'), 10);
                const clearBtn = input.parentElement?.querySelector('.rpt-col-clear');
                if (val) {
                    activeColMap[colIdx] = val;
                    activeColCount++;
                    input.classList.add('has-val');
                    if (clearBtn) clearBtn.style.display = 'block';
                } else {
                    input.classList.remove('has-val');
                    if (clearBtn) clearBtn.style.display = 'none';
                }
            });

            if (colFilterBadge) {
                if (activeColCount > 0) {
                    colFilterBadge.textContent = String(activeColCount);
                    colFilterBadge.style.display = 'inline-flex';
                } else {
                    colFilterBadge.style.display = 'none';
                }
            }

            if (searchClear) {
                searchClear.style.display = query ? 'block' : 'none';
            }

            let visibleCount = 0;
            allRows.forEach((row) => {
                let matches = true;

                // Check global query across text
                if (query) {
                    const rowText = row.textContent.toLowerCase();
                    if (!rowText.includes(query)) matches = false;
                }

                // Check each active column filter
                if (matches && activeColCount > 0) {
                    for (const [colIdxStr, filterVal] of Object.entries(activeColMap)) {
                        const colIdx = parseInt(colIdxStr, 10);
                        const cell = row.querySelector(`td[data-col="${colIdx}"]`);
                        const cellText = (cell ? cell.textContent : '').trim();

                        if (colIdx === 6 || colIdx === 7) { // Lines or Quantity (numeric)
                            if (!evaluateNumericFilter(cellText, filterVal)) {
                                matches = false;
                                break;
                            }
                        } else {
                            if (!cellText.toLowerCase().includes(filterVal.toLowerCase())) {
                                matches = false;
                                break;
                            }
                        }
                    }
                }

                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            // Renumber S.No for visible rows
            let sNo = 1;
            allRows.forEach((row) => {
                if (row.style.display !== 'none') {
                    const snoCell = row.querySelector('td.col-sno');
                    if (snoCell) snoCell.textContent = String(sNo++);
                }
            });

            // Update count badge & clear all button
            if (countBadge) {
                countBadge.textContent = `Showing ${visibleCount} of ${totalRows} orders`;
                if (visibleCount < totalRows || query || activeColCount > 0) {
                    countBadge.classList.add('is-filtered');
                    if (clearAllBtn) clearAllBtn.style.display = 'inline-flex';
                } else {
                    countBadge.classList.remove('is-filtered');
                    if (clearAllBtn) clearAllBtn.style.display = 'none';
                }
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
        if (searchClear) {
            searchClear.addEventListener('click', () => {
                searchInput.value = '';
                applyFilters();
                searchInput.focus();
            });
        }

        colFilterInputs.forEach((input) => {
            input.addEventListener('input', applyFilters);
            const clearBtn = input.parentElement?.querySelector('.rpt-col-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    input.value = '';
                    applyFilters();
                    input.focus();
                });
            }
        });

        if (clearColFiltersBtn) {
            clearColFiltersBtn.addEventListener('click', () => {
                colFilterInputs.forEach((input) => { input.value = ''; });
                applyFilters();
            });
        }

        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', () => {
                if (searchInput) searchInput.value = '';
                colFilterInputs.forEach((input) => { input.value = ''; });
                applyFilters();
            });
        }

        // 2. Toggle Column Filters Row
        if (colFilterBtn) {
            colFilterBtn.addEventListener('click', () => {
                const isOpen = table.classList.toggle('show-col-filters');
                colFilterBtn.classList.toggle('is-active', isOpen);
                colFilterBtn.setAttribute('aria-pressed', isOpen ? 'true' : 'false');
            });
        }

        // 3. Scroll Body Only Toggle
        if (stickyScrollBtn && tableWrap) {
            stickyScrollBtn.addEventListener('click', () => {
                const isSticky = tableWrap.classList.toggle('is-scroll-body');
                stickyScrollBtn.classList.toggle('is-active', isSticky);
                const label = document.getElementById('ordersStickyScrollLabel');
                if (label) label.textContent = isSticky ? 'Scroll Body Only' : 'Expand Full Table';
            });
        }

        // 4. Columns Dropdown Toggle & Column Checkboxes
        const columnDefinitions = [
            { id: 0, label: 'S.No' },
            { id: 1, label: 'Sales Order' },
            { id: 2, label: 'Customer' },
            { id: 3, label: 'Material / Style' },
            { id: 4, label: 'Creation Date' },
            { id: 5, label: 'Plant' },
            { id: 6, label: 'Lines' },
            { id: 7, label: 'Quantity' }
        ];

        if (colToggleMenu) {
            colToggleMenu.innerHTML = '';
            const head = document.createElement('div');
            head.className = 'sales-col-menu-head';
            head.textContent = 'Show columns';
            colToggleMenu.appendChild(head);
            columnDefinitions.forEach((col) => {
                const item = document.createElement('label');
                item.className = 'sales-col-menu-item';
                item.setAttribute('role', 'menuitemcheckbox');
                item.setAttribute('aria-checked', 'true');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.setAttribute('data-toggle-col', String(col.id));
                checkbox.checked = true;
                const text = document.createElement('span');
                text.textContent = col.label;
                item.appendChild(checkbox);
                item.appendChild(text);
                checkbox.addEventListener('change', () => {
                    const isVisible = checkbox.checked;
                    item.setAttribute('aria-checked', isVisible ? 'true' : 'false');
                    table.querySelectorAll(`th[data-col="${col.id}"], td[data-col="${col.id}"]`).forEach((cell) => {
                        cell.style.display = isVisible ? '' : 'none';
                    });
                });
                colToggleMenu.appendChild(item);
            });
        }

        if (colToggleBtn && colToggleDropdown) {
            colToggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = colToggleDropdown.classList.toggle('is-open');
                colToggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            document.addEventListener('click', (e) => {
                if (!colToggleDropdown.contains(e.target)) {
                    colToggleDropdown.classList.remove('is-open');
                    colToggleBtn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // 5. Column Header Sorting
        const sortableHeaders = Array.from(table.querySelectorAll('th.col-sortable'));
        sortableHeaders.forEach((th) => {
            th.addEventListener('click', () => {
                const colIdx = parseInt(th.getAttribute('data-col'), 10);
                if (isNaN(colIdx)) return;

                const isAsc = currentSort.col === colIdx && currentSort.dir === 'asc';
                currentSort = { col: colIdx, dir: isAsc ? 'desc' : 'asc' };

                // Update sort arrows on headers
                sortableHeaders.forEach((h) => {
                    const span = h.querySelector('.th-content span');
                    if (span) {
                        span.textContent = span.textContent.replace(/ [▲▼]$/, '');
                        if (h === th) {
                            span.textContent += currentSort.dir === 'asc' ? ' ▲' : ' ▼';
                        }
                    }
                });

                const tbody = table.querySelector('tbody');
                const isNumericCol = colIdx === 6 || colIdx === 7;

                allRows.sort((a, b) => {
                    const aCell = a.querySelector(`td[data-col="${colIdx}"]`);
                    const bCell = b.querySelector(`td[data-col="${colIdx}"]`);
                    const aText = (aCell ? aCell.textContent : '').trim();
                    const bText = (bCell ? bCell.textContent : '').trim();

                    if (isNumericCol) {
                        const aNum = parseFloat(aText.replace(/[^\d.-]/g, '')) || 0;
                        const bNum = parseFloat(bText.replace(/[^\d.-]/g, '')) || 0;
                        return currentSort.dir === 'asc' ? aNum - bNum : bNum - aNum;
                    }
                    return currentSort.dir === 'asc'
                        ? aText.localeCompare(bText, undefined, { numeric: true, sensitivity: 'base' })
                        : bText.localeCompare(aText, undefined, { numeric: true, sensitivity: 'base' });
                });

                allRows.forEach((row) => tbody.appendChild(row));
                applyFilters();
            });
        });

        // 6. 90% Screen Offcanvas Canvas for Fabric Utilization
        function closeCanvas() {
            if (canvas) canvas.hidden = true;
            if (frame) frame.removeAttribute('src');
            document.body.classList.remove('so-canvas-open');
        }

        function openFabric(so) {
            if (!so || !canvas || !frame) return;
            if (title) title.textContent = `Sales Order: ${so}`;
            const baseUrl = cfg.pageUrl || window.location.pathname;
            const sep = baseUrl.includes('?') ? '&' : '?';
            const modeParam = cfg.mode ? `&mode=${encodeURIComponent(cfg.mode)}` : '';
            const fullLink = document.getElementById('soCanvasFull');
            if (fullLink) {
                const modeQs = cfg.mode ? `&mode=${encodeURIComponent(cfg.mode)}` : '';
                fullLink.href = `${baseUrl}${sep}so=${encodeURIComponent(so)}${modeQs}`;
                fullLink.hidden = false;
            }
            frame.src = `${baseUrl}${sep}so=${encodeURIComponent(so)}&embedded=1${modeParam}`;
            canvas.hidden = false;
            document.body.classList.add('so-canvas-open');
        }

        if (canvas) {
            canvas.querySelectorAll('[data-so-close]').forEach((el) => {
                el.addEventListener('click', closeCanvas);
            });
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && canvas && !canvas.hidden) {
                closeCanvas();
            }
        });

        allRows.forEach((row) => {
            const so = row.getAttribute('data-so');
            const trigger = () => openFabric(so);
            row.addEventListener('click', (e) => {
                // Don't trigger if clicked on an interactive element
                if (e.target.closest('input, button, a')) return;
                trigger();
            });
            row.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    trigger();
                }
            });
        });
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
        if (Math.abs(num - Math.round(num)) < 0.0005) return Number(num).toFixed(1);
        return Number(num.toFixed(3)).toString();
    }

    function footTotal(n) {
        const v = Number(n) || 0;
        return Math.abs(v) < 0.0000001 ? '—' : String(Math.round(v) || 0);
    }

    function displayCode(n) {
        const text = String(n ?? '').trim();
        return (text === '' || text === '-') ? '—' : text;
    }

    function formatUnit(unit) {
        if (!unit || unit === '-' || unit === '—') return '';
        return String(unit).trim();
    }

    function initQtyHoverCard() {
        let card = document.getElementById('rptQtyHoverCard');
        if (!card) {
            card = document.createElement('div');
            card.id = 'rptQtyHoverCard';
            card.className = 'qty-hover-card';
            card.setAttribute('role', 'tooltip');
            card.innerHTML = '<div class="qty-hover-arrow"></div><div class="qty-hover-card-content"></div>';
            document.body.appendChild(card);
        }

        let activeCell = null;

        function buildCardHtml(cell) {
            const type = cell.getAttribute('data-hover-type');
            const qty = cell.getAttribute('data-qty') || '—';
            const unit = cell.getAttribute('data-unit') || '';
            const convQty = cell.getAttribute('data-conv-qty') || '';
            const convUnit = cell.getAttribute('data-conv-unit') || '';

            let icon = 'fas fa-circle-info';
            let title = 'Quantity Details';
            let rowsHtml = '';

            if (type === 'so') {
                icon = 'fas fa-file-invoice';
                title = 'Sales Order Quantity';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">SO_QTY:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)}</span>
                    </div>
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">SO_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit || '—')}</span></span>
                    </div>
                `;
            } else if (type === 'bom') {
                icon = 'fas fa-layer-group';
                title = 'BOM Quantity';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">BOM_QTY:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)}</span>
                    </div>
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">BOM_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit || '—')}</span></span>
                    </div>
                `;
            } else if (type === 'total_bom') {
                icon = 'fas fa-calculator';
                title = 'Total Add SO BOM Qty';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">Total BOM:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)}</span>
                    </div>
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">BOM_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit || '—')}</span></span>
                    </div>
                `;
            } else if (type === 'plan') {
                icon = 'fas fa-calendar-check';
                title = 'Planned Quantity';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">Planned:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)}</span>
                    </div>
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">BOM_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit || '—')}</span></span>
                    </div>
                `;
            } else if (type === 'prod') {
                icon = 'fas fa-industry';
                title = 'Production Quantity';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">Production:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)}</span>
                    </div>
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">BOM_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit || '—')}</span></span>
                    </div>
                `;
            } else if (type === 'po') {
                icon = 'fas fa-cart-shopping';
                title = 'Purchase Order Quantity';
                const hasConv = convQty && convQty !== '—' && convQty !== '-' && convQty !== '0';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">PO_QTY:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)} ${unit ? `<span class="qty-hover-tag">${escapeHtml(unit)}</span>` : ''}</span>
                    </div>
                    ${unit ? `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">PO_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit)}</span></span>
                    </div>
                    ` : ''}
                    ${hasConv ? `
                    <div class="qty-hover-divider"></div>
                    <div class="qty-hover-row is-conv">
                        <span class="qty-hover-lbl">PO_Conv_QTY:</span>
                        <span class="qty-hover-val"><b>${escapeHtml(convQty)}</b> <span class="qty-hover-tag">${escapeHtml(convUnit || unit || '—')}</span></span>
                    </div>
                    <div class="qty-hover-row is-conv">
                        <span class="qty-hover-lbl">PO_Conv_Unit:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(convUnit || unit || '—')}</span></span>
                    </div>
                    ` : (convUnit ? `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">PO_Conv_Unit:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(convUnit)}</span></span>
                    </div>
                    ` : '')}
                `;
            } else if (type === 'grn') {
                icon = 'fas fa-boxes-stacked';
                title = 'GRN Quantity';
                const hasConv = convQty && convQty !== '—' && convQty !== '-' && convQty !== '0';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">GRN_QTY:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)}</span>
                    </div>
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">GRN_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit || '—')}</span></span>
                    </div>
                    ${hasConv ? `
                    <div class="qty-hover-divider"></div>
                    <div class="qty-hover-row is-conv">
                        <span class="qty-hover-lbl">GRN_Conv_QTY:</span>
                        <span class="qty-hover-val"><b>${escapeHtml(convQty)}</b> <span class="qty-hover-tag">${escapeHtml(convUnit || unit || '—')}</span></span>
                    </div>
                    <div class="qty-hover-row is-conv">
                        <span class="qty-hover-lbl">GRN_Conv_Unit:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(convUnit || unit || '—')}</span></span>
                    </div>
                    ` : (convUnit ? `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">GRN_Conv_Unit:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(convUnit)}</span></span>
                    </div>
                    ` : '')}
                `;
            } else if (type === 'issue') {
                icon = 'fas fa-share-from-square';
                title = 'Issue Quantity';
                rowsHtml = `
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">Issue Qty:</span>
                        <span class="qty-hover-val">${escapeHtml(qty)}</span>
                    </div>
                    <div class="qty-hover-row">
                        <span class="qty-hover-lbl">GI_UNIT:</span>
                        <span class="qty-hover-val"><span class="qty-hover-tag">${escapeHtml(unit || '—')}</span></span>
                    </div>
                `;
            } else {
                return '';
            }

            return `
                <div class="qty-hover-card-hdr">
                    <i class="${icon}"></i>
                    <span>${escapeHtml(title)}</span>
                </div>
                <div class="qty-hover-card-body">
                    ${rowsHtml}
                </div>
            `;
        }

        function positionCard(cell) {
            const content = card.querySelector('.qty-hover-card-content');
            const html = buildCardHtml(cell);
            if (!html) {
                hideCard();
                return;
            }
            content.innerHTML = html;

            card.style.display = 'block';
            card.style.visibility = 'hidden';
            const cardRect = card.getBoundingClientRect();
            const rect = cell.getBoundingClientRect();

            let top = rect.top - cardRect.height - 8;
            let placeBelow = false;
            if (top < 10) {
                top = rect.bottom + 8;
                placeBelow = true;
            }

            let left = rect.left + (rect.width / 2) - (cardRect.width / 2);
            if (left < 10) left = 10;
            if (left + cardRect.width > window.innerWidth - 10) {
                left = window.innerWidth - cardRect.width - 10;
            }

            card.classList.toggle('pos-bottom', placeBelow);
            card.classList.toggle('pos-top', !placeBelow);
            card.style.top = `${Math.round(top)}px`;
            card.style.left = `${Math.round(left)}px`;
            card.style.visibility = 'visible';
            card.classList.add('is-visible');

            const arrow = card.querySelector('.qty-hover-arrow');
            if (arrow) {
                const arrowLeft = rect.left + (rect.width / 2) - left - 5;
                arrow.style.left = `${Math.round(Math.max(14, Math.min(cardRect.width - 24, arrowLeft)))}px`;
            }
        }

        function hideCard() {
            activeCell = null;
            card.classList.remove('is-visible');
            card.style.display = 'none';
        }

        document.addEventListener('mouseover', function (e) {
            const cell = e.target.closest('td[data-hover-type]');
            if (!cell) return;
            if (cell === activeCell) return;
            activeCell = cell;
            positionCard(cell);
        });

        document.addEventListener('mouseout', function (e) {
            const cell = e.target.closest('td[data-hover-type]');
            if (!cell) return;
            const related = e.relatedTarget;
            if (related && cell.contains(related)) return;
            if (cell === activeCell) {
                hideCard();
            }
        });

        window.addEventListener('scroll', hideCard, { passive: true });
        document.addEventListener('scroll', hideCard, { passive: true, capture: true });
    }
    initQtyHoverCard();


    // [{ so, qty }] — qty is null when SAP sends a plain SO number without "-quantity".
    function soListFromRow(row) {
        if (Array.isArray(row.grn_so_qty) && row.grn_so_qty.length) return row.grn_so_qty;
        if (Array.isArray(row.grn_so_list) && row.grn_so_list.length) return row.grn_so_list.map(so => ({ so, qty: null }));
        if (row.grn_sales_orders) {
            return String(row.grn_sales_orders).replace(/ /g, '').split(',').map(s => s.trim()).filter(Boolean).map(so => ({ so, qty: null }));
        }
        return [];
    }

    function setNoDataVisible(visible, so) {
        const panel = document.getElementById('rptNoData');
        const body = document.getElementById('rptReportBody');
        const soEl = document.getElementById('rptNoDataSo');
        if (soEl && so) soEl.textContent = String(so);
        if (panel) panel.hidden = !visible;
        if (body) body.hidden = !!visible;
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
            setNoDataVisible(true, cfg.so || '');
            return;
        }

        setNoDataVisible(false);

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
                    const clean = String(item.so ?? '').replace(/,/g, '').trim();
                    if (!clean) return '';
                    const qtyText = item.qty === null || item.qty === undefined ? '' : dashCell(item.qty).replace('—', '-');
                    const exportText = qtyText ? `${clean} (${qtyText})` : clean;
                    const title = `Direct API call for Sales Order ${clean}` + (qtyText ? ` · Qty ${qtyText}` : '');
                    const qtyBadge = qtyText ? `<span class="so-chip-qty">${escapeHtml(qtyText)}</span>` : '';
                    return `<a href="${escapeHtml(pageUrl)}?so=${encodeURIComponent(clean)}" class="so-chip" data-export="${escapeHtml(exportText)}" title="${escapeHtml(title)}">${escapeHtml(clean)}${qtyBadge}</a>`;
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
            const poNum = displayCode(row.purchase_order).replace(/,/g, '').trim();
            const poItem = displayCode(row.po_item).replace(/,/g, '').trim();
            const poHtml = /^\d{1,10}$/.test(poNum)
                ? `<button type="button" class="po-link" data-po-open="${escapeHtml(poNum)}" data-po-item="${escapeHtml(poItem)}" title="Show purchase order ${escapeHtml(poNum)} details">${escapeHtml(poNum)}</button>`
                : escapeHtml(poNum);
            const matInfoHtml = isFabric ? '' : [row.mat_type, row.mat_type_desc, row.mat_group, row.mat_group_desc, row.season, row.season_year].map(textCell).join('');
            const fabricSeasonHtml = isFabric ? [row.season, row.season_year].map(textCell).join('') : '';
            const attrHtml = isFabric ? [row.attribute1_text, row.attribute2_text, row.attribute3_text, row.colour].map(textCell).join('') : '';
            const trimMetaHtml = isTrims ? [row.colour, row.size1, row.size2].map(textCell).join('') : '';

            const soUnit = formatUnit(row.so_unit) || formatUnit(row.bom_unit);
            const bomUnit = formatUnit(row.bom_unit);
            const poUnit = formatUnit(row.po_unit) || bomUnit;
            const grnUnit = formatUnit(row.grn_unit) || bomUnit;
            const giUnit = formatUnit(row.gi_unit) || bomUnit;
            const poConvQty = parseFloat(String(row.po_conv_qty ?? 0).replace(/,/g, '')) || 0;
            const poConvUnit = formatUnit(row.po_conv_unit);
            const grnConvQty = parseFloat(String(row.grn_conv_qty ?? 0).replace(/,/g, '')) || 0;
            const grnConvUnit = formatUnit(row.grn_conv_unit);

            const soTitle = soUnit ? `SO_UNIT: ${soUnit}` : '';
            const bomTitle = bomUnit ? `BOM_UNIT: ${bomUnit}` : '';

            const poTitleParts = [];
            if (dashCell(poConvQty) !== '—') poTitleParts.push(`PO_Conv_QTY: ${dashCell(poConvQty)}`);
            if (poConvUnit) poTitleParts.push(`PO_Conv_Unit: ${poConvUnit}`);
            else if (poUnit) poTitleParts.push(`PO_Conv_Unit: ${poUnit}`);
            if (poUnit) poTitleParts.push(`PO_UNIT: ${poUnit}`);
            const poTitle = poTitleParts.join('  ');

            const grnTitleParts = [];
            if (grnUnit) grnTitleParts.push(`GRN_UNIT: ${grnUnit}`);
            if (dashCell(grnConvQty) !== '—') grnTitleParts.push(`GRN_Conv_QTY: ${dashCell(grnConvQty)}`);
            if (grnConvUnit) grnTitleParts.push(`GRN_Conv_Unit: ${grnConvUnit}`);
            const grnTitle = grnTitleParts.join('  ');

            const giTitle = giUnit ? `GI_UNIT: ${giUnit}` : '';

            const soCell = `<td class="num qty-cell" data-qty-type="so" data-hover-type="so" data-qty="${escapeHtml(dashCell(row.so_qty))}" data-unit="${escapeHtml(soUnit)}"><span class="qty-val">${escapeHtml(dashCell(row.so_qty))}</span></td>`;
            const bomCell = `<td class="num bom-qty-cell" data-hover-type="bom" data-qty="${escapeHtml(dashCell(row.bom_qty))}" data-unit="${escapeHtml(bomUnit)}"><span class="bom-val">${escapeHtml(dashCell(row.bom_qty))}</span></td>`;
            const totalBomCell = `<td class="num bom-qty-cell total-bom-cell" data-hover-type="total_bom" data-qty="${escapeHtml(dashCell(row.total_bom_qty))}" data-unit="${escapeHtml(bomUnit)}"><span class="bom-val">${escapeHtml(dashCell(row.total_bom_qty))}</span></td>`;
            const planCell = `<td class="num qty-cell" data-qty-type="plan" data-hover-type="plan" data-qty="${escapeHtml(dashCell(row.planned_qty))}" data-unit="${escapeHtml(bomUnit)}"><span class="qty-val">${escapeHtml(dashCell(row.planned_qty))}</span></td>`;
            const prodCell = `<td class="num qty-cell" data-qty-type="prod" data-hover-type="prod" data-qty="${escapeHtml(dashCell(row.production_qty))}" data-unit="${escapeHtml(bomUnit)}"><span class="qty-val">${escapeHtml(dashCell(row.production_qty))}</span></td>`;
            const poCell = `<td class="num qty-cell" data-qty-type="po" data-hover-type="po" data-qty="${escapeHtml(dashCell(row.po_qty))}" data-unit="${escapeHtml(poUnit)}" data-conv-qty="${escapeHtml(dashCell(poConvQty))}" data-conv-unit="${escapeHtml(poConvUnit)}" data-base-unit="${escapeHtml(bomUnit)}"><span class="qty-val">${escapeHtml(dashCell(row.po_qty))}</span></td>`;
            const grnCell = `<td class="num qty-cell" data-qty-type="grn" data-hover-type="grn" data-qty="${escapeHtml(dashCell(row.grn_qty))}" data-unit="${escapeHtml(grnUnit)}" data-conv-qty="${escapeHtml(dashCell(grnConvQty))}" data-conv-unit="${escapeHtml(grnConvUnit)}" data-base-unit="${escapeHtml(bomUnit)}"><span class="qty-val">${escapeHtml(dashCell(row.grn_qty))}</span></td>`;
            const issueCell = `<td class="num qty-cell" data-qty-type="issue" data-hover-type="issue" data-qty="${escapeHtml(dashCell(row.issue_qty))}" data-unit="${escapeHtml(giUnit)}"><span class="qty-val">${escapeHtml(dashCell(row.issue_qty))}</span></td>`;

            // Cell order must match the <thead> in app/views/reports/material.php.
            tr.innerHTML = `
                <td class="num sno">${i + 1}</td>
                <td>${soHtml}</td>
                <td class="rpt-mat" title="${escapeHtml(displayCode(row.material))}">${escapeHtml(displayCode(row.material))}</td>
                ${textCell(row.description)}
                ${isFabric ? fabricSeasonHtml : matInfoHtml}
                <td>${poHtml}</td>
                <td>${escapeHtml(poItem)}</td>
                ${soCell}
                ${bomCell}
                ${totalBomCell}
                ${planCell}
                ${prodCell}
                ${poCell}
                ${grnCell}
                ${issueCell}
                <td class="grn-sos">${extraSo}</td>
                ${isTrims ? trimMetaHtml : attrHtml}
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
            if (span) span.textContent = footTotal(val);
        };
        const label = document.getElementById('footTotalLabel');
        if (label) label.textContent = `Total (${lines.toLocaleString('en-US')} lines)`;
        setFoot('footBomQty', bom);
        setFoot('footTotalBomQty', Number(s.total_bom_qty || 0));
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

    function loadLiveSapData(cfg) {
        showSpinner();
        const params = new URLSearchParams({
            so: cfg.so || '',
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
                const records = data.records || [];
                renderLiveRows(cfg, records);
                if (!records.length) return;
                applyLiveSummary(data.summary || {});
                if (dataNode) dataNode.textContent = JSON.stringify(data.chart || {});
                chartsDrawn = false;
                if (totalsChart) { totalsChart.destroy(); totalsChart = null; }
                if (mixChart) { mixChart.destroy(); mixChart = null; }
                drawCharts();
                initDataTable();
            })
            .catch((err) => {
                setNoDataVisible(true, cfg.so || '');
                const lede = document.querySelector('#rptNoData .rpt-nodata-lede');
                if (lede) {
                    lede.textContent = err.message || 'Unable to load SAP data.';
                }
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
        const footTotalBomQty = document.getElementById('footTotalBomQty');
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

        let vc = 0, sb = 0, stb = 0, sp = 0, spd = 0, spo = 0, sg = 0, si = 0;
        const seenMats = new Set();
        const seenTotalBom = new Set();
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
                if (COL.totalBom !== null) {
                    // Lines with the same material and GRN sales orders repeat the same Total BOM Qty.
                    const totalBomKey = mat + '|' + (item.colTexts[COL.sos] || '').replace(/\s+/g, ' ');
                    if (!seenTotalBom.has(totalBomKey)) {
                        seenTotalBom.add(totalBomKey);
                        stb += item.colNums[COL.totalBom];
                    }
                }
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
        if (footBomQty) footBomQty.innerHTML = `<span class="foot-val">${footTotal(sb)}</span>`;
        if (footTotalBomQty) footTotalBomQty.innerHTML = `<span class="foot-val">${footTotal(stb)}</span>`;
        if (footPlannedQty) {
            const tr = getTrend(sp, sb);
            footPlannedQty.innerHTML = `<span class="foot-val">${footTotal(sp)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total Planned vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footProductionQty) {
            const tr = getTrend(spd, sb);
            footProductionQty.innerHTML = `<span class="foot-val">${footTotal(spd)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total Production vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footPoQty) {
            const tr = getTrend(spo, sb);
            footPoQty.innerHTML = `<span class="foot-val">${footTotal(spo)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total PO Qty vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footGrnQty) {
            const tr = getTrend(sg, sb);
            footGrnQty.innerHTML = `<span class="foot-val">${footTotal(sg)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total GRN Qty vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
        }
        if (footIssueQty) {
            const tr = getTrend(si, sb);
            footIssueQty.innerHTML = `<span class="foot-val">${footTotal(si)}</span>` + (sb > 0 ? `<span class="foot-trend is-${tr.status}" title="Total Issue Qty vs BOM (${tr.label})"><i class="fas ${tr.icon}"></i></span>` : '');
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

        if (window.rptSetupColumnFeatures) {
            window.rptSetupColumnFeatures(table, { onChange: syncHeaderHeight });
        }
 }

    function initLookupDateList(cfg) {
        const rangeSelect = document.getElementById('soRange');
        const customBox = document.getElementById('soCustomRange');
        const fromInput = document.getElementById('soFrom');
        const toInput = document.getElementById('soTo');
        const rangeError = document.getElementById('soRangeError');
        const rangeApply = document.getElementById('soRangeApply');
        const listEl = document.getElementById('soDateList');
        const lookup = document.querySelector('.lookup');
        if (!rangeSelect || !listEl || !cfg.ordersUrl) return;

        let orders = [];
        let currentLabel = '';
        let requestSeq = 0;

        function ymd(d) {
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${d.getFullYear()}-${month}-${day}`;
        }
        function pretty(value) {
            const parts = String(value || '').split('-');
            if (parts.length !== 3) return value;
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return `${parts[2]} ${months[parseInt(parts[1], 10) - 1] || parts[1]} ${parts[0]}`;
        }
        function addMonths(date, months) {
            const next = new Date(date.getFullYear(), date.getMonth() + months, date.getDate());
            if (next.getDate() !== date.getDate()) next.setDate(0);
            return next;
        }
        function startOfWeek(date) {
            const next = new Date(date.getFullYear(), date.getMonth(), date.getDate());
            const day = next.getDay();
            next.setDate(next.getDate() - (day === 0 ? 6 : day - 1));
            return next;
        }
        function periodRange(key) {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (key === 'today') return { from: today, to: today };
            if (key === 'yesterday') {
                const day = new Date(today);
                day.setDate(day.getDate() - 1);
                return { from: day, to: day };
            }
            if (key === 'this_week') return { from: startOfWeek(today), to: today };
            if (key === 'this_month') return { from: new Date(today.getFullYear(), today.getMonth(), 1), to: today };
            if (key === 'prev_week') {
                const start = startOfWeek(today);
                start.setDate(start.getDate() - 7);
                const end = new Date(start);
                end.setDate(start.getDate() + 6);
                return { from: start, to: end };
            }
            if (key === 'prev_month') {
                return {
                    from: new Date(today.getFullYear(), today.getMonth() - 1, 1),
                    to: new Date(today.getFullYear(), today.getMonth(), 0),
                };
            }
            return null;
        }
        function showError(message) {
            if (!rangeError) return;
            rangeError.hidden = !message;
            rangeError.textContent = message || '';
        }
        function syncLimits() {
            if (!fromInput || !toInput) return;
            if (fromInput.value) {
                toInput.min = fromInput.value;
                toInput.max = ymd(addMonths(new Date(fromInput.value + 'T00:00:00'), 6));
            }
            if (toInput.value) {
                fromInput.max = toInput.value;
                fromInput.min = ymd(addMonths(new Date(toInput.value + 'T00:00:00'), -6));
            }
        }
        function resetSearch() {
            requestSeq += 1;
            rangeSelect.value = '';
            if (customBox) customBox.hidden = true;
            if (fromInput) fromInput.value = '';
            if (toInput) toInput.value = '';
            showError('');
            orders = [];
            currentLabel = '';
            listEl.hidden = true;
            listEl.innerHTML = '';
            lookup?.classList.remove('is-orders-screen');
        }
        function openFabric(so) {
            if (!so || !cfg.pageUrl) return;
            const params = new URLSearchParams({ so: String(so) });
            if (cfg.mode) params.set('mode', cfg.mode);
            window.location.href = `${cfg.pageUrl}?${params.toString()}`;
        }
        function render(filterText) {
            const q = (filterText || '').trim().toLowerCase();
            const rows = orders.filter((order) => !q || [order.sales_order, order.customer, order.plant, order.material, order.created_by, order.creation_date].join(' ').toLowerCase().includes(q));
            const body = rows.map((order) => `
                <tr data-so="${escapeHtml(order.sales_order)}">
                    <td class="so-num">${escapeHtml(order.sales_order)}</td>
                    <td>${escapeHtml(order.creation_date || '—')}</td>
                    <td>${escapeHtml(order.customer || '—')}</td>
                    <td>${escapeHtml(order.plant || '—')}</td>
                    <td>${escapeHtml(order.material || '—')}</td>
                    <td>${escapeHtml(order.lines)}</td>
                    <td>${escapeHtml(order.quantity)}</td>
                </tr>
            `).join('');
            listEl.innerHTML = `
                <div class="lookup-orders-head">
                    <div>
                        <button type="button" class="lookup-back-search" id="soBackSearch">Back to search</button>
                        <strong>Sales orders</strong>
                        <span>${escapeHtml(currentLabel)} · ${rows.length} of ${orders.length}</span>
                    </div>
                    <input class="lookup-orders-search" id="soDateSearch" type="search" placeholder="Search orders" value="${escapeHtml(filterText || '')}">
                </div>
                <div class="lookup-orders-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Sales order</th><th>Created</th><th>Customer</th><th>Plant</th><th>Material</th><th>Lines</th><th>Qty</th>
                            </tr>
                        </thead>
                        <tbody>${body || `<tr><td colspan="7" class="lookup-orders-empty">${orders.length ? 'No rows match that search.' : 'No sales orders were created in this period.'}</td></tr>`}</tbody>
                    </table>
                </div>
            `;
            const search = document.getElementById('soDateSearch');
            if (search) {
                search.addEventListener('input', () => render(search.value));
            }
            document.getElementById('soBackSearch')?.addEventListener('click', resetSearch);
            listEl.querySelectorAll('tbody tr[data-so]').forEach((row) => {
                row.addEventListener('click', () => openFabric(row.getAttribute('data-so')));
            });
        }
        function loadRange(from, to, label) {
            currentLabel = label;
            const seq = ++requestSeq;
            lookup?.classList.add('is-orders-screen');
            listEl.hidden = false;
            listEl.innerHTML = `<div class="lookup-orders-status">Loading sales orders ${escapeHtml(label)}…</div>`;
            const params = new URLSearchParams({ from, to, _: String(Date.now()) });
            fetch(`${cfg.ordersUrl}?${params.toString()}`, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' })
                .then(async (res) => {
                    const json = JSON.parse(await res.text());
                    if (!res.ok || json.success === false) throw new Error(json.message || json.error || 'Unable to load sales orders.');
                    return json;
                })
                .then((json) => {
                    if (seq !== requestSeq) return;
                    orders = Array.isArray(json.orders) ? json.orders : [];
                    render('');
                })
                .catch((err) => {
                    if (seq !== requestSeq) return;
                    orders = [];
                    listEl.innerHTML = `<div class="lookup-orders-empty">${escapeHtml(err.message || 'Unable to load sales orders.')}<div><button type="button" class="lookup-back-search" id="soBackSearch">Back to search</button></div></div>`;
                    document.getElementById('soBackSearch')?.addEventListener('click', resetSearch);
                });
        }

        rangeSelect.addEventListener('change', () => {
            const key = rangeSelect.value;
            showError('');
            if (!key) {
                resetSearch();
                return;
            }
            if (key === 'custom') {
                if (customBox) customBox.hidden = false;
                listEl.hidden = true;
                lookup?.classList.remove('is-orders-screen');
                syncLimits();
                return;
            }
            if (customBox) customBox.hidden = true;
            const range = periodRange(key);
            if (!range) return;
            const from = ymd(range.from);
            const to = ymd(range.to);
            loadRange(from, to, from === to ? pretty(from) : `${pretty(from)} – ${pretty(to)}`);
        });
        fromInput?.addEventListener('change', syncLimits);
        toInput?.addEventListener('change', syncLimits);
        rangeApply?.addEventListener('click', () => {
            const from = (fromInput?.value || '').trim();
            const to = (toInput?.value || '').trim();
            if (!from || !to) {
                showError('Choose both a start date and an end date.');
                return;
            }
            if (to < from) {
                showError('The end date must be on or after the start date.');
                return;
            }
            const max = ymd(addMonths(new Date(from + 'T00:00:00'), 6));
            if (to > max) {
                showError('A custom date range can cover at most 6 months.');
                return;
            }
            showError('');
            loadRange(from, to, from === to ? pretty(from) : `${pretty(from)} – ${pretty(to)}`);
        });
    }
})();
