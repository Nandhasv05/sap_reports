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
    // Actions always last; Status stays first (status-only, no Map Costing button)
    {
        const ai = COLS.indexOf('actions');
        if (ai >= 0) COLS.splice(ai, 1);
        if (!COLS.includes('actions')) { /* ensure present */ }
        COLS.push('actions');
    }
    const NUM_COLS = new Set([
        'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
        'budget_conv_rt', 'budget_cost_per_gmt', 'budget_cost_per_piece', 'conv', 'actual_cost', 'variance_pct',
    ]);
    // Status tint on budget/actual result columns
    const HIGHLIGHT_COLS = new Set(['budget_cost_per_gmt', 'budget_cost_per_piece', 'actual_cost']);
    /** Costing Data group — quotation / zCOST line identity + supporting budget fields */
    const ZCOST_COLS = new Set([
        'costing_quotation_no', 'costing_item_no', 'zsection', 'maktx',
        'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'conv',
    ]);
    /** Result group — budget vs actual comparison + actions */
    const RESULT_COLS = new Set([
        'budget_cost_per_gmt', 'budget_cost_per_piece', 'buom', 'actual_cost', 'variance_pct', 'actions',
    ]);
    /** Blank these when unmatched (costing + result, except actions) */
    const COSTING_BLANK_COLS = new Set([
        ...ZCOST_COLS,
        'budget_cost_per_gmt', 'budget_cost_per_piece', 'buom', 'actual_cost', 'variance_pct',
    ]);
    // Click any cell: show TEMPFIN / SAP API source column + original value
    // (costing_quotation_no excluded — that opens sap_reports/zcost)
    const EXPLAIN_COLS = new Set([
        'pono', 'poitem', 'prno', 'salordno', 'style', 'material', 'matgroup',
        'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'ouom', 'porate', 'totvalue', 'mat_desc',
        'po_currency', 'costing_item_no', 'zsection', 'maktx',
        'budgeted_cons', 'budget_inr', 'budget_currency',
        'budget_conv_rt', 'budget_cost_per_gmt', 'budget_cost_per_piece', 'conv', 'buom', 'actual_cost',
        'variance_pct',
    ]);
    /** UI column key → SAP OData (Z_QuotationZCostCom / ZCOSTI*) field name */
    const API_COL_REF = {
        status: { api: 'derived', source: 'Z_QuotationZCostCom + ZCOST', desc: 'PASS when ActualCost ≤ RATE (budget per GMT).' },
        pono: { api: 'PurchaseOrder', source: 'Z_QuotationZCostCom', desc: 'Purchase order number.' },
        poitem: { api: 'PurchaseOrderItem', source: 'Z_QuotationZCostCom', desc: 'Purchase order item.' },
        prno: { api: 'PurchaseRequisition', source: 'Z_QuotationZCostCom', desc: 'Purchase requisition number.' },
        salordno: { api: 'SalesOrderReference', source: 'Z_QuotationZCostCom', desc: 'Sales order number.' },
        style: { api: 'style', source: 'Z_QuotationZCostCom', desc: 'Style code.' },
        material: { api: 'Material', source: 'Z_QuotationZCostCom', desc: 'Material number.' },
        matgroup: { api: 'MaterialGroup', source: 'Z_QuotationZCostCom', desc: 'Material group.' },
        so_qty: { api: 'SaleOrderQuantity', source: 'Z_QuotationZCostCom', desc: 'Sales order quantity.' },
        prwise_so_qty: { api: 'SaleOrderQuantity', source: 'Z_QuotationZCostCom', desc: 'Sales order quantity (PR-wise).' },
        consumption: { api: 'ReqBOMQuantity / SaleOrderQuantity', source: 'Z_QuotationZCostCom', desc: 'Consumption per garment (derived).' },
        reqqty: { api: 'ReqBOMQuantity', source: 'Z_QuotationZCostCom', desc: 'Required BOM quantity.' },
        tot_poqty: { api: 'OrderQuantity', source: 'Z_QuotationZCostCom', desc: 'PO order quantity.' },
        so_wise_poqty: { api: 'OrderQuantity', source: 'Z_QuotationZCostCom', desc: 'SO-wise portion of OrderQuantity.' },
        so_wise_po_val: { api: 'NetPriceAmount', source: 'Z_QuotationZCostCom', desc: 'SO-wise portion of NetPriceAmount.' },
        ouom: { api: 'PurchaseOrderQuantityUnit', source: 'Z_QuotationZCostCom', desc: 'PO quantity unit.' },
        porate: { api: 'NetPriceAmount / OrderQuantity', source: 'Z_QuotationZCostCom', desc: 'Unit rate (derived from net amount ÷ qty).' },
        totvalue: { api: 'NetPriceAmount', source: 'Z_QuotationZCostCom', desc: 'PO line net price amount.' },
        mat_desc: { api: 'MaterialDesc', source: 'Z_QuotationZCostCom', desc: 'Material description.' },
        po_currency: { api: 'DocumentCurrency', source: 'Z_QuotationZCostCom', desc: 'Document currency.' },
        costing_quotation_no: { api: 'CostingQuotationNo', source: 'Z_QuotationZCostCom', desc: 'zCOST quotation number.' },
        costing_item_no: { api: 'ITEM', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'zCOST costing line ITEM code.' },
        zsection: { api: 'ZSECTION', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Costing section (e.g. T.FABRIC).' },
        maktx: { api: 'MAKTX', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Costing line material description.' },
        budgeted_cons: { api: 'GROSS', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budgeted consumption (gross).' },
        budget_inr: { api: 'TOTALINR', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budget total in INR.' },
        budget_currency: { api: 'CURUNIT', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Costing currency unit.' },
        budget_conv_rt: { api: 'CONVR', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budget conversion rate.' },
        budget_cost_per_gmt: { api: 'TOTALINR / GROSS', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budget cost per piece = Total in INR ÷ Gross.' },
        budget_cost_per_piece: { api: 'TOTALINR', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budget cost per garment — zCOST Total in INR (TOTALINR).' },
        conv: { api: 'CONV (unit)', source: 'TEMPFIN / PASSPO', desc: 'Unit conversion factor (not Curr Rate).' },
        buom: { api: 'PurchaseOrderQuantityUnit', source: 'Z_QuotationZCostCom', desc: 'Base / order unit of measure.' },
        actual_cost: { api: 'PORATE × Curr Rate', source: 'TEMPFIN / PASSPO + Curr/Rate', desc: 'ACTUAL_COST in INR = PORATE × Curr Rate (BUDGET_CONV_RT) × PO consumption, with classic unit rules. Never × zCOST Gross.' },
        variance_pct: { api: 'derived', source: 'Computed', desc: '(Actual INR − TotalINR/Gross) ÷ Budget × 100.' },
    };
    const colGroup = (key) => {
        if (RESULT_COLS.has(key)) return 'result';
        if (ZCOST_COLS.has(key)) return 'zcost';
        return 'po';
    };

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

    function openZcostView(quote, itemNo) {
        const href = zcostViewUrl(quote, itemNo);
        if (!href) return;
        window.open(href, '_blank', 'noopener,noreferrer');
    }

    function quotationLinkHtml(quote, itemNo) {
        const q = String(quote ?? '').trim();
        if (!q) return '—';
        const href = zcostViewUrl(q, itemNo);
        return `<a class="pcw-quote-link" href="${esc(href)}" data-quote="${esc(q)}" target="_blank" rel="noopener noreferrer" title="Open zCOST Sales Costing Sheet">${esc(q)}</a>`;
    }

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
    let tableStatusFilter = ''; // PASS | NOT_MATCHED | NOT_VALIDATED | NEEDS_REVIEW
    let colFeatures = null;
    let filterVisible = false;
    let formulaAnchor = null;
    let matchRowIndex = null;
    let matchPickKey = '';
    let assignedUser = { id: '', username: '' };
    let userSearchTimer = null;

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

    /**
     * ACTUAL_COST in INR using Curr + Rate (po_currency / budget_currency + budget_conv_rt).
     * Mirrors classic portal: PORATE × FX, button GRO ÷ 144, THREADS CPS/BOX scales.
     */
    /** zCOST budget per GMT = Total in INR ÷ Gross (fallback RATE). */
    function lineBudgetPerGmt(line) {
        if (!line) return null;
        const tot = nVal(line.total_inr);
        const g = nVal(line.gross);
        if (tot !== null && tot > 0 && g !== null && g > 0) {
            return Math.round((tot / g) * 10000) / 10000;
        }
        return nVal(line.budget_per_gmt) ?? nVal(line.rate) ?? nVal(line.purp) ?? nVal(line.net_price);
    }

    /**
     * BUDGET_COST_PER_PIECE column = zCOST Total in INR (TOTALINR).
     * Example: SIZE LABEL VVVVSWH096 → Total INR 2.5376 (BUDGET_COST_PER_GMT stays Total÷Gross).
     */
    function budgetCostPerPiece(r, line) {
        if (line) {
            const tot = nVal(line.total_inr) ?? nVal(line.budget_inr) ?? nVal(line.cost_total_inr);
            if (tot !== null && tot > 0) return Math.round(tot * 10000) / 10000;
        }
        if (!r) return null;
        const stored = nVal(r.budget_cost_per_piece);
        if (stored !== null && stored > 0) return stored;
        const tot = nVal(r.total_inr) ?? nVal(r.cost_total_inr) ?? nVal(r.budget_inr);
        if (tot !== null && tot > 0) return Math.round(tot * 10000) / 10000;
        return null;
    }

    function actualCostInr(r) {
        if (!r) return null;
        const porate = nVal(r.porate ?? r.net_price);
        if (porate === null) {
            return nVal(r.actual_cost ?? r.po_unit_cost);
        }
        const curr = String(r.po_currency || r.currency || 'INR').toUpperCase();
        const fx = nVal(r.budget_conv_rt) ?? nVal(r.convr);
        const rate = (fx !== null && fx > 0) ? fx : 1;
        let unitConv = nVal(r.conv);
        if (unitConv === null || unitConv <= 0) unitConv = 1;
        // PO CONSUMPTION only — never zCOST GROSS/NET (that inflated 0.15×83×1.68 → 21.924)
        let cons = nVal(r.consumption);
        if (cons === null || cons <= 0) cons = 1;
        const gross = nVal(r.gross) ?? nVal(r.budgeted_cons);
        if (gross !== null && gross > 1.05 && Math.abs(cons - gross) < 0.0001) {
            cons = 1; // consumption was wrongly filled from zCOST GROSS
        }
        const mg = String(r.matgroup || r.material_group || '').toUpperCase();
        const ouom = String(r.ouom || '').toUpperCase();
        const buom = String(r.buom || '').toUpperCase();
        const matDesc = String(r.mat_desc || r.material_desc || '').toUpperCase();
        const foreign = curr !== '' && curr !== 'INR';
        // CONV sometimes mirrors BUDGET_CONV_RT — don't apply FX twice / as unit factor
        if (foreign && Math.abs(unitConv - rate) < 0.0001) unitConv = 1;
        if (!foreign && unitConv > 20) unitConv = 1;

        if (mg === 'THREADS1' && buom === 'CPS') {
            return Math.round(((porate / 1000) * unitConv) * 10000) / 10000;
        }
        if (mg === 'THREADS1' && buom === 'BOX') {
            return Math.round(((porate / 10000) * unitConv) * 10000) / 10000;
        }

        const base = foreign ? (porate * rate) : porate;
        const mid = base * unitConv;
        const isButtonGro = (ouom === 'GRO' || buom === 'GRO') && (
            mg.includes('BUTTON') || mg.includes('SEMI DULL') || mg.includes('HOOK')
            || mg.includes('PEARL BTN') || matDesc.includes('BTN') || matDesc.includes('BUTTON')
            || matDesc.includes('PLASTIC')
        );
        if (isButtonGro) {
            return Math.round((Math.round(mid * cons) / 144) * 10000) / 10000;
        }
        if (ouom === 'KG' || buom === 'KG') {
            return Math.round(((mid * cons) / 500) * 10000) / 10000;
        }
        return Math.round((mid * cons) * 10000) / 10000;
    }

    function totalReqForItem(r) {
        const pono = String(r.pono || r.purchase_order || '');
        const item = String(r.poitem || r.po_item || '');
        let total = 0;
        rows.forEach((row) => {
            if (String(row.pono || row.purchase_order || '') !== pono) return;
            if (String(row.poitem || row.po_item || '') !== item) return;
            const q = nVal(row.reqqty) ?? nVal(row.ReqBOMQuantity);
            if (q !== null) total += q;
        });
        if (total === 0) {
            total = nVal(r.reqqty) || nVal(r.tot_poqty) || nVal(r.order_qty) || 1;
        }
        return total;
    }

    function formatExplainValue(key, r) {
        const raw = cellValue(key, r, 0);
        if (raw === null || raw === undefined || raw === '') return '-';
        if (key === 'status') return statusDisplay(r).label;
        if (key === 'variance_pct') {
            const n = Number(raw);
            return Number.isFinite(n) ? `${n.toFixed(2)}%` : String(raw);
        }
        if (NUM_COLS.has(key)) {
            const dig = ['porate', 'consumption', 'budgeted_cons', 'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost'].includes(key) ? 4 : 2;
            return fmtStep(raw, dig);
        }
        return String(raw);
    }

    function statusDisplay(r) {
        const st = String(r?.status || '').toUpperCase().replace(/\s+/g, '_');
        if (st === 'FOOTER' || isFooterRow(r)) {
            return { code: 'FOOTER', label: '—', cls: 'is-footer' };
        }
        const hasLink = !!(r.manual_match || r.matched_line_key || r.cost_item || r.maktx || r.costing_item_no);
        // Unmapped / still needs Map Costing
        if (!hasLink && (
            isUnmatchedZcost(r)
            || st === 'NOT_MATCHED'
            || needsManualMatch(r)
            || /no matching|no linked|cannot load|not matched|does not match|mapping required/i.test(String(r?.validation_result || ''))
        )) {
            return { code: 'NOT_MATCHED', label: 'Mapping Required', cls: 'is-mapping' };
        }
        const budget = nVal(r?.budget_cost_per_gmt ?? r?.expected_unit_cost);
        const actual = actualCostInr(r);
        const overBudget = budget !== null && budget > 0 && actual !== null && actual > budget + 0.005;
        // Cost Mismatch before Need to Review when Actual > Budget
        if (overBudget || st === 'EXCEPTION' || st === 'FAIL' || st === 'NOT_VALIDATED' || !!r.cost_mismatch) {
            return { code: 'NOT_VALIDATED', label: 'Cost Mismatch', cls: 'is-mismatch' };
        }
        if (st === 'NEEDS_REVIEW' || st === 'AMBIGUOUS' || st === 'AMBIGUOUS_MATCH') {
            return { code: 'NEEDS_REVIEW', label: 'Need to Review', cls: 'is-ambiguous' };
        }
        if (budget !== null && budget > 0 && actual !== null && actual <= budget + 0.005) {
            return { code: 'PASS', label: 'Passed', cls: 'is-pass' };
        }
        if (st === 'PASS') return { code: 'PASS', label: 'Passed', cls: 'is-pass' };
        // Linked but uncertain → Need to Review; else Mapping Required
        if (hasLink) {
            return { code: 'NEEDS_REVIEW', label: 'Need to Review', cls: 'is-ambiguous' };
        }
        return { code: 'NOT_MATCHED', label: 'Mapping Required', cls: 'is-mapping' };
    }

    /** True when row still needs Map Costing — costing cells stay blank. */
    function isUnmatchedZcost(r) {
        if (!r || isFooterRow(r)) return false;
        if (r.manual_match && (r.matched_line_key || r.cost_item || r.maktx)) return false;
        if (r.family_mismatch || r.needs_manual_match || r._editingMatch || r._zcost_pending) return true;
        const hasCost = !!(r.cost_item || r.zcost_item || r.costing_item_no || r.maktx || r.matched_line_key);
        if (!hasCost) return true;
        return needsManualMatch(r) && !r.matched_line_key;
    }

    function statusReasonExplain(r) {
        const disp = statusDisplay(r);
        const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const actual = actualCostInr(r);
        const variance = nVal(r.variance_pct);
        const quote = String(r.costing_quotation_no || r.matched_quotation || '').trim();
        const costItem = String(r.costing_item_no || r.cost_item || r.item || '').trim();
        const matched = !needsManualMatch(r) && !!quote;
        const savedMsg = String(r.validation_result || '').trim();

        let why = '';
        if (disp.code === 'PASS') {
            why = matched
                ? 'Actual unit cost is within (≤) budget RATE — line passes validation.'
                : 'Line marked PASS.';
        } else if (disp.code === 'NEEDS_REVIEW') {
            why = savedMsg || 'Budget rate missing, zero, or match is uncertain — needs review.';
        } else if (disp.code === 'NOT_MATCHED') {
            why = savedMsg || 'No linked zCOST line / quotation item for this PO material — match required.';
        } else {
            why = savedMsg || (budget !== null && actual !== null && actual > budget
                ? 'Actual unit cost exceeds budget RATE — not validated.'
                : 'Line failed cost validation.');
        }

        const steps = [
            { label: 'Status', value: disp.label },
            { label: 'Why', value: why },
            { label: 'Match module', value: matched ? `Matched · Quote ${quote}${costItem ? ' / Item ' + costItem : ''}` : 'Not matched to zCOST' },
            { label: 'Budget module (RATE)', value: budget === null ? '—' : fmtStep(budget, 4) },
            { label: 'Actual module (PO rate)', value: actual === null ? '—' : fmtStep(actual, 4) },
            {
                label: 'Rule',
                value: 'PASS when Actual ≤ Budget RATE · NOT VALIDATED when Actual > RATE · NOT MATCHED when no zCOST link · NEEDS REVIEW when budget missing/uncertain',
            },
        ];
        if (variance !== null) {
            steps.push({ label: 'Variance %', value: `${variance > 0 ? '+' : ''}${fmtStep(variance, 2)}%` });
        }
        if (savedMsg) {
            steps.push({ label: 'System message', value: savedMsg });
        }

        return {
            title: `Status · ${disp.label}`,
            desc: why,
            formula: 'ActualCost ≤ RATE → PASS',
            formulaLabel: 'STATUS RULE',
            stepsLabel: 'MODULE REASON',
            example: `PO item ${r.poitem || '—'} · Material ${r.material || '—'}`,
            steps,
            resultLabel: disp.label,
            resultExpr: why,
        };
    }

    function explainFor(key, r) {
        if (key === 'status') {
            return statusReasonExplain(r);
        }
        const meta = API_COL_REF[key];
        if (!meta) return null;
        const original = formatExplainValue(key, r);
        const steps = [
            { label: 'SAP API column', value: meta.api },
            { label: 'Entity', value: meta.source },
            { label: 'Original value', value: original },
        ];
        // Cost modules: also show why the row is PASS / NOT VALIDATED / NOT MATCHED / REVIEW
        if (key === 'budget_cost_per_gmt' || key === 'actual_cost' || key === 'variance_pct') {
            const reason = statusReasonExplain(r);
            steps.push({ label: 'Row status', value: reason.resultLabel });
            steps.push({ label: 'Why this status', value: reason.desc });
            const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            const actual = nVal(r.actual_cost ?? r.po_unit_cost);
            if (key === 'budget_cost_per_gmt') {
                const totInr = nVal(r.budget_inr ?? r.cost_total_inr ?? r.total_inr);
                const gross = nVal(r.budgeted_cons ?? r.gross);
                steps.push({
                    label: 'Formula',
                    value: (totInr !== null && gross !== null && gross > 0)
                        ? `Total in INR ${fmtStep(totInr, 4)} ÷ Gross ${fmtStep(gross, 4)} = ${fmtStep(totInr / gross, 4)}`
                        : 'TOTALINR / GROSS (fallback RATE when Gross is blank)',
                });
                steps.push({
                    label: 'Module check',
                    value: budget === null || budget <= 0
                        ? 'No budget (Total INR / Gross) → needs review / not matched'
                        : `Budget ${fmtStep(budget, 4)} compared with actual ${actual === null ? '—' : fmtStep(actual, 4)}`,
                });
            }
            if (key === 'actual_cost') {
                const porate = nVal(r.porate ?? r.net_price);
                const fx = nVal(r.budget_conv_rt) ?? nVal(r.convr) ?? 1;
                const cons = nVal(r.consumption) ?? 1;
                steps.push({
                    label: 'Formula',
                    value: porate === null
                        ? 'PORATE × Curr Rate × PO consumption (classic unit rules)'
                        : `PORATE ${fmtStep(porate, 4)} × Rate ${fmtStep(fx, 4)} × Cons ${fmtStep(cons, 4)} → INR ${fmtStep(actual, 4)}`,
                });
                steps.push({
                    label: 'Module check',
                    value: actual === null
                        ? 'No actual cost on PO line'
                        : (budget !== null && actual > budget
                            ? `Actual ${fmtStep(actual, 4)} > Budget ${fmtStep(budget, 4)} → NOT VALIDATED`
                            : `Actual ${fmtStep(actual, 4)} ≤ Budget ${budget === null ? '—' : fmtStep(budget, 4)} → PASS`),
                });
            }
        }
        return {
            title: meta.api,
            desc: meta.desc || '',
            formula: meta.api,
            example: `Source: ${meta.source}`,
            steps,
            resultLabel: meta.api,
            resultExpr: `= ${original}`,
        };
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
        const exprLabel = exprBox?.querySelector('.pcw-formula-formula-label');
        if (info.formula) {
            exprBox.hidden = false;
            if (exprLabel) {
                exprLabel.innerHTML = `<span class="pcw-formula-sigma" aria-hidden="true">Σ</span> ${esc(info.formulaLabel || 'SAP API COLUMN')}`;
            }
            $('pcwFormulaExprText').textContent = info.formula;
        } else {
            exprBox.hidden = true;
        }

        const stepsBox = $('pcwFormulaSteps');
        const stepsLabel = stepsBox?.querySelector('.pcw-formula-steps-label');
        if (stepsLabel) stepsLabel.textContent = info.stepsLabel || 'VALUE DETAIL';
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
        const shell = $('pcwCardDetailModal');
        if (!shell) return;
        shell.classList.remove('is-open');
        shell.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pcw-card-offcanvas-open');
        window.setTimeout(() => {
            if (!shell.classList.contains('is-open')) shell.hidden = true;
        }, 280);
    }

    function statusFilterLabel(code) {
        const map = {
            PASS: 'Passed',
            NOT_MATCHED: 'Mapping Required',
            NOT_VALIDATED: 'Cost Mismatch',
            NEEDS_REVIEW: 'Need to Review',
        };
        return map[String(code || '').toUpperCase()] || String(code || '');
    }

    function filterTableByStatus(status) {
        // Filter by statusDisplay code (NOT raw API status / NOT_VALIDATED text search)
        tableStatusFilter = String(status || '').toUpperCase();
        const searchInput = $('rptTableSearch');
        if (searchInput) searchInput.value = '';
        document.querySelectorAll('#rptFilterRow .rpt-col-input').forEach((i) => { i.value = ''; });
        $('rptTableSearchClear') && ($('rptTableSearchClear').style.display = 'none');
        renderTable();
        // Scroll table into view so filtered result is obvious
        $('rptTable')?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    }

    function detailSourceRows() {
        return rows.filter((r) => !isFooterRow(r));
    }

    function openCardDetailModal(cardKey) {
        const detailRows = detailSourceRows();
        const s = summarizeRows(detailRows);
        const shell = $('pcwCardDetailModal');
        const panel = $('pcwCardOffcanvasPanel');
        if (!shell || !panel) return;

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
        let rowCount = 0;

        const curr = s.currency || 'INR';
        const uom = s.unit || '';
        const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
        const fmtDec = (n, d = 2) => Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d });

        const rowLineHtml = (r, i) => `<tr>
            <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
            <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
            <td class="num">${qty(r.tot_poqty || r.order_qty || r.po_qty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
            <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
            <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || r.order_qty || 1)))}</td>
            <td>${statusPill(r.status, r)}</td>
        </tr>`;

        if (cardKey === 'status_total') {
            icon = 'description';
            subtitle = 'Validation Overview';
            title = 'Total Items';
            val = fmt(s.total_records || detailRows.length);
            formula = 'Total Items = Passed + Mapping Required + Cost Mismatch + Need to Review';
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Passed</span><strong>${fmt(s.passed)}</strong></div>
                <div class="pcw-cdm-step-item"><span>Mapping Required</span><strong>${fmt(s.mapping_required)}</strong></div>
                <div class="pcw-cdm-step-item"><span>Cost Mismatch</span><strong>${fmt(s.cost_mismatch)}</strong></div>
                <div class="pcw-cdm-step-item"><span>Need to Review</span><strong>${fmt(s.ambiguous_match)}</strong></div>
                <div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Items</span><strong>${fmt(s.total_records || detailRows.length)}</strong></div>
            `;
            rowsHtml = detailRows.map(rowLineHtml).join('');
            rowCount = detailRows.length;
        } else if (cardKey === 'status_pass') {
            icon = 'check_circle';
            subtitle = 'Validation Overview';
            title = 'Passed';
            val = fmt(s.passed);
            formula = 'Passed = Actual Cost ≤ Budget Cost per GMT (green)';
            showFilterBtn = true;
            filterStatus = 'PASS';
            const passRows = detailRows.filter((r) => statusDisplay(r).code === 'PASS');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>Actual ≤ Budget (green cells)</strong></div>
                <div class="pcw-cdm-step-item"><span>Passed</span><strong>${fmt(s.passed)} of ${fmt(detailRows.length)}</strong></div>
            `;
            rowsHtml = passRows.map(rowLineHtml).join('');
            rowCount = passRows.length;
        } else if (cardKey === 'status_mapping' || cardKey === 'status_fail') {
            icon = 'warning';
            subtitle = 'Validation Overview';
            title = 'Mapping Required';
            val = fmt(s.mapping_required ?? s.exceptions);
            formula = 'Mapping Required = zCOST not matched — select dropdown (yellow/red until mapped)';
            showFilterBtn = true;
            filterStatus = 'NOT_MATCHED';
            const mapRows = detailRows.filter((r) => statusDisplay(r).code === 'NOT_MATCHED');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>PO matgroup/desc ≠ zCOST — manual select</strong></div>
                <div class="pcw-cdm-step-item"><span>Mapping Required</span><strong>${fmt(mapRows.length)} of ${fmt(detailRows.length)}</strong></div>
            `;
            rowsHtml = mapRows.map(rowLineHtml).join('');
            rowCount = mapRows.length;
        } else if (cardKey === 'status_mismatch') {
            icon = 'error';
            subtitle = 'Validation Overview';
            title = 'Cost Mismatch';
            val = fmt(s.cost_mismatch);
            formula = 'Cost Mismatch = matched line but Actual &gt; Budget (red)';
            showFilterBtn = true;
            filterStatus = 'NOT_VALIDATED';
            const mmRows = detailRows.filter((r) => statusDisplay(r).code === 'NOT_VALIDATED');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>Actual Cost &gt; Budget Cost per GMT</strong></div>
                <div class="pcw-cdm-step-item"><span>Cost Mismatch</span><strong>${fmt(mmRows.length)} of ${fmt(detailRows.length)}</strong></div>
            `;
            rowsHtml = mmRows.map(rowLineHtml).join('');
            rowCount = mmRows.length;
        } else if (cardKey === 'status_ambiguous' || cardKey === 'status_review') {
            icon = 'layers';
            subtitle = 'Validation Overview';
            title = 'Need to Review';
            val = fmt(s.ambiguous_match ?? s.needs_review);
            formula = 'Need to Review = uncertain mapping / needs review (yellow)';
            showFilterBtn = true;
            filterStatus = 'NEEDS_REVIEW';
            const ambRows = detailRows.filter((r) => statusDisplay(r).code === 'NEEDS_REVIEW');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>Uncertain family / zero budget / review needed</strong></div>
                <div class="pcw-cdm-step-item"><span>Need to Review</span><strong>${fmt(ambRows.length)} of ${fmt(detailRows.length)}</strong></div>
            `;
            rowsHtml = ambRows.map(rowLineHtml).join('');
            rowCount = ambRows.length;
        } else if (cardKey === 'status_po_value') {
            icon = 'payments';
            subtitle = 'Validation Overview';
            title = 'Total PO Value';
            val = `${esc(s.currency || 'INR')} ${fmtDec(s.total_po_value, 2)}`;
            formula = 'Total PO Value = Sum of PORATE × TOT_POQTY (unique PO items)';
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Currency</span><strong>${esc(s.currency || 'INR')}</strong></div>
                <div class="pcw-cdm-step-item"><span>Total PO Value</span><strong>${fmtDec(s.total_po_value, 2)}</strong></div>
            `;
            rowsHtml = detailRows.map(rowLineHtml).join('');
            rowCount = detailRows.length;
        } else if (cardKey === 'po_items') {
            icon = 'format_list_numbered';
            subtitle = 'PO DATA';
            title = 'Total Items';
            val = fmt(s.po_items);
            formula = 'Total Items = Count of unique PO Item numbers (distinct poitem)';
            const seenIt = {};
            const distItems = detailRows.filter(r => {
                const it = String(r.poitem || r.po_item || r.item || '').trim();
                if (it && !seenIt[it]) { seenIt[it] = true; return true; }
                return false;
            });
            stepsHtml = distItems.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item || r.item)} (${esc(r.material)})</span><strong>Qty: ${qty(r.tot_poqty || r.order_qty || 0)} · Value: ₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || r.order_qty || 1)))}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Unique Items</span><strong>${fmt(s.po_items)}</strong></div>`;
            rowsHtml = distItems.map(r => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || r.order_qty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = distItems.length;
        } else if (cardKey === 'po_materials') {
            icon = 'category';
            subtitle = 'PO DATA';
            title = 'Total Material';
            val = fmt(s.materials);
            formula = 'Total Material = Count of distinct Material Codes across PO lines';
            const seenMat = {};
            const distMats = detailRows.filter(r => {
                const m = String(r.material || '').trim();
                if (m && !seenMat[m]) { seenMat[m] = true; return true; }
                return false;
            });
            stepsHtml = distMats.map(r => `<div class="pcw-cdm-step-item"><span>${esc(r.material)}</span><strong>${esc(r.mat_desc || r.maktx || '')}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Unique Materials</span><strong>${fmt(s.materials)}</strong></div>`;
            rowsHtml = distMats.map(r => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || 1)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || r.order_qty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = distMats.length;
        } else if (cardKey === 'po_so_qty') {
            icon = 'inventory_2';
            subtitle = 'PO DATA';
            title = 'Total PO Qty';
            val = fmt(s.total_order_qty || s.total_so_qty || 0);
            unit = uom;
            formula = 'Total PO Qty = ∑ TOT_POQTY (once per PO item)';
            stepsHtml = detailRows.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item || r.item)} · SO ${esc(r.salordno || r.sales_order || '—')}</span><strong>${qty(r.tot_poqty ?? r.order_qty ?? r.so_qty ?? 0)} ${esc(r.ouom || uom)}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total PO Qty</span><strong>${fmt(s.total_order_qty || s.total_so_qty || 0)} ${esc(uom)}</strong></div>`;
            rowsHtml = detailRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">SO: ${esc(r.salordno || r.sales_order || '—')}</small></td>
                <td class="num">${qty(r.tot_poqty ?? r.order_qty ?? r.so_qty ?? 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = detailRows.length;
        } else if (cardKey === 'po_value') {
            icon = 'payments';
            subtitle = 'PO DATA';
            title = 'Total Net Value';
            val = fmt(s.total_po_value);
            unit = curr;
            formula = 'Total Net Value = ∑ (TOT_POQTY × PORATE) once per PO item';
            const seenIt = {};
            const itRows = detailRows.filter(r => {
                const it = String(r.poitem || r.po_item || r.item || '').trim();
                if (it && !seenIt[it]) { seenIt[it] = true; return true; }
                return !it;
            });
            const poLineNetOf = (r) => {
                const rate = nVal(r.porate ?? r.po_unit_cost ?? r.net_price ?? r.actual_cost);
                const q = Number(r.tot_poqty || r.order_qty || 0) || 0;
                if (rate !== null && q > 0) return rate * q;
                return Number(r.totvalue ?? r.net_value ?? r.so_wise_po_val ?? 0) || 0;
            };
            stepsHtml = itRows.map(r => {
                const q = Number(r.tot_poqty || r.order_qty || 0) || 0;
                const rate = nVal(r.porate ?? r.po_unit_cost ?? r.net_price ?? r.actual_cost) || 0;
                return `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item || r.item)} (${qty(q)} × ₹${fmtDec(rate, 4)})</span><strong>₹${money(poLineNetOf(r))}</strong></div>`;
            }).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Net Value</span><strong>₹${fmt(s.total_po_value)} ${esc(curr)}</strong></div>`;
            rowsHtml = itRows.map((r, i) => {
                const q = Number(r.tot_poqty || r.order_qty || 0) || 0;
                const rate = nVal(r.porate ?? r.po_unit_cost ?? r.net_price ?? r.actual_cost) || 0;
                return `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(q)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(rate, 4)}</td>
                <td class="num">₹${money(poLineNetOf(r))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`;
            }).join('');
            rowCount = itRows.length;
        } else if (cardKey === 'zcost_items') {
            icon = 'format_list_numbered';
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total Items';
            val = fmt(s.zcost_items);
            formula = 'zCOST Items = Count of PO items with a confirmed zCOST match (unmatched excluded)';
            const matchedRows = detailRows.filter(isMatchedRow);
            const seenZ = {};
            const distZ = matchedRows.filter(r => {
                const it = String(r.poitem || r.po_item || r.item || '').trim();
                if (it && !seenZ[it]) { seenZ[it] = true; return true; }
                return false;
            });
            stepsHtml = distZ.length
                ? distZ.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item || r.item)} Matched to zCOST Line</span><strong>${esc(r.maktx || r.cost_description || 'Matched')}</strong></div>`).join('')
                    + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Matched Items</span><strong>${fmt(s.zcost_items)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched items</span><strong>0</strong></div>';
            rowsHtml = distZ.map(r => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.budget_cost_per_gmt ?? r.expected_unit_cost, 4)}</td>
                <td class="num">₹${money((r.budget_cost_per_gmt ?? r.expected_unit_cost) * (r.tot_poqty || r.order_qty || 1))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = distZ.length;
        } else if (cardKey === 'zcost_materials') {
            icon = 'category';
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total Material';
            val = fmt(s.zcost_materials);
            formula = 'zCOST Material = Count of distinct PO materials with a confirmed zCOST match (unmatched excluded)';
            const matchedRows = detailRows.filter(isMatchedRow);
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
                <td>#${esc(r.poitem || r.po_item || r.item || 1)}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">${esc(r.maktx || r.cost_description || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.budget_cost_per_gmt ?? r.expected_unit_cost, 4)}</td>
                <td class="num">₹${money((r.budget_cost_per_gmt ?? r.expected_unit_cost) * (r.tot_poqty || r.order_qty || 1))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = distMats.length;
        } else if (cardKey === 'zcost_so_qty') {
            icon = 'inventory_2';
            subtitle = 'COSTING DATA (MATCHED ONLY)';
            title = 'Total PO Qty';
            val = fmt(s.zcost_so_qty);
            unit = uom;
            formula = 'Costing PO Qty = ∑ TOT_POQTY on matched lines';
            const matchedRows = detailRows.filter(isMatchedRow);
            stepsHtml = matchedRows.length
                ? matchedRows.map(r => `<div class="pcw-cdm-step-item"><span>Matched Item #${esc(r.poitem || r.po_item || r.item)} · SO ${esc(r.salordno || r.sales_order || '—')}</span><strong>${qty(r.tot_poqty ?? r.order_qty ?? r.so_qty ?? 0)} ${esc(r.ouom || uom)}</strong></div>`).join('')
                    + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Matched PO Qty</span><strong>${fmt(s.zcost_so_qty)} ${esc(uom)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched quantity</span><strong>0</strong></div>';
            rowsHtml = matchedRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                <td class="num">${qty(r.tot_poqty ?? r.order_qty ?? r.so_qty ?? 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.budget_cost_per_gmt ?? r.expected_unit_cost, 4)}</td>
                <td class="num">₹${money((r.budget_cost_per_gmt ?? r.expected_unit_cost) * (r.tot_poqty || r.order_qty || 1))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = matchedRows.length;
        } else if (cardKey === 'zcost_value') {
            icon = 'payments';
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total Net Value (Budget Total)';
            val = fmt(s.zcost_net_value);
            unit = curr;
            formula = 'Costing Net Value = ∑ (SO_QTY × BUDGET_COST_PER_GMT) once per matched PO item';
            const matchedRows = detailRows.filter(isMatchedRow);
            const seenZVal = {};
            const valRows = matchedRows.filter(r => {
                const it = String(r.poitem || r.po_item || r.item || '').trim();
                if (it && !seenZVal[it]) { seenZVal[it] = true; return true; }
                return !it;
            });
            const budgetQtyOf = (r) => {
                const so = Number(r.so_qty ?? r.prwise_so_qty ?? 0) || 0;
                const po = Number(r.tot_poqty || r.order_qty || 0) || 0;
                return so > 0 ? so : (po > 0 ? po : 1);
            };
            stepsHtml = valRows.length
                ? valRows.map(r => {
                    const b = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost) || 0;
                    const q = budgetQtyOf(r);
                    return `<div class="pcw-cdm-step-item"><span>Item #${esc(r.poitem || r.po_item || r.item)} (${qty(q)} SO × ₹${fmtDec(b, 4)})</span><strong>₹${money(b * q)}</strong></div>`;
                }).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Expected Budget</span><strong>₹${fmt(s.zcost_net_value)} ${esc(curr)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched value</span><strong>₹0</strong></div>';
            rowsHtml = valRows.map((r, i) => {
                const b = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost) || 0;
                const q = budgetQtyOf(r);
                return `<tr>
                    <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                    <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                    <td class="num">${qty(q)} ${esc(r.ouom || uom)}</td>
                    <td class="num">₹${fmtDec(b, 4)}</td>
                    <td class="num">₹${money(b * q)}</td>
                    <td>${statusPill(r.status, r)}</td>
                </tr>`;
            }).join('');
            rowCount = valRows.length;
        }

        const isZcost = String(cardKey || '').startsWith('zcost_');
        const isStatus = String(cardKey || '').startsWith('status_');
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

        panel.classList.remove('is-po', 'is-zcost', 'is-status');
        panel.classList.add(isZcost ? 'is-zcost' : (isStatus ? 'is-status' : 'is-po'));

        $('pcwCardModalIcon').textContent = icon;
        $('pcwCardModalSubtitle').textContent = subtitle;
        $('pcwCardModalTitle').textContent = title;
        $('pcwCardModalVal').textContent = val;
        $('pcwCardModalUnit').textContent = unit;
        $('pcwCardModalFormula').textContent = formula;
        $('pcwCardModalSteps').innerHTML = stepsHtml || '<p style="color:#64748b; margin:0;">Standard aggregation across active records.</p>';
        $('pcwCardModalTableBody').innerHTML = rowsHtml || '<tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">No rows found for this metric.</td></tr>';
        $('pcwCardModalTableCount').textContent = `${rowCount} row${rowCount === 1 ? '' : 's'}`;
        if ($('pcwCardModalTableTitle')) {
            $('pcwCardModalTableTitle').textContent = tableTitle;
        }

        const filterBtn = $('pcwCardModalFilterBtn');
        if (filterBtn) {
            if (showFilterBtn && filterStatus) {
                filterBtn.style.display = 'inline-flex';
                $('pcwCardModalFilterLabel').textContent = `Filter Table by ${statusFilterLabel(filterStatus)}`;
                filterBtn.onclick = () => {
                    closeCardDetailModal();
                    filterTableByStatus(filterStatus);
                };
            } else {
                filterBtn.style.display = 'none';
            }
        }

        shell.hidden = false;
        shell.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pcw-card-offcanvas-open');
        requestAnimationFrame(() => {
            shell.classList.add('is-open');
        });
    }

    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    function headerPo() {
        return String($('po')?.value || root?.dataset.po || cfg.po || '').replace(/\D/g, '');
    }

    function pdfUrlFor(po, opts) {
        const digits = String(po || '').replace(/\D/g, '');
        if (!digits) return '';
        // Resolve against the current app path (not origin root) so /sap_reports/… works
        const base = cfg.pdfUrl || new URL('purchase-order/pdf', window.location.href).pathname;
        const u = new URL(base, window.location.href);
        u.searchParams.set('po', digits);
        u.searchParams.set('_', String(Date.now())); // bust iframe/cache
        if (opts?.ensure) u.searchParams.set('ensure', '1');
        // Clean full-page view: no Chrome PDF toolbar / thumbnails
        const page = Number(opts?.page || 2);
        const clean = opts?.clean !== false;
        if (opts?.ensure) {
            return u.toString();
        }
        if (clean) {
            return `${u.toString()}#page=${page}&toolbar=0&navpanes=0&scrollbar=0&view=FitH`;
        }
        return `${u.toString()}#page=${page}`;
    }

    /** Warm-cache PO PDF on first search (local EVOL folder; SAP only if missing). */
    async function ensureLocalPoPdfCache(po) {
        const digits = String(po || '').replace(/\D/g, '');
        if (!/^\d{1,10}$/.test(digits)) return;
        try {
            const url = pdfUrlFor(digits, { ensure: true, clean: false });
            await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
        } catch (_) {
            /* non-blocking */
        }
    }

    let esignPdfBlobUrl = '';
    let esignPdfRenderToken = 0;
    let esignPdfDoc = null;
    let esignPdfResizeObs = null;

    function revokeEsignPdfBlob() {
        if (esignPdfBlobUrl) {
            try { URL.revokeObjectURL(esignPdfBlobUrl); } catch (_) {}
            esignPdfBlobUrl = '';
        }
    }

    function esignPdfSurfaceBox() {
        // Stamp maps to last-page canvas (exact PDF page pixels on screen)
        const last = document.querySelector('.pcw-esign-pdf-page.is-sign');
        const canvas = last?.querySelector('canvas');
        if (canvas && canvas.clientWidth > 0 && canvas.clientHeight > 0) {
            return {
                el: last,
                canvas,
                w: canvas.clientWidth,
                h: canvas.clientHeight,
            };
        }
        if (last && last.clientWidth > 0 && last.clientHeight > 0) {
            return { el: last, canvas: null, w: last.clientWidth, h: last.clientHeight };
        }
        const surface = $('pcwEsignPdfSurface');
        const stage = $('pcwEsignStage');
        return {
            el: surface || stage,
            canvas: null,
            w: (surface || stage)?.clientWidth || 1,
            h: (surface || stage)?.clientHeight || 1,
        };
    }

    function esignStageSize() {
        const stage = $('pcwEsignStage');
        if (!stage) return { w: 800, h: 600 };
        const pad = 24;
        const w = Math.max(280, (stage.clientWidth || 800) - pad);
        const h = Math.max(280, (stage.clientHeight || 600) - pad);
        return { w, h };
    }

    function parkEsignStamps() {
        // Keep live stamps + template alive — never let pagesEl.innerHTML destroy them
        const surface = $('pcwEsignPdfSurface');
        if (!surface) return [];
        const template = $('pcwEsignStamp');
        if (template && template.parentElement !== surface) surface.appendChild(template);
        const parked = [];
        document.querySelectorAll('.pcw-esign-stamp.is-live').forEach((el) => {
            const pageEl = el.closest('.pcw-esign-pdf-page');
            const canvas = pageEl?.querySelector('canvas');
            let place = null;
            if (canvas) {
                const cRect = canvas.getBoundingClientRect();
                const sRect = el.getBoundingClientRect();
                const sw = cRect.width || 1;
                const sh = cRect.height || 1;
                place = {
                    page: Number(pageEl?.dataset.page || 1),
                    x_pct: ((sRect.left - cRect.left) / sw) * 100,
                    y_pct: ((sRect.top - cRect.top) / sh) * 100,
                    w_pct: (sRect.width / sw) * 100,
                    h_pct: (sRect.height / sh) * 100,
                };
            }
            surface.appendChild(el);
            parked.push({ el, place });
        });
        return parked;
    }

    function clearLiveEsignStamps() {
        document.querySelectorAll('.pcw-esign-stamp.is-live').forEach((el) => el.remove());
        const clearBtn = $('pcwEsignClearStamps');
        if (clearBtn) clearBtn.disabled = true;
    }

    function liveEsignStamps() {
        return Array.from(document.querySelectorAll('.pcw-esign-stamp.is-live'));
    }

    function syncEsignStampButtons() {
        const clearBtn = $('pcwEsignClearStamps');
        if (clearBtn) clearBtn.disabled = liveEsignStamps().length === 0;
    }

    function remountParkedStamps(parked) {
        (parked || []).forEach(({ el, place }) => {
            const pageNum = Number(place?.page || 0);
            const pageEl = document.querySelector(`.pcw-esign-pdf-page[data-page="${pageNum}"]`)
                || document.querySelector('.pcw-esign-pdf-page.is-sign');
            const canvas = pageEl?.querySelector('canvas');
            if (!pageEl || !canvas || !place) {
                el.remove();
                return;
            }
            pageEl.appendChild(el);
            const w = canvas.clientWidth || 1;
            const h = canvas.clientHeight || 1;
            el.style.left = `${(place.x_pct / 100) * w}px`;
            el.style.top = `${(place.y_pct / 100) * h}px`;
            if (place.w_pct > 0) el.style.width = `${(place.w_pct / 100) * w}px`;
            el.hidden = false;
        });
        syncEsignStampButtons();
    }

    async function renderEsignPdfPages() {
        const stage = $('pcwEsignStage');
        const surface = $('pcwEsignPdfSurface');
        const pagesEl = $('pcwEsignPdfPages');
        const frame = $('pcwEsignFrame');
        if (!stage || !pagesEl || !esignPdfDoc || !window.pdfjsLib) return false;

        const parked = parkEsignStamps();

        const total = esignPdfDoc.numPages || 1;
        const first = await esignPdfDoc.getPage(1);
        const base = first.getViewport({ scale: 1 });
        const { w: availW, h: availH } = esignStageSize();
        // Fit page width; if only 1 page also fit height. Multi-page: scrollable stack.
        let scale = availW / base.width;
        if (total === 1) {
            scale = Math.min(availW / base.width, availH / base.height);
        }
        scale = Math.max(0.35, Math.min(scale, 2.2));
        const ratio = Math.min(2, window.devicePixelRatio || 1);

        pagesEl.innerHTML = '';
        let pageWidth = 0;
        for (let n = 1; n <= total; n += 1) {
            const page = await esignPdfDoc.getPage(n);
            const viewport = page.getViewport({ scale });
            pageWidth = Math.floor(viewport.width);

            const wrap = document.createElement('div');
            wrap.className = 'pcw-esign-pdf-page' + (n === total ? ' is-sign' : '');
            wrap.dataset.page = String(n);

            const label = document.createElement('span');
            label.className = 'pcw-esign-pdf-page-label';
            label.textContent = `Page ${n} / ${total}`;
            wrap.appendChild(label);

            const canvas = document.createElement('canvas');
            canvas.setAttribute('aria-label', `PO PDF page ${n}`);
            canvas.width = Math.floor(viewport.width * ratio);
            canvas.height = Math.floor(viewport.height * ratio);
            canvas.style.width = `${Math.floor(viewport.width)}px`;
            canvas.style.height = `${Math.floor(viewport.height)}px`;
            wrap.appendChild(canvas);
            pagesEl.appendChild(wrap);

            const ctx = canvas.getContext('2d');
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, viewport.width, viewport.height);
            await page.render({ canvasContext: ctx, viewport }).promise;
        }

        if (surface && pageWidth) {
            surface.style.width = `${pageWidth}px`;
            surface.style.height = '';
        }
        if (frame) {
            frame.hidden = true;
            frame.removeAttribute('src');
        }
        remountParkedStamps(parked);

        // Show last page (signature area) after render
        const last = pagesEl.querySelector('.pcw-esign-pdf-page.is-sign');
        if (last) {
            last.scrollIntoView({ block: 'nearest', behavior: 'auto' });
        } else {
            stage.scrollTop = 0;
        }
        stage.scrollLeft = 0;
        return true;
    }

    function bindEsignPdfResize() {
        const stage = $('pcwEsignStage');
        if (!stage || typeof ResizeObserver === 'undefined') return;
        if (esignPdfResizeObs) {
            try { esignPdfResizeObs.disconnect(); } catch (_) {}
        }
        let t = 0;
        esignPdfResizeObs = new ResizeObserver(() => {
            if (!esignPdfDoc) return;
            window.clearTimeout(t);
            t = window.setTimeout(() => { void renderEsignPdfPages(); }, 140);
        });
        esignPdfResizeObs.observe(stage);
    }

    async function loadEsignPdfClean(po) {
        const stage = $('pcwEsignStage');
        const pagesEl = $('pcwEsignPdfPages');
        const frame = $('pcwEsignFrame');
        const loading = $('pcwEsignPdfLoading');
        const fetchUrl = pdfUrlFor(po, { page: 1, clean: false }).split('#')[0];
        if (!fetchUrl || !stage) return;
        const token = ++esignPdfRenderToken;
        if (loading) loading.hidden = false;
        revokeEsignPdfBlob();
        esignPdfDoc = null;
        if (pagesEl) pagesEl.innerHTML = '';

        // Preferred: pdf.js — render ALL pages stacked (page 1, page 2, …)
        if (window.pdfjsLib && pagesEl) {
            try {
                const res = await fetch(fetchUrl, { credentials: 'same-origin', cache: 'no-store' });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.arrayBuffer();
                if (token !== esignPdfRenderToken) return;
                const pdf = await window.pdfjsLib.getDocument({ data }).promise;
                if (token !== esignPdfRenderToken) return;
                esignPdfDoc = pdf;
                await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
                if (token !== esignPdfRenderToken) return;
                const ok = await renderEsignPdfPages();
                if (!ok) throw new Error('PDF page render failed');
                bindEsignPdfResize();
                if (loading) loading.hidden = true;
                return;
            } catch (err) {
                console.warn('Clean PDF canvas render failed, falling back to iframe', err);
                esignPdfDoc = null;
            }
        }

        // Fallback iframe — start at page 1 so both pages are scrollable
        try {
            const res = await fetch(fetchUrl, { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const blob = await res.blob();
            if (token !== esignPdfRenderToken) return;
            revokeEsignPdfBlob();
            esignPdfBlobUrl = URL.createObjectURL(blob);
            if (pagesEl) pagesEl.innerHTML = '';
            if (frame) {
                frame.hidden = false;
                frame.src = `${esignPdfBlobUrl}#page=1&toolbar=0&navpanes=0&view=FitH`;
            }
            const surface = $('pcwEsignPdfSurface');
            const stamp = $('pcwEsignStamp');
            if (surface && stamp && stamp.parentElement !== surface) surface.appendChild(stamp);
        } catch (_) {
            if (frame) {
                frame.hidden = false;
                frame.src = pdfUrlFor(po, { page: 1, clean: true });
            }
        } finally {
            if (token === esignPdfRenderToken && loading) loading.hidden = true;
        }
    }

    let pdfPassIntent = false;

    function closePdfModal() {
        const modal = $('pcwPdfModal');
        const backdrop = $('pcwPdfBackdrop');
        const frame = $('pcwPdfFrame');
        const passFoot = $('pcwPdfPassFoot');
        pdfPassIntent = false;
        if (passFoot) passFoot.hidden = true;
        if (modal) modal.hidden = true;
        if (backdrop) backdrop.hidden = true;
        document.body.classList.remove('pcw-pdf-open');
        if (frame) {
            frame.hidden = true;
            frame.removeAttribute('src');
        }
        if ($('pcwPdfLoading')) $('pcwPdfLoading').hidden = false;
    }

    function openPdfModal(po, opts = {}) {
        const url = pdfUrlFor(po);
        if (!url) {
            alert('Enter a purchase order number first.');
            return;
        }
        const forPass = !!opts.forPass;
        pdfPassIntent = forPass;
        const modal = $('pcwPdfModal');
        const backdrop = $('pcwPdfBackdrop');
        const frame = $('pcwPdfFrame');
        const loading = $('pcwPdfLoading');
        const openTab = $('pcwPdfOpenTab');
        const passFoot = $('pcwPdfPassFoot');
        if (!modal || !frame) {
            window.open(url, '_blank', 'noopener');
            if (forPass) passPoAndOpenEsign({ fromPassPo: true });
            return;
        }
        if ($('pcwPdfTitle')) $('pcwPdfTitle').textContent = forPass ? 'Review PO PDF before Pass' : 'PO PDF';
        if ($('pcwPdfMeta')) {
            $('pcwPdfMeta').textContent = forPass
                ? ('Purchase Order ' + po + ' — review PDF, then Continue to Pass')
                : ('Purchase Order ' + po);
        }
        if (openTab) openTab.href = url;
        if (passFoot) passFoot.hidden = !forPass;
        if (loading) loading.hidden = false;
        frame.hidden = true;
        frame.onload = () => {
            if (loading) loading.hidden = true;
            frame.hidden = false;
        };
        frame.src = url;
        modal.hidden = false;
        if (backdrop) backdrop.hidden = false;
        document.body.classList.add('pcw-pdf-open');
    }

    function continuePassFromPdf() {
        if (!pdfPassIntent) return;
        const po = headerPo();
        pdfPassIntent = false;
        const passFoot = $('pcwPdfPassFoot');
        if (passFoot) passFoot.hidden = true;
        closePdfModal();
        if (!po) {
            alert('Load a purchase order first.');
            return;
        }
        passPoAndOpenEsign({ fromPassPo: true });
    }

    function openPdfTab(po) {
        const url = pdfUrlFor(po);
        if (!url) {
            alert('Enter a purchase order number first.');
            return;
        }
        window.open(url, '_blank', 'noopener');
    }

    let esignDrag = null;
    let esignDrawing = false;
    let esignHasInk = false;

    function setEsignTab(tab) {
        const which = tab === 'draw' ? 'draw' : 'upload';
        document.querySelectorAll('[data-esign-tab]').forEach((b) => {
            b.classList.toggle('is-active', b.getAttribute('data-esign-tab') === which);
        });
        // Saved tab = preview + place actions; Draw tab = canvas
        if ($('pcwEsignSavedPane')) $('pcwEsignSavedPane').hidden = which === 'draw';
        if ($('pcwEsignUploadPane')) $('pcwEsignUploadPane').hidden = true;
        if ($('pcwEsignDrawPane')) $('pcwEsignDrawPane').hidden = which !== 'draw';
        if ($('pcwEsignChangeSig')) $('pcwEsignChangeSig').hidden = which === 'draw';
        if (which === 'draw') initEsignCanvas();
    }

    function setEsignCreateMode(showCreate) {
        const tabs = $('pcwEsignTabs');
        if (tabs) tabs.hidden = false;
        setEsignTab(showCreate ? 'upload' : 'upload');
    }

    async function loadEsignAssignUsers() {
        const sel = $('pcwEsignAssignUser');
        if (!sel) return;
        const endpoint = cfg.usersUrl || 'purchase-order/users';
        const params = new URLSearchParams({ q: '', limit: '80', _: String(Date.now()) });
        try {
            const res = await fetch(`${endpoint}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            const users = Array.isArray(json?.data?.users) ? json.data.users : [];
            const prev = String(sel.value || '');
            sel.innerHTML = `<option value="">— Select user —</option>` + users.map((u) => {
                const id = String(u.id || '');
                const name = String(u.username || '');
                const email = String(u.email || '');
                return `<option value="${esc(id)}" data-username="${esc(name)}" data-email="${esc(email)}">${esc(name)}</option>`;
            }).join('');
            if (prev && [...sel.options].some((o) => o.value === prev)) {
                sel.value = prev;
            }
            syncEsignAssignEmail();
        } catch (_) {
            /* keep placeholder */
        }
    }

    function syncEsignAssignEmail() {
        const sel = $('pcwEsignAssignUser');
        const emailEl = $('pcwEsignAssignEmail');
        if (!sel || !emailEl) return;
        const opt = sel.options[sel.selectedIndex];
        emailEl.value = String(opt?.getAttribute('data-email') || '');
        sel.classList.toggle('is-invalid', !sel.value);
    }

    function formatEsignLogTime(raw) {
        const s = String(raw || '').trim();
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return s;
        try {
            return d.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch (_) {
            return s;
        }
    }

    function formatEsignLogAction(action) {
        const a = String(action || '').trim().toLowerCase();
        if (!a) return '—';
        if (a === 'pass' || a === 'pass_po') return 'Pass PO';
        if (a === 'esign' || a === 'e-sign' || a === 'sign') return 'E-Sign';
        if (a === 'approve' || a === 'approved') return 'Approved';
        if (a === 'save' || a === 'save_signed') return 'Save signed';
        return a.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }

    function setEsignLogDetails(row) {
        const wrap = $('pcwEsignLogDetails');
        const userEl = $('pcwEsignLogUser');
        const timeEl = $('pcwEsignLogTime');
        const actionEl = $('pcwEsignLogAction');
        const statusEl = $('pcwEsignLogStatus');
        if (!userEl || !timeEl || !actionEl || !statusEl) return;
        wrap?.classList.remove('is-ok', 'is-fail');
        if (!row) {
            userEl.textContent = '—';
            timeEl.textContent = '—';
            actionEl.textContent = '—';
            statusEl.textContent = 'No log yet';
            wrap?.classList.add('is-empty');
            return;
        }
        const person = String(row.username || row.user_id || '').trim() || '—';
        const status = String(row.status || (row.success ? 'OK' : 'Failed')).trim() || '—';
        const ok = row.success !== false && !/fail|error|reject/i.test(status);
        userEl.textContent = person;
        timeEl.textContent = formatEsignLogTime(row.created_at);
        actionEl.textContent = formatEsignLogAction(row.action);
        statusEl.textContent = status;
        wrap?.classList.remove('is-empty');
        wrap?.classList.add(ok ? 'is-ok' : 'is-fail');
        userEl.title = person;
        timeEl.title = String(row.created_at || '');
        statusEl.title = status;
    }

    function resetEsignLogDetails() {
        setEsignLogDetails(null);
        const statusEl = $('pcwEsignLogStatus');
        if (statusEl) statusEl.textContent = 'Loading…';
    }

    async function loadEsignLogDetails(po) {
        const digits = String(po || '').replace(/\D/g, '');
        if (!digits) {
            setEsignLogDetails(null);
            return;
        }
        try {
            const endpoint = cfg.passLogUrl || 'purchase-order/pass-log';
            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('po', digits);
            url.searchParams.set('limit', '5');
            const res = await fetch(url.toString(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const json = await res.json().catch(() => ({}));
            const rows = Array.isArray(json?.data?.rows) ? json.data.rows : [];
            setEsignLogDetails(rows[0] || null);
        } catch (_) {
            setEsignLogDetails(null);
            const statusEl = $('pcwEsignLogStatus');
            if (statusEl) statusEl.textContent = 'Unavailable';
        }
    }

    function openEsignModal(opts) {
        const po = String(opts?.po || headerPo() || '').replace(/\D/g, '');
        const requestId = String(opts?.requestId || '');
        // Approved button only after Pass PO click
        const showApprove = !!opts?.fromPassPo;
        const shell = $('pcwEsignModal');
        if (!shell || !po) return;
        if ($('pcwEsignPo')) $('pcwEsignPo').value = po;
        if ($('pcwEsignRequestId')) {
            $('pcwEsignRequestId').value = requestId || String(Date.now() % 1000000000);
        }
        if ($('pcwEsignPoLabel')) $('pcwEsignPoLabel').textContent = `PO ${po}`;
        if ($('pcwEsignFromDb')) $('pcwEsignFromDb').value = '0';
        if ($('pcwEsignFile')) $('pcwEsignFile').value = '';
        if ($('pcwEsignRemarks')) $('pcwEsignRemarks').value = '';
        if ($('pcwEsignAssignEmail')) $('pcwEsignAssignEmail').value = '';
        if ($('pcwEsignAssignUser')) {
            $('pcwEsignAssignUser').value = '';
            $('pcwEsignAssignUser').classList.remove('is-invalid');
        }
        resetEsignLogDetails();
        void loadEsignLogDetails(po);
        // eSign toolbar → hide Approved; Pass PO → show Approved
        shell.classList.toggle('is-from-pass-po', showApprove);
        const approveBtn = $('pcwEsignApprove');
        const approveHint = $('pcwEsignApproveHint');
        if (approveBtn) {
            approveBtn.hidden = !showApprove;
            approveBtn.setAttribute('aria-hidden', showApprove ? 'false' : 'true');
        }
        if (approveHint) approveHint.hidden = !showApprove;
        setEsignSignatureData('');
        setEsignCreateMode(true);
        clearLiveEsignStamps();
        const stamp = $('pcwEsignStamp');
        if (stamp) {
            stamp.hidden = true;
            stamp.classList.remove('is-live');
        }
        const panel = shell.querySelector('.pcw-esign-panel');
        // Stable split: left tools + right PDF
        panel?.classList.remove('is-tools-collapsed');
        const toolsBtn = $('pcwEsignToolsToggle');
        if (toolsBtn) {
            toolsBtn.setAttribute('aria-expanded', 'true');
            const label = toolsBtn.querySelector('span:last-child');
            if (label && !label.classList.contains('material-icons-round')) label.textContent = 'Tools';
        }
        shell.hidden = false;
        shell.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pcw-esign-open');
        requestAnimationFrame(() => {
            shell.classList.add('is-open');
            void loadEsignPdfClean(po);
        });
        initEsignCanvas();
        void loadUserEsignFromDb();
        void loadEsignAssignUsers();
    }

    function setEsignToolsCollapsed(collapsed) {
        const panel = document.querySelector('.pcw-esign-panel');
        const btn = $('pcwEsignToolsToggle');
        if (!panel) return;
        panel.classList.toggle('is-tools-collapsed', !!collapsed);
        if (btn) {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            const label = btn.querySelector('span:last-child');
            if (label && !label.classList.contains('material-icons-round')) {
                label.textContent = 'Tools';
            }
        }
        const po = String($('pcwEsignPo')?.value || '').replace(/\D/g, '');
        if (po) {
            window.setTimeout(() => { void loadEsignPdfClean(po); }, 80);
        }
    }

    function closeEsignModal() {
        const shell = $('pcwEsignModal');
        if (!shell) return;
        shell.classList.remove('is-from-pass-po');
        const approveBtn = $('pcwEsignApprove');
        const approveHint = $('pcwEsignApproveHint');
        if (approveBtn) {
            approveBtn.hidden = true;
            approveBtn.setAttribute('aria-hidden', 'true');
        }
        if (approveHint) approveHint.hidden = true;
        esignPdfRenderToken += 1;
        esignPdfDoc = null;
        if (esignPdfResizeObs) {
            try { esignPdfResizeObs.disconnect(); } catch (_) {}
            esignPdfResizeObs = null;
        }
        shell.classList.remove('is-open');
        shell.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pcw-esign-open');
        window.setTimeout(() => {
            if (!shell.classList.contains('is-open')) {
                shell.hidden = true;
                const frame = $('pcwEsignFrame');
                if (frame) {
                    frame.hidden = true;
                    frame.removeAttribute('src');
                }
                clearLiveEsignStamps();
                const pagesEl = $('pcwEsignPdfPages');
                if (pagesEl) pagesEl.innerHTML = '';
                const surface = $('pcwEsignPdfSurface');
                const stamp = $('pcwEsignStamp');
                if (surface && stamp && stamp.parentElement !== surface) {
                    surface.appendChild(stamp);
                }
                if (surface) surface.removeAttribute('style');
                revokeEsignPdfBlob();
            }
        }, 220);
    }

    function initEsignCanvas() {
        const canvas = $('pcwEsignCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const ratio = window.devicePixelRatio || 1;
        const w = canvas.clientWidth || 320;
        const h = canvas.clientHeight || 120;
        canvas.width = Math.floor(w * ratio);
        canvas.height = Math.floor(h * ratio);
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 2.2;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#0f172a';
        ctx.clearRect(0, 0, w, h);
        esignHasInk = false;
    }

    /**
     * Keep only signature ink — remove white/gray fill, border frame, then crop.
     */
    function makeSignatureTransparent(dataUrl, opts) {
        const doCrop = !opts || opts.crop !== false;
        return new Promise((resolve) => {
            const src = String(dataUrl || '');
            if (!src) {
                resolve('');
                return;
            }
            const img = new Image();
            img.onload = () => {
                try {
                    const w = img.naturalWidth || img.width || 1;
                    const h = img.naturalHeight || img.height || 1;
                    const c = document.createElement('canvas');
                    c.width = w;
                    c.height = h;
                    const ctx = c.getContext('2d', { willReadFrequently: true });
                    if (!ctx) {
                        resolve(src);
                        return;
                    }
                    ctx.clearRect(0, 0, w, h);
                    ctx.drawImage(img, 0, 0, w, h);
                    const imageData = ctx.getImageData(0, 0, w, h);
                    const d = imageData.data;
                    let minX = w;
                    let minY = h;
                    let maxX = 0;
                    let maxY = 0;

                    for (let y = 0; y < h; y++) {
                        for (let x = 0; x < w; x++) {
                            const i = (y * w + x) * 4;
                            const r = d[i];
                            const g = d[i + 1];
                            const b = d[i + 2];
                            const a = d[i + 3];
                            if (a === 0) continue;

                            const lum = 0.299 * r + 0.587 * g + 0.114 * b;
                            const sat = Math.max(r, g, b) - Math.min(r, g, b);
                            // White / gray fill + light border → fully transparent
                            const isBg = lum >= 185 || (lum >= 155 && sat < 48);
                            if (isBg) {
                                d[i + 3] = 0;
                                continue;
                            }

                            const inkAlpha = Math.max(0, Math.min(255, Math.round((255 - lum) * 1.55)));
                            if (inkAlpha < 36) {
                                d[i + 3] = 0;
                                continue;
                            }
                            d[i] = 15;
                            d[i + 1] = 23;
                            d[i + 2] = 42;
                            d[i + 3] = Math.min(a, inkAlpha);

                            if (x < minX) minX = x;
                            if (y < minY) minY = y;
                            if (x > maxX) maxX = x;
                            if (y > maxY) maxY = y;
                        }
                    }
                    ctx.putImageData(imageData, 0, 0);

                    // Keep full canvas size when burning to PDF so placement matches on-screen stamp
                    if (!doCrop) {
                        resolve(c.toDataURL('image/png'));
                        return;
                    }

                    // Crop to ink only (no empty bordered box) — for DB / preview storage
                    if (maxX >= minX && maxY >= minY) {
                        const pad = 2;
                        const sx = Math.max(0, minX - pad);
                        const sy = Math.max(0, minY - pad);
                        const sw = Math.min(w - sx, maxX - minX + 1 + pad * 2);
                        const sh = Math.min(h - sy, maxY - minY + 1 + pad * 2);
                        const out = document.createElement('canvas');
                        out.width = Math.max(1, sw);
                        out.height = Math.max(1, sh);
                        const octx = out.getContext('2d');
                        if (octx) {
                            octx.clearRect(0, 0, out.width, out.height);
                            octx.drawImage(c, sx, sy, sw, sh, 0, 0, sw, sh);
                            resolve(out.toDataURL('image/png'));
                            return;
                        }
                    }
                    resolve(c.toDataURL('image/png'));
                } catch (_) {
                    resolve(src);
                }
            };
            img.onerror = () => resolve(src);
            img.src = src;
        });
    }

    function applyEsignSignatureUi(dataUrl, fromDb) {
        if ($('pcwEsignSigData')) $('pcwEsignSigData').value = dataUrl || '';
        if ($('pcwEsignFromDb')) $('pcwEsignFromDb').value = fromDb ? '1' : '0';
        const img = $('pcwEsignSavedImg');
        const empty = $('pcwEsignSavedEmpty');
        const useBtn = $('pcwEsignUseSaved');
        const cropBtn = $('pcwEsignCropBtn');
        const stampImg = $('pcwEsignStampImg');
        if (dataUrl) {
            if (img) {
                img.src = dataUrl;
                img.hidden = false;
            }
            if (empty) empty.hidden = true;
            if (useBtn) useBtn.disabled = false;
            if (cropBtn) cropBtn.disabled = false;
            if (stampImg) stampImg.src = dataUrl;
            setEsignCreateMode(false);
            // Do not auto-place — user clicks Place on PDF for each location
            liveEsignStamps().forEach((el) => {
                const img = el.querySelector('img');
                if (img) img.src = dataUrl;
            });
        } else {
            if (img) {
                img.removeAttribute('src');
                img.hidden = true;
            }
            if (empty) empty.hidden = false;
            if (useBtn) useBtn.disabled = true;
            if (cropBtn) cropBtn.disabled = true;
            if ($('pcwEsignRawSig')) $('pcwEsignRawSig').value = '';
            closeEsignCrop();
            setEsignCreateMode(true);
        }
    }

    function setEsignSignatureData(dataUrl, fromDb, opts) {
        if (!dataUrl) {
            applyEsignSignatureUi('', fromDb);
            return;
        }
        if (!(opts && opts.keepRaw) && $('pcwEsignRawSig')) {
            $('pcwEsignRawSig').value = dataUrl;
        }
        void makeSignatureTransparent(dataUrl).then((transparentUrl) => {
            const out = transparentUrl || dataUrl;
            applyEsignSignatureUi(out, fromDb);
            if (!fromDb && !(opts && opts.skipDbSave)) {
                void persistEsignToDb(out);
            }
        });
    }

    let esignCropDrag = null;
    let esignCropSource = null; // HTMLImageElement for crop canvas source

    function closeEsignCrop() {
        const pane = $('pcwEsignCropPane');
        if (pane) pane.hidden = true;
        const sel = $('pcwEsignCropSel');
        if (sel) {
            sel.classList.remove('is-on');
            sel.style.cssText = '';
        }
        esignCropDrag = null;
        esignCropSource = null;
    }

    function setCropSelection(left, top, width, height) {
        const sel = $('pcwEsignCropSel');
        const stage = $('pcwEsignCropStage');
        if (!sel || !stage) return;
        const maxW = stage.clientWidth || 1;
        const maxH = stage.clientHeight || 1;
        const l = Math.max(0, Math.min(left, maxW - 4));
        const t = Math.max(0, Math.min(top, maxH - 4));
        const w = Math.max(8, Math.min(width, maxW - l));
        const h = Math.max(8, Math.min(height, maxH - t));
        sel.classList.add('is-on');
        sel.style.left = `${l}px`;
        sel.style.top = `${t}px`;
        sel.style.width = `${w}px`;
        sel.style.height = `${h}px`;
    }

    function paintCropCanvas(img) {
        const canvas = $('pcwEsignCropCanvas');
        const stage = $('pcwEsignCropStage');
        if (!canvas || !stage || !img) return;
        const cw = Math.max(120, stage.clientWidth || 280);
        const ch = Math.max(120, stage.clientHeight || 200);
        canvas.width = cw;
        canvas.height = ch;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.clearRect(0, 0, cw, ch);
        const iw = img.naturalWidth || img.width || 1;
        const ih = img.naturalHeight || img.height || 1;
        const scale = Math.min(cw / iw, ch / ih);
        const dw = iw * scale;
        const dh = ih * scale;
        const dx = (cw - dw) / 2;
        const dy = (ch - dh) / 2;
        ctx.drawImage(img, dx, dy, dw, dh);
        canvas.dataset.dx = String(dx);
        canvas.dataset.dy = String(dy);
        canvas.dataset.dw = String(dw);
        canvas.dataset.dh = String(dh);
        canvas.dataset.iw = String(iw);
        canvas.dataset.ih = String(ih);
        // Default selection over drawn image area
        setCropSelection(dx + dw * 0.08, dy + dh * 0.12, dw * 0.84, dh * 0.76);
    }

    function openEsignCrop() {
        const raw = $('pcwEsignRawSig')?.value || $('pcwEsignSigData')?.value || '';
        if (!raw) {
            alert('Add a signature first.');
            return;
        }
        const pane = $('pcwEsignCropPane');
        if (!pane) return;
        pane.hidden = false;
        const img = new Image();
        img.onload = () => {
            esignCropSource = img;
            requestAnimationFrame(() => paintCropCanvas(img));
        };
        img.onerror = () => alert('Could not load signature for crop.');
        img.src = raw;
    }

    function applyEsignCrop() {
        const canvas = $('pcwEsignCropCanvas');
        const sel = $('pcwEsignCropSel');
        const src = esignCropSource;
        if (!canvas || !sel || !src || !sel.classList.contains('is-on')) {
            alert('Open Crop and drag a box over the signature first.');
            return;
        }
        const dx = Number(canvas.dataset.dx || 0);
        const dy = Number(canvas.dataset.dy || 0);
        const dw = Number(canvas.dataset.dw || 1);
        const dh = Number(canvas.dataset.dh || 1);
        const iw = Number(canvas.dataset.iw || 1);
        const ih = Number(canvas.dataset.ih || 1);
        const left = parseFloat(sel.style.left) || 0;
        const top = parseFloat(sel.style.top) || 0;
        const swDisp = parseFloat(sel.style.width) || 0;
        const shDisp = parseFloat(sel.style.height) || 0;

        // Map selection from canvas display space → natural image pixels
        const scaleX = iw / Math.max(dw, 1);
        const scaleY = ih / Math.max(dh, 1);
        const sx = Math.max(0, (left - dx) * scaleX);
        const sy = Math.max(0, (top - dy) * scaleY);
        const sw = Math.min(iw - sx, swDisp * scaleX);
        const sh = Math.min(ih - sy, shDisp * scaleY);
        if (sw < 8 || sh < 8) {
            alert('Crop area is too small — drag a larger box.');
            return;
        }
        const out = document.createElement('canvas');
        out.width = Math.max(1, Math.round(sw));
        out.height = Math.max(1, Math.round(sh));
        const ctx = out.getContext('2d');
        if (!ctx) return;
        ctx.clearRect(0, 0, out.width, out.height);
        ctx.drawImage(src, sx, sy, sw, sh, 0, 0, out.width, out.height);
        const cropped = out.toDataURL('image/png');
        closeEsignCrop();
        setEsignSignatureData(cropped, false, { keepRaw: true });
    }

    async function persistEsignToDb(dataUrl) {
        const endpoint = cfg.esignSignatureUrl || 'purchase-order/esign-signature';
        try {
            await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ signature_data: dataUrl }),
            });
        } catch (_) {
            // Non-blocking — final Save still upserts signature
        }
    }

    async function loadUserEsignFromDb() {
        const endpoint = cfg.esignSignatureUrl || 'purchase-order/esign-signature';
        try {
            const res = await fetch(`${endpoint}?_=${Date.now()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            const data = json?.data || {};
            if (res.ok && json.success !== false && data.found && data.signature_data) {
                setEsignSignatureData(String(data.signature_data), true);
                return true;
            }
        } catch (_) {}
        setEsignCreateMode(true);
        return false;
    }

    function readEsignUploadFile(file) {
        if (!file) return;
        if (!/^image\/(png|jpe?g)$/i.test(file.type || '')) {
            alert('Upload a PNG or JPG signature image.');
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            const dataUrl = String(reader.result || '');
            if (!dataUrl) return;
            setEsignSignatureData(dataUrl, false);
        };
        reader.readAsDataURL(file);
    }

    function createLiveEsignStamp(dataUrl) {
        const template = $('pcwEsignStamp');
        if (!template) return null;
        const stamp = template.cloneNode(true);
        stamp.id = '';
        stamp.classList.add('is-live');
        stamp.hidden = false;
        stamp.removeAttribute('aria-hidden');
        stamp.title = 'Drag to move · click × to remove';
        const img = stamp.querySelector('img');
        if (img && dataUrl) {
            img.src = dataUrl;
            img.alt = 'Signature stamp';
        }
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'pcw-esign-stamp-remove';
        rm.title = 'Remove this signature';
        rm.setAttribute('aria-label', 'Remove signature');
        rm.textContent = '×';
        rm.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            stamp.remove();
            syncEsignStampButtons();
        });
        stamp.appendChild(rm);
        return stamp;
    }

    function placeStampOnPreview(opts) {
        const quiet = !!(opts && opts.quiet);
        const data = $('pcwEsignSigData')?.value || '';
        if (!data) {
            if (!quiet) alert('Upload or draw a signature first.');
            return;
        }
        // Target last page by default (signature block); user can drag anywhere on that page
        const pageEl = document.querySelector('.pcw-esign-pdf-page.is-sign')
            || document.querySelector('.pcw-esign-pdf-page:last-child');
        const canvas = pageEl?.querySelector('canvas');
        if (!pageEl || !canvas || !(canvas.clientWidth > 10)) {
            if (!quiet) {
                alert('PDF is still loading — wait a moment, then click Place on PDF again.');
            }
            return;
        }
        const stamp = createLiveEsignStamp(data);
        if (!stamp) return;
        pageEl.appendChild(stamp);

        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        const count = liveEsignStamps().length;
        const stampW = Math.min(120, Math.max(80, w * 0.14));
        stamp.style.width = `${stampW}px`;
        // Stagger each new place so multiple stamps don't stack exactly
        const slots = [
            { x: 0.12, y: 0.78 }, // Prepared By
            { x: 0.40, y: 0.78 }, // Checked By
            { x: 0.70, y: 0.78 }, // Authorised Signatory
        ];
        const slot = slots[Math.min(count - 1, slots.length - 1)];
        const extra = Math.max(0, count - slots.length) * 18;
        stamp.style.left = `${Math.max(4, Math.min(w * slot.x, w - stampW - 4))}px`;
        stamp.style.top = `${Math.max(4, Math.min(h * slot.y + extra, h - 40))}px`;

        syncEsignStampButtons();
        try {
            stamp.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } catch (_) {
            pageEl.scrollIntoView({ block: 'nearest' });
        }
    }

    function stampPlacementsAll() {
        const out = [];
        liveEsignStamps().forEach((stamp) => {
            const pageEl = stamp.closest('.pcw-esign-pdf-page');
            const canvas = pageEl?.querySelector('canvas');
            if (!canvas) return;
            const cRect = canvas.getBoundingClientRect();
            const sRect = stamp.getBoundingClientRect();
            const sw = cRect.width || 1;
            const sh = cRect.height || 1;
            const place = {
                page: Number(pageEl?.dataset.page || 1),
                x_pct: ((sRect.left - cRect.left) / sw) * 100,
                y_pct: ((sRect.top - cRect.top) / sh) * 100,
                w_pct: (sRect.width / sw) * 100,
                h_pct: (sRect.height / sh) * 100,
            };
            if (place.w_pct > 0.5 && place.h_pct > 0.5) out.push(place);
        });
        return out;
    }

    function stampPlacementPct() {
        const all = stampPlacementsAll();
        return all[0] || { x_pct: 0, y_pct: 0, w_pct: 0, h_pct: 0, page: 0 };
    }

    let poPassState = { po: '', passed: false }; // APPROVEDSTATUS from Pass_po_validtion

    function applyApprovedStatus(po, status) {
        const digits = String(po || '').replace(/\D/g, '');
        const st = String(status || '').trim();
        const approved = st === 'Approved';
        poPassState = { po: digits, passed: approved };
        return approved ? 'Approved' : 'NotApproved';
    }

    function syncPassRevertButtons(summary) {
        const passBtn = $('pcwPassPoBtn');
        const el = $('pcwPassStatus');
        const po = headerPo();
        const mayPass = cfg.canPassPo !== false;
        const s = summary || {};
        const exceptions = Number(s.exceptions || 0);
        const review = Number(s.needs_review || 0);
        const mapping = Number(s.mapping_required || 0);
        const mismatch = Number(s.cost_mismatch || 0);
        const ambiguous = Number(s.ambiguous_match || 0);
        const passedRows = Number(s.passed || 0);
        const canPassRows = !!s.can_pass_po
            || (passedRows > 0
                && exceptions === 0
                && review === 0
                && mapping === 0
                && mismatch === 0
                && ambiguous === 0);
        // APPROVEDSTATUS: Approved | NotApproved (from Pass_po_validtion / approve API)
        const isThisPoPassed = poPassState.passed && poPassState.po === po;
        // Workbench rows loaded from validate/search
        const dataLoaded = (rows || []).some((r) => r && !isFooterRow(r));

        if (passBtn) {
            // Enable Pass PO when all detail lines pass and PO is not yet approved
            const showPassPo = mayPass && !!po && !isThisPoPassed;
            const enablePassPo = showPassPo && dataLoaded && canPassRows;
            passBtn.hidden = !showPassPo;
            passBtn.disabled = !enablePassPo;
            passBtn.title = !mayPass
                ? 'No permission to Pass PO'
                : isThisPoPassed
                    ? 'PO already approved'
                    : !dataLoaded
                        ? 'Search / load the PO first'
                        : !canPassRows
                            ? 'All lines must Pass before Pass PO'
                            : 'Pass PO (Value = X)';
        }
        if (!el) return;
        el.classList.remove('is-passed', 'is-partial', 'is-pending');
        // Field APPROVEDSTATUS — only Approved / NotApproved
        if (isThisPoPassed) {
            el.textContent = 'STATUS : Approved';
            el.classList.add('is-passed');
        } else {
            el.textContent = 'STATUS : NotApproved';
            if (!po || !dataLoaded) el.classList.add('is-pending');
            else if (review > 0 || exceptions > 0 || mapping > 0 || mismatch > 0) el.classList.add('is-partial');
        }
    }

    async function postPoApproval(action) {
        if (cfg.canPassPo === false) return;
        const po = headerPo();
        if (!po) {
            alert('Load a purchase order first.');
            return;
        }
        const isRevert = action === 'revert';
        const value = isRevert ? ' ' : 'X';
        const passBtn = $('pcwPassPoBtn');
        if (passBtn) passBtn.disabled = true;
        showSpinner(true);
        try {
            const endpoint = cfg.passPoUrl || 'purchase-order/pass';
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ po, value, action: isRevert ? 'revert' : 'pass' }),
            });
            const rawText = await res.text();
            let json = {};
            try {
                json = rawText ? JSON.parse(rawText) : {};
            } catch (_) {
                throw new Error(
                    rawText
                        ? `PASS/REVERT returned non-JSON (HTTP ${res.status}): ${rawText.slice(0, 180)}`
                        : `PASS/REVERT returned empty response (HTTP ${res.status}).`
                );
            }
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            if (isRevert) {
                applyApprovedStatus(po, 'NotApproved');
                setPassStatus(summarizeRows(rows));
                const toast = document.createElement('div');
                toast.className = 'pcw-toast is-ok';
                toast.textContent = `PO ${po} reverted (Value = blank)`;
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3200);
                return;
            }
            applyApprovedStatus(po, 'Approved');
            setPassStatus(summarizeRows(rows));
            const pdfUrl = pdfUrlFor(po);
            const requestId = String(json?.data?.request_id || '');
            openEsignModal({ po, pdfUrl, requestId, fromPassPo: true });
        } catch (err) {
            alert(String(err.message || (isRevert ? 'REVERT failed' : 'PASS PO failed')));
            syncPassRevertButtons({});
        } finally {
            showSpinner(false);
        }
    }

    /** Open e-sign page. Pass PO sets fromPassPo so Approved button shows. */
    function passPoAndOpenEsign(opts = {}) {
        const po = headerPo();
        if (!po) {
            alert('Load a purchase order first.');
            return;
        }
        openEsignModal({
            po,
            pdfUrl: pdfUrlFor(po),
            requestId: String(Date.now() % 1000000000),
            fromPassPo: !!opts.fromPassPo,
        });
    }

    async function revertPoApproval() {
        if (!confirm('Revert this PO approval? SAP Value will be sent as blank.')) return;
        await postPoApproval('revert');
    }

    function dataUrlToUint8(dataUrl) {
        const m = String(dataUrl || '').match(/^data:([^;]+);base64,(.+)$/i);
        if (!m) throw new Error('Invalid signature image data.');
        const bin = atob(m[2]);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return { mime: m[1].toLowerCase(), bytes };
    }

    function uint8ToBase64(bytes) {
        let s = '';
        const chunk = 0x8000;
        for (let i = 0; i < bytes.length; i += chunk) {
            s += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
        }
        return btoa(s);
    }

    /**
     * Burn signature into the PDF at one or many on-screen places (1:1 %).
     * @param {string} pdfUrl
     * @param {string} sigDataUrl
     * @param {object|object[]} placeOrPlaces
     */
    async function buildSignedPdfBase64(pdfUrl, sigDataUrl, placeOrPlaces) {
        const PDFLib = window.PDFLib;
        if (!PDFLib || !PDFLib.PDFDocument) {
            throw new Error('PDF stamp library failed to load. Hard-refresh and try again.');
        }
        const res = await fetch(pdfUrl, { credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok) throw new Error(`Could not load PO PDF (HTTP ${res.status})`);
        const pdfBytes = await res.arrayBuffer();
        const pdfDoc = await PDFLib.PDFDocument.load(pdfBytes, { ignoreEncryption: true });
        const pages = pdfDoc.getPages();
        if (!pages.length) throw new Error('PO PDF has no pages.');

        const { mime, bytes } = dataUrlToUint8(sigDataUrl);
        const img = mime.includes('jpeg') || mime.includes('jpg')
            ? await pdfDoc.embedJpg(bytes)
            : await pdfDoc.embedPng(bytes);

        const places = Array.isArray(placeOrPlaces)
            ? placeOrPlaces
            : (placeOrPlaces ? [placeOrPlaces] : []);
        const list = places.filter((p) => Number(p?.w_pct || 0) > 0.5 && Number(p?.h_pct || 0) > 0.5);
        if (!list.length) {
            // Fallback single Authorised Signatory on last page
            list.push({ page: pages.length, x_pct: 70, y_pct: 78, w_pct: 14, h_pct: 4.5 });
        }

        list.forEach((place) => {
            const pageIdx = Math.max(0, Math.min(pages.length - 1, (Number(place.page) || pages.length) - 1));
            const page = pages[pageIdx];
            const { width, height } = page.getSize();
            let sigW = width * (Number(place.x_pct) >= 0 ? Number(place.w_pct) / 100 : 0.14);
            let sigH = height * (Number(place.h_pct) / 100);
            let x = width * (Number(place.x_pct) / 100);
            let y = height - height * (Number(place.y_pct) / 100) - sigH;
            if (!(sigW > 0)) sigW = Math.min(width * 0.155, 110);
            if (!(sigH > 0)) sigH = (img.height / Math.max(img.width, 1)) * sigW;
            if (sigW > width) sigW = width;
            if (sigH > height) sigH = height;
            x = Math.max(0, Math.min(x, width - sigW));
            y = Math.max(0, Math.min(y, height - sigH));
            page.drawImage(img, { x, y, width: sigW, height: sigH, opacity: 1 });
        });

        const stamped = await pdfDoc.save({ useObjectStreams: false });
        return uint8ToBase64(stamped);
    }

    /** Approved button: call POApprovalSet only (Value=X). No signed PDF. */
    async function approveEsignOnly() {
        const po = String($('pcwEsignPo')?.value || headerPo() || '').replace(/\D/g, '');
        if (!po) {
            alert('PO is missing.');
            return;
        }
        const btn = $('pcwEsignApprove');
        if (btn) btn.disabled = true;
        showSpinner(true);
        try {
            const endpoint = cfg.passPoUrl || 'purchase-order/pass';
            const requestId = $('pcwEsignRequestId')?.value || String(Date.now() % 1000000000);
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    po,
                    request_id: requestId,
                    value: 'X',
                    action: 'approve',
                    approve_only: true,
                }),
            });
            const rawText = await res.text();
            let json = {};
            try {
                json = rawText ? JSON.parse(rawText) : {};
            } catch (_) {
                throw new Error(
                    rawText
                        ? `Approve API returned non-JSON (HTTP ${res.status}): ${rawText.slice(0, 180)}`
                        : `Approve API returned empty body (HTTP ${res.status}).`
                );
            }
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            // SAP Approve succeeded → APPROVEDSTATUS = Approved
            applyApprovedStatus(po, 'Approved');
            syncPassRevertButtons(summarizeRows(rows));

            const toast = document.createElement('div');
            toast.className = 'pcw-toast is-ok';
            toast.textContent = `PO ${po} approved in SAP (POApprovalSet)`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3200);
            closeEsignModal();
        } catch (err) {
            // Do not mark local APPROVED when SAP fails
            alert(String(err.message || 'Approve API failed'));
        } finally {
            showSpinner(false);
            if (btn) btn.disabled = false;
        }
    }

    async function saveEsignPackage() {
        const po = String($('pcwEsignPo')?.value || headerPo() || '').replace(/\D/g, '');
        const sig = String($('pcwEsignSigData')?.value || '');
        if (!po) {
            alert('PO is missing.');
            return;
        }
        if (!sig) {
            alert('Add a signature and place it on the PDF.');
            return;
        }
        const places = stampPlacementsAll();
        if (!places.length) {
            alert('Click Place on PDF at least once, then drag each stamp where you want it.');
            return;
        }
        const assignSel = $('pcwEsignAssignUser');
        const assignId = String(assignSel?.value || '').trim();
        const assignOpt = assignSel?.options?.[assignSel.selectedIndex];
        const assignName = String(assignOpt?.getAttribute('data-username') || assignOpt?.textContent || '').trim();
        const assignEmail = String($('pcwEsignAssignEmail')?.value || '').trim();
        const remarks = String($('pcwEsignRemarks')?.value || '').trim();
        assignSel?.classList.remove('is-invalid');
        const place = places[0];
        const btn = $('pcwEsignSave');
        if (btn) btn.disabled = true;
        showSpinner(true);
        try {
            // Transparent ink, same canvas size as on-screen stamp (no crop → no position shift)
            const cleanSig = await makeSignatureTransparent(sig, { crop: false });
            const pdfUrl = pdfUrlFor(po, { page: 2 });
            let signedPdf = '';
            try {
                signedPdf = await buildSignedPdfBase64(pdfUrl.split('#')[0], cleanSig || sig, places);
            } catch (stampErr) {
                throw new Error(String(stampErr.message || stampErr || 'Could not stamp signature onto PDF'));
            }

            const endpoint = cfg.esignSaveUrl || 'purchase-order/esign-save';
            const requestId = $('pcwEsignRequestId')?.value || String(Date.now() % 1000000000);
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    po,
                    request_id: requestId,
                    signature_data: cleanSig || sig,
                    signed_pdf: signedPdf,
                    placement: 'multi',
                    placements: places,
                    assigned_user_id: assignId,
                    assigned_username: assignName,
                    assigned_email: assignEmail,
                    remarks,
                    ...place,
                }),
            });
            const rawText = await res.text();
            let json = {};
            try {
                json = rawText ? JSON.parse(rawText) : {};
            } catch (_) {
                throw new Error(
                    rawText
                        ? `E-sign save returned non-JSON (HTTP ${res.status}): ${rawText.slice(0, 180)}`
                        : `E-sign save returned empty body (HTTP ${res.status}). Check PHP error log / post_max_size.`
                );
            }
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            // Save signed PDF only — does NOT approve in SAP and does NOT set local APPROVED
            syncPassRevertButtons(summarizeRows(rows));

            // Open the signed PDF right after e-sign save (clean full-page view)
            let fileUrl = String(json?.data?.file_url || '').trim()
                || new URL(`purchase-order/signed-file?po=${encodeURIComponent(po)}`, window.location.href).toString();
            if (fileUrl && !fileUrl.includes('#')) {
                fileUrl += '#toolbar=0&navpanes=0&view=FitH';
            }
            try {
                window.open(fileUrl, '_blank', 'noopener');
            } catch (_) {}

            const toast = document.createElement('div');
            toast.className = 'pcw-toast is-ok';
            toast.innerHTML = '';
            const msg = document.createElement('span');
            msg.textContent = 'Signed PDF saved (not SAP approved)';
            toast.appendChild(msg);
            const link = document.createElement('a');
            link.href = fileUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = 'Open PDF';
            link.style.marginLeft = '10px';
            link.style.color = '#fff';
            link.style.fontWeight = '800';
            link.style.textDecoration = 'underline';
            toast.appendChild(link);
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 6000);
            closeEsignModal();
        } catch (err) {
            alert(String(err.message || 'Could not save e-sign'));
        } finally {
            showSpinner(false);
            if (btn) btn.disabled = false;
        }
    }

    function bindEsignUi() {
        $('pcwEsignClose')?.addEventListener('click', closeEsignModal);
        $('pcwEsignFabClose')?.addEventListener('click', closeEsignModal);
        $('pcwEsignToolsToggle')?.addEventListener('click', () => {
            setEsignToolsCollapsed(false);
        });
        $('pcwEsignSideCollapse')?.addEventListener('click', () => {
            setEsignToolsCollapsed(true);
        });
        $('pcwEsignApprove')?.addEventListener('click', () => { void approveEsignOnly(); });
        $('pcwEsignSave')?.addEventListener('click', () => { void saveEsignPackage(); });
        $('pcwEsignUseSaved')?.addEventListener('click', () => {
            // Each click adds another signature place on the PDF
            placeStampOnPreview({ quiet: false });
        });
        $('pcwEsignClearStamps')?.addEventListener('click', () => {
            clearLiveEsignStamps();
        });
        $('pcwEsignCropBtn')?.addEventListener('click', openEsignCrop);
        $('pcwEsignCropCancel')?.addEventListener('click', closeEsignCrop);
        $('pcwEsignCropApply')?.addEventListener('click', applyEsignCrop);
        $('pcwEsignChangeSig')?.addEventListener('click', () => {
            closeEsignCrop();
            setEsignCreateMode(true);
            if ($('pcwEsignUploadPane')) $('pcwEsignUploadPane').hidden = false;
            if ($('pcwEsignDrawPane')) $('pcwEsignDrawPane').hidden = true;
        });
        $('pcwEsignFile')?.addEventListener('change', (e) => {
            const file = e.target?.files?.[0];
            readEsignUploadFile(file);
        });

        const cropStage = $('pcwEsignCropStage');
        const cropSel = $('pcwEsignCropSel');
        if (cropStage && cropSel) {
            const cropPos = (e) => {
                const r = cropStage.getBoundingClientRect();
                const src = e.touches ? e.touches[0] : e;
                return {
                    x: Math.max(0, Math.min(src.clientX - r.left, r.width)),
                    y: Math.max(0, Math.min(src.clientY - r.top, r.height)),
                };
            };
            const cropStart = (e) => {
                const p = cropPos(e);
                esignCropDrag = { x0: p.x, y0: p.y };
                cropSel.classList.add('is-on');
                cropSel.style.left = `${p.x}px`;
                cropSel.style.top = `${p.y}px`;
                cropSel.style.width = '0px';
                cropSel.style.height = '0px';
                e.preventDefault();
            };
            const cropMove = (e) => {
                if (!esignCropDrag) return;
                const p = cropPos(e);
                const left = Math.min(esignCropDrag.x0, p.x);
                const top = Math.min(esignCropDrag.y0, p.y);
                cropSel.style.left = `${left}px`;
                cropSel.style.top = `${top}px`;
                cropSel.style.width = `${Math.abs(p.x - esignCropDrag.x0)}px`;
                cropSel.style.height = `${Math.abs(p.y - esignCropDrag.y0)}px`;
                e.preventDefault();
            };
            const cropEnd = () => { esignCropDrag = null; };
            cropStage.addEventListener('mousedown', cropStart);
            cropStage.addEventListener('touchstart', cropStart, { passive: false });
            window.addEventListener('mousemove', cropMove);
            window.addEventListener('touchmove', cropMove, { passive: false });
            window.addEventListener('mouseup', cropEnd);
            window.addEventListener('touchend', cropEnd);
        }

        document.querySelectorAll('[data-esign-tab]').forEach((btn) => {
            btn.addEventListener('click', () => {
                setEsignTab(btn.getAttribute('data-esign-tab'));
            });
        });
        $('pcwEsignAssignUser')?.addEventListener('change', syncEsignAssignEmail);

        const canvas = $('pcwEsignCanvas');
        if (canvas) {
            const pos = (e) => {
                const r = canvas.getBoundingClientRect();
                const src = e.touches ? e.touches[0] : e;
                return { x: src.clientX - r.left, y: src.clientY - r.top };
            };
            const start = (e) => {
                esignDrawing = true;
                const ctx = canvas.getContext('2d');
                if (!ctx) return;
                const p = pos(e);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                e.preventDefault();
            };
            const move = (e) => {
                if (!esignDrawing) return;
                const ctx = canvas.getContext('2d');
                if (!ctx) return;
                const p = pos(e);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
                esignHasInk = true;
                e.preventDefault();
            };
            const end = () => { esignDrawing = false; };
            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', end);
        }
        $('pcwEsignClear')?.addEventListener('click', () => {
            initEsignCanvas();
            esignHasInk = false;
        });
        $('pcwEsignKeepDraw')?.addEventListener('click', () => {
            const canvasEl = $('pcwEsignCanvas');
            if (!canvasEl || !esignHasInk) {
                alert('Draw a signature first.');
                return;
            }
            setEsignSignatureData(canvasEl.toDataURL('image/png'), false);
        });

        // Drag any live stamp (delegation — supports multiple places)
        const stage = $('pcwEsignStage');
        if (stage) {
            const onDown = (e) => {
                if (e.target?.closest?.('.pcw-esign-stamp-remove')) return;
                const stamp = e.target?.closest?.('.pcw-esign-stamp.is-live');
                if (!stamp) return;
                const src = e.touches ? e.touches[0] : e;
                const rect = stamp.getBoundingClientRect();
                esignDrag = {
                    el: stamp,
                    dx: src.clientX - rect.left,
                    dy: src.clientY - rect.top,
                };
                e.preventDefault();
            };
            const onMove = (e) => {
                if (!esignDrag?.el) return;
                const stamp = esignDrag.el;
                const pageEl = stamp.closest('.pcw-esign-pdf-page');
                const canvas = pageEl?.querySelector('canvas') || pageEl;
                if (!canvas) return;
                const src = e.touches ? e.touches[0] : e;
                const sRect = canvas.getBoundingClientRect();
                const maxW = canvas.clientWidth || sRect.width;
                const maxH = canvas.clientHeight || sRect.height;
                let x = src.clientX - sRect.left - esignDrag.dx;
                let y = src.clientY - sRect.top - esignDrag.dy;
                x = Math.max(0, Math.min(x, maxW - stamp.offsetWidth));
                y = Math.max(0, Math.min(y, maxH - stamp.offsetHeight));
                stamp.style.left = `${x}px`;
                stamp.style.top = `${y}px`;
                e.preventDefault();
            };
            const onUp = () => { esignDrag = null; };
            stage.addEventListener('mousedown', onDown);
            stage.addEventListener('touchstart', onDown, { passive: false });
            window.addEventListener('mousemove', onMove);
            window.addEventListener('touchmove', onMove, { passive: false });
            window.addEventListener('mouseup', onUp);
            window.addEventListener('touchend', onUp);
        }

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
        const po = headerPo();
        if (poPassState.po && poPassState.po !== po) {
            poPassState = { po: '', passed: false };
        }
        syncPassRevertButtons(summary);
        const validateBtn = $('pcwOpenValidateBtn');
        if (validateBtn) {
            const mayValidate = cfg.canValidate !== false;
            validateBtn.hidden = !mayValidate;
            validateBtn.disabled = !mayValidate;
        }
        const assignWrap = $('pcwUserAssign')?.closest('.pcw-user-assign, .pcw-assign-wrap, label, div') || $('pcwUserAssign');
        if ($('pcwUserAssign')) {
            const mayAssign = cfg.canAssign !== false;
            $('pcwUserAssign').disabled = !mayAssign;
            if (assignWrap && assignWrap !== $('pcwUserAssign')) {
                assignWrap.style.opacity = mayAssign ? '' : '0.45';
                assignWrap.style.pointerEvents = mayAssign ? '' : 'none';
            }
        }
    }

    function isFooterRow(r) {
        const kind = String(r?.row_kind || '');
        return kind === 'footer' || kind === 'charge' || kind === 'tax' || kind === 'total' || kind === 'subtotal';
    }

    const FOOTER_SUM_KEYS = [
        'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'totvalue', 'budgeted_cons', 'budget_inr',
        'budget_cost_per_gmt', 'conv', 'actual_cost',
    ];

    function footerLabelOf(r) {
        return String(r?.pono || r?.purchase_order || r?.matgroup || r?.validation_result || '')
            .toUpperCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    function hasClassicFooterRows(list) {
        return (list || []).some((r) => {
            const label = footerLabelOf(r).replace(/-/g, '');
            return label === 'SUBTOTAL' || label === 'TOTAL' || label.includes('SUB TOTAL');
        });
    }

    function sumFooterValues(detailRows) {
        const sums = Object.fromEntries(FOOTER_SUM_KEYS.map((k) => [k, 0]));
        (detailRows || []).forEach((r) => {
            FOOTER_SUM_KEYS.forEach((k) => {
                const n = Number(r?.[k] ?? r?.[k === 'actual_cost' ? 'po_unit_cost' : k]);
                if (Number.isFinite(n)) sums[k] += n;
            });
        });
        return sums;
    }

    function makeFooterRow(label, kind, sums) {
        const withSums = !!sums;
        const row = {
            pono: label,
            purchase_order: label,
            poitem: '',
            prno: '',
            salordno: '',
            style: '',
            material: '',
            matgroup: label,
            ouom: '',
            mat_desc: '',
            po_currency: '',
            costing_quotation_no: '',
            costing_item_no: '',
            zsection: '',
            maktx: '',
            budget_currency: '',
            buom: '',
            porate: null,
            budget_conv_rt: null,
            variance_pct: null,
            row_kind: kind,
            status: 'FOOTER',
            validation_result: label,
            needs_manual_match: false,
            selected: false,
            _synthetic_footer: true,
        };
        FOOTER_SUM_KEYS.forEach((k) => {
            row[k] = withSums ? Number(sums[k] || 0) : null;
        });
        return row;
    }

    /** Footer rows (SUB-TOTAL / TOTAL / charges) are hidden in the workbench grid. */
    function buildClassicFooterRows(detailRows) {
        return [];
    }

    function isEmptyChargeFooter(r) {
        if (!r || !isFooterRow(r)) return false;
        const label = footerLabelOf(r);
        if (!/(CHARGES|CHARGE)/i.test(label)) return false;
        return !FOOTER_SUM_KEYS.some((k) => {
            const n = Number(r?.[k]);
            return Number.isFinite(n) && n !== 0;
        });
    }

    function appendFooterBlock(detailIndexed) {
        // Detail lines only — totals render in <tfoot>, not as body rows
        return detailIndexed;
    }

    /** Columns summed once per PO item (values repeat on SO split lines). */
    const FOOT_ONCE_PER_ITEM = new Set([
        'tot_poqty', 'so_wise_poqty', 'totvalue', 'so_wise_po_val', 'budget_inr', 'porate',
        'budget_cost_per_piece',
    ]);
    /** Columns summed across every visible detail row. */
    const FOOT_SUM_ROWS = new Set(['reqqty', 'prwise_so_qty', 'so_qty']);
    /** Money-style footer columns. */
    const FOOT_MONEY = new Set(['totvalue', 'so_wise_po_val', 'budget_inr', 'budget_cost_per_piece']);
    /** Unit-rate columns: average of non-empty values (once per PO item). */
    const FOOT_AVG = new Set(['actual_cost', 'consumption', 'budgeted_cons', 'conv']);
    /**
     * BUDGET_COST_PER_GMT footer shows Σ zCOST Total in INR (TOTALINR),
     * same field as the ZCOST page "Total in INR" column — not the per-GMT average.
     */
    const FOOT_TOTALINR = new Set(['budget_cost_per_gmt']);

    function footRawNumber(key, r) {
        if (!r) return null;
        return nVal(cellValue(key, r, 0));
    }

    /** zCOST TOTALINR for a row (matched costing line). */
    function footTotalInr(r) {
        if (!r || isFooterRow(r)) return null;
        return nVal(r.total_inr)
            ?? nVal(r.cost_total_inr)
            ?? nVal(r.budget_inr)
            ?? nVal(r.budget_cost_per_piece)
            ?? budgetCostPerPiece(r);
    }

    function formatFootVal(key, n) {
        if (!Number.isFinite(n)) return '';
        if (FOOT_MONEY.has(key) || FOOT_TOTALINR.has(key)) {
            return Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
        }
        if (FOOT_AVG.has(key) || key === 'porate') {
            return Number(n).toLocaleString('en-IN', { maximumFractionDigits: 4 });
        }
        return Number(n).toLocaleString('en-IN', { maximumFractionDigits: 3 });
    }

    function isFootTotalKey(key) {
        return FOOT_ONCE_PER_ITEM.has(key)
            || FOOT_SUM_ROWS.has(key)
            || FOOT_AVG.has(key)
            || FOOT_TOTALINR.has(key);
    }

    function computeFootTotals(detailRows) {
        const sums = {};
        const avgSum = {};
        const avgCount = {};
        const seenItem = {};
        (detailRows || []).forEach((r) => {
            if (!r || isFooterRow(r)) return;
            const item = String(r.poitem || r.po_item || '').trim()
                || ('__' + String(r.material || '') + '_' + String(r.salordno || r.sales_order || ''));

            FOOT_ONCE_PER_ITEM.forEach((k) => {
                const seenKey = item + '|' + k;
                if (seenItem[seenKey]) return;
                const n = footRawNumber(k, r);
                if (n === null) return;
                seenItem[seenKey] = true;
                sums[k] = (sums[k] || 0) + n;
            });

            FOOT_SUM_ROWS.forEach((k) => {
                const n = footRawNumber(k, r);
                if (n === null) return;
                sums[k] = (sums[k] || 0) + n;
            });

            FOOT_AVG.forEach((k) => {
                const seenKey = item + '|avg|' + k;
                if (seenItem[seenKey]) return;
                const n = footRawNumber(k, r);
                if (n === null) return;
                seenItem[seenKey] = true;
                avgSum[k] = (avgSum[k] || 0) + n;
                avgCount[k] = (avgCount[k] || 0) + 1;
            });

            // BUDGET_COST_PER_GMT footer = Σ TOTALINR (once per PO item)
            FOOT_TOTALINR.forEach((k) => {
                const seenKey = item + '|totalinr|' + k;
                if (seenItem[seenKey]) return;
                const n = footTotalInr(r);
                if (n === null) return;
                seenItem[seenKey] = true;
                sums[k] = (sums[k] || 0) + n;
            });
        });

        FOOT_AVG.forEach((k) => {
            if (avgCount[k]) {
                sums[k] = avgSum[k] / avgCount[k];
            }
        });
        return sums;
    }

    function updateTableFooter(detailRows) {
        const foot = document.getElementById('pcwTableFoot');
        if (!foot) return;
        const sums = computeFootTotals(detailRows);
        const count = (detailRows || []).length;
        const label = document.getElementById('pcwFootTotalLabel');
        if (label) {
            label.textContent = count ? `TOTAL (${count})` : 'TOTAL';
        }
        foot.querySelectorAll('[data-foot-key], [data-col-id]').forEach((th) => {
            if (th.id === 'pcwFootTotalLabel') return;
            const key = th.getAttribute('data-foot-key')
                || th.getAttribute('data-col-id')
                || '';
            if (!key || key === 'status' || key === 'actions') {
                th.textContent = '';
                th.classList.remove('has-val');
                return;
            }
            if (isFootTotalKey(key)) {
                const n = sums[key];
                const text = (n != null && Number.isFinite(n)) ? formatFootVal(key, n) : '';
                th.textContent = text;
                th.classList.toggle('has-val', !!text);
                if (text) {
                    let tip = `Total: ${text}`;
                    if (FOOT_AVG.has(key)) tip = `Average: ${text}`;
                    if (FOOT_TOTALINR.has(key)) tip = `Total in INR (TOTALINR): ${text}`;
                    th.setAttribute('title', tip);
                } else {
                    th.removeAttribute('title');
                }
            } else {
                th.textContent = '';
                th.classList.remove('has-val');
                th.removeAttribute('title');
            }
        });
        foot.removeAttribute('hidden');
        foot.style.display = count === 0 ? 'none' : '';
    }

    function isMatchedRow(r) {
        if (!r || isFooterRow(r)) return false;
        if (r.family_mismatch && !r.manual_match) return false;
        if (r.manual_match || r.matched_line_key || r.cost_item || r.maktx) {
            if (!isZcostAlignedWithPo(r) && !r.manual_match) return false;
            const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            return budget !== null && budget > 0;
        }
        if (r.needs_manual_match === true) return false;
        const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const actual = nVal(r.actual_cost ?? r.po_unit_cost);
        if (budget === null || budget <= 0 || actual === null) return false;
        const st = String(r.status || '').toUpperCase();
        if (st === 'EXCEPTION' && /no matching|no linked|cannot load|no budget/i.test(String(r.validation_result || ''))) {
            return false;
        }
        // TEMPFIN budget/actual present but no explicit zCOST ITEM yet — still countable
        return true;
    }

    function needsZcostItemPick(r) {
        if (!r || isFooterRow(r)) return false;
        if (r._editingMatch) return true;
        // Wrong PASSPO / first-load family (HANGTAG↔CARE, BUTTON↔ELASTIC) — force rematch dropdown
        if (r.family_mismatch || r.needs_manual_match) {
            return !(r.manual_match || r.matched_line_key);
        }
        // Has a zCOST link that does not align with PO matgroup/desc
        const hasCost = !!(r.cost_item || r.zcost_item || r.costing_item_no || r.maktx);
        if (hasCost && !r.manual_match && !isZcostAlignedWithPo(r)) {
            return true;
        }
        // TEMPFIN already validated (budget+actual) with aligned zCOST — empty ITEM optional
        const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const actual = nVal(r.actual_cost ?? r.po_unit_cost);
        const st = String(r.status || '').toUpperCase();
        if ((st === 'PASS' || st === 'EXCEPTION') && budget !== null && budget > 0 && actual !== null && isZcostAlignedWithPo(r)) {
            return false;
        }
        if ((st === 'PASS' || st === 'EXCEPTION') && budget !== null && budget > 0 && actual !== null && hasCost) {
            return false;
        }
        return !(r.manual_match || r.matched_line_key || r.cost_item || r.maktx);
    }

    function summarizeRows(list) {
        const materials = {};
        const salesOrders = {};
        const items = {};
        const seenValue = {};
        let passed = 0;
        let exceptions = 0;
        let needsReview = 0;
        let mappingRequired = 0;
        let costMismatch = 0;
        let ambiguousMatch = 0;
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
            if (isFooterRow(r)) return;
            // Cards + pills + table filter all use the same statusDisplay() code
            const st = statusDisplay(r).code;
            if (st === 'PASS') {
                passed += 1;
            } else if (st === 'NOT_MATCHED') {
                mappingRequired += 1;
                exceptions += 1;
            } else if (st === 'NOT_VALIDATED') {
                costMismatch += 1;
                exceptions += 1;
            } else if (st === 'NEEDS_REVIEW') {
                ambiguousMatch += 1;
                needsReview += 1;
            } else {
                mappingRequired += 1;
                exceptions += 1;
            }
            const mat = String(r.material || '').trim();
            if (mat) materials[mat] = true;
            const so = String(r.salordno || r.sales_order || '').trim();
            if (so) salesOrders[so] = true;
            const item = String(r.poitem || r.po_item || '').trim();
            if (item) items[item] = true;

            const rowSoQty = Number(r.so_qty ?? r.prwise_so_qty ?? 0) || 0;
            const rowOrderQty = Number(r.tot_poqty ?? r.order_qty ?? r.po_qty ?? 0) || 0;
            // PO net = PORATE × TOT_POQTY (fallback TOTVALUE). Costing net = BUDGET × SO_QTY (per GMT).
            const poRate = nVal(r.porate ?? r.po_unit_cost ?? r.net_price ?? r.actual_cost);
            const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            const actual = nVal(r.porate ?? r.actual_cost ?? r.po_unit_cost);
            const poQty = rowOrderQty > 0 ? rowOrderQty : (rowSoQty > 0 ? rowSoQty : 0);
            const garmentQty = rowSoQty > 0 ? rowSoQty : (rowOrderQty > 0 ? rowOrderQty : 1);
            const poLineNet = (poRate !== null && poQty > 0)
                ? (poRate * poQty)
                : (Number(r.totvalue ?? r.net_value ?? r.so_wise_po_val ?? 0) || 0);

            // Once per PO item — TOTVALUE / TOT_POQTY repeat on every SO line
            if (item && !seenValue[item]) {
                seenValue[item] = true;
                totalValue += poLineNet;
                totalOrderQty += poQty;
                poSoQty += poQty;
                if (budget !== null) totalExpected += budget * garmentQty;
                if (actual !== null) totalActual += actual * garmentQty;
            } else if (!item) {
                totalValue += poLineNet;
                totalOrderQty += poQty;
                poSoQty += poQty;
                if (budget !== null) totalExpected += budget * garmentQty;
                if (actual !== null) totalActual += actual * garmentQty;
            }

            // Strict match check: only count rows that have a confirmed zCOST match
            const isMatched = isMatchedRow(r);

            if (isMatched) {
                if (item) zItems[item] = true;
                if (mat) zMaterials[mat] = true;

                // Costing Total PO Qty + Net Value — once per matched PO item
                if (item && !seenZItem[item]) {
                    seenZItem[item] = true;
                    zcostSoQty += poQty;
                    // BUDGET_COST_PER_GMT is per garment → × SO_QTY (fallback TOT_POQTY)
                    zcostExpected += (budget || 0) * garmentQty;
                } else if (!item) {
                    zcostSoQty += poQty;
                    zcostExpected += (budget || 0) * garmentQty;
                }
            }

            if (!unit) unit = String(r.ouom || r.unit || '').trim();
            if (r.po_currency || r.currency) currency = String(r.po_currency || r.currency);
        });

        const zcostItemCount = Object.keys(zItems).length;
        const zcostMatCount = Object.keys(zMaterials).length;

        const charges = summarizeCharges(list || []);
        return {
            total_records: (list || []).length,
            po_items: Object.keys(items).length || (list || []).length,
            materials: Object.keys(materials).length,
            sales_orders: Object.keys(salesOrders).length,
            total_order_qty: totalOrderQty,
            total_so_qty: poSoQty,
            total_po_value: totalValue,
            sub_total: totalValue,
            charges,
            charges_total: charges.total,
            grand_total: Math.round((totalValue + charges.total) * 100) / 100,
            zcost_items: zcostItemCount,
            zcost_materials: zcostMatCount,
            zcost_so_qty: zcostSoQty,
            zcost_net_value: zcostExpected,
            total_expected: totalExpected,
            total_actual: totalActual,
            unit,
            currency,
            passed,
            exceptions,
            needs_review: needsReview,
            mapping_required: mappingRequired,
            cost_mismatch: costMismatch,
            ambiguous_match: ambiguousMatch,
            can_pass_po: passed > 0
                && mappingRequired === 0
                && costMismatch === 0
                && ambiguousMatch === 0
                && exceptions === 0
                && needsReview === 0,
        };
    }

    /** SAP Cond* on PO lines (+ PASSPO charge footer fallback) → PDF-style charges. */
    function summarizeCharges(list) {
        const empty = {
            other: 0, freight: 0, moq: 0, screen: 0, bank: 0,
            discount: 0, sgst: 0, cgst: 0, igst: 0, total: 0,
        };
        const cond = { other: 0, freight: 0, moq: 0, screen: 0, bank: 0 };
        const footer = { ...empty };
        const seenItem = {};
        (list || []).forEach((r) => {
            if (!r) return;
            if (!isFooterRow(r)) {
                const item = String(r.poitem || r.po_item || '').trim()
                    || ('__' + String(r.material || '') + '|' + String(r.salordno || r.sales_order || ''));
                if (seenItem[item]) return;
                seenItem[item] = true;
                cond.other += nVal(r.cond_othr) || 0;
                cond.freight += (nVal(r.cond_frb1) || 0) + (nVal(r.cond_frb2) || 0);
                cond.moq += nVal(r.cond_zb00) || 0;
                cond.screen += nVal(r.cond_zsci) || 0;
                cond.bank += nVal(r.cond_zbcr) || 0;
                return;
            }
            const label = String(r.pono || r.matgroup || r.validation_result || '')
                .toUpperCase().replace(/\s+/g, ' ').trim();
            const amt = nVal(
                r.so_wise_po_val ?? r.totvalue ?? r.net_value
                ?? r.budget_inr ?? r.actual_cost ?? r.po_unit_cost ?? r.porate
            ) || 0;
            if (!amt || !label) return;
            if (label.includes('OTHER')) footer.other += amt;
            else if (label.includes('FREIGHT')) footer.freight += amt;
            else if (label.includes('MOQ')) footer.moq += amt;
            else if (label.includes('SCREEN')) footer.screen += amt;
            else if (label.includes('BANK')) footer.bank += amt;
            else if (label.includes('SGST')) footer.sgst += amt;
            else if (label.includes('CGST')) footer.cgst += amt;
            else if (label.includes('IGST')) footer.igst += amt;
            else if (label.includes('DISCOUNT')) footer.discount += amt;
        });
        const condSum = cond.other + cond.freight + cond.moq + cond.screen + cond.bank;
        const src = condSum > 0.0001 ? cond : footer;
        const out = {
            other: round2(src.other || 0),
            freight: round2(src.freight || 0),
            moq: round2(src.moq || 0),
            screen: round2(src.screen || 0),
            bank: round2(src.bank || 0),
            discount: round2(footer.discount || 0),
            sgst: round2(footer.sgst || 0),
            cgst: round2(footer.cgst || 0),
            igst: round2(footer.igst || 0),
            total: 0,
        };
        out.total = round2(
            out.other + out.freight + out.moq + out.screen + out.bank
            + out.sgst + out.cgst + out.igst - out.discount
        );
        return out;
    }

    function round2(n) {
        return Math.round((Number(n) || 0) * 100) / 100;
    }

    function fillChargesPanel(summary) {
        const panel = $('pcwChargesPanel');
        if (!panel) return;
        const s = summary || {};
        const charges = s.charges && typeof s.charges === 'object'
            ? s.charges
            : summarizeCharges(rows);
        const sub = Number(s.sub_total ?? s.total_po_value) || 0;
        const grand = Number(s.grand_total);
        const grandVal = Number.isFinite(grand) && grand > 0
            ? grand
            : round2(sub + (Number(charges.total) || 0));
        const curr = s.currency || 'INR';
        const money = (n) => Number(n || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        const set = (id, n) => { if ($(id)) $(id).textContent = money(n); };
        set('pcwChargeSubTotal', sub);
        set('pcwChargeDiscount', charges.discount);
        set('pcwChargeFreight', charges.freight);
        set('pcwChargeMoq', charges.moq);
        set('pcwChargeOther', charges.other);
        set('pcwChargeScreen', charges.screen);
        set('pcwChargeBank', charges.bank);
        set('pcwChargeSgst', charges.sgst);
        set('pcwChargeCgst', charges.cgst);
        set('pcwChargeIgst', charges.igst);
        set('pcwChargeGrandTotal', grandVal);
        if ($('pcwChargeGrandCurr')) $('pcwChargeGrandCurr').textContent = curr;

        const tileMap = {
            discount: Number(charges.discount) || 0,
            freight: Number(charges.freight) || 0,
            moq: Number(charges.moq) || 0,
            other: Number(charges.other) || 0,
            screen: Number(charges.screen) || 0,
            bank: Number(charges.bank) || 0,
        };
        panel.querySelectorAll('.pcw-po-tile[data-charge]').forEach((el) => {
            const key = el.getAttribute('data-charge') || '';
            el.classList.toggle('is-active', Math.abs(tileMap[key] || 0) > 0.0001);
        });
        const taxMap = {
            pcwChargeSgst: Number(charges.sgst) || 0,
            pcwChargeCgst: Number(charges.cgst) || 0,
            pcwChargeIgst: Number(charges.igst) || 0,
        };
        Object.keys(taxMap).forEach((id) => {
            const el = $(id)?.closest('.pcw-po-tax-item');
            if (el) el.classList.toggle('is-active', Math.abs(taxMap[id]) > 0.0001);
        });

        panel.hidden = !(rows.length > 0);
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
        // Always trust row-derived PASS / EXCEPTION / REVIEW (API used to count SUB-TOTAL as exceptions)
        if (rows.length) {
            s.passed = fromRows.passed;
            s.exceptions = fromRows.exceptions;
            s.needs_review = fromRows.needs_review;
            s.mapping_required = fromRows.mapping_required;
            s.cost_mismatch = fromRows.cost_mismatch;
            s.ambiguous_match = fromRows.ambiguous_match;
            s.can_pass_po = fromRows.can_pass_po
                ?? (Number(fromRows.passed) > 0
                    && Number(fromRows.mapping_required || 0) === 0
                    && Number(fromRows.cost_mismatch || 0) === 0
                    && Number(fromRows.ambiguous_match || 0) === 0);
        }
        // Always prefer row-derived qty/value cards (API summary used wrong fields)
        if (rows.length) {
            s.total_order_qty = fromRows.total_order_qty;
            s.total_so_qty = fromRows.total_so_qty;
            s.total_po_value = fromRows.total_po_value;
            s.sub_total = fromRows.sub_total;
            s.charges = fromRows.charges;
            s.charges_total = fromRows.charges_total;
            s.grand_total = fromRows.grand_total;
            s.total_expected = fromRows.total_expected;
            s.total_actual = fromRows.total_actual;
            s.zcost_so_qty = fromRows.zcost_so_qty;
            s.zcost_net_value = fromRows.zcost_net_value;
            s.zcost_items = fromRows.zcost_items;
            s.zcost_materials = fromRows.zcost_materials;
            s.unit = fromRows.unit || s.unit;
            s.currency = fromRows.currency || s.currency;
        }
        // Merge API charges (esp. IGST/SGST/CGST) when row scan missed PASSPO tax footers
        if (fromApi.charges && typeof fromApi.charges === 'object') {
            const rowCh = s.charges && typeof s.charges === 'object' ? s.charges : summarizeCharges([]);
            const apiCh = fromApi.charges;
            const keys = ['other', 'freight', 'moq', 'screen', 'bank', 'discount', 'sgst', 'cgst', 'igst'];
            const merged = { ...rowCh };
            let changed = false;
            keys.forEach((k) => {
                const a = Number(apiCh[k]) || 0;
                const b = Number(rowCh[k]) || 0;
                if (a > b + 0.0001) {
                    merged[k] = round2(a);
                    changed = true;
                } else {
                    merged[k] = round2(b);
                }
            });
            if (changed || !(Number(rowCh.total) > 0)) {
                merged.total = round2(
                    merged.other + merged.freight + merged.moq + merged.screen + merged.bank
                    + merged.sgst + merged.cgst + merged.igst - merged.discount
                );
                s.charges = merged;
                s.charges_total = merged.total;
                s.grand_total = round2((Number(s.sub_total ?? s.total_po_value) || 0) + merged.total);
            }
        } else if ((!s.charges || !(Number(s.charges.total) > 0)) && fromApi.grand_total) {
            s.grand_total = fromApi.grand_total;
        }
        const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
        const total = (Number(s.passed) || 0) + (Number(s.exceptions) || 0) + (Number(s.needs_review) || 0) || rows.length || 0;

        // Row 1: Classic status cards
        const mapping = Number(s.mapping_required ?? fromRows.mapping_required) || 0;
        const mismatch = Number(s.cost_mismatch ?? fromRows.cost_mismatch) || 0;
        const ambiguous = Number(s.ambiguous_match ?? fromRows.ambiguous_match) || 0;
        const statusTotal = (Number(s.passed) || 0) + mapping + mismatch + ambiguous
            || total || rows.filter((r) => !isFooterRow(r)).length || 0;
        if ($('pcwKpiTotal')) $('pcwKpiTotal').textContent = fmt(statusTotal);
        if ($('pcwKpiPassed')) $('pcwKpiPassed').textContent = fmt(s.passed || 0);
        if ($('pcwKpiMapping')) $('pcwKpiMapping').textContent = fmt(mapping);
        if ($('pcwKpiMismatch')) $('pcwKpiMismatch').textContent = fmt(mismatch);
        if ($('pcwKpiAmbiguous')) $('pcwKpiAmbiguous').textContent = fmt(ambiguous);
        // legacy ids (if present)
        if ($('pcwKpiExceptions')) $('pcwKpiExceptions').textContent = fmt(mapping + mismatch);
        if ($('pcwKpiReview')) $('pcwKpiReview').textContent = fmt(ambiguous || s.needs_review || 0);

        // Row 2 Left: PO Data — Total PO Qty uses TOT_POQTY
        const poItems = Number(s.po_items) > 0 ? s.po_items : (s.unique_po_items || rows.length || 0);
        const poMaterials = Number(s.materials) > 0 ? s.materials : 0;
        const poSoQty = Number(s.total_order_qty) > 0 ? Number(s.total_order_qty) : (Number(s.total_so_qty) || 0);
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
        const grandShown = Number(s.grand_total) > 0 ? Number(s.grand_total) : poNetVal;
        if ($('pcwKpiStatusPoValue')) {
            $('pcwKpiStatusPoValue').textContent = `${curr} ${Number(grandShown || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            $('pcwKpiStatusPoValue').title = Number(s.charges_total) > 0
                ? `Sub total ${Number(poNetVal || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 })} + charges ${Number(s.charges_total).toLocaleString('en-IN', { minimumFractionDigits: 2 })}`
                : 'Total PO Value';
        }
        fillChargesPanel(s);

        // Row 2 Right: zCOST Data (Matched only)
        const zItems = s.zcost_items ?? fromRows.zcost_items ?? 0;
        const zMaterials = s.zcost_materials ?? fromRows.zcost_materials ?? 0;
        const zSoQty = s.zcost_so_qty ?? fromRows.zcost_so_qty ?? 0;
        const zNetVal = Number(s.zcost_net_value ?? fromRows.zcost_net_value) > 0
            ? Number(s.zcost_net_value ?? fromRows.zcost_net_value)
            : 0;

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

    function statusPill(status, row) {
        const disp = row
            ? statusDisplay(row)
            : (() => {
                const st = String(status || '').toUpperCase().replace(/\s+/g, '_');
                if (st === 'PASS') return { label: 'Passed', cls: 'is-pass' };
                if (st === 'NEEDS_REVIEW' || st === 'AMBIGUOUS' || st === 'AMBIGUOUS_MATCH') {
                    return { label: 'Need to Review', cls: 'is-ambiguous' };
                }
                if (st === 'FOOTER') return { label: '—', cls: 'is-footer' };
                if (st === 'EXCEPTION' || st === 'FAIL' || st === 'NOT_VALIDATED') {
                    return { label: 'Cost Mismatch', cls: 'is-mismatch' };
                }
                return { label: 'Mapping Required', cls: 'is-mapping' };
            })();
        return `<span class="pcw-pill ${disp.cls}">${esc(disp.label)}</span>`;
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
        if (!r || isFooterRow(r)) return false;
        if (r.needs_manual_match === false) {
            const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            const actual = nVal(r.actual_cost ?? r.po_unit_cost);
            if (budget !== null && budget > 0 && actual !== null) return false;
        }
        return needsZcostItemPick(r) || !isMatchedRow(r);
    }

    function needsDropdown(r) {
        return needsManualMatch(r);
    }

    function zcostSelectHtml(r, index) {
        const cands = candidateLinesForRow(r || {});
        const selectedKey = String(r.matched_line_key || '');
        const poFamily = trimFamily(poMatchText(r || {}));
        const familyHint = poFamily ? ` (${poFamily})` : '';
        const options = [`<option value="">Select zCOST${familyHint}…</option>`].concat(
            cands.slice(0, 80).map((line) => {
                const globalIdx = (costLines || []).indexOf(line);
                const key = lineKey(line, globalIdx >= 0 ? globalIdx : 0);
                const rate = lineBudgetPerGmt(line);
                const label = [
                    line.item || line.material || '—',
                    line.maktx || line.description || '',
                    line.section || '',
                    rate !== null ? `₹${fmtStep(rate, 2)}` : '',
                ].filter(Boolean).join(' · ');
                const sel = key === selectedKey ? ' selected' : '';
                return `<option value="${esc(key)}"${sel}>${esc(label)}</option>`;
            })
        );
        const emptyHint = !costLines.length
            ? ' title="No zCOST candidates loaded for this PO"'
            : (!cands.length
                ? ` title="No ${poFamily || 'matching'} zCOST lines for this PO matgroup/desc"`
                : ` title="Manual select — ${poFamily || 'matching'} zCOST lines only"`);
        return `<div class="pcw-zcost-dd-wrap"${emptyHint}>
            <select class="pcw-zcost-select" data-idx="${index}" aria-label="Match zCOST line for row ${index + 1}">
                ${options.join('')}
            </select>
        </div>`;
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

    function trimFamily(text) {
        const t = String(text || '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
        if (!t) return '';
        if (/DRC[A-Z0-9]*/.test(t) || t.includes('DRAWCORD') || t.includes('DRAW CORD')) return 'CORD';
        if (/\b(BUTTON|BTN|PEARLBTN|HOOK\s*EYE|SNAP)\b/.test(t) || t.includes('BUTTON')) return 'BUTTON';
        if (/\b(ELASTIC|ELASTANE|RUBBERTAPE)\b/.test(t) || t.includes('ELASTIC')) return 'ELASTIC';
        if (/\b(THREAD|THREADS)\b/.test(t) || t.includes('THREAD')) return 'THREAD';
        if (/\b(ZIPPER|ZIP)\b/.test(t) || t.includes('ZIPPER')) return 'ZIPPER';
        if (/\b(STICKER|STICKERS|RFID)\b/.test(t) || t.includes('STICKER') || t.includes('RFID')) return 'STICKER';
        if (/\b(LABEL|LBL|HANGTAG)\b/.test(t) || t.includes('LABEL') || t.includes('HANGTAG')
            || /CARE\s*&?\s*CONTENT|WASH\s*CARE/.test(t)) return 'LABEL';
        if (/\b(DRAWCORD|CORD)\b/.test(t) || t.includes('CORD')) return 'CORD';
        if (/\b(RIBBON|TAPE|WEBBING)\b/.test(t)) return 'TAPE';
        if (/\b(POLYBAG|BAG)\b/.test(t) || t.includes('POLYBAG')) return 'BAG';
        return '';
    }

    /** Finer LABEL kinds so HANGTAG never auto-accepts CARE & CONTENT / WASH CARE. */
    function labelKind(text) {
        const raw = String(text || '').toUpperCase();
        const t = raw.replace(/[^A-Z0-9&#]+/g, ' ').replace(/\s+/g, ' ').trim();
        if (!t) return '';
        if (/HANG\s*TAG|HANGTAG|\bLHT\b|T[\s-]?LHT/.test(t)) return 'HANGTAG';
        if (/CARE\s*&?\s*CONTENT|WASH\s*CARE|CARE\s*LBL|CARELBL|CARE\s*LABEL|\bLCC\b|T[\s-]?LCC/.test(t)) return 'CARE';
        if (/MAIN\s*LBL|MAINLBL|MAIN\s*LABEL|\bLML\b|T[\s-]?LML/.test(t)) return 'MAIN';
        // SIZE LABEL + material patterns like 30SIZSTNSIZNTN#L-16 / SIDE SEW LABEL … L/16
        if (
            /SIZE\s*LBL|SIZELBL|SIZE\s*LABEL|\bLSL\b|T[\s-]?LSL/.test(t)
            || /SIZSTN|SIZNTN|\bSIZ\b/.test(t)
            || (/#(S|M|L|XL|XXL)-\d+/.test(raw) && /LABEL|LBL|SEW/.test(t))
            || (/SIDE\s*SEW\s*LABEL/.test(t) && /\b(S|M|L|XL|XXL)\b|\b[SML]\/\d+/.test(t))
        ) {
            return 'SIZE';
        }
        if (/WOVEN\s*LABEL/.test(t)) return 'WOVEN';
        if (/LABEL|LBL/.test(t)) return 'LABEL';
        return '';
    }

    /** Style / article codes shared by PO MAT_DESC and zCOST (e.g. VVVVSWH096). */
    function extractStyleCodes(text) {
        const raw = String(text || '').toUpperCase();
        const out = new Set();
        // Alphanumeric style tokens: letters + digits, length 6–20 (VVVVSWH096)
        (raw.match(/[A-Z]{2,}\d{2,}[A-Z0-9]*|[A-Z]{3,}[A-Z0-9]{3,}/g) || []).forEach((c) => {
            const u = c.replace(/[^A-Z0-9]/g, '');
            if (u.length >= 6 && u.length <= 24 && !/^(SIZE|LABEL|TRIM|PACK|THREAD|BUTTON)/.test(u)) {
                out.add(u);
            }
        });
        // Also capture CODE before first hyphen/slash: VVVVSWH096-S/2 → VVVVSWH096
        (raw.match(/[A-Z]{3,}[A-Z0-9]{2,}(?=[-_\/\s,])/g) || []).forEach((c) => {
            const u = c.replace(/[^A-Z0-9]/g, '');
            if (u.length >= 6 && u.length <= 24) out.add(u);
        });
        return [...out];
    }

    function styleCodesOverlap(poText, costText) {
        const a = extractStyleCodes(poText);
        const b = extractStyleCodes(costText);
        if (!a.length || !b.length) return [];
        return a.filter((x) => b.some((y) => x === y || x.includes(y) || y.includes(x)));
    }

    function poCostTokensOverlap(poText, costText) {
        if (styleCodesOverlap(poText, costText).length) return true;
        const norm = (s) => String(s || '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').trim().split(/\s+/)
            .filter((p) => p.length >= 3)
            .filter((p) => !['THE', 'AND', 'FOR', 'WITH', 'PCS', 'YDS', 'USD', 'INR', 'EUR', 'ACCESSOR', 'TRIM', 'TRIMS', 'PACKING', 'OTHERS', 'LABEL', 'LABELS', 'SIZE', 'SIDE', 'SEW'].includes(p));
        const a = [...new Set(norm(poText))];
        const b = [...new Set(norm(costText))];
        if (!a.length || !b.length) return false;
        if (a.some((x) => b.includes(x))) return true;
        return a.some((pa) => b.some((pb) => pa.length >= 4 && pb.length >= 4 && (pa.includes(pb) || pb.includes(pa))));
    }

    /** Rank a zCOST candidate for a PO row (higher = better). */
    function scoreCostLineForPo(r, line) {
        const poText = poMatchText(r);
        const lineText = costMatchText(line);
        const poFamily = trimFamily(poText);
        const lineFamily = trimFamily(lineText);
        const poKind = labelKind(poText);
        const lineKind = labelKind(lineText);
        if (poFamily && lineFamily && poFamily !== lineFamily) return -1;
        if (poKind && lineKind && poKind !== lineKind && poKind !== 'LABEL' && lineKind !== 'LABEL') return -1;

        const matCode = String(r.material || '').trim().toUpperCase();
        const item = String(line.item || line.material || '').trim().toUpperCase();
        const poDesc = String(r.mat_desc || r.material_desc || '').trim().toUpperCase();
        const costDesc = String(line.maktx || line.description || '').trim().toUpperCase();
        const styles = styleCodesOverlap(poText, lineText);
        let score = 0;
        if (matCode && item && (matCode === item || item.includes(matCode) || matCode.includes(item))) score += 100;
        if (styles.length) score += 120; // VVVVSWH096 ↔ SIZE LABEL: VVVVSWH096
        if (poKind && lineKind && (poKind === lineKind || (poKind === 'LABEL' && lineKind === 'SIZE') || (poKind === 'SIZE' && lineKind === 'LABEL'))) {
            score += poKind === lineKind ? 80 : 55;
        }
        if (poFamily && lineFamily && poFamily === lineFamily) score += 40;
        if (poDesc && costDesc && (poDesc.includes(costDesc.slice(0, 20)) || costDesc.includes(poDesc.slice(0, 20)))) score += 30;
        if (poCostTokensOverlap(poText, lineText)) score += 50;
        if (nVal(line.budget_per_gmt ?? line.rate ?? line.total_inr) > 0) score += 5;
        return score;
    }

    function poMatchText(r) {
        return `${r.matgroup || ''} ${r.mat_desc || r.material_desc || ''} ${r.material || ''}`;
    }

    function costMatchText(lineOrRow) {
        return `${lineOrRow.item || lineOrRow.cost_item || lineOrRow.zcost_item || lineOrRow.costing_item_no || ''} ${lineOrRow.maktx || lineOrRow.description || lineOrRow.cost_description || ''} ${lineOrRow.section || lineOrRow.zsection || ''}`;
    }

    /**
     * First-load rule: zCOST section/matrix must align with PO matgroup + mat_desc.
     * Same coarse family alone is not enough (LABEL HANGTAG ≠ LABEL CARE).
     */
    function isZcostAlignedWithPo(r, line) {
        if (!r) return false;
        const poText = poMatchText(r);
        const costText = line ? costMatchText(line) : costMatchText(r);
        if (!String(costText).trim()) return false;
        const poFamily = trimFamily(poText);
        const costFamily = trimFamily(costText);
        if (poFamily && costFamily && poFamily !== costFamily) return false;
        if (poFamily === 'LABEL' || costFamily === 'LABEL' || labelKind(poText) || labelKind(costText)) {
            const poKind = labelKind(poText);
            const costKind = labelKind(costText);
            // SIZE ↔ SIZE/LABEL OK; HANGTAG ↔ CARE still blocked
            const sizeCompatible = (poKind === 'SIZE' && (costKind === 'SIZE' || costKind === 'LABEL'))
                || (costKind === 'SIZE' && (poKind === 'SIZE' || poKind === 'LABEL'));
            if (poKind && costKind && poKind !== costKind && poKind !== 'LABEL' && costKind !== 'LABEL' && !sizeCompatible) {
                return false;
            }
        }
        if (styleCodesOverlap(poText, costText).length) return true;
        const matCode = String(r.material || '').trim().toUpperCase();
        const costItem = String((line && (line.item || line.material)) || r.cost_item || r.zcost_item || '').trim().toUpperCase();
        if (matCode && costItem && (matCode === costItem || costItem.includes(matCode) || matCode.includes(costItem))) {
            return true;
        }
        return poCostTokensOverlap(poText, costText);
    }

    /** Clear all Costing Data fields until user picks a dropdown line. */
    function emptyZcostDisplayFields(r) {
        if (!r) return;
        // Keep PO FX / BUOM for runtime calc after dropdown select
        if (r._po_budget_conv_rt == null && nVal(r.budget_conv_rt) !== null) {
            r._po_budget_conv_rt = nVal(r.budget_conv_rt);
        }
        if (r._po_buom == null && r.buom) {
            r._po_buom = String(r.buom);
        }
        if (r._po_budget_currency == null && r.budget_currency) {
            r._po_budget_currency = String(r.budget_currency);
        }
        r.cost_item = '';
        r.zcost_item = '';
        r.costing_item_no = '';
        r.costing_quotation_no = '';
        r.matched_quotation = '';
        r.maktx = '';
        r.cost_description = '';
        r.zsection = '';
        r.cost_section = '';
        r.budget_cost_per_gmt = null;
        r.budget_cost_per_piece = null;
        r.expected_unit_cost = null;
        r.budget_inr = null;
        r.cost_total_inr = null;
        r.total_inr = null;
        r.gross = null;
        r.budgeted_cons = null;
        r.budget_currency = '';
        r.budget_conv_rt = null;
        r.variance_pct = null;
        r.cost_mismatch = false;
        r.cost_matrix = null;
        // Hide costing ACTUAL until a line is selected (PO PORATE kept for runtime calc)
        r.actual_cost = null;
        r._zcost_pending = true;
    }

    function clearWrongZcostMatch(r, reason) {
        if (!r || isFooterRow(r)) return;
        r.family_mismatch = true;
        r.needs_manual_match = true;
        r.manual_match = false;
        r.matched_line_key = '';
        emptyZcostDisplayFields(r);
        r.status = 'NOT_MATCHED';
        r.validation_result = reason || 'NOT MATCHED — zCOST section/matrix does not match PO MATGROUP / MAT_DESC — select matching line';
        r._editingMatch = true;
    }

    /** On first load: reject wrong auto links (e.g. HANGTAG → CARE & CONTENT). */
    function enforceFirstLoadZcostMatch(r) {
        if (!r || isFooterRow(r)) return;
        if (r.manual_match) return;
        const hasCost = !!(r.cost_item || r.zcost_item || r.costing_item_no || r.maktx || r.cost_description);
        if (!hasCost) {
            // No zCOST yet — empty costing cells + dropdown
            r.needs_manual_match = true;
            r._editingMatch = true;
            r._zcost_pending = true;
            emptyZcostDisplayFields(r);
            r.status = 'NOT_MATCHED';
            r.validation_result = r.validation_result && /not matched|does not match|no matching|no linked/i.test(String(r.validation_result))
                ? r.validation_result
                : 'NOT MATCHED — select matching zCOST line';
            return;
        }
        if (!isZcostAlignedWithPo(r)) {
            const poKind = labelKind(poMatchText(r)) || trimFamily(poMatchText(r)) || 'PO';
            const costKind = labelKind(costMatchText(r)) || trimFamily(costMatchText(r)) || 'zCOST';
            clearWrongZcostMatch(
                r,
                `NOT MATCHED — zCOST "${costKind}" does not match PO "${poKind}" (MATGROUP/MAT_DESC) — select matching line`
            );
        }
    }

    /** PO MATGROUP → allowed zCOST sections (PACKING1 → Packing only). */
    function sectionsForPoRow(r) {
        const g = String(r?.matgroup || r?.material_group || '').toUpperCase();
        const d = String(r?.mat_desc || r?.material_desc || r?.description || '').toUpperCase();
        const blob = `${g} ${d}`;
        if (/PACK/.test(g) || /\bPACKING\b/.test(blob)) return ['Packing'];
        if (/FABRIC|FAB/.test(g)) return ['Fabric'];
        if (/LABEL|HANGTAG|CARE|STICKER|TAG/.test(blob) || trimFamily(blob) === 'LABEL') {
            return ['Trim', 'Packing', 'Special'];
        }
        if (/BUTTON|ELASTIC|THREAD|ZIPPER|CORD|ACCESSOR|TRIM/.test(blob)) {
            return ['Trim', 'Special'];
        }
        return ['Trim', 'Packing', 'Special'];
    }

    function lineSectionName(line) {
        return String(line?.section || line?.zsection || '').trim();
    }

    function matchRemarkForLine(r, line) {
        const matCode = String(r.material || '').trim().toUpperCase();
        const item = String(line.item || line.material || '').trim().toUpperCase();
        const poDesc = String(r.mat_desc || r.material_desc || '').trim().toUpperCase();
        const costDesc = String(line.maktx || line.description || '').trim().toUpperCase();
        const styles = styleCodesOverlap(poMatchText(r), costMatchText(line));
        if (styles.length) {
            return `Style code match (${styles[0]})`;
        }
        if (matCode && item && (matCode === item || item.includes(matCode) || matCode.includes(item))) {
            return 'Exact material code match';
        }
        const poKind = labelKind(poMatchText(r));
        const lineKind = labelKind(costMatchText(line));
        if (poKind && lineKind && poKind === lineKind) {
            return `${poKind} label kind match`;
        }
        if (poDesc && costDesc && (poDesc === costDesc || costDesc.includes(poDesc.slice(0, 24)) || poDesc.includes(costDesc.slice(0, 24)))) {
            return 'Exact description match';
        }
        if (poCostTokensOverlap(poMatchText(r), costMatchText(line))) {
            return 'Token / keyword match';
        }
        const sec = lineSectionName(line);
        return sec ? `Same section (${sec})` : 'Same material group section';
    }

    /**
     * Auto-map unmatched PO lines when a strong SIZE / style match exists
     * (e.g. 30SIZSTNSIZNTN#L-16 + VVVVSWH096 → SIZE LABEL: VVVVSWH096).
     */
    function autoMapUnmatchedFromCostLines() {
        if (!Array.isArray(costLines) || !costLines.length || !Array.isArray(rows)) return 0;
        let mapped = 0;
        rows.forEach((r) => {
            if (!r || isFooterRow(r) || r.manual_match) return;
            if (!isUnmatchedZcost(r) && (r.cost_item || r.maktx || r.budget_cost_per_gmt)) return;
            const candidates = candidateLinesForRow(r);
            if (!candidates.length) return;
            let best = null;
            let bestScore = -1;
            let second = -1;
            candidates.forEach((line) => {
                const sc = scoreCostLineForPo(r, line);
                if (sc > bestScore) {
                    second = bestScore;
                    bestScore = sc;
                    best = line;
                } else if (sc > second) {
                    second = sc;
                }
            });
            // Require strong unique match: style code and/or SIZE kind
            const styles = best ? styleCodesOverlap(poMatchText(r), costMatchText(best)) : [];
            const kindOk = best && (
                labelKind(poMatchText(r)) === 'SIZE'
                || labelKind(costMatchText(best)) === 'SIZE'
                || styles.length > 0
            );
            const unique = bestScore >= 150 || (bestScore >= 100 && bestScore - second >= 25);
            if (best && kindOk && unique && bestScore >= 100) {
                applyCostLineToRow(r, best);
                r.manual_match = false; // system auto-map, not user pick
                r.validation_result = `PASS — auto-matched ${matchRemarkForLine(r, best)}`;
                if (String(r.status || '').toUpperCase() === 'PASS') {
                    r.needs_manual_match = false;
                }
                mapped += 1;
            }
        });
        return mapped;
    }

    /** Active quotation for Map Costing — do not mix other quote numbers. */
    function quotationForRow(r) {
        const fromRow = String(
            r?.costing_quotation_no
            || r?.matched_quotation
            || r?.customer_quotation
            || r?.quotation
            || ''
        ).replace(/\D/g, '');
        const fromSelected = String(selectedCustomer || '').replace(/\D/g, '');
        if (fromSelected) return fromSelected;
        if (fromRow) return fromRow;
        if (Array.isArray(validatedQuotes) && validatedQuotes.length === 1) {
            return String(validatedQuotes[0] || '').replace(/\D/g, '');
        }
        // Prefer quotation already used on other matched rows of this PO
        for (const row of rows || []) {
            if (isFooterRow(row)) continue;
            const q = String(row.costing_quotation_no || row.matched_quotation || '').replace(/\D/g, '');
            if (q) return q;
        }
        return '';
    }

    function lineQuotationNo(line) {
        return String(
            line?.quotation_no
            || line?.costing_quotation_no
            || line?.parent_quotation_no
            || ''
        ).replace(/\D/g, '');
    }

    function candidateLinesForRow(r) {
        const mat = selectedMat();
        const matCode = String(r.material || '').trim().toLowerCase();
        const desc = String(r.mat_desc || r.material_desc || r.description || '').trim().toLowerCase();
        const group = String(r.matgroup || '').trim().toLowerCase();
        const poText = poMatchText(r);
        const poFamily = trimFamily(poText);
        const poKind = labelKind(poText);
        const wantSections = sectionsForPoRow(r).map((s) => s.toLowerCase());
        const packingOnly = wantSections.length === 1 && wantSections[0] === 'packing';
        const wantQuote = quotationForRow(r);
        const allowedQuotes = new Set(
            [wantQuote, ...(validatedQuotes || []).map((q) => String(q || '').replace(/\D/g, ''))]
                .filter(Boolean)
        );
        return (costLines || []).filter((line) => {
            // Only matching quotation (+ validated quotes for this PO) — never all quotes
            const lq = lineQuotationNo(line);
            if (wantQuote) {
                if (lq && lq !== wantQuote) return false;
            } else if (allowedQuotes.size > 0 && lq && !allowedQuotes.has(lq)) {
                return false;
            }
            if (mat === 'fabric' && String(line.section || '') !== 'Fabric' && line.mat_category !== 'fabric') {
                return false;
            }
            if (mat === 'trims' && String(line.section || '') === 'Fabric' && line.mat_category === 'fabric') {
                return false;
            }
            const sec = lineSectionName(line).toLowerCase();
            // PACKING1 / packing PO → Packing section of matching quotation only
            if (packingOnly) {
                return sec === 'packing';
            }
            if (wantSections.length && sec && !wantSections.includes(sec)) {
                // Allow exact material-code hits across section
                const item = String(line.item || line.material || '').toLowerCase();
                if (!(matCode && item && (item === matCode || item.includes(matCode)))) {
                    return false;
                }
            }
            const lineText = costMatchText(line);
            const lineFamily = trimFamily(lineText);
            // Only same family in dropdown (Label PO → Label lines only)
            if (poFamily && lineFamily && poFamily !== lineFamily) {
                return false;
            }
            if (poFamily && !lineFamily) {
                if (!poCostTokensOverlap(poText, lineText)) return false;
            }
            return true;
        }).sort((a, b) => scoreCostLineForPo(r, b) - scoreCostLineForPo(r, a));
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
            targetRow.manual_match = false;
            targetRow.needs_manual_match = true;
            targetRow.matched_line_key = '';
            targetRow._editingMatch = true;
            emptyZcostDisplayFields(targetRow);
            targetRow.status = 'NOT_MATCHED';
            targetRow.validation_result = 'NOT MATCHED — select matching zCOST line';
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
                const rt = lineBudgetPerGmt(line);
                matchedBox.innerHTML = `<strong>Matched PO Item #${esc(targetRow.poitem || targetRow.po_item || (idx + 1))}: ${itName}</strong>
                    <span>${maktx} ${rt !== null ? `· Budget: ₹${fmtStep(rt, 2)}` : ''}</span>`;
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
        // Classic portal / TEMPFIN values win — zCOST RATE must never replace BUDGET or ACTUAL
        const tempfinBudget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        // Apply Curr / Rate from matched zCOST line before computing INR actual
        if (line.currency) {
            r.budget_currency = String(line.currency);
            if (!r.po_currency || String(r.po_currency).toUpperCase() === 'INR') {
                // Keep PO currency when already foreign; otherwise adopt sheet Curr
                if (!r.po_currency) r.po_currency = String(line.currency);
            }
        }
        const lineConvr = nVal(line.convr) ?? nVal(r._po_budget_conv_rt);
        if (lineConvr !== null && lineConvr > 0) {
            // Curr Rate only — never copy FX into unit CONV (inflates ACTUAL_COST)
            r.budget_conv_rt = lineConvr;
        }
        if (nVal(r.conv) === null || nVal(r.conv) <= 0) {
            r.conv = 1;
        }
        if (!r.buom) {
            r.buom = String(line.unit || r._po_buom || '');
        }
        const lockedCons = !!r.tempfin_cons_locked || (nVal(r.consumption) !== null && nVal(r.consumption) > 0);
        const priorStatus = String(r.status || '').toUpperCase().replace(/\s+/g, '_');
        // BUDGET column = Total in INR ÷ Gross (preferred over TEMPFIN when zCOST matched)
        const rawLineBudget = lineBudgetPerGmt(line);
        const lineBudget = (rawLineBudget !== null && rawLineBudget > 0) ? rawLineBudget : null;
        const finalBudget = (lineBudget !== null && lineBudget > 0)
            ? lineBudget
            : ((tempfinBudget !== null && tempfinBudget > 0) ? tempfinBudget : null);

        // API ITEM only — never invent POSNR-ZLINE when ITEM is blank
        r.zcost_item = Object.prototype.hasOwnProperty.call(line, 'zcost_item')
            ? String(line.zcost_item || '')
            : String(line.item || line.costing_item_no || '');
        if (/^\d+-\d+$/.test(r.zcost_item)) {
            const soItem = String(line.so_item || '').trim();
            const lineNo = String(line.line_no || '').trim();
            if (soItem && lineNo && r.zcost_item === `${soItem}-${lineNo}`) {
                r.zcost_item = '';
            }
        }
        r.cost_item = r.zcost_item;
        r.costing_item_no = r.zcost_item;
        r.costing_quotation_no = String(
            line.costing_quotation_no || line.quotation_no || line.parent_quotation_no || ''
        );
        r.maktx = String(line.maktx || line.description || '');
        r.cost_description = r.maktx;
        r.cost_section = String(line.section || line.zsection || '');
        r.zsection = String(line.zsection || line.section || '');
        // Keep zCOST GROSS / TOTALINR for BUDGET (= Total INR ÷ Gross) — do NOT
        // overwrite PO CONSUMPTION (that inflated ACTUAL_COST).
        r.gross = nVal(line.gross) ?? r.gross;
        if (nVal(line.total_inr) !== null) {
            r.total_inr = nVal(line.total_inr);
            r.cost_total_inr = nVal(line.total_inr);
            r.budget_inr = nVal(line.total_inr);
        }
        // budgeted_cons for display may mirror gross, but keep PO consumption intact
        if (!lockedCons && nVal(line.gross) !== null && nVal(line.gross) > 0) {
            r.budgeted_cons = nVal(line.gross);
        }
        r.budget_currency = String(line.currency || r.budget_currency || 'INR');
        if (nVal(line.convr) !== null && nVal(line.convr) > 0) {
            r.budget_conv_rt = nVal(line.convr);
        }
        if (finalBudget !== null && finalBudget > 0) {
            r.budget_cost_per_gmt = finalBudget;
            r.expected_unit_cost = finalBudget;
        }
        // RESULT column: show zCOST Total INR (e.g. 2.5376), not per-GMT
        const totalInr = nVal(line.total_inr) ?? nVal(line.budget_inr) ?? nVal(line.cost_total_inr);
        if (totalInr !== null && totalInr > 0) {
            r.budget_cost_per_piece = Math.round(totalInr * 10000) / 10000;
            r.total_inr = totalInr;
            r.cost_total_inr = totalInr;
            r.budget_inr = totalInr;
        } else {
            const perPiece = budgetCostPerPiece(r, line);
            if (perPiece !== null && perPiece > 0) {
                r.budget_cost_per_piece = perPiece;
            }
        }
        r.tempfin_budget_locked = false; // matched zCOST TotalINR/Gross is source of truth
        // Keep TEMPFIN BUOM — do not replace with zCOST unit
        if (!r.buom) {
            r.buom = String(line.unit || '');
        }
        // ACTUAL = PORATE × Curr Rate × PO consumption (never zCOST Gross)
        const inrActual = actualCostInr(r);
        if (inrActual !== null) {
            r.actual_cost = inrActual;
            r.po_unit_cost = inrActual;
        }
        let variancePct = null;
        let status = priorStatus || 'PASS';
        let result = String(r.validation_result || 'PASS — manually matched to zCOST line');
        if (finalBudget !== null && finalBudget > 0 && inrActual !== null) {
            variancePct = roundPct(((inrActual - finalBudget) / finalBudget) * 100);
            if (inrActual <= finalBudget + 0.005) {
                status = 'PASS';
                result = 'PASS — manually matched to zCOST line';
            } else {
                status = 'EXCEPTION';
                result = 'NOT VALIDATED — actual cost exceeds Total INR / Gross budget';
            }
        } else if (finalBudget !== null && finalBudget > 0) {
            status = 'PASS';
            result = 'PASS — manually matched to zCOST line';
        } else {
            status = 'NEEDS_REVIEW';
            result = 'Matched zCOST line but Total INR / Gross budget is zero — Needs Review';
        }
        r.variance_pct = variancePct;
        r.status = status;
        r.validation_result = result;
        r.cost_matrix = {
            budget: finalBudget,
            actual: inrActual,
            status,
        };
        r.manual_match = true;
        r.family_mismatch = false;
        r.needs_manual_match = !(finalBudget !== null && finalBudget > 0 && inrActual !== null);
        r._editingMatch = false;
        r._zcost_pending = false;
        r.matched_quotation = String(line.quotation_no || line.costing_quotation_no || '');
        r.matched_line_no = String(line.line_no || '');
        // Fill costing display fields from selected line (were blank while unmatched)
        if (!r.budget_currency) {
            r.budget_currency = String(line.currency || r.po_currency || 'INR');
        }
        if (!r.buom && line.unit) {
            r.buom = String(line.unit);
        }
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
        const validateBtn = $('pcwOpenValidateBtn');
        if (validateBtn) {
            validateBtn.disabled = !(headerPo() && rows.some((r) => !isFooterRow(r)));
        }
        const selectSameBtn = $('pcwSelectSameBtn');
        if (selectSameBtn) selectSameBtn.style.display = 'none';
    }

    function openMatchPicker(index) {
        const r = rows[index];
        if (!r) return;
        matchRowIndex = index;
        // Keep current mapping selected when reopening Change
        matchPickKey = String(r.matched_line_key || '');
        const pop = $('pcwMatchPop');
        const title = $('pcwMatchTitle');
        const desc = $('pcwMatchDesc');
        const search = $('pcwMatchSearch');
        const alreadyMapped = !!(r.manual_match || r.matched_line_key || r.cost_item || r.maktx);
        if (title) title.textContent = alreadyMapped ? 'Change Costing Mapping' : 'Manual Costing Mapping';
        const sections = sectionsForPoRow(r);
        const sectionHint = sections.length === 1 ? sections[0] : sections.join(' / ');
        if (desc) {
            desc.textContent = alreadyMapped
                ? `PO Item ${r.poitem || r.po_item || '—'} · ${r.matgroup || '—'} — already mapped; pick a different ${sectionHint} line if wrong.`
                : `PO Item ${r.poitem || r.po_item || '—'} · ${r.matgroup || '—'} — showing ${sectionHint} zCOST lines only.`;
        }
        const meta = $('pcwMatchMeta');
        if (meta) {
            const quote = quotationForRow(r) || String(r.costing_quotation_no || r.matched_quotation || selectedCustomer || '').trim();
            // Exactly 2 rows × 4 columns
            meta.innerHTML = `
                <div><small>PO Item</small><b>${esc(r.poitem || r.po_item || '—')}</b></div>
                <div><small>Material Code</small><b title="${esc(r.material || '')}">${esc(r.material || '—')}</b></div>
                <div class="is-wide"><small>PO Description</small><b title="${esc(r.mat_desc || r.material_desc || '')}">${esc(r.mat_desc || r.material_desc || '—')}</b></div>
                <div><small>SO / Style</small><b title="${esc((r.salordno || r.sales_order || '') + ' / ' + (r.style || ''))}">${esc(r.salordno || r.sales_order || '—')} / ${esc(r.style || '—')}</b></div>
                <div><small>PO Qty</small><b>${esc(qty(r.tot_poqty || r.order_qty || r.so_qty || 0))} ${esc(r.ouom || r.buom || '')}</b></div>
                <div><small>PO Price</small><b>${esc(fmtStep(r.porate ?? r.net_price, 4))} ${esc(r.po_currency || r.currency || '')}</b></div>
                <div><small>Mat Group / Section</small><b>${esc(r.matgroup || '—')} · <span class="pcw-match-sec-filter">${esc(sectionHint)}</span></b></div>
            `;
            const tableTitle = $('pcwMatchTableTitle');
            if (tableTitle) {
                tableTitle.textContent = quote
                    ? `Select Matching ${sectionHint} Row · Quotation ${quote}`
                    : `Select Matching ${sectionHint} Costing Row`;
            }
        }
        // Remap without blanking the current mapping until Apply
        r._matchEditSnapshot = {
            editing: !!r._editingMatch,
            needs: !!r.needs_manual_match,
        };
        // Only blank costing cells for first-time Map Costing, not Change
        if (!alreadyMapped) {
            r._editingMatch = true;
            r.needs_manual_match = true;
        }

        if (search) search.value = '';
        renderMatchHistory(r);
        renderMatchList();
        // If prior key not in list, try match by cost item / description
        if (!matchPickKey && alreadyMapped) {
            const lines = $('pcwMatchList')?._pcwLines || [];
            const hit = lines.findIndex((line) => {
                const item = String(line.item || line.material || '');
                const descL = String(line.maktx || line.description || '');
                return (r.cost_item && item && item === String(r.cost_item))
                    || (r.costing_item_no && item && item === String(r.costing_item_no))
                    || (r.maktx && descL && descL === String(r.maktx));
            });
            if (hit >= 0) {
                matchPickKey = lineKey(lines[hit], hit);
                renderMatchList();
            }
        }
        if (pop) {
            pop.hidden = false;
            pop.setAttribute('aria-hidden', 'false');
            requestAnimationFrame(() => pop.classList.add('is-open'));
        }
        document.body.classList.add('pcw-match-offcanvas-open');
        $('pcwMatchApply') && ($('pcwMatchApply').disabled = !matchPickKey);
        search?.focus();
    }

    function closeMatchPicker() {
        // Cancel Change: restore prior flags so already-mapped row stays mapped
        if (matchRowIndex !== null && rows[matchRowIndex]) {
            const r = rows[matchRowIndex];
            const snap = r._matchEditSnapshot;
            if (snap) {
                r._editingMatch = !!snap.editing;
                r.needs_manual_match = !!snap.needs;
                delete r._matchEditSnapshot;
            } else if (r.manual_match || r.matched_line_key || r.cost_item || r.maktx) {
                r._editingMatch = false;
            }
        }
        const pop = $('pcwMatchPop');
        if (pop) {
            pop.classList.remove('is-open');
            pop.setAttribute('aria-hidden', 'true');
            window.setTimeout(() => {
                if (!pop.classList.contains('is-open')) pop.hidden = true;
            }, 280);
        }
        document.body.classList.remove('pcw-match-offcanvas-open');
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
            const secHint = sectionsForPoRow(r || {}).join(' / ') || 'matching';
            list.innerHTML = `<div class="pcw-match-empty">
                <span class="material-icons-round" style="font-size:32px; color:#f59e0b; display:block; margin-bottom:6px;">search_off</span>
                <p style="margin:0 0 10px; font-weight:700; color:#334155;">
                    ${costLines.length
                        ? `No ${esc(secHint)} zCOST lines match this filter.`
                        : 'No zCOST candidates automatically found for this PO.'}
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
        const header = `<div class="pcw-match-list-head" aria-hidden="true">
            <span class="pcw-mcol-pick"></span>
            <span class="pcw-mcol-quote">Quotation</span>
            <span class="pcw-mcol-item">ZCOST Item</span>
            <span class="pcw-mcol-section">Section</span>
            <span class="pcw-mcol-desc">Costing Description</span>
            <span class="pcw-mcol-total">Total INR</span>
            <span class="pcw-mcol-gross">Gross</span>
            <span class="pcw-mcol-uom">UOM</span>
            <span class="pcw-mcol-budget">Budget / GMT</span>
            <span class="pcw-mcol-remark">Remarks</span>
        </div>`;
        const cards = lines
            .slice(0, 120)
            .map((line, i) => {
                const key = lineKey(line, i);
                const checked = key === matchPickKey ? ' checked' : '';
                const rate = lineBudgetPerGmt(line);
                const section = lineSectionName(line) || '—';
                const quote = String(line.quotation_no || line.costing_quotation_no || line.parent_quotation_no || '—');
                const item = String(line.item || line.material || '—');
                const descTxt = String(line.maktx || line.description || '—');
                const totInr = nVal(line.total_inr);
                const gross = nVal(line.gross);
                const uom = String(line.unit || line.buom || line.uom || '—');
                const curr = String(line.currency || 'INR').toUpperCase() === 'IND' ? 'INR' : String(line.currency || 'INR');
                const remark = matchRemarkForLine(r, line);
                const exact = /exact/i.test(remark);
                return `<label class="pcw-match-option${checked ? ' is-active' : ''}${exact ? ' is-exact' : ''}" data-key="${esc(key)}">
                    <span class="pcw-mcol-pick">
                        <input type="radio" name="pcwMatchPick" value="${esc(key)}"${checked}>
                    </span>
                    <span class="pcw-mcol-quote" title="${esc(quote)}">${esc(quote)}</span>
                    <span class="pcw-mcol-item" title="${esc(item)}">${esc(item)}</span>
                    <span class="pcw-mcol-section"><span class="pcw-nav-chip is-sec-${esc(section.toLowerCase())}">${esc(section)}</span></span>
                    <span class="pcw-mcol-desc" title="${esc(descTxt)}">${esc(descTxt)}</span>
                    <span class="pcw-mcol-total">${totInr !== null ? esc(fmtStep(totInr, 4)) : '—'}</span>
                    <span class="pcw-mcol-gross">${gross !== null ? esc(fmtStep(gross, 4)) : '—'}</span>
                    <span class="pcw-mcol-uom">${esc(uom)}</span>
                    <span class="pcw-mcol-budget">
                        <b>${rate !== null ? esc(fmtStep(rate, 4)) : '—'}</b>
                        <small>${esc(curr)}</small>
                    </span>
                    <span class="pcw-mcol-remark">${esc(remark)}</span>
                </label>`;
            })
            .join('');
        list.innerHTML = header + cards;
        // stash lines on list for apply by key
        list._pcwLines = lines.slice(0, 120);
    }

    function renderMatchHistory(r) {
        const box = $('pcwMatchHistory');
        if (!box) return;
        const mat = String(r?.material || '').trim().toLowerCase();
        const hist = (rows || []).filter((row) => {
            if (isFooterRow(row) || isUnmatchedZcost(row)) return false;
            if (!row.manual_match && !row.matched_line_key) return false;
            if (!mat) return !!(row.cost_item || row.maktx);
            return String(row.material || '').trim().toLowerCase() === mat;
        }).slice(0, 8);
        if (!hist.length) {
            box.innerHTML = '<p class="pcw-match-history-empty">No saved mappings for this material yet.</p>';
            return;
        }
        box.innerHTML = `
            <div class="pcw-match-hist-table">
                <div class="pcw-match-hist-head">
                    <span>Material</span><span>Quotation</span><span>zCOST</span><span>Description</span>
                </div>
                ${hist.map((row) => `
                    <div class="pcw-match-hist-row">
                        <span title="${esc(row.material || '')}">${esc(row.material || '—')}</span>
                        <span>${esc(row.costing_quotation_no || '—')}</span>
                        <span>${esc(row.cost_item || row.costing_item_no || '—')}</span>
                        <span title="${esc(row.maktx || row.cost_description || '')}">${esc(row.maktx || row.cost_description || '—')}</span>
                    </div>
                `).join('')}
            </div>`;
    }

    function cellValue(key, r, index) {
        switch (key) {
            case 'sno':
                return String(index + 1);
            case 'status':
                return statusDisplay(r).label;
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
            case 'so_qty': {
                const so = nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.SaleOrderQuantity);
                return so;
            }
            case 'prwise_so_qty': {
                const pr = nVal(r.prwise_so_qty) ?? nVal(r.so_qty) ?? nVal(r.SaleOrderQuantity);
                return pr;
            }
            case 'consumption': {
                // Prefer TEMPFIN CONSUMPTION / BUDGETED_CONS — never invent REQQTY÷SO (wrong vs Excel)
                const cons = nVal(r.consumption);
                if (cons !== null && cons > 0) return cons;
                const bc = nVal(r.budgeted_cons);
                if (bc !== null && bc > 0) return bc;
                return null;
            }
            case 'reqqty':
                return r.reqqty ?? r.ReqBOMQuantity;
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
                return r.mat_desc || r.material_desc || '';
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
            case 'zsection':
                return r.zsection || r.cost_section || r.section || '';
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
                // Never fall back to zCOST RATE — that showed 15.9 instead of TEMPFIN budget
                return r.budget_cost_per_gmt ?? r.expected_unit_cost;
            case 'budget_cost_per_piece':
                return budgetCostPerPiece(r);
            case 'conv':
                return r.conv ?? r.convr ?? 1;
            case 'buom':
                return r.buom || r.ouom || r.unit || '';
            case 'actual_cost':
                // INR actual (PORATE × Curr Rate) — never show raw foreign PORATE
                return actualCostInr(r);
            case 'variance_pct':
                return r.variance_pct;
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
                let label = 'PO Data';
                let icon = 'receipt_long';
                let cls = 'is-po';
                if (run.group === 'zcost') {
                    label = 'Costing Data';
                    icon = 'calculate';
                    cls = 'is-zcost';
                } else if (run.group === 'result') {
                    label = 'Result';
                    icon = 'fact_check';
                    cls = 'is-result';
                }
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
        return `<button type="button" class="pcw-formula-cell" data-explain="${esc(key)}" data-idx="${index}" title="Show API column">${displayHtml}</button>`;
    }

    function renderCell(key, r, index) {
        const group = colGroup(key);
        const classes = [];
        if (NUM_COLS.has(key) || key === 'sno') classes.push('num');
        if (key === 'sno') classes.push('sno');
        classes.push(`pcw-col-${group}`);
        if (HIGHLIGHT_COLS.has(key) && !isFooterRow(r)) classes.push('pcw-hl-cost');
        if (EXPLAIN_COLS.has(key) && !isFooterRow(r)) classes.push('is-explainable');
        const cls = classes.length ? ` class="${classes.join(' ')}"` : '';
        let inner = '—';

        // Classic PASSPO footer / charge / tax rows
        if (isFooterRow(r)) {
            if (key === 'pono') {
                inner = `<strong class="pcw-footer-label">${esc(r.pono || r.matgroup || r.validation_result || '—')}</strong>`;
            } else if (key === 'status') {
                inner = '';
            } else if (r.row_kind === 'charge' || r.row_kind === 'tax') {
                inner = '';
            } else if (FOOTER_SUM_KEYS.includes(key)) {
                const v = cellValue(key, r, index);
                if (key === 'so_wise_po_val' || key === 'totvalue' || key === 'budget_inr') {
                    inner = money(v);
                } else if (key === 'actual_cost' || key === 'budget_cost_per_gmt' || key === 'consumption' || key === 'conv' || key === 'budgeted_cons') {
                    inner = num(v, 4);
                } else {
                    inner = qty(v);
                }
            } else {
                inner = '';
            }
            return `<td${cls} data-col-id="${esc(key)}" data-col-group="${esc(group)}">${inner}</td>`;
        }

        // Unmatched: blank Costing + Result cells (Map Costing opens picker)
        if (COSTING_BLANK_COLS.has(key) && isUnmatchedZcost(r)) {
            return `<td${cls} data-col-id="${esc(key)}" data-col-group="${esc(group)}">—</td>`;
        }

        switch (key) {
            case 'status': {
                // Status column = status pill only
                const pill = `<button type="button" class="pcw-formula-cell pcw-status-reason-btn" data-explain="status" data-idx="${index}" title="Why this status?">
                    ${statusPill(r.status, r)}
                </button>`;
                inner = `<div class="pcw-status-cell">${pill}</div>`;
                break;
            }
            case 'actions': {
                // Unmatched → Map Costing
                // Map Costing rows (manual_match) or still-wrong → Change + View (reopen picker)
                // Auto-matched Passed → View only
                if (isFooterRow(r)) {
                    inner = '';
                    break;
                }
                const unmatchedA = isUnmatchedZcost(r) || needsManualMatch(r);
                const stA = statusDisplay(r).code;
                const canChangeA = !unmatchedA && (
                    !!r.manual_match
                    || stA === 'NOT_VALIDATED'
                    || stA === 'NEEDS_REVIEW'
                    || !!r.cost_mismatch
                );
                if (unmatchedA) {
                    inner = `<button type="button" class="pcw-match-btn" data-idx="${index}" title="Select matching zCOST line">
                        <span class="material-icons-round">link</span> Map Costing
                    </button>`;
                } else if (canChangeA) {
                    inner = `<div class="pcw-action-btns">
                        <button type="button" class="pcw-match-btn is-change" data-idx="${index}" title="Change this Map Costing selection">
                            <span class="material-icons-round">edit</span> Change
                        </button>
                        <button type="button" class="pcw-view-btn pcw-formula-cell" data-explain="actual_cost" data-idx="${index}" title="View cost details">
                            <span class="material-icons-round">visibility</span> View
                        </button>
                    </div>`;
                } else {
                    inner = `<button type="button" class="pcw-view-btn pcw-formula-cell" data-explain="status" data-idx="${index}" title="View status details">
                        <span class="material-icons-round">visibility</span> View
                    </button>`;
                }
                break;
            }
            case 'pono':
                inner = wrapExplain(key, index, `<span class="pcw-mat">${dash(r.pono || r.purchase_order)}</span>`);
                break;
            case 'material':
                inner = wrapExplain(key, index, `<span class="pcw-mat">${dash(r.material)}</span>`);
                break;
            case 'mat_desc':
                inner = wrapExplain(
                    key,
                    index,
                    `<span class="pcw-compare-po" title="${esc(r.mat_desc || r.material_desc || '')}">${dash(r.mat_desc || r.material_desc)}</span>`
                );
                break;
            case 'costing_quotation_no':
                if (isFooterRow(r) || isUnmatchedZcost(r)) {
                    inner = '—';
                } else {
                    // Quotation stays a link to sap_reports/zcost (not API popover)
                    inner = quotationLinkHtml(
                        cellValue(key, r, index),
                        cellValue('costing_item_no', r, index)
                    );
                }
                break;
            case 'costing_item_no':
                if (isFooterRow(r) || isUnmatchedZcost(r)) {
                    inner = '—';
                } else {
                    inner = wrapExplain(
                        key,
                        index,
                        `<span class="pcw-nav-chip" title="zCOST ITEM">${dash(cellValue(key, r, index))}</span>`
                    );
                }
                break;
            case 'zsection':
                if (isFooterRow(r) || isUnmatchedZcost(r)) {
                    inner = '—';
                } else {
                    inner = wrapExplain(
                        key,
                        index,
                        `<span class="pcw-nav-chip" title="ZSECTION">${dash(cellValue(key, r, index))}</span>`
                    );
                }
                break;
            case 'maktx':
                if (isFooterRow(r)) {
                    inner = '—';
                } else {
                    // Classic UI: unmatched stays blank — use Actions → Map Costing
                    inner = wrapExplain(
                        key,
                        index,
                        `<span class="pcw-compare-maktx" title="${esc(r.maktx || r.cost_description || '')}">${dash(r.maktx || r.cost_description)}</span>`
                    );
                }
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
            case 'budget_cost_per_piece':
            case 'conv':
            case 'actual_cost': {
                const mismatch = !!r.cost_mismatch && (key === 'budget_cost_per_gmt' || key === 'budget_cost_per_piece' || key === 'actual_cost');
                const shown = wrapExplain(key, index, num(cellValue(key, r, index), 4));
                inner = mismatch ? `<span class="pcw-cost-mismatch">${shown}</span>` : shown;
                break;
            }
            case 'variance_pct':
                inner = wrapExplain(key, index, varianceCell(cellValue(key, r, index)));
                break;
            case 'poitem':
            case 'prno':
            case 'salordno':
            case 'style':
            case 'matgroup':
                inner = wrapExplain(key, index, dash(cellValue(key, r, index)));
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
        if (tableStatusFilter) {
            if (statusDisplay(r).code !== tableStatusFilter) return false;
        }
        if (query) {
            const blob = COLS.map((k) => String(cellValue(k, r, index) ?? '')).join(' ').toLowerCase();
            if (!blob.includes(query)) return false;
        }
        for (const f of filters) {
            const val = cellValue(f.key, r, index);
            if (f.key === 'status') {
                const label = statusDisplay(r).label.toLowerCase();
                const code = statusDisplay(r).code.toLowerCase();
                if (!label.includes(f.raw) && !code.includes(f.raw)) return false;
                continue;
            }
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
        const matchedDetails = sortedRows(
            indexed.filter(({ row, index }) => !isFooterRow(row) && matchRow(row, index, query, filters))
        );
        const matched = appendFooterBlock(matchedDetails);

        const noMatch = $('rptNoMatchRow');
        const countEl = $('rptTableCount');
        const badge = $('rptActiveFilterBadge');
        const clearBtn = $('rptClearAllFilters');
        const isFiltered = query !== '' || filters.length > 0 || !!tableStatusFilter;
        const detailCount = matchedDetails.length;
        const detailTotal = rows.filter((r) => !isFooterRow(r)).length;

        if (countEl) {
            const statusHint = tableStatusFilter ? ` · ${statusFilterLabel(tableStatusFilter)}` : '';
            countEl.textContent = `Showing ${detailCount} of ${detailTotal} items`
                + (isFiltered ? ` (filtered${statusHint})` : '');
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
            updateTableFooter([]);
            colFeatures?.refresh?.();
            return;
        }

        if (!matchedDetails.length) {
            tbody.querySelectorAll('tr.pcw-row').forEach((tr) => tr.remove());
            if (noMatch) {
                if (!tbody.contains(noMatch)) tbody.appendChild(noMatch);
                noMatch.style.display = '';
            }
            updateTableFooter([]);
            colFeatures?.refresh?.();
            return;
        }

        if (noMatch) noMatch.style.display = 'none';
        tbody.innerHTML = matched
            .map(({ row, index }) => {
                const kind = String(row.row_kind || 'detail');
                const footerLabel = footerLabelOf(row).replace(/\s+/g, '');
                const isTotal = kind === 'total' || footerLabel === 'TOTAL';
                if (isFooterRow(row)) {
                    const footer = ` is-footer-row is-${esc(kind)}${isTotal ? ' is-total' : ''}`;
                    return `<tr class="pcw-row${footer}" data-idx="${index}" data-row-kind="${esc(kind)}">${COLS.map((k) => renderCell(k, row, index)).join('')}</tr>`;
                }
                const disp = statusDisplay(row);
                const st = disp.code;
                const open = needsDropdown(row) ? ' is-open' : '';
                const mapping = st === 'NOT_MATCHED' || isUnmatchedZcost(row);
                const mismatch = !mapping && (st === 'NOT_VALIDATED' || (!!row.cost_mismatch && st !== 'PASS')
                    || st === 'EXCEPTION' || st === 'FAIL');
                const ambiguous = !mapping && !mismatch && st === 'NEEDS_REVIEW';
                // Cell colors ONLY on BUDGET/ACTUAL: green=pass, yellow=mapping/ambiguous, red=cost mismatch
                const pass = st === 'PASS' ? ' is-pass-row' : '';
                const review = (mapping || ambiguous) ? ' is-review-row' : '';
                const fail = mismatch ? ' is-fail-row is-cost-mismatch' : '';
                const unmatchedCls = mapping ? ' is-unmatched-row' : '';
                const sel = row.selected ? ' is-selected' : '';
                return `<tr class="pcw-row${open}${pass}${review}${fail}${unmatchedCls}${sel}" data-idx="${index}" data-row-kind="${esc(kind)}">${COLS.map((k) => renderCell(k, row, index)).join('')}</tr>`;
            })
            .join('');
        if (noMatch) tbody.appendChild(noMatch);
        colFeatures?.refresh?.();
        updateTableFooter(matchedDetails.map(({ row }) => row));
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
                // First load: zCOST section/matrix vs PO matgroup + mat_desc
                enforceFirstLoadZcostMatch(r);
                // Unmatched → keep costing cells empty (no ACTUAL/BUDGET until dropdown select)
                if (isUnmatchedZcost(r)) {
                    emptyZcostDisplayFields(r);
                    r.status = 'NOT_MATCHED';
                    return;
                }
                // Normalize ACTUAL_COST to INR via Curr × Rate (matched rows only)
                const inr = actualCostInr(r);
                if (inr !== null) {
                    r.actual_cost = inr;
                    r.po_unit_cost = inr;
                    const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
                    if (budget !== null && budget > 0) {
                        r.variance_pct = Math.round((((inr - budget) / budget) * 100) * 100) / 100;
                        if (!r.family_mismatch && !r.needs_manual_match) {
                            if (inr <= budget + 0.005) {
                                r.status = 'PASS';
                                r.validation_result = r.validation_result && /family|does not match/i.test(String(r.validation_result))
                                    ? r.validation_result
                                    : 'PASS — actual cost within budget';
                                r.cost_mismatch = false;
                            } else if (String(r.status || '').toUpperCase() !== 'NEEDS_REVIEW') {
                                r.status = 'EXCEPTION';
                                r.cost_mismatch = true;
                            }
                        }
                    }
                }
            });

            // Auto-map SIZE LABEL / style codes (VVVVSWH096 ↔ 30SIZSTNSIZNTN#L-16)
            autoMapUnmatchedFromCostLines();
            rows.forEach((r) => {
                if (isFooterRow(r) || isUnmatchedZcost(r)) return;
                const inr = actualCostInr(r);
                if (inr === null) return;
                r.actual_cost = inr;
                r.po_unit_cost = inr;
                const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
                if (budget !== null && budget > 0 && !r.family_mismatch) {
                    r.variance_pct = Math.round((((inr - budget) / budget) * 100) * 100) / 100;
                    if (inr <= budget + 0.005) {
                        r.status = 'PASS';
                        r.cost_mismatch = false;
                        r.needs_manual_match = false;
                    }
                }
            });

            applyApprovedStatus(po, data.approved_status || 'NotApproved');
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
            // First-time PO search: ensure local PDF (SAP only if missing; never overwrite signed)
            void ensureLocalPoPdfCache(po);
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

    function openActionOffcanvas(shellId) {
        const shell = $(shellId);
        if (!shell) return;
        shell.hidden = false;
        shell.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pcw-action-offcanvas-open');
        requestAnimationFrame(() => {
            shell.classList.add('is-open');
        });
    }

    function closeActionOffcanvas(shellId) {
        const shell = $(shellId);
        if (!shell) return;
        shell.classList.remove('is-open');
        shell.setAttribute('aria-hidden', 'true');
        const stillOpen = ['pcwValidateModal'].some((id) => {
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

    function avatarLetter(name) {
        const s = String(name || '').trim();
        return (s ? s.charAt(0) : '?').toUpperCase();
    }

    function formatActivityWhen(raw) {
        const whenFull = String(raw || '').replace('T', ' ').slice(0, 19);
        if (!whenFull) return '—';
        try {
            const d = new Date(whenFull.replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return whenFull;
            return d.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch (_) {
            return whenFull;
        }
    }

    function groupValidationLogs(logs) {
        const grouped = [];
        const seen = new Map();
        (logs || []).forEach((log) => {
            const when = String(log.created_at || '').replace('T', ' ').slice(0, 19);
            const key = [
                when,
                String(log.created_by || ''),
                String(log.assigned_username || ''),
                String(log.validation_status || ''),
                String(log.description || ''),
            ].join('|');
            if (seen.has(key)) {
                seen.get(key).item_count += 1;
                return;
            }
            const entry = { ...log, when, item_count: 1 };
            seen.set(key, entry);
            grouped.push(entry);
        });
        return grouped;
    }

    function renderActivityFeed(grouped, po) {
        const body = $('pcwHistoryBody');
        const countEl = $('pcwHistoryCount');
        if (!body) return;
        if (countEl) {
            countEl.textContent = grouped.length
                ? `${grouped.length} activit${grouped.length === 1 ? 'y' : 'ies'}`
                : '0 activities';
        }
        if (!grouped.length) {
            body.innerHTML = `<div class="pcw-history-empty">No comments or activity yet.</div>`;
            return;
        }
        body.innerHTML = grouped
            .map((log) => {
                const st = String(log.validation_status || '');
                const byUser = String(log.created_by || '').trim() || '—';
                const assigned = String(log.assigned_username || '').trim();
                const remarks = String(log.description || '').trim();
                let action = 'updated this PO';
                if (st === 'COMMENT') {
                    action = 'added a comment';
                } else if (st === 'VALIDATE_PO') {
                    action = `validated this PO${assigned ? ` and assigned <strong>${esc(assigned)}</strong>` : ''}`;
                } else if (st === 'NOT_VALIDATE') {
                    action = `marked Not Validate${assigned ? ` and assigned <strong>${esc(assigned)}</strong>` : ''}`;
                }
                const showCommentBox = st === 'COMMENT' || !!remarks;
                return `<article class="pcw-activity-item" role="listitem">
                    <span class="pcw-activity-avatar" aria-hidden="true">${esc(avatarLetter(byUser))}</span>
                    <div class="pcw-activity-body">
                        <div class="pcw-activity-line">
                            <strong>${esc(byUser)}</strong> ${action}
                        </div>
                        ${showCommentBox && remarks ? `<div class="pcw-activity-comment">${formatCommentHtml(remarks)}</div>` : ''}
                        <time class="pcw-activity-when">${esc(formatActivityWhen(log.when || log.created_at))}</time>
                    </div>
                </article>`;
            })
            .join('');
    }

    async function loadActivityFeed(po) {
        const body = $('pcwHistoryBody');
        const countEl = $('pcwHistoryCount');
        if (!po) {
            if (countEl) countEl.textContent = '—';
            if (body) body.innerHTML = `<div class="pcw-history-empty">Load a PO to see activity.</div>`;
            return;
        }
        if (countEl) countEl.textContent = 'Loading…';
        if (body) body.innerHTML = `<div class="pcw-history-empty">Loading activity…</div>`;
        const endpoint = cfg.validationLogUrl || 'purchase-order/validation-log';
        try {
            const res = await fetch(`${endpoint}?po=${encodeURIComponent(po)}&_=${Date.now()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const rawText = await res.text();
            let json = {};
            try {
                json = rawText ? JSON.parse(rawText) : {};
            } catch (_) {
                throw new Error(
                    rawText
                        ? `Activity returned non-JSON (HTTP ${res.status})`
                        : `Activity returned empty response (HTTP ${res.status})`
                );
            }
            const logs = Array.isArray(json?.data?.logs) ? json.data.logs : [];
            renderActivityFeed(groupValidationLogs(logs), po);
        } catch (err) {
            if (countEl) countEl.textContent = 'Error';
            if (body) {
                body.innerHTML = `<div class="pcw-history-empty">${esc(err.message || 'Failed to load activity')}</div>`;
            }
        }
    }

    function setComposeOpen(open) {
        const box = $('pcwActivityCompose');
        const actions = $('pcwActivityComposeActions');
        const ta = $('pcwValidateRemarks');
        box?.classList.toggle('is-open', !!open);
        if (actions) actions.hidden = !open;
        if (!open) hideMentionMenu();
        if (ta && !open) {
            ta.value = '';
            ta.style.height = '';
            ta.blur();
        }
    }

    function autoSizeComment() {
        const ta = $('pcwValidateRemarks');
        if (!ta) return;
        ta.style.height = 'auto';
        ta.style.height = `${Math.min(180, Math.max(40, ta.scrollHeight))}px`;
    }

    function formatCommentHtml(text) {
        return esc(text).replace(/@([A-Za-z0-9._-]+)/g, '<span class="pcw-mention">@$1</span>');
    }

    function extractMentionsFromText(text) {
        const out = [];
        const seen = new Set();
        String(text || '').replace(/@([A-Za-z0-9._-]+)/g, (_, name) => {
            const key = String(name).toLowerCase();
            if (!seen.has(key)) {
                seen.add(key);
                out.push(name);
            }
            return _;
        });
        return out;
    }

    function getMentionContext(ta) {
        if (!ta) return null;
        const pos = ta.selectionStart ?? 0;
        const before = String(ta.value || '').slice(0, pos);
        const m = before.match(/(^|[\s([{])@([A-Za-z0-9._-]*)$/);
        if (!m) return null;
        const query = m[2] || '';
        const start = pos - query.length - 1;
        return { start, end: pos, query };
    }

    let mentionTimer = null;
    let mentionUsers = [];
    let mentionActiveIdx = -1;

    function hideMentionMenu() {
        const menu = $('pcwMentionMenu');
        if (menu) {
            menu.hidden = true;
            menu.innerHTML = '';
        }
        mentionUsers = [];
        mentionActiveIdx = -1;
    }

    function renderMentionMenu(users) {
        const menu = $('pcwMentionMenu');
        if (!menu) return;
        mentionUsers = users || [];
        if (!mentionUsers.length) {
            menu.innerHTML = `<div class="pcw-mention-empty">No users found</div>`;
            menu.hidden = false;
            mentionActiveIdx = -1;
            return;
        }
        mentionActiveIdx = 0;
        menu.innerHTML = mentionUsers
            .map((u, i) => {
                const initial = avatarLetter(u.username || '?');
                return `<button type="button" class="pcw-mention-option${i === 0 ? ' is-active' : ''}" role="option"
                    data-idx="${i}" data-id="${esc(u.id)}" data-username="${esc(u.username)}" data-email="${esc(u.email || '')}">
                    <span class="pcw-activity-avatar" aria-hidden="true">${esc(initial)}</span>
                    <span class="pcw-mention-meta">
                        <strong>@${esc(u.username)}</strong>
                        <small>${esc([u.email, u.department, u.role].filter(Boolean).join(' · ') || '—')}</small>
                    </span>
                </button>`;
            })
            .join('');
        menu.hidden = false;
    }

    async function searchMentionUsers(q) {
        const endpoint = cfg.usersUrl || 'purchase-order/users';
        const params = new URLSearchParams({ q: q || '', limit: '8', _: String(Date.now()) });
        try {
            const res = await fetch(`${endpoint}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            const users = Array.isArray(json?.data?.users) ? json.data.users : [];
            if (!getMentionContext($('pcwValidateRemarks'))) {
                hideMentionMenu();
                return;
            }
            renderMentionMenu(users);
        } catch (_) {
            renderMentionMenu([]);
        }
    }

    function scheduleMentionLookup() {
        const ta = $('pcwValidateRemarks');
        const ctx = getMentionContext(ta);
        if (!ctx) {
            hideMentionMenu();
            return;
        }
        clearTimeout(mentionTimer);
        mentionTimer = setTimeout(() => {
            void searchMentionUsers(ctx.query);
        }, 120);
    }

    function insertMention(username) {
        const ta = $('pcwValidateRemarks');
        if (!ta || !username) return;
        const ctx = getMentionContext(ta);
        const value = String(ta.value || '');
        const start = ctx ? ctx.start : ta.selectionStart;
        const end = ctx ? ctx.end : ta.selectionStart;
        const insert = `@${username} `;
        ta.value = value.slice(0, start) + insert + value.slice(end);
        const caret = start + insert.length;
        ta.focus();
        ta.setSelectionRange(caret, caret);
        hideMentionMenu();
        setComposeOpen(true);
        autoSizeComment();
    }

    function moveMentionActive(delta) {
        if (!mentionUsers.length) return;
        const menu = $('pcwMentionMenu');
        if (!menu || menu.hidden) return;
        mentionActiveIdx = (mentionActiveIdx + delta + mentionUsers.length) % mentionUsers.length;
        menu.querySelectorAll('.pcw-mention-option').forEach((el, i) => {
            el.classList.toggle('is-active', i === mentionActiveIdx);
        });
        menu.querySelector('.pcw-mention-option.is-active')?.scrollIntoView({ block: 'nearest' });
    }

    function acceptActiveMention() {
        if (mentionActiveIdx < 0 || !mentionUsers[mentionActiveIdx]) return false;
        insertMention(mentionUsers[mentionActiveIdx].username);
        return true;
    }

    function openValidateModal() {
        if (cfg.canValidate === false) return;
        const po = headerPo();
        if (!po) {
            alert('Load a PO first.');
            return;
        }
        const loginUser = String(cfg.currentUser || $('pcwLoginUserName')?.textContent || 'User').trim();
        if ($('pcwLoginUserName')) $('pcwLoginUserName').textContent = loginUser || 'User';
        const avatar = $('pcwActivityAvatar');
        if (avatar) avatar.textContent = avatarLetter(loginUser || 'U');
        setComposeOpen(false);
        const feed = $('pcwHistoryBody');
        const hideBtn = $('pcwActivityHideDetails');
        if (feed) {
            feed.hidden = false;
            feed.classList.remove('is-compact');
        }
        if (hideBtn) hideBtn.textContent = 'Hide details';
        openActionOffcanvas('pcwValidateModal');
        void loadActivityFeed(po);
    }

    function closeHistoryModal() {
        closeValidateModal();
    }

    async function openHistoryModal() {
        openValidateModal();
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
            menu.innerHTML = users
                .map(
                    (u) => `<button type="button" class="pcw-user-option" role="option"
                        data-id="${esc(u.id)}" data-username="${esc(u.username)}">
                        <strong>${esc(u.username)}</strong>
                        <small>${esc([u.department, u.role, u.email].filter(Boolean).join(' · ') || '—')}</small>
                    </button>`
                )
                .join('');
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
        syncSelectionUi();
    }

    async function saveValidationLog() {
        const description = String($('pcwValidateRemarks')?.value || '').trim();
        if (!description) {
            alert('Write a comment first.');
            $('pcwValidateRemarks')?.focus();
            return;
        }
        const po = headerPo();
        if (!po) return;
        const loginUser = String(cfg.currentUser || $('pcwLoginUserName')?.textContent || 'User').trim();
        const loginEmail = String(cfg.currentUserEmail || '').trim();
        const endpoint = cfg.validationLogUrl || 'purchase-order/validation-log';
        const mentions = extractMentionsFromText(description);
        const payload = {
            purchase_order: po,
            mat_cat: selectedMat(),
            assigned_user_id: null,
            assigned_username: loginUser || 'system',
            assigned_email: loginEmail,
            validation_status: 'COMMENT',
            description,
            mentions,
            po_item: '',
            material: '',
            mat_desc: '',
            matched_zcost_item: '',
            matched_maktx: '',
            row_status: '',
        };
        const btn = $('pcwValidateSave');
        if (btn) btn.disabled = true;
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const rawText = await res.text();
            let json = {};
            try {
                json = rawText ? JSON.parse(rawText) : {};
            } catch (_) {
                throw new Error(
                    rawText
                        ? `Save returned non-JSON (HTTP ${res.status}): ${rawText.slice(0, 180)}`
                        : `Save returned empty response (HTTP ${res.status}). Check PHP / mail SMTP timeout.`
                );
            }
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            setComposeOpen(false);
            hideMentionMenu();
            await loadActivityFeed(po);
            const mailInfo = json?.data?.mail || {};
            const mailed = Number(mailInfo.sent || 0);
            const mailFail = Number(mailInfo.failed || 0);
            const mailErr = Array.isArray(mailInfo.errors) && mailInfo.errors.length
                ? String(mailInfo.errors[0])
                : '';
            const fromId = String(mailInfo.from || '');
            const recipients = Array.isArray(mailInfo.recipients) ? mailInfo.recipients.join(', ') : '';
            const toast = document.createElement('div');
            toast.className = mailed > 0 ? 'pcw-toast is-ok' : (mailFail > 0 || mailErr ? 'pcw-toast is-warn' : 'pcw-toast is-ok');
            if (mailed > 0) {
                toast.textContent = `Comment saved · mailed ${mailed} to ${recipients || 'mention(s)'} from ${fromId || 'apps@evolvclothing.com'}`;
            } else if (mailErr || mailFail > 0) {
                toast.textContent = `Comment saved · mail NOT sent: ${mailErr || 'check config/mail.php SMTP password'}`;
            } else {
                toast.textContent = 'Comment saved (no @mention mail)';
            }
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 6000);
        } catch (err) {
            const msg = String(err.message || 'Could not save comment');
            alert(msg.includes('lastInsertId')
                ? 'Save failed due to a database driver issue. Please hard-refresh and try again.'
                : msg);
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    function bindUserValidateUi() {
        const search = $('pcwUserSearch');
        search?.addEventListener('input', () => {
            const q = String(search.value || '').trim();
            if (assignedUser.username && q !== assignedUser.username) {
                assignedUser = { id: '', username: '' };
                if ($('pcwUserId')) $('pcwUserId').value = '';
                if ($('pcwUserName')) $('pcwUserName').value = '';
                syncSelectionUi();
            }
            clearTimeout(userSearchTimer);
            userSearchTimer = setTimeout(() => searchUsers(q), 180);
        });
        search?.addEventListener('focus', () => {
            void searchUsers(String(search.value || '').trim());
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
        $('pcwOpenValidateBtn')?.addEventListener('click', () => {
            if (cfg.canValidate === false) return;
            openValidateModal();
        });
        $('pcwValidateClose')?.addEventListener('click', closeValidateModal);
        $('pcwValidateCancel')?.addEventListener('click', () => setComposeOpen(false));
        $('pcwValidateBackdrop')?.addEventListener('click', closeValidateModal);
        $('pcwValidateSave')?.addEventListener('click', () => {
            void saveValidationLog();
        });
        const remarks = $('pcwValidateRemarks');
        remarks?.addEventListener('focus', () => setComposeOpen(true));
        remarks?.addEventListener('input', () => {
            autoSizeComment();
            scheduleMentionLookup();
        });
        remarks?.addEventListener('keydown', (e) => {
            const menu = $('pcwMentionMenu');
            const menuOpen = menu && !menu.hidden && mentionUsers.length;
            if (menuOpen && e.key === 'ArrowDown') {
                e.preventDefault();
                moveMentionActive(1);
                return;
            }
            if (menuOpen && e.key === 'ArrowUp') {
                e.preventDefault();
                moveMentionActive(-1);
                return;
            }
            if (menuOpen && (e.key === 'Enter' || e.key === 'Tab') && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                acceptActiveMention();
                return;
            }
            if (menuOpen && e.key === 'Escape') {
                e.preventDefault();
                hideMentionMenu();
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                void saveValidationLog();
            }
        });
        $('pcwMentionMenu')?.addEventListener('mousedown', (e) => {
            const opt = e.target.closest('.pcw-mention-option');
            if (!opt) return;
            e.preventDefault();
            insertMention(opt.getAttribute('data-username'));
        });
        document.addEventListener('click', (e) => {
            if (e.target.closest('#pcwActivityCompose')) return;
            hideMentionMenu();
        });
        $('pcwActivityHideDetails')?.addEventListener('click', () => {
            const feed = $('pcwHistoryBody');
            const btn = $('pcwActivityHideDetails');
            if (!feed || !btn) return;
            const compact = !feed.classList.contains('is-compact');
            feed.classList.toggle('is-compact', compact);
            btn.textContent = compact ? 'Show details' : 'Hide details';
        });
        $('pcwViewPdfBtn')?.addEventListener('click', () => {
            openPdfModal(headerPo());
        });
        $('pcwOpenPdfBtn')?.addEventListener('click', () => {
            openPdfTab(headerPo());
        });
        $('pcwOpenEsignBtn')?.addEventListener('click', () => {
            if (cfg.canEsign === false) {
                alert('You do not have eSign access for PO Costing.');
                return;
            }
            // Direct e-sign open — no Approved button
            passPoAndOpenEsign({ fromPassPo: false });
        });
        $('pcwPdfClose')?.addEventListener('click', closePdfModal);
        $('pcwPdfBackdrop')?.addEventListener('click', closePdfModal);
        $('pcwHistoryBtn')?.addEventListener('click', () => {
            void openHistoryModal();
        });
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
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && $('pcwPdfModal') && !$('pcwPdfModal').hidden) {
                closePdfModal();
                return;
            }
            if (e.key === 'Escape' && $('pcwValidateModal') && !$('pcwValidateModal').hidden) {
                closeValidateModal();
            }
            if (e.key === 'Escape' && $('pcwHistoryModal') && !$('pcwHistoryModal').hidden) {
                closeHistoryModal();
            }
        });
    }

    function onMatchApply() {
        if (matchRowIndex === null || !matchPickKey) return;
        const list = $('pcwMatchList');
        const lines = list?._pcwLines || [];
        const line = lines.find((l, i) => lineKey(l, i) === matchPickKey);
        if (!line || !rows[matchRowIndex]) return;

        const targetRow = rows[matchRowIndex];
        delete targetRow._matchEditSnapshot;
        applyCostLineToRow(targetRow, line);
        targetRow.matched_line_key = matchPickKey;
        targetRow.selected = true;

        // Persist so User2 / refresh sees the same mapping
        void persistManualMapping(targetRow, line);

        closeMatchPicker();
        fillKpis(summarizeRows(rows));
        renderTable();
    }

    async function persistManualMapping(r, line) {
        const po = headerPo() || String(r?.pono || r?.purchase_order || '').replace(/\D/g, '');
        if (!po || !r) return;
        const endpoint = cfg.manualMapUrl || 'purchase-order/manual-map';
        const payload = {
            purchase_order: po,
            po_item: String(r.poitem || r.po_item || ''),
            material: String(r.material || ''),
            mat_cat: selectedMat(),
            quotation_no: String(line?.quotation_no || line?.costing_quotation_no || r.costing_quotation_no || ''),
            zcost_item: String(r.zcost_item || r.cost_item || line?.item || ''),
            maktx: String(r.maktx || line?.maktx || line?.description || ''),
            zsection: String(r.zsection || line?.section || line?.zsection || ''),
            total_inr: nVal(line?.total_inr ?? r.total_inr),
            gross: nVal(line?.gross ?? r.gross),
            currency: String(line?.currency || r.budget_currency || 'INR'),
            convr: nVal(line?.convr ?? r.budget_conv_rt),
            unit: String(line?.unit || r.buom || ''),
            line_no: String(line?.line_no || r.matched_line_no || ''),
            matched_line_key: String(r.matched_line_key || ''),
            budget_cost_per_gmt: nVal(r.budget_cost_per_gmt),
            cost_description: String(r.cost_description || r.maktx || ''),
        };
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const rawText = await res.text();
            let json = {};
            try {
                json = rawText ? JSON.parse(rawText) : {};
            } catch (_) {
                throw new Error(rawText ? rawText.slice(0, 160) : `Empty response (HTTP ${res.status})`);
            }
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
        } catch (err) {
            console.warn('manual map save failed', err);
            const toast = document.createElement('div');
            toast.className = 'pcw-toast is-warn';
            toast.textContent = `Mapped on screen, but not saved for other users: ${String(err.message || err)}`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 6500);
        }
    }

    function clearFilters() {
        const search = $('rptTableSearch');
        if (search) search.value = '';
        document.querySelectorAll('#rptFilterRow .rpt-col-input').forEach((i) => {
            i.value = '';
        });
        tableStatusFilter = '';
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

        // Default: clean table view — column filters open only when this button is clicked
        const filterRow = $('rptFilterRow');
        if (filterRow) filterRow.style.display = 'none';
        $('rptToggleColFilters')?.classList.remove('is-active');
        $('rptToggleColFilters')?.addEventListener('click', () => {
            filterVisible = !filterVisible;
            const row = $('rptFilterRow');
            if (row) row.style.display = filterVisible ? '' : 'none';
            $('rptToggleColFilters')?.classList.toggle('is-active', filterVisible);
            $('rptToggleColFilters')?.setAttribute(
                'title',
                filterVisible ? 'Hide column filter inputs' : 'Show column filter inputs'
            );
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

        $('pcwBackBtn')?.addEventListener('click', (e) => {
            const ref = String(document.referrer || '');
            if (ref && ref.startsWith(window.location.origin) && /po-costing|purchase-order/i.test(ref)) {
                e.preventDefault();
                window.history.back();
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
            poPassState = { po: '', passed: false };
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
            if (cfg.canPassPo === false) return;
            // PASS PO → e-sign + show Approved (Approve API only)
            passPoAndOpenEsign({ fromPassPo: true });
        });
        $('pcwPdfContinuePass')?.addEventListener('click', () => {
            continuePassFromPdf();
        });
        $('pcwPdfPassCancel')?.addEventListener('click', () => {
            closePdfModal();
        });
        bindEsignUi();
        controlBar?.querySelectorAll('input[name="mat_cat"]').forEach((el) => {
            el.addEventListener('change', () => {
                if (!headerPo()) return;
                selectedCustomer = '';
                selectedEndUser = '';
                currentMode = 'validated';
                void runValidate({ mode: 'validated', forceValidated: true });
            });
        });

        $('pcwMatchClose')?.addEventListener('click', closeMatchPicker);
        $('pcwMatchCancel')?.addEventListener('click', closeMatchPicker);
        $('pcwMatchBackdrop')?.addEventListener('click', closeMatchPicker);
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
            if (e.key === 'Escape' && $('pcwMatchPop')?.classList.contains('is-open')) {
                closeMatchPicker();
            }
        });

        tbody?.addEventListener('change', onRowSelectChange);
        tbody?.addEventListener('click', (e) => {
            const quoteHit = e.target.closest('a.pcw-quote-link, td[data-col-id="costing_quotation_no"]');
            if (!quoteHit) return;
            e.preventDefault();
            e.stopPropagation();
            const tr = quoteHit.closest('tr');
            const idx = Number(tr?.getAttribute('data-idx'));
            const row = Number.isFinite(idx) ? rows[idx] : null;
            const q = quoteHit.getAttribute('data-quote')
                || (row ? cellValue('costing_quotation_no', row, idx) : '');
            const item = row ? cellValue('costing_item_no', row, idx) : '';
            openZcostView(q, item);
        }, true);
        tbody?.addEventListener('click', (e) => {
            if (e.target.closest('a.pcw-quote-link, td[data-col-id="costing_quotation_no"]')) return;
            const matchBtn = e.target.closest('button.pcw-match-btn');
            if (matchBtn) {
                e.preventDefault();
                e.stopPropagation();
                const idx = Number(matchBtn.getAttribute('data-idx'));
                if (Number.isFinite(idx)) openMatchPicker(idx);
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

        bindUserValidateUi();

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
