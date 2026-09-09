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

    function showSpinner() {
        if (!spinner) {
            return;
        }
        spinner.hidden = false;
        document.body.classList.add('rpt-loading');
    }

    function hideBoot() {
        if (app) {
            app.classList.remove('is-first');
        }
        if (boot) {
            boot.hidden = true;
        }
        drawCharts();
    }

    const form = document.getElementById('rptFilterForm');
    form?.addEventListener('submit', function () {
        const so = (document.getElementById('so')?.value || '').trim();
        if (so === '') {
            return;
        }
        showSpinner();
    });

    document.querySelectorAll('.rpt-tab, .rpt-pager a, .lookup-hint a, .lookup-switch a').forEach(function (el) {
        el.addEventListener('click', function () {
            if (el.classList.contains('active') || el.classList.contains('on')) {
                return;
            }
            showSpinner();
        });
    });

    let chartsDrawn = false;
    function drawCharts() {
        if (chartsDrawn || !dataNode || typeof Chart === 'undefined') {
            return;
        }
        chartsDrawn = true;

        let data = {};
        try {
            data = JSON.parse(dataNode.textContent || '{}');
        } catch (e) {
            return;
        }

        const totalsEl = document.getElementById('rptTotalsChart');
        const mixEl = document.getElementById('rptMixChart');

        if (totalsEl) {
            new Chart(totalsEl, {
                type: 'bar',
                data: {
                    labels: data.totals_labels || [],
                    datasets: [{
                        label: 'Quantity',
                        data: data.totals || [],
                        backgroundColor: ['#0f766e', '#0284c7', '#16a34a', '#d97706', '#7c3aed', '#e11d48'],
                        borderRadius: 8,
                        maxBarThickness: 42,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 900 },
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                    },
                },
            });
        }

        if (mixEl) {
            const mixLabels = data.mix_labels || [];
            const mixValues = data.mix_values || [];
            const mixColors = ['#0f766e', '#0284c7', '#7c3aed', '#d97706', '#16a34a', '#e11d48', '#0ea5e9', '#94a3b8'];
            new Chart(mixEl, {
                type: 'doughnut',
                data: {
                    labels: mixLabels.length ? mixLabels : ['No data'],
                    datasets: [{
                        data: mixValues.length ? mixValues : [1],
                        backgroundColor: mixValues.length ? mixColors : ['#e2e8e0'],
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    animation: { duration: 900 },
                    plugins: {
                        legend: {
                            display: mixValues.length > 0,
                            position: 'right',
                            labels: { boxWidth: 10, font: { size: 11 } },
                        },
                    },
                },
            });
        }
    }

    if (app && app.classList.contains('is-first')) {
        window.setTimeout(hideBoot, 1400);
    } else {
        drawCharts();
    }

    /*
     * -------------------------------------------------------------
     * Table ASC/DESC Sorting, Column-by-Column Filtering & Search
     * -------------------------------------------------------------
     */
    initDataTable();

    function initDataTable() {
        const table = document.getElementById('rptDataTable');
        const tbody = document.getElementById('rptTableBody');
        if (!table || !tbody) {
            return;
        }

        const rows = Array.from(tbody.querySelectorAll('tr[data-orig-sno]'));
        if (rows.length === 0) {
            return;
        }

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

        // Footer elements
        const footTotalLabel = document.getElementById('footTotalLabel');
        const footBomQty = document.getElementById('footBomQty');
        const footPlannedQty = document.getElementById('footPlannedQty');
        const footProductionQty = document.getElementById('footProductionQty');
        const footPoQty = document.getElementById('footPoQty');
        const footGrnQty = document.getElementById('footGrnQty');
        const footIssueQty = document.getElementById('footIssueQty');

        function parseNum(val) {
            if (!val || val === '—' || val === '-' || val.trim() === '') {
                return 0;
            }
            const clean = val.replace(/,/g, '').trim();
            const n = parseFloat(clean);
            return isNaN(n) ? 0 : n;
        }

        function formatQty(num) {
            if (num === 0) {
                return '—';
            }
            if (Math.abs(num - Math.round(num)) < 0.001) {
                return Math.round(num).toLocaleString('en-US');
            }
            return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Cache row data for performance
        const rowData = rows.map((tr, index) => {
            const cells = Array.from(tr.children);
            const colTexts = cells.map(td => td.textContent.trim());
            const colNums = colTexts.map(val => parseNum(val));
            const fullSearchText = colTexts.slice(1).join(' ').toLowerCase();
            return {
                tr,
                origIndex: index,
                origSno: parseInt(tr.getAttribute('data-orig-sno') || (index + 1), 10),
                colTexts,
                colNums,
                fullSearchText,
                visible: true,
            };
        });

        let currentSort = { col: null, dir: null, type: null };

        // 1. Column Sorting
        sortableHeaders.forEach(th => {
            th.addEventListener('click', function () {
                const col = parseInt(th.getAttribute('data-col'), 10);
                const type = th.getAttribute('data-type') || 'text';

                if (currentSort.col === col) {
                    if (currentSort.dir === 'asc') {
                        currentSort.dir = 'desc';
                    } else if (currentSort.dir === 'desc') {
                        // Reset sort to original sequence
                        currentSort = { col: null, dir: null, type: null };
                    }
                } else {
                    currentSort = { col, dir: 'asc', type };
                }

                updateSortUI();
                applySorting();
                applyFilterAndSearch();
            });
        });

        function updateSortUI() {
            sortableHeaders.forEach(th => {
                const col = parseInt(th.getAttribute('data-col'), 10);
                const icon = th.querySelector('.sort-icon');
                th.classList.remove('is-sorted-asc', 'is-sorted-desc');

                if (currentSort.col === col) {
                    if (currentSort.dir === 'asc') {
                        th.classList.add('is-sorted-asc');
                        if (icon) icon.innerHTML = '<i class="fas fa-sort-up"></i>';
                    } else if (currentSort.dir === 'desc') {
                        th.classList.add('is-sorted-desc');
                        if (icon) icon.innerHTML = '<i class="fas fa-sort-down"></i>';
                    }
                } else {
                    if (icon) icon.innerHTML = '<i class="fas fa-sort"></i>';
                }
            });
        }

        function applySorting() {
            if (currentSort.col === null || currentSort.dir === null) {
                // Restore original order
                rowData.sort((a, b) => a.origIndex - b.origIndex);
            } else {
                const { col, dir, type } = currentSort;
                rowData.sort((a, b) => {
                    let res = 0;
                    if (type === 'num') {
                        res = a.colNums[col] - b.colNums[col];
                    } else {
                        res = a.colTexts[col].localeCompare(b.colTexts[col], undefined, {
                            numeric: true,
                            sensitivity: 'base',
                        });
                    }
                    if (res === 0) {
                        res = a.origIndex - b.origIndex;
                    }
                    return dir === 'asc' ? res : -res;
                });
            }

            // Re-append in sorted order
            const frag = document.createDocumentFragment();
            rowData.forEach(item => frag.appendChild(item.tr));
            if (noMatchRow) frag.appendChild(noMatchRow);
            tbody.appendChild(frag);
        }

        // 2. Filter & Search Logic
        function parseNumCondition(query) {
            const trimmed = query.trim();
            const match = trimmed.match(/^([><]=?|=)\s*(-?\d+(?:\.\d+)?)$/);
            if (match) {
                return { op: match[1], val: parseFloat(match[2]) };
            }
            return null;
        }

        function applyFilterAndSearch() {
            const query = (searchInput?.value || '').trim().toLowerCase();
            const colFilters = [];

            colInputs.forEach(input => {
                const col = parseInt(input.getAttribute('data-col'), 10);
                const rawVal = input.value.trim();
                const isNum = input.hasAttribute('data-numeric');
                const clearBtn = input.parentElement?.querySelector('.rpt-col-clear');

                if (rawVal !== '') {
                    input.classList.add('has-val');
                    if (clearBtn) clearBtn.style.display = 'block';
                    colFilters.push({
                        col,
                        raw: rawVal,
                        lower: rawVal.toLowerCase(),
                        isNum,
                        cond: isNum ? parseNumCondition(rawVal) : null,
                    });
                } else {
                    input.classList.remove('has-val');
                    if (clearBtn) clearBtn.style.display = 'none';
                }
            });

            // Update Quick Search Clear button
            if (searchClear) {
                searchClear.style.display = query !== '' ? 'flex' : 'none';
            }

            let visibleCount = 0;
            let sumBom = 0;
            let sumPlan = 0;
            let sumProd = 0;
            let sumPo = 0;
            let sumGrn = 0;
            let sumIssue = 0;

            rowData.forEach(item => {
                let match = true;

                // Check quick search
                if (query !== '') {
                    if (!item.fullSearchText.includes(query)) {
                        match = false;
                    }
                }

                // Check column filters
                if (match && colFilters.length > 0) {
                    for (const filter of colFilters) {
                        const cellText = item.colTexts[filter.col] || '';
                        const cellNum = item.colNums[filter.col];

                        if (filter.cond) {
                            const { op, val } = filter.cond;
                            if (op === '>' && !(cellNum > val)) { match = false; break; }
                            if (op === '>=' && !(cellNum >= val)) { match = false; break; }
                            if (op === '<' && !(cellNum < val)) { match = false; break; }
                            if (op === '<=' && !(cellNum <= val)) { match = false; break; }
                            if (op === '=' && cellNum !== val) { match = false; break; }
                        } else {
                            if (!cellText.toLowerCase().includes(filter.lower)) {
                                match = false;
                                break;
                            }
                        }
                    }
                }

                item.visible = match;
                if (match) {
                    visibleCount++;
                    item.tr.style.display = '';
                    const snoCell = item.tr.firstElementChild;
                    if (snoCell) {
                        snoCell.textContent = visibleCount;
                    }

                    sumBom += item.colNums[5];
                    sumPlan += item.colNums[6];
                    sumProd += item.colNums[7];
                    sumPo += item.colNums[8];
                    sumGrn += item.colNums[9];
                    sumIssue += item.colNums[10];
                } else {
                    item.tr.style.display = 'none';
                }
            });

            // No match row
            if (noMatchRow) {
                noMatchRow.style.display = visibleCount === 0 ? '' : 'none';
            }

            // Update Counts and Badges
            const isFiltered = query !== '' || colFilters.length > 0;
            if (countBadge) {
                if (isFiltered) {
                    countBadge.textContent = `Showing ${visibleCount.toLocaleString()} of ${rowData.length.toLocaleString()} lines`;
                    countBadge.classList.add('is-filtered');
                } else {
                    countBadge.textContent = `Showing ${rowData.length.toLocaleString()} of ${rowData.length.toLocaleString()} lines`;
                    countBadge.classList.remove('is-filtered');
                }
            }

            if (activeFilterBadge) {
                if (colFilters.length > 0) {
                    activeFilterBadge.style.display = 'inline-flex';
                    activeFilterBadge.textContent = colFilters.length;
                } else {
                    activeFilterBadge.style.display = 'none';
                }
            }

            if (clearAllBtn) {
                clearAllBtn.style.display = (isFiltered || currentSort.col !== null) ? 'inline-flex' : 'none';
            }

            // Dynamic Footer Totals
            if (footTotalLabel) {
                if (isFiltered) {
                    footTotalLabel.textContent = `Total (${visibleCount.toLocaleString()} of ${rowData.length.toLocaleString()} lines)`;
                } else {
                    footTotalLabel.textContent = `Total (${rowData.length.toLocaleString()} lines)`;
                }
            }
            if (footBomQty) footBomQty.textContent = formatQty(sumBom);
            if (footPlannedQty) footPlannedQty.textContent = formatQty(sumPlan);
            if (footProductionQty) footProductionQty.textContent = formatQty(sumProd);
            if (footPoQty) footPoQty.textContent = formatQty(sumPo);
            if (footGrnQty) footGrnQty.textContent = formatQty(sumGrn);
            if (footIssueQty) footIssueQty.textContent = formatQty(sumIssue);
        }

        // Quick Search events
        let searchDebounce = null;
        searchInput?.addEventListener('input', function () {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(applyFilterAndSearch, 80);
        });
        searchInput?.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchInput.value = '';
                applyFilterAndSearch();
            } else if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
        searchClear?.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            applyFilterAndSearch();
        });

        // Column Filter events
        let colFilterDebounce = null;
        colInputs.forEach(input => {
            input.addEventListener('input', function () {
                clearTimeout(colFilterDebounce);
                colFilterDebounce = setTimeout(applyFilterAndSearch, 100);
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    input.value = '';
                    applyFilterAndSearch();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });

            // Clear button inside input wrapper
            const clearBtn = input.parentElement?.querySelector('.rpt-col-clear');
            clearBtn?.addEventListener('click', function (e) {
                e.stopPropagation();
                input.value = '';
                input.focus();
                applyFilterAndSearch();
            });
        });

        // Reset all Column Filters only
        colFilterResetBtn?.addEventListener('click', function () {
            colInputs.forEach(input => {
                input.value = '';
            });
            applyFilterAndSearch();
        });

        // Toggle Column Filter Row visibility
        toggleFiltersBtn?.addEventListener('click', function () {
            if (!filterRow) return;
            const isHidden = filterRow.style.display === 'none';
            filterRow.style.display = isHidden ? '' : 'none';
            toggleFiltersBtn.classList.toggle('is-active', isHidden);
        });

        // Clear All (Search, Column Filters, and Sort)
        function clearAllFilters() {
            if (searchInput) {
                searchInput.value = '';
            }
            colInputs.forEach(input => {
                input.value = '';
            });
            currentSort = { col: null, dir: null, type: null };
            updateSortUI();
            applySorting();
            applyFilterAndSearch();
        }

        clearAllBtn?.addEventListener('click', clearAllFilters);
        resetTableBtn?.addEventListener('click', clearAllFilters);
    }
})();

