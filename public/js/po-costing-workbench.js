/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : PO Costing Validation Workbench — PO API + ZCOST compare UI
 */
(function () {
    let cfg = {};
    try {
        cfg = JSON.parse(document.getElementById('poConfig')?.textContent || '{}');
    } catch (e) {
        cfg = {};
    }

    const $ = (id) => document.getElementById(id);
    const spinner = $('rptSpinner');
    const money = (n) =>
        n === null || n === undefined || n === ''
            ? '—'
            : Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qty = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
    const esc = (v) =>
        String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));

    let rows = [];
    let selectedIdx = -1;

    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    function selectedMat() {
        const el = document.querySelector('input[name="pcw_mat"]:checked');
        return el ? el.value : 'trims';
    }

    function setAlert(text, isError) {
        const box = $('pcwAlert');
        if (!box) return;
        if (!text) {
            box.hidden = true;
            box.textContent = '';
            return;
        }
        box.hidden = false;
        box.textContent = text;
        box.classList.toggle('is-error', !!isError);
        box.classList.toggle('is-ok', !isError);
    }

    function fillKpis(summary) {
        const s = summary || {};
        if ($('pcwKpiItems')) $('pcwKpiItems').textContent = qty(s.po_items || 0);
        if ($('pcwKpiPassed')) $('pcwKpiPassed').textContent = qty(s.passed || 0);
        if ($('pcwKpiExceptions')) $('pcwKpiExceptions').textContent = qty(s.exceptions || 0);
        if ($('pcwKpiValue')) {
            const cur = s.currency === 'INR' ? '₹ ' : '';
            $('pcwKpiValue').textContent = cur + money(s.total_po_value || 0);
        }
        const passBtn = $('pcwPassPoBtn');
        if (passBtn) passBtn.disabled = !s.can_pass_po;
    }

    function statusPill(status) {
        const st = String(status || '').toUpperCase();
        const cls = st === 'PASS' ? 'is-pass' : 'is-fail';
        return `<span class="pcw-pill ${cls}">${esc(st || '—')}</span>`;
    }

    function varianceCell(pct) {
        if (pct === null || pct === undefined || pct === '') return '—';
        const n = Number(pct);
        const cls = n > 0 ? 'is-bad' : 'is-good';
        const sign = n > 0 ? '+' : '';
        return `<span class="pcw-var ${cls}">${sign}${n.toFixed(1)}%</span>`;
    }

    function renderTable() {
        const tbody = $('pcwTableBody');
        if (!tbody) return;
        if (!rows.length) {
            tbody.innerHTML = '<tr class="pcw-empty"><td colspan="12">No PO items found for this filter.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map((r, i) => {
                const active = i === selectedIdx ? ' is-active' : '';
                return `<tr class="pcw-row${active}" data-idx="${i}">
                    <td class="pcw-check"><input type="checkbox" class="pcw-row-check" data-idx="${i}" ${r._checked ? 'checked' : ''}></td>
                    <td>${statusPill(r.status)}</td>
                    <td>${dash(r.po_item)}</td>
                    <td class="pcw-mat">${dash(r.material)}</td>
                    <td title="${esc(r.description)}">${dash(r.description)}</td>
                    <td>${dash(r.sales_order)}</td>
                    <td class="num">${qty(r.po_qty)}</td>
                    <td title="${esc(r.costing_ref)}">${dash(r.costing_ref)}</td>
                    <td class="num">${money(r.expected_unit_cost)}</td>
                    <td class="num">${money(r.po_unit_cost)}</td>
                    <td class="num">${varianceCell(r.variance_pct)}</td>
                    <td>${dash(r.validation_result)}</td>
                </tr>`;
            })
            .join('');
    }

    function dlRow(label, value, extraClass) {
        return `<div><dt>${esc(label)}</dt><dd class="${extraClass || ''}">${value}</dd></div>`;
    }

    function showDetail(idx) {
        selectedIdx = idx;
        const r = rows[idx];
        const detail = $('pcwDetail');
        if (!r || !detail) {
            if (detail) detail.hidden = true;
            return;
        }
        detail.hidden = false;
        if ($('pcwDetailSub')) {
            $('pcwDetailSub').textContent = `Item ${r.po_item || '—'} · ${r.material || r.description || ''}`;
        }

        const quote = $('pcwPanelQuote');
        const po = $('pcwPanelPo');
        const diff = $('pcwPanelDiff');
        const info = $('pcwPanelInfo');

        if (quote) {
            quote.innerHTML =
                dlRow('Quotation / Costing Ref', dash(r.costing_ref)) +
                dlRow('Quoted Unit Cost', '₹ ' + money(r.expected_unit_cost)) +
                dlRow('Currency', dash(r.quoted_currency || 'INR')) +
                dlRow('UOM', dash(r.quoted_uom || r.unit)) +
                dlRow('Section', dash(r.cost_section));
        }
        if (po) {
            po.innerHTML =
                dlRow('PO Number', dash(r.purchase_order)) +
                dlRow('PO Item', dash(r.po_item)) +
                dlRow('PO Unit Cost', '₹ ' + money(r.po_unit_cost)) +
                dlRow('Currency', dash(r.currency || 'INR')) +
                dlRow('UOM', dash(r.unit)) +
                dlRow('PO Qty', qty(r.po_qty));
        }
        if (diff) {
            const d = r.difference;
            const dCls = d === null || d === undefined ? '' : Number(d) > 0 ? 'is-bad' : 'is-good';
            const dText =
                d === null || d === undefined
                    ? '—'
                    : `${Number(d) > 0 ? '+' : ''}₹ ${money(Math.abs(d))} (${r.variance_pct > 0 ? '+' : ''}${r.variance_pct ?? '—'}%)`;
            diff.innerHTML =
                dlRow('Expected Unit Cost', '₹ ' + money(r.expected_unit_cost)) +
                dlRow('PO Unit Cost', '₹ ' + money(r.po_unit_cost)) +
                dlRow('Difference', dText, dCls) +
                dlRow('Validation Result', statusPill(r.status) + ' ' + esc(r.validation_result || ''));
        }
        if (info) {
            info.innerHTML =
                dlRow('Sales Order', dash(r.sales_order)) +
                dlRow('Material Description', dash(r.description)) +
                dlRow('Cost Item', dash(r.cost_item)) +
                dlRow('ZCOST Description', dash(r.cost_description)) +
                dlRow('Last Updated', new Date().toLocaleString('en-IN')) +
                dlRow('Source', 'PO API + ZCOST_SRV');
        }
        renderTable();
    }

    async function runValidate() {
        const po = String($('pcwPoInput')?.value || '').replace(/\D/g, '');
        if (!/^\d{1,10}$/.test(po)) {
            setAlert('Enter a valid PO number (up to 10 digits).', true);
            return;
        }
        const so = String($('pcwSoInput')?.value || '').replace(/\D/g, '');
        const mat = selectedMat();
        const url = new URL(cfg.validateUrl || 'purchase-order/validate', window.location.origin);
        // validateUrl may already be absolute path
        const endpoint = cfg.validateUrl || 'purchase-order/validate';
        const params = new URLSearchParams({ po, mat_cat: mat, _: String(Date.now()) });
        if (so) params.set('so', so);

        showSpinner(true);
        setAlert('');
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
            selectedIdx = rows.length ? 0 : -1;
            fillKpis(data.summary || {});
            renderTable();
            if (selectedIdx >= 0) showDetail(selectedIdx);

            const ex = Number(data.summary?.exceptions || 0);
            if (ex > 0) setAlert(`${ex} exception${ex === 1 ? '' : 's'} require review`, true);
            else if (rows.length) setAlert('All lines passed costing validation', false);
            else setAlert(json.message || 'No items to validate', true);

            // Keep URL in sync
            const page = cfg.pageUrl || 'purchase-order';
            const next = new URL(page, window.location.origin);
            next.searchParams.set('view', 'costing');
            next.searchParams.set('po', po);
            if (so) next.searchParams.set('so', so);
            if (mat !== 'all') next.searchParams.set('mat_cat', mat);
            window.history.replaceState({}, '', next.pathname + next.search);
            document.title = `EVOLV | PO Costing ${po}`;
        } catch (err) {
            rows = [];
            selectedIdx = -1;
            fillKpis({});
            renderTable();
            $('pcwDetail') && ($('pcwDetail').hidden = true);
            setAlert(err.message || 'Validation failed', true);
        } finally {
            showSpinner(false);
        }
    }

    function exportCsv() {
        if (!rows.length) return;
        const headers = [
            'Status',
            'PO Item',
            'Material',
            'Description',
            'Sales Order',
            'PO Qty',
            'Costing Ref',
            'Expected Unit Cost',
            'PO Unit Cost',
            'Variance %',
            'Validation Result',
        ];
        const lines = [headers.join(',')];
        rows.forEach((r) => {
            const vals = [
                r.status,
                r.po_item,
                r.material,
                r.description,
                r.sales_order,
                r.po_qty,
                r.costing_ref,
                r.expected_unit_cost,
                r.po_unit_cost,
                r.variance_pct,
                r.validation_result,
            ].map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`);
            lines.push(vals.join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `po-costing-validation-${cfg.po || 'export'}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    }

    function bind() {
        $('pcwFetchForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            void runValidate();
        });
        $('pcwValidateBtn')?.addEventListener('click', () => void runValidate());
        $('pcwRevalidateBtn')?.addEventListener('click', () => void runValidate());
        $('pcwExportBtn')?.addEventListener('click', exportCsv);
        $('pcwPassPoBtn')?.addEventListener('click', () => {
            if ($('pcwPassPoBtn')?.disabled) return;
            setAlert('All lines passed. Use PO E-Signature / Costing Pass PO flow to complete sign-off.', false);
        });

        $('pcwSelectAll')?.addEventListener('change', (e) => {
            const on = !!e.target.checked;
            rows.forEach((r) => {
                r._checked = on;
            });
            renderTable();
        });

        $('pcwTableBody')?.addEventListener('click', (e) => {
            const check = e.target.closest('.pcw-row-check');
            if (check) {
                const i = Number(check.getAttribute('data-idx'));
                if (!Number.isNaN(i) && rows[i]) {
                    rows[i]._checked = check.checked;
                }
                return;
            }
            const tr = e.target.closest('tr.pcw-row');
            if (!tr) return;
            const i = Number(tr.getAttribute('data-idx'));
            if (!Number.isNaN(i)) showDetail(i);
        });
    }

    bind();
    if (cfg.po) {
        if ($('pcwPoInput')) $('pcwPoInput').value = cfg.po;
        if ($('pcwSoInput') && cfg.salesOrder) $('pcwSoInput').value = cfg.salesOrder;
        void runValidate();
    } else {
        showSpinner(false);
    }
})();
