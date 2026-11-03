/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 30/10/2026
 * DESCRIPTION : PO Costing Workbench — TEMPFIN columns, formula popovers, search, filters, column prefs
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
        'sno', 'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
        'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost', 'variance_pct',
    ]);
    const HIGHLIGHT_COLS = new Set(['budget_cost_per_gmt', 'actual_cost']);
    const ZCOST_COLS = new Set([
        'cost_item', 'maktx',
        'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'budget_cost_per_gmt',
        'conv', 'buom', 'actual_cost', 'variance_pct', 'validation_result',
    ]);
    const EXPLAIN_COLS = new Set([
        'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'ouom', 'porate', 'totvalue',
        'po_currency', 'budgeted_cons', 'budget_inr', 'budget_currency',
        'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'buom', 'actual_cost',
        'variance_pct',
    ]);
    const colGroup = (key) => (ZCOST_COLS.has(key) ? 'zcost' : 'po');

    let rows = [];
    let costLines = [];
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
    let formulaAnchor = null;
    let matchRowIndex = null;
    let matchPickKey = '';

    function nVal(v) {
        const n = Number(v);
        return Number.isFinite(n) ? n : null;
    }

    function fmtStep(v, dig = 2) {
        if (v === null || v === undefined || v === '') return '—';
        const n = Number(v);
        if (!Number.isFinite(n)) return String(v);
        return n.toLocaleString('en-IN', { minimumFractionDigits: dig, maximumFractionDigits: dig });
    }

    function totalReqForItem(r) {
        const pono = String(r.pono || r.purchase_order || '');
        const item = String(r.poitem || r.po_item || '');
        let total = 0;
        rows.forEach((row) => {
            if (String(row.pono || row.purchase_order || '') !== pono) return;
            if (String(row.poitem || row.po_item || '') !== item) return;
            const q = nVal(row.reqqty);
            if (q !== null) total += q;
        });
        return total;
    }

    function explainFor(key, r) {
        const soQty = nVal(r.so_qty) ?? nVal(r.prwise_so_qty);
        const prSo = nVal(r.prwise_so_qty) ?? soQty;
        const cons = nVal(r.consumption);
        const req = nVal(r.reqqty);
        const totPo = nVal(r.tot_poqty);
        const soPoQty = nVal(r.so_wise_poqty);
        const soPoVal = nVal(r.so_wise_po_val);
        const porate = nVal(r.porate);
        const conv = nVal(r.conv) ?? 1;
        const budget = nVal(r.budget_cost_per_gmt);
        const actual = nVal(r.actual_cost);
        const totValue = nVal(r.totvalue ?? r.net_value);
        const budgetCons = nVal(r.budgeted_cons);
        const budgetInr = nVal(r.budget_inr);
        const budgetConv = nVal(r.budget_conv_rt);
        const poCur = String(r.po_currency || r.currency || '—');
        const budCur = String(r.budget_currency || '—');
        const buom = String(r.buom || '—');
        const totalReq = totalReqForItem(r);
        const withinBudget =
            budget !== null && actual !== null && actual <= budget
                ? '\n*Within budget (green).'
                : budget !== null && actual !== null
                  ? '\n*Above budget — review.'
                  : '';

        const defs = {
            so_qty: {
                title: 'SO_QTY',
                desc: 'Total garment quantity on the Sales Order.',
                example: 'This is the order qty for the style — not the trim consumption qty.',
                steps: [{ label: 'SO qty for this style', value: fmtStep(soQty) }],
                resultLabel: 'SO_QTY',
                resultExpr: `= ${fmtStep(soQty)}`,
            },
            prwise_so_qty: {
                title: 'PRWISE_SO_QTY',
                desc: 'Garment quantity from the Sales Order.',
                example: 'Used with REQ QTY to work out consumption per garment.',
                steps: [{ label: 'SO qty for this material', value: fmtStep(prSo) }],
                resultLabel: 'PRWISE_SO_QTY',
                resultExpr: `= ${fmtStep(prSo)}`,
            },
            consumption: {
                title: 'CONSUMPTION',
                desc: 'Required qty ÷ SO qty (per garment)',
                formula: 'req_qty / so_qty',
                example: 'How much material is needed for each garment on the SO.',
                steps: [
                    { label: 'REQ QTY', value: fmtStep(req) },
                    { label: 'PRWISE SO QTY', value: fmtStep(prSo) },
                ],
                resultLabel: 'CONSUMPTION',
                resultExpr:
                    prSo && prSo !== 0
                        ? `${fmtStep(req)} ÷ ${fmtStep(prSo)}\n= ${fmtStep(cons ?? req / prSo, 4)}`
                        : `= ${fmtStep(cons, 4)}`,
            },
            reqqty: {
                title: 'REQQTY',
                desc: 'Material requirement from the Sales Order',
                formula: 'so_qty * consumption',
                example: 'Total material required for this SO line before PO split.',
                steps: [
                    { label: 'PRWISE SO QTY', value: fmtStep(prSo) },
                    { label: 'Consumption', value: fmtStep(cons, 4) },
                ],
                resultLabel: 'REQQTY',
                resultExpr:
                    prSo !== null && cons !== null
                        ? `${fmtStep(prSo)} × ${fmtStep(cons, 4)}\n= ${fmtStep(req ?? prSo * cons)}`
                        : `= ${fmtStep(req)}`,
            },
            tot_poqty: {
                title: 'TOT_POQTY',
                desc: 'Quantity ordered on the PO line',
                example: 'This is the full order quantity on the Purchase Order item.',
                steps: [{ label: 'PO line qty', value: fmtStep(totPo) }],
                resultLabel: 'TOT_POQTY',
                resultExpr: `= ${fmtStep(totPo)}`,
            },
            so_wise_poqty: {
                title: 'SO_WISE_POQTY',
                desc: 'Base qty × REQ QTY ÷ Total req (per PO item)',
                formula: 'base_qty * req_qty / total_req',
                example: `PO qty for this SO is ${fmtStep(soPoQty)}. Full material need is ${fmtStep(req)} (SO qty × Consumption).`,
                steps: [
                    { label: 'Base qty (PO)', value: fmtStep(totPo) },
                    { label: 'REQ QTY', value: fmtStep(req) },
                    { label: 'Total req (PO item)', value: fmtStep(totalReq) },
                ],
                resultLabel: 'SO_WISE_POQTY',
                resultExpr:
                    totPo !== null && req !== null && totalReq
                        ? `${fmtStep(totPo)} × ${fmtStep(req)} ÷ ${fmtStep(totalReq)}\n= ${fmtStep(soPoQty ?? (totPo * req) / totalReq)}`
                        : `= ${fmtStep(soPoQty)}`,
            },
            so_wise_po_val: {
                title: 'SO_WISE_PO_VAL',
                desc: 'PO Rate × SO Wise PO Qty × Conversion',
                formula: 'po_rate * so_wise_po_qty * conv',
                example: 'Value of this PO line portion attributed to the Sales Order.',
                steps: [
                    { label: 'PO Rate', value: fmtStep(porate, 4) },
                    { label: 'SO Wise PO Qty', value: fmtStep(soPoQty) },
                    { label: 'Conversion', value: fmtStep(conv, 4) },
                ],
                resultLabel: 'SO_WISE_PO_VAL',
                resultExpr:
                    porate !== null && soPoQty !== null
                        ? `${fmtStep(porate, 4)} × ${fmtStep(soPoQty)} × ${fmtStep(conv, 4)}\n= ${fmtStep(soPoVal ?? porate * soPoQty * conv)}`
                        : `= ${fmtStep(soPoVal)}`,
            },
            ouom: {
                title: 'OUOM',
                desc: 'Unit of measure on the PO (e.g. M, KG, GRO).',
                example: 'Order unit used with PO qty and PO rate.',
                steps: [{ label: 'Order UOM', value: String(r.ouom || r.unit || '—') }],
                resultLabel: 'OUOM',
                resultExpr: `= ${String(r.ouom || r.unit || '—')}`,
            },
            porate: {
                title: 'PORATE',
                desc: 'PO net price ÷ price unit',
                formula: 'net_price / price_unit',
                example: 'Unit rate used in value and cost calculations.',
                steps: [{ label: 'Rate per piece', value: fmtStep(porate, 4) }],
                resultLabel: 'PORATE',
                resultExpr: `= ${fmtStep(porate, 4)}`,
            },
            totvalue: {
                title: 'TOTVALUE',
                desc: 'Net price on the PO line',
                example: 'This is the net price from the PO — not qty × rate, and not SO Wise PO Value.',
                steps: [{ label: 'PO net price', value: fmtStep(totValue) }],
                resultLabel: 'TOTVALUE',
                resultExpr: `= ${fmtStep(totValue)}`,
            },
            po_currency: {
                title: 'PO_CURRENCY',
                desc: 'Currency on the Purchase Order.',
                steps: [{ label: 'PO currency', value: poCur }],
                resultLabel: 'PO_CURRENCY',
                resultExpr: `= ${poCur}`,
            },
            budgeted_cons: {
                title: 'BUDGETED_CONS',
                desc: 'Average consumption from budget sheet',
                example: 'Compare with calculated Consumption — large gap may flag a validation issue.',
                steps: [{ label: 'Budget consumption', value: fmtStep(budgetCons, 4) }],
                resultLabel: 'BUDGETED_CONS',
                resultExpr: `= ${fmtStep(budgetCons, 4)}`,
            },
            budget_inr: {
                title: 'BUDGET_INR',
                desc: 'Budget amount in INR',
                example: 'Total budget value for this material on the costing sheet.',
                steps: [{ label: 'Budget INR', value: fmtStep(budgetInr, 2) }],
                resultLabel: 'BUDGET_INR',
                resultExpr: `= ${fmtStep(budgetInr, 2)}`,
            },
            budget_currency: {
                title: 'BUDGET_CURRENCY',
                desc: 'Currency on the budget / costing sheet.',
                steps: [{ label: 'Budget currency', value: budCur }],
                resultLabel: 'BUDGET_CURRENCY',
                resultExpr: `= ${budCur}`,
            },
            budget_conv_rt: {
                title: 'BUDGET_CONV_RT',
                desc: '1 when PO is in INR',
                example: 'Used when converting foreign PO rates to INR for cost comparison.',
                steps: [{ label: 'Conversion rate', value: fmtStep(budgetConv, 4) }],
                resultLabel: 'BUDGET_CONV_RT',
                resultExpr: `= ${fmtStep(budgetConv, 4)}`,
            },
            budget_cost_per_gmt: {
                title: 'BUDGET_COST_PER_GMT',
                desc: 'Budget cost per garment (INR)',
                example: 'Target cost per piece — Actual Cost should stay below this (green).',
                steps: [{ label: 'Budget per garment', value: fmtStep(budget, 4) }],
                resultLabel: 'BUDGET_COST_PER_GMT',
                resultExpr: `= ${fmtStep(budget, 4)}`,
            },
            conv: {
                title: 'CONV',
                desc: 'PO qty ÷ Base qty',
                formula: 'po_qty / base_qty',
                example: 'Usually 1 when order qty equals base qty.',
                steps: [{ label: 'Conversion factor', value: fmtStep(conv, 4) }],
                resultLabel: 'CONV',
                resultExpr: `= ${fmtStep(conv, 4)}`,
            },
            buom: {
                title: 'BUOM',
                desc: 'Base unit of measure on the material.',
                example: 'Used with CONV when order unit differs from base unit.',
                steps: [{ label: 'Base UOM', value: buom }],
                resultLabel: 'BUOM',
                resultExpr: `= ${buom}`,
            },
            actual_cost: {
                title: 'ACTUAL_COST',
                desc: 'PO Rate × Conversion × Consumption',
                formula: 'po_rate * conv * consumption',
                example: `Cost per garment from PO rate and consumption.${withinBudget}`,
                steps: [
                    { label: 'PO Rate', value: fmtStep(porate, 4) },
                    { label: 'Conversion', value: fmtStep(conv, 4) },
                    { label: 'Consumption', value: fmtStep(cons, 4) },
                ],
                resultLabel: 'ACTUAL_COST',
                resultExpr:
                    porate !== null
                        ? `${fmtStep(porate, 4)} × ${fmtStep(conv, 4)} × ${fmtStep(cons, 4)}\n= ${fmtStep(actual ?? porate * conv * (cons ?? 1), 4)}`
                        : `= ${fmtStep(actual, 4)}`,
            },
            cost_matrix: {
                title: 'zCOST Matrix',
                desc: 'Budget cost per GMT vs Actual cost side by side',
                example: 'Green when actual stays within budget; review when actual is higher.',
                steps: [
                    { label: 'Budget / GMT', value: fmtStep(budget, 4) },
                    { label: 'Actual cost', value: fmtStep(actual, 4) },
                ],
                resultLabel: 'zCOST Matrix',
                resultExpr:
                    budget !== null && actual !== null
                        ? `Budget ${fmtStep(budget, 4)}  ·  Actual ${fmtStep(actual, 4)}`
                        : '—',
            },
            variance_pct: {
                title: 'Variance %',
                desc: '(Actual − Budget) ÷ Budget × 100',
                formula: '(actual - budget) / budget * 100',
                example: 'Negative or zero = within / under budget. Positive = over budget.',
                steps: [
                    { label: 'Budget / GMT', value: fmtStep(budget, 4) },
                    { label: 'Actual cost', value: fmtStep(actual, 4) },
                ],
                resultLabel: 'Variance %',
                resultExpr:
                    budget !== null && actual !== null && budget !== 0
                        ? `(${fmtStep(actual, 4)} − ${fmtStep(budget, 4)}) ÷ ${fmtStep(budget, 4)} × 100\n= ${fmtStep(((actual - budget) / budget) * 100, 2)}%`
                        : `= ${fmtStep(r.variance_pct, 2)}%`,
            },
        };
        return defs[key] || null;
    }

    function costMatrixHtml(r) {
        const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const actual = nVal(r.actual_cost ?? r.po_unit_cost);
        if (budget === null && actual === null) return '—';
        const max = Math.max(budget || 0, actual || 0, 0.0001);
        const bPct = Math.max(4, Math.round(((budget || 0) / max) * 100));
        const aPct = Math.max(4, Math.round(((actual || 0) / max) * 100));
        const ok = budget !== null && actual !== null && actual <= budget;
        const tone = ok ? 'is-ok' : 'is-over';
        return `<div class="pcw-cost-matrix ${tone}">
            <div class="pcw-cost-matrix-row"><span>B</span><i style="width:${bPct}%"></i><strong>${esc(fmtStep(budget, 4))}</strong></div>
            <div class="pcw-cost-matrix-row"><span>A</span><i style="width:${aPct}%"></i><strong>${esc(fmtStep(actual, 4))}</strong></div>
        </div>`;
    }

    function closeFormulaPop() {
        const pop = $('pcwFormulaPop');
        if (pop) pop.hidden = true;
        document.querySelectorAll('.pcw-formula-cell.is-active').forEach((el) => el.classList.remove('is-active'));
        formulaAnchor = null;
    }

    function positionFormulaPop(anchor) {
        const pop = $('pcwFormulaPop');
        if (!pop || !anchor) return;
        pop.hidden = false;
        const rect = anchor.getBoundingClientRect();
        const pad = 10;
        const pw = pop.offsetWidth || 320;
        const ph = pop.offsetHeight || 240;
        let left = rect.left;
        let top = rect.bottom + 8;
        if (left + pw > window.innerWidth - pad) {
            left = Math.max(pad, window.innerWidth - pw - pad);
        }
        if (top + ph > window.innerHeight - pad) {
            top = Math.max(pad, rect.top - ph - 8);
        }
        if (left < pad) left = pad;
        pop.style.left = `${left}px`;
        pop.style.top = `${top}px`;
    }

    function openFormulaPop(key, r, anchor) {
        const info = explainFor(key, r);
        const pop = $('pcwFormulaPop');
        if (!info || !pop) return;
        closeFormulaPop();
        formulaAnchor = anchor;
        if (anchor) anchor.classList.add('is-active');

        $('pcwFormulaTitle').textContent = info.title;
        $('pcwFormulaDesc').textContent = info.desc || '';

        const exprBox = $('pcwFormulaExpr');
        if (info.formula) {
            exprBox.hidden = false;
            $('pcwFormulaExprText').textContent = info.formula;
        } else {
            exprBox.hidden = true;
        }

        const stepsBox = $('pcwFormulaSteps');
        const list = $('pcwFormulaStepsList');
        const result = $('pcwFormulaResult');
        if (info.steps && info.steps.length) {
            stepsBox.hidden = false;
            list.innerHTML = info.steps
                .map(
                    (s) =>
                        `<li><span class="pcw-formula-dot"></span><span class="pcw-formula-step-label">${esc(s.label)}</span><strong>${esc(s.value)}</strong></li>`
                )
                .join('');
            result.innerHTML = `<span class="pcw-formula-dot is-solid"></span><span class="pcw-formula-step-label">${esc(info.resultLabel || info.title)}</span><div class="pcw-formula-result-expr">${esc(info.resultExpr || '')}</div>`;
        } else {
            stepsBox.hidden = true;
            list.innerHTML = '';
            result.innerHTML = '';
        }

        const ex = $('pcwFormulaExample');
        if (info.example) {
            ex.hidden = false;
            $('pcwFormulaExampleText').textContent = info.example;
        } else {
            ex.hidden = true;
        }

        positionFormulaPop(anchor);
    }

    function closeCardDetailModal() {
        const modal = $('pcwCardDetailModal');
        const backdrop = $('pcwCardDetailBackdrop');
        if (modal) modal.hidden = true;
        if (backdrop) backdrop.hidden = true;
    }

    function filterTableByStatus(status) {
        const statusTh = $('rptFilterRow')?.querySelector('th[data-col-id="status"] input');
        if (statusTh) {
            statusTh.value = status;
            statusTh.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
            const searchInput = $('rptTableSearch');
            if (searchInput) {
                searchInput.value = status;
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    }

    function openCardDetailModal(cardKey) {
        const s = summarizeRows(rows);
        const modal = $('pcwCardDetailModal');
        const backdrop = $('pcwCardDetailBackdrop');
        if (!modal || !backdrop) return;

        let icon = 'analytics';
        let subtitle = 'PO DATA Card Details';
        let title = '';
        let val = '—';
        let unit = '';
        let formula = '';
        let stepsHtml = '';
        let tableTitle = 'Contributing Items';
        let rowsHtml = '';
        let showFilterBtn = false;
        let filterStatus = '';

        const curr = s.currency || 'INR';
        const uom = s.unit || '';
        const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
        const fmtDec = (n, d = 2) => Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d });

        if (cardKey === 'status_total') {
            icon = 'rule';
            subtitle = 'Validation Overview';
            title = 'Total Validation Lines';
            val = fmt(s.total_records || rows.length);
            formula = 'Total Lines = Count of all item rows evaluated in Purchase Order';
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Passed Lines</span><strong>${fmt(s.passed)}</strong></div>
                <div class="pcw-cdm-step-item"><span>Exception Lines</span><strong>${fmt(s.exceptions)}</strong></div>
                <div class="pcw-cdm-step-item"><span>Needs Review Lines</span><strong>${fmt(s.needs_review)}</strong></div>
                <div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Evaluated</span><strong>${fmt(s.total_records || rows.length)}</strong></div>
            `;
            rowsHtml = rows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || 1)))}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'status_pass') {
            icon = 'check_circle';
            subtitle = 'Validation Overview';
            title = 'Passed Lines';
            val = fmt(s.passed);
            formula = 'Passed Lines = PO lines where Actual PO Rate ≤ Budget Cost per GMT';
            showFilterBtn = true;
            filterStatus = 'PASS';
            const passRows = rows.filter(r => String(r.status).toUpperCase() === 'PASS');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>Actual Cost ≤ Budget Cost per GMT</strong></div>
                <div class="pcw-cdm-step-item"><span>Total Passed Lines</span><strong>${fmt(s.passed)} of ${fmt(rows.length)}</strong></div>
            `;
            rowsHtml = passRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue)}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'status_fail') {
            icon = 'error';
            subtitle = 'Validation Overview';
            title = 'Exception Lines';
            val = fmt(s.exceptions);
            formula = 'Exceptions = PO lines exceeding budget cost or missing valid costing link';
            showFilterBtn = true;
            filterStatus = 'EXCEPTION';
            const failRows = rows.filter(r => String(r.status).toUpperCase() !== 'PASS' && String(r.status).toUpperCase() !== 'NEEDS_REVIEW');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>PO Rate > Budget or Unmatched in zCOST</strong></div>
                <div class="pcw-cdm-step-item"><span>Total Exception Lines</span><strong>${fmt(s.exceptions)} of ${fmt(rows.length)}</strong></div>
            `;
            rowsHtml = failRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue)}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'status_review') {
            icon = 'rate_review';
            subtitle = 'Validation Overview';
            title = 'Needs Review Lines';
            val = fmt(s.needs_review);
            formula = 'Needs Review = PO lines with zero budget rate or uncertain match requiring review';
            showFilterBtn = true;
            filterStatus = 'NEEDS_REVIEW';
            const revRows = rows.filter(r => String(r.status).toUpperCase() === 'NEEDS_REVIEW');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>Rate is zero or uncertain mapping</strong></div>
                <div class="pcw-cdm-step-item"><span>Total Needs Review</span><strong>${fmt(s.needs_review)} of ${fmt(rows.length)}</strong></div>
            `;
            rowsHtml = revRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue)}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'po_items') {
            icon = 'format_list_numbered';
            subtitle = 'PO DATA';
            title = 'Total Items';
            val = fmt(s.po_items);
            formula = 'Total Items = Count of unique PO Item numbers (distinct poitem)';
            const seenIt = {};
            const distItems = rows.filter(r => {
                const it = String(r.poitem || r.po_item || '').trim();
                if (it && !seenIt[it]) { seenIt[it] = true; return true; }
                return false;
            });
            stepsHtml = distItems.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item)} (${esc(r.material)})</span><strong>Qty: ${qty(r.tot_poqty || 0)} · Value: ₹${money(r.totvalue)}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Unique Items</span><strong>${fmt(s.po_items)}</strong></div>`;
            rowsHtml = distItems.map(r => `<tr>
                <td>#${esc(r.poitem || r.po_item)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue)}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'po_materials') {
            icon = 'category';
            subtitle = 'PO DATA';
            title = 'Total Material';
            val = fmt(s.materials);
            formula = 'Total Material = Count of distinct Material Codes across PO lines';
            const seenMat = {};
            const distMats = rows.filter(r => {
                const m = String(r.material || '').trim();
                if (m && !seenMat[m]) { seenMat[m] = true; return true; }
                return false;
            });
            stepsHtml = distMats.map(r => `<div class="pcw-cdm-step-item"><span>${esc(r.material)}</span><strong>${esc(r.mat_desc || r.maktx || '')}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Unique Materials</span><strong>${fmt(s.materials)}</strong></div>`;
            rowsHtml = distMats.map(r => `<tr>
                <td>#${esc(r.poitem || 1)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue)}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'po_so_qty') {
            icon = 'inventory_2';
            subtitle = 'PO DATA';
            title = 'Total SO Qty';
            val = fmt(s.total_order_qty || s.total_so_qty);
            unit = uom;
            formula = 'Total SO Qty = ∑ (PO Order Quantity across unique items)';
            const seenIt = {};
            const itRows = rows.filter(r => {
                const it = String(r.poitem || r.po_item || '').trim();
                if (it && !seenIt[it]) { seenIt[it] = true; return true; }
                return !it;
            });
            stepsHtml = itRows.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item)}</span><strong>${qty(r.tot_poqty || r.order_qty || 0)} ${esc(r.ouom || uom)}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Order Qty</span><strong>${fmt(s.total_order_qty || s.total_so_qty)} ${esc(uom)}</strong></div>`;
            rowsHtml = rows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">SO: ${esc(r.salordno || r.sales_order || '—')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue)}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'po_value') {
            icon = 'payments';
            subtitle = 'PO DATA';
            title = 'Total Net Value';
            val = fmt(s.total_po_value);
            unit = curr;
            formula = 'Total Net Value = ∑ (PO Order Quantity × PO Unit Rate)';
            const seenIt = {};
            const itRows = rows.filter(r => {
                const it = String(r.poitem || r.po_item || '').trim();
                if (it && !seenIt[it]) { seenIt[it] = true; return true; }
                return !it;
            });
            stepsHtml = itRows.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item)} (${qty(r.tot_poqty || 0)} × ₹${fmtDec(r.porate ?? r.po_unit_cost, 2)})</span><strong>₹${money(r.totvalue)}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Net Value</span><strong>₹${fmt(s.total_po_value)} ${esc(curr)}</strong></div>`;
            rowsHtml = rows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue)}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'zcost_items') {
            icon = 'format_list_numbered';
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total Items';
            val = fmt(s.zcost_items);
            formula = 'zCOST Items = Count of PO items with a confirmed zCOST match (unmatched excluded)';
            const matchedRows = rows.filter(isMatchedRow);
            const seenZ = {};
            const distZ = matchedRows.filter(r => {
                const it = String(r.poitem || r.po_item || '').trim();
                if (it && !seenZ[it]) { seenZ[it] = true; return true; }
                return false;
            });
            stepsHtml = distZ.length
                ? distZ.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem)} Matched to zCOST Line</span><strong>${esc(r.maktx || r.cost_description || 'Matched')}</strong></div>`).join('')
                    + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Matched Items</span><strong>${fmt(s.zcost_items)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched items</span><strong>0</strong></div>';
            rowsHtml = distZ.map(r => `<tr>
                <td>#${esc(r.poitem)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                <td class="num">${qty(r.tot_poqty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.budget_cost_per_gmt ?? r.expected_unit_cost, 4)}</td>
                <td class="num">₹${money((r.budget_cost_per_gmt ?? r.expected_unit_cost) * (r.tot_poqty || 1))}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'zcost_materials') {
            icon = 'category';
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total Material';
            val = fmt(s.zcost_materials);
            formula = 'zCOST Material = Count of distinct PO materials with a confirmed zCOST match (unmatched excluded)';
            const matchedRows = rows.filter(isMatchedRow);
            const seenMat = {};
            const distMats = matchedRows.filter(r => {
                const m = String(r.material || '').trim();
                if (m && !seenMat[m]) { seenMat[m] = true; return true; }
                return false;
            });
            stepsHtml = distMats.length
                ? distMats.map(r => `<div class="pcw-cdm-step-item"><span>Material: ${esc(r.material)}</span><strong>zCOST: ${esc(r.maktx || r.cost_description || 'Matched')}</strong></div>`).join('')
                    + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Matched Materials</span><strong>${fmt(s.zcost_materials)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched materials</span><strong>0</strong></div>';
            rowsHtml = distMats.map(r => `<tr>
                <td>#${esc(r.poitem || 1)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">${esc(r.maktx || r.cost_description || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.budget_cost_per_gmt ?? r.expected_unit_cost, 4)}</td>
                <td class="num">₹${money((r.budget_cost_per_gmt ?? r.expected_unit_cost) * (r.tot_poqty || 1))}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'zcost_so_qty') {
            icon = 'inventory_2';
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total SO Qty';
            val = fmt(s.zcost_so_qty);
            unit = uom;
            formula = 'zCOST SO Qty = ∑ (Order Quantity of MATCHED PO items only, unmatched excluded)';
            const matchedRows = rows.filter(isMatchedRow);
            const seenIt = {};
            const itRows = matchedRows.filter(r => {
                const it = String(r.poitem || r.po_item || '').trim();
                if (it && !seenIt[it]) { seenIt[it] = true; return true; }
                return !it;
            });
            stepsHtml = itRows.length
                ? itRows.map(r => `<div class="pcw-cdm-step-item"><span>Matched Item #${esc(r.poitem)}</span><strong>${qty(r.tot_poqty || r.order_qty || 0)} ${esc(r.ouom || uom)}</strong></div>`).join('')
                    + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Matched Qty</span><strong>${fmt(s.zcost_so_qty)} ${esc(uom)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched quantity</span><strong>0</strong></div>';
            rowsHtml = matchedRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.budget_cost_per_gmt ?? r.expected_unit_cost, 4)}</td>
                <td class="num">₹${money((r.budget_cost_per_gmt ?? r.expected_unit_cost) * (r.tot_poqty || 1))}</td>
                <td>${statusPill(r.status)}</td>
            </tr>`).join('');
        } else if (cardKey === 'zcost_value') {
            icon = 'payments';
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total Net Value (Budget Total)';
            val = fmt(s.zcost_net_value);
            unit = curr;
            formula = 'zCOST Net Value = ∑ (Cost Quantity × Budget Cost per GMT) for MATCHED items only';
            const matchedRows = rows.filter(isMatchedRow);
            stepsHtml = matchedRows.length
                ? matchedRows.map(r => {
                    const b = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost) || 0;
                    const q = Number(r.tot_poqty || r.order_qty || 1);
                    return `<div class="pcw-cdm-step-item"><span>Item #${esc(r.poitem)} (${qty(q)} × ₹${fmtDec(b, 4)})</span><strong>₹${money(b * q)}</strong></div>`;
                }).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Expected Budget</span><strong>₹${fmt(s.zcost_net_value)} ${esc(curr)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched value</span><strong>₹0</strong></div>';
            rowsHtml = matchedRows.map((r, i) => {
                const b = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost) || 0;
                const q = Number(r.tot_poqty || r.order_qty || 1);
                return `<tr>
                    <td>#${esc(r.poitem || (i + 1))}</td>
                    <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                    <td class="num">${qty(q)} ${esc(r.ouom || uom)}</td>
                    <td class="num">₹${fmtDec(b, 4)}</td>
                    <td class="num">₹${money(b * q)}</td>
                    <td>${statusPill(r.status)}</td>
                </tr>`;
            }).join('');
        }

        const isZcost = String(cardKey || '').startsWith('zcost_');
        const thRate = isZcost ? 'Budget Rate' : 'PO Rate';
        const thVal = isZcost ? 'Budget Value' : 'Net Value';
        if ($('pcwCardModalTableHead')) {
            $('pcwCardModalTableHead').innerHTML = `
                <th># Item</th>
                <th>${isZcost ? 'PO Material / zCOST Match' : 'Material / Description'}</th>
                <th class="num">Quantity</th>
                <th class="num">${thRate}</th>
                <th class="num">${thVal}</th>
                <th>Status</th>
            `;
        }

        $('pcwCardModalIcon').textContent = icon;
        $('pcwCardModalSubtitle').textContent = subtitle;
        $('pcwCardModalTitle').textContent = title;
        $('pcwCardModalVal').textContent = val;
        $('pcwCardModalUnit').textContent = unit;
        $('pcwCardModalFormula').textContent = formula;
        $('pcwCardModalSteps').innerHTML = stepsHtml || '<p style="color:#64748b; margin:0;">Standard aggregation across active records.</p>';
        $('pcwCardModalTableBody').innerHTML = rowsHtml || '<tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">No rows found for this metric.</td></tr>';
        $('pcwCardModalTableCount').textContent = `Showing ${tableTitle}`;

        const filterBtn = $('pcwCardModalFilterBtn');
        if (filterBtn) {
            if (showFilterBtn && filterStatus) {
                filterBtn.style.display = 'inline-flex';
                $('pcwCardModalFilterLabel').textContent = `Filter Table by ${filterStatus}`;
                filterBtn.onclick = () => {
                    closeCardDetailModal();
                    filterTableByStatus(filterStatus);
                };
            } else {
                filterBtn.style.display = 'none';
            }
        }

        modal.hidden = false;
        backdrop.hidden = false;
    }

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
        const el =
            document.querySelector('#pcwControlBar input[name="mat_cat"]:checked') ||
            document.querySelector('input[name="pcw_mat"]:checked');
        const v = el ? el.value : root?.dataset.mat || cfg.matCat || 'trims';
        return v === 'fabric' || v === 'trims' ? v : 'trims';
    }

    function setPassStatus(summary) {
        const el = $('pcwPassStatus');
        const btn = $('pcwPassPoBtn');
        const po = headerPo();
        if (!el) return;
        el.classList.remove('is-passed', 'is-partial', 'is-pending');
        if (!po) {
            el.textContent = 'STATUS : PENDING';
            el.classList.add('is-pending');
            if (btn) btn.disabled = true;
            return;
        }
        const s = summary || {};
        const exceptions = Number(s.exceptions || 0);
        const review = Number(s.needs_review || 0);
        const passed = Number(s.passed || 0);
        const canPass = !!s.can_pass_po || (passed > 0 && exceptions === 0 && review === 0);
        if (canPass && passed > 0) {
            el.textContent = 'STATUS : READY TO PASS';
            el.classList.add('is-partial');
        } else if (exceptions > 0) {
            el.textContent = 'STATUS : NOT PASSED';
        } else if (review > 0) {
            el.textContent = 'STATUS : NOT PASSED';
            el.classList.add('is-partial');
        } else {
            el.textContent = 'STATUS : NOT PASSED';
        }
        if (btn) btn.disabled = !canPass;
    }

    function isMatchedRow(r) {
        if (!r) return false;
        if (r.needs_manual_match === true) return false;
        if (r.manual_match) return true;
        const hasMatch = !!(r.maktx || r.cost_description || r.cost_item || r.item || r.matched_line_key);
        if (!hasMatch) return false;
        const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        if (budget === null || budget <= 0) return false;
        const st = String(r.status || '').toUpperCase();
        if (st === 'EXCEPTION' && /no matching|no linked|cannot load|no budget/i.test(String(r.validation_result || ''))) {
            return false;
        }
        return true;
    }

    function summarizeRows(list) {
        const materials = {};
        const salesOrders = {};
        const items = {};
        const seenValue = {};
        let passed = 0;
        let exceptions = 0;
        let needsReview = 0;
        let totalValue = 0;
        let totalOrderQty = 0;
        let poSoQty = 0;
        let totalExpected = 0;
        let totalActual = 0;
        let unit = '';
        let currency = 'INR';

        const zItems = {};
        const zMaterials = {};
        const seenZItem = {};
        let zcostSoQty = 0;
        let zcostExpected = 0;

        (list || []).forEach((r) => {
            const st = String(r.status || '').toUpperCase();
            if (st === 'PASS') passed += 1;
            else if (st === 'NEEDS_REVIEW') needsReview += 1;
            else exceptions += 1;
            const mat = String(r.material || '').trim();
            if (mat) materials[mat] = true;
            const so = String(r.salordno || r.sales_order || '').trim();
            if (so) salesOrders[so] = true;
            const item = String(r.poitem || r.po_item || '').trim();
            if (item) items[item] = true;

            const rowSoQty = Number(r.so_qty ?? r.prwise_so_qty ?? 0) || 0;
            const rowOrderQty = Number(r.tot_poqty ?? r.order_qty ?? r.po_qty ?? 0) || 0;
            const effectiveQty = rowSoQty > 0 ? rowSoQty : rowOrderQty;
            poSoQty += effectiveQty;

            if (item && !seenValue[item]) {
                seenValue[item] = true;
                totalValue += Number(r.totvalue ?? r.net_value ?? 0) || 0;
                totalOrderQty += rowOrderQty;
            } else if (!item) {
                totalValue += Number(r.totvalue ?? r.net_value ?? 0) || 0;
                totalOrderQty += rowOrderQty;
            }
            const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            const actual = nVal(r.actual_cost ?? r.po_unit_cost);
            const costQty = rowSoQty > 0 ? rowSoQty : (rowOrderQty > 0 ? rowOrderQty : 1);
            if (budget !== null) totalExpected += budget * costQty;
            if (actual !== null) totalActual += actual * costQty;

            // Strict match check: only count rows that have a confirmed zCOST match
            const isMatched = isMatchedRow(r);

            if (isMatched) {
                if (item) zItems[item] = true;
                if (mat) zMaterials[mat] = true;

                if (rowSoQty > 0) {
                    zcostSoQty += rowSoQty;
                } else if (item && !seenZItem[item]) {
                    seenZItem[item] = true;
                    zcostSoQty += rowOrderQty;
                } else if (!item) {
                    zcostSoQty += rowOrderQty;
                }
                zcostExpected += (budget || 0) * costQty;
            }

            if (!unit) unit = String(r.ouom || r.unit || '').trim();
            if (r.po_currency || r.currency) currency = String(r.po_currency || r.currency);
        });

        const poTotalSoQty = totalOrderQty > 0 ? totalOrderQty : poSoQty;
        const zcostItemCount = Object.keys(zItems).length;
        const zcostMatCount = Object.keys(zMaterials).length;
        const finalZcostSoQty = zcostSoQty;
        const finalZcostNetValue = zcostExpected;

        return {
            total_records: (list || []).length,
            po_items: Object.keys(items).length || (list || []).length,
            materials: Object.keys(materials).length,
            sales_orders: Object.keys(salesOrders).length,
            total_order_qty: totalOrderQty,
            total_so_qty: poTotalSoQty,
            total_po_value: totalValue,
            zcost_items: zcostItemCount,
            zcost_materials: zcostMatCount,
            zcost_so_qty: finalZcostSoQty,
            zcost_net_value: finalZcostNetValue,
            total_expected: totalExpected,
            total_actual: totalActual,
            unit,
            currency,
            passed,
            exceptions,
            needs_review: needsReview,
        };
    }

    function fillKpis(summary) {
        const fromApi = summary || {};
        const fromRows = rows.length ? summarizeRows(rows) : {};
        const s = {
            ...fromRows,
            ...Object.fromEntries(
                Object.entries(fromApi).filter(([, v]) => v !== null && v !== undefined && v !== '')
            ),
        };
        // Prefer API counts when present; keep row-derived qty/value if API zeros look wrong
        if (!(Number(fromApi.total_order_qty) > 0) && Number(fromRows.total_order_qty) > 0) {
            s.total_order_qty = fromRows.total_order_qty;
            s.total_so_qty = fromRows.total_so_qty;
            s.unit = fromRows.unit || s.unit;
        }
        if (!(Number(fromApi.total_po_value) > 0) && Number(fromRows.total_po_value) > 0) {
            s.total_po_value = fromRows.total_po_value;
        }
        if (!(Number(fromApi.total_expected) > 0) && Number(fromRows.total_expected) > 0) {
            s.total_expected = fromRows.total_expected;
        }
        if (!(Number(fromApi.total_actual) > 0) && Number(fromRows.total_actual) > 0) {
            s.total_actual = fromRows.total_actual;
        }
        const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
        const total = (Number(s.passed) || 0) + (Number(s.exceptions) || 0) + (Number(s.needs_review) || 0) || rows.length || 0;

        // Row 1: Common status
        if ($('pcwKpiTotal')) $('pcwKpiTotal').textContent = fmt(total);
        if ($('pcwKpiPassed')) $('pcwKpiPassed').textContent = fmt(s.passed || 0);
        if ($('pcwKpiExceptions')) $('pcwKpiExceptions').textContent = fmt(s.exceptions || 0);
        if ($('pcwKpiReview')) $('pcwKpiReview').textContent = fmt(s.needs_review || 0);

        // Row 2 Left: PO Data
        const poItems = Number(s.po_items) > 0 ? s.po_items : (s.unique_po_items || rows.length || 0);
        const poMaterials = Number(s.materials) > 0 ? s.materials : 0;
        const poSoQty = Number(s.total_order_qty) > 0 ? s.total_order_qty : (s.total_so_qty || 0);
        const poNetVal = Number(s.total_po_value) >= 0 ? s.total_po_value : 0;
        const curr = s.currency || 'INR';
        const unit = s.unit || '';

        if ($('pcwKpiPoItems')) $('pcwKpiPoItems').textContent = fmt(poItems);
        if ($('pcwKpiItems')) $('pcwKpiItems').textContent = fmt(poItems);

        if ($('pcwKpiPoMaterials')) $('pcwKpiPoMaterials').textContent = fmt(poMaterials);
        if ($('pcwKpiMaterials')) $('pcwKpiMaterials').textContent = fmt(poMaterials);

        if ($('pcwKpiPoOrderQty')) $('pcwKpiPoOrderQty').textContent = fmt(poSoQty);
        if ($('pcwKpiOrderQty')) $('pcwKpiOrderQty').textContent = fmt(poSoQty);
        if ($('pcwKpiPoOrderQtyUnit')) $('pcwKpiPoOrderQtyUnit').textContent = unit;
        if ($('pcwKpiOrderQtyUnit')) $('pcwKpiOrderQtyUnit').textContent = unit;

        if ($('pcwKpiPoValue')) $('pcwKpiPoValue').textContent = fmt(poNetVal);
        if ($('pcwKpiValue')) $('pcwKpiValue').textContent = fmt(poNetVal);
        if ($('pcwKpiPoValueUnit')) $('pcwKpiPoValueUnit').textContent = curr;
        if ($('pcwKpiValueUnit')) $('pcwKpiValueUnit').textContent = curr;

        // Row 2 Right: zCOST Data (Matched only)
        const zItems = fromRows.zcost_items ?? 0;
        const zMaterials = fromRows.zcost_materials ?? 0;
        const zSoQty = fromRows.zcost_so_qty ?? 0;
        const zNetVal = (Number(fromRows.zcost_net_value) > 0)
            ? Number(fromRows.zcost_net_value)
            : ((Number(s.total_expected) > 0 && zItems > 0) ? Number(s.total_expected) : 0);

        if ($('pcwKpiZcostItems')) $('pcwKpiZcostItems').textContent = fmt(zItems);
        if ($('pcwKpiZcostMaterials')) $('pcwKpiZcostMaterials').textContent = fmt(zMaterials);
        if ($('pcwKpiZcostOrderQty')) $('pcwKpiZcostOrderQty').textContent = fmt(zSoQty);
        if ($('pcwKpiZcostOrderQtyUnit')) $('pcwKpiZcostOrderQtyUnit').textContent = unit;

        if ($('pcwKpiZcostValue')) $('pcwKpiZcostValue').textContent = fmt(zNetVal);
        if ($('pcwKpiExpected')) $('pcwKpiExpected').textContent = fmt(zNetVal);
        if ($('pcwKpiZcostValueUnit')) $('pcwKpiZcostValueUnit').textContent = curr;
        if ($('pcwKpiExpectedUnit')) $('pcwKpiExpectedUnit').textContent = curr;

        setPassStatus(s);
        if ($('pcwStatusKpis')) $('pcwStatusKpis').hidden = false;
        if ($('pcwKpis')) $('pcwKpis').hidden = false;
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

    function needsManualMatch(r) {
        return !isMatchedRow(r);
    }

    function needsDropdown(r) {
        return needsManualMatch(r);
    }

    function lineKey(line, i) {
        return [
            String(line.quotation_no || ''),
            String(line.so_item || ''),
            String(line.line_no || ''),
            String(line.item || ''),
            String(line.maktx || line.description || ''),
            String(i),
        ].join('|');
    }

    function candidateLinesForRow(r) {
        const mat = selectedMat();
        const matCode = String(r.material || '').trim().toLowerCase();
        const desc = String(r.mat_desc || r.material_desc || r.description || '').trim().toLowerCase();
        const group = String(r.matgroup || '').trim().toLowerCase();
        return (costLines || []).filter((line) => {
            if (mat === 'fabric' && String(line.section || '') !== 'Fabric' && line.mat_category !== 'fabric') {
                return false;
            }
            if (mat === 'trims' && String(line.section || '') === 'Fabric' && line.mat_category === 'fabric') {
                return false;
            }
            return true;
        }).sort((a, b) => {
            const aItem = String(a.item || a.material || '').toLowerCase();
            const bItem = String(b.item || b.material || '').toLowerCase();
            const aDesc = String(a.maktx || a.description || '').toLowerCase();
            const bDesc = String(b.maktx || b.description || '').toLowerCase();
            const aScore = (matCode && aItem === matCode ? 100 : 0)
                + (matCode && aItem.includes(matCode) ? 40 : 0)
                + (desc && aDesc && (aDesc.includes(desc.slice(0, 24)) || desc.includes(aDesc.slice(0, 24))) ? 30 : 0)
                + (group && (aItem.includes('thread') || aDesc.includes('thread')) && group.includes('thread') ? 50 : 0);
            const bScore = (matCode && bItem === matCode ? 100 : 0)
                + (matCode && bItem.includes(matCode) ? 40 : 0)
                + (desc && bDesc && (bDesc.includes(desc.slice(0, 24)) || desc.includes(bDesc.slice(0, 24))) ? 30 : 0)
                + (group && (bItem.includes('thread') || bDesc.includes('thread')) && group.includes('thread') ? 50 : 0);
            return bScore - aScore;
        });
    }

    function findSameValueRows(sourceRow, sourceIndex) {
        if (!sourceRow) return { exactMatMatches: [], groupMatches: [], checkedMatches: [], allMatches: [] };
        const sourceMat = String(sourceRow.material || '').trim().toLowerCase();
        const sourceGroup = String(sourceRow.matgroup || '').trim().toLowerCase();

        const exactMatMatches = [];
        const groupMatches = [];
        const checkedMatches = [];
        const seenIdx = new Set();

        rows.forEach((r, idx) => {
            if (idx === sourceIndex) return;

            const rMat = String(r.material || '').trim().toLowerCase();
            const rGroup = String(r.matgroup || '').trim().toLowerCase();

            if (sourceMat && rMat && rMat === sourceMat) {
                exactMatMatches.push({ index: idx, row: r, reason: 'same_material' });
                seenIdx.add(idx);
            } else if (sourceGroup && rGroup && rGroup === sourceGroup) {
                groupMatches.push({ index: idx, row: r, reason: 'same_group' });
                seenIdx.add(idx);
            }
            if (r.selected && !seenIdx.has(idx)) {
                checkedMatches.push({ index: idx, row: r, reason: 'selected' });
                seenIdx.add(idx);
            }
        });

        const allMatches = [
            ...exactMatMatches,
            ...groupMatches.filter(m => !exactMatMatches.some(e => e.index === m.index)),
            ...checkedMatches.filter(m => !exactMatMatches.some(e => e.index === m.index) && !groupMatches.some(g => g.index === m.index)),
        ];

        return { exactMatMatches, groupMatches, checkedMatches, allMatches };
    }

    let pendingSameValAction = null;

    function closeSameValModal() {
        const modal = $('pcwSameValModal');
        const backdrop = $('pcwSameValBackdrop');
        if (modal) modal.hidden = true;
        if (backdrop) backdrop.hidden = true;
        pendingSameValAction = null;
    }

    function handleZcostDropdownChange(idx, key) {
        const targetRow = rows[idx];
        if (!targetRow) return;

        if (!key) {
            targetRow.cost_item = '';
            targetRow.item = '';
            targetRow.maktx = '';
            targetRow.manual_match = false;
            targetRow.needs_manual_match = true;
            targetRow.matched_line_key = '';
            targetRow._editingMatch = false;
            fillKpis(summarizeRows(rows));
            renderTable();
            return;
        }

        const cands = candidateLinesForRow(targetRow);
        const line = (costLines || []).find((l, i) => lineKey(l, i) === key)
            || cands.find((l, i) => lineKey(l, i) === key);
        if (!line) return;

        applyCostLineToRow(targetRow, line);
        targetRow.matched_line_key = key;
        targetRow._editingMatch = false;

        const matches = findSameValueRows(targetRow, idx);
        const hasSameMat = matches.exactMatMatches.length > 0;
        const hasSameGroup = matches.groupMatches.length > 0;
        const hasChecked = matches.checkedMatches.length > 0;

        if (hasSameMat || hasSameGroup || hasChecked) {
            const modal = $('pcwSameValModal');
            const backdrop = $('pcwSameValBackdrop');
            const matchedBox = $('pcwSameValMatchedBox');
            const optionsEl = $('pcwSameValOptions');
            const applyLabel = $('pcwSameValApplyLabel');

            if (matchedBox) {
                const itName = esc(line.item || line.material || 'zCOST Line');
                const maktx = esc(line.maktx || line.description || '');
                const rt = nVal(line.rate) ?? nVal(line.purp);
                matchedBox.innerHTML = `<strong>Matched PO Item #${esc(targetRow.poitem || targetRow.po_item || (idx + 1))}: ${itName}</strong>
                    <span>${maktx} ${rt !== null ? `· Rate: ₹${fmtStep(rt, 2)}` : ''}</span>`;
            }

            let optionsHtml = '';

            if (hasSameMat) {
                const count = matches.exactMatMatches.length;
                optionsHtml += `<label class="pcw-svm-option-label is-active">
                    <input type="radio" name="pcwSvmChoice" value="mat" checked>
                    <div class="pcw-svm-option-text">
                        <strong>Match All ${count + 1} Items with Same Material Code (${esc(targetRow.material)})</strong>
                        <small>Items: #${esc(targetRow.poitem || (idx + 1))}, ${matches.exactMatMatches.map(m => '#' + (m.row.poitem || (m.index + 1))).join(', ')}</small>
                    </div>
                </label>`;
            }

            if (hasSameGroup) {
                const count = matches.groupMatches.length;
                const isChk = !hasSameMat ? ' checked' : '';
                optionsHtml += `<label class="pcw-svm-option-label${!hasSameMat ? ' is-active' : ''}">
                    <input type="radio" name="pcwSvmChoice" value="group"${isChk}>
                    <div class="pcw-svm-option-text">
                        <strong>Match All ${count + 1} Items in Material Group "${esc(targetRow.matgroup || 'Same Group')}"</strong>
                        <small>Items: #${esc(targetRow.poitem || (idx + 1))}, ${matches.groupMatches.map(m => '#' + (m.row.poitem || (m.index + 1))).join(', ')}</small>
                    </div>
                </label>`;
            }

            if (hasChecked) {
                const count = matches.checkedMatches.length;
                const isChk = (!hasSameMat && !hasSameGroup) ? ' checked' : '';
                optionsHtml += `<label class="pcw-svm-option-label${(!hasSameMat && !hasSameGroup) ? ' is-active' : ''}">
                    <input type="radio" name="pcwSvmChoice" value="checked"${isChk}>
                    <div class="pcw-svm-option-text">
                        <strong>Match All ${count + 1} Currently Selected Items</strong>
                        <small>Items: #${esc(targetRow.poitem || (idx + 1))}, ${matches.checkedMatches.map(m => '#' + (m.row.poitem || (m.index + 1))).join(', ')}</small>
                    </div>
                </label>`;
            }

            if (optionsEl) optionsEl.innerHTML = optionsHtml;
            if (applyLabel) applyLabel.textContent = `Match All Items`;

            pendingSameValAction = {
                line,
                key,
                targetIndex: idx,
                matches,
            };

            if (modal) modal.hidden = false;
            if (backdrop) backdrop.hidden = false;
            return;
        }

        fillKpis(summarizeRows(rows));
        renderTable();
    }

    function applyCostLineToRow(r, line) {
        const budget = nVal(line.rate) ?? nVal(line.purp) ?? nVal(line.net_price);
        const actual = nVal(r.actual_cost ?? r.po_unit_cost ?? r.porate);
        let variancePct = null;
        let status = 'PASS';
        let result = 'PASS — manually matched to zCOST line';
        if (budget !== null && budget > 0 && actual !== null) {
            variancePct = roundPct(((actual - budget) / budget) * 100);
            if (actual <= budget) {
                status = 'PASS';
                result = 'PASS — manually matched to zCOST line';
            } else {
                status = 'EXCEPTION';
                result = 'EXCEPTION — actual cost exceeds matched budget';
            }
        } else if (budget !== null && budget > 0) {
            status = 'PASS';
            result = 'PASS — manually matched to zCOST line';
        }

        r.cost_item = String(line.item || '');
        r.item = r.cost_item;
        r.maktx = String(line.maktx || line.description || '');
        r.cost_description = r.maktx;
        r.cost_section = String(line.section || '');
        r.zsection = String(line.zsection || '');
        r.budgeted_cons = nVal(line.gross) ?? nVal(line.net) ?? r.budgeted_cons;
        r.budget_inr = nVal(line.total_inr) ?? r.budget_inr;
        r.budget_currency = String(line.currency || r.budget_currency || 'INR');
        r.budget_conv_rt = nVal(line.convr) ?? r.budget_conv_rt;
        r.budget_cost_per_gmt = budget;
        r.expected_unit_cost = budget;
        r.conv = nVal(line.convr) ?? r.conv;
        r.buom = String(line.unit || r.buom || '');
        if (actual !== null) {
            r.actual_cost = actual;
            r.po_unit_cost = actual;
        }
        r.variance_pct = variancePct;
        r.status = status;
        r.validation_result = result;
        r.cost_matrix = {
            budget,
            actual,
            status,
        };
        r.manual_match = true;
        r.needs_manual_match = false;
        r._editingMatch = false;
        r.matched_quotation = String(line.quotation_no || '');
        r.matched_line_no = String(line.line_no || '');
        return r;
    }

    function roundPct(n) {
        return Math.round(n * 100) / 100;
    }

    function syncSameValueHighlights() {
        const selected = rows.filter((r) => !!r.selected);
        const trs = tbody?.querySelectorAll('tr.pcw-row') || [];
        if (selected.length === 0) {
            trs.forEach((tr) => tr.classList.remove('is-same-mat'));
            return;
        }
        const selMats = new Set(selected.map((r) => String(r.material || '').trim().toLowerCase()).filter(Boolean));
        const selGroups = new Set(selected.map((r) => String(r.matgroup || '').trim().toLowerCase()).filter(Boolean));

        trs.forEach((tr) => {
            const idx = Number(tr.getAttribute('data-idx'));
            const r = rows[idx];
            if (!r) return;
            const mat = String(r.material || '').trim().toLowerCase();
            const grp = String(r.matgroup || '').trim().toLowerCase();
            const isSame = (mat && selMats.has(mat)) || (grp && selGroups.has(grp));
            tr.classList.toggle('is-same-mat', isSame && !r.selected);
        });
    }

    function syncSelectionUi() {
        const selected = rows.filter((r) => !!r.selected);
        const countEl = $('pcwSelectedCount');
        const matchBtn = $('pcwMatchSelectedBtn');
        if (countEl) {
            countEl.hidden = selected.length === 0;
            countEl.textContent = `${selected.length} selected`;
        }
        if (matchBtn) {
            const canMatch = selected.some((r) => needsManualMatch(r)) && costLines.length > 0;
            matchBtn.disabled = !canMatch;
        }
        const all = $('pcwCheckAll');
        if (all) {
            const matchable = rows.filter((r) => needsManualMatch(r));
            all.checked = matchable.length > 0 && matchable.every((r) => !!r.selected);
            all.indeterminate = selected.length > 0 && !all.checked;
        }

        let selectSameBtn = $('pcwSelectSameBtn');
        if (selected.length === 1) {
            const selRow = selected[0];
            const selIdx = rows.indexOf(selRow);
            const matches = findSameValueRows(selRow, selIdx);
            const targetMatches = matches.exactMatMatches.length ? matches.exactMatMatches : matches.groupMatches;
            if (targetMatches.length > 0) {
                if (!selectSameBtn) {
                    selectSameBtn = document.createElement('button');
                    selectSameBtn.type = 'button';
                    selectSameBtn.id = 'pcwSelectSameBtn';
                    selectSameBtn.className = 'rpt-tb-btn pcw-select-same-btn';
                    countEl?.parentNode?.insertBefore(selectSameBtn, countEl.nextSibling);
                    selectSameBtn.addEventListener('click', () => {
                        const curMatches = findSameValueRows(rows.find(r => r.selected), rows.findIndex(r => r.selected));
                        const mList = curMatches.exactMatMatches.length ? curMatches.exactMatMatches : curMatches.groupMatches;
                        mList.forEach((m) => { m.row.selected = true; });
                        renderTable();
                    });
                }
                const labelGroup = selRow.material || selRow.matgroup || 'same';
                selectSameBtn.innerHTML = `<span class="material-icons-round" style="font-size:14px;">done_all</span> Select All ${targetMatches.length + 1} (${esc(labelGroup)})`;
                selectSameBtn.style.display = 'inline-flex';
            } else if (selectSameBtn) {
                selectSameBtn.style.display = 'none';
            }
        } else if (selectSameBtn) {
            selectSameBtn.style.display = 'none';
        }
    }

    function openMatchPicker(index) {
        const r = rows[index];
        if (!r) return;
        matchRowIndex = index;
        matchPickKey = '';
        const pop = $('pcwMatchPop');
        const title = $('pcwMatchTitle');
        const desc = $('pcwMatchDesc');
        const search = $('pcwMatchSearch');
        if (title) title.textContent = 'Match zCOST line';
        if (desc) {
            desc.textContent = `PO item ${r.poitem || r.po_item || '—'} · ${r.material || '—'} — pick a zCOST line to link.`;
        }

        const matches = findSameValueRows(r, index);
        const sameCount = matches.exactMatMatches.length || matches.groupMatches.length;
        const lbl = $('pcwMatchMultiCheckLabel');
        if (lbl) {
            const reason = matches.exactMatMatches.length
                ? `same Material (${r.material})`
                : (matches.groupMatches.length ? `same Group (${r.matgroup || 'THREADS1'})` : 'same values');
            lbl.textContent = sameCount > 0
                ? `Match all ${sameCount + 1} items with ${reason}`
                : `Match all selected items`;
        }

        if (search) search.value = '';
        renderMatchList();
        if (pop) pop.hidden = false;
        $('pcwMatchApply') && ($('pcwMatchApply').disabled = true);
        search?.focus();
    }

    function closeMatchPicker() {
        const pop = $('pcwMatchPop');
        if (pop) pop.hidden = true;
        matchRowIndex = null;
        matchPickKey = '';
    }

    function renderMatchList() {
        const list = $('pcwMatchList');
        if (!list || matchRowIndex === null) return;
        const r = rows[matchRowIndex];
        const q = String($('pcwMatchSearch')?.value || '').trim().toLowerCase();
        let lines = candidateLinesForRow(r || {});
        if (q) {
            lines = lines.filter((line) => {
                const blob = [
                    line.item, line.material, line.maktx, line.description,
                    line.quotation_no, line.section, line.rate, line.currency,
                ].join(' ').toLowerCase();
                return blob.includes(q);
            });
        }
        if (!lines.length) {
            list.innerHTML = `<div class="pcw-match-empty">
                <span class="material-icons-round" style="font-size:32px; color:#f59e0b; display:block; margin-bottom:6px;">search_off</span>
                <p style="margin:0 0 10px; font-weight:700; color:#334155;">
                    ${costLines.length ? 'No zCOST lines match this search filter.' : 'No zCOST candidates automatically found for this PO.'}
                </p>
                ${!costLines.length ? `
                <div style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-top:8px;">
                    <div style="font-size:12px; font-weight:700; color:#1e1b4b; margin-bottom:6px;">Load Costing Lines by Quotation # or Sales Order #:</div>
                    <div style="display:flex; gap:6px;">
                        <input type="text" id="pcwManualQuoteInput" class="rpt-tb-input" placeholder="e.g. 22004502 or 3115" style="flex:1; height:32px; font-size:12px;">
                        <button type="button" id="pcwManualQuoteBtn" class="rpt-tb-btn rpt-tb-btn-primary" style="height:32px;">
                            <span class="material-icons-round" style="font-size:14px;">download</span> Load
                        </button>
                    </div>
                    <div id="pcwManualQuoteStatus" style="font-size:11px; margin-top:4px; color:#64748b;"></div>
                </div>` : ''}
            </div>`;

            $('pcwManualQuoteBtn')?.addEventListener('click', async () => {
                const qVal = String($('pcwManualQuoteInput')?.value || '').trim();
                if (!qVal) return;
                const statusEl = $('pcwManualQuoteStatus');
                if (statusEl) { statusEl.textContent = 'Loading costing lines from SAP…'; statusEl.style.color = '#4f46e5'; }
                try {
                    await runValidate({ customer: qVal, mode: 'non_validated' });
                    renderMatchList();
                } catch (e) {
                    if (statusEl) { statusEl.textContent = 'Failed: ' + e.message; statusEl.style.color = '#ef4444'; }
                }
            });
            return;
        }
        list.innerHTML = lines
            .slice(0, 120)
            .map((line, i) => {
                const key = lineKey(line, i);
                const checked = key === matchPickKey ? ' checked' : '';
                const rate = nVal(line.rate) ?? nVal(line.purp);
                return `<label class="pcw-match-option${checked ? ' is-active' : ''}" data-key="${esc(key)}">
                    <input type="radio" name="pcwMatchPick" value="${esc(key)}"${checked}>
                    <span class="pcw-match-option-body">
                        <strong>${esc(line.item || '—')}</strong>
                        <em>${esc(line.maktx || line.description || '—')}</em>
                        <small>
                            ${esc(line.section || '—')}
                            · Q ${esc(line.quotation_no || '—')}
                            · ${rate !== null ? esc(fmtStep(rate, 4)) : '—'}
                            ${esc(line.currency || '')}
                        </small>
                    </span>
                </label>`;
            })
            .join('');
        // stash lines on list for apply by key
        list._pcwLines = lines.slice(0, 120);
    }

    function cellValue(key, r, index) {
        switch (key) {
            case 'check':
                return r.selected ? '1' : '0';
            case 'sno':
                return String(index + 1);
            case 'status':
                return String(r.status || '');
            case 'pono':
                return r.pono || r.purchase_order || '';
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
                return r.matgroup || r.material_group || '';
            case 'so_qty':
                return r.so_qty;
            case 'prwise_so_qty':
                return r.prwise_so_qty;
            case 'consumption':
                return r.consumption;
            case 'reqqty':
                return r.reqqty;
            case 'tot_poqty':
                return r.tot_poqty;
            case 'so_wise_poqty':
                return r.so_wise_poqty;
            case 'so_wise_po_val':
                return r.so_wise_po_val;
            case 'ouom':
                return r.ouom || r.unit || '';
            case 'porate':
                return r.porate;
            case 'totvalue':
                return r.totvalue ?? r.net_value;
            case 'mat_desc':
                return r.mat_desc || r.material_desc || '';
            case 'po_currency':
                return r.po_currency || r.currency || '';
            case 'cost_item':
                return r.cost_item || r.item || '';
            case 'maktx':
                return r.maktx || r.cost_description || '';
            case 'budgeted_cons':
                return r.budgeted_cons;
            case 'budget_inr':
                return r.budget_inr;
            case 'budget_currency':
                return r.budget_currency || '';
            case 'budget_conv_rt':
                return r.budget_conv_rt;
            case 'budget_cost_per_gmt':
                return r.budget_cost_per_gmt ?? r.expected_unit_cost;
            case 'conv':
                return r.conv;
            case 'buom':
                return r.buom || '';
            case 'actual_cost':
                return r.actual_cost ?? r.po_unit_cost;
            case 'cost_matrix': {
                const b = r.budget_cost_per_gmt ?? r.expected_unit_cost;
                const a = r.actual_cost ?? r.po_unit_cost;
                return b !== null && b !== undefined && a !== null && a !== undefined
                    ? `B ${b} / A ${a}`
                    : '';
            }
            case 'variance_pct':
                return r.variance_pct;
            case 'validation_result':
                return r.validation_result || '';
            default:
                return r[key] ?? '';
        }
    }

    function syncGroupHeaders() {
        const groupRow = $('pcwGroupRow');
        const headerRow = table?.querySelector('thead tr.rpt-header-row');
        if (!groupRow || !headerRow) return;

        const visible = Array.from(headerRow.children).filter((th) => {
            if (!(th instanceof HTMLElement)) return false;
            return window.getComputedStyle(th).display !== 'none';
        });

        const runs = [];
        visible.forEach((th) => {
            const g = th.getAttribute('data-col-group') || colGroup(th.getAttribute('data-col-id') || '');
            const last = runs[runs.length - 1];
            if (last && last.group === g) last.span += 1;
            else runs.push({ group: g, span: 1 });
        });

        if (!runs.length) {
            groupRow.innerHTML = '';
            return;
        }

        groupRow.innerHTML = runs
            .map((run) => {
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
            })
            .join('');
    }

    function wrapExplain(key, index, displayHtml) {
        if (!EXPLAIN_COLS.has(key)) return displayHtml;
        return `<button type="button" class="pcw-formula-cell" data-explain="${esc(key)}" data-idx="${index}" title="Show calculation">${displayHtml}</button>`;
    }

    function renderCell(key, r, index) {
        const group = colGroup(key);
        const classes = [];
        if (NUM_COLS.has(key) || key === 'sno') classes.push('num');
        if (key === 'sno') classes.push('sno');
        if (key === 'check') classes.push('pcw-check');
        classes.push(`pcw-col-${group}`);
        if (HIGHLIGHT_COLS.has(key)) classes.push('pcw-hl-cost');
        if (EXPLAIN_COLS.has(key)) classes.push('is-explainable');
        const cls = classes.length ? ` class="${classes.join(' ')}"` : '';
        let inner = '—';
        switch (key) {
            case 'check': {
                const unmatched = needsManualMatch(r);
                const checked = r.selected ? ' checked' : '';
                inner = `<div class="pcw-select-cell">
                    <input type="checkbox" class="pcw-check-input pcw-row-check" data-idx="${index}"${checked} title="${unmatched ? 'Select unmatched row' : 'Select row'}" aria-label="Select row ${index + 1}">
                    ${unmatched
                        ? `<button type="button" class="pcw-match-btn" data-idx="${index}" title="Manually match this PO item to a zCOST line">
                            <span class="material-icons-round">link</span>
                            Match
                        </button>`
                        : (r.manual_match
                            ? `<span class="pcw-match-tag" title="Manually matched">Manual</span>`
                            : '')}
                </div>`;
                break;
            }
            case 'sno':
                inner = esc(index + 1);
                break;
            case 'status':
                inner = statusPill(r.status);
                break;
            case 'pono':
                inner = `<span class="pcw-mat">${dash(r.pono || r.purchase_order)}</span>`;
                break;
            case 'material':
                inner = `<span class="pcw-mat">${dash(r.material)}</span>`;
                break;
            case 'mat_desc':
                inner = `<span class="pcw-compare-po" title="${esc(r.mat_desc || r.material_desc || '')}">${dash(r.mat_desc || r.material_desc)}</span>`;
                break;
            case 'cost_item': {
                const itDisplay = (r.cost_item || r.item || '').trim();
                inner = `<span class="pcw-compare-po" title="${esc(r.maktx || r.cost_description || '')}">${dash(itDisplay)}</span>`;
                break;
            }
            case 'maktx':
                inner = `<span class="pcw-compare-maktx" title="${esc(r.maktx || r.cost_description || '')}">${dash(r.maktx || r.cost_description)}</span>`;
                break;
            case 'so_qty':
            case 'prwise_so_qty':
            case 'reqqty':
            case 'tot_poqty':
            case 'so_wise_poqty':
                inner = wrapExplain(key, index, qty(cellValue(key, r, index)));
                break;
            case 'so_wise_po_val':
            case 'totvalue':
            case 'budget_inr':
                inner = EXPLAIN_COLS.has(key)
                    ? wrapExplain(key, index, money(cellValue(key, r, index)))
                    : money(cellValue(key, r, index));
                break;
            case 'ouom':
            case 'buom':
            case 'po_currency':
            case 'budget_currency':
                inner = wrapExplain(key, index, dash(cellValue(key, r, index)));
                break;
            case 'consumption':
            case 'porate':
            case 'budgeted_cons':
            case 'budget_conv_rt':
            case 'budget_cost_per_gmt':
            case 'conv':
            case 'actual_cost':
                inner = wrapExplain(key, index, num(cellValue(key, r, index), 4));
                break;
            case 'cost_matrix':
                inner = wrapExplain(key, index, costMatrixHtml(r));
                break;
            case 'variance_pct':
                inner = wrapExplain(key, index, varianceCell(cellValue(key, r, index)));
                break;
            default:
                inner = EXPLAIN_COLS.has(key)
                    ? wrapExplain(key, index, dash(cellValue(key, r, index)))
                    : dash(cellValue(key, r, index));
        }
        return `<td${cls} data-col-id="${esc(key)}" data-col-group="${esc(group)}">${inner}</td>`;
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
                const sel = row.selected ? ' is-selected' : '';
                return `<tr class="pcw-row${open}${pass}${fail}${sel}" data-idx="${index}">${COLS.map((k) => renderCell(k, row, index)).join('')}</tr>`;
            })
            .join('');
        if (noMatch) tbody.appendChild(noMatch);
        colFeatures?.refresh?.();
        syncGroupHeaders();
        syncSelectionUi();
        syncSameValueHighlights();
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
            costLines = Array.isArray(data.cost_lines) ? data.cost_lines : [];
            quoteOptions = Array.isArray(data.quote_options) ? data.quote_options : [];
            validatedQuotes = Array.isArray(data.validated_quotations) ? data.validated_quotations : [];
            nonValidatedQuotes = Array.isArray(data.non_validated_quotations) ? data.non_validated_quotations : [];
            currentMode = data.mode || mode;
            selectedCustomer = String(data.customer_quotation || customer || '');
            selectedEndUser = String(data.end_user_quotation || endUser || '');
            rows.forEach((r) => {
                if (typeof r.selected !== 'boolean') r.selected = false;
            });

            fillKpis(data.summary || {});
            renderTable();

            const page = cfg.pageUrl || 'purchase-order';
            const next = new URL(page, window.location.origin);
            next.searchParams.set('view', 'costing');
            next.searchParams.set('po', po);
            if (so) next.searchParams.set('so', so);
            next.searchParams.set('mat_cat', mat === 'fabric' ? 'fabric' : 'trims');
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
            costLines = [];
            fillKpis({});
            renderTable();
        } finally {
            showSpinner(false);
        }
    }

    function onRowSelectChange(e) {
        const check = e.target.closest('input.pcw-row-check');
        if (check) {
            e.stopPropagation();
            const idx = Number(check.getAttribute('data-idx'));
            if (Number.isFinite(idx) && rows[idx]) {
                rows[idx].selected = !!check.checked;
                const tr = check.closest('tr.pcw-row');
                tr?.classList.toggle('is-selected', !!check.checked);
                syncSelectionUi();
                syncSameValueHighlights();
            }
            return;
        }
        const zcostSel = e.target.closest('select.pcw-zcost-select');
        if (zcostSel) {
            e.stopPropagation();
            const idx = Number(zcostSel.getAttribute('data-idx'));
            const key = zcostSel.value;
            handleZcostDropdownChange(idx, key);
            return;
        }
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

    function onMatchApply() {
        if (matchRowIndex === null || !matchPickKey) return;
        const list = $('pcwMatchList');
        const lines = list?._pcwLines || [];
        const line = lines.find((l, i) => lineKey(l, i) === matchPickKey);
        if (!line || !rows[matchRowIndex]) return;

        const targetRow = rows[matchRowIndex];
        applyCostLineToRow(targetRow, line);
        targetRow.matched_line_key = matchPickKey;
        targetRow.selected = true;

        const applyAllSame = $('pcwMatchApplyAllSame')?.checked ?? true;
        if (applyAllSame) {
            const matches = findSameValueRows(targetRow, matchRowIndex);
            if (matches.checkedMatches.length > 0) {
                matches.checkedMatches.forEach((m) => {
                    applyCostLineToRow(m.row, line);
                    m.row.matched_line_key = matchPickKey;
                });
            } else if (matches.exactMatMatches.length > 0) {
                matches.exactMatMatches.forEach((m) => {
                    applyCostLineToRow(m.row, line);
                    m.row.matched_line_key = matchPickKey;
                });
            } else if (matches.groupMatches.length > 0) {
                matches.groupMatches.forEach((m) => {
                    applyCostLineToRow(m.row, line);
                    m.row.matched_line_key = matchPickKey;
                });
            }
        }

        closeMatchPicker();
        fillKpis(summarizeRows(rows));
        renderTable();
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
            if (!key || key === 'check' || key === 'customer' || key === 'end_user') return;
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

        const controlBar = $('pcwControlBar');
        controlBar?.addEventListener('submit', (e) => {
            e.preventDefault();
            selectedCustomer = '';
            selectedEndUser = '';
            currentMode = 'validated';
            void runValidate({ mode: 'validated', forceValidated: true });
        });
        $('pcwResetBtn')?.addEventListener('click', () => {
            const poInput = $('po');
            if (poInput) poInput.value = '';
            const trims = controlBar?.querySelector('input[name="mat_cat"][value="trims"]');
            if (trims) trims.checked = true;
            rows = [];
            costLines = [];
            fillKpis({});
            renderTable();
            setPassStatus({});
            const page = cfg.pageUrl || 'purchase-order';
            const next = new URL(page, window.location.origin);
            next.searchParams.set('view', 'costing');
            next.searchParams.set('mat_cat', 'trims');
            window.history.replaceState({}, '', next.pathname + next.search);
            document.title = 'EVOLV | PO Costing';
        });
        $('pcwPassPoBtn')?.addEventListener('click', () => {
            const po = headerPo();
            if (!po) return;
            const type = selectedMat() === 'fabric' ? 'FABRIC' : 'TRIMS';
            const url = `../index.php?type=${encodeURIComponent(type)}&po=${encodeURIComponent(po)}`;
            window.open(url, '_blank', 'noopener');
        });
        controlBar?.querySelectorAll('input[name="mat_cat"]').forEach((el) => {
            el.addEventListener('change', () => {
                if (!headerPo()) return;
                selectedCustomer = '';
                selectedEndUser = '';
                currentMode = 'validated';
                void runValidate({ mode: 'validated', forceValidated: true });
            });
        });

        $('pcwCheckAll')?.addEventListener('change', (e) => {
            const on = !!e.target.checked;
            rows.forEach((r) => {
                if (needsManualMatch(r)) r.selected = on;
                else if (!on) r.selected = false;
            });
            renderTable();
        });
        $('pcwMatchSelectedBtn')?.addEventListener('click', () => {
            const idx = rows.findIndex((r) => r.selected && needsManualMatch(r));
            if (idx < 0) return;
            openMatchPicker(idx);
        });
        $('pcwMatchClose')?.addEventListener('click', closeMatchPicker);
        $('pcwMatchCancel')?.addEventListener('click', closeMatchPicker);
        $('pcwMatchApply')?.addEventListener('click', onMatchApply);
        $('pcwMatchSearch')?.addEventListener('input', () => {
            matchPickKey = '';
            $('pcwMatchApply') && ($('pcwMatchApply').disabled = true);
            renderMatchList();
        });
        $('pcwMatchList')?.addEventListener('change', (e) => {
            const radio = e.target.closest('input[name="pcwMatchPick"]');
            if (!radio) return;
            matchPickKey = String(radio.value || '');
            $('pcwMatchApply') && ($('pcwMatchApply').disabled = !matchPickKey);
            $('pcwMatchList')?.querySelectorAll('.pcw-match-option').forEach((el) => {
                el.classList.toggle('is-active', el.getAttribute('data-key') === matchPickKey);
            });
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && $('pcwMatchPop') && !$('pcwMatchPop').hidden) {
                closeMatchPicker();
            }
        });

        tbody?.addEventListener('change', onRowSelectChange);
        tbody?.addEventListener('click', (e) => {
            const matchBtn = e.target.closest('button.pcw-match-btn');
            if (matchBtn) {
                e.preventDefault();
                e.stopPropagation();
                const idx = Number(matchBtn.getAttribute('data-idx'));
                if (Number.isFinite(idx)) openMatchPicker(idx);
                return;
            }
            const changeBtn = e.target.closest('button.pcw-match-change-btn');
            if (changeBtn) {
                e.preventDefault();
                e.stopPropagation();
                const idx = Number(changeBtn.getAttribute('data-idx'));
                if (Number.isFinite(idx) && rows[idx]) {
                    rows[idx]._editingMatch = true;
                    renderTable();
                }
                return;
            }
            const btn = e.target.closest('button.pcw-formula-cell');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            const key = btn.getAttribute('data-explain');
            const idx = Number(btn.getAttribute('data-idx'));
            const row = rows[idx];
            if (!key || !row) return;
            if (btn.classList.contains('is-active') && !$('pcwFormulaPop')?.hidden) {
                closeFormulaPop();
                return;
            }
            openFormulaPop(key, row, btn);
        });

        $('pcwSameValApply')?.addEventListener('click', () => {
            if (!pendingSameValAction) return;
            const choice = $('pcwSameValOptions')?.querySelector('input[name="pcwSvmChoice"]:checked')?.value || 'mat';
            const { line, key, matches } = pendingSameValAction;

            let targets = [];
            if (choice === 'mat') targets = matches.exactMatMatches;
            else if (choice === 'group') targets = matches.groupMatches;
            else if (choice === 'checked') targets = matches.checkedMatches;
            else targets = matches.allMatches;

            targets.forEach(({ row }) => {
                applyCostLineToRow(row, line);
                row.matched_line_key = key;
            });

            closeSameValModal();
            fillKpis(summarizeRows(rows));
            renderTable();
        });

        $('pcwSameValCancel')?.addEventListener('click', () => {
            closeSameValModal();
            fillKpis(summarizeRows(rows));
            renderTable();
        });

        $('pcwSameValBackdrop')?.addEventListener('click', () => {
            closeSameValModal();
            fillKpis(summarizeRows(rows));
            renderTable();
        });

        $('pcwSameValOptions')?.addEventListener('change', (e) => {
            const rad = e.target.closest('input[name="pcwSvmChoice"]');
            if (!rad) return;
            $('pcwSameValOptions').querySelectorAll('.pcw-svm-option-label').forEach((lbl) => {
                lbl.classList.toggle('is-active', lbl.contains(rad));
            });
        });
        $('pcwFormulaClose')?.addEventListener('click', closeFormulaPop);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeFormulaPop();
        });
        document.addEventListener('click', (e) => {
            const pop = $('pcwFormulaPop');
            if (!pop || pop.hidden) return;
            if (pop.contains(e.target) || e.target.closest('button.pcw-formula-cell')) return;
            closeFormulaPop();
        });
        window.addEventListener('scroll', () => {
            if (formulaAnchor && !$('pcwFormulaPop')?.hidden) positionFormulaPop(formulaAnchor);
        }, true);
        window.addEventListener('resize', () => {
            if (formulaAnchor && !$('pcwFormulaPop')?.hidden) positionFormulaPop(formulaAnchor);
        });

        $('pcwStatusKpis')?.addEventListener('click', (e) => {
            const card = e.target.closest('[data-card-key]');
            if (!card) return;
            e.preventDefault();
            const key = card.getAttribute('data-card-key');
            if (key) openCardDetailModal(key);
        });
        $('pcwKpis')?.addEventListener('click', (e) => {
            const card = e.target.closest('[data-card-key]');
            if (!card) return;
            e.preventDefault();
            const key = card.getAttribute('data-card-key');
            if (key) openCardDetailModal(key);
        });
        $('pcwCardModalClose')?.addEventListener('click', closeCardDetailModal);
        $('pcwCardModalDone')?.addEventListener('click', closeCardDetailModal);
        $('pcwCardDetailBackdrop')?.addEventListener('click', closeCardDetailModal);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && $('pcwCardDetailModal') && !$('pcwCardDetailModal').hidden) {
                closeCardDetailModal();
            }
        });

        if (typeof window.rptSetupColumnFeatures === 'function' && table) {
            colFeatures = window.rptSetupColumnFeatures(table, {
                onChange: () => {
                    renderTable();
                    syncGroupHeaders();
                },
            });
        }
        syncGroupHeaders();
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
