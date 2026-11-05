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
    const qty = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
    const num = (n, dig = 4) => {
        if (n === null || n === undefined || n === '') return '—';
        const v = Number(n);
        if (!Number.isFinite(v)) return '—';
        return v.toLocaleString('en-IN', { maximumFractionDigits: dig });
    };
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));
    const fmtDate = (iso) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        return m ? `${m[3]}-${m[2]}-${m[1]}` : '—';
    };

    /*
    * Handle zCOST View URL Functionality
    */
    function padPosnr(v) {
        const digits = String(v ?? '').replace(/\D/g, '');
        if (!digits) return '000010';
        return digits.padStart(6, '0').slice(-6);
    }

    function zcostViewUrl(quote, itemNo) {
        const raw = String(quote ?? '').trim();
        const q = (raw.match(/\d+/) || [raw])[0];
        if (!q) return '';
        const item = String(itemNo ?? '').trim();
        const posnr = /^\d+$/.test(item.replace(/^0+/, '') || item) ? padPosnr(item) : '000010';
        const configured = String(cfg.zcostUrl || cfg.zcostAppUrl || '').trim();
        const base = configured || new URL('zcost', window.location.href.replace(/\/[^/]*$/, '/')).pathname;
        try {
            const u = new URL(base, window.location.origin);
            u.searchParams.set('vbeln', q);
            u.searchParams.set('posnr', posnr);
            u.searchParams.set('view', '1');
            return u.href;
        } catch (err) {
            return `zcost?vbeln=${encodeURIComponent(q)}&posnr=${encodeURIComponent(posnr)}&view=1`;
        }
    }

    function quotationLinkHtml(quote, itemNo) {
        const q = String(quote ?? '').trim();
        if (!q) return '—';
        const href = zcostViewUrl(q, itemNo);
        return `<a class="pcw-quote-link" href="${esc(href)}" data-quote="${esc(q)}" target="_blank" rel="noopener noreferrer" title="Open zCOST Sales Costing Sheet">${esc(q)}</a>`;
    }

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
        { key: 'sno', label: 'S.No', locked: true },
        { key: 'po', label: 'PONO' },
        { key: 'date', label: 'PO Date' },
        { key: 'material', label: 'Material Type' },
        { key: 'items', label: 'No. of Items' },
        { key: 'vendor_name', label: 'Vendor Name' },
        { key: 'vendor_code', label: 'Vendor Code' },
        { key: 'passed', label: 'Passed' },
        { key: 'exceptions', label: 'Exceptions' },
        { key: 'pending', label: 'Pending' },
        { key: 'status', label: 'Validation Status' },
        { key: 'so_qty', label: 'Total SO Qty' },
        { key: 'prwise_so_qty', label: 'Total PRWISE_SO_QTY' },
        { key: 'consumption', label: 'Total Consumption' },
        { key: 'reqqty', label: 'Total Req Qty' },
        { key: 'tot_poqty', label: 'Total PO Qty' },
        { key: 'so_wise_poqty', label: 'Total SO Wise PO Qty' },
        { key: 'so_wise_po_val', label: 'Total SO Wise PO Val' },
        { key: 'totvalue', label: 'Total Value' },
        { key: 'po_currency', label: 'PO Currency' },
        { key: 'budgeted_cons', label: 'Total BUDGETED_CONS' },
        { key: 'budget_inr', label: 'Total BUDGET_INR' },
        { key: 'budget_cost_per_gmt', label: 'Total BUDGET_COST_PER_GMT' },
        { key: 'conv', label: 'Total CONV' },
        { key: 'actual_cost', label: 'Total ACTUAL_COST' },
        { key: 'pdf', label: 'View PDF', locked: true },
        { key: 'last', label: 'Last Validated' },
    ];
    let hiddenCols = new Set();
    let pcbTablePrefManager = null;
    let pcbItemTablePrefManager = null;
    let currentSort = { key: null, dir: null };
    const NUMERIC_SORT_KEYS = new Set([
        'sno', 'items', 'passed', 'exceptions', 'pending',
        'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'totvalue',
        'budgeted_cons', 'budget_inr', 'budget_cost_per_gmt', 'conv', 'actual_cost',
    ]);
    const MONEY_KEYS = new Set(['so_wise_po_val', 'totvalue', 'budget_inr']);
    const RATE_KEYS = new Set(['consumption', 'budgeted_cons', 'budget_cost_per_gmt', 'conv', 'actual_cost']);
    const colFilterState = Object.create(null);
    let assignedUser = { id: '', username: '' };
    let validateTargetPos = [];

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
        const radio = document.querySelector('input[name="pcb_mat"]:checked');
        const val = radio?.value || $('pcbMat')?.value || 'all';
        if ($('pcbMat') && $('pcbMat').value !== val) $('pcbMat').value = val;
        return val || 'all';
    }

    function workbenchUrl(po) {
        const u = new URL(cfg.pageUrl || 'purchase-order', window.location.origin);
        u.searchParams.set('view', 'costing');
        u.searchParams.set('po', po);
        const mat = selectedMat();
        if (mat !== 'all') u.searchParams.set('mat_cat', mat);
        return u.pathname + u.search;
    }

    function pdfUrl(po) {
        const u = new URL(cfg.pdfUrl || 'purchase-order/pdf', window.location.origin);
        u.searchParams.set('po', po);
        return u.pathname + u.search;
    }

    function statusBadge(st) {
        const s = String(st || 'PENDING').toUpperCase();
        let cls = 'is-pending';
        let label = s;
        if (s === 'PASSED') cls = 'is-pass';
        else if (s === 'EXCEPTION') cls = 'is-fail';
        else if (s === 'NEEDS_REVIEW') {
            cls = 'is-review';
            label = 'NEEDS REVIEW';
        }
        return `<span class="pcb-badge ${cls}">${esc(label)}</span>`;
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
        let passed = 0, exceptions = 0, needsReview = 0, pending = 0;
        let validationStatus = 'PENDING';
        let lastValidated = '';
        let validatedBy = '';
        if (cached && Array.isArray(cached.records)) {
            cached.records.forEach((r) => {
                const st = String(r.status || '').toUpperCase();
                if (st === 'PASS') passed++;
                else if (st === 'NEEDS_REVIEW') needsReview++;
                else exceptions++;
            });
            pending = 0;
            if (exceptions > 0) validationStatus = 'EXCEPTION';
            else if (needsReview > 0) validationStatus = 'NEEDS_REVIEW';
            else if (passed > 0) validationStatus = 'PASSED';
            else validationStatus = 'PENDING';
            lastValidated = cached._validated_at || '';
            validatedBy = cached._validated_by || '';
        } else {
            pending = Number(rec.items_count || 0) || 1;
            validationStatus = 'PENDING';
        }
        const totals = totalsFromRecords(cached?.records) || rec._totals || emptyTotals();
        return {
            ...rec,
            _checked: !!rec._checked,
            _mat_label: materialTypeLabel(rec),
            _passed: passed,
            _exceptions: exceptions,
            _needs_review: needsReview,
            _pending: pending,
            _validation_status: validationStatus,
            _last_validated: lastValidated,
            _validated_by: validatedBy,
            _totals: totals,
            so_qty: totals.so_qty,
            prwise_so_qty: totals.prwise_so_qty,
            consumption: totals.consumption,
            reqqty: totals.reqqty,
            tot_poqty: totals.tot_poqty,
            so_wise_poqty: totals.so_wise_poqty,
            so_wise_po_val: totals.so_wise_po_val,
            totvalue: totals.totvalue,
            po_currency: totals.po_currency || rec.currency || rec.po_currency || 'INR',
            budgeted_cons: totals.budgeted_cons,
            budget_inr: totals.budget_inr,
            budget_cost_per_gmt: totals.budget_cost_per_gmt,
            conv: totals.conv,
            actual_cost: totals.actual_cost,
            _po_items: totals.po_items,
            _po_materials: totals.po_materials,
            _z_items: totals.z_items,
            _z_materials: totals.z_materials,
            _z_so_qty: totals.z_so_qty,
            _z_net_value: totals.z_net_value,
        };
    }

    function emptyTotals() {
        return {
            so_qty: 0, prwise_so_qty: 0, consumption: 0, reqqty: 0, tot_poqty: 0,
            so_wise_poqty: 0, so_wise_po_val: 0, totvalue: 0, po_currency: 'INR',
            budgeted_cons: 0, budget_inr: 0, budget_cost_per_gmt: 0, conv: 0, actual_cost: 0,
            po_items: 0, po_materials: 0,
            z_items: 0, z_materials: 0, z_so_qty: 0, z_net_value: 0,
        };
    }

    function nNum(v) {
        const n = Number(v);
        return Number.isFinite(n) ? n : 0;
    }

    function isFooterLine(r) {
        const kind = String(r?.row_kind || '').toLowerCase();
        return kind === 'footer' || kind === 'subtotal' || kind === 'total';
    }

    function isMatchedLine(r) {
        if (!r || isFooterLine(r)) return false;
        const budget = nNum(r.budget_cost_per_gmt ?? r.expected_unit_cost ?? r.budget_inr);
        if (r.manual_match || r.matched_line_key || r.cost_item || r.maktx || r.costing_item_no) {
            return budget > 0;
        }
        if (r.needs_manual_match === true) return false;
        const actual = nNum(r.actual_cost ?? r.po_unit_cost);
        if (budget <= 0 || !actual) return false;
        const st = String(r.status || '').toUpperCase();
        if (st === 'EXCEPTION' && /no matching|no linked|cannot load|no budget/i.test(String(r.validation_result || ''))) {
            return false;
        }
        return true;
    }

    function totalsFromRecords(records) {
        if (!Array.isArray(records) || !records.length) return emptyTotals();
        const t = emptyTotals();
        const seenItems = new Set();
        const poMats = new Set();
        const zItems = new Set();
        const zMats = new Set();
        const seenZItem = new Set();
        let convSum = 0;
        let convN = 0;
        records.forEach((r) => {
            if (isFooterLine(r)) return;
            const soQty = nNum(r.so_qty ?? r.prwise_so_qty);
            const orderQty = nNum(r.tot_poqty ?? r.order_qty ?? r.po_qty);
            const costQty = soQty > 0 ? soQty : (orderQty > 0 ? orderQty : 1);
            const budget = nNum(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            const actual = nNum(r.actual_cost ?? r.po_unit_cost);
            const budgetInr = nNum(r.budget_inr ?? r.cost_total_inr);

            t.so_qty += soQty;
            t.prwise_so_qty += nNum(r.prwise_so_qty ?? r.so_qty);
            t.consumption += nNum(r.consumption);
            t.reqqty += nNum(r.reqqty);
            t.so_wise_poqty += nNum(r.so_wise_poqty);
            t.so_wise_po_val += nNum(r.so_wise_po_val);
            t.budgeted_cons += nNum(r.budgeted_cons);
            t.budget_cost_per_gmt += budget;
            t.actual_cost += actual * costQty;
            t.budget_inr += budgetInr > 0 ? budgetInr : (budget * costQty);
            const cv = nNum(r.conv ?? r.convr ?? r.budget_conv_rt);
            if (cv) { convSum += cv; convN += 1; }

            const item = String(r.poitem || r.po_item || '').trim();
            const mat = String(r.material || '').trim();
            if (item) seenItems.add(item);
            if (mat) poMats.add(mat);

            if (item) {
                if (!t._seenValue) t._seenValue = new Set();
                if (!t._seenValue.has(item)) {
                    t._seenValue.add(item);
                    t.tot_poqty += orderQty;
                    t.totvalue += nNum(r.totvalue ?? r.net_value);
                }
            } else {
                t.tot_poqty += orderQty;
                t.totvalue += nNum(r.totvalue ?? r.net_value);
            }

            if (isMatchedLine(r)) {
                if (item) zItems.add(item);
                if (mat) zMats.add(mat);
                if (soQty > 0) t.z_so_qty += soQty;
                else if (item && !seenZItem.has(item)) {
                    seenZItem.add(item);
                    t.z_so_qty += orderQty;
                } else if (!item) {
                    t.z_so_qty += orderQty;
                }
                t.z_net_value += (budget > 0 ? budget : budgetInr) * (budget > 0 ? costQty : 1);
            }

            if (!t.po_currency) t.po_currency = String(r.po_currency || r.currency || 'INR');
        });
        t.conv = convN ? convSum / convN : 0;
        t.po_items = seenItems.size;
        t.po_materials = poMats.size;
        t.z_items = zItems.size;
        t.z_materials = zMats.size;
        delete t._seenValue;
        if (!t.po_currency) t.po_currency = 'INR';
        return t;
    }

    function formatCol(key, r, rowIndex) {
        switch (key) {
            case 'check':
                return `<input type="checkbox" class="pcb-row-check" data-po="${esc(r.purchase_order)}" ${r._checked ? 'checked' : ''}>`;
            case 'sno':
                return String(rowIndex);
            case 'po':
                return `<button type="button" class="pcb-po-link" data-po="${esc(r.purchase_order)}">${esc(r.purchase_order)}</button>`;
            case 'date':
                return fmtDate(r.date);
            case 'material':
                return `<span class="pcb-mat-chip">${dash(r._mat_label)}</span>`;
            case 'items':
                return qty(r.items_count);
            case 'vendor_name':
                return dash(r.supplier_full_name);
            case 'vendor_code':
                return dash(r.supplier);
            case 'passed':
                return qty(r._passed);
            case 'exceptions':
                return qty(r._exceptions);
            case 'pending':
                return qty(r._pending);
            case 'status':
                return statusBadge(r._validation_status);
            case 'po_currency':
                return dash(r.po_currency || 'INR');
            case 'pdf':
                return `<button type="button" class="pcb-pdf-btn" data-po="${esc(r.purchase_order)}" title="View PO PDF">
                    <span class="material-icons-round">picture_as_pdf</span>
                    View PDF
                </button>`;
            case 'last':
                return dash(r._last_validated);
            default: {
                const v = r[key];
                if (MONEY_KEYS.has(key)) return money2(v);
                if (RATE_KEYS.has(key)) return num(v, 4);
                if (NUMERIC_SORT_KEYS.has(key)) return qty(v);
                return dash(v);
            }
        }
    }

    function pctLabel(n, total) {
        if (!total) return '0%';
        const p = (n / total) * 100;
        if (p > 0 && p < 1) return '<1%';
        return Math.round(p) + '%';
    }

    function colFilterMap() {
        const map = {};
        Object.keys(colFilterState).forEach((key) => {
            const val = String(colFilterState[key] || '').trim();
            if (val) map[key] = val;
        });
        if (Object.keys(map).length) return map;
        document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => {
            const key = inp.getAttribute('data-key');
            const val = String(inp.value || '').trim();
            if (key && val) map[key] = val;
        });
        return map;
    }

    function rowField(r, key) {
        switch (key) {
            case 'po': return String(r.purchase_order || '');
            case 'date': return [fmtDate(r.date), r.date].join(' ');
            case 'vendor_code': return String(r.supplier || '');
            case 'vendor_name': return String(r.supplier_full_name || '');
            case 'material': return String(r._mat_label || '');
            case 'items': return [r.items_count, qty(r.items_count)].join(' ');
            case 'passed': return [r._passed, qty(r._passed)].join(' ');
            case 'exceptions': return [r._exceptions, qty(r._exceptions)].join(' ');
            case 'pending': return [r._pending, qty(r._pending)].join(' ');
            case 'status': {
                const s = String(r._validation_status || '');
                return s + (s === 'EXCEPTION' ? ' fail failed exception' : '') + (s === 'PASSED' ? ' pass ok' : '') + (s === 'PENDING' ? ' pending' : '');
            }
            case 'last': return String(r._last_validated || '');
            case 'po_currency': return String(r.po_currency || 'INR');
            default: {
                const v = r[key];
                return [v, qty(v), money2(v), num(v, 4)].join(' ');
            }
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
        document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => {
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
                const needle = String(val || '').toLowerCase().replace(/,/g, '').trim();
                if (!needle) continue;
                const hay = String(rowField(r, key) || '').toLowerCase();
                if (!(hay.includes(String(val).toLowerCase()) || hay.replace(/,/g, '').includes(needle))) return false;
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
            case 'passed': return Number(r._passed || 0);
            case 'exceptions': return Number(r._exceptions || 0);
            case 'pending': return Number(r._pending || 0);
            case 'status': return String(r._validation_status || '');
            case 'last': return String(r._last_validated || '');
            case 'po_currency': return String(r.po_currency || '');
            default: return NUMERIC_SORT_KEYS.has(key) ? Number(r[key] || 0) : String(r[key] || '');
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

    function initTablePreferences({
        scriptId,
        tableId,
        menuId,
        toggleBtnId,
        dropdownId,
        statusId,
        colAttr = 'data-col-key',
    }) {
        const scriptNode = document.getElementById(scriptId);
        if (!scriptNode) return null;
        let config = null;
        try { config = JSON.parse(scriptNode.textContent || 'null'); } catch (e) { return null; }
        if (!config || !Array.isArray(config.columns)) return null;

        const table = document.getElementById(tableId);
        if (!table) return null;
        const menu = document.getElementById(menuId);
        const dropdown = document.getElementById(dropdownId);
        const toggleBtn = document.getElementById(toggleBtnId);
        const statusEl = document.getElementById(statusId);

        const columns = config.columns;
        const defaultOrder = columns.map((c) => c.id);
        const isLocked = (id) => columns.some((c) => c.id === id && c.locked);
        const labels = Object.fromEntries(columns.map((c) => [c.id, c.label]));

        let currentState = {
            columnOrder: [...defaultOrder],
            hiddenColumns: Array.isArray(config.defaultHidden) ? [...config.defaultHidden] : [],
        };
        let appliedOrder = [...defaultOrder];

        let styleEl = document.getElementById(tableId + 'ColStyle');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = tableId + 'ColStyle';
            document.head.appendChild(styleEl);
        }

        function applyHidden(hidden) {
            if (!hidden || !hidden.length) {
                styleEl.textContent = '';
            } else {
                styleEl.textContent = hidden.map((id) =>
                    `#${tableId} [${colAttr}="${id}"], #${tableId} [data-icol="${id}"] { display: none !important; }`
                ).join('\n');
            }
            if (toggleBtn) toggleBtn.classList.toggle('is-active', !!(hidden && hidden.length > 0));
            if (tableId === 'pcbItemTable' && typeof syncItemGroupHeaders === 'function') {
                syncItemGroupHeaders();
            }
        }

        function applyOrder(order) {
            appliedOrder = [...order];
            const rows = Array.from(table.querySelectorAll('thead tr, tbody tr')).filter(
                (tr) =>
                    !tr.classList.contains('pcb-empty') &&
                    !tr.classList.contains('pcw-empty') &&
                    !tr.classList.contains('pcw-group-row')
            );
            rows.forEach((tr) => {
                const byId = {};
                Array.from(tr.children).forEach((cell) => {
                    const key = cell.getAttribute(colAttr) || cell.getAttribute('data-icol') || cell.getAttribute('data-col-key');
                    if (key) byId[key] = cell;
                });
                const frag = document.createDocumentFragment();
                order.forEach((id) => {
                    if (byId[id]) frag.appendChild(byId[id]);
                });
                tr.appendChild(frag);
            });
        }

        function renderStatus(state) {
            if (!statusEl) return;
            let html = '';
            let kind = '';
            if (state && state.error) {
                kind = 'error';
                html = `<span class="material-icons-round">error_outline</span><span>${esc(state.error.message)}</span>`
                    + (state.error.retry ? '<button type="button" data-col-retry>Retry</button>' : '');
            } else if (state && state.saving) {
                kind = 'saving';
                html = '<span class="rpt-col-save-spin" aria-hidden="true"></span><span>Saving columns…</span>';
            } else if (state && state.saved) {
                kind = 'saved';
                html = '<span class="material-icons-round">check_circle</span><span>Saved</span>';
            }
            statusEl.hidden = kind === '';
            statusEl.className = 'rpt-col-save' + (kind ? ' is-' + kind : '');
            statusEl.title = (state && state.error) ? state.error.message : '';
            if (statusEl.innerHTML !== html) statusEl.innerHTML = html;
        }

        function buildMenu() {
            if (!menu) return;
            const order = currentState.columnOrder;
            const hidden = new Set(currentState.hiddenColumns);
            const movable = order.filter((id) => !isLocked(id));
            const visibleCount = order.length - hidden.size;

            menu.innerHTML = `
                <div class="rpt-colmgr-head">
                    <span class="rpt-cat-dd-header">Manage columns</span>
                    <span class="rpt-colmgr-count">${visibleCount} of ${order.length} shown</span>
                </div>
                <p class="rpt-colmgr-hint">Drag or use arrows to reorder. Saved to your account automatically.</p>
                <ul class="rpt-colmgr-list">
                    ${order.map((id) => {
                        const label = esc(labels[id] || id);
                        if (isLocked(id)) {
                            return `<li class="rpt-colmgr-item is-locked" data-col-item="${id}" title="Always shown first">
                                <span class="material-icons-round rpt-colmgr-grip">lock</span>
                                <span class="rpt-colmgr-label"><input type="checkbox" checked disabled><span>${label}</span></span>
                            </li>`;
                        }
                        const pos = movable.indexOf(id);
                        return `<li class="rpt-colmgr-item${hidden.has(id) ? ' is-hidden' : ''}" data-col-item="${id}" draggable="true">
                            <span class="material-icons-round rpt-colmgr-grip" title="Drag to reorder">drag_indicator</span>
                            <label class="rpt-colmgr-label"><input type="checkbox" data-col-toggle="${id}"${hidden.has(id) ? '' : ' checked'}><span>${label}</span></label>
                            <span class="rpt-colmgr-move">
                                <button type="button" data-col-move="-1" aria-label="Move ${label} up"${pos === 0 ? ' disabled' : ''}><span class="material-icons-round">keyboard_arrow_up</span></button>
                                <button type="button" data-col-move="1" aria-label="Move ${label} down"${pos === movable.length - 1 ? ' disabled' : ''}><span class="material-icons-round">keyboard_arrow_down</span></button>
                            </span>
                        </li>`;
                    }).join('')}
                </ul>
                <div class="rpt-cat-dd-divider"></div>
                <button type="button" class="rpt-cat-dd-item" data-col-reset>
                    <span class="material-icons-round">restart_alt</span>
                    <span class="rpt-cat-dd-item-text">Reset to default</span>
                </button>
            `;
        }

        function moveColumn(from, target) {
            if (from === target || isLocked(from)) return;
            const order = currentState.columnOrder.filter((id) => id !== from);
            const fromPos = currentState.columnOrder.indexOf(from);
            const toPos = order.indexOf(target);
            const insertAt = isLocked(target) ? toPos + 1 : (fromPos <= toPos ? toPos + 1 : toPos);
            order.splice(insertAt, 0, from);
            if (prefsStore) {
                prefsStore.setPreferences({ columnOrder: order, hiddenColumns: currentState.hiddenColumns });
            } else {
                currentState.columnOrder = order;
                applyOrder(order);
                buildMenu();
            }
        }

        function bindDrag(root, itemSelector, idOf) {
            let dragFrom = null;
            const clearMarks = () => root.querySelectorAll('.is-drag-over, .is-dragging').forEach((el) => el.classList.remove('is-drag-over', 'is-dragging'));
            root.addEventListener('dragstart', (e) => {
                const item = e.target.closest?.(itemSelector);
                const id = item ? idOf(item) : null;
                if (!id || isLocked(id)) return;
                dragFrom = id;
                item.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', id); } catch (err) {}
                }
            });
            root.addEventListener('dragover', (e) => {
                const item = e.target.closest?.(itemSelector);
                if (dragFrom === null || !item) return;
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                root.querySelectorAll('.is-drag-over').forEach((el) => { if (el !== item) el.classList.remove('is-drag-over'); });
                if (idOf(item) !== dragFrom) item.classList.add('is-drag-over');
            });
            root.addEventListener('drop', (e) => {
                const item = e.target.closest?.(itemSelector);
                if (dragFrom === null || !item) return;
                e.preventDefault();
                const from = dragFrom;
                dragFrom = null;
                clearMarks();
                moveColumn(from, idOf(item));
            });
            root.addEventListener('dragend', () => { dragFrom = null; clearMarks(); });
        }

        if (menu) {
            bindDrag(menu, '[data-col-item]', (li) => li.getAttribute('data-col-item'));
            menu.addEventListener('change', (e) => {
                const cb = e.target.closest('input[data-col-toggle]');
                if (!cb) return;
                const id = cb.getAttribute('data-col-toggle');
                const hidden = currentState.hiddenColumns.filter((h) => h !== id);
                if (!cb.checked) hidden.push(id);
                if (prefsStore) {
                    prefsStore.setPreferences({ columnOrder: currentState.columnOrder, hiddenColumns: hidden });
                } else {
                    currentState.hiddenColumns = hidden;
                    applyHidden(hidden);
                    buildMenu();
                }
            });
            menu.addEventListener('click', (e) => {
                e.stopPropagation();
                if (e.target.closest('[data-col-reset]')) {
                    if (prefsStore) {
                        prefsStore.reset();
                    } else {
                        currentState.columnOrder = [...defaultOrder];
                        currentState.hiddenColumns = Array.isArray(config.defaultHidden) ? [...config.defaultHidden] : [];
                        applyOrder(currentState.columnOrder);
                        applyHidden(currentState.hiddenColumns);
                        buildMenu();
                    }
                    return;
                }
                const moveBtn = e.target.closest('[data-col-move]');
                if (!moveBtn) return;
                const id = moveBtn.closest('[data-col-item]').getAttribute('data-col-item');
                const dir = parseInt(moveBtn.getAttribute('data-col-move'), 10);
                const movable = currentState.columnOrder.filter((c) => !isLocked(c));
                const neighbour = movable[movable.indexOf(id) + dir];
                if (!neighbour) return;
                moveColumn(id, neighbour);
            });
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdown?.classList.toggle('is-open');
                const open = dropdown?.classList.contains('is-open');
                toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) buildMenu();
            });
        }
        document.addEventListener('click', (e) => {
            if (dropdown && !dropdown.contains(e.target)) {
                dropdown.classList.remove('is-open');
                toggleBtn?.setAttribute('aria-expanded', 'false');
            }
        });

        if (statusEl) {
            statusEl.addEventListener('click', (e) => {
                if (e.target.closest('[data-col-retry]')) currentState?.error?.retry?.();
            });
        }

        const theadFirstRow = table.querySelector('thead tr.pcb-item-header-row, thead tr.pcb-header-row, thead tr.rpt-header-row')
            || table.querySelector('thead tr:not(.pcw-group-row)');
        if (theadFirstRow) {
            Array.from(theadFirstRow.children).forEach((th) => {
                const key = th.getAttribute(colAttr) || th.getAttribute('data-icol') || th.getAttribute('data-col-key');
                if (isLocked(key)) return;
                th.setAttribute('draggable', 'true');
                th.title = (th.title ? th.title + ' — ' : '') + 'drag to move column';
            });
            bindDrag(theadFirstRow, 'th', (th) => th.getAttribute(colAttr) || th.getAttribute('data-icol') || th.getAttribute('data-col-key'));
        }

        function onPreferenceChange(next) {
            const prev = currentState;
            currentState = next;
            const orderKey = next.columnOrder.join(',');
            const hiddenKey = next.hiddenColumns.join(',');
            const prevOrderKey = prev?.columnOrder?.join(',');
            const prevHiddenKey = prev?.hiddenColumns?.join(',');

            if (orderKey !== prevOrderKey || orderKey !== appliedOrder.join(',')) {
                applyOrder(next.columnOrder);
            }
            if (hiddenKey !== prevHiddenKey) {
                applyHidden(next.hiddenColumns);
            }
            buildMenu();
            renderStatus(next);
        }

        const prefsStore = (typeof window.rptCreateTablePreferences === 'function')
            ? window.rptCreateTablePreferences(config, onPreferenceChange)
            : null;

        if (!prefsStore) {
            applyOrder(currentState.columnOrder);
            applyHidden(currentState.hiddenColumns);
            buildMenu();
        }

        return {
            getOrder() { return currentState.columnOrder; },
            getHidden() { return new Set(currentState.hiddenColumns); },
            refresh() {
                applyOrder(currentState.columnOrder);
                applyHidden(currentState.hiddenColumns);
            },
        };
    }

    function applyColumnVisibility() {
        if (pcbTablePrefManager) {
            pcbTablePrefManager.refresh();
            return;
        }
        const table = $('pcbTable');
        if (!table) return;
        table.querySelectorAll('[data-col-key]').forEach((cell) => {
            const key = cell.getAttribute('data-col-key');
            const hide = hiddenCols.has(key);
            cell.style.display = hide ? 'none' : '';
        });
    }

    function buildColumnMenu() {
        if (pcbTablePrefManager) return;
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

        let poItems = 0;
        let poMats = 0;
        let poSoQty = 0;
        let poValue = 0;
        let zItems = 0;
        let zMats = 0;
        let zSoQty = 0;
        let zValue = 0;
        filtered.forEach((r) => {
            const hasLines = Number(r._po_items || 0) > 0;
            poItems += hasLines ? Number(r._po_items) : Number(r.items_count || 0);
            poMats += Number(r._po_materials || 0);
            poSoQty += Number(r.tot_poqty || r.so_qty || 0);
            poValue += Number(r.totvalue || r.total_value || 0);
            zItems += Number(r._z_items || 0);
            zMats += Number(r._z_materials || 0);
            zSoQty += Number(r._z_so_qty || 0);
            zValue += Number(r._z_net_value || r.budget_inr || 0);
        });
        const setCmp = (id, text) => {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        };
        setCmp('pcbCmpPoItems', qty(poItems));
        setCmp('pcbCmpPoMaterials', qty(poMats));
        setCmp('pcbCmpPoQty', qty(poSoQty));
        setCmp('pcbCmpPoValue', money(poValue || value));
        setCmp('pcbCmpZcItems', qty(zItems));
        setCmp('pcbCmpZcMaterials', qty(zMats));
        setCmp('pcbCmpZcQty', qty(zSoQty));
        setCmp('pcbCmpZcValue', money(zValue));
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
            tbody.innerHTML = `<tr class="pcb-empty"><td colspan="${COL_DEFS.length}">No purchase orders match the filters.</td></tr>`;
            if (cards) cards.innerHTML = '<div class="pcb-card-empty">No purchase orders match the filters.</div>';
            $('pcbPager') && ($('pcbPager').hidden = true);
            fillKpis();
            return;
        }

        const startIdx = (page - 1) * pageSize;
        tbody.innerHTML = rows.map((r, i) => {
            const active = activePo === r.purchase_order ? ' is-active' : '';
            const sno = startIdx + i + 1;
            return `<tr class="pcb-row${active}" data-po="${esc(r.purchase_order)}">${
                COL_DEFS.map((c) => {
                    const numCls = NUMERIC_SORT_KEYS.has(c.key) ? ' num' : '';
                    const extra = c.key === 'check' ? ' pcb-check' : (c.key === 'po' ? ' pcb-sticky-po' : (c.key === 'vendor_name' ? ' pcb-vendor' : ''));
                    return `<td class="${(numCls + extra).trim()}" data-col-key="${esc(c.key)}">${formatCol(c.key, r, sno)}</td>`;
                }).join('')
            }</tr>`;
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
                        <button type="button" class="pcb-pdf-btn" data-po="${esc(r.purchase_order)}">
                            <span class="material-icons-round">picture_as_pdf</span>
                            View PDF
                        </button>
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
        applyColumnVisibility();
        updateSortUi();
        fillKpis();
    }

    function presetDates() {
        return cfg.presetDates || {};
    }

    function ymdDaysAgo(days) {
        const d = new Date();
        d.setDate(d.getDate() - days);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    let lastNonCustomRange = 'today';

    function setCustomDateError(message) {
        const modalError = $('poCustomDateError');
        if (!modalError) return;
        if (!message) {
            modalError.hidden = true;
            modalError.textContent = '';
            return;
        }
        modalError.textContent = message;
        modalError.hidden = false;
    }

    function openCustomDateModal() {
        const modal = $('poCustomDateModal');
        if (!modal) return;
        const fromVal = $('pcbFrom')?.value || ymdDaysAgo(7);
        const toVal = $('pcbTo')?.value || ymdDaysAgo(0);
        if ($('poCustomModalFrom')) $('poCustomModalFrom').value = fromVal;
        if ($('poCustomModalTo')) $('poCustomModalTo').value = toVal;
        setCustomDateError('');
        modal.hidden = false;
        document.body.classList.add('po-modal-open');
        $('poCustomModalFrom')?.focus();
    }

    function closeCustomDateModal({ revertSelect = false } = {}) {
        const modal = $('poCustomDateModal');
        if (modal) modal.hidden = true;
        document.body.classList.remove('po-modal-open');
        if (revertSelect && $('pcbRange')) {
            $('pcbRange').value = lastNonCustomRange || 'today';
            applyRangePreset($('pcbRange').value, { fillDates: true });
        }
    }

    function applyCustomDatesAndLoad() {
        const fVal = $('poCustomModalFrom')?.value.trim() || '';
        const tVal = $('poCustomModalTo')?.value.trim() || '';
        if (!fVal || !tVal) {
            setCustomDateError(!fVal ? 'Select a From date.' : 'Select a To date.');
            (!fVal ? $('poCustomModalFrom') : $('poCustomModalTo'))?.focus();
            return;
        }
        if (fVal > tVal) {
            setCustomDateError('To date must be on or after From date.');
            $('poCustomModalTo')?.focus();
            return;
        }
        const maxDays = Number($('poCustomDateModal')?.getAttribute('data-max-days')) || 100;
        const minYmd = ymdDaysAgo(maxDays);
        if (fVal < minYmd) {
            setCustomDateError(`Select dates within the last ${maxDays} days.`);
            $('poCustomModalFrom')?.focus();
            return;
        }
        if ($('pcbFrom')) $('pcbFrom').value = fVal;
        if ($('pcbTo')) $('pcbTo').value = tVal;
        lastNonCustomRange = 'custom';
        closeCustomDateModal({ revertSelect: false });
        void fetchPos();
    }

    function applyRangePreset(key, { fillDates = true } = {}) {
        const isCustom = key === 'custom';
        if (!isCustom && fillDates) {
            const dates = presetDates()[key];
            if (dates) {
                if ($('pcbFrom')) $('pcbFrom').value = dates.from || '';
                if ($('pcbTo')) $('pcbTo').value = dates.to || '';
            }
        }
    }

    function currentRange() {
        return String($('pcbRange')?.value || cfg.range?.preset || 'today');
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
            enqueueSelectedValidation(allRows.map((r) => String(r.purchase_order || '')).filter(Boolean));
            const u = new URL(cfg.pageUrl || 'purchase-order', window.location.origin);
            u.searchParams.set('view', 'costing');
            u.searchParams.set('range', range);
            if (from) u.searchParams.set('from', from);
            if (to) u.searchParams.set('to', to);
            if (supplier) u.searchParams.set('supplier', supplier);
            if (mat !== 'all') u.searchParams.set('mat_cat', mat);
            window.history.replaceState({}, '', u.pathname + u.search);
            toast(`Loaded ${records.length.toLocaleString('en-IN')} purchase orders.`, 'ok');
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
        if (!raw) {
            if ($('pcbQuickSearch') && $('pcbQuickSearch').value) {
                $('pcbQuickSearch').value = '';
                applyFilter();
            }
            return;
        }
        const po = raw.replace(/\D/g, '');
        if (!po || !/^\d{1,10}$/.test(po)) {
            toast('Enter a valid PO number (up to 10 digits).', 'error');
            return;
        }
        if ($('pcbQuickSearch')) $('pcbQuickSearch').value = po;

        // 1. Exact match in current loaded list (full or without leading zeros)
        const exact = allRows.find((r) => {
            const rowPo = String(r.purchase_order || '');
            return rowPo === po || rowPo.replace(/^0+/, '') === po.replace(/^0+/, '');
        });
        if (exact) {
            applyFilter();
            void openDrill(exact.purchase_order);
            return;
        }

        // 2. Partial match in current list (e.g. typing last few digits)
        const partialMatches = allRows.filter((r) => String(r.purchase_order || '').includes(po));
        if (partialMatches.length > 0) {
            applyFilter();
            if (partialMatches.length === 1) {
                void openDrill(partialMatches[0].purchase_order);
            }
            return;
        }

        // 3. If a full PO number (8-10 digits) was typed and not in current list, open workbench
        if (po.length >= 8) {
            window.open(workbenchUrl(po), '_blank');
            return;
        }

        // 4. Otherwise filter table and inform user
        applyFilter();
        toast(`No PO found matching "${po}" in loaded records.`, 'info');
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
        setRuntimeStatus(true, `Loading PO vs zCOST 0/${startedWith}`);
        setProgress(true, 0, startedWith, 'Loading PO vs zCOST totals…');

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
                setRuntimeStatus(true, `Loading PO vs zCOST ${done}/${total}`);
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
                ? `Loaded totals for ${done - fail} PO(s) · ${fail} failed`
                : `PO vs zCOST totals loaded for ${done} PO(s)`,
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
        let cls = 'is-fail';
        let label = s || '—';
        if (s === 'PASS') {
            cls = 'is-pass';
            label = 'PASS';
        } else if (s === 'NEEDS_REVIEW') {
            cls = 'is-review';
            label = 'NEEDS REVIEW';
        } else if (s === 'FAIL' || s === 'EXCEPTION') {
            cls = 'is-fail';
            label = 'EXCEPTION';
        }
        return `<span class="pcw-pill ${cls}">${esc(label)}</span>`;
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
    const DEFAULT_HIDDEN_ITEM_COLS = [
        'prno',
        'prwise_so_qty',
        'consumption',
        'mat_desc',
        'budgeted_cons',
        'budget_inr',
        'budget_currency',
        'budget_conv_rt',
        'conv',
        'variance_pct',
    ];
    let hiddenItemCols = new Set(DEFAULT_HIDDEN_ITEM_COLS);
    /** @type {Record<string, string>} live column-filter values (source of truth) */
    const itemColFilterState = Object.create(null);

    const ITEM_COL_DEFS = [
        { key: 'status', label: 'Status', locked: true },
        { key: 'pono', label: 'PONO' },
        { key: 'poitem', label: 'POITEM' },
        { key: 'prno', label: 'PRNO' },
        { key: 'salordno', label: 'SALORDNO' },
        { key: 'style', label: 'STYLE' },
        { key: 'material', label: 'MATERIAL' },
        { key: 'matgroup', label: 'MATGROUP' },
        { key: 'so_qty', label: 'SO_QTY' },
        { key: 'prwise_so_qty', label: 'PRWISE_SO_QTY' },
        { key: 'consumption', label: 'CONSUMPTION' },
        { key: 'reqqty', label: 'REQQTY' },
        { key: 'tot_poqty', label: 'TOT_POQTY' },
        { key: 'so_wise_poqty', label: 'SO_WISE_POQTY' },
        { key: 'so_wise_po_val', label: 'SO_WISE_PO_VAL' },
        { key: 'ouom', label: 'OUOM' },
        { key: 'porate', label: 'PORATE' },
        { key: 'totvalue', label: 'TOTVALUE' },
        { key: 'mat_desc', label: 'MAT_DESC' },
        { key: 'po_currency', label: 'PO_CURRENCY' },
        { key: 'costing_quotation_no', label: 'Quotation No' },
        { key: 'costing_item_no', label: 'zCOST Item' },
        { key: 'maktx', label: 'MAKTX' },
        { key: 'budgeted_cons', label: 'BUDGETED_CONS' },
        { key: 'budget_inr', label: 'BUDGET_INR' },
        { key: 'budget_currency', label: 'BUDGET_CURRENCY' },
        { key: 'budget_conv_rt', label: 'BUDGET_CONV_RT' },
        { key: 'budget_cost_per_gmt', label: 'BUDGET_COST_PER_GMT' },
        { key: 'conv', label: 'CONV' },
        { key: 'buom', label: 'BUOM' },
        { key: 'actual_cost', label: 'ACTUAL_COST' },
        { key: 'variance_pct', label: 'Variance %' },
    ];
    const ITEM_NUM_COLS = new Set([
        'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
        'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost', 'variance_pct',
    ]);
    const ITEM_ZCOST_COLS = new Set([
        'costing_quotation_no', 'costing_item_no', 'maktx',
        'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'budget_cost_per_gmt',
        'conv', 'buom', 'actual_cost', 'variance_pct',
    ]);
    const ITEM_HIGHLIGHT_COLS = new Set(['budget_cost_per_gmt', 'actual_cost']);
    const itemColGroup = (key) => (ITEM_ZCOST_COLS.has(key) ? 'zcost' : 'po');

    function itemCellValue(key, r) {
        switch (key) {
            case 'status':
                return r.status || '';
            case 'pono':
                return r.pono || r.purchase_order || activePo || '';
            case 'poitem':
                return r.poitem || r.po_item || '';
            case 'prno':
                return r.prno || '';
            case 'salordno':
                return r.salordno || r.sales_order || '';
            case 'style':
                return r.style || '';
            case 'material':
                return r.material || '';
            case 'matgroup':
                return r.matgroup || r.material_group || r.cost_item || '';
            case 'so_qty': {
                const so = Number(r.so_qty);
                if (Number.isFinite(so) && so > 0) return so;
                const pr = Number(r.prwise_so_qty);
                return Number.isFinite(pr) && pr > 0 ? pr : (r.so_qty ?? r.prwise_so_qty);
            }
            case 'prwise_so_qty': {
                const pr = Number(r.prwise_so_qty);
                if (Number.isFinite(pr) && pr > 0) return pr;
                const so = Number(r.so_qty);
                return Number.isFinite(so) && so > 0 ? so : (r.prwise_so_qty ?? r.so_qty);
            }
            case 'consumption': {
                // Prefer TEMPFIN CONSUMPTION / BUDGETED_CONS — never invent REQQTY÷SO
                const cons = Number(r.consumption);
                if (Number.isFinite(cons) && cons > 0) return cons;
                const bc = Number(r.budgeted_cons);
                if (Number.isFinite(bc) && bc > 0) return bc;
                return null;
            }
            case 'reqqty':
                return r.reqqty;
            case 'tot_poqty':
                return r.tot_poqty ?? r.order_qty ?? r.po_qty;
            case 'so_wise_poqty':
                return r.so_wise_poqty ?? r.tot_poqty ?? r.order_qty ?? r.po_qty;
            case 'so_wise_po_val':
                return r.so_wise_po_val ?? r.totvalue ?? r.net_value;
            case 'ouom':
                return r.ouom || r.unit || '';
            case 'porate':
                return r.porate ?? r.po_unit_cost ?? r.net_price;
            case 'totvalue':
                return r.totvalue ?? r.net_value;
            case 'mat_desc':
                return r.mat_desc || r.material_desc || r.description || '';
            case 'po_currency':
                return r.po_currency || r.currency || 'INR';
            case 'costing_quotation_no':
                return r.costing_quotation_no || r.costing_quotation || r.matched_quotation || r.quotation || '';
            case 'costing_item_no': {
                // zCOST ITEM only — empty API ITEM must show empty (not POSNR-ZLINE)
                if (Object.prototype.hasOwnProperty.call(r, 'zcost_item')) {
                    return String(r.zcost_item || '');
                }
                const raw = String(r.cost_item || r.costing_item_no || '').trim();
                if (/^\d+-\d+$/.test(raw)) return '';
                return raw;
            }
            case 'maktx':
                return r.maktx || r.cost_description || '';
            case 'budgeted_cons':
                return r.budgeted_cons ?? r.consumption ?? r.gross ?? r.net;
            case 'budget_inr':
                return r.budget_inr ?? r.cost_total_inr ?? r.total_inr;
            case 'budget_currency':
                return r.budget_currency || r.curunit || 'INR';
            case 'budget_conv_rt':
                return r.budget_conv_rt ?? r.convr ?? r.conv ?? 1;
            case 'budget_cost_per_gmt':
                return r.budget_cost_per_gmt ?? r.expected_unit_cost ?? r.rate;
            case 'conv':
                return r.conv ?? r.convr ?? 1;
            case 'buom':
                return r.buom || r.ouom || r.unit || '';
            case 'actual_cost':
                return r.actual_cost ?? r.po_unit_cost;
            case 'variance_pct':
                return r.variance_pct;
            default:
                return r[key] ?? '';
        }
    }

    function renderItemCell(key, r) {
        const group = itemColGroup(key);
        const classes = [];
        if (ITEM_NUM_COLS.has(key)) classes.push('num');
        classes.push(`pcw-col-${group}`);
        if (ITEM_HIGHLIGHT_COLS.has(key)) classes.push('pcw-hl-cost');
        const cls = classes.length ? ` class="${classes.join(' ')}"` : '';
        let inner = '—';
        switch (key) {
            case 'status':
                inner = resultPill(r.status);
                break;
            case 'pono':
                inner = `<span class="pcw-mat">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'material':
                inner = `<span class="pcw-mat">${dash(r.material)}</span>`;
                break;
            case 'mat_desc':
                inner = `<span class="pcw-compare-po" title="${esc(itemCellValue(key, r))}">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'costing_quotation_no':
                inner = quotationLinkHtml(
                    itemCellValue(key, r),
                    itemCellValue('costing_item_no', r)
                );
                break;
            case 'costing_item_no':
                inner = `<span class="pcw-nav-chip" title="zCOST ITEM">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'maktx':
                inner = `<span class="pcw-compare-maktx" title="${esc(itemCellValue(key, r))}">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'so_qty':
            case 'prwise_so_qty':
            case 'reqqty':
            case 'tot_poqty':
            case 'so_wise_poqty':
                inner = qty(itemCellValue(key, r));
                break;
            case 'so_wise_po_val':
            case 'totvalue':
            case 'budget_inr':
                inner = money2(itemCellValue(key, r));
                break;
            case 'ouom':
            case 'buom':
            case 'po_currency':
            case 'budget_currency':
                inner = dash(itemCellValue(key, r));
                break;
            case 'consumption':
            case 'porate':
            case 'budgeted_cons':
            case 'budget_conv_rt':
            case 'budget_cost_per_gmt':
            case 'conv':
            case 'actual_cost': {
                const mismatch = !!r.cost_mismatch && (key === 'budget_cost_per_gmt' || key === 'actual_cost');
                const shown = num(itemCellValue(key, r), 4);
                inner = mismatch ? `<span class="pcw-cost-mismatch">${shown}</span>` : shown;
                break;
            }
            case 'variance_pct':
                inner = varianceCell(itemCellValue(key, r));
                break;
            default:
                inner = dash(itemCellValue(key, r));
        }
        return `<td${cls} data-col-key="${esc(key)}" data-col-group="${esc(group)}">${inner}</td>`;
    }

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
            case 'so_qty':
            case 'prwise_so_qty':
            case 'reqqty':
            case 'tot_poqty':
            case 'so_wise_poqty':
                out.push(qty(v), String(Number(v || 0)));
                break;
            case 'net_value':
            case 'expected_unit_cost':
            case 'po_unit_cost':
            case 'difference':
            case 'sell_price':
            case 'making':
            case 'so_wise_po_val':
            case 'totvalue':
            case 'budget_inr':
                out.push(money(v), money2(v), String(Number(v || 0)));
                break;
            case 'consumption':
            case 'porate':
            case 'budgeted_cons':
            case 'budget_conv_rt':
            case 'budget_cost_per_gmt':
            case 'conv':
            case 'actual_cost':
                out.push(num(v, 4), String(Number(v || 0)));
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

    function itemSearchHay(r) {
        return ITEM_COL_DEFS.map((c) => itemCellValue(c.key, r)).join(' ');
    }

    function itemMatchesCol(r, key, val) {
        const candidates = itemFieldCandidates(r, key);
        const mapped = itemCellValue(key, r);
        if (mapped !== null && mapped !== undefined && mapped !== '') candidates.push(String(mapped));
        return candidates.some((c) => textMatches(c, val));
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
        if (pcbItemTablePrefManager) {
            pcbItemTablePrefManager.refresh();
            syncItemGroupHeaders();
            return;
        }
        const table = $('pcbItemTable');
        if (!table) return;
        const headerCells = table.querySelectorAll('tr.pcb-item-header-row th');
        const filterCells = table.querySelectorAll('tr.pcb-item-filter-row th');
        headerCells.forEach((th, i) => {
            const key = th.getAttribute('data-icol') || th.getAttribute('data-col-key');
            const hide = key ? hiddenItemCols.has(key) : false;
            th.style.display = hide ? 'none' : '';
            if (filterCells[i]) filterCells[i].style.display = hide ? 'none' : '';
        });
        table.querySelectorAll('tbody tr').forEach((tr) => {
            if (tr.classList.contains('pcw-empty')) return;
            const tds = tr.children;
            headerCells.forEach((th, i) => {
                const key = th.getAttribute('data-icol') || th.getAttribute('data-col-key');
                if (!tds[i]) return;
                tds[i].style.display = (key && hiddenItemCols.has(key)) ? 'none' : '';
            });
        });
        syncItemGroupHeaders();
    }

    function syncItemGroupHeaders() {
        const table = $('pcbItemTable');
        const groupRow = table?.querySelector('tr.pcw-group-row');
        const headerRow = table?.querySelector('tr.pcb-item-header-row');
        if (!groupRow || !headerRow) return;
        const visible = Array.from(headerRow.children).filter((th) => {
            if (!(th instanceof HTMLElement)) return false;
            return window.getComputedStyle(th).display !== 'none';
        });
        const runs = [];
        visible.forEach((th) => {
            const g = th.getAttribute('data-col-group') || itemColGroup(th.getAttribute('data-col-key') || '');
            const last = runs[runs.length - 1];
            if (last && last.group === g) last.span += 1;
            else runs.push({ group: g, span: 1 });
        });
        if (!runs.length) {
            groupRow.innerHTML = '';
            return;
        }
        groupRow.innerHTML = runs.map((run) => {
            const isPo = run.group !== 'zcost';
            const label = isPo ? 'PO Data' : 'zCOST Data';
            const icon = isPo ? 'receipt_long' : 'calculate';
            const cls = isPo ? 'is-po' : 'is-zcost';
            return `<th class="pcw-group-th ${cls}" data-group="${esc(run.group)}" colspan="${run.span}">
                <span class="pcw-group-label">
                    <span class="material-icons-round">${icon}</span>
                    ${label}
                </span>
            </th>`;
        }).join('');
    }

    function buildItemColumnMenu() {
        if (pcbItemTablePrefManager) return;
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
                if (act === 'all') {
                    hiddenItemCols.clear();
                    buildItemColumnMenu();
                    applyItemColumnVisibility();
                } else if (act === 'reset') {
                    hiddenItemCols = new Set(DEFAULT_HIDDEN_ITEM_COLS);
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
        const items = filtered;
        updateItemFilterUi();
        if ($('pcbItemCount')) {
            if (!filtered.length) {
                $('pcbItemCount').textContent = 'No items';
            } else {
                $('pcbItemCount').textContent = `Showing ${filtered.length} of ${activeItemRows.length} items`;
            }
            $('pcbItemCount').classList.toggle('is-filtered', !!(itemSearchQuery() || Object.keys(itemColFilterMap()).length));
        }
        if (!items.length) {
            body.innerHTML = `<tr class="pcw-empty"><td colspan="${ITEM_COL_DEFS.length}">${
                activeItemRows.length ? 'No items match the filters.' : (runtimeRunning ? 'Validation in progress…' : 'Not validated yet. Select this PO to validate.')
            }</td></tr>`;
            hideItemDetail();
            applyItemColumnVisibility();
            return;
        }

        body.innerHTML = items.map((r) => {
            const idx = r._srcIdx != null ? r._srcIdx : activeItemRows.indexOf(r);
            const active = idx === activeItemIdx ? ' is-active' : '';
            const st = String(r.status || '').toUpperCase();
            const unmatched = !!r.needs_manual_match
                || (!r.costing_quotation_no && !r.costing_quotation && !r.matched_quotation && !r.maktx && !r.cost_item);
            const pass = !unmatched && st === 'PASS' ? ' is-pass-row' : '';
            const review = !unmatched && st === 'NEEDS_REVIEW' ? ' is-review-row' : '';
            const fail = !unmatched && (st === 'EXCEPTION' || st === 'FAIL') ? ' is-fail-row' : '';
            const unmatchedCls = unmatched ? ' is-unmatched-row' : '';
            const mismatch = r.cost_mismatch && !unmatched && st !== 'PASS' ? ' is-cost-mismatch' : '';
            return `<tr class="pcw-row${active}${pass}${review}${fail}${unmatchedCls}${mismatch}" data-idx="${idx}">${
                ITEM_COL_DEFS.map((c) => renderItemCell(c.key, r)).join('')
            }</tr>`;
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
        const headers = ITEM_COL_DEFS.map((c) => c.label);
        const lines = [headers.join(',')];
        rows.forEach((r) => {
            lines.push(ITEM_COL_DEFS.map((c) => `"${String(itemCellValue(c.key, r) ?? '').replace(/"/g, '""')}"`).join(','));
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
        if ($('pcbOcKpiReview')) $('pcbOcKpiReview').textContent = qty(s.needs_review || 0);
        if ($('pcbOcKpiValue')) {
            const val = s.total_po_value != null ? s.total_po_value : fallbackValue;
            const curr = (s.currency || 'INR').toUpperCase();
            const sym = curr === 'EUR' ? '€ ' : (curr === 'USD' ? '$ ' : '₹ ');
            $('pcbOcKpiValue').textContent = sym + money2(val || 0);
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
            const s = data?.summary || {};
            const parts = [];
            if (s.passed != null) parts.push(`${s.passed} passed`);
            if (s.exceptions != null) parts.push(`${s.exceptions} exceptions`);
            if (s.needs_review != null && s.needs_review > 0) parts.push(`${s.needs_review} needs review`);
            if (data?._validated_at) parts.push(data._validated_at);
            $('pcbDrillSub').textContent = data
                ? parts.join(' · ')
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

    function openDrill(po) {
        if (!po) return;
        window.location.href = workbenchUrl(po);
    }

    function selectedPos() {
        const po = allRows.find((r) => !!r._checked);
        return po && po.purchase_order ? [String(po.purchase_order)] : [];
    }

    function openActionOffcanvas(shellId) {
        const shell = $(shellId);
        if (!shell) return;
        shell.hidden = false;
        shell.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pcw-action-offcanvas-open');
        requestAnimationFrame(() => shell.classList.add('is-open'));
    }

    function closeActionOffcanvas(shellId) {
        const shell = $(shellId);
        if (!shell) return;
        shell.classList.remove('is-open');
        shell.setAttribute('aria-hidden', 'true');
        const stillOpen = ['pcwValidateModal', 'pcwHistoryModal'].some((id) => {
            const el = $(id);
            return el && el !== shell && el.classList.contains('is-open');
        });
        if (!stillOpen) document.body.classList.remove('pcw-action-offcanvas-open');
        window.setTimeout(() => {
            if (!shell.classList.contains('is-open')) shell.hidden = true;
        }, 280);
    }

    function closeValidateModal() {
        closeActionOffcanvas('pcwValidateModal');
    }

    function closeHistoryModal() {
        closeActionOffcanvas('pcwHistoryModal');
    }

    function openValidateModal() {
        const selected = selectedPos();
        if (!selected.length) {
            toast('Select one PO row to assign & validate.', 'error');
            return;
        }
        validateTargetPos = selected.slice(0, 1);
        const loginUser = String(cfg.currentUser || $('pcwLoginUserName')?.textContent || 'User').trim();
        if ($('pcwLoginUserName')) $('pcwLoginUserName').textContent = loginUser || 'User';
        const meta = $('pcwValidateMeta');
        if (meta) {
            meta.innerHTML = `
                <div class="pcw-validate-meta-chip"><span>Purchase Order</span><strong>${esc(validateTargetPos[0] || '—')}</strong></div>
                <div class="pcw-validate-meta-chip"><span>Scope</span><strong>Single PO</strong></div>
                <div class="pcw-validate-meta-chip"><span>Validated By</span><strong>${esc(loginUser || '—')}</strong></div>`;
        }
        if ($('pcwValidateRemarks')) $('pcwValidateRemarks').value = '';
        document.querySelectorAll('input[name="pcwValidateStatus"]').forEach((el, i) => {
            el.checked = i === 0;
            el.closest('label')?.classList.toggle('is-active', i === 0);
        });
        closeHistoryModal();
        openActionOffcanvas('pcwValidateModal');
        $('pcwUserSearch')?.focus();
    }

    async function openHistoryModal() {
        const selected = selectedPos();
        const po = selected[0] || activePo || '';
        if (!po) {
            toast('Select one PO row to view validation history.', 'error');
            return;
        }
        const body = $('pcwHistoryBody');
        const countEl = $('pcwHistoryCount');
        if (countEl) countEl.textContent = 'Loading…';
        if (body) body.innerHTML = `<div class="pcw-history-empty">Loading validation history…</div>`;
        if ($('pcwHistoryDesc')) {
            $('pcwHistoryDesc').textContent = `Validation history for PO ${po}.`;
        }
        closeValidateModal();
        openActionOffcanvas('pcwHistoryModal');
        const endpoint = cfg.validationLogUrl || 'purchase-order/validation-log';
        try {
            const res = await fetch(`${endpoint}?po=${encodeURIComponent(po)}&_=${Date.now()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            const logs = Array.isArray(json?.data?.logs) ? json.data.logs : [];
            const grouped = [];
            const seen = new Map();
            logs.forEach((log) => {
                const when = String(log.created_at || '').replace('T', ' ').slice(0, 19);
                const key = [when, log.created_by, log.assigned_username, log.validation_status, log.description].join('|');
                if (seen.has(key)) {
                    seen.get(key).item_count += 1;
                    return;
                }
                const entry = { ...log, when, item_count: 1 };
                seen.set(key, entry);
                grouped.push(entry);
            });
            if (countEl) {
                countEl.textContent = grouped.length
                    ? `${grouped.length} validation${grouped.length === 1 ? '' : 's'}`
                    : '0 validations';
            }
            if (!body) return;
            if (!grouped.length) {
                body.innerHTML = `<div class="pcw-history-empty">No validation history for PO ${esc(po)}.</div>`;
                return;
            }
            body.innerHTML = grouped.map((log) => {
                const st = String(log.validation_status || '');
                const stLabel = st === 'VALIDATE_PO' ? 'Validate PO' : st === 'NOT_VALIDATE' ? 'Not Validate' : st;
                const whenFull = String(log.when || '—');
                const remarks = String(log.description || '').trim();
                return `<article class="pcw-history-card" role="listitem">
                    <div class="pcw-history-when">
                        <strong>${esc(whenFull.slice(0, 10) || '—')}</strong>
                        <small>${esc(whenFull.length > 10 ? whenFull.slice(11) : '')}</small>
                    </div>
                    <div class="pcw-history-main">
                        <div class="pcw-history-people">
                            <span><span class="material-icons-round">account_circle</span>By <strong>${esc(log.created_by || '—')}</strong></span>
                            <span><span class="material-icons-round">person_add</span>Assigned <strong>${esc(log.assigned_username || '—')}</strong></span>
                        </div>
                        <p class="pcw-history-remarks${remarks ? '' : ' is-empty'}">${esc(remarks || 'No remarks')}</p>
                    </div>
                    <span class="pcw-pill ${st === 'VALIDATE_PO' ? 'is-pass' : 'is-review'}">${esc(stLabel)}</span>
                </article>`;
            }).join('');
        } catch (err) {
            if (countEl) countEl.textContent = 'Error';
            if (body) body.innerHTML = `<div class="pcw-history-empty">${esc(err.message || 'Failed to load history')}</div>`;
        }
    }

    async function searchUsers(q) {
        const menu = $('pcwUserMenu');
        if (!menu) return;
        const endpoint = cfg.usersUrl || 'purchase-order/users';
        const params = new URLSearchParams({ q: q || '', limit: '30', _: String(Date.now()) });
        try {
            const res = await fetch(`${endpoint}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            const users = Array.isArray(json?.data?.users) ? json.data.users : [];
            if (!users.length) {
                menu.innerHTML = `<div class="pcw-user-empty">No users found</div>`;
                menu.hidden = false;
                return;
            }
            menu.innerHTML = users.map((u) => `<button type="button" class="pcw-user-option" role="option"
                data-id="${esc(u.id)}" data-username="${esc(u.username)}">
                <strong>${esc(u.username)}</strong>
                <small>${esc([u.department, u.role, u.email].filter(Boolean).join(' · ') || '—')}</small>
            </button>`).join('');
            menu.hidden = false;
        } catch (err) {
            menu.innerHTML = `<div class="pcw-user-empty">Could not load users</div>`;
            menu.hidden = false;
        }
    }

    function pickUser(id, username) {
        assignedUser = { id: String(id || ''), username: String(username || '') };
        if ($('pcwUserId')) $('pcwUserId').value = assignedUser.id;
        if ($('pcwUserName')) $('pcwUserName').value = assignedUser.username;
        if ($('pcwUserSearch')) $('pcwUserSearch').value = assignedUser.username;
        if ($('pcwUserMenu')) $('pcwUserMenu').hidden = true;
    }

    async function saveValidationLog() {
        const status = document.querySelector('input[name="pcwValidateStatus"]:checked')?.value || 'VALIDATE_PO';
        const description = String($('pcwValidateRemarks')?.value || '').trim();
        if (!assignedUser.username) {
            toast('Select a next-level user to assign.', 'error');
            $('pcwUserSearch')?.focus();
            return;
        }
        const targets = (validateTargetPos.length ? validateTargetPos : selectedPos()).slice(0, 1);
        if (!targets.length) {
            toast('Select one PO row.', 'error');
            return;
        }
        const endpoint = cfg.validationLogUrl || 'purchase-order/validation-log';
        const btn = $('pcwValidateSave');
        if (btn) btn.disabled = true;
        let ok = 0;
        let fail = 0;
        try {
            for (const po of targets) {
                const payload = {
                    purchase_order: po,
                    mat_cat: selectedMat(),
                    assigned_user_id: assignedUser.id || null,
                    assigned_username: assignedUser.username,
                    validation_status: status,
                    description,
                    po_item: '',
                    material: '',
                    mat_desc: '',
                    matched_zcost_item: '',
                    matched_maktx: '',
                    row_status: '',
                };
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (!res.ok || json.success === false) {
                    fail += 1;
                    continue;
                }
                ok += 1;
                const row = allRows.find((r) => String(r.purchase_order) === String(po));
                if (row) {
                    row._validated_by = String(cfg.currentUser || $('pcwLoginUserName')?.textContent || 'User').trim();
                    row._last_validated = new Date().toISOString().slice(0, 19).replace('T', ' ');
                    if (status === 'VALIDATE_PO') row._validation_status = 'PASSED';
                    else if (status === 'NOT_VALIDATE') row._validation_status = 'NEEDS_REVIEW';
                }
            }
            closeValidateModal();
            renderTable();
            toast(
                fail
                    ? `Saved ${ok} PO validation(s), ${fail} failed.`
                    : `Saved validation for ${ok} PO${ok === 1 ? '' : 's'} → ${assignedUser.username}`,
                fail ? 'error' : 'ok'
            );
        } catch (err) {
            toast(err.message || 'Could not save validation log', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    function exportCsv() {
        if (!filtered.length) {
            toast('Nothing to export.', 'error');
            return;
        }
        const headers = COL_DEFS.filter((c) => c.key !== 'check' && c.key !== 'pdf').map((c) => c.label);
        const lines = [headers.join(',')];
        filtered.forEach((r, i) => {
            lines.push(COL_DEFS.filter((c) => c.key !== 'check' && c.key !== 'pdf').map((c) => {
                const raw = c.key === 'sno' ? i + 1 : (c.key === 'po' ? r.purchase_order : (c.key === 'status' ? r._validation_status : rowField(r, c.key)));
                return `"${String(raw ?? '').replace(/"/g, '""')}"`;
            }).join(','));
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
        allRows.forEach((r) => {
            r._checked = on && String(r.purchase_order || '') === String(po || '');
        });
        if (on && po) activePo = po;
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
            const range = currentRange();
            if (range === 'custom') {
                openCustomDateModal();
                return;
            }
            lastNonCustomRange = range;
            applyRangePreset(range, { fillDates: true });
            void fetchPos();
        });

        // Custom Date Modal handlers
        $('poCustomDateApply')?.addEventListener('click', applyCustomDatesAndLoad);
        $('poCustomDateCancel')?.addEventListener('click', () => closeCustomDateModal({ revertSelect: true }));
        $('poCustomDateClose')?.addEventListener('click', () => closeCustomDateModal({ revertSelect: true }));
        $('poCustomDateModal')?.addEventListener('click', (e) => {
            if (e.target === $('poCustomDateModal')) closeCustomDateModal({ revertSelect: true });
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && $('poCustomDateModal') && !$('poCustomDateModal').hidden) {
                closeCustomDateModal({ revertSelect: true });
            }
        });
        $('poCustomModalFrom')?.addEventListener('input', () => setCustomDateError(''));
        $('poCustomModalTo')?.addEventListener('input', () => setCustomDateError(''));
        $('poCustomModalFrom')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); applyCustomDatesAndLoad(); }
        });
        $('poCustomModalTo')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); applyCustomDatesAndLoad(); }
        });

        document.querySelectorAll('input[name="pcb_mat"]').forEach((el) => {
            el.addEventListener('change', () => {
                selectedMat();
                void fetchPos();
            });
        });
        $('pcbSupplier')?.addEventListener('change', () => void fetchPos());
        $('pcbPoSearchBtn')?.addEventListener('click', () => searchPoHeader());
        $('pcbPoSearch')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchPoHeader();
            }
        });
        $('pcbResetBtn')?.addEventListener('click', () => {
            if ($('pcbRange')) $('pcbRange').value = 'today';
            lastNonCustomRange = 'today';
            applyRangePreset('today', { fillDates: true });
            if ($('pcbSupplier')) $('pcbSupplier').value = '';
            if ($('pcbValStatus')) $('pcbValStatus').value = 'all';
            if ($('pcbMat')) $('pcbMat').value = 'all';
            document.querySelectorAll('input[name="pcb_mat"]').forEach((el) => {
                el.checked = el.value === 'all';
            });
            if ($('pcbPoSearch')) $('pcbPoSearch').value = '';
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => { inp.value = ''; });
            Object.keys(colFilterState).forEach((k) => { delete colFilterState[k]; });
            stopRuntimeValidation();
            void fetchPos();
        });
        $('pcbQuickSearch')?.addEventListener('input', () => applyFilter());
        $('pcbQuickSearchClear')?.addEventListener('click', () => {
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            applyFilter();
        });
        document.addEventListener('input', (e) => {
            const inp = e.target && e.target.closest ? e.target.closest('#pcbFilterRow .pcb-col-filter') : null;
            if (!inp) return;
            const key = inp.getAttribute('data-key');
            if (!key) return;
            const val = String(inp.value || '').trim();
            if (val) colFilterState[key] = val;
            else delete colFilterState[key];
            applyFilter();
        }, true);
        $('pcbColFilterReset')?.addEventListener('click', () => {
            document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => { inp.value = ''; });
            Object.keys(colFilterState).forEach((k) => { delete colFilterState[k]; });
            applyFilter();
        });
        $('pcbClearAllFilters')?.addEventListener('click', () => {
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => { inp.value = ''; });
            Object.keys(colFilterState).forEach((k) => { delete colFilterState[k]; });
            applyFilter();
        });
        $('pcbToggleColFilters')?.addEventListener('click', () => {
            const row = $('pcbFilterRow');
            const btn = $('pcbToggleColFilters');
            if (!row) return;
            const hidden = window.getComputedStyle(row).display === 'none';
            row.style.display = hidden ? 'table-row' : 'none';
            btn?.classList.toggle('is-active', hidden);
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
        pcbTablePrefManager = initTablePreferences({
            scriptId: 'pcbTablePrefs',
            tableId: 'pcbTable',
            menuId: 'pcbColumnToggleMenu',
            toggleBtnId: 'pcbColumnToggleBtn',
            dropdownId: 'pcbColDropdown',
            statusId: 'pcbColSaveStatus',
            colAttr: 'data-col-key',
        });
        $('pcbExportBtn')?.addEventListener('click', exportCsv);
        $('pcbPrev')?.addEventListener('click', () => { if (page > 1) { page--; renderTable(); } });
        $('pcbNext')?.addEventListener('click', () => {
            const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
            if (page < pages) { page++; renderTable(); }
        });
        const onListClick = (e) => {
            const pdfBtn = e.target.closest('button.pcb-pdf-btn');
            if (pdfBtn) {
                e.preventDefault();
                e.stopPropagation();
                const po = pdfBtn.getAttribute('data-po');
                if (po) window.open(pdfUrl(po), '_blank', 'noopener');
                return;
            }
            const checkCell = e.target.closest('.pcb-check');
            const check = e.target.closest('.pcb-row-check, .pcb-card-check');
            if (check || checkCell) {
                const input = (check && check.matches('input')) ? check : (checkCell || check).querySelector('input');
                if (input) {
                    if (e.target !== input) input.checked = !input.checked;
                    setChecked(input.getAttribute('data-po'), input.checked);
                    renderTable();
                }
                e.stopPropagation();
                return;
            }
            const row = e.target.closest('tr.pcb-row, article.pcb-card, button.pcb-po-link');
            if (!row) return;
            const po = row.getAttribute('data-po');
            if (po) {
                e.preventDefault();
                openDrill(po);
            }
        };
        $('pcbTableBody')?.addEventListener('click', onListClick);
        $('pcbCards')?.addEventListener('click', onListClick);

        $('pcbOpenValidateBtn')?.addEventListener('click', () => {
            if (cfg.canValidate === false) return;
            openValidateModal();
        });
        if ($('pcbOpenValidateBtn') && cfg.canValidate === false) {
            $('pcbOpenValidateBtn').hidden = true;
        }
        $('pcbHistoryBtn')?.addEventListener('click', () => { void openHistoryModal(); });
        $('pcwValidateClose')?.addEventListener('click', closeValidateModal);
        $('pcwValidateCancel')?.addEventListener('click', closeValidateModal);
        $('pcwValidateBackdrop')?.addEventListener('click', closeValidateModal);
        $('pcwValidateSave')?.addEventListener('click', () => { void saveValidationLog(); });
        $('pcwHistoryClose')?.addEventListener('click', closeHistoryModal);
        $('pcwHistoryDone')?.addEventListener('click', closeHistoryModal);
        $('pcwHistoryBackdrop')?.addEventListener('click', closeHistoryModal);
        document.querySelectorAll('input[name="pcwValidateStatus"]').forEach((el) => {
            el.addEventListener('change', () => {
                document.querySelectorAll('input[name="pcwValidateStatus"]').forEach((r) => {
                    r.closest('label')?.classList.toggle('is-active', r.checked);
                });
            });
        });
        const userSearch = $('pcwUserSearch');
        userSearch?.addEventListener('input', () => {
            const q = String(userSearch.value || '').trim();
            if (assignedUser.username && q !== assignedUser.username) {
                assignedUser = { id: '', username: '' };
                if ($('pcwUserId')) $('pcwUserId').value = '';
                if ($('pcwUserName')) $('pcwUserName').value = '';
            }
            void searchUsers(q);
        });
        userSearch?.addEventListener('focus', () => {
            void searchUsers(String(userSearch.value || '').trim());
        });
        $('pcwUserMenu')?.addEventListener('click', (e) => {
            const opt = e.target.closest('.pcw-user-option');
            if (!opt) return;
            pickUser(opt.getAttribute('data-id'), opt.getAttribute('data-username'));
        });
        document.addEventListener('click', (e) => {
            if (e.target.closest('#pcwUserAssign')) return;
            if ($('pcwUserMenu')) $('pcwUserMenu').hidden = true;
        });
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            if ($('pcwValidateModal') && !$('pcwValidateModal').hidden) closeValidateModal();
            if ($('pcwHistoryModal') && !$('pcwHistoryModal').hidden) closeHistoryModal();
        });

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
        // Default: clean item table — filters open only on button click
        if ($('pcbItemFilterRow')) $('pcbItemFilterRow').style.display = 'none';
        $('pcbItemToggleFilters')?.classList.remove('is-active');
        $('pcbItemToggleFilters')?.addEventListener('click', () => {
            const row = $('pcbItemFilterRow');
            const btn = $('pcbItemToggleFilters');
            if (!row) return;
            const show = row.style.display === 'none';
            row.style.display = show ? '' : 'none';
            btn?.classList.toggle('is-active', show);
        });
        expandItemTableFully();
        pcbItemTablePrefManager = initTablePreferences({
            scriptId: 'pcbItemTablePrefs',
            tableId: 'pcbItemTable',
            menuId: 'pcbItemColumnMenu',
            toggleBtnId: 'pcbItemColToggle',
            dropdownId: 'pcbItemColDropdown',
            statusId: 'pcbItemColSaveStatus',
            colAttr: 'data-col-key',
        });
        syncItemGroupHeaders();
        $('pcbItemExcelBtn')?.addEventListener('click', exportItemCsv);
        $('pcbItemSelectAll')?.addEventListener('change', (e) => {
            const on = !!e.target.checked;
            filterItemRows().forEach((r) => { r._checked = on; });
            renderItemTable();
        });
        $('pcbItemDetailClose')?.addEventListener('click', () => hideItemDetail());
        $('pcbItemBody')?.addEventListener('click', (e) => {
            const quoteHit = e.target.closest('a.pcw-quote-link, td[data-col-id="costing_quotation_no"]');
            if (quoteHit) {
                e.preventDefault();
                e.stopPropagation();
                const href = quoteHit.getAttribute('href') || zcostViewUrl(quoteHit.getAttribute('data-quote') || '');
                if (href) window.open(href, '_blank', 'noopener,noreferrer');
                return;
            }
            const check = e.target.closest('.pcb-item-check');
            if (check) {
                const idx = Number(check.getAttribute('data-idx'));
                if (!Number.isNaN(idx) && activeItemRows[idx]) {
                    activeItemRows[idx]._checked = check.checked;
                    if (check.checked) {
                        showItemDetail(idx, { scroll: true });
                    } else {
                        renderItemTable();
                    }
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
