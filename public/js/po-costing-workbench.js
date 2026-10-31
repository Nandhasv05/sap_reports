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
        'conv', 'buom', 'actual_cost', 'cost_matrix', 'variance_pct', 'validation_result',
    ]);
    const EXPLAIN_COLS = new Set([
        'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'ouom', 'porate', 'totvalue',
        'po_currency', 'budgeted_cons', 'budget_inr', 'budget_currency',
        'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'buom', 'actual_cost',
        'cost_matrix', 'variance_pct',
    ]);
    const colGroup = (key) => (ZCOST_COLS.has(key) ? 'zcost' : 'po');

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
    let formulaAnchor = null;

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
        let totalExpected = 0;
        let totalActual = 0;
        let unit = '';
        let currency = 'INR';
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
            if (item && !seenValue[item]) {
                seenValue[item] = true;
                totalValue += Number(r.totvalue ?? r.net_value ?? 0) || 0;
                totalOrderQty += Number(r.tot_poqty ?? r.order_qty ?? r.po_qty ?? 0) || 0;
            } else if (!item) {
                totalValue += Number(r.totvalue ?? r.net_value ?? 0) || 0;
                totalOrderQty += Number(r.tot_poqty ?? r.order_qty ?? r.po_qty ?? 0) || 0;
            }
            const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            const actual = nVal(r.actual_cost ?? r.po_unit_cost);
            const soQty = Number(r.so_qty ?? r.prwise_so_qty ?? 0) || 0;
            const costQty = soQty > 0 ? soQty : 1;
            if (budget !== null) totalExpected += budget * costQty;
            if (actual !== null) totalActual += actual * costQty;
            if (!unit) unit = String(r.ouom || r.unit || '').trim();
            if (r.po_currency || r.currency) currency = String(r.po_currency || r.currency);
        });
        return {
            po_items: Object.keys(items).length || (list || []).length,
            materials: Object.keys(materials).length,
            sales_orders: Object.keys(salesOrders).length,
            total_order_qty: totalOrderQty,
            total_po_value: totalValue,
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
        if ($('pcwKpiItems')) $('pcwKpiItems').textContent = fmt(s.po_items || s.unique_po_items || 0);
        if ($('pcwKpiMaterials')) $('pcwKpiMaterials').textContent = fmt(s.materials || 0);
        if ($('pcwKpiOrderQty')) $('pcwKpiOrderQty').textContent = fmt(s.total_order_qty || 0);
        if ($('pcwKpiOrderQtyUnit')) $('pcwKpiOrderQtyUnit').textContent = s.unit || '';
        if ($('pcwKpiValue')) $('pcwKpiValue').textContent = fmt(s.total_po_value || 0);
        if ($('pcwKpiValueUnit')) $('pcwKpiValueUnit').textContent = s.currency || 'INR';
        if ($('pcwKpiSalesOrders')) $('pcwKpiSalesOrders').textContent = fmt(s.sales_orders || 0);
        if ($('pcwKpiPassed')) $('pcwKpiPassed').textContent = fmt(s.passed || 0);
        if ($('pcwKpiExceptions')) $('pcwKpiExceptions').textContent = fmt(s.exceptions || 0);
        if ($('pcwKpiReview')) $('pcwKpiReview').textContent = fmt(s.needs_review || 0);
        if ($('pcwKpiExpected')) $('pcwKpiExpected').textContent = fmt(s.total_expected || 0);
        if ($('pcwKpiExpectedUnit')) $('pcwKpiExpectedUnit').textContent = s.currency || 'INR';
        if ($('pcwKpiActual')) $('pcwKpiActual').textContent = fmt(s.total_actual || 0);
        if ($('pcwKpiActualUnit')) $('pcwKpiActualUnit').textContent = s.currency || 'INR';
        setPassStatus(s);
        const split = $('pcwKpis');
        if (split) split.hidden = false;
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
        classes.push(`pcw-col-${group}`);
        if (HIGHLIGHT_COLS.has(key)) classes.push('pcw-hl-cost');
        if (EXPLAIN_COLS.has(key)) classes.push('is-explainable');
        const cls = classes.length ? ` class="${classes.join(' ')}"` : '';
        let inner = '—';
        switch (key) {
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
            case 'cost_item':
                inner = r.cost_item || r.item
                    ? `<span class="pcw-nav-chip">${esc(r.cost_item || r.item)}</span>`
                    : '—';
                break;
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
                return `<tr class="pcw-row${open}${pass}${fail}" data-idx="${index}">${COLS.map((k) => renderCell(k, row, index)).join('')}</tr>`;
            })
            .join('');
        if (noMatch) tbody.appendChild(noMatch);
        colFeatures?.refresh?.();
        syncGroupHeaders();
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

        tbody?.addEventListener('change', onRowSelectChange);
        tbody?.addEventListener('click', (e) => {
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
