/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : Bulk PO Costing Validation — fetch, validate, drill-down, workbench
 */
(function () {
    let cfg = {};
    try { cfg = JSON.parse(document.getElementById('poConfig')?.textContent || '{}'); } catch (e) { cfg = {}; }

    const $ = (id) => document.getElementById(id);
    const spinner = $('rptSpinner');
    const money = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const money2 = (n) => (n === null || n === undefined || n === '') ? '—' : Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qty = (n) => Number(n || 0).toLocaleString('en-IN');
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));
    const fmtDate = (iso) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        return m ? `${m[3]}-${m[2]}-${m[1]}` : '—';
    };

    const FABRIC = new Set(['ZFRI', 'ZFRD']);
    const TRIMS = new Set(['ZTRD', 'ZTRI']);
    const RUNTIME_CONCURRENCY = 2;

    let allRows = [];
    let filtered = [];
    let page = 1;
    const pageSize = 25;
    let activePo = null;
    let itemCache = {};
    let toastTimer = null;
    let runtimeToken = 0;
    let runtimeRunning = false;
    let validateQueue = [];

    const COL_DEFS = [
        { key: 'check', label: 'Select', locked: true },
        { key: 'po', label: 'PO Number' },
        { key: 'date', label: 'PO Date' },
        { key: 'vendor_code', label: 'Vendor Code' },
        { key: 'vendor_name', label: 'Vendor Name' },
        { key: 'material', label: 'Material Type' },
        { key: 'items', label: 'No. of Items' },
        { key: 'value', label: 'PO Value (₹)' },
        { key: 'passed', label: 'Passed' },
        { key: 'exceptions', label: 'Exceptions' },
        { key: 'pending', label: 'Pending' },
        { key: 'status', label: 'Validation Status' },
        { key: 'last', label: 'Last Validated' },
        { key: 'validated_by', label: 'Validated By' },
        { key: 'actions', label: 'Actions', locked: true },
    ];
    let hiddenCols = new Set();
    let currentSort = { key: null, dir: null }; // dir: 'asc' | 'desc' | null
    const NUMERIC_SORT_KEYS = new Set(['items', 'value', 'passed', 'exceptions', 'pending']);

    /*
    * Handle Current User Functionality
    */
    function currentUserName() {
        const raw = String(cfg.currentUser || cfg.username || cfg.user_name || '').trim();
        if (!raw) return '';
        return raw.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }

    /*
    * Handle Spinner Functionality
    */
    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    /*
    * Handle Toast Functionality
    */
    function toast(msg, kind = 'info') {
        const el = $('pcbToast');
        if (!el) return;
        el.hidden = false;
        el.className = 'pcb-toast is-' + kind;
        el.textContent = msg;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.hidden = true; }, kind === 'error' ? 8000 : 4500);
    }

    /*
    * Handle Progress Bar Functionality
    */
    function setProgress(on, done = 0, total = 0, label = 'Validating…') {
        const wrap = $('pcbProgress');
        if (!wrap) return;
        wrap.hidden = !on;
        if (!on) return;
        const pct = total ? Math.round((done / total) * 100) : 0;
        if ($('pcbProgressLabel')) $('pcbProgressLabel').textContent = label;
        if ($('pcbProgressPct')) $('pcbProgressPct').textContent = pct + '%';
        if ($('pcbProgressBar')) $('pcbProgressBar').style.width = pct + '%';
    }

    /*************
     * Handldew Selceted Material Functionality
     */
    function selectedMat() {
        const sel = $('pcbMat');
        if (sel) return sel.value || 'all';
        return document.querySelector('input[name="pcb_mat"]:checked')?.value || 'all';
    }

    function workbenchUrl(po) {
        const u = new URL(cfg.pageUrl || 'purchase-order', window.location.origin);
        u.searchParams.set('view', 'costing');
        u.searchParams.set('po', po);
        const mat = selectedMat();
        if (mat !== 'all') u.searchParams.set('mat_cat', mat);
        return u.pathname + u.search;
    }

    function statusBadge(st) {
        const s = String(st || 'PENDING').toUpperCase();
        const cls = s === 'PASSED' ? 'is-pass' : (s === 'EXCEPTION' ? 'is-fail' : 'is-pending');
        return `<span class="pcb-badge ${cls}">${esc(s)}</span>`;
    }

    /*****
     * Materail Type Label Functionality
     */
    function materialTypeLabel(rec) {
        const cat = String(rec.mat_category || '').toLowerCase();
        if (cat === 'fabric') return 'Fabric';
        if (cat === 'trims') return 'Trims';
        if (cat === 'mixed') return 'Mixed';

        const cats = Array.isArray(rec.mat_categories) ? rec.mat_categories.map((c) => String(c).toLowerCase()) : [];
        if (cats.includes('fabric') && cats.includes('trims')) return 'Mixed';
        if (cats.includes('fabric')) return 'Fabric';
        if (cats.includes('trims')) return 'Trims';

        const types = Array.isArray(rec.material_types)
            ? rec.material_types
            : String(rec.material_type || '').split(',').map((s) => s.trim()).filter(Boolean);
        let hasFab = false;
        let hasTrim = false;
        types.forEach((t) => {
            const u = String(t).toUpperCase();
            if (FABRIC.has(u)) hasFab = true;
            if (TRIMS.has(u)) hasTrim = true;
        });
        if (hasFab && hasTrim) return 'Mixed';
        if (hasFab) return 'Fabric';
        if (hasTrim) return 'Trims';
        return '—';
    }

    function normalizeRow(rec) {
        const po = String(rec.purchase_order || '');
        const cached = itemCache[po];
        let passed = 0, exceptions = 0, pending = 0;
        let validationStatus = 'PENDING';
        let lastValidated = '';
        let validatedBy = '';
        if (cached && Array.isArray(cached.records)) {
            cached.records.forEach((r) => {
                if (r.status === 'PASS') passed++;
                else exceptions++;
            });
            pending = 0;
            validationStatus = exceptions > 0 ? 'EXCEPTION' : (passed > 0 ? 'PASSED' : 'PENDING');
            lastValidated = cached._validated_at || '';
            validatedBy = cached._validated_by || '';
        } else {
            pending = Number(rec.items_count || 0) || 1;
            validationStatus = 'PENDING';
        }
        return {
            ...rec,
            _checked: !!rec._checked,
            _mat_label: materialTypeLabel(rec),
            _passed: passed,
            _exceptions: exceptions,
            _pending: pending,
            _validation_status: validationStatus,
            _last_validated: lastValidated,
            _validated_by: validatedBy,
        };
    }

    function pctLabel(n, total) {
        if (!total) return '0%';
        const p = (n / total) * 100;
        if (p > 0 && p < 1) return '<1%';
        return Math.round(p) + '%';
    }

    function colFilterMap() {
        const map = {};
        document.querySelectorAll('.pcb-col-filter').forEach((inp) => {
            const key = inp.getAttribute('data-key');
            const val = String(inp.value || '').trim().toLowerCase();
            if (key && val) map[key] = val;
        });
        return map;
    }

    function rowField(r, key) {
        switch (key) {
            case 'po': return String(r.purchase_order || '');
            case 'date': return fmtDate(r.date);
            case 'vendor_code': return String(r.supplier || '');
            case 'vendor_name': return String(r.supplier_full_name || '');
            case 'material': return String(r._mat_label || '');
            case 'items': return [r.items_count, qty(r.items_count)].filter((v) => v !== '' && v != null).join(' ');
            case 'value': return [r.total_value, money(r.total_value), money2(r.total_value)].join(' ');
            case 'passed': return [r._passed, qty(r._passed)].join(' ');
            case 'exceptions': return [r._exceptions, qty(r._exceptions)].join(' ');
            case 'pending': return [r._pending, qty(r._pending)].join(' ');
            case 'status': {
                const s = String(r._validation_status || '');
                return s + (s === 'EXCEPTION' ? ' fail failed' : '') + (s === 'PASSED' ? ' pass ok' : '');
            }
            case 'last': return String(r._last_validated || '');
            case 'validated_by': return String(r._validated_by || '');
            default: return '';
        }
    }

    function updateFilterUi() {
        const q = String($('pcbQuickSearch')?.value || '').trim();
        const colMap = colFilterMap();
        const activeCols = Object.keys(colMap).length;
        const clearBtn = $('pcbQuickSearchClear');
        if (clearBtn) clearBtn.style.display = q ? '' : 'none';
        const badge = $('pcbActiveFilterBadge');
        if (badge) {
            badge.style.display = activeCols ? '' : 'none';
            badge.textContent = String(activeCols);
        }
        const clearAll = $('pcbClearAllFilters');
        if (clearAll) clearAll.style.display = (q || activeCols) ? '' : 'none';
        document.querySelectorAll('.pcb-col-filter').forEach((inp) => {
            inp.classList.toggle('has-val', !!String(inp.value || '').trim());
        });
        const count = $('pcbCount');
        if (count) count.classList.toggle('is-filtered', !!(q || activeCols));
    }

    function applyFilter() {
        const q = String($('pcbQuickSearch')?.value || '').trim().toLowerCase();
        const vs = String($('pcbValStatus')?.value || 'all');
        const cols = colFilterMap();
        filtered = allRows.filter((r) => {
            if (vs !== 'all' && r._validation_status !== vs) return false;
            if (q) {
                const hay = [r.purchase_order, r.supplier, r.supplier_full_name, r._mat_label, r._validation_status, r._validated_by].join(' ').toLowerCase();
                if (!hay.includes(q)) return false;
            }
            for (const [key, val] of Object.entries(cols)) {
                const hay = rowField(r, key);
                const n = String(val || '').toLowerCase().replace(/,/g, '');
                const h = String(hay || '').toLowerCase();
                if (!(h.includes(String(val || '').toLowerCase()) || h.replace(/,/g, '').includes(n))) return false;
            }
            return true;
        });
        applySort();
        page = 1;
        updateFilterUi();
        updateSortUi();
        renderTable();
        fillKpis();
    }

    function sortValue(r, key) {
        switch (key) {
            case 'po': return String(r.purchase_order || '');
            case 'date': return String(r.date || ''); // ISO yyyy-mm-dd sorts correctly
            case 'vendor_code': return String(r.supplier || '');
            case 'vendor_name': return String(r.supplier_full_name || '');
            case 'material': return String(r._mat_label || '');
            case 'items': return Number(r.items_count || 0);
            case 'value': return Number(r.total_value || 0);
            case 'passed': return Number(r._passed || 0);
            case 'exceptions': return Number(r._exceptions || 0);
            case 'pending': return Number(r._pending || 0);
            case 'status': return String(r._validation_status || '');
            case 'last': return String(r._last_validated || '');
            case 'validated_by': return String(r._validated_by || '');
            default: return '';
        }
    }

    function applySort() {
        if (!currentSort.key || !currentSort.dir) return;
        const key = currentSort.key;
        const dir = currentSort.dir === 'desc' ? -1 : 1;
        const numeric = NUMERIC_SORT_KEYS.has(key);
        filtered.sort((a, b) => {
            const av = sortValue(a, key);
            const bv = sortValue(b, key);
            if (numeric) {
                const an = Number(av); const bn = Number(bv);
                if (an === bn) return 0;
                return an > bn ? dir : -dir;
            }
            const as = String(av).toLowerCase();
            const bs = String(bv).toLowerCase();
            if (as === bs) return 0;
            return as > bs ? dir : -dir;
        });
    }

    function updateSortUi() {
        document.querySelectorAll('#pcbTable th.is-sortable').forEach((th) => {
            const key = th.getAttribute('data-sort');
            const asc = currentSort.key === key && currentSort.dir === 'asc';
            const desc = currentSort.key === key && currentSort.dir === 'desc';
            th.classList.toggle('is-sorted-asc', asc);
            th.classList.toggle('is-sorted-desc', desc);
            th.setAttribute('aria-sort', asc ? 'ascending' : (desc ? 'descending' : 'none'));
        });
    }

    function toggleSort(key) {
        if (!key) return;
        if (currentSort.key !== key) currentSort = { key, dir: 'asc' };
        else if (currentSort.dir === 'asc') currentSort = { key, dir: 'desc' };
        else currentSort = { key: null, dir: null };
        applySort();
        page = 1;
        updateSortUi();
        renderTable();
    }

    function applyColumnVisibility() {
        const table = $('pcbTable');
        if (!table) return;
        table.querySelectorAll('[data-col-key]').forEach((cell) => {
            const key = cell.getAttribute('data-col-key');
            const hide = hiddenCols.has(key);
            cell.style.display = hide ? 'none' : '';
        });
        // filter row cells align by index with header
        const headerCells = table.querySelectorAll('tr.pcb-header-row th');
        const filterCells = table.querySelectorAll('tr.pcb-filter-row th');
        headerCells.forEach((th, i) => {
            const key = th.getAttribute('data-col-key');
            const hide = key ? hiddenCols.has(key) : false;
            th.style.display = hide ? 'none' : '';
            if (filterCells[i]) filterCells[i].style.display = hide ? 'none' : '';
        });
        // body cells: use same order
        table.querySelectorAll('tbody tr').forEach((tr) => {
            if (tr.classList.contains('pcb-empty')) return;
            const tds = tr.children;
            headerCells.forEach((th, i) => {
                const key = th.getAttribute('data-col-key');
                if (!tds[i]) return;
                tds[i].style.display = (key && hiddenCols.has(key)) ? 'none' : '';
            });
        });
    }

    function buildColumnMenu() {
        const menu = $('pcbColumnToggleMenu');
        if (!menu) return;
        const visible = COL_DEFS.filter((c) => !c.locked && !hiddenCols.has(c.key)).length;
        const optional = COL_DEFS.filter((c) => !c.locked).length;
        menu.innerHTML =
            `<div class="pcb-col-menu-head">
                <div>
                    <strong>Columns</strong>
                    <small>${visible} of ${optional} visible</small>
                </div>
                <div class="pcb-col-menu-actions">
                    <button type="button" class="pcb-col-menu-btn" data-pcb-col-act="all">Show all</button>
                    <button type="button" class="pcb-col-menu-btn" data-pcb-col-act="reset">Reset</button>
                </div>
            </div>
            <div class="pcb-col-menu-list">` +
            COL_DEFS.map((c) => {
                const checked = !hiddenCols.has(c.key);
                const disabled = c.locked ? ' disabled' : '';
                return `<label class="rpt-cat-dd-item pcb-col-menu-item${c.locked ? ' is-locked' : ''}">
                    <input type="checkbox" data-col-key="${esc(c.key)}" ${checked ? 'checked' : ''}${disabled}>
                    <span>${esc(c.label)}</span>
                    ${c.locked ? '<em>Fixed</em>' : ''}
                </label>`;
            }).join('') +
            `</div>`;

        menu.querySelectorAll('[data-pcb-col-act]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const act = btn.getAttribute('data-pcb-col-act');
                if (act === 'all' || act === 'reset') {
                    hiddenCols.clear();
                    buildColumnMenu();
                    applyColumnVisibility();
                }
            });
        });
        menu.querySelectorAll('input[data-col-key]').forEach((inp) => {
            inp.addEventListener('change', () => {
                const key = inp.getAttribute('data-col-key');
                if (!key) return;
                if (inp.checked) hiddenCols.delete(key);
                else hiddenCols.add(key);
                applyColumnVisibility();
                buildColumnMenu();
            });
        });
    }

    function applyListStickyMode(enabled) {
        const wrap = $('pcbTableWrap');
        const btn = $('pcbToggleStickyScroll');
        const label = $('pcbStickyScrollLabel');
        if (!wrap) return;
        wrap.classList.toggle('is-scroll-body', !!enabled);
        wrap.classList.toggle('is-expanded', !enabled);
        if (enabled) {
            wrap.style.maxHeight = '';
            wrap.style.height = '';
            wrap.style.overflow = '';
        } else {
            wrap.style.maxHeight = 'none';
            wrap.style.height = 'auto';
            wrap.style.overflow = 'visible';
        }
        btn?.classList.toggle('is-active', !!enabled);
        if (label) label.textContent = enabled ? 'Scroll Body Only' : 'Expand Table';
    }

    function fillKpis() {
        const total = filtered.length;
        let passed = 0, exc = 0, pend = 0, value = 0;
        filtered.forEach((r) => {
            value += Number(r.total_value || 0);
            if (r._validation_status === 'PASSED') passed++;
            else if (r._validation_status === 'EXCEPTION') exc++;
            else pend++;
        });
        const barPct = (n) => (total ? Math.min(100, Math.round((n / total) * 100)) : 0);
        if ($('pcbKpiTotal')) $('pcbKpiTotal').textContent = qty(total);
        if ($('pcbKpiPassed')) $('pcbKpiPassed').textContent = qty(passed);
        if ($('pcbKpiExceptions')) $('pcbKpiExceptions').textContent = qty(exc);
        if ($('pcbKpiPending')) $('pcbKpiPending').textContent = qty(pend);
        if ($('pcbKpiPassedPct')) $('pcbKpiPassedPct').textContent = `Passed (${pctLabel(passed, total)})`;
        if ($('pcbKpiExcPct')) $('pcbKpiExcPct').textContent = `Exceptions (${pctLabel(exc, total)})`;
        if ($('pcbKpiPendPct')) $('pcbKpiPendPct').textContent = `Pending (${pctLabel(pend, total)})`;
        if ($('pcbKpiValue')) $('pcbKpiValue').textContent = '₹ ' + money(value);
        if ($('pcbKpiPassedBar')) $('pcbKpiPassedBar').style.width = barPct(passed) + '%';
        if ($('pcbKpiExcBar')) $('pcbKpiExcBar').style.width = barPct(exc) + '%';
        if ($('pcbKpiPendBar')) $('pcbKpiPendBar').style.width = barPct(pend) + '%';
        if ($('pcbKpiTotalBar')) $('pcbKpiTotalBar').style.width = total ? '100%' : '0%';

        document.querySelectorAll('.pcb-kpi').forEach((card) => {
            card.classList.remove('is-pulse');
            // force reflow so animation can replay on data refresh
            void card.offsetWidth;
            card.classList.add('is-pulse');
        });

        const start = (page - 1) * pageSize;
        const showing = Math.min(pageSize, Math.max(0, filtered.length - start));
        if ($('pcbCount')) $('pcbCount').textContent = filtered.length
            ? `Showing ${showing} of ${filtered.length} purchase orders`
            : 'No purchase orders';
    }

    function pageRows() {
        const start = (page - 1) * pageSize;
        return filtered.slice(start, start + pageSize);
    }

    function renderTable() {
        const tbody = $('pcbTableBody');
        const cards = $('pcbCards');
        if (!tbody) return;
        const rows = pageRows();
        if (!rows.length) {
            tbody.innerHTML = '<tr class="pcb-empty"><td colspan="15">No purchase orders match the filters.</td></tr>';
            if (cards) cards.innerHTML = '<div class="pcb-card-empty">No purchase orders match the filters.</div>';
            $('pcbPager') && ($('pcbPager').hidden = true);
            fillKpis();
            return;
        }

        tbody.innerHTML = rows.map((r) => {
            const active = activePo === r.purchase_order ? ' is-active' : '';
            return `<tr class="pcb-row${active}" data-po="${esc(r.purchase_order)}">
                <td class="pcb-check"><input type="checkbox" class="pcb-row-check" data-po="${esc(r.purchase_order)}" ${r._checked ? 'checked' : ''}></td>
                <td class="pcb-sticky-po"><button type="button" class="pcb-po-link" data-po="${esc(r.purchase_order)}">${esc(r.purchase_order)}</button></td>
                <td>${fmtDate(r.date)}</td>
                <td>${dash(r.supplier)}</td>
                <td class="pcb-vendor" title="${esc(r.supplier_full_name)}">${dash(r.supplier_full_name)}</td>
                <td><span class="pcb-mat-chip">${dash(r._mat_label)}</span></td>
                <td class="num">${qty(r.items_count)}</td>
                <td class="num">${money(r.total_value)}</td>
                <td class="num">${qty(r._passed)}</td>
                <td class="num">${qty(r._exceptions)}</td>
                <td class="num">${qty(r._pending)}</td>
                <td>${statusBadge(r._validation_status)}</td>
                <td>${dash(r._last_validated)}</td>
                <td>${dash(r._validated_by)}</td>
                <td><button type="button" class="pcb-btn pcb-btn-sm pcb-drill-btn" data-po="${esc(r.purchase_order)}">View</button></td>
            </tr>`;
        }).join('');

        if (cards) {
            cards.innerHTML = rows.map((r) => {
                const active = activePo === r.purchase_order ? ' is-active' : '';
                return `<article class="pcb-card${active}" data-po="${esc(r.purchase_order)}">
                    <div class="pcb-card-top">
                        <label class="pcb-card-check"><input type="checkbox" class="pcb-row-check" data-po="${esc(r.purchase_order)}" ${r._checked ? 'checked' : ''}></label>
                        <button type="button" class="pcb-po-link" data-po="${esc(r.purchase_order)}">${esc(r.purchase_order)}</button>
                        ${statusBadge(r._validation_status)}
                    </div>
                    <div class="pcb-card-meta">
                        <span>${fmtDate(r.date)}</span>
                        <span class="pcb-mat-chip">${dash(r._mat_label)}</span>
                    </div>
                    <div class="pcb-card-vendor" title="${esc(r.supplier_full_name)}">${dash(r.supplier)} · ${dash(r.supplier_full_name)}</div>
                    <div class="pcb-card-stats">
                        <span><b>${qty(r.items_count)}</b> items</span>
                        <span><b>₹ ${money(r.total_value)}</b></span>
                        <span class="is-pass">${qty(r._passed)} pass</span>
                        <span class="is-fail">${qty(r._exceptions)} exc</span>
                    </div>
                    <div class="pcb-card-meta">
                        <span>${dash(r._last_validated)}</span>
                        <span title="Validated by">${dash(r._validated_by)}</span>
                    </div>
                    <div class="pcb-card-actions">
                        <button type="button" class="pcb-btn pcb-btn-sm pcb-drill-btn" data-po="${esc(r.purchase_order)}">View</button>
                    </div>
                </article>`;
            }).join('');
        }

        const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if ($('pcbPager')) {
            $('pcbPager').hidden = pages <= 1;
            if ($('pcbPageInfo')) $('pcbPageInfo').textContent = `Page ${page} of ${pages}`;
            if ($('pcbPrev')) $('pcbPrev').disabled = page <= 1;
            if ($('pcbNext')) $('pcbNext').disabled = page >= pages;
        }
        if ($('pcbSelectAll')) {
            const pageChecked = rows.length && rows.every((r) => r._checked);
            $('pcbSelectAll').checked = pageChecked;
            $('pcbSelectAll').indeterminate = !pageChecked && rows.some((r) => r._checked);
        }
        applyColumnVisibility();
        updateSortUi();
        fillKpis();
    }

    function presetDates() {
        return cfg.presetDates || {};
    }

    function applyRangePreset(key, { fillDates = true } = {}) {
        const customWrap = $('pcbCustomDates');
        const isCustom = key === 'custom';
        if (customWrap) {
            customWrap.hidden = !isCustom;
            customWrap.style.display = isCustom ? 'flex' : 'none';
        }
        if (!isCustom && fillDates) {
            const dates = presetDates()[key];
            if (dates) {
                if ($('pcbFrom')) $('pcbFrom').value = dates.from || '';
                if ($('pcbTo')) $('pcbTo').value = dates.to || '';
            }
        }
    }

    function currentRange() {
        return String($('pcbRange')?.value || cfg.range?.preset || 'this_month');
    }

    async function fetchPos(e) {
        e?.preventDefault();
        const range = currentRange();
        applyRangePreset(range, { fillDates: range !== 'custom' });
        const from = $('pcbFrom')?.value || '';
        const to = $('pcbTo')?.value || '';
        if (!from || !to) {
            toast(range === 'custom' ? 'Select Custom From and To dates.' : 'Unable to resolve date range.', 'error');
            return;
        }
        if (from > to) {
            toast('From date cannot be after To date.', 'error');
            return;
        }
        if (range === 'custom') {
            const maxDays = 100;
            const a = new Date(`${from}T00:00:00`);
            const b = new Date(`${to}T00:00:00`);
            const span = Math.floor((b.getTime() - a.getTime()) / 86400000) + 1;
            if (span > maxDays) {
                toast(`Custom Date allows a maximum of ${maxDays} days.`, 'error');
                return;
            }
        }
        const supplier = $('pcbSupplier')?.value || '';
        const mat = selectedMat();
        const params = new URLSearchParams({
            list: '1',
            range,
            from,
            to,
            supplier,
            mat_cat: mat,
            view: 'costing',
            _: String(Date.now()),
        });
        stopRuntimeValidation();
        showSpinner(true);
        setProgress(false);
        try {
            const res = await fetch(`${cfg.dataUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            if (!res.ok || json.success === false) throw new Error(json.message || json.error || 'Fetch failed');
            const records = Array.isArray(json.data?.records) ? json.data.records : [];
            itemCache = {};
            allRows = records.map((r) => normalizeRow(r));
            refreshSupplierOptions(allRows);
            activePo = null;
            closeOffcanvas();
            applyFilter();
            const u = new URL(cfg.pageUrl || 'purchase-order', window.location.origin);
            u.searchParams.set('view', 'costing');
            u.searchParams.set('range', range);
            if (from) u.searchParams.set('from', from);
            if (to) u.searchParams.set('to', to);
            if (supplier) u.searchParams.set('supplier', supplier);
            if (mat !== 'all') u.searchParams.set('mat_cat', mat);
            window.history.replaceState({}, '', u.pathname + u.search);
            toast(`Loaded ${records.length.toLocaleString('en-IN')} purchase orders. Select rows to validate.`, 'ok');
        } catch (err) {
            stopRuntimeValidation();
            allRows = [];
            applyFilter();
            toast(err.message || 'Unable to fetch POs', 'error');
        } finally {
            showSpinner(false);
        }
    }

    function refreshSupplierOptions(rows) {
        const sel = $('pcbSupplier');
        if (!sel) return;
        const current = String(sel.value || '').trim();
        const map = new Map();
        // Keep currently selected vendor even if not in this result set
        if (current) {
            const selectedOpt = sel.querySelector(`option[value="${CSS.escape(current)}"]`);
            const label = selectedOpt ? selectedOpt.textContent : `Supplier ${current}`;
            map.set(current, label.replace(/\s*\(\d+\)\s*$/, '').trim() || `Supplier ${current}`);
        }
        rows.forEach((r) => {
            const c = String(r.supplier || '').trim();
            if (!c) return;
            if (!map.has(c)) map.set(c, String(r.supplier_full_name || `Supplier ${c}`));
        });
        const opts = ['<option value="">All Vendors</option>'];
        [...map.entries()].sort((a, b) => a[1].localeCompare(b[1])).forEach(([c, n]) => {
            opts.push(`<option value="${esc(c)}"${c === current ? ' selected' : ''}>${esc(n)} (${esc(c)})</option>`);
        });
        sel.innerHTML = opts.join('');
        if (current) sel.value = current;
    }

    function searchPoHeader() {
        const raw = String($('pcbPoSearch')?.value || '').trim();
        const po = raw.replace(/\D/g, '');
        if (!po || !/^\d{1,10}$/.test(po)) {
            toast('Enter a valid PO number (up to 10 digits).', 'error');
            return;
        }
        if ($('pcbQuickSearch')) $('pcbQuickSearch').value = po;
        // Prefer opening offcanvas for this PO if it is already in the list
        const inList = allRows.some((r) => String(r.purchase_order) === po);
        if (inList) {
            applyFilter();
            void openDrill(po);
            return;
        }
        // Otherwise open costing workbench for that PO
        window.location.href = workbenchUrl(po);
    }

    async function validateOne(po) {
        const mat = selectedMat();
        const params = new URLSearchParams({ po, mat_cat: mat, _: String(Date.now()) });
        const res = await fetch(`${cfg.validateUrl}?${params}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const json = await res.json();
        if (!res.ok || json.success === false) throw new Error(json.message || json.error || `Validate failed for ${po}`);
        const data = json.data || {};
        data._validated_at = new Date().toLocaleString('en-IN');
        data._validated_by = currentUserName() || '—';
        itemCache[po] = data;
        return data;
    }

    function syncRow(po) {
        const idx = allRows.findIndex((r) => r.purchase_order === po);
        if (idx >= 0) {
            const checked = !!allRows[idx]._checked;
            allRows[idx] = normalizeRow({ ...allRows[idx], _checked: checked });
        }
        const fIdx = filtered.findIndex((r) => r.purchase_order === po);
        if (fIdx >= 0) {
            const src = allRows.find((r) => r.purchase_order === po);
            if (src) filtered[fIdx] = src;
        }
    }

    function setRuntimeStatus(on, text = '') {
        const wrap = $('pcbRuntimeStatus');
        const label = $('pcbRuntimeText');
        if (wrap) wrap.hidden = !on;
        if (label && text) label.textContent = text;
    }

    function stopRuntimeValidation() {
        runtimeToken += 1;
        runtimeRunning = false;
        validateQueue = [];
        setRuntimeStatus(false);
        setProgress(false);
    }

    function selectedPendingPos() {
        return allRows
            .filter((r) => r._checked && r.purchase_order && !itemCache[r.purchase_order])
            .map((r) => String(r.purchase_order));
    }

    function enqueueSelectedValidation(extraPos = []) {
        const next = [...selectedPendingPos(), ...extraPos.map(String)].filter((po) => po && !itemCache[po]);
        next.forEach((po) => {
            if (!validateQueue.includes(po)) validateQueue.push(po);
        });
        if (validateQueue.length) void drainValidateQueue();
    }

    async function drainValidateQueue() {
        if (runtimeRunning) return;
        if (!validateQueue.length) {
            setRuntimeStatus(false);
            setProgress(false);
            return;
        }

        const token = ++runtimeToken;
        runtimeRunning = true;
        let done = 0;
        let fail = 0;
        const startedWith = validateQueue.length;
        setRuntimeStatus(true, `Validating selected 0/${startedWith}`);
        setProgress(true, 0, startedWith, 'Validating selected POs…');

        const workers = Array.from({ length: Math.min(RUNTIME_CONCURRENCY, Math.max(1, validateQueue.length)) }, async () => {
            while (token === runtimeToken) {
                const po = validateQueue.shift();
                if (!po) return;
                if (itemCache[po]) {
                    done++;
                    continue;
                }
                try {
                    await validateOne(po);
                    if (token !== runtimeToken) return;
                    syncRow(po);
                } catch (err) {
                    fail++;
                }
                done++;
                if (token !== runtimeToken) return;
                const remaining = validateQueue.length;
                const total = done + remaining;
                setProgress(true, done, total, `Validated ${done}/${total}${fail ? ` · ${fail} failed` : ''}`);
                setRuntimeStatus(true, `Validating selected ${done}/${total}`);
                if (done % 2 === 0 || remaining === 0 || pageRows().some((r) => r.purchase_order === po)) {
                    fillKpis();
                    renderTable();
                    if (activePo === po) renderDrill(po);
                }
            }
        });

        await Promise.all(workers);
        if (token !== runtimeToken) return;

        runtimeRunning = false;
        fillKpis();
        renderTable();
        if (activePo && itemCache[activePo]) renderDrill(activePo);

        if (validateQueue.length) {
            void drainValidateQueue();
            return;
        }

        setProgress(false);
        setRuntimeStatus(false);
        toast(
            fail
                ? `Selected validation finished · ${done - fail} ok · ${fail} failed`
                : `Selected validation complete · ${done} PO(s)`,
            fail ? 'info' : 'ok'
        );
    }

    function varianceCell(pct) {
        if (pct === null || pct === undefined || pct === '') return '—';
        const n = Number(pct);
        const cls = n > 0 ? 'is-bad' : 'is-good';
        return `<span class="pcb-var ${cls}">${n > 0 ? '+' : ''}${n.toFixed(1)}%</span>`;
    }

    function resultPill(status) {
        const s = String(status || '').toUpperCase();
        return `<span class="pcb-badge ${s === 'PASS' ? 'is-pass' : 'is-fail'}">${esc(s || '—')}</span>`;
    }

    function openOffcanvas() {
        const el = $('pcbOffcanvas');
        if (!el) return;
        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => el.classList.add('is-open'));
        document.body.classList.add('pcb-offcanvas-open');
        expandItemTableFully();
    }

    function closeOffcanvas() {
        const el = $('pcbOffcanvas');
        if (!el) return;
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pcb-offcanvas-open');
        window.setTimeout(() => {
            if (!el.classList.contains('is-open')) el.hidden = true;
        }, 280);
    }

    let activeItemIdx = -1;
    let activeItemRows = [];
    let filteredItemRows = [];
    let hiddenItemCols = new Set();
    /** @type {Record<string, string>} live column-filter values (source of truth) */
    const itemColFilterState = Object.create(null);

    const ITEM_COL_DEFS = [
        { key: 'check', label: 'Select', locked: true },
        { key: 'status', label: 'Status' },
        { key: 'po_item', label: 'PO Item' },
        { key: 'material', label: 'Material Code' },
        { key: 'description', label: 'Material Description' },
        { key: 'mat_category', label: 'Material Type' },
        { key: 'sales_order', label: 'Sales Order' },
        { key: 'po_qty', label: 'PO Qty' },
        { key: 'unit', label: 'UOM' },
        { key: 'currency', label: 'Currency' },
        { key: 'net_value', label: 'Net Value' },
        { key: 'costing_ref', label: 'Costing Ref' },
        { key: 'cost_section', label: 'Cost Section' },
        { key: 'cost_item', label: 'Cost Item' },
        { key: 'cost_description', label: 'ZCOST Description' },
        { key: 'expected_unit_cost', label: 'Expected Unit Cost' },
        { key: 'po_unit_cost', label: 'PO Unit Cost' },
        { key: 'difference', label: 'Difference' },
        { key: 'variance_pct', label: 'Variance %' },
        { key: 'sell_price', label: 'Sell Price' },
        { key: 'making', label: 'Making' },
        { key: 'quoted_uom', label: 'Quoted UOM' },
        { key: 'quoted_currency', label: 'Quoted Currency' },
        { key: 'validation_result', label: 'Validation Result' },
    ];

    function dlRow(label, value, extraClass) {
        return `<div class="pcw-kv"><dt>${esc(label)}</dt><dd class="${extraClass || ''}">${value}</dd></div>`;
    }

    function itemSearchQuery() {
        return String($('pcbItemSearch')?.value || '').trim().toLowerCase();
    }

    function itemColFilterMap() {
        // Prefer live state; fall back to DOM (e.g. after page restore)
        const map = {};
        Object.keys(itemColFilterState).forEach((key) => {
            const val = String(itemColFilterState[key] || '').trim();
            if (val) map[key] = val;
        });
        if (Object.keys(map).length) return map;
        document.querySelectorAll('#pcbItemFilterRow .pcb-item-col-filter').forEach((inp) => {
            const key = inp.getAttribute('data-key');
            const val = String(inp.value || '').trim();
            if (key && val) map[key] = val;
        });
        return map;
    }

    function setItemColFilterValue(inp) {
        if (!inp) return;
        const key = inp.getAttribute('data-key');
        if (!key) return;
        const val = String(inp.value || '').trim();
        if (val) itemColFilterState[key] = val;
        else delete itemColFilterState[key];
        inp.classList.toggle('has-val', !!val);
    }

    function expandItemTableFully() {
        const wrap = $('pcbItemTableWrap');
        if (!wrap) return;
        wrap.classList.remove('is-scroll-body', 'pcw-table-wrap');
        wrap.classList.add('is-expanded', 'pcb-oc-table-wrap');
        const detailOpen = !!document.querySelector('.pcb-offcanvas-body.has-item-detail');
        // When comparison is open, keep a compact scrollable table above it.
        // Otherwise show all rows; only horizontal scroll on the inner wrapper.
        if (detailOpen) {
            wrap.style.cssText = [
                'max-height:min(28vh,260px)!important',
                'height:auto!important',
                'width:100%!important',
                'max-width:100%!important',
                'min-width:0!important',
                'overflow:hidden!important',
            ].join(';');
            const x = wrap.querySelector('.pcb-oc-table-scrollx');
            if (x) {
                x.style.cssText = 'width:100%;max-width:100%;max-height:min(28vh,260px)!important;height:auto!important;overflow:auto!important;';
            }
            return;
        }
        wrap.style.cssText = [
            'max-height:none!important',
            'height:auto!important',
            'min-height:min-content!important',
            'width:100%!important',
            'max-width:100%!important',
            'min-width:0!important',
            'overflow:hidden!important',
        ].join(';');
        const x = wrap.querySelector('.pcb-oc-table-scrollx');
        if (x) {
            x.style.cssText = 'width:100%;max-width:100%;max-height:none!important;height:auto!important;overflow-x:auto;overflow-y:hidden;';
        }
    }

    function normNeedle(s) {
        return String(s || '').toLowerCase().replace(/,/g, '').replace(/\s+/g, ' ').trim();
    }

    function textMatches(haystack, needle) {
        const n = normNeedle(needle);
        if (!n) return true;
        const h = String(haystack ?? '');
        if (h.toLowerCase().includes(n)) return true;
        const h2 = normNeedle(h);
        if (h2.includes(n)) return true;
        // allow "exception" / "fail" / "pass" aliases for status pills
        return false;
    }

    function itemFieldCandidates(r, key) {
        const v = r?.[key];
        const raw = (v === null || v === undefined) ? '' : String(v);
        const out = [raw];
        switch (key) {
            case 'status':
                out.push(raw, raw === 'PASS' ? 'passed ok' : '', raw === 'EXCEPTION' ? 'fail failed exception' : '');
                break;
            case 'po_qty':
                out.push(qty(v), String(Number(v || 0)));
                break;
            case 'net_value':
            case 'expected_unit_cost':
            case 'po_unit_cost':
            case 'difference':
            case 'sell_price':
            case 'making':
                out.push(money(v), money2(v), String(Number(v || 0)));
                break;
            case 'variance_pct':
                if (v !== null && v !== undefined && v !== '') {
                    const n = Number(v);
                    out.push(`${n}`, `${n.toFixed(1)}`, `${n.toFixed(1)}%`, `${n > 0 ? '+' : ''}${n.toFixed(1)}%`);
                }
                break;
            default:
                break;
        }
        return out.filter((x) => x !== '');
    }

    function itemMatchesCol(r, key, val) {
        return itemFieldCandidates(r, key).some((c) => textMatches(c, val));
    }

    function itemSearchHay(r) {
        return [
            r.status, r.po_item, r.material, r.description, r.mat_category,
            r.material_group, r.sales_order, r.costing_ref, r.cost_section, r.cost_item,
            r.cost_description, r.validation_result, r.unit, r.currency,
            qty(r.po_qty), money2(r.net_value), money2(r.expected_unit_cost),
            money2(r.po_unit_cost), money2(r.difference), r.variance_pct,
        ].join(' ');
    }

    function updateItemFilterUi() {
        const q = itemSearchQuery();
        const cols = itemColFilterMap();
        const n = Object.keys(cols).length;
        const badge = $('pcbItemFilterBadge');
        if (badge) {
            badge.style.display = n ? '' : 'none';
            badge.textContent = String(n);
        }
        const clear = $('pcbItemClearFilters');
        if (clear) clear.style.display = (q || n) ? '' : 'none';
        document.querySelectorAll('#pcbItemFilterRow .pcb-item-col-filter').forEach((inp) => {
            inp.classList.toggle('has-val', !!String(inp.value || '').trim());
        });
    }

    function filterItemRows() {
        const q = itemSearchQuery();
        const cols = itemColFilterMap();
        filteredItemRows = activeItemRows.filter((r) => {
            if (q && !textMatches(itemSearchHay(r), q)) return false;
            for (const [key, val] of Object.entries(cols)) {
                if (!itemMatchesCol(r, key, val)) return false;
            }
            return true;
        });
        return filteredItemRows;
    }

    /** Strip trailing garment size so M/L/XL variants share one group key. */
    function styleBaseKey(desc) {
        let s = String(desc || '').toUpperCase().trim();
        s = s.replace(/[,.\s]+(XXXL|XXL|XL|XXS|XS|2XL|3XL|4XL|S|M|L)\s*$/i, '');
        s = s.replace(/[^A-Z0-9]+/g, ' ').trim().replace(/\s+/g, ' ');
        return s;
    }

    function normGroupToken(v) {
        return String(v || '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').trim();
    }

    function tokenLooksLikeMaterial(token, material) {
        const a = normGroupToken(token);
        const b = normGroupToken(material);
        if (!a || !b) return false;
        return a === b || b.includes(a) || a.includes(b);
    }

    /**
     * Group key: ZCOST Item (Label/Button/…) first, else SAP material group,
     * else same style base + unit cost (size variants).
     * Skip keys that are just the line’s own material code so size variants still collapse.
     */
    function itemGroupKey(r) {
        const costItem = String(r.cost_item || '').trim().toUpperCase();
        const costSection = String(r.cost_section || '').trim().toUpperCase();
        const matGroup = String(r.material_group || '').trim().toUpperCase();
        const material = String(r.material || '').trim();
        const expected = (r.expected_unit_cost === null || r.expected_unit_cost === undefined || r.expected_unit_cost === '')
            ? 'x'
            : String(Number(r.expected_unit_cost));
        const poUnit = String(Number(r.po_unit_cost || 0));
        const so = String(r.sales_order || '');
        if (costItem && !tokenLooksLikeMaterial(costItem, material)) {
            return ['CI', costSection, costItem, expected, so].join('|');
        }
        if (matGroup && !tokenLooksLikeMaterial(matGroup, material)) {
            return ['MG', matGroup, poUnit, so].join('|');
        }
        const base = styleBaseKey(r.description || r.material || '') || normGroupToken(material);
        return ['ST', base, poUnit, so].join('|');
    }

    function formatPoItemRange(items) {
        const nums = items
            .map((v) => Number(String(v).replace(/\D/g, '')))
            .filter((n) => !Number.isNaN(n))
            .sort((a, b) => a - b);
        if (!nums.length) return items.join(', ');
        const unique = [...new Set(nums)];
        if (unique.length === 1) return String(unique[0]);
        const sequential = unique.every((n, i) => i === 0 || n === unique[i - 1] + 10 || n === unique[i - 1] + 1);
        if (sequential && unique.length > 2) return `${unique[0]}–${unique[unique.length - 1]}`;
        return unique.join(', ');
    }

    function buildGroupedItemRow(members) {
        const rows = members.map((m) => m.r);
        const idxs = members.map((m) => m.idx);
        if (rows.length === 1) {
            return {
                ...rows[0],
                _group: false,
                _group_size: 1,
                _members: rows,
                _memberIdxs: idxs,
                _srcIdx: idxs[0],
            };
        }
        const materials = [...new Set(rows.map((r) => String(r.material || '').trim()).filter(Boolean))];
        const poItems = rows.map((r) => String(r.po_item || '').trim()).filter(Boolean);
        const anyFail = rows.some((r) => String(r.status || '').toUpperCase() !== 'PASS');
        const results = [...new Set(rows.map((r) => String(r.validation_result || '').trim()).filter(Boolean))];
        const qtySum = rows.reduce((s, r) => s + Number(r.po_qty || 0), 0);
        const netSum = rows.reduce((s, r) => s + Number(r.net_value || 0), 0);
        const first = rows[0];
        const groupLabel = String(first.cost_item || first.material_group || '').trim();
        const groupLabelIsMat = tokenLooksLikeMaterial(groupLabel, first.material || '');
        const labelForUi = groupLabel && !groupLabelIsMat ? groupLabel : '';
        const baseDesc = styleBaseKey(first.description || '') || String(first.description || '');
        const desc = first.cost_description
            || (labelForUi ? `${labelForUi} · ${baseDesc}` : baseDesc)
            || materials.join(', ');
        return {
            ...first,
            po_item: formatPoItemRange(poItems),
            material: materials.join(', '),
            description: desc,
            po_qty: qtySum,
            net_value: Math.round(netSum * 100) / 100,
            status: anyFail ? 'EXCEPTION' : 'PASS',
            validation_result: results.length <= 1 ? (results[0] || first.validation_result) : (anyFail ? 'Grouped — has exceptions' : 'PASS'),
            material_group: labelForUi || first.material_group || '',
            cost_item: labelForUi || first.cost_item || '',
            _checked: rows.every((r) => !!r._checked),
            _group: true,
            _group_size: rows.length,
            _members: rows,
            _memberIdxs: idxs,
            _srcIdx: idxs[0],
        };
    }

    /** Collapse matching material-group / Label / size-variant lines into one display row. */
    function groupItemRows(rows) {
        const map = new Map();
        const order = [];
        rows.forEach((r) => {
            const idx = activeItemRows.indexOf(r);
            const key = itemGroupKey(r);
            if (!map.has(key)) {
                map.set(key, []);
                order.push(key);
            }
            map.get(key).push({ r, idx });
        });
        return order.map((key) => buildGroupedItemRow(map.get(key)));
    }

    function clearItemFilters({ keepSearch = false } = {}) {
        if (!keepSearch && $('pcbItemSearch')) $('pcbItemSearch').value = '';
        Object.keys(itemColFilterState).forEach((k) => { delete itemColFilterState[k]; });
        document.querySelectorAll('#pcbItemFilterRow .pcb-item-col-filter').forEach((inp) => {
            inp.value = '';
            inp.classList.remove('has-val');
        });
    }

    function runItemFilters() {
        renderItemTable();
        afterItemFilterRender();
    }

    function afterItemFilterRender() {
        if (filteredItemRows.length) {
            // Only keep/update an already-open comparison; don't auto-open on filter type
            if (activeItemIdx >= 0) {
                if (filteredItemRows.includes(activeItemRows[activeItemIdx])) {
                    showItemDetail(activeItemIdx, { scroll: false });
                } else {
                    const firstIdx = activeItemRows.indexOf(filteredItemRows[0]);
                    if (firstIdx >= 0) showItemDetail(firstIdx, { scroll: true });
                }
            }
        } else if (activeItemIdx >= 0) {
            hideItemDetail();
        }
    }

    function applyItemColumnVisibility() {
        const table = $('pcbItemTable');
        if (!table) return;
        const headerCells = table.querySelectorAll('tr.pcb-item-header-row th');
        const filterCells = table.querySelectorAll('tr.pcb-item-filter-row th');
        headerCells.forEach((th, i) => {
            const key = th.getAttribute('data-icol');
            const hide = key ? hiddenItemCols.has(key) : false;
            th.style.display = hide ? 'none' : '';
            if (filterCells[i]) filterCells[i].style.display = hide ? 'none' : '';
        });
        table.querySelectorAll('tbody tr').forEach((tr) => {
            if (tr.classList.contains('pcw-empty')) return;
            const tds = tr.children;
            headerCells.forEach((th, i) => {
                const key = th.getAttribute('data-icol');
                if (!tds[i]) return;
                tds[i].style.display = (key && hiddenItemCols.has(key)) ? 'none' : '';
            });
        });
    }

    function buildItemColumnMenu() {
        const menu = $('pcbItemColumnMenu');
        if (!menu) return;
        const visible = ITEM_COL_DEFS.filter((c) => !c.locked && !hiddenItemCols.has(c.key)).length;
        const optional = ITEM_COL_DEFS.filter((c) => !c.locked).length;
        menu.innerHTML =
            `<div class="pcb-col-menu-head">
                <div>
                    <strong>Columns</strong>
                    <small>${visible} of ${optional} visible</small>
                </div>
                <div class="pcb-col-menu-actions">
                    <button type="button" class="pcb-col-menu-btn" data-pcb-icol-act="all">Show all</button>
                    <button type="button" class="pcb-col-menu-btn" data-pcb-icol-act="reset">Reset</button>
                </div>
            </div>
            <div class="pcb-col-menu-list">` +
            ITEM_COL_DEFS.map((c) => {
                const checked = !hiddenItemCols.has(c.key);
                const disabled = c.locked ? ' disabled' : '';
                return `<label class="rpt-cat-dd-item pcb-col-menu-item${c.locked ? ' is-locked' : ''}">
                    <input type="checkbox" data-icol="${esc(c.key)}" ${checked ? 'checked' : ''}${disabled}>
                    <span>${esc(c.label)}</span>
                    ${c.locked ? '<em>Fixed</em>' : ''}
                </label>`;
            }).join('') +
            `</div>`;

        menu.querySelectorAll('[data-pcb-icol-act]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const act = btn.getAttribute('data-pcb-icol-act');
                if (act === 'all' || act === 'reset') {
                    hiddenItemCols.clear();
                    buildItemColumnMenu();
                    applyItemColumnVisibility();
                }
            });
        });
        menu.querySelectorAll('input[data-icol]').forEach((inp) => {
            inp.addEventListener('change', () => {
                const key = inp.getAttribute('data-icol');
                if (!key) return;
                if (inp.checked) hiddenItemCols.delete(key);
                else hiddenItemCols.add(key);
                applyItemColumnVisibility();
                buildItemColumnMenu();
            });
        });
    }

    function renderItemTable() {
        const body = $('pcbItemBody');
        if (!body) return;
        const filtered = filterItemRows();
        const items = groupItemRows(filtered);
        updateItemFilterUi();
        if ($('pcbItemCount')) {
            if (!filtered.length) {
                $('pcbItemCount').textContent = 'No items';
            } else if (items.length === filtered.length) {
                $('pcbItemCount').textContent = `Showing ${filtered.length} of ${activeItemRows.length} items`;
            } else {
                $('pcbItemCount').textContent =
                    `Showing ${items.length} groups · ${filtered.length} of ${activeItemRows.length} items`;
            }
            $('pcbItemCount').classList.toggle('is-filtered', !!(itemSearchQuery() || Object.keys(itemColFilterMap()).length));
        }
        if (!items.length) {
            body.innerHTML = `<tr class="pcw-empty"><td colspan="24">${
                activeItemRows.length ? 'No items match the filters.' : (runtimeRunning ? 'Validation in progress…' : 'Not validated yet. Select this PO to validate.')
            }</td></tr>`;
            hideItemDetail();
            applyItemColumnVisibility();
            return;
        }

        body.innerHTML = items.map((r) => {
            const idx = r._srcIdx != null ? r._srcIdx : activeItemRows.indexOf(r);
            const active = (r._memberIdxs || [idx]).includes(activeItemIdx) ? ' is-active' : '';
            const groupBadge = r._group
                ? `<span class="pcb-group-badge" title="${esc(String(r._group_size))} matching lines">${esc(String(r._group_size))}×</span>`
                : '';
            const matGroup = r.cost_item || r.material_group || '';
            return `<tr class="pcw-row${active}${r._group ? ' is-group' : ''}" data-idx="${idx}" data-member-idxs="${esc((r._memberIdxs || [idx]).join(','))}">
                <td class="pcw-check"><input type="checkbox" class="pcb-item-check" data-idx="${idx}" data-member-idxs="${esc((r._memberIdxs || [idx]).join(','))}" ${r._checked ? 'checked' : ''}></td>
                <td>${resultPill(r.status)}</td>
                <td>${dash(r.po_item)}</td>
                <td class="pcw-mat" title="${esc(r.material)}">${dash(r.material)}</td>
                <td title="${esc(r.description)}">${dash(r.description)}${groupBadge}</td>
                <td>${dash(r.mat_category)}</td>
                <td>${dash(r.sales_order)}</td>
                <td class="num">${qty(r.po_qty)}</td>
                <td>${dash(r.unit)}</td>
                <td>${dash(r.currency)}</td>
                <td class="num">${money2(r.net_value)}</td>
                <td title="${esc(r.costing_ref)}">${dash(r.costing_ref)}</td>
                <td>${dash(r.cost_section)}</td>
                <td title="${esc(matGroup)}">${dash(matGroup)}</td>
                <td title="${esc(r.cost_description)}">${dash(r.cost_description)}</td>
                <td class="num">${money2(r.expected_unit_cost)}</td>
                <td class="num">${money2(r.po_unit_cost)}</td>
                <td class="num">${money2(r.difference)}</td>
                <td class="num">${varianceCell(r.variance_pct)}</td>
                <td class="num">${money2(r.sell_price)}</td>
                <td class="num">${money2(r.making)}</td>
                <td>${dash(r.quoted_uom)}</td>
                <td>${dash(r.quoted_currency)}</td>
                <td>${dash(r.validation_result)}</td>
            </tr>`;
        }).join('');

        if ($('pcbItemSelectAll')) {
            const allOn = filtered.length && filtered.every((r) => r._checked);
            $('pcbItemSelectAll').checked = allOn;
            $('pcbItemSelectAll').indeterminate = !allOn && filtered.some((r) => r._checked);
        }
        applyItemColumnVisibility();
        expandItemTableFully();
    }

    function exportItemCsv() {
        const rows = filterItemRows();
        if (!rows.length) {
            toast('Nothing to export.', 'error');
            return;
        }
        const headers = [
            'Status', 'PO Item', 'Material Code', 'Material Description', 'Material Type',
            'Sales Order', 'PO Qty', 'UOM', 'Currency', 'Net Value', 'Costing Ref',
            'Cost Section', 'Cost Item', 'ZCOST Description', 'Expected Unit Cost',
            'PO Unit Cost', 'Difference', 'Variance %', 'Sell Price', 'Making',
            'Quoted UOM', 'Quoted Currency', 'Validation Result',
        ];
        const lines = [headers.join(',')];
        rows.forEach((r) => {
            lines.push([
                r.status, r.po_item, r.material, r.description, r.mat_category,
                r.sales_order, r.po_qty, r.unit, r.currency, r.net_value, r.costing_ref,
                r.cost_section, r.cost_item, r.cost_description, r.expected_unit_cost,
                r.po_unit_cost, r.difference, r.variance_pct, r.sell_price, r.making,
                r.quoted_uom, r.quoted_currency, r.validation_result,
            ].map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `po-costing-items-${activePo || 'export'}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
        toast('Item Excel/CSV downloaded.', 'ok');
    }

    function fillOffcanvasKpis(summary, fallbackValue) {
        const s = summary || {};
        if ($('pcbOcKpiItems')) $('pcbOcKpiItems').textContent = qty(s.po_items || 0);
        if ($('pcbOcKpiPassed')) $('pcbOcKpiPassed').textContent = qty(s.passed || 0);
        if ($('pcbOcKpiExceptions')) $('pcbOcKpiExceptions').textContent = qty(s.exceptions || 0);
        if ($('pcbOcKpiValue')) {
            const val = s.total_po_value != null ? s.total_po_value : fallbackValue;
            $('pcbOcKpiValue').textContent = '₹ ' + money(val || 0);
        }
    }

    function setItemDetailBodyState(on) {
        const body = document.querySelector('.pcb-offcanvas-body');
        if (body) body.classList.toggle('has-item-detail', !!on);
    }

    function hideItemDetail() {
        activeItemIdx = -1;
        const detail = $('pcbItemDetail');
        if (detail) {
            detail.hidden = true;
            detail.classList.remove('is-open');
        }
        setItemDetailBodyState(false);
        document.querySelectorAll('#pcbItemBody tr.pcw-row').forEach((tr) => tr.classList.remove('is-active'));
        expandItemTableFully();
    }

    function revealItemDetail(detail) {
        if (!detail) return;
        const body = detail.closest('.pcb-offcanvas-body');
        requestAnimationFrame(() => {
            // Prefer keeping the full comparison cards visible under the table
            detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (body) {
                const pad = 8;
                const top = Math.max(0, detail.offsetTop - pad);
                const bottom = detail.offsetTop + detail.offsetHeight + pad;
                const viewTop = body.scrollTop;
                const viewBottom = viewTop + body.clientHeight;
                if (bottom > viewBottom || top < viewTop) {
                    body.scrollTo({ top: Math.max(0, bottom - body.clientHeight), behavior: 'smooth' });
                }
            }
            const active = document.querySelector('#pcbItemBody tr.pcw-row.is-active');
            active?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }

    function showItemDetail(idx, opts = {}) {
        activeItemIdx = idx;
        const r = activeItemRows[idx];
        const detail = $('pcbItemDetail');
        if (!r || !detail) {
            if (detail) {
                detail.hidden = true;
                detail.classList.remove('is-open');
            }
            setItemDetailBodyState(false);
            return;
        }
        detail.hidden = false;
        detail.classList.add('is-open');
        setItemDetailBodyState(true);

        const groupRows = (() => {
            const key = itemGroupKey(r);
            return activeItemRows.filter((x) => itemGroupKey(x) === key);
        })();
        const groupMats = [...new Set(groupRows.map((x) => String(x.material || '').trim()).filter(Boolean))];
        const groupItems = groupRows.map((x) => String(x.po_item || '').trim()).filter(Boolean);
        const groupQty = groupRows.reduce((s, x) => s + Number(x.po_qty || 0), 0);
        const groupLabel = String(r.cost_item || r.material_group || '').trim();
        const matLabel = groupRows.length > 1
            ? (groupLabel || groupMats.join(', ') || r.description || '—')
            : ([r.material, r.description].filter(Boolean).join(' - ') || '—');
        if ($('pcbItemDetailMeta')) {
            $('pcbItemDetailMeta').textContent = groupRows.length > 1
                ? `Group: ${groupLabel || 'Matching items'} · ${groupRows.length} lines · PO Items ${formatPoItemRange(groupItems)} · SO ${r.sales_order || '—'}`
                : `PO Item: ${r.po_item || '—'} | Material: ${matLabel} | Sales Order: ${r.sales_order || '—'}`;
        }
        if ($('pcbItemDetailSub')) {
            $('pcbItemDetailSub').textContent = groupRows.length > 1
                ? `${groupLabel || 'Group'} · ${groupMats.slice(0, 4).join(', ')}${groupMats.length > 4 ? '…' : ''} · SO ${r.sales_order || '—'}`
                : `PO Item ${r.po_item || '—'} · ${r.material || '—'} · SO ${r.sales_order || '—'}`;
        }

        const quote = $('pcbPanelQuote');
        const po = $('pcbPanelPo');
        const diff = $('pcbPanelDiff');
        const info = $('pcbPanelInfo');
        const cache = itemCache[activePo] || {};
        const quoteDate = r.quotation_date || r.quote_date || r.costing_date || '';
        const updatedAt = cache._validated_at || new Date().toLocaleString('en-IN');
        const updatedBy = cache._validated_by || currentUserName() || '—';

        if (quote) {
            quote.innerHTML =
                dlRow('Quotation Reference', dash(r.costing_ref)) +
                dlRow('Quoted Unit Cost', '<strong>₹ ' + money2(r.expected_unit_cost) + '</strong>') +
                dlRow('Currency', dash(r.quoted_currency || 'INR')) +
                dlRow('UOM', dash(r.quoted_uom || r.unit)) +
                dlRow('Quotation Date', quoteDate ? (fmtDate(quoteDate) !== '—' ? fmtDate(quoteDate) : dash(quoteDate)) : '—');
        }
        if (po) {
            po.innerHTML =
                dlRow('PO Number', dash(r.purchase_order || activePo)) +
                dlRow('PO Item', dash(groupRows.length > 1 ? formatPoItemRange(groupItems) : r.po_item)) +
                dlRow('PO Unit Cost', '<strong>₹ ' + money2(r.po_unit_cost) + '</strong>') +
                dlRow('Currency', dash(r.currency || 'INR')) +
                dlRow('UOM', dash(r.unit)) +
                dlRow('PO Qty', qty(groupRows.length > 1 ? groupQty : r.po_qty));
        }
        if (diff) {
            const d = r.difference;
            const dCls = d === null || d === undefined || d === '' ? '' : (Number(d) > 0 ? 'is-bad' : 'is-good');
            const dText =
                d === null || d === undefined || d === ''
                    ? '—'
                    : `${Number(d) > 0 ? '+ ' : '- '}₹ ${money2(Math.abs(Number(d)))} [${r.variance_pct > 0 ? '+' : ''}${r.variance_pct ?? '—'}%]`;
            diff.innerHTML =
                dlRow('Expected Unit Cost', '₹ ' + money2(r.expected_unit_cost)) +
                dlRow('PO Unit Cost', '₹ ' + money2(r.po_unit_cost)) +
                dlRow('Difference', dText, dCls) +
                dlRow('Variance %', varianceCell(r.variance_pct)) +
                dlRow('Validation Result', resultPill(r.status));
        }
        if (info) {
            info.innerHTML =
                dlRow('Sales Order', dash(r.sales_order)) +
                dlRow('Material Group', dash(groupLabel || '—')) +
                dlRow('Material Description', dash(r.description)) +
                (groupRows.length > 1
                    ? dlRow('Grouped PO Items', dash(formatPoItemRange(groupItems))) +
                      dlRow('Grouped Materials', dash(groupMats.join(', '))) +
                      dlRow('Lines in group', String(groupRows.length))
                    : '') +
                dlRow('Last Updated', dash(updatedAt)) +
                dlRow('Updated By', dash(updatedBy));
        }

        document.querySelectorAll('#pcbItemBody tr.pcw-row').forEach((tr) => {
            const members = String(tr.getAttribute('data-member-idxs') || tr.getAttribute('data-idx') || '')
                .split(',')
                .map((v) => Number(v));
            tr.classList.toggle('is-active', members.includes(idx));
        });

        expandItemTableFully();
        if (opts.scroll !== false) revealItemDetail(detail);
    }

    function renderDrill(po) {
        const prevPo = activePo;
        const keepIdx = (prevPo === po && activeItemIdx >= 0) ? activeItemIdx : -1;
        activePo = po;
        openOffcanvas();
        const row = allRows.find((r) => r.purchase_order === po);
        const data = itemCache[po];
        if ($('pcbDrillTitle')) $('pcbDrillTitle').textContent = `PO ${po}`;
        if ($('pcbDrillSub')) {
            $('pcbDrillSub').textContent = data
                ? `${data.summary?.passed || 0} passed · ${data.summary?.exceptions || 0} exceptions · ${data._validated_at || ''}`
                : (runtimeRunning ? 'Validation in progress…' : 'Loading ZCOST validation…');
        }
        const wb = $('pcbOpenWorkbench');
        if (wb) {
            wb.hidden = false;
            wb.href = workbenchUrl(po);
        }

        const items = Array.isArray(data?.records) ? data.records.map((r) => ({ ...r, _checked: !!r._checked })) : [];
        activeItemRows = items;
        fillOffcanvasKpis(data?.summary, row?.total_value);
        renderItemTable();
        // Keep comparison open across refresh of the same PO; otherwise wait for row click
        if (keepIdx >= 0 && items[keepIdx]) {
            showItemDetail(keepIdx, { scroll: false });
        } else if (prevPo !== po) {
            hideItemDetail();
        }
        renderTable();
    }

    async function openDrill(po) {
        if (!po) return;
        const switching = activePo && activePo !== po;
        activePo = po;
        if (switching) {
            activeItemIdx = -1;
            clearItemFilters();
            hideItemDetail();
        }
        renderDrill(po);
        if (!itemCache[po]) {
            try {
                await validateOne(po);
                syncRow(po);
                fillKpis();
                if (activePo === po) renderDrill(po);
            } catch (err) {
                if ($('pcbDrillSub')) $('pcbDrillSub').textContent = err.message || 'Validation failed';
                toast(err.message || 'Validation failed', 'error');
            }
        }
    }

    function exportCsv() {
        if (!filtered.length) {
            toast('Nothing to export.', 'error');
            return;
        }
        const headers = ['PO Number','PO Date','Vendor','Vendor Name','Material Type','Items','PO Value','Passed','Exceptions','Pending','Validation Status','Last Validated','Validated By'];
        const lines = [headers.join(',')];
        filtered.forEach((r) => {
            lines.push([
                r.purchase_order, r.date, r.supplier, r.supplier_full_name, r._mat_label,
                r.items_count, r.total_value, r._passed, r._exceptions, r._pending,
                r._validation_status, r._last_validated, r._validated_by,
            ].map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'bulk-po-costing-validation.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        toast('Export downloaded.', 'ok');
    }

    function setChecked(po, on) {
        const row = allRows.find((r) => r.purchase_order === po);
        if (row) row._checked = on;
        if (on && po) enqueueSelectedValidation([po]);
    }

    function bind() {
        $('pcbFilterForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            // Enter in PO search should open that PO, not refetch list
            if (document.activeElement && document.activeElement.id === 'pcbPoSearch') {
                searchPoHeader();
                return;
            }
            void fetchPos(e);
        });
        $('pcbRange')?.addEventListener('change', () => {
            applyRangePreset(currentRange(), { fillDates: true });
            if (currentRange() !== 'custom') void fetchPos();
        });
        $('pcbFrom')?.addEventListener('change', () => {
            if (currentRange() === 'custom') void fetchPos();
        });
        $('pcbTo')?.addEventListener('change', () => {
            if (currentRange() === 'custom') void fetchPos();
        });
        $('pcbMat')?.addEventListener('change', () => void fetchPos());
        $('pcbSupplier')?.addEventListener('change', () => void fetchPos());
        $('pcbPoSearchBtn')?.addEventListener('click', () => searchPoHeader());
        $('pcbPoSearch')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchPoHeader();
            }
        });
        $('pcbResetBtn')?.addEventListener('click', () => {
            if ($('pcbRange')) $('pcbRange').value = 'this_month';
            applyRangePreset('this_month', { fillDates: true });
            if ($('pcbSupplier')) $('pcbSupplier').value = '';
            if ($('pcbValStatus')) $('pcbValStatus').value = 'all';
            if ($('pcbMat')) $('pcbMat').value = 'all';
            if ($('pcbPoSearch')) $('pcbPoSearch').value = '';
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            document.querySelectorAll('.pcb-col-filter').forEach((inp) => { inp.value = ''; });
            stopRuntimeValidation();
            void fetchPos();
        });
        $('pcbQuickSearch')?.addEventListener('input', () => applyFilter());
        $('pcbQuickSearchClear')?.addEventListener('click', () => {
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            applyFilter();
        });
        $('pcbValStatus')?.addEventListener('change', () => applyFilter());
        document.querySelectorAll('.pcb-col-filter').forEach((inp) => {
            inp.addEventListener('input', () => applyFilter());
        });
        $('pcbColFilterReset')?.addEventListener('click', () => {
            document.querySelectorAll('.pcb-col-filter').forEach((inp) => { inp.value = ''; });
            applyFilter();
        });
        $('pcbClearAllFilters')?.addEventListener('click', () => {
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            document.querySelectorAll('.pcb-col-filter').forEach((inp) => { inp.value = ''; });
            applyFilter();
        });
        $('pcbToggleColFilters')?.addEventListener('click', () => {
            const row = $('pcbFilterRow');
            const btn = $('pcbToggleColFilters');
            if (!row) return;
            const show = row.style.display === 'none';
            row.style.display = show ? '' : 'none';
            btn?.classList.toggle('is-active', show);
        });
        $('pcbToggleStickyScroll')?.addEventListener('click', () => {
            const wrap = $('pcbTableWrap');
            if (!wrap) return;
            applyListStickyMode(!wrap.classList.contains('is-scroll-body'));
        });
        applyListStickyMode(false); // default: expand — show all page rows
        $('pcbTable')?.querySelector('thead')?.addEventListener('click', (e) => {
            const th = e.target.closest('th.is-sortable');
            if (!th || e.target.closest('input,button,a,label')) return;
            const key = th.getAttribute('data-sort');
            if (key) toggleSort(key);
        });
        $('pcbColumnToggleBtn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            const dd = $('pcbColumnToggleDropdown');
            const open = !dd?.classList.contains('is-open');
            dd?.classList.toggle('is-open', open);
            $('pcbColumnToggleBtn')?.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) buildColumnMenu();
        });
        document.addEventListener('click', (e) => {
            const dd = $('pcbColumnToggleDropdown');
            if (dd && !dd.contains(e.target)) {
                dd.classList.remove('is-open');
                $('pcbColumnToggleBtn')?.setAttribute('aria-expanded', 'false');
            }
        });
        $('pcbExportBtn')?.addEventListener('click', exportCsv);
        $('pcbPrev')?.addEventListener('click', () => { if (page > 1) { page--; renderTable(); } });
        $('pcbNext')?.addEventListener('click', () => {
            const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
            if (page < pages) { page++; renderTable(); }
        });
        $('pcbSelectAll')?.addEventListener('change', (e) => {
            const on = !!e.target.checked;
            const pagePos = pageRows().map((r) => r.purchase_order);
            const set = new Set(pagePos);
            allRows.forEach((r) => { if (set.has(r.purchase_order)) r._checked = on; });
            renderTable();
            if (on) enqueueSelectedValidation(pagePos);
        });

        const onListClick = (e) => {
            const check = e.target.closest('.pcb-row-check, .pcb-card-check');
            if (check) {
                const input = check.matches('input') ? check : check.querySelector('input');
                if (input) {
                    setChecked(input.getAttribute('data-po'), input.checked);
                    e.stopPropagation();
                    if ($('pcbSelectAll')) {
                        const rows = pageRows();
                        const pageChecked = rows.length && rows.every((r) => r._checked);
                        $('pcbSelectAll').checked = pageChecked;
                        $('pcbSelectAll').indeterminate = !pageChecked && rows.some((r) => r._checked);
                    }
                }
                return;
            }
            const poBtn = e.target.closest('[data-po].pcb-drill-btn, button.pcb-po-link[data-po]');
            if (poBtn) {
                e.preventDefault();
                const po = poBtn.getAttribute('data-po');
                if (po) void openDrill(po);
                return;
            }
            const row = e.target.closest('tr.pcb-row, article.pcb-card');
            if (!row) return;
            const po = row.getAttribute('data-po');
            if (po) void openDrill(po);
        };
        $('pcbTableBody')?.addEventListener('click', onListClick);
        $('pcbCards')?.addEventListener('click', onListClick);

        $('pcbOffcanvasClose')?.addEventListener('click', closeOffcanvas);
        $('pcbOffcanvasBackdrop')?.addEventListener('click', closeOffcanvas);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeOffcanvas();
        });
        $('pcbItemSearch')?.addEventListener('input', () => runItemFilters());
        // Capture-phase so column filters always update state + re-render
        document.addEventListener('input', (e) => {
            const inp = e.target && e.target.closest ? e.target.closest('#pcbItemFilterRow .pcb-item-col-filter') : null;
            if (!inp) return;
            setItemColFilterValue(inp);
            runItemFilters();
        }, true);
        $('pcbItemFilterRow')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') e.preventDefault();
            if (e.key === 'Escape') {
                const inp = e.target.closest?.('.pcb-item-col-filter');
                if (inp) {
                    inp.value = '';
                    setItemColFilterValue(inp);
                    runItemFilters();
                }
            }
        });
        $('pcbItemColFilterReset')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearItemFilters({ keepSearch: true });
            runItemFilters();
        });
        $('pcbItemClearFilters')?.addEventListener('click', () => {
            clearItemFilters();
            runItemFilters();
        });
        $('pcbItemToggleFilters')?.addEventListener('click', () => {
            const row = $('pcbItemFilterRow');
            const btn = $('pcbItemToggleFilters');
            if (!row) return;
            const show = row.style.display === 'none';
            row.style.display = show ? '' : 'none';
            btn?.classList.toggle('is-active', show);
        });
        expandItemTableFully();
        $('pcbItemColumnBtn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            const dd = $('pcbItemColumnDropdown');
            const open = !dd?.classList.contains('is-open');
            dd?.classList.toggle('is-open', open);
            $('pcbItemColumnBtn')?.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) buildItemColumnMenu();
        });
        document.addEventListener('click', (e) => {
            const dd = $('pcbItemColumnDropdown');
            if (dd && !dd.contains(e.target)) {
                dd.classList.remove('is-open');
                $('pcbItemColumnBtn')?.setAttribute('aria-expanded', 'false');
            }
        });
        $('pcbItemExcelBtn')?.addEventListener('click', exportItemCsv);
        $('pcbItemSelectAll')?.addEventListener('change', (e) => {
            const on = !!e.target.checked;
            filterItemRows().forEach((r) => { r._checked = on; });
            renderItemTable();
        });
        $('pcbItemDetailClose')?.addEventListener('click', () => hideItemDetail());
        $('pcbItemBody')?.addEventListener('click', (e) => {
            const check = e.target.closest('.pcb-item-check');
            if (check) {
                const idxs = String(check.getAttribute('data-member-idxs') || check.getAttribute('data-idx') || '')
                    .split(',')
                    .map((v) => Number(v))
                    .filter((n) => !Number.isNaN(n));
                idxs.forEach((i) => {
                    if (activeItemRows[i]) activeItemRows[i]._checked = check.checked;
                });
                const primary = idxs[0];
                if (check.checked && primary != null) {
                    showItemDetail(primary, { scroll: true });
                } else {
                    renderItemTable();
                }
                e.stopPropagation();
                return;
            }
            const tr = e.target.closest('tr.pcw-row');
            if (!tr) return;
            const idx = Number(tr.getAttribute('data-idx'));
            if (Number.isNaN(idx)) return;
            // Toggle closed if same row clicked again
            if (idx === activeItemIdx && !$('pcbItemDetail')?.hidden) {
                hideItemDetail();
                return;
            }
            showItemDetail(idx, { scroll: true });
        });
    }

    bind();
    applyRangePreset(currentRange(), { fillDates: currentRange() !== 'custom' });
    void fetchPos();
})();
