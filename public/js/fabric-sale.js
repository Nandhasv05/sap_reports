/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 29/10/2026
 * DESCRIPTION : Fabric Sale Order — lookup → details (KPI cards + PO-style table) → 90% fabric offcanvas
 */
(function () {
    const page = document.getElementById('fsoPage');
    if (!page) return;

    const $ = (id) => document.getElementById(id);
    const spinner = $('rptSpinner');
    const ordersUrl = page.dataset.ordersUrl || '';
    const soUrl = page.dataset.soUrl || '';
    const fabricUrl = page.dataset.fabricUrl || '';
    const fabricReportUrl = page.dataset.fabricReportUrl || '';
    let presetDates = {};
    try {
        presetDates = JSON.parse(page.dataset.presetDates || '{}') || {};
    } catch (e) {
        presetDates = {};
    }

    const esc = (v) =>
        String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));
    const num = (n, dig = 2) =>
        n === null || n === undefined || n === ''
            ? '—'
            : Number(n).toLocaleString('en-IN', { maximumFractionDigits: dig });
    const qtyFmt = (n) => {
        const v = Number(n);
        if (!Number.isFinite(v) || v === 0) return '—';
        return Math.abs(v - Math.round(v)) < 0.0005
            ? Math.round(v).toLocaleString('en-IN')
            : v.toLocaleString('en-IN', { maximumFractionDigits: 3 });
    };

    let drawerTotalsChart = null;
    let drawerMixChart = null;
    let drawerRecords = [];
    const mixColors = ['#0f766e', '#0284c7', '#7c3aed', '#d97706', '#16a34a', '#e11d48', '#0ea5e9', '#94a3b8'];

    let allOrders = [];
    let filteredOrders = [];
    let currentFrom = page.dataset.from || '';
    let currentTo = page.dataset.to || '';
    let currentPreset = page.dataset.preset || '';
    let currentSoFilter = (page.dataset.so || '').trim();
    let soOnlyMode = false;
    let selectedSo = '';
    let colFilters = {};
    let sortKey = '';
    let sortDir = 'asc';
    let COL_DEFS = [];
    try {
        COL_DEFS = JSON.parse($('fsoTable')?.dataset?.fsoColumns || '[]') || [];
    } catch (e) {
        COL_DEFS = [];
    }
    if (!COL_DEFS.length) {
        COL_DEFS = [
            { key: 'sno', label: 'S.No', locked: true },
            { key: 'so', label: 'Sale Order', locked: true },
            { key: 'date', label: 'Date' },
            { key: 'buyer', label: 'Buyer' },
            { key: 'material', label: 'Material' },
            { key: 'qty', label: 'Order Qty' },
            { key: 'value', label: 'Net Value' },
            { key: 'currency', label: 'Currency' },
            { key: 'status', label: 'Status' },
            { key: 'plant', label: 'Plant' },
        ];
    }
    let columnFeatures = { refresh() {} };
    try {
        localStorage.removeItem('fso_hidden_cols_v3');
        localStorage.removeItem('fso_hidden_cols_v2');
    } catch (e) {}

    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    function setAlert() {
        /* banner removed — status shown in KPI/table empty states */
    }

    function setFormError(text) {
        const el = $('fsoFormError');
        if (!el) return;
        if (!text) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.hidden = false;
        el.textContent = text;
    }

    function syncPresetUi(presetEl, customEl, fromEl, toEl) {
        const preset = (presetEl?.value || '').toLowerCase();
        if (customEl) {
            customEl.hidden = preset !== 'custom';
            customEl.style.display = preset === 'custom' ? 'flex' : 'none';
        }
        if (preset && preset !== 'custom' && presetDates[preset]) {
            if (fromEl) fromEl.value = presetDates[preset].from || '';
            if (toEl) toEl.value = presetDates[preset].to || '';
        }
    }

    function syncCustomDates() {
        syncPresetUi($('fsoPreset'), $('fsoCustomDates'), $('fsoFrom'), $('fsoTo'));
        syncPresetUi($('fsoHdrPreset'), $('fsoHdrCustomDates'), $('fsoHdrFrom'), $('fsoHdrTo'));
    }

    function syncFormsFromState() {
        if ($('fsoPreset')) $('fsoPreset').value = currentPreset || '';
        if ($('fsoHdrPreset')) $('fsoHdrPreset').value = currentPreset || '';
        if ($('fsoFrom')) $('fsoFrom').value = currentFrom || '';
        if ($('fsoTo')) $('fsoTo').value = currentTo || '';
        if ($('fsoHdrFrom')) $('fsoHdrFrom').value = currentFrom || '';
        if ($('fsoHdrTo')) $('fsoHdrTo').value = currentTo || '';
        if ($('fsoSoInput')) $('fsoSoInput').value = currentSoFilter || '';
        if ($('fsoHdrSo')) $('fsoHdrSo').value = currentSoFilter || '';
        syncCustomDates();
    }

    function showLookup() {
        page.classList.add('is-lookup');
        page.classList.remove('is-details');
        closeDrawer();
        selectedSo = '';
        setAlert('');
        history.replaceState({}, '', location.pathname);
        syncFormsFromState();
        setTimeout(() => $('fsoSoInput')?.focus(), 40);
    }

    function showDetails() {
        page.classList.remove('is-lookup');
        page.classList.add('is-details');
        syncFormsFromState();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        setTimeout(syncHeaderHeight, 60);
    }

    function clearDateFields() {
        currentPreset = '';
        currentFrom = '';
        currentTo = '';
        if ($('fsoHdrPreset')) $('fsoHdrPreset').value = '';
        if ($('fsoPreset')) $('fsoPreset').value = '';
        if ($('fsoHdrFrom')) $('fsoHdrFrom').value = '';
        if ($('fsoHdrTo')) $('fsoHdrTo').value = '';
        if ($('fsoFrom')) $('fsoFrom').value = '';
        if ($('fsoTo')) $('fsoTo').value = '';
        syncCustomDates();
    }

    function clearSoFields() {
        currentSoFilter = '';
        if ($('fsoHdrSo')) $('fsoHdrSo').value = '';
        if ($('fsoSoInput')) $('fsoSoInput').value = '';
    }

    /** Exclusive modes: SO search clears date; date search clears SO */
    function resolveRangeFromForm(source, prefer) {
        const isHeader = source === 'header';
        const preset = ((isHeader ? $('fsoHdrPreset') : $('fsoPreset'))?.value || '').toLowerCase();
        const so = ((isHeader ? $('fsoHdrSo') : $('fsoSoInput'))?.value || '').trim();
        const fromEl = isHeader ? $('fsoHdrFrom') : $('fsoFrom');
        const toEl = isHeader ? $('fsoHdrTo') : $('fsoTo');
        const mode = prefer || (so ? 'so' : 'date');

        if (mode === 'so' && so) {
            return { preset: '', from: '', to: '', so, soOnly: true };
        }

        if (preset === 'custom') {
            return {
                preset: 'custom',
                from: (fromEl?.value || '').trim(),
                to: (toEl?.value || '').trim(),
                so: '',
                soOnly: false,
            };
        }
        if (preset && presetDates[preset]) {
            return {
                preset,
                from: presetDates[preset].from,
                to: presetDates[preset].to,
                so: '',
                soOnly: false,
            };
        }
        if (so) {
            return { preset: '', from: '', to: '', so, soOnly: true };
        }
        return { preset: '', from: '', to: '', so: '', soOnly: false };
    }

    function updateRangeLabels() {
        /* secondary title row removed */
    }

    function rowMap(r) {
        return {
            so: String(r.sales_order || ''),
            date: String(r.creation_date || ''),
            buyer: String(r.customer || r.sold_to_party || ''),
            customer_group: String(r.customer_group || ''),
            sales_district: String(r.sales_district || ''),
            material: String(r.material || ''),
            style: String(r.style || ''),
            orig_material: String(r.orig_material || r.originally_requested_material || ''),
            material_group: String(r.material_group || ''),
            material_by_customer: String(r.material_by_customer || ''),
            lines: Number(r.lines) || 0,
            qty: Number(r.quantity) || 0,
            requested_qty: Number(r.requested_qty ?? r.requested_quantity) || 0,
            confd_deliv_qty: Number(r.confd_deliv_qty) || 0,
            unit: String(r.unit || ''),
            unit_price: Number(r.unit_price) || 0,
            value: Number(r.net_value) || 0,
            cost: Number(r.cost) || 0,
            tax: Number(r.tax_amount) || 0,
            currency: String(r.currency || ''),
            status: String(r.status || ''),
            delivery_status: String(r.delivery_status || ''),
            rejection_status: String(r.rejection_status || r.sd_document_rejection_status || ''),
            plant: String(r.plant || ''),
            storage_location: String(r.storage_location || ''),
            shipping_point: String(r.shipping_point || ''),
            shipping_type: String(r.shipping_type || ''),
            route: String(r.route || ''),
            delivery_priority: String(r.delivery_priority || ''),
            sales_org: String(r.sales_org || ''),
            channel: String(r.distribution_channel || r.channel || ''),
            division: String(r.division || r.organization_division || ''),
            so_type: String(r.sales_order_type || r.so_type || ''),
            item_category: String(r.item_category || ''),
            item_type: String(r.item_type || ''),
            incoterms: String(r.incoterms || ''),
            payment_terms: String(r.payment_terms || ''),
            billing_date: String(r.billing_date || ''),
            pricing_date: String(r.pricing_date || ''),
            last_change_date: String(r.last_change_date || ''),
            created_by: String(r.created_by || ''),
        };
    }

    function cellHtml(key, r, i) {
        const m = rowMap(r);
        const so = m.so;
        const statusClass = r.is_complete ? 'is-done' : 'is-open';
        const attr = `data-col-id="${esc(key)}"`;
        switch (key) {
            case 'sno':
                return `<td class="sno" ${attr}>${i + 1}</td>`;
            case 'so':
                return `<td ${attr}>
                    <button type="button" class="fso-so-pill" data-so="${esc(so)}">
                        <span class="material-icons-round">receipt_long</span>
                        ${dash(so)}
                    </button>
                </td>`;
            case 'qty':
            case 'requested_qty':
            case 'confd_deliv_qty':
            case 'value':
            case 'cost':
            case 'unit_price':
            case 'tax':
            case 'lines':
                return `<td class="num" ${attr}>${num(m[key], key === 'lines' ? 0 : 2)}</td>`;
            case 'material':
                return `<td ${attr} title="${esc(m.material)}"><span class="fso-mat-pill">${dash(m.material)}</span></td>`;
            case 'plant':
                return `<td ${attr}><span class="fso-plant-pill">${dash(m.plant)}</span></td>`;
            case 'status':
                return `<td ${attr}><span class="fso-status ${statusClass}">${dash(m.status)}</span></td>`;
            default:
                return `<td ${attr} title="${esc(m[key] ?? '')}">${dash(m[key])}</td>`;
        }
    }

    function updateFilterChrome() {
        const q = ($('fsoTableFilter')?.value || '').trim();
        const clearBtn = $('fsoTableFilterClear');
        if (clearBtn) clearBtn.style.display = q ? '' : 'none';
        const active = Object.keys(colFilters).length + (q ? 1 : 0);
        const badge = $('fsoActiveFilterBadge');
        if (badge) {
            badge.style.display = active ? '' : 'none';
            badge.textContent = String(active);
        }
        const clearAll = $('fsoClearAllFilters');
        if (clearAll) clearAll.style.display = active ? '' : 'none';
        $('fsoTableCount')?.classList.toggle('is-filtered', active > 0);
    }

    function syncHeaderHeight() {
        const headerRow = $('fsoTable')?.querySelector('thead tr.rpt-header-row');
        if (headerRow && headerRow.offsetHeight > 0) {
            document.documentElement.style.setProperty('--rpt-header-h', headerRow.offsetHeight + 'px');
        }
    }

    function matchFilterValue(val, needle, key, row) {
        if (!needle) return true;
        const sNeedle = String(needle).trim();
        if (!sNeedle) return true;

        const numKeys = ['lines', 'qty', 'requested_qty', 'confd_deliv_qty', 'unit_price', 'value', 'cost', 'tax'];
        const isNum = numKeys.includes(key);

        if (isNum) {
            const numVal = Number(val);
            // Handle comparison operators: >, >=, <, <=, =, ==, !=
            const opMatch = sNeedle.match(/^([><]=?|==?|!=)\s*([0-9,.]*)$/);
            if (opMatch) {
                const op = opMatch[1];
                const numPart = opMatch[2].replace(/,/g, '');
                if (numPart === '' || numPart === '.') {
                    // Operator entered, user typing number - don't filter out everything
                    return true;
                }
                const target = parseFloat(numPart);
                if (Number.isNaN(target)) return true;
                if (Number.isNaN(numVal)) return false;

                switch (op) {
                    case '>': return numVal > target;
                    case '>=': return numVal >= target;
                    case '<': return numVal < target;
                    case '<=': return numVal <= target;
                    case '=':
                    case '==': return Math.abs(numVal - target) < 0.0001;
                    case '!=': return Math.abs(numVal - target) >= 0.0001;
                }
            }

            // Handle range like "10-50" or "10..50"
            const rangeMatch = sNeedle.match(/^(-?\d+(?:\.\d+)?)\s*(?:-|to|\.\.)\s*(-?\d+(?:\.\d+)?)$/i);
            if (rangeMatch) {
                const minVal = parseFloat(rangeMatch[1]);
                const maxVal = parseFloat(rangeMatch[2]);
                if (!Number.isNaN(minVal) && !Number.isNaN(maxVal) && !Number.isNaN(numVal)) {
                    return numVal >= minVal && numVal <= maxVal;
                }
            }

            // Fallback for number without operator: exact equality or substring on raw number
            const target = parseFloat(sNeedle.replace(/,/g, ''));
            if (!Number.isNaN(target) && !Number.isNaN(numVal)) {
                if (Math.abs(numVal - target) < 0.0001) return true;
            }
            return String(val ?? '').toLowerCase().includes(sNeedle.toLowerCase());
        }

        // Sales order comparison (strip leading zeros for easier match)
        if (key === 'so') {
            const cleanVal = String(val ?? '').replace(/^0+/, '').toLowerCase();
            const cleanNeedle = sNeedle.replace(/^0+/, '').toLowerCase();
            return cleanVal.includes(cleanNeedle);
        }

        // Date comparison (check formatted date and optional iso date)
        if (key === 'date') {
            const str = String(val ?? '').toLowerCase();
            const iso = String(row?.creation_date_iso || row?.date_iso || '').toLowerCase();
            const q = sNeedle.toLowerCase();
            return str.includes(q) || (iso && iso.includes(q));
        }

        // Default text substring comparison
        return String(val ?? '').toLowerCase().includes(sNeedle.toLowerCase());
    }

    function applyFilters() {
        const q = ($('fsoTableFilter')?.value || '').trim().toLowerCase();
        updateFilterChrome();

        filteredOrders = allOrders.filter((r) => {
            const map = rowMap(r);
            if (q) {
                const hay = Object.values(map)
                    .map((x) => String(x ?? '').toLowerCase())
                    .join(' ');
                if (!hay.includes(q)) return false;
            }
            for (const [key, needle] of Object.entries(colFilters)) {
                if (!needle) continue;
                if (!matchFilterValue(map[key], needle, key, r)) return false;
            }
            return true;
        });

        if (sortKey) {
            const dir = sortDir === 'desc' ? -1 : 1;
            filteredOrders.sort((a, b) => {
                const ma = rowMap(a);
                const mb = rowMap(b);
                const va = ma[sortKey];
                const vb = mb[sortKey];
                if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
                return String(va).localeCompare(String(vb), undefined, { numeric: true, sensitivity: 'base' }) * dir;
            });
        }
        renderTable();
        updateKpis(filteredOrders);
        syncHeaderHeight();
    }

    function applyStickyMode(enabled) {
        const wrap = $('fsoTableWrap');
        const btn = $('fsoToggleStickyScroll');
        const label = $('fsoStickyScrollLabel');
        if (!wrap) return;
        wrap.classList.toggle('is-scroll-body', enabled);
        wrap.classList.toggle('is-expanded', !enabled);
        btn?.classList.toggle('is-active', enabled);
        if (label) label.textContent = enabled ? 'Scroll Body Only' : 'Full Page Scroll';
        syncHeaderHeight();
    }

    function clearAllFilters() {
        if ($('fsoTableFilter')) $('fsoTableFilter').value = '';
        colFilters = {};
        document.querySelectorAll('#fsoFilterRow .rpt-col-input').forEach((inp) => {
            inp.value = '';
            inp.classList.remove('has-val');
            const wrap = inp.closest('.rpt-col-input-wrap');
            const clr = wrap?.querySelector('.rpt-col-clear');
            if (clr) clr.style.display = 'none';
        });
        applyFilters();
    }

    function updateKpis(orders) {
        const buyers = new Set();
        let qty = 0;
        let value = 0;
        let lines = 0;
        let currency = 'INR';
        orders.forEach((o) => {
            if (o.customer && o.customer !== '—') buyers.add(String(o.customer));
            qty += Number(o.quantity) || 0;
            value += Number(o.net_value) || 0;
            lines += Number(o.lines) || 0;
            if (o.currency) currency = String(o.currency);
        });
        if ($('kpiOrders')) $('kpiOrders').textContent = num(orders.length, 0);
        if ($('kpiLines')) $('kpiLines').textContent = num(lines, 0);
        if ($('kpiQty')) $('kpiQty').textContent = num(qty, 2);
        if ($('kpiValue')) $('kpiValue').textContent = num(value, 2);
        if ($('kpiCurrency')) $('kpiCurrency').textContent = currency || 'INR';
        if ($('kpiBuyers')) $('kpiBuyers').textContent = num(buyers.size, 0);
    }

    function renderTable() {
        const tbody = $('fsoTableBody');
        if (!tbody) return;
        const total = allOrders.length;
        const shown = filteredOrders.length;
        if ($('fsoTableCount')) {
            $('fsoTableCount').textContent =
                total === 0
                    ? 'Showing 0 sale orders'
                    : `Showing ${shown.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')} sale orders`;
        }
        const colCount = COL_DEFS.length || 10;
        if (!filteredOrders.length) {
            const msg = allOrders.length
                ? 'No sale orders match the applied search or filter.'
                : 'No sale orders found for this search.';
            tbody.innerHTML = `<tr><td colspan="${colCount}" class="rpt-table-empty">${msg}</td></tr>`;
            return;
        }
        tbody.innerHTML = filteredOrders
            .map((r, i) => {
                const so = String(r.sales_order || '');
                const active = so === selectedSo ? ' is-active' : '';
                const cells = COL_DEFS.map((c) => cellHtml(c.key, r, i)).join('');
                return `<tr class="fso-row${active}" data-so="${esc(so)}" title="Open fabric for SO ${esc(so)}">${cells}</tr>`;
            })
            .join('');
        columnFeatures.refresh();
    }

    function paintResults(orders) {
        allOrders = orders;
        filteredOrders = orders.slice();
        updateKpis(orders);
        if ($('fsoTableFilter')) $('fsoTableFilter').value = '';
        colFilters = {};
        document.querySelectorAll('#fsoFilterRow .rpt-col-input').forEach((inp) => {
            inp.value = '';
            inp.classList.remove('has-val');
            const wrap = inp.closest('.rpt-col-input-wrap');
            const clr = wrap?.querySelector('.rpt-col-clear');
            if (clr) clr.style.display = 'none';
        });
        applyFilters();
        updateRangeLabels();
        showDetails();
    }

    function syncUrl() {
        const qs = new URLSearchParams();
        if (soOnlyMode && currentSoFilter) {
            qs.set('so', currentSoFilter);
        } else {
            if (currentPreset) qs.set('range', currentPreset);
            if (currentFrom) qs.set('from', currentFrom);
            if (currentTo) qs.set('to', currentTo);
            if (currentSoFilter) qs.set('so', currentSoFilter);
        }
        history.replaceState({}, '', `${location.pathname}?${qs}`);
    }

    function orderFromHubRecords(so, records) {
        let qty = 0;
        let value = 0;
        let tax = 0;
        let first = records[0] || {};
        records.forEach((r) => {
            qty += Number(r.qty ?? r.quantity ?? 0) || 0;
            value += Number(r.net_amount ?? r.net_value ?? 0) || 0;
            tax += Number(r.tax_amount ?? 0) || 0;
        });
        const status = first.status || '—';
        const st = String(status).toLowerCase();
        let cost = 0;
        let reqQty = 0;
        let confQty = 0;
        records.forEach((r) => {
            cost += Number(r.cost ?? 0) || 0;
            reqQty += Number(r.requested_quantity ?? r.requested_qty ?? 0) || 0;
            confQty += Number(r.confd_deliv_qty ?? 0) || 0;
        });
        return {
            sales_order: String(so).replace(/^0+/, '') || so,
            creation_date: first.date || '—',
            customer: first.sold_to_party || first.customer_ref || first.customer || '—',
            sold_to_party: first.sold_to_party || '—',
            customer_group: first.customer_group || '—',
            sales_district: first.sales_district || '—',
            plant: first.plant || '—',
            material: first.material || first.style || '—',
            style: first.style || '—',
            orig_material: first.originally_requested_material || first.orig_material || '—',
            material_group: first.material_group || '—',
            material_by_customer: first.material_by_customer || '—',
            created_by: first.created_by || '—',
            currency: first.currency || 'INR',
            status,
            status_code: first.sd_process_status || '',
            delivery_status: first.delivery_status || first.total_delivery_status || '—',
            rejection_status: first.sd_document_rejection_status || first.rejection_status || '—',
            shipping_point: first.shipping_point || '—',
            shipping_type: first.shipping_type || '—',
            storage_location: first.storage_location || '—',
            route: first.route || '—',
            delivery_priority: first.delivery_priority || '—',
            sales_org: first.sales_org || '—',
            distribution_channel: first.channel || '—',
            division: first.organization_division || first.division || '—',
            sales_order_type: first.sales_order_type || '—',
            item_category: first.item_category || '—',
            item_type: first.item_type || '—',
            unit: first.unit || 'EA',
            unit_price: qty > 0 ? Math.round((value / qty) * 100) / 100 : Number(first.unit_price) || 0,
            tax_amount: Math.round(tax * 100) / 100,
            cost: Math.round(cost * 100) / 100,
            requested_qty: Math.round(reqQty * 1000) / 1000,
            confd_deliv_qty: Math.round(confQty * 1000) / 1000,
            incoterms: first.incoterms || '—',
            payment_terms: first.payment_terms || '—',
            billing_date: first.billing_date || '—',
            pricing_date: first.pricing_date || '—',
            last_change_date: first.last_change_date || '—',
            lines: records.length,
            quantity: Math.round(qty * 100) / 100,
            net_value: Math.round(value * 100) / 100,
            is_complete: st.includes('complet') || st.includes('closed') || st.includes('fully'),
        };
    }

    function orderFromFabricRecords(so, records) {
        let bom = 0;
        let soQty = 0;
        let material = '—';
        records.forEach((r, i) => {
            bom += Number(fabricField(r, ['bom_qty', 'BomQty', 'BOMQty', 'total_bom_qty'], 0)) || 0;
            soQty += Number(fabricField(r, ['so_qty', 'SoQty', 'SO_QTY'], 0)) || 0;
            if (i === 0) {
                material = String(fabricField(r, ['material', 'Material'], '—'));
            }
        });
        const qty = soQty > 0 ? soQty : bom;
        const first = records[0] || {};
        return {
            sales_order: String(so).replace(/^0+/, '') || so,
            creation_date: '—',
            customer: '—',
            sold_to_party: '—',
            customer_group: '—',
            sales_district: '—',
            plant: String(fabricField(first, ['plant', 'Plant'], '—')),
            material,
            style: String(fabricField(first, ['material_desc', 'MaterialDesc', 'description'], '—')),
            orig_material: material,
            material_group: String(fabricField(first, ['mat_group', 'mat_group_desc', 'material_group'], '—')),
            material_by_customer: '—',
            created_by: '—',
            currency: '—',
            status: 'Active',
            status_code: '',
            delivery_status: '—',
            rejection_status: '—',
            shipping_point: '—',
            shipping_type: '—',
            storage_location: '—',
            route: '—',
            delivery_priority: '—',
            sales_org: '—',
            distribution_channel: '—',
            division: '—',
            sales_order_type: '—',
            item_category: '—',
            item_type: '—',
            unit: String(fabricField(first, ['so_unit', 'bom_unit', 'unit'], 'EA')),
            unit_price: 0,
            tax_amount: 0,
            cost: 0,
            requested_qty: qty,
            confd_deliv_qty: 0,
            incoterms: '—',
            payment_terms: '—',
            billing_date: '—',
            pricing_date: '—',
            last_change_date: '—',
            lines: records.length,
            quantity: Math.round(qty * 1000) / 1000,
            net_value: 0,
            is_complete: false,
            source: 'fabric',
        };
    }

    async function fetchFabricRecords(so) {
        const params = new URLSearchParams({
            so: String(so),
            per_page: '10000',
            _: String(Date.now()),
        });
        const res = await fetch(`${fabricUrl}?${params}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const json = await res.json();
        if (!res.ok || json.success === false) {
            throw new Error(json.message || json.error || `No fabric data for SO ${so}`);
        }
        return Array.isArray(json.data?.records)
            ? json.data.records
            : Array.isArray(json.records)
              ? json.records
              : [];
    }

    async function loadBySalesOrder(so) {
        showSpinner(true);
        setAlert('');
        closeDrawer();
        selectedSo = '';
        clearDateFields();
        const needle = String(so || '').trim();
        try {
            // 1) Prefer ZI_SalesApi_HUB when available
            let order = null;
            try {
                const params = new URLSearchParams({ sales_order: needle, _: String(Date.now()) });
                const res = await fetch(`${soUrl}?${params}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                const json = await res.json();
                const hubRecords = Array.isArray(json.records) ? json.records : [];
                if (res.ok && hubRecords.length) {
                    order = orderFromHubRecords(json.sales_order || needle, hubRecords);
                }
            } catch (hubErr) {
                /* fall through to fabric */
            }

            // 2) Fallback: FABRIC_UTILIZATIONSet (same source as Fabric report for SO 4203)
            if (!order) {
                const fabricRecords = await fetchFabricRecords(needle);
                if (!fabricRecords.length) {
                    throw new Error(`No sale order / fabric lines found for SO ${needle}`);
                }
                order = orderFromFabricRecords(needle, fabricRecords);
            }

            paintResults([order]);
            syncUrl();
            setAlert(`Sale order ${order.sales_order} loaded. Click the row to open Fabric Utilization.`, false);
        } catch (err) {
            paintResults([]);
            setAlert(err.message || String(err), true);
            showDetails();
        } finally {
            showSpinner(false);
        }
    }

    async function loadByDateRange() {
        showSpinner(true);
        setAlert('');
        closeDrawer();
        selectedSo = '';
        try {
            const params = new URLSearchParams({
                from: currentFrom,
                to: currentTo,
                _: String(Date.now()),
            });
            const res = await fetch(`${ordersUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || 'Failed to load sales orders');
            }

            let orders = Array.isArray(json.orders) ? json.orders : [];
            if (currentSoFilter) {
                const needle = currentSoFilter.replace(/^0+/, '');
                orders = orders.filter((o) => String(o.sales_order || '').replace(/^0+/, '').includes(needle));
            }

            paintResults(orders);
            syncUrl();
            setAlert(
                orders.length
                    ? `Loaded ${orders.length.toLocaleString('en-IN')} sale order(s). Click a row for fabric utilization.`
                    : 'No sale orders found for this search.',
                orders.length === 0
            );
        } catch (err) {
            paintResults([]);
            setAlert(err.message || String(err), true);
            showDetails();
        } finally {
            showSpinner(false);
        }
    }

    async function loadOrders() {
        if (soOnlyMode && currentSoFilter) {
            await loadBySalesOrder(currentSoFilter);
            return;
        }
        if (!currentFrom || !currentTo) {
            setFormError('Choose a date range or enter a sales order.');
            showLookup();
            return;
        }
        if (currentTo < currentFrom) {
            setFormError('To date must be on or after From date.');
            showLookup();
            return;
        }
        await loadByDateRange();
    }

    function fabricField(row, keys, fallback = '') {
        for (const k of keys) {
            if (row[k] !== undefined && row[k] !== null && String(row[k]).trim() !== '') {
                return row[k];
            }
        }
        return fallback;
    }

    function destroyDrawerCharts() {
        if (drawerTotalsChart) {
            drawerTotalsChart.destroy();
            drawerTotalsChart = null;
        }
        if (drawerMixChart) {
            drawerMixChart.destroy();
            drawerMixChart = null;
        }
    }

    function openDrawer() {
        const drawer = $('fsoDrawer');
        if (!drawer) return;
        drawer.hidden = false;
        requestAnimationFrame(() => drawer.classList.add('is-open'));
        document.body.classList.add('fso-drawer-open');
        drawer.setAttribute('aria-hidden', 'false');
    }

    function closeDrawer() {
        const drawer = $('fsoDrawer');
        if (!drawer) return;
        drawer.classList.remove('is-open');
        document.body.classList.remove('fso-drawer-open');
        drawer.setAttribute('aria-hidden', 'true');
        setTimeout(() => {
            if (!drawer.classList.contains('is-open')) {
                drawer.hidden = true;
                destroyDrawerCharts();
            }
        }, 260);
    }

    function summarizeFabric(records) {
        const materials = new Set();
        let bom = 0;
        let planned = 0;
        let production = 0;
        let po = 0;
        let grn = 0;
        let issue = 0;
        const byMaterial = {};
        records.forEach((r) => {
            const mat = String(fabricField(r, ['material', 'Material'], '') || '');
            const bomQty = Number(fabricField(r, ['bom_qty', 'BomQty', 'BOMQty'], 0)) || 0;
            const planQty = Number(fabricField(r, ['planned_qty', 'Planned_Qty', 'PlannedQty'], 0)) || 0;
            const prodQty = Number(fabricField(r, ['production_qty', 'Production_Qty', 'ProductionQty'], 0)) || 0;
            const poQty = Number(fabricField(r, ['po_qty', 'PoQty', 'POQty'], 0)) || 0;
            const grnQty = Number(fabricField(r, ['grn_qty', 'GrnQty', 'GRNQty'], 0)) || 0;
            const issueQty = Number(fabricField(r, ['issue_qty', 'IssueQty', 'Issue_QTY'], 0)) || 0;
            po += poQty;
            grn += grnQty;
            if (mat && !materials.has(mat)) {
                materials.add(mat);
                bom += bomQty;
                planned += planQty;
                production += prodQty;
                issue += issueQty;
                byMaterial[mat] = bomQty;
            }
        });
        return {
            materials: materials.size,
            bom,
            planned,
            production,
            po,
            grn,
            issue,
            byMaterial,
        };
    }

    function buildFabricChartPayload(summary, chart) {
        if (chart && Array.isArray(chart.totals) && chart.totals.length) {
            return chart;
        }
        const entries = Object.entries(summary.byMaterial || {}).sort((a, b) => b[1] - a[1]);
        const mixLabels = [];
        const mixValues = [];
        let other = 0;
        entries.forEach(([label, value], i) => {
            if (i < 7) {
                mixLabels.push(label);
                mixValues.push(Math.round(value * 1000) / 1000);
            } else {
                other += value;
            }
        });
        if (other > 0) {
            mixLabels.push('Other');
            mixValues.push(Math.round(other * 1000) / 1000);
        }
        return {
            totals_labels: ['BOM', 'Planned', 'Production', 'PO', 'GRN', 'Issue'],
            totals: [
                Math.round(summary.bom * 1000) / 1000,
                Math.round(summary.planned * 1000) / 1000,
                Math.round(summary.production * 1000) / 1000,
                Math.round(summary.po * 1000) / 1000,
                Math.round(summary.grn * 1000) / 1000,
                Math.round(summary.issue * 1000) / 1000,
            ],
            mix_labels: mixLabels,
            mix_values: mixValues,
        };
    }

    function drawDrawerCharts(chart) {
        destroyDrawerCharts();
        if (typeof Chart === 'undefined') return;
        const totalsEl = $('fsoDrawerTotalsChart');
        const mixEl = $('fsoDrawerMixChart');
        if (totalsEl) {
            drawerTotalsChart = new Chart(totalsEl, {
                type: 'bar',
                data: {
                    labels: chart.totals_labels || [],
                    datasets: [{
                        label: 'Quantity',
                        data: chart.totals || [],
                        backgroundColor: ['#0f766e', '#0284c7', '#16a34a', '#d97706', '#7c3aed', '#e11d48'],
                        borderRadius: 8,
                        barPercentage: 0.62,
                        categoryPercentage: 0.78,
                        maxBarThickness: 56,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: false } },
                        y: { beginAtZero: true, grace: '4%' },
                    },
                },
            });
        }
        if (mixEl) {
            const labels = chart.mix_labels || [];
            const values = chart.mix_values || [];
            drawerMixChart = new Chart(mixEl, {
                type: 'doughnut',
                data: {
                    labels: labels.length ? labels : ['No data'],
                    datasets: [{
                        data: values.length ? values : [1],
                        backgroundColor: values.length ? mixColors.slice(0, values.length) : ['#e2e8e0'],
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            display: values.length > 0,
                            position: window.innerWidth < 768 ? 'bottom' : 'right',
                            labels: { boxWidth: 10, font: { size: 11 } },
                        },
                    },
                },
            });
        }
    }

    function fabricRowHtml(r, i) {
        const so = fabricField(r, ['sales_order', 'SalesOrder'], selectedSo);
        const material = fabricField(r, ['material', 'Material']);
        const desc = fabricField(r, ['description', 'Description', 'MaterialDescription']);
        const season = fabricField(r, ['season', 'Season']);
        const seasonYear = fabricField(r, ['season_year', 'SeasonYear']);
        const po = fabricField(r, ['purchase_order', 'PurchaseOrder']);
        const poLine = fabricField(r, ['po_item', 'PO_Item', 'po_line']);
        const soQty = fabricField(r, ['so_qty', 'SO_QTY'], 0);
        const bom = fabricField(r, ['bom_qty', 'BomQty', 'BOMQty'], 0);
        const totalBom = fabricField(r, ['total_bom_qty', 'TotalBOM_QTY'], 0);
        const planned = fabricField(r, ['planned_qty', 'Planned_Qty'], 0);
        const production = fabricField(r, ['production_qty', 'Production_Qty'], 0);
        const poQty = fabricField(r, ['po_qty', 'PoQty', 'POQty'], 0);
        const grn = fabricField(r, ['grn_qty', 'GrnQty', 'GRNQty'], 0);
        const issue = fabricField(r, ['issue_qty', 'IssueQty', 'Issue_QTY'], 0);
        const addSo = fabricField(r, ['grn_sales_orders', 'additional_sales_orders']);
        const a1 = fabricField(r, ['attribute1_text', 'Attribute1_text']);
        const a2 = fabricField(r, ['attribute2_text', 'Attribute2_text']);
        const a3 = fabricField(r, ['attribute3_text', 'Attribute3_text']);
        const colour = fabricField(r, ['colour', 'color', 'COLOR', 'Colour']);
        const hay = [
            so, material, desc, season, seasonYear, po, poLine, addSo, a1, a2, a3, colour,
        ].join(' ').toLowerCase();
        return `<tr data-fso-fabric-row data-hay="${esc(hay)}">
            <td class="num sno">${i + 1}</td>
            <td><span class="fso-so-pill is-static">${dash(so)}</span></td>
            <td title="${esc(material)}"><span class="fso-mat-pill">${dash(material)}</span></td>
            <td title="${esc(desc)}">${dash(desc)}</td>
            <td>${dash(season)}</td>
            <td>${dash(seasonYear)}</td>
            <td>${dash(po)}</td>
            <td class="num">${dash(poLine)}</td>
            <td class="num">${qtyFmt(soQty)}</td>
            <td class="num">${qtyFmt(bom)}</td>
            <td class="num">${qtyFmt(totalBom)}</td>
            <td class="num">${qtyFmt(planned)}</td>
            <td class="num">${qtyFmt(production)}</td>
            <td class="num">${qtyFmt(poQty)}</td>
            <td class="num">${qtyFmt(grn)}</td>
            <td class="num">${qtyFmt(issue)}</td>
            <td title="${esc(addSo)}">${dash(addSo)}</td>
            <td title="${esc(a1)}">${dash(a1)}</td>
            <td title="${esc(a2)}">${dash(a2)}</td>
            <td title="${esc(a3)}">${dash(a3)}</td>
            <td>${dash(colour)}</td>
        </tr>`;
    }

    function bindDrawerTableUi() {
        const search = $('fsoDrawerTableSearch');
        const clear = $('fsoDrawerTableSearchClear');
        const count = $('fsoDrawerTableCount');
        const rows = () => Array.from(document.querySelectorAll('#fsoDrawerTableBody tr[data-fso-fabric-row]'));
        const update = () => {
            const q = (search?.value || '').trim().toLowerCase();
            if (clear) clear.style.display = q ? '' : 'none';
            let shown = 0;
            rows().forEach((tr) => {
                const ok = !q || String(tr.getAttribute('data-hay') || '').includes(q);
                tr.style.display = ok ? '' : 'none';
                if (ok) shown += 1;
            });
            if (count) {
                count.textContent = `Showing ${shown.toLocaleString('en-IN')} of ${drawerRecords.length.toLocaleString('en-IN')} lines`;
            }
        };
        search?.addEventListener('input', update);
        clear?.addEventListener('click', () => {
            if (search) search.value = '';
            update();
        });
        update();
    }

    function renderFabricDrawer(payload, so, metaRow) {
        const content = $('fsoDrawerContent');
        const loading = $('fsoDrawerLoading');
        const err = $('fsoDrawerError');
        const openFull = $('fsoDrawerOpenFull');
        if (loading) loading.hidden = true;
        if (err) {
            err.hidden = true;
            err.textContent = '';
        }
        if ($('fsoDrawerTitle')) $('fsoDrawerTitle').textContent = `SO ${so}`;
        if (openFull && fabricReportUrl) {
            openFull.hidden = false;
            openFull.href = `${fabricReportUrl}?mode=unit&so=${encodeURIComponent(so)}`;
        }
        const records = Array.isArray(payload?.records) ? payload.records : Array.isArray(payload) ? payload : [];
        drawerRecords = records;
        const computed = summarizeFabric(records);
        const apiSummary = payload?.summary && typeof payload.summary === 'object' ? payload.summary : null;
        const summary = {
            materials: computed.materials,
            bom: Number(apiSummary?.bom_qty ?? computed.bom) || 0,
            planned: Number(apiSummary?.planned_qty ?? computed.planned) || 0,
            production: Number(apiSummary?.production_qty ?? computed.production) || 0,
            po: Number(apiSummary?.po_qty ?? computed.po) || 0,
            grn: Number(apiSummary?.grn_qty ?? computed.grn) || 0,
            issue: Number(apiSummary?.issue_qty ?? computed.issue) || 0,
            byMaterial: computed.byMaterial,
        };

        if ($('fsoDrawerMeta')) {
            const bits = [];
            if (metaRow?.customer && metaRow.customer !== '—') bits.push(metaRow.customer);
            if (metaRow?.creation_date && metaRow.creation_date !== '—') bits.push(metaRow.creation_date);
            bits.push(`${records.length.toLocaleString('en-IN')} line(s)`);
            bits.push(`${summary.materials.toLocaleString('en-IN')} material(s)`);
            $('fsoDrawerMeta').textContent = bits.join(' · ');
        }
        if (!content) return;
        content.hidden = false;
        if (!records.length) {
            destroyDrawerCharts();
            content.innerHTML = '<p class="fso-drawer-empty">No fabric utilization lines for this sales order.</p>';
            return;
        }

        const chart = buildFabricChartPayload(summary, payload?.chart);
        const rowsHtml = records.map((r, i) => fabricRowHtml(r, i)).join('');

        content.innerHTML = `
            <div class="rpt-stats fso-drawer-stats">
                <article class="rpt-stat tone-teal"><span class="material-icons-round">category</span><div><div class="stat-value-wrap"><b>${num(summary.materials, 0)}</b></div><small>Materials</small></div></article>
                <article class="rpt-stat tone-sky"><span class="material-icons-round">inventory_2</span><div><div class="stat-value-wrap"><b>${qtyFmt(summary.bom)}</b></div><small>BOM Qty</small></div></article>
                <article class="rpt-stat tone-indigo"><span class="material-icons-round">event_note</span><div><div class="stat-value-wrap"><b>${qtyFmt(summary.planned)}</b></div><small>Planned</small></div></article>
                <article class="rpt-stat tone-mint"><span class="material-icons-round">precision_manufacturing</span><div><div class="stat-value-wrap"><b>${qtyFmt(summary.production)}</b></div><small>Production</small></div></article>
                <article class="rpt-stat tone-amber"><span class="material-icons-round">shopping_cart</span><div><div class="stat-value-wrap"><b>${qtyFmt(summary.po)}</b></div><small>PO Qty</small></div></article>
                <article class="rpt-stat tone-violet"><span class="material-icons-round">local_shipping</span><div><div class="stat-value-wrap"><b>${qtyFmt(summary.grn)}</b></div><small>GRN Qty</small></div></article>
                <article class="rpt-stat tone-rose"><span class="material-icons-round">output</span><div><div class="stat-value-wrap"><b>${qtyFmt(summary.issue)}</b></div><small>Issue Qty</small></div></article>
            </div>

            <div class="rpt-charts fso-drawer-charts">
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">bar_chart</span>
                        <div>
                            <h2>Sale Order Quantity Utilization</h2>
                            <p>BOM, planned, production, PO, GRN, and issue</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body"><canvas id="fsoDrawerTotalsChart"></canvas></div>
                </section>
                <section class="rpt-chart-card">
                    <div class="rpt-chart-head">
                        <span class="material-icons-round">donut_large</span>
                        <div>
                            <h2>Sales Order BOM Quantity By Material</h2>
                            <p>Share of BOM quantity</p>
                        </div>
                    </div>
                    <div class="rpt-chart-body rpt-chart-donut"><canvas id="fsoDrawerMixChart"></canvas></div>
                </section>
            </div>

            <div class="rpt-table-toolbar fso-drawer-toolbar">
                <div class="rpt-tb-search">
                    <span class="material-icons-round rpt-search-ico">search</span>
                    <input type="search" id="fsoDrawerTableSearch" class="rpt-tb-input"
                           placeholder="Quick search Material, PO, Colour…" autocomplete="off" spellcheck="false">
                    <button type="button" id="fsoDrawerTableSearchClear" class="rpt-tb-clear" title="Clear search" style="display:none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="rpt-tb-actions">
                    <span class="rpt-tb-count" id="fsoDrawerTableCount">Showing ${records.length} of ${records.length} lines</span>
                </div>
            </div>

            <div class="fso-drawer-table-wrap">
                <table class="rpt-table fso-drawer-table text-nowrap" id="fsoDrawerTable">
                    <thead>
                        <tr>
                            <th class="num sno">S.No</th>
                            <th>Sales Order</th>
                            <th>Material</th>
                            <th>Description</th>
                            <th>Season</th>
                            <th>Season Year</th>
                            <th>Purchase Order</th>
                            <th class="num">PO Line</th>
                            <th class="num">SO_QTY</th>
                            <th class="num">BOM_QTY</th>
                            <th class="num">Total Add SO BOM Qty</th>
                            <th class="num">Planned</th>
                            <th class="num">Production</th>
                            <th class="num">PO_QTY</th>
                            <th class="num">GRN_QTY</th>
                            <th class="num">ISSUE_QTY</th>
                            <th>Additional Sale Order</th>
                            <th>Attribute1_text</th>
                            <th>Attribute2_text</th>
                            <th>Attribute3_text</th>
                            <th>Colour</th>
                        </tr>
                    </thead>
                    <tbody id="fsoDrawerTableBody">${rowsHtml}</tbody>
                </table>
            </div>`;

        requestAnimationFrame(() => {
            drawDrawerCharts(chart);
            bindDrawerTableUi();
        });
    }

    async function loadFabric(so) {
        selectedSo = String(so || '').replace(/^0+/, '') || String(so || '');
        renderTable();
        if (!selectedSo) return;

        const metaRow = allOrders.find((o) => String(o.sales_order) === selectedSo) || null;
        openDrawer();
        destroyDrawerCharts();
        const loading = $('fsoDrawerLoading');
        const err = $('fsoDrawerError');
        const content = $('fsoDrawerContent');
        const openFull = $('fsoDrawerOpenFull');
        if (loading) {
            loading.hidden = false;
            loading.textContent = `Loading fabric utilization for SO ${selectedSo}…`;
        }
        if (err) {
            err.hidden = true;
            err.textContent = '';
        }
        if (content) {
            content.hidden = true;
            content.innerHTML = '';
        }
        if (openFull) openFull.hidden = true;
        if ($('fsoDrawerTitle')) $('fsoDrawerTitle').textContent = `SO ${selectedSo}`;
        if ($('fsoDrawerMeta')) $('fsoDrawerMeta').textContent = metaRow?.customer || 'Calling FABRIC_UTILIZATIONSet…';

        try {
            const params = new URLSearchParams({
                so: selectedSo,
                per_page: '10000',
                _: String(Date.now()),
            });
            const res = await fetch(`${fabricUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || 'Failed to load fabric utilization');
            }
            const data = json.data && typeof json.data === 'object' ? json.data : json;
            const records = Array.isArray(data.records) ? data.records : Array.isArray(json.records) ? json.records : [];
            renderFabricDrawer({
                records,
                summary: data.summary || null,
                chart: data.chart || null,
            }, selectedSo, metaRow);
        } catch (e) {
            if (loading) loading.hidden = true;
            if (content) content.hidden = true;
            if (err) {
                err.hidden = false;
                err.textContent = e.message || String(e);
            }
        }
    }

    function applySearch(source, opts = {}) {
        const prefer = opts.prefer || '';
        const range = resolveRangeFromForm(source, prefer);
        const isLookup = source !== 'header';
        const allowCustomPending = !!opts.allowCustomPending;

        if (!range.so && !range.preset) {
            if (isLookup) setFormError('Choose a date range or enter a sales order (one only).');
            else setAlert('Choose a date range or enter a sales order (one only).', true);
            return;
        }
        if (range.preset === 'custom') {
            if (!range.from || !range.to) {
                if (!allowCustomPending) {
                    if (isLookup) setFormError('Choose both From and To dates for Custom Date.');
                    else setAlert('Choose both From and To dates for Custom Date.', true);
                }
                return;
            }
        }
        if (!range.soOnly && (!range.from || !range.to)) {
            if (isLookup) setFormError('Choose a date range or enter a sales order (one only).');
            else setAlert('Choose a date range or enter a sales order (one only).', true);
            return;
        }

        soOnlyMode = !!range.soOnly;
        if (soOnlyMode) {
            clearDateFields();
            currentSoFilter = range.so || '';
        } else {
            clearSoFields();
            currentPreset = range.preset;
            currentFrom = range.from;
            currentTo = range.to;
        }
        setFormError('');
        syncFormsFromState();
        void loadOrders();
    }

    document.addEventListener('DOMContentLoaded', () => {
        $('fsoHdrLookup')?.addEventListener('click', showLookup);
        $('fsoSearchForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            const so = ($('fsoSoInput')?.value || '').trim();
            applySearch('lookup', { prefer: so ? 'so' : 'date' });
        });
        $('fsoHeaderForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            const so = ($('fsoHdrSo')?.value || '').trim();
            applySearch('header', { prefer: so ? 'so' : 'date' });
        });

        // Date selected → clear SO, then search by date only
        $('fsoHdrPreset')?.addEventListener('change', () => {
            clearSoFields();
            syncPresetUi($('fsoHdrPreset'), $('fsoHdrCustomDates'), $('fsoHdrFrom'), $('fsoHdrTo'));
            const preset = ($('fsoHdrPreset')?.value || '').toLowerCase();
            if (!preset) return;
            if (preset === 'custom') return;
            applySearch('header', { prefer: 'date' });
        });
        $('fsoHdrFrom')?.addEventListener('change', () => {
            if (($('fsoHdrPreset')?.value || '') === 'custom') {
                clearSoFields();
                applySearch('header', { prefer: 'date' });
            }
        });
        $('fsoHdrTo')?.addEventListener('change', () => {
            if (($('fsoHdrPreset')?.value || '') === 'custom') {
                clearSoFields();
                applySearch('header', { prefer: 'date' });
            }
        });

        // Typing SO → clear date filter (search runs on submit / Enter)
        const onSoTyped = () => {
            if ((($('fsoHdrSo')?.value || $('fsoSoInput')?.value || '')).trim()) {
                clearDateFields();
            }
        };
        $('fsoHdrSo')?.addEventListener('input', onSoTyped);
        $('fsoSoInput')?.addEventListener('input', onSoTyped);

        $('fsoPreset')?.addEventListener('change', () => {
            setFormError('');
            clearSoFields();
            syncPresetUi($('fsoPreset'), $('fsoCustomDates'), $('fsoFrom'), $('fsoTo'));
        });

        $('fsoTableFilter')?.addEventListener('input', applyFilters);
        $('fsoTableFilterClear')?.addEventListener('click', () => {
            if ($('fsoTableFilter')) $('fsoTableFilter').value = '';
            applyFilters();
        });
        $('fsoClearAllFilters')?.addEventListener('click', clearAllFilters);
        $('fsoToggleColFilters')?.addEventListener('click', () => {
            const row = $('fsoFilterRow');
            const btn = $('fsoToggleColFilters');
            if (!row) return;
            const hidden = row.classList.toggle('is-hidden');
            row.style.display = hidden ? 'none' : '';
            btn?.classList.toggle('is-active', !hidden);
            if (!hidden) {
                row.querySelector('.rpt-col-input')?.focus();
            }
            syncHeaderHeight();
        });
        $('fsoToggleStickyScroll')?.addEventListener('click', () => {
            const wrap = $('fsoTableWrap');
            applyStickyMode(!wrap?.classList.contains('is-scroll-body'));
        });
        $('fsoColFilterReset')?.addEventListener('click', () => {
            colFilters = {};
            document.querySelectorAll('#fsoFilterRow .rpt-col-input').forEach((inp) => {
                inp.value = '';
                inp.classList.remove('has-val');
                const wrap = inp.closest('.rpt-col-input-wrap');
                const clr = wrap?.querySelector('.rpt-col-clear');
                if (clr) clr.style.display = 'none';
            });
            applyFilters();
        });
        document.querySelectorAll('#fsoFilterRow .rpt-col-input').forEach((inp) => {
            inp.addEventListener('input', () => {
                const key = inp.getAttribute('data-fso-col') || '';
                const raw = (inp.value || '').trim();
                inp.classList.toggle('has-val', raw !== '');
                const wrap = inp.closest('.rpt-col-input-wrap');
                const clr = wrap?.querySelector('.rpt-col-clear');
                if (clr) clr.style.display = raw !== '' ? 'inline-flex' : 'none';
                if (raw) colFilters[key] = raw;
                else delete colFilters[key];
                applyFilters();
            });
        });
        document.querySelectorAll('#fsoFilterRow .rpt-col-clear').forEach((btn) => {
            btn.addEventListener('click', () => {
                const wrap = btn.closest('.rpt-col-input-wrap');
                const inp = wrap?.querySelector('.rpt-col-input');
                if (!inp) return;
                inp.value = '';
                inp.classList.remove('has-val');
                btn.style.display = 'none';
                const key = inp.getAttribute('data-fso-col') || '';
                delete colFilters[key];
                applyFilters();
            });
        });
        window.addEventListener('resize', syncHeaderHeight, { passive: true });
        document.querySelectorAll('#fsoTable thead .is-sortable').forEach((th) => {
            th.addEventListener('click', () => {
                const key = th.getAttribute('data-fso-key') || '';
                if (!key || key === 'sno') return;
                if (sortKey === key) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                else {
                    sortKey = key;
                    sortDir = 'asc';
                }
                document.querySelectorAll('#fsoTable thead .is-sortable').forEach((el) => {
                    el.classList.remove('is-sorted-asc', 'is-sorted-desc');
                });
                th.classList.add(sortDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
                applyFilters();
            });
        });

        $('fsoTableBody')?.addEventListener('click', (e) => {
            const pill = e.target.closest('[data-so]');
            const tr = e.target.closest('tr[data-so]');
            const so = pill?.getAttribute('data-so') || tr?.getAttribute('data-so');
            if (!so) return;
            void loadFabric(so);
        });
        $('fsoDrawerClose')?.addEventListener('click', closeDrawer);
        $('fsoDrawerBackdrop')?.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && $('fsoDrawer')?.classList.contains('is-open')) closeDrawer();
        });

        const table = $('fsoTable');
        if (table && typeof window.rptSetupColumnFeatures === 'function') {
            columnFeatures = window.rptSetupColumnFeatures(table, {}) || columnFeatures;
        }
        applyStickyMode(true);
        syncCustomDates();

        const hasSo = !!(page.dataset.so || '').trim();
        const hasRange = !!(page.dataset.from && page.dataset.to) || !!(page.dataset.preset || '').trim();
        if (page.dataset.autoSearch === '1' && (hasSo || hasRange)) {
            if (hasSo) {
                soOnlyMode = true;
                currentSoFilter = (page.dataset.so || '').trim();
                clearDateFields();
            } else {
                soOnlyMode = false;
                clearSoFields();
                currentFrom = page.dataset.from || '';
                currentTo = page.dataset.to || '';
                currentPreset = page.dataset.preset || '';
            }
            syncFormsFromState();
            void loadOrders();
        } else {
            showLookup();
        }
    });
})();
