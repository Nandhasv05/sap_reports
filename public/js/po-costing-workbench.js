/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 30/10/2026
 * DESCRIPTION : PO Costing Workbench — full PO + ZCOST columns, search, filters, column hide/reorder, Excel
 */
(function () {
    let cfg = {};
    try {
        cfg = JSON.parse(document.getElementById('poConfig')?.textContent || '{}');
    } catch (e) {
        cfg = {};
    }

    let prefsCfg = null;
    try {
        prefsCfg = JSON.parse(document.getElementById('rptTablePrefs')?.textContent || 'null');
    } catch (e) {
        prefsCfg = null;
    }

    const root = document.getElementById('poCostingWorkbench');
    const $ = (id) => document.getElementById(id);
    const spinner = $('rptSpinner');
    const table = $('rptDataTable');
    const tbody = $('rptTableBody');
    const wrap = $('rptTableWrap');

    const money = (n) =>
        n === null || n === undefined || n === ''
            ? '—'
            : Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qty = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
    const num = (n, dig = 4) => {
        if (n === null || n === undefined || n === '') return '—';
        const v = Number(n);
        if (!Number.isFinite(v)) return '—';
        return v.toLocaleString('en-IN', { maximumFractionDigits: dig });
    };
    const esc = (v) =>
        String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));
    const fmtDate = (iso) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        return m ? `${m[3]}-${m[2]}-${m[1]}` : dash(iso);
    };

    const COLS = (prefsCfg && Array.isArray(prefsCfg.columns) ? prefsCfg.columns : []).map((c) => c.id);
    const NUM_COLS = new Set([
        'order_qty', 'net_value', 'net', 'waste', 'gross', 'rate', 'total_inr', 'total_fr', 'convr',
        'expected_unit_cost', 'po_unit_cost', 'variance_pct', 'eu_expected', 'eu_variance', 'po_item', 'sno',
    ]);

    let rows = [];
    let quoteOptions = [];
    let validatedQuotes = [];
    let nonValidatedQuotes = [];
    let selectedCustomer = '';
    let selectedEndUser = '';
    let currentMode = 'validated';
    let sortKey = null;
    let sortDir = null;
    let colFeatures = null;
    let filterVisible = true;

    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    function headerPo() {
        return String($('po')?.value || root?.dataset.po || cfg.po || '').replace(/\D/g, '');
    }
    function headerSo() {
        return String($('so')?.value || root?.dataset.so || cfg.salesOrder || '').replace(/\D/g, '');
    }
    function selectedMat() {
        const el = document.querySelector('input[name="pcw_mat"]:checked');
        return el ? el.value : root?.dataset.mat || cfg.matCat || 'all';
    }

    function fillKpis(summary) {
        const s = summary || {};
        if ($('pcwKpiItems')) $('pcwKpiItems').textContent = qty(s.po_items || 0);
        if ($('pcwKpiPassed')) $('pcwKpiPassed').textContent = qty(s.passed || 0);
        if ($('pcwKpiExceptions')) $('pcwKpiExceptions').textContent = qty(s.exceptions || 0);
        if ($('pcwKpiReview')) $('pcwKpiReview').textContent = qty(s.needs_review || 0);
        if ($('pcwKpiValue')) {
            const cur = s.currency === 'INR' ? '₹ ' : '';
            $('pcwKpiValue').textContent = cur + money(s.total_po_value || 0);
        }
    }

    function statusPill(status) {
        const st = String(status || '').toUpperCase();
        let cls = 'is-fail';
        let label = st || '—';
        if (st === 'PASS') cls = 'is-pass';
        else if (st === 'NEEDS_REVIEW') {
            cls = 'is-review';
            label = 'NEEDS REVIEW';
        }
        return `<span class="pcw-pill ${cls}">${esc(label)}</span>`;
    }

    function varianceCell(pct) {
        if (pct === null || pct === undefined || pct === '') return '—';
        const n = Number(pct);
        const cls = n > 0 ? 'is-bad' : 'is-good';
        const sign = n > 0 ? '+' : '';
        return `<span class="pcw-var ${cls}">${sign}${n.toFixed(1)}%</span>`;
    }

    function optionList(exclude) {
        const opts = quoteOptions.length
            ? quoteOptions
            : [
                  ...validatedQuotes.map((q) => ({ quotation: q, is_validated: true })),
                  ...nonValidatedQuotes.map((q) => ({ quotation: q, is_validated: false })),
              ];
        return opts.filter((o) => String(o.quotation || '') && String(o.quotation) !== String(exclude || ''));
    }

    function quoteLabel(opt) {
        const q = String(opt.quotation || '');
        const tag = opt.is_validated ? 'Validated' : 'Non-validated';
        const lines =
            (opt.fabric_lines || 0) + (opt.trim_lines || 0) + (opt.packing_lines || 0) + (opt.special_lines || 0);
        return lines ? `${q} · ${tag} · ${lines} lines` : `${q} · ${tag}`;
    }

    function needsDropdown(r) {
        if (currentMode === 'non_validated') return true;
        if (!validatedQuotes.length) return true;
        const hasMatch = !!(r.maktx || r.cost_description || r.cost_section || r.cost_nav);
        if (!hasMatch) return true;
        const st = String(r.status || '').toUpperCase();
        if (st === 'EXCEPTION' && /no matching|no linked|cannot load/i.test(String(r.validation_result || ''))) {
            return true;
        }
        return false;
    }

    function selectHtml(kind, selected, exclude) {
        const opts = optionList(exclude);
        const placeholder = kind === 'customer' ? 'Select Customer…' : 'Select End User…';
        const options = [`<option value="">${esc(placeholder)}</option>`]
            .concat(
                opts.map((o) => {
                    const q = String(o.quotation);
                    const sel = q === String(selected || '') ? ' selected' : '';
                    return `<option value="${esc(q)}"${sel}>${esc(quoteLabel(o))}</option>`;
                })
            )
            .join('');
        return `<select class="pcw-row-select" data-kind="${kind}">${options}</select>`;
    }

    function cellValue(key, r, index) {
        switch (key) {
            case 'sno':
                return String(index + 1);
            case 'status':
                return String(r.status || '');
            case 'purchase_order':
                return r.purchase_order || '';
            case 'po_item':
                return r.po_item || '';
            case 'quotation':
                return r.quotation || r.customer_quotation || '';
            case 'sales_order':
                return r.sales_order || '';
            case 'po_type':
                return r.po_type || '';
            case 'po_type_name':
                return r.po_type_name || '';
            case 'po_date':
                return r.po_date || '';
            case 'supplier_full_name':
                return r.supplier_full_name || '';
            case 'supplier':
                return r.supplier || '';
            case 'created_by':
                return r.created_by || '';
            case 'material':
                return r.material || '';
            case 'material_desc':
                return r.material_desc || r.description || '';
            case 'material_type':
                return r.material_type || '';
            case 'mat_type_desc':
                return r.mat_type_desc || '';
            case 'material_group':
                return r.material_group || '';
            case 'mat_grp_desc':
                return r.mat_grp_desc || '';
            case 'plant':
                return r.plant || '';
            case 'order_qty':
                return r.order_qty ?? r.po_qty;
            case 'unit':
                return r.unit || '';
            case 'net_value':
                return r.net_value;
            case 'currency':
                return r.currency || '';
            case 'item_text':
                return r.item_text || '';
            case 'customer':
                return r.customer_quotation || selectedCustomer || '';
            case 'end_user':
                return r.end_user_quotation || selectedEndUser || '';
            case 'cost_nav':
                return r.cost_nav || '';
            case 'vbeln':
                return r.vbeln || '';
            case 'posnr':
                return r.posnr || '';
            case 'zline':
                return r.zline || '';
            case 'cost_item':
                return r.cost_item || '';
            case 'maktx':
                return r.maktx || r.cost_description || '';
            case 'wid_siz':
                return r.wid_siz || '';
            case 'net':
                return r.net;
            case 'waste':
                return r.waste;
            case 'gross':
                return r.gross;
            case 'curunit':
                return r.curunit || r.quoted_currency || '';
            case 'rate':
                return r.rate ?? r.cost_rate;
            case 'total_inr':
                return r.total_inr ?? r.cost_total_inr;
            case 'total_fr':
                return r.total_fr;
            case 'convr':
                return r.convr;
            case 'zsection':
                return r.zsection || '';
            case 'expected_unit_cost':
                return r.expected_unit_cost;
            case 'po_unit_cost':
                return r.po_unit_cost;
            case 'variance_pct':
                return r.variance_pct;
            case 'eu_maktx':
                return r.end_user_maktx || '';
            case 'eu_expected':
                return r.end_user_expected_unit_cost;
            case 'eu_variance':
                return r.end_user_variance_pct;
            case 'validation_result':
                return r.validation_result || '';
            default:
                return r[key] ?? '';
        }
    }

    function renderCell(key, r, index) {
        const cls = NUM_COLS.has(key) ? ' class="num"' : key === 'sno' ? ' class="num sno"' : '';
        const open = needsDropdown(r) ? ' is-open' : '';
        let inner = '—';
        switch (key) {
            case 'sno':
                inner = esc(index + 1);
                break;
            case 'status':
                inner = statusPill(r.status);
                break;
            case 'purchase_order':
                inner = `<span class="pcw-mat">${dash(r.purchase_order)}</span>`;
                break;
            case 'material':
                inner = `<span class="pcw-mat">${dash(r.material)}</span>`;
                break;
            case 'material_desc':
                inner = `<span class="pcw-compare-po" title="${esc(r.material_desc || r.description || '')}">${dash(r.material_desc || r.description)}</span>`;
                break;
            case 'material_type':
                inner = `${dash(r.material_type)}${r.mat_type_desc ? '' : ''}`;
                break;
            case 'mat_type_desc':
                inner = r.mat_type_desc
                    ? `<span class="po-mat-chip is-type-desc">${esc(r.mat_type_desc)}</span>`
                    : '—';
                break;
            case 'po_date':
                inner = fmtDate(r.po_date);
                break;
            case 'supplier_full_name':
                inner = `${dash(r.supplier_full_name)}${r.supplier ? `<div class="pcw-sub">${esc(r.supplier)}</div>` : ''}`;
                break;
            case 'customer':
                inner = needsDropdown(r)
                    ? selectHtml('customer', selectedCustomer || r.customer_quotation || '', selectedEndUser)
                    : `<span class="pcw-quote-fixed">${dash(r.customer_quotation || r.quotation || validatedQuotes[0])}</span>`;
                break;
            case 'end_user':
                inner = needsDropdown(r)
                    ? selectHtml('end_user', selectedEndUser || r.end_user_quotation || '', selectedCustomer)
                    : '—';
                break;
            case 'cost_nav':
                inner = r.cost_nav ? `<span class="pcw-nav-chip">${esc(r.cost_nav)}</span>` : '—';
                break;
            case 'maktx':
            case 'eu_maktx':
                inner = `<span class="pcw-compare-maktx" title="${esc(cellValue(key, r, index))}">${dash(cellValue(key, r, index))}</span>`;
                break;
            case 'order_qty':
                inner = qty(cellValue(key, r, index));
                break;
            case 'expected_unit_cost':
            case 'po_unit_cost':
            case 'eu_expected':
            case 'net_value':
                inner = money(cellValue(key, r, index));
                break;
            case 'variance_pct':
            case 'eu_variance':
                inner = varianceCell(cellValue(key, r, index));
                break;
            case 'net':
            case 'waste':
            case 'gross':
            case 'rate':
            case 'total_inr':
            case 'total_fr':
                inner = num(cellValue(key, r, index));
                break;
            case 'convr':
                inner = num(cellValue(key, r, index), 3);
                break;
            default:
                inner = dash(cellValue(key, r, index));
        }
        return `<td${cls} data-col-id="${esc(key)}">${inner}</td>`;
    }

    function searchQuery() {
        return String($('rptTableSearch')?.value || '').trim().toLowerCase();
    }

    function colFilters() {
        const out = [];
        document.querySelectorAll('#rptFilterRow .rpt-col-input').forEach((input) => {
            const raw = String(input.value || '').trim();
            if (!raw) return;
            const key = input.getAttribute('data-col-key') || COLS[Number(input.getAttribute('data-col'))];
            if (!key) return;
            out.push({ key, raw: raw.toLowerCase(), numeric: input.hasAttribute('data-numeric') });
        });
        return out;
    }

    function matchRow(r, index, query, filters) {
        if (query) {
            const blob = COLS.map((k) => String(cellValue(k, r, index) ?? '')).join(' ').toLowerCase();
            if (!blob.includes(query)) return false;
        }
        for (const f of filters) {
            const val = cellValue(f.key, r, index);
            if (f.numeric) {
                const n = Number(val);
                const m = /^(>=|<=|>|<|=)?\s*(-?\d+(?:\.\d+)?)$/.exec(f.raw.replace(',', ''));
                if (!m || !Number.isFinite(n)) return false;
                const op = m[1] || '=';
                const target = Number(m[2]);
                if (op === '>' && !(n > target)) return false;
                if (op === '>=' && !(n >= target)) return false;
                if (op === '<' && !(n < target)) return false;
                if (op === '<=' && !(n <= target)) return false;
                if (op === '=' && n !== target) return false;
            } else if (!String(val ?? '').toLowerCase().includes(f.raw)) {
                return false;
            }
        }
        return true;
    }

    function sortedRows(list) {
        if (!sortKey || !sortDir) return list;
        const dir = sortDir === 'desc' ? -1 : 1;
        const numeric = NUM_COLS.has(sortKey);
        return [...list].sort((a, b) => {
            const av = cellValue(sortKey, a.row, a.index);
            const bv = cellValue(sortKey, b.row, b.index);
            if (numeric) {
                const an = Number(av);
                const bn = Number(bv);
                const aOk = Number.isFinite(an);
                const bOk = Number.isFinite(bn);
                if (!aOk && !bOk) return 0;
                if (!aOk) return 1;
                if (!bOk) return -1;
                return (an - bn) * dir;
            }
            return String(av ?? '').localeCompare(String(bv ?? ''), undefined, { sensitivity: 'base' }) * dir;
        });
    }

    function renderTable() {
        if (!tbody) return;
        const query = searchQuery();
        const filters = colFilters();
        const indexed = rows.map((row, index) => ({ row, index }));
        const matched = sortedRows(indexed.filter(({ row, index }) => matchRow(row, index, query, filters)));

        const noMatch = $('rptNoMatchRow');
        const countEl = $('rptTableCount');
        const badge = $('rptActiveFilterBadge');
        const clearBtn = $('rptClearAllFilters');
        const isFiltered = query !== '' || filters.length > 0;

        if (countEl) {
            countEl.textContent = `Showing ${matched.length} of ${rows.length} items` + (isFiltered ? ` (filtered)` : '');
            countEl.classList.toggle('is-filtered', isFiltered);
        }
        if (badge) {
            badge.style.display = filters.length ? 'inline-flex' : 'none';
            badge.textContent = String(filters.length);
        }
        if (clearBtn) clearBtn.style.display = isFiltered ? 'inline-flex' : 'none';

        if (!rows.length) {
            tbody.innerHTML = `<tr class="pcw-empty"><td colspan="${COLS.length || 1}">Load a PO from the header search to compare.</td></tr>`;
            if (noMatch) noMatch.style.display = 'none';
            colFeatures?.refresh?.();
            return;
        }

        if (!matched.length) {
            tbody.querySelectorAll('tr.pcw-row').forEach((tr) => tr.remove());
            if (noMatch) {
                if (!tbody.contains(noMatch)) tbody.appendChild(noMatch);
                noMatch.style.display = '';
            }
            colFeatures?.refresh?.();
            return;
        }

        if (noMatch) noMatch.style.display = 'none';
        tbody.innerHTML = matched
            .map(({ row, index }) => {
                const st = String(row.status || '').toUpperCase();
                const open = needsDropdown(row) ? ' is-open' : '';
                const pass = st === 'PASS' ? ' is-pass-row' : '';
                const fail = st === 'EXCEPTION' ? ' is-fail-row' : '';
                return `<tr class="pcw-row${open}${pass}${fail}" data-idx="${index}">${COLS.map((k) => renderCell(k, row, index)).join('')}</tr>`;
            })
            .join('');
        if (noMatch) tbody.appendChild(noMatch);
        colFeatures?.refresh?.();
    }

    async function runValidate(opts) {
        const options = opts || {};
        const po = headerPo();
        if (!/^\d{1,10}$/.test(po)) {
            return;
        }
        const so = headerSo();
        const mat = selectedMat();
        const customer = String(options.customer ?? selectedCustomer ?? '').replace(/\D/g, '');
        const endUser = String(options.endUser ?? selectedEndUser ?? '').replace(/\D/g, '');
        const mode =
            options.mode ||
            (customer ? 'non_validated' : currentMode === 'non_validated' && !options.forceValidated ? 'non_validated' : 'validated');

        const endpoint = cfg.validateUrl || 'purchase-order/validate';
        const params = new URLSearchParams({ po, mat_cat: mat, mode, _: String(Date.now()) });
        if (so) params.set('so', so);
        if (customer) params.set('customer', customer);
        if (endUser) params.set('end_user', endUser);

        showSpinner(true);
        try {
            const res = await fetch(`${endpoint}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            const data = json.data || {};
            rows = Array.isArray(data.records) ? data.records : [];
            quoteOptions = Array.isArray(data.quote_options) ? data.quote_options : [];
            validatedQuotes = Array.isArray(data.validated_quotations) ? data.validated_quotations : [];
            nonValidatedQuotes = Array.isArray(data.non_validated_quotations) ? data.non_validated_quotations : [];
            currentMode = data.mode || mode;
            selectedCustomer = String(data.customer_quotation || customer || '');
            selectedEndUser = String(data.end_user_quotation || endUser || '');

            fillKpis(data.summary || {});
            renderTable();

            const page = cfg.pageUrl || 'purchase-order';
            const next = new URL(page, window.location.origin);
            next.searchParams.set('view', 'costing');
            next.searchParams.set('po', po);
            if (so) next.searchParams.set('so', so);
            if (mat !== 'all') next.searchParams.set('mat_cat', mat);
            if (currentMode === 'non_validated') next.searchParams.set('mode', 'non_validated');
            if (selectedCustomer) next.searchParams.set('customer', selectedCustomer);
            if (selectedEndUser) next.searchParams.set('end_user', selectedEndUser);
            window.history.replaceState({}, '', next.pathname + next.search);
            document.title = `EVOLV | PO Costing ${po}`;
            if (root) {
                root.dataset.po = po;
                root.dataset.so = so;
                root.dataset.mat = mat;
            }
        } catch (err) {
            rows = [];
            fillKpis({});
            renderTable();
        } finally {
            showSpinner(false);
        }
    }

    function onRowSelectChange(e) {
        const sel = e.target.closest('select.pcw-row-select');
        if (!sel) return;
        e.stopPropagation();
        const kind = sel.getAttribute('data-kind');
        const val = String(sel.value || '').replace(/\D/g, '');
        if (kind === 'customer') {
            selectedCustomer = val;
            if (selectedEndUser === selectedCustomer) selectedEndUser = '';
        } else {
            selectedEndUser = val;
        }
        if (!selectedCustomer) {
            currentMode = 'validated';
            void runValidate({ mode: 'validated', customer: '', endUser: '', forceValidated: true });
            return;
        }
        void runValidate({ mode: 'non_validated', customer: selectedCustomer, endUser: selectedEndUser });
    }

    function clearFilters() {
        const search = $('rptTableSearch');
        if (search) search.value = '';
        document.querySelectorAll('#rptFilterRow .rpt-col-input').forEach((i) => {
            i.value = '';
        });
        $('rptTableSearchClear') && ($('rptTableSearchClear').style.display = 'none');
        renderTable();
    }

    function bindToolbar() {
        const search = $('rptTableSearch');
        const clear = $('rptTableSearchClear');
        let t = null;
        search?.addEventListener('input', () => {
            if (clear) clear.style.display = search.value ? 'inline-flex' : 'none';
            clearTimeout(t);
            t = setTimeout(renderTable, 100);
        });
        clear?.addEventListener('click', () => {
            if (search) search.value = '';
            clear.style.display = 'none';
            renderTable();
        });

        document.querySelectorAll('#rptFilterRow .rpt-col-input').forEach((input) => {
            input.addEventListener('input', () => {
                clearTimeout(t);
                t = setTimeout(renderTable, 100);
            });
            input.closest('.rpt-col-input-wrap')?.querySelector('.rpt-col-clear')?.addEventListener('click', () => {
                input.value = '';
                renderTable();
            });
        });

        $('rptColFilterReset')?.addEventListener('click', clearFilters);
        $('rptClearAllFilters')?.addEventListener('click', clearFilters);
        $('rptResetFilterTableBtn')?.addEventListener('click', clearFilters);

        $('rptToggleColFilters')?.addEventListener('click', () => {
            filterVisible = !filterVisible;
            const row = $('rptFilterRow');
            if (row) row.style.display = filterVisible ? '' : 'none';
            $('rptToggleColFilters')?.classList.toggle('is-active', filterVisible);
        });

        $('rptToggleStickyScroll')?.addEventListener('click', () => {
            const on = !wrap?.classList.contains('is-scroll-body');
            wrap?.classList.toggle('is-scroll-body', on);
            $('rptToggleStickyScroll')?.classList.toggle('is-active', on);
            if ($('rptStickyScrollLabel')) {
                $('rptStickyScrollLabel').textContent = on ? 'Scroll Body Only' : 'Expand Table';
            }
        });

        table?.querySelector('thead tr.rpt-header-row')?.addEventListener('click', (e) => {
            if (e.target.closest('select, input, button, a')) return;
            const th = e.target.closest('th[data-col-id]');
            if (!th || th.getAttribute('draggable') === 'true' && e.detail > 1) return;
            // Allow drag; single click sorts
            if (e.defaultPrevented) return;
            const key = th.getAttribute('data-col-id');
            if (!key || key === 'customer' || key === 'end_user') return;
            if (sortKey === key) {
                sortDir = sortDir === 'asc' ? 'desc' : sortDir === 'desc' ? null : 'asc';
                if (!sortDir) sortKey = null;
            } else {
                sortKey = key;
                sortDir = 'asc';
            }
            table.querySelectorAll('thead th.is-sortable').forEach((h) => {
                h.classList.remove('is-asc', 'is-desc');
            });
            if (sortKey && sortDir) th.classList.add(sortDir === 'asc' ? 'is-asc' : 'is-desc');
            renderTable();
        });
    }

    function bind() {
        bindToolbar();
        $('pcwRevalidateBtn')?.addEventListener('click', () => {
            if (selectedCustomer) {
                void runValidate({ mode: 'non_validated', customer: selectedCustomer, endUser: selectedEndUser });
            } else {
                void runValidate({ mode: 'validated', forceValidated: true });
            }
        });
        document.querySelectorAll('input[name="pcw_mat"]').forEach((el) => {
            el.addEventListener('change', () => {
                if (selectedCustomer) {
                    void runValidate({ mode: 'non_validated', customer: selectedCustomer, endUser: selectedEndUser });
                } else {
                    void runValidate({ mode: 'validated', forceValidated: true });
                }
            });
        });
        tbody?.addEventListener('change', onRowSelectChange);

        if (typeof window.rptSetupColumnFeatures === 'function' && table) {
            colFeatures = window.rptSetupColumnFeatures(table, { onChange: () => renderTable() });
        }
    }

    try {
        const sp = new URLSearchParams(window.location.search);
        if (sp.get('mode') === 'non_validated') currentMode = 'non_validated';
        selectedCustomer = String(sp.get('customer') || '').replace(/\D/g, '');
        selectedEndUser = String(sp.get('end_user') || '').replace(/\D/g, '');
    } catch (e) {
        /* ignore */
    }

    bind();
    if (headerPo() || cfg.po) {
        void runValidate({
            mode: selectedCustomer ? 'non_validated' : 'validated',
            customer: selectedCustomer,
            endUser: selectedEndUser,
            forceValidated: !selectedCustomer,
        });
    } else {
        showSpinner(false);
        renderTable();
    }
})();
