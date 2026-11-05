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
        'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
        'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost', 'variance_pct',
    ]);
    const HIGHLIGHT_COLS = new Set(['budget_cost_per_gmt', 'actual_cost']);
    const ZCOST_COLS = new Set([
        'costing_quotation_no', 'costing_item_no', 'maktx',
        'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'budget_cost_per_gmt',
        'conv', 'buom', 'actual_cost', 'variance_pct',
    ]);
    // Click any cell: show TEMPFIN / SAP API source column + original value
    // (costing_quotation_no excluded — that opens sap_reports/zcost)
    const EXPLAIN_COLS = new Set([
        'pono', 'poitem', 'prno', 'salordno', 'style', 'material', 'matgroup',
        'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'ouom', 'porate', 'totvalue', 'mat_desc',
        'po_currency', 'costing_item_no', 'maktx',
        'budgeted_cons', 'budget_inr', 'budget_currency',
        'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'buom', 'actual_cost',
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
        maktx: { api: 'MAKTX', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Costing line material description.' },
        budgeted_cons: { api: 'GROSS', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budgeted consumption (gross).' },
        budget_inr: { api: 'TOTALINR', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budget total in INR.' },
        budget_currency: { api: 'CURUNIT', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Costing currency unit.' },
        budget_conv_rt: { api: 'CONVR', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budget conversion rate.' },
        budget_cost_per_gmt: { api: 'RATE', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budget cost per garment.' },
        conv: { api: 'CONVR', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Conversion factor.' },
        buom: { api: 'PurchaseOrderQuantityUnit', source: 'Z_QuotationZCostCom', desc: 'Base / order unit of measure.' },
        actual_cost: { api: 'PORATE × CONV × CONSUMPTION (BUTTON+GRO ÷ 144)', source: 'TEMPFIN / PASSPO', desc: 'Actual cost per garment. Buttons in GRO: round(PORATE×CONV×CONS)÷144.' },
        variance_pct: { api: 'derived', source: 'Computed', desc: '(Actual − RATE) ÷ RATE × 100.' },
    };
    const colGroup = (key) => (ZCOST_COLS.has(key) ? 'zcost' : 'po');

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
        const budget = nVal(r?.budget_cost_per_gmt ?? r?.expected_unit_cost);
        const actual = nVal(r?.actual_cost ?? r?.po_unit_cost);
        // Classic portal: green when actual ≤ budget + 0.005 (same as PO Costing Validation)
        if (budget !== null && budget > 0 && actual !== null && actual <= budget + 0.005) {
            return { code: 'PASS', label: 'PASS', cls: 'is-pass' };
        }
        if (st === 'PASS') return { code: 'PASS', label: 'PASS', cls: 'is-pass' };
        if (st === 'NEEDS_REVIEW') return { code: 'NEEDS_REVIEW', label: 'NEEDS REVIEW', cls: 'is-review' };
        if (st === 'FOOTER') return { code: 'FOOTER', label: '—', cls: 'is-footer' };
        if (needsManualMatch(r) || /no matching|no linked|cannot load|no budget/i.test(String(r?.validation_result || ''))) {
            return { code: 'NOT_MATCHED', label: 'NOT MATCHED', cls: 'is-fail' };
        }
        if (st === 'EXCEPTION' || st === 'FAIL' || st === 'NOT_VALIDATED') {
            return { code: 'NOT_VALIDATED', label: 'NOT VALIDATED', cls: 'is-fail' };
        }
        return { code: st || 'NOT_MATCHED', label: st || 'NOT MATCHED', cls: 'is-fail' };
    }

    function statusReasonExplain(r) {
        const disp = statusDisplay(r);
        const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const actual = nVal(r.actual_cost ?? r.po_unit_cost);
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
                steps.push({
                    label: 'Module check',
                    value: budget === null || budget <= 0
                        ? 'No budget RATE → needs review / not matched'
                        : `Budget RATE ${fmtStep(budget, 4)} compared with actual ${actual === null ? '—' : fmtStep(actual, 4)}`,
                });
            }
            if (key === 'actual_cost') {
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

        if (cardKey === 'status_total') {
            icon = 'rule';
            subtitle = 'Validation Overview';
            title = 'Total Validation Lines';
            val = fmt(s.total_records || detailRows.length);
            formula = 'Total Lines = Count of all item rows evaluated in Purchase Order';
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Passed Lines</span><strong>${fmt(s.passed)}</strong></div>
                <div class="pcw-cdm-step-item"><span>Not Matched / Not Validated</span><strong>${fmt(s.exceptions)}</strong></div>
                <div class="pcw-cdm-step-item"><span>Needs Review Lines</span><strong>${fmt(s.needs_review)}</strong></div>
                <div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Evaluated</span><strong>${fmt(s.total_records || detailRows.length)}</strong></div>
            `;
            rowsHtml = detailRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = detailRows.length;
        } else if (cardKey === 'status_pass') {
            icon = 'check_circle';
            subtitle = 'Validation Overview';
            title = 'Passed Lines';
            val = fmt(s.passed);
            formula = 'Passed Lines = PO lines where Actual PO Rate ≤ Budget Cost per GMT';
            showFilterBtn = true;
            filterStatus = 'PASS';
            const passRows = detailRows.filter(r => String(r.status).toUpperCase() === 'PASS');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>Actual Cost ≤ Budget Cost per GMT</strong></div>
                <div class="pcw-cdm-step-item"><span>Total Passed Lines</span><strong>${fmt(s.passed)} of ${fmt(detailRows.length)}</strong></div>
            `;
            rowsHtml = passRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || r.po_qty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || r.order_qty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = passRows.length;
        } else if (cardKey === 'status_fail') {
            icon = 'error';
            subtitle = 'Validation Overview';
            title = 'Not Matched / Not Validated';
            val = fmt(s.exceptions);
            formula = 'Not Matched = no zCOST link · Not Validated = Actual cost > Budget RATE';
            showFilterBtn = true;
            filterStatus = 'NOT';
            const failRows = detailRows.filter(r => String(r.status).toUpperCase() !== 'PASS' && String(r.status).toUpperCase() !== 'NEEDS_REVIEW');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>No zCOST match OR Actual &gt; Budget RATE</strong></div>
                <div class="pcw-cdm-step-item"><span>Not Matched / Not Validated</span><strong>${fmt(s.exceptions)} of ${fmt(detailRows.length)}</strong></div>
            `;
            rowsHtml = failRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || r.po_qty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || r.order_qty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = failRows.length;
        } else if (cardKey === 'status_review') {
            icon = 'rate_review';
            subtitle = 'Validation Overview';
            title = 'Needs Review Lines';
            val = fmt(s.needs_review);
            formula = 'Needs Review = PO lines with zero budget rate or uncertain match requiring review';
            showFilterBtn = true;
            filterStatus = 'NEEDS_REVIEW';
            const revRows = detailRows.filter(r => String(r.status).toUpperCase() === 'NEEDS_REVIEW');
            stepsHtml = `
                <div class="pcw-cdm-step-item"><span>Condition</span><strong>Rate is zero or uncertain mapping</strong></div>
                <div class="pcw-cdm-step-item"><span>Total Needs Review</span><strong>${fmt(s.needs_review)} of ${fmt(detailRows.length)}</strong></div>
            `;
            rowsHtml = revRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || r.po_qty || r.so_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || r.order_qty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = revRows.length;
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
            title = 'Total SO Qty';
            val = fmt(s.total_so_qty || 0);
            unit = uom;
            formula = 'Total SO Qty = ∑ SO_QTY (PRWISE_SO_QTY when SO_QTY blank)';
            stepsHtml = detailRows.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item || r.item)} · SO ${esc(r.salordno || r.sales_order || '—')}</span><strong>${qty(r.so_qty ?? r.prwise_so_qty ?? 0)} ${esc(r.ouom || uom)}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total SO Qty</span><strong>${fmt(s.total_so_qty || 0)} ${esc(uom)}</strong></div>`;
            rowsHtml = detailRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">SO: ${esc(r.salordno || r.sales_order || '—')}</small></td>
                <td class="num">${qty(r.so_qty ?? r.prwise_so_qty ?? 0)} ${esc(r.ouom || uom)}</td>
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
            formula = 'Total Net Value = ∑ (PO Order Quantity × PO Unit Rate)';
            const seenIt = {};
            const itRows = detailRows.filter(r => {
                const it = String(r.poitem || r.po_item || r.item || '').trim();
                if (it && !seenIt[it]) { seenIt[it] = true; return true; }
                return !it;
            });
            stepsHtml = itRows.map(r => `<div class="pcw-cdm-step-item"><span>PO Item #${esc(r.poitem || r.po_item || r.item)} (${qty(r.tot_poqty || r.order_qty || 0)} × ₹${fmtDec(r.porate ?? r.po_unit_cost, 2)})</span><strong>₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || 1)))}</strong></div>`).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Net Value</span><strong>₹${fmt(s.total_po_value)} ${esc(curr)}</strong></div>`;
            rowsHtml = detailRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#64748b;">${esc(r.mat_desc || '')}</small></td>
                <td class="num">${qty(r.tot_poqty || r.order_qty || 0)} ${esc(r.ouom || uom)}</td>
                <td class="num">₹${fmtDec(r.porate ?? r.po_unit_cost, 2)}</td>
                <td class="num">₹${money(r.totvalue ?? r.net_value ?? ((r.porate ?? r.po_unit_cost ?? 0) * (r.tot_poqty || 1)))}</td>
                <td>${statusPill(r.status, r)}</td>
            </tr>`).join('');
            rowCount = detailRows.length;
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
            subtitle = 'zCOST DATA (MATCHED ONLY)';
            title = 'Total SO Qty';
            val = fmt(s.zcost_so_qty);
            unit = uom;
            formula = 'zCOST SO Qty = ∑ SO_QTY on matched lines (same field as PO Total SO Qty)';
            const matchedRows = detailRows.filter(isMatchedRow);
            stepsHtml = matchedRows.length
                ? matchedRows.map(r => `<div class="pcw-cdm-step-item"><span>Matched Item #${esc(r.poitem || r.po_item || r.item)} · SO ${esc(r.salordno || r.sales_order || '—')}</span><strong>${qty(r.so_qty ?? r.prwise_so_qty ?? 0)} ${esc(r.ouom || uom)}</strong></div>`).join('')
                    + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Matched SO Qty</span><strong>${fmt(s.zcost_so_qty)} ${esc(uom)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched quantity</span><strong>0</strong></div>';
            rowsHtml = matchedRows.map((r, i) => `<tr>
                <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                <td class="num">${qty(r.so_qty ?? r.prwise_so_qty ?? 0)} ${esc(r.ouom || uom)}</td>
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
            formula = 'zCOST Net Value = ∑ (TOT_POQTY × BUDGET_COST_PER_GMT) once per matched PO item';
            const matchedRows = detailRows.filter(isMatchedRow);
            const seenZVal = {};
            const valRows = matchedRows.filter(r => {
                const it = String(r.poitem || r.po_item || r.item || '').trim();
                if (it && !seenZVal[it]) { seenZVal[it] = true; return true; }
                return !it;
            });
            stepsHtml = valRows.length
                ? valRows.map(r => {
                    const b = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost) || 0;
                    const q = Number(r.tot_poqty || r.order_qty || 1);
                    return `<div class="pcw-cdm-step-item"><span>Item #${esc(r.poitem || r.po_item || r.item)} (${qty(q)} × ₹${fmtDec(b, 4)})</span><strong>₹${money(b * q)}</strong></div>`;
                }).join('')
                + `<div class="pcw-cdm-step-item" style="font-weight:800; border-top:1.5px solid #cbd5e1;"><span>Total Expected Budget</span><strong>₹${fmt(s.zcost_net_value)} ${esc(curr)}</strong></div>`
                : '<div class="pcw-cdm-step-item"><span>No matched value</span><strong>₹0</strong></div>';
            rowsHtml = matchedRows.map((r, i) => {
                const b = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost) || 0;
                const q = Number(r.tot_poqty || r.order_qty || 1);
                return `<tr>
                    <td>#${esc(r.poitem || r.po_item || r.item || (i + 1))}</td>
                    <td><strong>${esc(r.material)}</strong><br><small style="color:#4338ca;">zCOST: ${esc(r.maktx || r.cost_description || '—')}</small></td>
                    <td class="num">${qty(q)} ${esc(r.ouom || uom)}</td>
                    <td class="num">₹${fmtDec(b, 4)}</td>
                    <td class="num">₹${money(b * q)}</td>
                    <td>${statusPill(r.status, r)}</td>
                </tr>`;
            }).join('');
            rowCount = matchedRows.length;
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
                $('pcwCardModalFilterLabel').textContent = `Filter Table by ${filterStatus}`;
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

    function pdfUrlFor(po) {
        const digits = String(po || '').replace(/\D/g, '');
        if (!digits) return '';
        const u = new URL(cfg.pdfUrl || 'purchase-order/pdf', window.location.origin);
        u.searchParams.set('po', digits);
        return u.toString();
    }

    function closePdfModal() {
        const modal = $('pcwPdfModal');
        const backdrop = $('pcwPdfBackdrop');
        const frame = $('pcwPdfFrame');
        if (modal) modal.hidden = true;
        if (backdrop) backdrop.hidden = true;
        document.body.classList.remove('pcw-pdf-open');
        if (frame) {
            frame.hidden = true;
            frame.removeAttribute('src');
        }
        if ($('pcwPdfLoading')) $('pcwPdfLoading').hidden = false;
    }

    function openPdfModal(po) {
        const url = pdfUrlFor(po);
        if (!url) {
            alert('Enter a purchase order number first.');
            return;
        }
        const modal = $('pcwPdfModal');
        const backdrop = $('pcwPdfBackdrop');
        const frame = $('pcwPdfFrame');
        const loading = $('pcwPdfLoading');
        const openTab = $('pcwPdfOpenTab');
        if (!modal || !frame) {
            window.open(url, '_blank', 'noopener');
            return;
        }
        if ($('pcwPdfTitle')) $('pcwPdfTitle').textContent = 'PO PDF';
        if ($('pcwPdfMeta')) $('pcwPdfMeta').textContent = 'Purchase Order ' + po;
        if (openTab) openTab.href = url;
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

    function openPdfTab(po) {
        const url = pdfUrlFor(po);
        if (!url) {
            alert('Enter a purchase order number first.');
            return;
        }
        window.open(url, '_blank', 'noopener');
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
        const mayPass = cfg.canPassPo !== false;
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
        if (btn) {
            btn.hidden = !mayPass;
            btn.disabled = !mayPass || !canPass;
        }
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
        return kind === 'footer' || kind === 'charge' || kind === 'tax';
    }

    function isMatchedRow(r) {
        if (!r || isFooterRow(r)) return false;
        if (r.manual_match || r.matched_line_key || r.cost_item || r.maktx) {
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
        // TEMPFIN already validated (budget+actual) — empty zCOST ITEM is optional, not "needs review"
        const budget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const actual = nVal(r.actual_cost ?? r.po_unit_cost);
        const st = String(r.status || '').toUpperCase();
        if ((st === 'PASS' || st === 'EXCEPTION') && budget !== null && budget > 0 && actual !== null) {
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
            const st = statusDisplay(r).code;
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
            // Total SO Qty card = ∑ SO_QTY (not TOT_POQTY)
            poSoQty += rowSoQty > 0 ? rowSoQty : 0;

            // PO net value & PO order qty — once per PO item (TOTVALUE repeats per SO line)
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
            // Budget/actual totals use PO qty (same basis as PO net = PORATE × TOT_POQTY)
            const costQty = rowOrderQty > 0 ? rowOrderQty : (rowSoQty > 0 ? rowSoQty : 1);
            if (budget !== null) totalExpected += budget * costQty;
            if (actual !== null) totalActual += actual * costQty;

            // Strict match check: only count rows that have a confirmed zCOST match
            const isMatched = isMatchedRow(r);

            if (isMatched) {
                if (item) zItems[item] = true;
                if (mat) zMaterials[mat] = true;

                // zCOST Total SO Qty = ∑ SO_QTY on matched lines (same field as PO card)
                zcostSoQty += rowSoQty > 0 ? rowSoQty : 0;

                // zCOST Net Value = ∑ (BUDGET_COST_PER_GMT × TOT_POQTY) once per PO item
                if (item && !seenZItem[item]) {
                    seenZItem[item] = true;
                    zcostExpected += (budget || 0) * (rowOrderQty > 0 ? rowOrderQty : costQty);
                } else if (!item) {
                    zcostExpected += (budget || 0) * costQty;
                }
            }

            if (!unit) unit = String(r.ouom || r.unit || '').trim();
            if (r.po_currency || r.currency) currency = String(r.po_currency || r.currency);
        });

        const zcostItemCount = Object.keys(zItems).length;
        const zcostMatCount = Object.keys(zMaterials).length;

        return {
            total_records: (list || []).length,
            po_items: Object.keys(items).length || (list || []).length,
            materials: Object.keys(materials).length,
            sales_orders: Object.keys(salesOrders).length,
            total_order_qty: totalOrderQty,
            total_so_qty: poSoQty,
            total_po_value: totalValue,
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
        // Always trust row-derived PASS / EXCEPTION / REVIEW (API used to count SUB-TOTAL as exceptions)
        if (rows.length) {
            s.passed = fromRows.passed;
            s.exceptions = fromRows.exceptions;
            s.needs_review = fromRows.needs_review;
            s.can_pass_po = fromRows.can_pass_po
                ?? (Number(fromRows.passed) > 0 && Number(fromRows.exceptions) === 0 && Number(fromRows.needs_review) === 0);
        }
        // Always prefer row-derived qty/value cards (API summary used wrong fields)
        if (rows.length) {
            s.total_order_qty = fromRows.total_order_qty;
            s.total_so_qty = fromRows.total_so_qty;
            s.total_po_value = fromRows.total_po_value;
            s.total_expected = fromRows.total_expected;
            s.total_actual = fromRows.total_actual;
            s.zcost_so_qty = fromRows.zcost_so_qty;
            s.zcost_net_value = fromRows.zcost_net_value;
            s.zcost_items = fromRows.zcost_items;
            s.zcost_materials = fromRows.zcost_materials;
            s.unit = fromRows.unit || s.unit;
            s.currency = fromRows.currency || s.currency;
        }
        const fmt = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
        const total = (Number(s.passed) || 0) + (Number(s.exceptions) || 0) + (Number(s.needs_review) || 0) || rows.length || 0;

        // Row 1: Common status
        if ($('pcwKpiTotal')) $('pcwKpiTotal').textContent = fmt(total);
        if ($('pcwKpiPassed')) $('pcwKpiPassed').textContent = fmt(s.passed || 0);
        if ($('pcwKpiExceptions')) $('pcwKpiExceptions').textContent = fmt(s.exceptions || 0);
        if ($('pcwKpiReview')) $('pcwKpiReview').textContent = fmt(s.needs_review || 0);

        // Row 2 Left: PO Data — Total SO Qty must use SO_QTY (not TOT_POQTY)
        const poItems = Number(s.po_items) > 0 ? s.po_items : (s.unique_po_items || rows.length || 0);
        const poMaterials = Number(s.materials) > 0 ? s.materials : 0;
        const poSoQty = Number(s.total_so_qty) > 0 ? Number(s.total_so_qty) : (Number(s.total_order_qty) || 0);
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
                const st = String(status || '').toUpperCase();
                if (st === 'PASS') return { label: 'PASS', cls: 'is-pass' };
                if (st === 'NEEDS_REVIEW') return { label: 'NEEDS REVIEW', cls: 'is-review' };
                if (st === 'FOOTER') return { label: '—', cls: 'is-footer' };
                if (st === 'EXCEPTION' || st === 'FAIL' || st === 'NOT_VALIDATED') {
                    return { label: 'NOT VALIDATED', cls: 'is-fail' };
                }
                return { label: st || 'NOT MATCHED', cls: 'is-fail' };
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
        const options = [`<option value="">Select zCOST ITEM…</option>`].concat(
            cands.slice(0, 80).map((line) => {
                const globalIdx = (costLines || []).indexOf(line);
                const key = lineKey(line, globalIdx >= 0 ? globalIdx : 0);
                const rate = nVal(line.rate) ?? nVal(line.purp);
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
            : (!cands.length ? ' title="No zCOST lines for this material type"' : '');
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
            targetRow.zcost_item = '';
            targetRow.costing_item_no = '';
            targetRow.costing_quotation_no = '';
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
        // Classic portal / TEMPFIN values win — zCOST RATE must never replace BUDGET or ACTUAL
        const tempfinBudget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const tempfinActual = nVal(r.actual_cost ?? r.po_unit_cost);
        const lockedBudget = !!r.tempfin_budget_locked || (tempfinBudget !== null && tempfinBudget > 0);
        const lockedActual = !!r.tempfin_actual_locked || (tempfinActual !== null && tempfinActual > 0);
        const lockedCons = !!r.tempfin_cons_locked || (nVal(r.consumption) !== null && nVal(r.consumption) > 0);
        const priorStatus = String(r.status || '').toUpperCase().replace(/\s+/g, '_');
        const rawLineBudget = nVal(line.rate) ?? nVal(line.purp) ?? nVal(line.net_price);
        // Zero / blank zCOST RATE is not a usable budget — never write 0 over TEMPFIN
        const lineBudget = (rawLineBudget !== null && rawLineBudget > 0) ? rawLineBudget : null;
        const budget = lockedBudget
            ? tempfinBudget
            : ((tempfinBudget !== null && tempfinBudget > 0) ? tempfinBudget : lineBudget);
        const actual = tempfinActual;
        let variancePct = null;
        let status = priorStatus || 'PASS';
        let result = String(r.validation_result || 'PASS — manually matched to zCOST line');
        if (budget !== null && budget > 0 && actual !== null) {
            variancePct = roundPct(((actual - budget) / budget) * 100);
            if (actual <= budget + 0.005) {
                status = 'PASS';
                result = 'PASS — manually matched to zCOST line';
            } else {
                status = 'EXCEPTION';
                result = 'NOT VALIDATED — actual cost exceeds matched budget RATE';
            }
        } else if (budget !== null && budget > 0) {
            status = 'PASS';
            result = 'PASS — manually matched to zCOST line';
        } else if (priorStatus === 'PASS' && tempfinBudget !== null && tempfinBudget > 0) {
            // Keep Excel/TEMPFIN PASS — matched line had no RATE
            status = 'PASS';
            result = String(r.validation_result || 'PASS — actual cost within budget');
        } else {
            status = 'NEEDS_REVIEW';
            result = 'Matched zCOST line but budget RATE is zero — Needs Review';
        }
        // Prefer recovered TEMPFIN budget when match RATE was blank/zero
        const finalBudget = (budget !== null && budget > 0)
            ? budget
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
        r.cost_section = String(line.section || '');
        r.zsection = String(line.zsection || '');
        // Do not overwrite TEMPFIN BUDGETED_CONS / CONSUMPTION with zCOST GROSS when locked
        if (!lockedCons) {
            r.budgeted_cons = nVal(line.gross) ?? nVal(line.net) ?? r.budgeted_cons;
        }
        // Never write zCOST total_inr over TEMPFIN BUDGET_INR when budget is locked
        if (!lockedBudget) {
            r.budget_inr = nVal(line.total_inr) ?? r.budget_inr;
        }
        r.budget_currency = String(line.currency || r.budget_currency || 'INR');
        r.budget_conv_rt = nVal(line.convr) ?? r.budget_conv_rt;
        // Keep prior TEMPFIN budget when match had no usable RATE (do not store 0)
        if (finalBudget !== null && finalBudget > 0) {
            r.budget_cost_per_gmt = finalBudget;
            r.expected_unit_cost = finalBudget;
        }
        r.tempfin_budget_locked = lockedBudget || (finalBudget !== null && finalBudget > 0 && finalBudget === tempfinBudget);
        r.conv = nVal(line.convr) ?? r.conv;
        // Keep TEMPFIN BUOM (GRO) — do not replace with zCOST unit
        if (!r.buom) {
            r.buom = String(line.unit || '');
        }
        if (actual !== null) {
            // Always keep existing TEMPFIN actual when locked
            if (!lockedActual) {
                r.actual_cost = actual;
                r.po_unit_cost = actual;
            }
            r.tempfin_actual_locked = lockedActual;
        }
        r.variance_pct = variancePct;
        r.status = status;
        r.validation_result = result;
        r.cost_matrix = {
            budget: finalBudget,
            actual,
            status,
        };
        r.manual_match = true;
        // TEMPFIN already has budget+actual → match is optional identity only
        r.needs_manual_match = !(finalBudget !== null && finalBudget > 0 && actual !== null);
        r._editingMatch = false;
        r.matched_quotation = String(line.quotation_no || line.costing_quotation_no || '');
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
        return `<button type="button" class="pcw-formula-cell" data-explain="${esc(key)}" data-idx="${index}" title="Show API column">${displayHtml}</button>`;
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
            case 'status':
                if (isFooterRow(r)) {
                    inner = '';
                } else {
                    const pill = `<button type="button" class="pcw-formula-cell pcw-status-reason-btn" data-explain="status" data-idx="${index}" title="Why this status?">
                        ${statusPill(r.status, r)}
                    </button>`;
                    inner = `<div class="pcw-status-cell">
                        ${pill}
                        ${r.manual_match
                            ? `<span class="pcw-match-tag" title="Manually matched">Manual</span>`
                            : ''}
                    </div>`;
                }
                break;
            case 'pono':
                if (isFooterRow(r)) {
                    inner = `<strong class="pcw-footer-label">${esc(r.pono || r.matgroup || r.validation_result || '—')}</strong>`;
                } else {
                    inner = wrapExplain(key, index, `<span class="pcw-mat">${dash(r.pono || r.purchase_order)}</span>`);
                }
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
                // Quotation stays a link to sap_reports/zcost (not API popover)
                inner = isFooterRow(r)
                    ? '—'
                    : quotationLinkHtml(
                        cellValue(key, r, index),
                        cellValue('costing_item_no', r, index)
                    );
                break;
            case 'costing_item_no':
                if (isFooterRow(r)) {
                    inner = '—';
                } else if (needsManualMatch(r) || !!r._editingMatch) {
                    inner = zcostSelectHtml(r, index);
                } else {
                    inner = wrapExplain(
                        key,
                        index,
                        `<span class="pcw-nav-chip" title="zCOST ITEM">${dash(cellValue(key, r, index))}</span>`
                    );
                }
                break;
            case 'maktx':
                if (isFooterRow(r)) {
                    inner = '—';
                } else {
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
            case 'conv':
            case 'actual_cost': {
                const mismatch = !!r.cost_mismatch && (key === 'budget_cost_per_gmt' || key === 'actual_cost');
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
                const disp = statusDisplay(row);
                const st = disp.code;
                const open = needsDropdown(row) ? ' is-open' : '';
                const unmatched = !isFooterRow(row) && needsManualMatch(row) && st !== 'PASS';
                const pass = !unmatched && st === 'PASS' ? ' is-pass-row' : '';
                const review = !unmatched && st === 'NEEDS_REVIEW' ? ' is-review-row' : '';
                const fail = !unmatched && (st === 'NOT_VALIDATED' || st === 'EXCEPTION' || st === 'FAIL') ? ' is-fail-row' : '';
                const unmatchedCls = unmatched ? ' is-unmatched-row' : '';
                const sel = row.selected ? ' is-selected' : '';
                const footer = isFooterRow(row) ? ` is-footer-row is-${String(row.row_kind || 'footer')}` : '';
                const mismatch = row.cost_mismatch && !unmatched && st !== 'PASS' ? ' is-cost-mismatch' : '';
                return `<tr class="pcw-row${open}${pass}${review}${fail}${unmatchedCls}${sel}${footer}${mismatch}" data-idx="${index}" data-row-kind="${esc(row.row_kind || 'detail')}">${COLS.map((k) => renderCell(k, row, index)).join('')}</tr>`;
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

    function openValidateModal() {
        if (cfg.canValidate === false) return;
        const selected = rows.filter((r) => !!r.selected && !isFooterRow(r));
        const detailRows = rows.filter((r) => !isFooterRow(r));
        const targets = selected.length ? selected : detailRows;
        const loginUser = String(cfg.currentUser || $('pcwLoginUserName')?.textContent || 'User').trim();
        if ($('pcwLoginUserName')) $('pcwLoginUserName').textContent = loginUser || 'User';
        const meta = $('pcwValidateMeta');
        if (meta) {
            meta.innerHTML = `
                <div class="pcw-validate-meta-chip"><span>Purchase Order</span><strong>${esc(headerPo() || '—')}</strong></div>
                <div class="pcw-validate-meta-chip"><span>Scope</span><strong>Full PO · ${targets.length} lines</strong></div>
                <div class="pcw-validate-meta-chip"><span>Validated By</span><strong>${esc(loginUser || '—')}</strong></div>`;
        }
        const remarks = $('pcwValidateRemarks');
        if (remarks) remarks.value = '';
        document.querySelectorAll('input[name="pcwValidateStatus"]').forEach((el, i) => {
            el.checked = i === 0;
            el.closest('label')?.classList.toggle('is-active', i === 0);
        });
        closeHistoryModal();
        openActionOffcanvas('pcwValidateModal');
        $('pcwUserSearch')?.focus();
    }

    function closeHistoryModal() {
        closeActionOffcanvas('pcwHistoryModal');
    }

    async function openHistoryModal() {
        const po = headerPo();
        const body = $('pcwHistoryBody');
        if (!po) {
            alert('Load a PO first to view history.');
            return;
        }
        const countEl = $('pcwHistoryCount');
        if (countEl) countEl.textContent = 'Loading…';
        if (body) body.innerHTML = `<div class="pcw-history-empty">Loading validation history…</div>`;
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
            if (!body) return;
            // Collapse legacy per-line saves into one PO validation per batch
            const grouped = [];
            const seen = new Map();
            logs.forEach((log) => {
                const when = String(log.created_at || '').replace('T', ' ').slice(0, 19);
                const key = [
                    when,
                    String(log.created_by || ''),
                    String(log.assigned_username || ''),
                    String(log.validation_status || ''),
                    String(log.description || ''),
                ].join('|');
                if (seen.has(key)) {
                    const g = seen.get(key);
                    g.item_count += 1;
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
            if (!grouped.length) {
                body.innerHTML = `<div class="pcw-history-empty">No validation history for PO ${esc(po)}.</div>`;
                return;
            }
            body.innerHTML = grouped
                .map((log) => {
                    const st = String(log.validation_status || '');
                    const stLabel = st === 'VALIDATE_PO' ? 'Validate PO' : st === 'NOT_VALIDATE' ? 'Not Validate' : st;
                    const byUser = String(log.created_by || '').trim() || '—';
                    const whenFull = String(log.when || '—');
                    const datePart = whenFull.slice(0, 10);
                    const timePart = whenFull.length > 10 ? whenFull.slice(11) : '';
                    const remarks = String(log.description || '').trim();
                    return `<article class="pcw-history-card" role="listitem">
                        <div class="pcw-history-when">
                            <strong>${esc(datePart || '—')}</strong>
                            <small>${esc(timePart || '')}</small>
                        </div>
                        <div class="pcw-history-main">
                            <div class="pcw-history-people">
                                <span><span class="material-icons-round">account_circle</span>By <strong>${esc(byUser)}</strong></span>
                                <span><span class="material-icons-round">person_add</span>Assigned <strong>${esc(log.assigned_username || '—')}</strong></span>
                            </div>
                            <p class="pcw-history-remarks${remarks ? '' : ' is-empty'}">${esc(remarks || 'No remarks')}</p>
                        </div>
                        <span class="pcw-pill ${st === 'VALIDATE_PO' ? 'is-pass' : 'is-review'}">${esc(stLabel)}</span>
                    </article>`;
                })
                .join('');
        } catch (err) {
            if (countEl) countEl.textContent = 'Error';
            if (body) {
                body.innerHTML = `<div class="pcw-history-empty">${esc(err.message || 'Failed to load history')}</div>`;
            }
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
        const status =
            document.querySelector('input[name="pcwValidateStatus"]:checked')?.value || 'VALIDATE_PO';
        const description = String($('pcwValidateRemarks')?.value || '').trim();
        if (!assignedUser.username) {
            alert('Select a next-level user to assign.');
            $('pcwUserSearch')?.focus();
            return;
        }
        const po = headerPo();
        if (!po) return;
        const detailRows = rows.filter((r) => !isFooterRow(r));
        const endpoint = cfg.validationLogUrl || 'purchase-order/validation-log';
        // One PO-level validation entry (not per line item)
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
            item_count: detailRows.length,
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
            const json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            detailRows.forEach((r) => {
                r.assigned_username = assignedUser.username;
                r.manual_validation_status = status;
                r.manual_validation_desc = description;
                if (status === 'VALIDATE_PO' && String(r.status || '').toUpperCase() !== 'EXCEPTION') {
                    r.validation_result = `Validate PO — assigned to ${assignedUser.username}`;
                } else if (status === 'NOT_VALIDATE') {
                    r.status = 'NEEDS_REVIEW';
                    r.validation_result = `Not Validate — assigned to ${assignedUser.username}`;
                }
            });
            closeValidateModal();
            fillKpis(summarizeRows(rows));
            renderTable();
            const toast = document.createElement('div');
            toast.className = 'pcw-toast is-ok';
            toast.textContent = status === 'VALIDATE_PO'
                ? `Saved: Validate PO → ${assignedUser.username}`
                : `Saved: Not Validate → ${assignedUser.username}`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 2800);
        } catch (err) {
            const msg = String(err.message || 'Could not save validation log');
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
        $('pcwValidateCancel')?.addEventListener('click', closeValidateModal);
        $('pcwValidateBackdrop')?.addEventListener('click', closeValidateModal);
        $('pcwValidateSave')?.addEventListener('click', () => {
            void saveValidationLog();
        });
        $('pcwViewPdfBtn')?.addEventListener('click', () => {
            openPdfModal(headerPo());
        });
        $('pcwOpenPdfBtn')?.addEventListener('click', () => {
            openPdfTab(headerPo());
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
            if (cfg.canPassPo === false) return;
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
