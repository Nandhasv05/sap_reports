/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Sale Costing — search particular PO → detail + PO Items aside
*/
(function () {
    const page = document.getElementById('pscPage');
    if (!page) return;

    const $ = (id) => document.getElementById(id);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
    const dash = (v) => (v === '' || v == null ? '—' : esc(v));
    const money = (n) => {
        if (n === '' || n == null || !Number.isFinite(Number(n))) return '—';
        return '₹ ' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    const qty = (n) => {
        if (n === '' || n == null || !Number.isFinite(Number(n))) return '—';
        return Number(n).toLocaleString('en-IN', { maximumFractionDigits: 3 });
    };
    const num = (n, d = 2) => {
        if (n === '' || n == null || !Number.isFinite(Number(n))) return '—';
        return Number(n).toLocaleString('en-IN', { maximumFractionDigits: d });
    };
    const digits = (v) => String(v ?? '').replace(/\D/g, '');
    const fmtDate = (iso) => {
        const s = String(iso || '');
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
        if (m) {
            const mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][Number(m[2]) - 1];
            return `${m[3]}-${mon}-${m[1]}`;
        }
        const d = new Date(s);
        if (!Number.isNaN(d.getTime())) {
            const dd = String(d.getDate()).padStart(2, '0');
            const mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getMonth()];
            return `${dd}-${mon}-${d.getFullYear()}`;
        }
        return dash(iso);
    };

    const cfg = {
        validateUrl: page.dataset.validateUrl || 'purchase-order/validate',
        passUrl: page.dataset.passUrl || 'purchase-order/pass',
        manualMapUrl: page.dataset.manualMapUrl || 'purchase-order/manual-map',
        chargesUrl: page.dataset.chargesUrl || 'purchase-order/charges',
        portalRevertUrl: page.dataset.portalRevertUrl || 'purchase-order/portal-revert',
        pdfUrl: page.dataset.pdfUrl || 'purchase-order/pdf',
        purchaseOrderUrl: page.dataset.purchaseOrderUrl || 'purchase-order',
        canPass: page.dataset.canPassPo === '1',
        canValidate: page.dataset.canValidate === '1',
        canSapRevert: page.dataset.canSapRevert === '1',
        canPortalRevert: page.dataset.canPortalRevert === '1',
        canEsign: page.dataset.canEsign === '1',
        startPo: digits(page.dataset.startPo || ''),
        startMat: String(page.dataset.startMat || 'trims').toLowerCase(),
    };

    let activePo = '';
    let matCat = 'all';
    let validatePayload = null;
    let costLines = [];
    let asideItems = [];
    let activeItemKey = '';
    let activeTab = 'so';
    let chargesSummary = null;
    let chargesLoading = false;
    let matchRow = null;
    let matchPickKey = '';
    let formulaAnchor = null;

    function setLoading(on, message) {
        if (!window.rptLoader) return;
        if (on) {
            window.rptLoader.show({
                solid: true,
                message: message || 'Loading…',
                steps: message
                    ? [String(message), 'Fetching from SAP…', 'Preparing results…']
                    : ['Connecting…', 'Loading PO…', 'Preparing validation…'],
            });
        } else if (typeof window.rptLoader.hide === 'function') {
            window.rptLoader.hide(true);
        }
    }

    /**
     * UI column key → SAP OData field
     * From Z_QUOTATIONZCOSTCOM_CDS / Z_QuotationZCostCom (+ ZCOSTI* for costing)
     * Aside "Qty"  → OrderQuantity (tot_poqty)
     * Aside "Rate" → NetPriceAmount / OrderQuantity (porate)
     */
    const API_COL_REF = {
        status: {
            api: 'ActualCost ≤ TOTALINR/GROSS',
            source: 'Z_QuotationZCostCom + ZCOSTI',
            desc: 'PASS when ActualCost ≤ RATE (budget per GMT).',
        },
        salordno: { api: 'SalesOrderReference', source: 'Z_QuotationZCostCom', desc: 'Sales order number.' },
        style: { api: 'style', source: 'Z_QuotationZCostCom', desc: 'FG Material / style code from sales order.' },
        so_qty: { api: 'SaleOrderQuantity', source: 'Z_QuotationZCostCom', desc: 'Sales order quantity (garment qty).' },
        fg_order_qty: { api: 'SaleOrderQuantity', source: 'Z_QuotationZCostCom', desc: 'FG Order QTY = SaleOrderQuantity.' },
        fg_qty_bom: {
            api: 'FG_HEAD_QTY',
            source: 'Z_QuotationZCostCom / TEMPFIN',
            desc: 'FG QTY (BOM) = FG_HEAD_QTY (BOM FG head quantity).',
        },
        required_qty_bom: { api: 'ReqBOMQuantity', source: 'Z_QuotationZCostCom', desc: 'Required QTY (BOM) = ReqBOMQuantity.' },
        consumption: {
            api: 'ReqBOMQuantity / FG_HEAD_QTY',
            source: 'Z_QuotationZCostCom / TEMPFIN',
            desc: 'CONSUMPTION = Required QTY (BOM) ÷ FG QTY (BOM) [FG_HEAD_QTY].',
        },
        total_req_with_cons: {
            api: 'FG_HEAD_QTY × CONSUMPTION',
            source: 'Derived',
            desc: 'Total Req.Qty with Consumption = FG QTY (BOM) [FG_HEAD_QTY] × CONSUMPTION.',
        },
        total_so_qty: {
            api: 'Σ ReqBOMQuantity',
            source: 'Z_QuotationZCostCom',
            desc: 'Total SO QTY = sum of ReqBOMQuantity across SO lines for this PO item.',
        },
        so_wise_qty: {
            api: 'OrderQuantity × Total Req.Qty with Consumption / Total SO QTY',
            source: 'Z_QuotationZCostCom',
            desc: 'SO Wise QTY = OrderQuantity × Total Req.Qty with Consumption ÷ Total SO QTY.',
        },
        so_wise_value: {
            api: 'PO Rate × SO Wise QTY × CONSUMPTION',
            source: 'Z_QuotationZCostCom',
            desc: 'SO Wise Value = PO Rate × SO Wise QTY × CONSUMPTION.',
        },
        waste: { api: 'WSTE', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Wastage percentage from costing line.' },
        reqqty: { api: 'ReqBOMQuantity', source: 'Z_QuotationZCostCom', desc: 'Required BOM quantity.' },
        budgeted_cons: { api: 'GROSS', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Budgeted / costing requirement (gross).' },
        budget_cost_per_gmt: {
            api: 'TOTALINR / GROSS',
            source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS',
            desc: 'Budget / costing rate per piece = TOTALINR ÷ GROSS.',
        },
        tot_poqty: { api: 'OrderQuantity', source: 'Z_QuotationZCostCom', desc: 'PO order quantity (aside Qty).' },
        porate: {
            api: 'NetPriceAmount / OrderQuantity',
            source: 'Z_QuotationZCostCom',
            desc: 'PO unit rate (aside Rate) = NetPriceAmount ÷ OrderQuantity.',
        },
        totvalue: { api: 'NetPriceAmount', source: 'Z_QuotationZCostCom', desc: 'PO line net amount.' },
        qty_variance: {
            api: 'OrderQuantity − ReqBOMQuantity',
            source: 'Z_QuotationZCostCom',
            desc: 'Quantity variance = SO-wise PO qty (OrderQuantity) − BOM requirement (ReqBOMQuantity).',
        },
        rate_variance: {
            api: '(NetPriceAmount/OrderQuantity) − (TOTALINR/GROSS)',
            source: 'Z_QuotationZCostCom + ZCOSTI',
            desc: 'Rate variance = PO rate − costing rate.',
        },
        material: { api: 'Material', source: 'Z_QuotationZCostCom', desc: 'Material number.' },
        ouom: { api: 'PurchaseOrderQuantityUnit', source: 'Z_QuotationZCostCom', desc: 'PO quantity unit.' },
        costing_quotation_no: { api: 'CostingQuotationNo', source: 'Z_QuotationZCostCom', desc: 'zCOST quotation number.' },
        zsection: { api: 'ZSECTION', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Costing section.' },
        maktx: { api: 'MAKTX', source: 'ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS', desc: 'Costing line description.' },
        actual_cost: {
            api: 'PORATE (INR) with UoM rules',
            source: 'TEMPFIN / PASSPO',
            desc: 'ACTUAL_COST = PORATE × cons (classic unit rules). Buttons with EA/GRO ÷144 when priced per gross — same as workbench.',
        },
        variance_pct: {
            api: '(Actual − TOTALINR/GROSS) ÷ (TOTALINR/GROSS) × 100',
            source: 'Z_QuotationZCostCom + ZCOSTI',
            desc: 'Variance % = (Actual − Budget) ÷ Budget × 100.',
        },
        poitem: { api: 'PurchaseOrderItem', source: 'Z_QuotationZCostCom', desc: 'Purchase order item.' },
    };

    function cellBtn(key, idx, html, extraClass = '') {
        return `<button type="button" class="pcw-formula-cell${extraClass ? ` ${extraClass}` : ''}" data-explain="${esc(key)}" data-idx="${idx}">${html}</button>`;
    }

    function toast(msg, ms = 2800) {
        const el = $('pscToast');
        if (!el) return;
        el.textContent = msg;
        el.hidden = false;
        clearTimeout(toast._t);
        toast._t = setTimeout(() => { el.hidden = true; }, ms);
    }

    function showLookup() {
        if ($('pscLookup')) $('pscLookup').hidden = false;
        if ($('pscDetail')) $('pscDetail').hidden = true;
        if ($('pscHeaderForm')) $('pscHeaderForm').hidden = true;
        if ($('pscPageTitle')) $('pscPageTitle').hidden = false;
        page.classList.add('is-psc-lookup');
        page.classList.remove('is-psc-detail');
        syncPassButton();
    }

    function showDetail() {
        if ($('pscLookup')) $('pscLookup').hidden = true;
        if ($('pscDetail')) $('pscDetail').hidden = false;
        if ($('pscHeaderForm')) $('pscHeaderForm').hidden = false;
        if ($('pscPageTitle')) $('pscPageTitle').hidden = true;
        if ($('pscHdrPo') && activePo) $('pscHdrPo').value = activePo;
        page.classList.add('is-psc-detail');
        page.classList.remove('is-psc-lookup');
        syncPassButton();
    }

    function setUrlPo(po) {
        try {
            const u = new URL(window.location.href);
            if (po) u.searchParams.set('po', po);
            else u.searchParams.delete('po');
            u.searchParams.delete('mat_cat');
            history.pushState({ pscPo: po || '', matCat: 'all' }, '', u.toString());
        } catch (_) {}
    }

    function workbenchUrl(po, action) {
        const base = cfg.purchaseOrderUrl || 'purchase-order';
        const u = new URL(base, window.location.origin);
        u.searchParams.set('view', 'costing');
        if (po) u.searchParams.set('po', po);
        u.searchParams.set('mat_cat', 'all');
        if (action) u.searchParams.set('open', action);
        return u.pathname + u.search;
    }

    function pdfUrlFor(po) {
        const clean = digits(po || activePo);
        if (!clean) return '';
        const base = cfg.pdfUrl || 'purchase-order/pdf';
        const u = new URL(base, window.location.href);
        u.searchParams.set('po', clean);
        u.searchParams.set('_', String(Date.now()));
        return `${u.toString()}#page=2&toolbar=0&navpanes=0&scrollbar=0&view=FitH`;
    }

    function syncItemActions() {
        const inDetail = page.classList.contains('is-psc-detail') && !!activePo;
        const pdfBtn = $('pscOpenPdfBtn');
        const remarksBtn = $('pscRemarksBtn');
        const esignBtn = $('pscEsignBtn');
        const sentBtn = $('pscSentBtn');
        if (pdfBtn) {
            pdfBtn.disabled = !inDetail;
            pdfBtn.title = inDetail ? 'Open PO PDF in a new tab' : 'Load a purchase order first';
        }
        if (remarksBtn) {
            const ok = inDetail && cfg.canValidate;
            remarksBtn.disabled = !ok;
            remarksBtn.title = !cfg.canValidate
                ? 'Remarks permission required'
                : (inDetail ? 'Status & Remarks' : 'Load a purchase order first');
        }
        if (esignBtn) {
            const ok = inDetail && cfg.canEsign;
            esignBtn.disabled = !ok;
            esignBtn.title = !cfg.canEsign
                ? 'eSign permission required'
                : (inDetail ? 'Open PO E-Signature' : 'Load a purchase order first');
        }
        if (sentBtn) {
            const ok = inDetail && cfg.canEsign;
            sentBtn.disabled = !ok;
            sentBtn.title = !cfg.canEsign
                ? 'eSign / send permission required'
                : (inDetail ? 'Send this PO to a user' : 'Load a purchase order first');
        }
    }

    function openPdfTab() {
        if (!activePo) {
            toast('Load a purchase order first');
            return;
        }
        const url = pdfUrlFor(activePo);
        if (!url) {
            toast('Unable to build PDF URL');
            return;
        }
        window.open(url, '_blank', 'noopener');
    }

    function openWorkbenchAction(action) {
        if (!activePo) {
            toast('Load a purchase order first');
            return;
        }
        if ((action === 'esign' || action === 'sent') && !cfg.canEsign) {
            toast('You do not have eSign access');
            return;
        }
        if ((action === 'remarks' || action === 'validate') && !cfg.canValidate) {
            toast('You do not have Remarks access');
            return;
        }
        const url = workbenchUrl(activePo, action);
        window.open(url, '_blank', 'noopener');
    }

    function selectedMat() {
        return 'all';
    }

    function setMat(mat, opts = {}) {
        matCat = 'all';
        if ($('pscMat')) $('pscMat').value = 'all';
        if (opts.reload && activePo) {
            void openPo(activePo);
        } else if (activePo) {
            setUrlPo(activePo);
        }
    }

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

    function hasCostingLink(r) {
        if (!r) return false;
        if (r.manual_match) return true;
        if (String(r.matched_line_key || '').trim()) return true;
        if (String(r.cost_item || r.zcost_item || r.costing_item_no || '').trim()) return true;
        // zCOST description only (not PO material_desc)
        if (String(r.maktx || '').trim()) return true;
        if (String(r.cost_description || '').trim()
            && String(r.cost_description).trim() !== String(r.mat_desc || r.material_desc || '').trim()) {
            return true;
        }
        if (String(r.costing_quotation_no || r.matched_quotation || '').trim()
            && (nVal(r.budget_cost_per_gmt) > 0 || nVal(r.budget_cost_per_piece) > 0 || nVal(r.total_inr) > 0)) {
            return true;
        }
        return false;
    }

    function needsMapping(r) {
        if (!r) return false;
        // Already mapped / VALID in workbench → never "Mapping Required"
        if (hasCostingLink(r)) return false;
        const st = String(r.status || '').toUpperCase().replace(/\s+/g, '_');
        if (st === 'PASS' || st === 'VALID' || st === 'APPROVED') return false;
        const result = String(r.validation_result || '');
        if (/^pass\b/i.test(result) || /\bvalid\b/i.test(result)) return false;

        if (r.needs_manual_match || r.family_mismatch || r._zcost_pending) return true;
        if (st === 'NOT_MATCHED' || st === 'MAPPING_REQUIRED') return true;
        // Do NOT treat rate/qty "does not match" as mapping required
        if (/mapping required|no matching|no linked|not matched|cannot load|no zcost/i.test(result)) {
            return true;
        }
        return true; // no costing link yet
    }

    function itemNeedsMap(item) {
        if (!item) return false;
        return (item.rows || []).some((r) => needsMapping(r));
    }

    /** Workbench-aligned budget ceiling for RESULT compare (prefer TOTALINR piece). */
    function budgetForCompare(r) {
        if (!r) return null;
        const piece = nVal(r.budget_cost_per_piece) ?? nVal(r.budget_cost_per_garment);
        if (piece !== null && piece > 0) return piece;
        const tot = nVal(r.total_inr) ?? nVal(r.cost_total_inr) ?? nVal(r.budget_inr);
        if (tot !== null && tot > 0) return tot;
        const gmt = nVal(r.budget_cost_per_gmt) ?? nVal(r.expected_unit_cost);
        return (gmt !== null && gmt > 0) ? gmt : null;
    }

    function isButtonFamily(r) {
        const mg = String(r?.matgroup || r?.material_group || '').toUpperCase();
        const matDesc = String(r?.mat_desc || r?.material_desc || r?.description || r?.maktx || '').toUpperCase();
        return mg.includes('BUTTON') || mg.includes('SEMI DULL') || mg.includes('HOOK')
            || mg.includes('PEARL BTN') || mg.includes('BADGE') || mg.includes('WASHER')
            || matDesc.includes('BTN') || matDesc.includes('BUTTON') || matDesc.includes('PLASTIC')
            || matDesc.includes('WASHER') || matDesc.includes('SNAP') || matDesc.includes('SHANK')
            || matDesc.includes('BADGE');
    }

    /** Recompute ACTUAL_COST + PASS/NEEDS_REVIEW like workbench after validate/map. */
    function syncRowActualAndStatus(r) {
        if (!r || String(r.row_kind || '').toLowerCase() === 'footer') return;
        const a = actualCostInr(r);
        if (a !== null && a > 0) {
            r.actual_cost = a;
            r.po_unit_cost = a;
        }
        if (needsMapping(r)) return;
        const budget = budgetForCompare(r);
        const actual = actualForCompare(r);
        if (budget !== null && budget > 0 && actual !== null && actual > 0) {
            r.variance_pct = Math.round((((actual - budget) / budget) * 100) * 100) / 100;
            if (actual <= budget + 0.005) {
                r.status = 'PASS';
                if (!r.validation_result || /need|review|fail|exception/i.test(String(r.validation_result))) {
                    r.validation_result = 'PASS';
                }
                r.cost_mismatch = false;
            } else {
                r.status = 'NEEDS_REVIEW';
                r.validation_result = 'Need to Review — actual exceeds costing budget';
                r.cost_mismatch = true;
            }
        }
    }

    /** Same ACTUAL_COST rules as purchase-order costing workbench (buttons GRO/EA ÷144). */
    function actualCostInr(r) {
        if (!r) return null;
        const porate = nVal(r.porate ?? r.net_price);
        if (porate === null || porate <= 0) {
            const stored = nVal(r.actual_cost ?? r.po_unit_cost);
            return (stored !== null && stored > 0) ? stored : null;
        }
        const fx = nVal(r.budget_conv_rt) ?? nVal(r.convr);
        const rate = (fx !== null && fx > 0) ? fx : 1;
        let unitConv = nVal(r.conv);
        if (unitConv === null || unitConv <= 0) unitConv = 1;
        let cons = nVal(r.consumption);
        if (cons === null || cons <= 0) cons = 1;
        const gross = nVal(r.gross) ?? nVal(r.budgeted_cons);
        if (gross !== null && gross > 1.05 && Math.abs(cons - gross) < 0.0001) cons = 1;

        const mg = String(r.matgroup || r.material_group || '').toUpperCase();
        const ouom = String(r.ouom || '').toUpperCase();
        const buom = String(r.buom || '').toUpperCase();
        if (rate > 1 && Math.abs(unitConv - rate) < 0.0001) unitConv = 1;
        if (unitConv > 20) unitConv = 1;

        let out = null;
        if (mg === 'THREADS1' && buom === 'CPS') {
            out = Math.round(((porate / 1000) * unitConv) * 10000) / 10000;
        } else if (mg === 'THREADS1' && buom === 'BOX') {
            out = Math.round(((porate / 10000) * unitConv) * 10000) / 10000;
        } else {
            const applyFx = r.porate_needs_fx === true && r.porate_already_inr !== true;
            const base = applyFx ? (porate * rate) : porate;
            const mid = base * unitConv;
            const buttonLike = isButtonFamily(r);
            const isGro = ouom === 'GRO' || buom === 'GRO';
            const isEa = ouom === 'EA' || buom === 'EA' || ouom === 'PCS' || buom === 'PCS';
            // Buttons priced per gross (144) — same as workbench
            if (buttonLike && isGro) {
                out = Math.round((Math.round(mid * cons) / 144) * 10000) / 10000;
            } else if (buttonLike && isEa && (mid * cons) > 1.5) {
                out = Math.round((Math.round(mid * cons) / 144) * 10000) / 10000;
            } else if (ouom === 'KG' || buom === 'KG') {
                out = Math.round(((mid * cons) / 500) * 10000) / 10000;
            } else {
                out = Math.round((mid * cons) * 10000) / 10000;
            }
        }
        return (out !== null && out > 0) ? out : null;
    }

    function actualForCompare(r) {
        if (!r) return null;
        const a = actualCostInr(r);
        if (a !== null && a > 0) return a;
        const stored = nVal(r.actual_cost) ?? nVal(r.po_unit_cost);
        return (stored !== null && stored > 0) ? stored : null;
    }

    /** Same RESULT buckets as purchase-order costing workbench. */
    function rowStatusDisplay(r) {
        if (!r || String(r.row_kind || '').toLowerCase() === 'footer') {
            return { key: 'ok', cls: 'is-ok', icon: 'check_circle', label: 'Valid' };
        }
        if (needsMapping(r)) {
            return { key: 'mapping', cls: 'is-mapping', icon: 'error', label: 'Mapping Required' };
        }
        const budget = budgetForCompare(r);
        const actual = actualForCompare(r);
        if (budget !== null && budget > 0 && actual !== null && actual > 0) {
            if (actual > budget + 0.005) {
                return { key: 'warn', cls: 'is-warn', icon: 'warning', label: 'Need to Review' };
            }
            return { key: 'ok', cls: 'is-ok', icon: 'check_circle', label: 'Valid' };
        }
        const st = String(r.status || '').toUpperCase().replace(/\s+/g, '_');
        if (st === 'PASS' || st === 'VALID') {
            return { key: 'ok', cls: 'is-ok', icon: 'check_circle', label: 'Valid' };
        }
        if (st === 'NEEDS_REVIEW' || st === 'AMBIGUOUS' || st === 'EXCEPTION' || st === 'FAIL') {
            return { key: 'warn', cls: 'is-warn', icon: 'warning', label: 'Need to Review' };
        }
        if (hasCostingLink(r)) {
            // Mapped but missing actual/budget numbers — still Valid if API says PASS
            if (/^pass\b/i.test(String(r.validation_result || ''))) {
                return { key: 'ok', cls: 'is-ok', icon: 'check_circle', label: 'Valid' };
            }
            return { key: 'warn', cls: 'is-warn', icon: 'warning', label: 'Need to Review' };
        }
        return { key: 'mapping', cls: 'is-mapping', icon: 'error', label: 'Mapping Required' };
    }

    function itemStatus(rows) {
        let mapping = 0;
        let warn = 0;
        let ok = 0;
        rows.forEach((r) => {
            const st = rowStatusDisplay(r);
            if (st.key === 'mapping') mapping += 1;
            else if (st.key === 'warn' || st.key === 'fail') warn += 1;
            else ok += 1;
        });
        if (mapping) return { key: 'mapping', cls: 'is-mapping', icon: 'error', label: 'Mapping Required' };
        if (warn) return { key: 'warn', cls: 'is-warn', icon: 'warning', label: 'Need to Review' };
        return { key: 'ok', cls: 'is-ok', icon: 'check_circle', label: 'Valid' };
    }

    function applyCostLineToRow(r, line) {
        if (!r || !line) return;
        const rawLineBudget = lineBudgetPerGmt(line);
        const lineBudget = (rawLineBudget !== null && rawLineBudget > 0) ? rawLineBudget : null;
        const tempfinBudget = nVal(r.budget_cost_per_gmt ?? r.expected_unit_cost);
        const finalBudget = (lineBudget !== null && lineBudget > 0)
            ? lineBudget
            : ((tempfinBudget !== null && tempfinBudget > 0) ? tempfinBudget : null);

        r.zcost_item = String(line.zcost_item || line.item || line.costing_item_no || '');
        r.cost_item = r.zcost_item;
        r.costing_item_no = r.zcost_item;
        r.costing_quotation_no = String(line.costing_quotation_no || line.quotation_no || line.parent_quotation_no || '');
        r.maktx = String(line.maktx || line.description || '');
        r.cost_description = r.maktx;
        r.cost_section = String(line.section || line.zsection || '');
        r.zsection = String(line.zsection || line.section || '');
        r.gross = nVal(line.gross) ?? r.gross;
        const totalInr = nVal(line.total_inr);
        if (totalInr !== null) {
            r.total_inr = totalInr;
            r.cost_total_inr = totalInr;
            r.budget_inr = totalInr;
            r.budget_cost_per_piece = Math.round(totalInr * 10000) / 10000;
        }
        if (nVal(line.gross) !== null && nVal(line.gross) > 0) {
            r.budgeted_cons = nVal(line.gross);
        }
        if (line.currency) r.budget_currency = String(line.currency);
        if (nVal(line.convr) !== null && nVal(line.convr) > 0) r.budget_conv_rt = nVal(line.convr);
        if (finalBudget !== null && finalBudget > 0) {
            r.budget_cost_per_gmt = finalBudget;
            r.expected_unit_cost = finalBudget;
        }
        if (line.unit || line.buom || line.uom) {
            r.buom = String(line.unit || line.buom || line.uom || r.buom || '');
        }
        if (!r.ouom && (r.buom || line.unit)) {
            r.ouom = String(r.buom || line.unit || '');
        }
        r.manual_match = true;
        r.needs_manual_match = false;
        r.family_mismatch = false;
        r._zcost_pending = false;
        r.matched_quotation = String(line.quotation_no || line.costing_quotation_no || '');
        r.matched_line_no = String(line.line_no || '');
        syncRowActualAndStatus(r);
        if (String(r.status || '').toUpperCase() === 'PASS') {
            r.validation_result = 'PASS — manually matched to zCOST line';
        }
    }

    function applySavedManualMappings(maps, records) {
        if (!Array.isArray(maps) || !maps.length || !Array.isArray(records)) return records;
        const byItem = new Map();
        maps.forEach((m) => {
            if (!m) return;
            const item = String(m.po_item || '').replace(/\D/g, '').replace(/^0+/, '') || String(m.po_item || '').trim();
            if (!item) return;
            const mat = String(m.material || '').trim().toLowerCase();
            byItem.set(`${item}|${mat}`, m);
            if (!byItem.has(`${item}|`)) byItem.set(`${item}|`, m);
        });
        records.forEach((r) => {
            if (!r || String(r.row_kind || '').toLowerCase() === 'footer') return;
            if (hasCostingLink(r)) {
                r.needs_manual_match = false;
                return;
            }
            const item = String(r.poitem || r.po_item || '').replace(/\D/g, '').replace(/^0+/, '')
                || String(r.poitem || r.po_item || '').trim();
            const mat = String(r.material || '').trim().toLowerCase();
            const m = byItem.get(`${item}|${mat}`) || byItem.get(`${item}|`) || null;
            if (!m) return;
            const zItem = String(m.zcost_item || '').trim();
            const maktx = String(m.maktx || '').trim();
            const section = String(m.zsection || '').trim();
            const quote = String(m.quotation_no || '').trim();
            if (zItem) {
                r.zcost_item = zItem;
                r.cost_item = zItem;
                r.costing_item_no = zItem;
            }
            if (maktx) {
                r.maktx = maktx;
                r.cost_description = maktx;
            }
            if (section) {
                r.zsection = section;
                r.cost_section = section;
            }
            if (quote) {
                r.costing_quotation_no = quote;
                r.matched_quotation = quote;
            }
            const tot = nVal(m.total_inr);
            const gross = nVal(m.gross);
            if (tot !== null && tot > 0) {
                r.total_inr = tot;
                r.budget_cost_per_piece = tot;
            }
            if (tot !== null && tot > 0 && gross !== null && gross > 0) {
                r.gross = gross;
                r.budget_cost_per_gmt = Math.round((tot / gross) * 10000) / 10000;
                r.expected_unit_cost = r.budget_cost_per_gmt;
            } else if (nVal(m.budget_cost_per_gmt) > 0) {
                r.budget_cost_per_gmt = nVal(m.budget_cost_per_gmt);
                r.expected_unit_cost = r.budget_cost_per_gmt;
            }
            if (m.matched_line_key) r.matched_line_key = String(m.matched_line_key);
            r.manual_match = true;
            r.needs_manual_match = false;
            r.family_mismatch = false;
            r._zcost_pending = false;
            if (!r.status || /not_matched|mapping/i.test(String(r.status))) {
                r.status = 'PASS';
                r.validation_result = 'PASS — saved manual mapping';
            }
        });
        return records;
    }

    function buildAsideItems(records) {
        const map = new Map();
        (records || []).forEach((r) => {
            if (String(r.row_kind || '').toLowerCase() === 'footer') return;
            const key = String(r.po_item || r.poitem || r.item || '') || String(r.material || '');
            if (!key) return;
            if (!map.has(key)) {
                map.set(key, {
                    key,
                    po_item: key,
                    material: r.material || '',
                    name: r.material_desc || r.mat_desc || r.description || r.maktx || r.material || `Item ${key}`,
                    uom: r.ouom || r.unit || r.buom || 'EA',
                    po_qty: 0,
                    po_rate: Number(r.porate || r.net_price || 0) || 0,
                    bom_qty: 0,
                    costing_rate: 0,
                    costing_rate_n: 0,
                    rows: [],
                });
            }
            const it = map.get(key);
            it.rows.push(r);
            if (it.rows.length === 1) {
                it.po_qty = Number(r.tot_poqty || r.po_qty || r.order_qty || 0) || 0;
                it.po_rate = Number(r.porate || r.net_price || 0) || it.po_rate;
            }
            it.bom_qty += Number(r.reqqty || r.req_bom_quantity || 0) || 0;
            const cr = Number(r.budget_cost_per_gmt || r.expected_unit_cost || r.rate || 0);
            if (Number.isFinite(cr) && cr > 0) {
                it.costing_rate += cr;
                it.costing_rate_n += 1;
            }
        });
        return Array.from(map.values()).map((it) => {
            it.avg_costing_rate = it.costing_rate_n ? (it.costing_rate / it.costing_rate_n) : 0;
            it.qty_var = Number((it.po_qty - it.bom_qty).toFixed(3));
            it.qty_var_pct = it.bom_qty ? Number(((it.qty_var / it.bom_qty) * 100).toFixed(2)) : 0;
            it.status = itemStatus(it.rows);
            return it;
        });
    }

    async function openPo(po) {
        const clean = digits(po);
        if (!clean) {
            const err = $('pscLookupError');
            if (err) {
                err.hidden = false;
                err.textContent = 'Enter a valid purchase order number.';
            }
            return;
        }
        activePo = clean.replace(/^0+/, '') || clean;
        if ($('pscHdrPo')) $('pscHdrPo').value = activePo;
        if ($('pscPoInput')) $('pscPoInput').value = activePo;
        showDetail();
        setUrlPo(activePo);

        try {
            setLoading(true, `Validating PO ${activePo}…`);
            const params = new URLSearchParams({
                po: activePo,
                mat_cat: selectedMat(),
                mode: 'validated',
                _: String(Date.now()),
            });
            const res = await fetch(`${cfg.validateUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || !json || json.success === false) {
                throw new Error(json?.message || json?.error || 'Validation failed');
            }
            validatePayload = json.data || json;
            costLines = Array.isArray(validatePayload.cost_lines) ? validatePayload.cost_lines : [];
            const records = Array.isArray(validatePayload.records) ? validatePayload.records : [];
            applySavedManualMappings(
                Array.isArray(validatePayload.manual_mappings) ? validatePayload.manual_mappings : [],
                records
            );
            // Clear stale mapping flags + align ACTUAL (buttons GRO÷144) with workbench Valid
            records.forEach((r) => {
                if (hasCostingLink(r)) {
                    r.needs_manual_match = false;
                    r.family_mismatch = false;
                    r._zcost_pending = false;
                }
                syncRowActualAndStatus(r);
            });
            validatePayload.records = records;
            chargesSummary = validatePayload.summary
                ? { ...validatePayload.summary, _fromSap: false }
                : null;
            asideItems = buildAsideItems(records);
            renderDetailShell();
            syncPassButton();
            if (asideItems.length) {
                selectItem(asideItems[0].key, { openMap: false });
                void loadChargesFromSap(activePo);
            } else {
                renderEmptyItem();
                const apiErr = String(
                    validatePayload.error
                    || validatePayload.flow?.step3_error
                    || validatePayload.costing?.error
                    || json?.message
                    || ''
                ).trim();
                toast(apiErr || `No PO items found for ${activePo}. Check the full PO number and try again.`);
            }
        } catch (err) {
            toast(err.message || 'Unable to open PO');
            validatePayload = null;
            costLines = [];
            asideItems = [];
            chargesSummary = null;
            renderDetailShell();
            renderEmptyItem();
            syncPassButton();
        } finally {
            setLoading(false);
        }
    }

    function overallAllPass() {
        if (!asideItems.length) return false;
        return asideItems.every((it) => it.status && it.status.key === 'ok');
    }

    function syncPassButton() {
        const btn = $('pscPassBtn');
        const statusEl = $('pscPassStatus');
        const sapBtn = $('pscSapRevertBtn');
        const portalBtn = $('pscPortalRevertBtn');
        const approvedRaw = String(
            validatePayload?.approved_status
            || validatePayload?.header?.approved_status
            || validatePayload?.header?.ApprovedStatus
            || ''
        );
        const approved = approvedRaw.toLowerCase();
        const isApproved = approved.includes('approv') && !approved.includes('not');
        const canPassApi = validatePayload?.summary?.can_pass_po;
        const hasMapping = asideItems.some((it) => it.status.key === 'mapping');
        const allPass = overallAllPass();
        const inDetail = page.classList.contains('is-psc-detail') && !!activePo;
        const canPassNow = cfg.canPass && inDetail && !isApproved && allPass
            && !hasMapping && canPassApi !== false;

        let statusText = 'STATUS : —';
        let statusCls = '';
        if (inDetail) {
            if (isApproved) {
                statusText = `STATUS : ${approvedRaw || 'Approved'}`;
                statusCls = 'is-passed';
            } else if (hasMapping) {
                statusText = 'STATUS : Mapping Required';
                statusCls = 'is-pending';
            } else if (!allPass) {
                statusText = 'STATUS : Need Review';
                statusCls = 'is-partial';
            } else {
                statusText = `STATUS : ${approvedRaw || 'NotApproved'}`;
                statusCls = 'is-pending';
            }
        }
        if (statusEl) {
            statusEl.textContent = statusText;
            statusEl.className = `pcw-cb-status${statusCls ? ` ${statusCls}` : ''}`;
        }

        // Pass PO — hide once Approved (same as workbench)
        if (btn) {
            const showPass = inDetail && cfg.canPass && !isApproved;
            btn.hidden = !showPass;
            btn.disabled = !canPassNow;
            btn.textContent = 'PASS PO';
            btn.title = !cfg.canPass
                ? 'No permission to Pass PO'
                : isApproved
                    ? 'PO already approved'
                    : (hasMapping
                        ? 'Map all required items before Pass PO'
                        : (!allPass
                            ? 'All items must be Valid before Pass PO'
                            : (canPassApi === false ? 'Validation exceptions remain' : 'Pass PO')));
        }

        // SAP Revert — only when Approved
        if (sapBtn) {
            const showSap = inDetail && cfg.canSapRevert && isApproved;
            sapBtn.hidden = !showSap;
            sapBtn.disabled = !showSap;
            sapBtn.title = !cfg.canSapRevert
                ? 'No permission to SAP Revert'
                : !isApproved
                    ? 'SAP Revert is available only when STATUS is Approved'
                    : 'SAP Revert — POApprovalSet Value = ""';
        }

        // Portal Revert — available whenever a PO is open (clears portal log/PDF)
        if (portalBtn) {
            const showPortal = inDetail && cfg.canPortalRevert;
            portalBtn.hidden = !showPortal;
            portalBtn.disabled = !showPortal;
            portalBtn.title = !cfg.canPortalRevert
                ? 'No permission to Portal Revert'
                : 'Portal Revert — delete po_release_log + PO PDF files';
        }

        syncItemActions();
    }

    function renderDetailShell() {
        const summary = validatePayload?.summary || {};

        let validCount = 0;
        let mappingCount = 0;
        let reviewCount = 0;
        asideItems.forEach((it) => {
            const key = it?.status?.key || 'ok';
            if (key === 'mapping') mappingCount += 1;
            else if (key === 'warn' || key === 'fail') reviewCount += 1;
            else validCount += 1;
        });
        if ($('pscSumValid')) $('pscSumValid').textContent = String(validCount);
        if ($('pscSumMapping')) $('pscSumMapping').textContent = String(mappingCount);
        if ($('pscSumReview')) $('pscSumReview').textContent = String(reviewCount);
        if ($('pscSumValue')) {
            $('pscSumValue').textContent = money(
                summary.total_po_value ?? summary.grand_total ?? summary.sub_total
            );
        }

        if ($('pscAsideCount')) $('pscAsideCount').textContent = String(asideItems.length);
        const list = $('pscAsideList');
        if (list) {
            list.innerHTML = asideItems.map((it, idx) => `
                <button type="button" class="pwv-aside-item${it.key === activeItemKey ? ' is-on' : ''}"
                        role="option" data-key="${esc(it.key)}" aria-selected="${it.key === activeItemKey ? 'true' : 'false'}">
                    <span class="pwv-aside-num">${idx + 1}</span>
                    <span>
                        <div class="pwv-aside-name">${esc(it.name)}</div>
                        <div class="pwv-aside-sub" title="Qty = OrderQuantity · Rate = NetPriceAmount ÷ OrderQuantity">Qty: ${qty(it.po_qty)} | Rate: ${money(it.po_rate)}</div>
                    </span>
                    <span class="material-icons-round pwv-aside-ico ${it.status.cls}" aria-hidden="true">${it.status.icon}</span>
                </button>
            `).join('') || '<div class="pwv-aside-sub" style="padding:16px">No PO items returned.</div>';
        }

        fillChargesPanel(chargesSummary || summary);
        syncPassButton();
    }

    function emptyCharges() {
        return {
            other: 0, freight: 0, moq: 0, screen: 0, bank: 0,
            discount: 0, sgst: 0, cgst: 0, igst: 0, total: 0,
        };
    }

    function round2(n) {
        return Math.round((Number(n) || 0) * 100) / 100;
    }

    function moneyPlain(n) {
        return Number(n || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function fillChargesPanel(summary) {
        const panel = $('pscChargesPanel');
        const body = $('pscChargesBody');
        if (!panel || !body) return;
        const s = summary || {};
        const charges = (s.charges && typeof s.charges === 'object')
            ? s.charges
            : emptyCharges();
        const sub = Number(s.document_sub_total ?? s.sub_total ?? s.total_po_value) || 0;
        const grand = Number(s.grand_total);
        const grandVal = Number.isFinite(grand) && grand > 0
            ? grand
            : round2(sub + (Number(charges.total) || 0));
        const curr = s.currency || 'INR';
        if ($('pscChargeGrandCurr')) $('pscChargeGrandCurr').textContent = curr;

        const cols = [
            { key: 'sub', amount: sub, kind: 'sub' },
            { key: 'discount', amount: Number(charges.discount) || 0, kind: 'charge' },
            { key: 'freight', amount: Number(charges.freight) || 0, kind: 'charge' },
            { key: 'moq', amount: Number(charges.moq) || 0, kind: 'charge' },
            { key: 'other', amount: Number(charges.other) || 0, kind: 'charge' },
            { key: 'screen', amount: Number(charges.screen) || 0, kind: 'charge' },
            { key: 'bank', amount: Number(charges.bank) || 0, kind: 'charge' },
            { key: 'sgst', amount: Number(charges.sgst) || 0, kind: 'tax' },
            { key: 'cgst', amount: Number(charges.cgst) || 0, kind: 'tax' },
            { key: 'igst', amount: Number(charges.igst) || 0, kind: 'tax' },
            { key: 'total', amount: grandVal, kind: 'total' },
        ];

        body.innerHTML = `<tr>${cols.map((c) => {
            const active = c.kind !== 'sub' && c.kind !== 'total' && Math.abs(c.amount) > 0.0001;
            const cls = [
                'num',
                active ? 'is-active' : '',
                c.kind === 'sub' ? 'is-sub' : '',
                c.kind === 'tax' && active ? 'is-tax' : '',
                c.kind === 'total' ? 'is-total' : '',
            ].filter(Boolean).join(' ');
            return `<td class="${cls}" data-charge="${esc(c.key)}">${moneyPlain(c.amount)}</td>`;
        }).join('')}</tr>`;

        const chargesFoot = $('pscChargesFoot');
        if (chargesFoot) {
            chargesFoot.hidden = false;
            if ($('pscChargesTotGrand')) $('pscChargesTotGrand').textContent = moneyPlain(grandVal);
        }

        if ($('pscChargesStatus')) {
            const hasAny = cols.some((c) => c.kind !== 'sub' && c.kind !== 'total' && Math.abs(c.amount) > 0.0001);
            $('pscChargesStatus').textContent = chargesLoading
                ? 'Refreshing SAP charges…'
                : (hasAny
                    ? `Source: ${s.charges_source || s.source || 'to_Cond / to_GST'}`
                    : 'No additional charges on this PO');
        }
    }

    async function loadChargesFromSap(po, { force = false } = {}) {
        const poDigits = digits(po || activePo);
        if (!poDigits || chargesLoading) return;
        if (!force && chargesSummary?.charges && chargesSummary._fromSap) {
            fillChargesPanel(chargesSummary);
            return;
        }
        chargesLoading = true;
        fillChargesPanel(chargesSummary || validatePayload?.summary || {});
        try {
            const endpoint = cfg.chargesUrl || 'purchase-order/charges';
            const url = `${endpoint}${endpoint.includes('?') ? '&' : '?'}po=${encodeURIComponent(poDigits)}&_=${Date.now()}`;
            const res = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const raw = await res.json().catch(() => ({}));
            const data = raw?.data && typeof raw.data === 'object' ? raw.data : raw;
            const apiCharges = (data && data.charges && typeof data.charges === 'object')
                ? data.charges
                : (raw.charges && typeof raw.charges === 'object' ? raw.charges : null);
            if (!apiCharges) {
                if ($('pscChargesStatus')) {
                    $('pscChargesStatus').textContent = raw?.message || 'Charges unavailable';
                }
                return;
            }
            const base = chargesSummary || validatePayload?.summary || {};
            const docSub = Number(data.document_sub_total ?? data.sub_total
                ?? raw?.document_sub_total ?? raw?.sub_total
                ?? base.document_sub_total ?? base.sub_total ?? base.total_po_value) || 0;
            const merged = {
                ...base,
                charges: apiCharges,
                charges_total: Number(apiCharges.total) || 0,
                document_sub_total: docSub,
                sub_total: docSub || Number(base.sub_total ?? base.total_po_value) || 0,
                currency: data.currency || raw.currency || base.currency || 'INR',
                charges_source: data.source || raw.source || 'Z_QuotationZCostCom+to_Cond+to_GST',
                _fromSap: true,
            };
            const sub = Number(merged.sub_total) || 0;
            merged.grand_total = Number(data.grand_total ?? raw?.grand_total) > 0
                ? Number(data.grand_total ?? raw?.grand_total)
                : round2(sub + (Number(apiCharges.total) || 0));
            chargesSummary = merged;
            if (validatePayload?.summary) {
                validatePayload.summary = {
                    ...validatePayload.summary,
                    charges: merged.charges,
                    charges_total: merged.charges_total,
                    document_sub_total: merged.document_sub_total,
                    sub_total: merged.sub_total,
                    grand_total: merged.grand_total,
                };
            }
            fillChargesPanel(merged);
        } catch (_) {
            if ($('pscChargesStatus')) {
                $('pscChargesStatus').textContent = 'Unable to refresh charges';
            }
        } finally {
            chargesLoading = false;
            fillChargesPanel(chargesSummary || validatePayload?.summary || {});
        }
    }

    function renderEmptyItem() {
        if ($('pscItemTitle')) $('pscItemTitle').textContent = 'PO Item Validation';
        if ($('pscItemMeta')) {
            $('pscItemMeta').textContent = activePo
                ? `No items returned for PO ${activePo}. Enter the full purchase order number.`
                : 'Material Code: — | UOM: —';
        }
        if ($('pscItemStatus')) {
            $('pscItemStatus').textContent = 'No Data';
            $('pscItemStatus').className = 'pwv-result-badge is-warn';
        }
        ['pscMetPoQty', 'pscMetBom', 'pscMetVar', 'pscMetPoRate', 'pscMetCostRate'].forEach((id) => {
            if ($(id)) $(id).textContent = '—';
        });
        ['pscSoBody', 'pscBomBody', 'pscCostBody'].forEach((id) => {
            if ($(id)) {
                $(id).innerHTML = activePo
                    ? `<tr class="pwv-empty-row"><td colspan="12">No PO items found for ${esc(activePo)}. Verify the full PO number in SAP / workbench.</td></tr>`
                    : '<tr class="pwv-empty-row"><td colspan="12">No item data.</td></tr>';
            }
        });
        ['pscSoFoot', 'pscBomFoot', 'pscCostFoot', 'pscSummaryFoot', 'pscChargesFoot'].forEach((id) => {
            if ($(id)) $(id).hidden = true;
        });
        renderCoverage(null);
        renderChecks(null);
    }

    function selectItem(key, opts = {}) {
        activeItemKey = String(key || '');
        const item = asideItems.find((x) => x.key === activeItemKey) || null;
        document.querySelectorAll('#pscAsideList .pwv-aside-item').forEach((btn) => {
            const on = btn.getAttribute('data-key') === activeItemKey;
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (!item) {
            renderEmptyItem();
            return;
        }
        if ($('pscItemTitle')) $('pscItemTitle').textContent = `PO Item Validation - ${item.name}`;
        if ($('pscItemMeta')) {
            $('pscItemMeta').textContent = `Material Code: ${item.material || item.po_item || '—'} | UOM: ${item.uom || 'EA'}`;
        }
        if ($('pscItemStatus')) {
            const st = item.status || { key: 'ok', cls: 'is-ok', label: 'Valid' };
            const badgeCls = st.key === 'ok' ? 'is-ok' : (st.key === 'mapping' ? 'is-mapping' : 'is-warn');
            $('pscItemStatus').textContent = st.label;
            $('pscItemStatus').className = `pwv-result-badge ${badgeCls}`;
        }
        if ($('pscMetPoQty')) $('pscMetPoQty').textContent = `${qty(item.po_qty)} ${esc(item.uom)}`;
        if ($('pscMetBom')) $('pscMetBom').textContent = `${qty(item.bom_qty)} ${esc(item.uom)}`;
        if ($('pscMetVar')) {
            const sign = item.qty_var > 0 ? '+' : '';
            $('pscMetVar').textContent = `${sign}${qty(item.qty_var)} ${esc(item.uom)} (${sign}${num(item.qty_var_pct)}%)`;
            $('pscMetVar').classList.toggle('is-warn', item.qty_var < 0);
        }
        if ($('pscMetPoRate')) $('pscMetPoRate').textContent = `${money(item.po_rate)} / ${esc(item.uom)}`;
        if ($('pscMetCostRate')) $('pscMetCostRate').textContent = `${money(item.avg_costing_rate)} / ${esc(item.uom)}`;
        renderItemTabs(item);
        renderCoverage(item);
        renderChecks(item);
        // Mapping modal only when Mapping Required — never for already Valid / mapped items
        if (opts.openMap && itemNeedsMap(item)) {
            openMatchPicker(item);
        }
    }

    function formatExplainValue(key, r) {
        if (!r) return '—';
        if (key === 'status') return rowStatusDisplay(r).label;
        if (key === 'salordno') return String(r.salordno || r.sales_order || '—');
        if (key === 'style') return String(r.style || r.fg_material || r.Style || '—');
        if (key === 'so_qty' || key === 'fg_order_qty') {
            return String(nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.sale_order_quantity) ?? '—');
        }
        if (key === 'fg_qty_bom') {
            return String(
                nVal(r.fg_head_qty) ?? nVal(r.FG_HEAD_QTY) ?? nVal(r.fg_qty_bom)
                ?? nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.sale_order_quantity) ?? '—'
            );
        }
        if (key === 'required_qty_bom' || key === 'reqqty') {
            return String(nVal(r.reqqty) ?? nVal(r.req_bom_quantity) ?? '—');
        }
        if (key === 'consumption') {
            const fgBom = nVal(r.fg_head_qty) ?? nVal(r.FG_HEAD_QTY) ?? nVal(r.fg_qty_bom)
                ?? nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.sale_order_quantity);
            const req = nVal(r.reqqty) ?? nVal(r.req_bom_quantity);
            if (fgBom && fgBom > 0 && req !== null) return String(Number((req / fgBom).toFixed(6)));
            return String(nVal(r.consumption) ?? nVal(r.budgeted_cons) ?? '—');
        }
        if (key === 'total_req_with_cons') {
            const fgBom = nVal(r.fg_head_qty) ?? nVal(r.FG_HEAD_QTY) ?? nVal(r.fg_qty_bom)
                ?? nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.sale_order_quantity) ?? 0;
            const req = nVal(r.reqqty) ?? nVal(r.req_bom_quantity) ?? 0;
            const cons = (fgBom > 0) ? (req / fgBom) : (nVal(r.consumption) ?? 0);
            return String(Number((fgBom * cons).toFixed(4)));
        }
        if (key === 'total_so_qty') return String(nVal(r._total_so_qty) ?? nVal(r.reqqty) ?? nVal(r.req_bom_quantity) ?? '—');
        if (key === 'so_wise_qty') return String(nVal(r._so_wise_qty) ?? nVal(r.so_wise_poqty) ?? '—');
        if (key === 'so_wise_value') return String(nVal(r._so_wise_value) ?? nVal(r.so_wise_po_val) ?? '—');
        if (key === 'waste') return String(nVal(r.waste) ?? nVal(r.wastage) ?? '—');
        if (key === 'budgeted_cons') return String(nVal(r.budgeted_cons) ?? nVal(r.reqqty) ?? '—');
        if (key === 'budget_cost_per_gmt') return String(nVal(r.budget_cost_per_gmt) ?? nVal(r.expected_unit_cost) ?? nVal(r.rate) ?? '—');
        if (key === 'porate') return String(nVal(r.porate) ?? nVal(r.net_price) ?? '—');
        if (key === 'qty_variance') {
            const bomReq = nVal(r.reqqty) ?? nVal(r.req_bom_quantity) ?? 0;
            const soPo = nVal(r.so_wise_poqty);
            const v = soPo !== null ? (soPo - bomReq) : null;
            return v === null ? '—' : String(v);
        }
        if (key === 'rate_variance') {
            const poRate = nVal(r.porate) ?? nVal(r.net_price) ?? 0;
            const costRate = nVal(r.budget_cost_per_gmt) ?? nVal(r.expected_unit_cost) ?? 0;
            return String(Number((poRate - costRate).toFixed(4)));
        }
        if (key === 'material') return String(r.material || '—');
        if (key === 'ouom') return String(r.ouom || r.buom || '—');
        if (key === 'costing_quotation_no') return String(r.costing_quotation_no || r.quotation || r.vbeln || '—');
        if (key === 'zsection') return String(r.zsection || r.cost_section || '—');
        if (key === 'maktx') return String(r.maktx || r.cost_description || r.material_desc || '—');
        if (key === 'actual_cost') return String(actualCostInr(r) ?? nVal(r.actual_cost) ?? nVal(r.porate) ?? '—');
        if (key === 'variance_pct') return String(nVal(r.variance_pct) ?? '—');
        if (key === 'poitem') return String(r.poitem || r.po_item || '—');
        const raw = r[key];
        return raw === '' || raw == null ? '—' : String(raw);
    }

    function explainFor(key, r) {
        const meta = API_COL_REF[key];
        if (!meta) return null;
        const original = formatExplainValue(key, r);
        const steps = [
            { label: 'SAP API column', value: meta.api },
            { label: 'Entity', value: meta.source },
            { label: 'Original value', value: original },
        ];

        if (key === 'porate' || key === 'tot_poqty') {
            const poQty = nVal(r.tot_poqty) ?? nVal(r.order_qty) ?? nVal(r.po_qty);
            const net = nVal(r.totvalue) ?? nVal(r.net_price_amount) ?? nVal(r.net_amount);
            const rate = nVal(r.porate) ?? nVal(r.net_price);
            steps.push({ label: 'OrderQuantity (PO Qty)', value: poQty === null ? '—' : String(poQty) });
            steps.push({ label: 'NetPriceAmount', value: net === null ? '—' : String(net) });
            if (key === 'porate' && poQty && net !== null) {
                steps.push({
                    label: 'Formula',
                    value: `NetPriceAmount ${net} ÷ OrderQuantity ${poQty} = ${rate ?? Number((net / poQty).toFixed(4))}`,
                });
            }
        }
        if (key === 'fg_qty_bom' || key === 'consumption' || key === 'total_req_with_cons'
            || key === 'so_wise_qty' || key === 'so_wise_value') {
            const fgBom = nVal(r.fg_head_qty) ?? nVal(r.FG_HEAD_QTY) ?? nVal(r.fg_qty_bom)
                ?? nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.sale_order_quantity) ?? 0;
            const req = nVal(r.reqqty) ?? nVal(r.req_bom_quantity) ?? 0;
            const cons = fgBom > 0 ? Number((req / fgBom).toFixed(6)) : (nVal(r.consumption) ?? 0);
            const totalReq = Number((fgBom * cons).toFixed(4));
            const totalSo = nVal(r._total_so_qty) ?? req;
            const orderQty = nVal(r.tot_poqty) ?? nVal(r.order_qty) ?? nVal(r.po_qty) ?? 0;
            const poRate = nVal(r.porate) ?? nVal(r.net_price) ?? 0;
            const soWiseQty = totalSo > 0 ? Number(((orderQty * totalReq) / totalSo).toFixed(4)) : 0;
            steps.push({ label: 'FG_HEAD_QTY (FG QTY BOM)', value: String(fgBom) });
            steps.push({ label: 'Required QTY (BOM)', value: String(req) });
            steps.push({ label: 'CONSUMPTION', value: `${req} ÷ ${fgBom} = ${cons}` });
            if (key === 'total_req_with_cons' || key === 'so_wise_qty' || key === 'so_wise_value') {
                steps.push({ label: 'Total Req.Qty with Consumption', value: `${fgBom} × ${cons} = ${totalReq}` });
            }
            if (key === 'so_wise_qty' || key === 'so_wise_value') {
                steps.push({ label: 'OrderQuantity', value: String(orderQty) });
                steps.push({ label: 'Total SO QTY', value: String(totalSo) });
                steps.push({
                    label: 'SO Wise QTY',
                    value: `${orderQty} × ${totalReq} ÷ ${totalSo} = ${soWiseQty}`,
                });
            }
            if (key === 'so_wise_value') {
                steps.push({ label: 'PO Rate', value: String(poRate) });
                steps.push({
                    label: 'SO Wise Value',
                    value: `${poRate} × ${soWiseQty} × ${cons} = ${Number((poRate * soWiseQty * cons).toFixed(2))}`,
                });
            }
        }
        if (key === 'qty_variance') {
            const bomReq = nVal(r.reqqty) ?? nVal(r.req_bom_quantity);
            const soPo = nVal(r.so_wise_poqty) ?? nVal(r.tot_poqty) ?? nVal(r.order_qty);
            steps.push({ label: 'OrderQuantity / SO-wise PO qty', value: soPo === null ? '—' : String(soPo) });
            steps.push({ label: 'ReqBOMQuantity', value: bomReq === null ? '—' : String(bomReq) });
        }
        if (key === 'rate_variance') {
            const poRate = nVal(r.porate) ?? nVal(r.net_price);
            const costRate = nVal(r.budget_cost_per_gmt) ?? nVal(r.expected_unit_cost);
            steps.push({ label: 'PO rate (NetPriceAmount/OrderQuantity)', value: poRate === null ? '—' : String(poRate) });
            steps.push({ label: 'Costing rate (TOTALINR/GROSS)', value: costRate === null ? '—' : String(costRate) });
        }
        if (key === 'status') {
            steps.push({ label: 'Row status', value: rowStatusDisplay(r).label });
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
        const pop = $('pscFormulaPop');
        if (pop) pop.hidden = true;
        document.querySelectorAll('.pcw-formula-cell.is-active, .pwv-metric.is-active').forEach((el) => {
            el.classList.remove('is-active');
        });
        formulaAnchor = null;
    }

    function positionFormulaPop(anchor) {
        const pop = $('pscFormulaPop');
        if (!pop || !anchor) return;
        pop.hidden = false;
        const rect = anchor.getBoundingClientRect();
        const pad = 10;
        const pw = pop.offsetWidth || 320;
        const ph = pop.offsetHeight || 240;
        let left = rect.left;
        let top = rect.bottom + 8;
        if (left + pw > window.innerWidth - pad) left = Math.max(pad, window.innerWidth - pw - pad);
        if (top + ph > window.innerHeight - pad) top = Math.max(pad, rect.top - ph - 8);
        if (left < pad) left = pad;
        pop.style.left = `${left}px`;
        pop.style.top = `${top}px`;
    }

    function explainMetric(key, item) {
        if (!item) return null;
        const rows = item.rows || [];
        const first = rows[0] || {};
        const uom = item.uom || 'EA';
        if (key === 'po_qty') {
            const poQty = item.po_qty;
            const net = nVal(first.totvalue) ?? nVal(first.net_price_amount);
            return {
                title: 'PO Quantity',
                desc: 'PO order quantity for this item (OrderQuantity).',
                formula: 'OrderQuantity',
                example: 'Source: Z_QuotationZCostCom',
                steps: [
                    { label: 'PO Item', value: String(item.po_item || '—') },
                    { label: 'Material', value: String(item.material || '—') },
                    { label: 'OrderQuantity', value: `${qty(poQty)} ${uom}` },
                    { label: 'NetPriceAmount', value: net == null ? '—' : String(net) },
                    { label: 'SO lines', value: String(rows.length) },
                ],
                resultLabel: 'PO Quantity',
                resultExpr: `= ${qty(poQty)} ${uom}`,
            };
        }
        if (key === 'bom_qty') {
            const parts = rows.map((r, i) => {
                const rq = nVal(r.reqqty) ?? nVal(r.req_bom_quantity) ?? 0;
                const so = r.salordno || r.sales_order || `SO ${i + 1}`;
                return { label: `ReqBOMQuantity · ${so}`, value: `${qty(rq)} ${uom}` };
            });
            return {
                title: 'Total BOM Requirement',
                desc: 'Sum of ReqBOMQuantity across sales-order lines for this PO item.',
                formula: 'Σ ReqBOMQuantity',
                example: 'Source: Z_QuotationZCostCom',
                steps: [
                    { label: 'PO Item', value: String(item.po_item || '—') },
                    ...parts,
                    { label: 'Lines counted', value: String(rows.length) },
                ],
                resultLabel: 'Total BOM Requirement',
                resultExpr: `= ${qty(item.bom_qty)} ${uom}`,
            };
        }
        if (key === 'qty_var') {
            const label = item.qty_var < 0 ? 'Shortage' : (item.qty_var > 0 ? 'Surplus' : 'Balanced');
            return {
                title: 'Quantity Variance',
                desc: 'PO Quantity minus Total BOM Requirement for this material.',
                formula: 'OrderQuantity − Σ ReqBOMQuantity',
                example: 'Source: Z_QuotationZCostCom',
                steps: [
                    { label: 'PO Quantity (OrderQuantity)', value: `${qty(item.po_qty)} ${uom}` },
                    { label: 'Total BOM Requirement', value: `${qty(item.bom_qty)} ${uom}` },
                    { label: 'Variance % vs BOM', value: `${item.qty_var_pct}%` },
                    { label: 'Coverage', value: label },
                ],
                resultLabel: 'Quantity Variance',
                resultExpr: `= ${qty(item.qty_var)} ${uom} (${label})`,
            };
        }
        if (key === 'po_rate') {
            const poQty = nVal(first.tot_poqty) ?? nVal(first.order_qty) ?? item.po_qty;
            const net = nVal(first.totvalue) ?? nVal(first.net_price_amount);
            const rate = item.po_rate;
            const steps = [
                { label: 'PO Item', value: String(item.po_item || '—') },
                { label: 'OrderQuantity', value: poQty == null ? '—' : String(poQty) },
                { label: 'NetPriceAmount', value: net == null ? '—' : String(net) },
                { label: 'UOM', value: uom },
            ];
            if (poQty && net != null) {
                steps.push({
                    label: 'Formula',
                    value: `NetPriceAmount ${net} ÷ OrderQuantity ${poQty} = ${rate}`,
                });
            }
            return {
                title: 'PO Rate',
                desc: 'Unit PO rate = NetPriceAmount ÷ OrderQuantity.',
                formula: 'NetPriceAmount / OrderQuantity',
                example: 'Source: Z_QuotationZCostCom',
                steps,
                resultLabel: 'PO Rate',
                resultExpr: `= ${money(rate)} / ${uom}`,
            };
        }
        if (key === 'cost_rate') {
            const parts = rows.map((r, i) => {
                const cr = nVal(r.budget_cost_per_gmt) ?? nVal(r.expected_unit_cost) ?? nVal(r.rate);
                const tot = nVal(r.total_inr) ?? nVal(r.budget_cost_per_piece);
                const gross = nVal(r.gross) ?? nVal(r.budgeted_cons);
                const so = r.salordno || r.sales_order || `Line ${i + 1}`;
                let detail = cr == null ? '—' : String(cr);
                if (tot != null && gross != null && gross > 0) {
                    detail = `TOTALINR ${tot} ÷ GROSS ${gross} = ${cr ?? Number((tot / gross).toFixed(4))}`;
                }
                return { label: `Budget rate · ${so}`, value: detail };
            });
            return {
                title: 'Avg. Costing Rate',
                desc: 'Average of TOTALINR ÷ GROSS across costing-linked SO lines.',
                formula: 'AVG(TOTALINR / GROSS)',
                example: 'Source: ZCOSTI / ZCOSTIT / ZCOSTIP / ZCOSTIS',
                steps: [
                    { label: 'PO Item', value: String(item.po_item || '—') },
                    ...parts,
                    { label: 'Lines averaged', value: String(item.costing_rate_n || rows.length) },
                ],
                resultLabel: 'Avg. Costing Rate',
                resultExpr: `= ${money(item.avg_costing_rate)} / ${uom}`,
            };
        }
        return null;
    }

    function showDetailPop(info, anchor) {
        const pop = $('pscFormulaPop');
        if (!info || !pop) return;
        closeFormulaPop();
        formulaAnchor = anchor;
        if (anchor) anchor.classList.add('is-active');
        if (pop.parentElement !== document.body) document.body.appendChild(pop);

        $('pscFormulaTitle').textContent = info.title;
        $('pscFormulaDesc').textContent = info.desc || '';

        const exprBox = $('pscFormulaExpr');
        if (info.formula) {
            exprBox.hidden = false;
            $('pscFormulaExprText').textContent = info.formula;
        } else {
            exprBox.hidden = true;
        }

        const stepsBox = $('pscFormulaSteps');
        const list = $('pscFormulaStepsList');
        const result = $('pscFormulaResult');
        if (info.steps && info.steps.length) {
            stepsBox.hidden = false;
            list.innerHTML = info.steps.map((s) =>
                `<li><span class="pcw-formula-dot"></span><span class="pcw-formula-step-label">${esc(s.label)}</span><strong>${esc(s.value)}</strong></li>`
            ).join('');
            result.innerHTML = `<span class="pcw-formula-dot is-solid"></span><span class="pcw-formula-step-label">${esc(info.resultLabel || info.title)}</span><div class="pcw-formula-result-expr">${esc(info.resultExpr || '')}</div>`;
        } else {
            stepsBox.hidden = true;
            list.innerHTML = '';
            result.innerHTML = '';
        }

        const ex = $('pscFormulaExample');
        if (info.example) {
            ex.hidden = false;
            $('pscFormulaExampleText').textContent = info.example;
        } else {
            ex.hidden = true;
        }
        positionFormulaPop(anchor);
    }

    function openFormulaPop(key, r, anchor) {
        showDetailPop(explainFor(key, r), anchor);
    }

    function openMetricPop(key, item, anchor) {
        showDetailPop(explainMetric(key, item), anchor);
    }

    function renderItemTabs(item) {
        const rows = item.rows || [];
        const soBody = $('pscSoBody');
        const soFoot = $('pscSoFoot');
        if (soBody) {
            let totGarment = 0;
            let totBomReq = 0;
            let totCostReq = 0;
            let totQtyVar = 0;
            soBody.innerHTML = rows.map((r, i) => {
                const so = r.salordno || r.sales_order || (Array.isArray(r.sales_orders) ? r.sales_orders[0] : '') || '—';
                const fgMat = String(r.style || r.fg_material || r.Style || '').trim();
                // Garment Qty column ← so_qty (SaleOrderQuantity)
                const garment = nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.sale_order_quantity) ?? 0;
                const cons = Number(r.consumption || r.budgeted_cons || 0) || 0;
                const waste = Number(r.waste || r.wastage || 0) || 0;
                const bomReq = Number(r.reqqty || r.req_bom_quantity || 0) || 0;
                const costReq = Number(r.budgeted_cons || r.reqqty || 0) || 0;
                const costRate = Number(r.budget_cost_per_gmt || r.expected_unit_cost || r.rate || 0) || 0;
                const poRate = Number(r.porate || r.net_price || item.po_rate || 0) || 0;
                const qVar = Number((Number(r.so_wise_poqty || 0) || bomReq) - bomReq) || (item.po_qty - bomReq);
                const rVar = Number((poRate - costRate).toFixed(4));
                const st = rowStatusDisplay(r);
                totGarment += Number(garment) || 0;
                totBomReq += Number(bomReq) || 0;
                totCostReq += Number(costReq) || 0;
                totQtyVar += Number(qVar) || 0;
                return `<tr data-row-idx="${i}">
                    <td>${i + 1}</td>
                    <td>${cellBtn('salordno', i, `<span class="pwv-po-link">SO-${esc(so)}</span>`)}</td>
                    <td>${cellBtn('style', i, dash(fgMat))}</td>
                    <td>${cellBtn('so_qty', i, qty(garment))}</td>
                    <td>${cellBtn('consumption', i, num(cons, 4))}</td>
                    <td>${cellBtn('waste', i, num(waste, 2))}</td>
                    <td>${cellBtn('reqqty', i, qty(bomReq))}</td>
                    <td>${cellBtn('budgeted_cons', i, qty(costReq))}</td>
                    <td>${cellBtn('budget_cost_per_gmt', i, num(costRate, 2))}</td>
                    <td>${cellBtn('porate', i, num(poRate, 2))}</td>
                    <td>${cellBtn('qty_variance', i, qty(qVar), qVar < 0 ? 'pwv-met-warn' : '')}</td>
                    <td>${cellBtn('rate_variance', i, num(rVar, 2))}</td>
                    <td>${cellBtn('status', i, `<span class="pwv-badge ${st.cls === 'is-ok' ? 'is-valid' : st.cls}">${esc(st.label)}</span>`)}</td>
                </tr>`;
            }).join('') || '<tr class="pwv-empty-row"><td colspan="13">No sales-order lines.</td></tr>';

            if (soFoot) {
                if (rows.length) {
                    soFoot.hidden = false;
                    if ($('pscSoTotGarment')) $('pscSoTotGarment').textContent = qty(totGarment);
                    if ($('pscSoTotBomReq')) $('pscSoTotBomReq').textContent = qty(totBomReq);
                    if ($('pscSoTotCostReq')) $('pscSoTotCostReq').textContent = qty(totCostReq);
                    if ($('pscSoTotQtyVar')) {
                        $('pscSoTotQtyVar').textContent = qty(totQtyVar);
                        $('pscSoTotQtyVar').classList.toggle('pwv-met-warn', totQtyVar < 0);
                    }
                } else {
                    soFoot.hidden = true;
                }
            }
        } else if (soFoot) {
            soFoot.hidden = true;
        }

        const bomBody = $('pscBomBody');
        const bomFoot = $('pscBomFoot');
        if (bomBody) {
            let totFgOrder = 0;
            let totFgBom = 0;
            let totBomReq = 0;
            let totReqCons = 0;
            let totSoWiseQty = 0;
            let totSoWiseVal = 0;
            const totalSoQty = rows.reduce((sum, r) => (
                sum + (Number(r.reqqty || r.req_bom_quantity || 0) || 0)
            ), 0);
            const orderQty = Number(
                item.po_qty
                || rows[0]?.tot_poqty
                || rows[0]?.order_qty
                || rows[0]?.po_qty
                || 0
            ) || 0;
            const poRate = Number(
                item.po_rate
                || rows[0]?.porate
                || rows[0]?.net_price
                || 0
            ) || 0;

            bomBody.innerHTML = rows.map((r, i) => {
                const fgOrder = nVal(r.so_qty) ?? nVal(r.prwise_so_qty) ?? nVal(r.sale_order_quantity) ?? 0;
                // FG QTY (BOM) ← FG_HEAD_QTY (fallback SaleOrderQuantity when missing)
                const fgBom = nVal(r.fg_head_qty) ?? nVal(r.FG_HEAD_QTY) ?? nVal(r.fg_qty_bom) ?? fgOrder;
                const reqBom = Number(r.reqqty || r.req_bom_quantity || 0) || 0;
                const cons = (fgBom > 0)
                    ? Number((reqBom / fgBom).toFixed(6))
                    : (Number(r.consumption || r.budgeted_cons || 0) || 0);
                const totalReqWithCons = Number((fgBom * cons).toFixed(4));
                const soWiseQty = totalSoQty > 0
                    ? Number(((orderQty * totalReqWithCons) / totalSoQty).toFixed(4))
                    : 0;
                const soWiseVal = Number((poRate * soWiseQty * cons).toFixed(2));
                r._total_so_qty = totalSoQty;
                r._so_wise_qty = soWiseQty;
                r._so_wise_value = soWiseVal;

                totFgOrder += Number(fgOrder) || 0;
                totFgBom += Number(fgBom) || 0;
                totBomReq += reqBom;
                totReqCons += totalReqWithCons;
                totSoWiseQty += soWiseQty;
                totSoWiseVal += soWiseVal;

                return `<tr data-row-idx="${i}">
                <td>${i + 1}</td>
                <td>${cellBtn('salordno', i, esc(r.salordno || r.sales_order || '—'))}</td>
                <td>${cellBtn('material', i, dash(r.material || item.material))}</td>
                <td>${cellBtn('fg_order_qty', i, qty(fgOrder))}</td>
                <td>${cellBtn('fg_qty_bom', i, qty(fgBom))}</td>
                <td>${cellBtn('required_qty_bom', i, qty(reqBom))}</td>
                <td>${cellBtn('consumption', i, num(cons, 4))}</td>
                <td>${cellBtn('total_req_with_cons', i, qty(totalReqWithCons))}</td>
                <td>${cellBtn('total_so_qty', i, qty(totalSoQty))}</td>
                <td>${cellBtn('so_wise_qty', i, qty(soWiseQty))}</td>
                <td>${cellBtn('so_wise_value', i, money(soWiseVal))}</td>
                <td>${cellBtn('waste', i, num(r.waste || r.wastage, 2))}</td>
                <td>${cellBtn('ouom', i, dash(r.ouom || r.buom || item.uom))}</td>
            </tr>`;
            }).join('') || '<tr class="pwv-empty-row"><td colspan="13">No BOM details.</td></tr>';
            if (bomFoot) {
                bomFoot.hidden = !rows.length;
                if ($('pscBomTotFgOrder')) $('pscBomTotFgOrder').textContent = rows.length ? qty(totFgOrder) : '—';
                if ($('pscBomTotFgBom')) $('pscBomTotFgBom').textContent = rows.length ? qty(totFgBom) : '—';
                if ($('pscBomTotReq')) $('pscBomTotReq').textContent = rows.length ? qty(totBomReq) : '—';
                if ($('pscBomTotReqCons')) $('pscBomTotReqCons').textContent = rows.length ? qty(totReqCons) : '—';
                if ($('pscBomTotSoQty')) $('pscBomTotSoQty').textContent = rows.length ? qty(totalSoQty) : '—';
                if ($('pscBomTotSoWiseQty')) $('pscBomTotSoWiseQty').textContent = rows.length ? qty(totSoWiseQty) : '—';
                if ($('pscBomTotSoWiseVal')) $('pscBomTotSoWiseVal').textContent = rows.length ? money(totSoWiseVal) : '—';
            }
        } else if (bomFoot) {
            bomFoot.hidden = true;
        }

        const costBody = $('pscCostBody');
        const costFoot = $('pscCostFoot');
        if (costBody) {
            let totCons = 0;
            let totActual = 0;
            costBody.innerHTML = rows.map((r, i) => {
                const cons = Number(r.budgeted_cons || 0) || 0;
                const actual = actualCostInr(r) ?? nVal(r.actual_cost) ?? nVal(r.porate) ?? 0;
                totCons += cons;
                totActual += Number(actual) || 0;
                return `<tr data-row-idx="${i}">
                <td>${i + 1}</td>
                <td>${cellBtn('costing_quotation_no', i, dash(r.costing_quotation_no || r.quotation || r.vbeln))}</td>
                <td>${cellBtn('zsection', i, dash(r.zsection || r.cost_section))}</td>
                <td>${cellBtn('maktx', i, dash(r.maktx || r.cost_description || r.material_desc))}</td>
                <td>${cellBtn('budgeted_cons', i, num(cons, 4))}</td>
                <td>${cellBtn('budget_cost_per_gmt', i, num(r.budget_cost_per_gmt || r.expected_unit_cost || r.rate, 2))}</td>
                <td>${cellBtn('actual_cost', i, num(actual, 2))}</td>
                <td>${cellBtn('variance_pct', i, num(r.variance_pct, 2), Number(r.variance_pct) < 0 ? 'pwv-met-warn' : '')}</td>
            </tr>`;
            }).join('') || '<tr class="pwv-empty-row"><td colspan="8">No costing details.</td></tr>';
            if (costFoot) {
                costFoot.hidden = !rows.length;
                if ($('pscCostTotCons')) $('pscCostTotCons').textContent = rows.length ? num(totCons, 4) : '—';
                if ($('pscCostTotActual')) $('pscCostTotActual').textContent = rows.length ? num(totActual, 2) : '—';
            }
        } else if (costFoot) {
            costFoot.hidden = true;
        }
    }

    function renderCoverage(item) {
        const poQty = item ? item.po_qty : 0;
        const bomQty = item ? item.bom_qty : 0;
        const max = Math.max(poQty, bomQty, 1);
        if ($('pscBarPo')) $('pscBarPo').style.width = `${Math.min(100, (poQty / max) * 100)}%`;
        if ($('pscBarBom')) $('pscBarBom').style.width = `${Math.min(100, (bomQty / max) * 100)}%`;
        if ($('pscBarPoLabel')) $('pscBarPoLabel').textContent = qty(poQty);
        if ($('pscBarBomLabel')) $('pscBarBomLabel').textContent = qty(bomQty);
        const box = $('pscShortageBox');
        if (box) {
            if (!item || !Number.isFinite(item.qty_var) || item.qty_var === 0) {
                box.hidden = true;
            } else if (item.qty_var < 0) {
                box.hidden = false;
                box.classList.remove('is-surplus');
                box.classList.add('is-shortage');
                if (box.querySelector('strong')) box.querySelector('strong').textContent = 'Shortage';
                if ($('pscShortageText')) {
                    $('pscShortageText').textContent = `${qty(Math.abs(item.qty_var))} ${item.uom} (${Math.abs(item.qty_var_pct)}%)`;
                }
            } else {
                box.hidden = false;
                box.classList.remove('is-shortage');
                box.classList.add('is-surplus');
                if (box.querySelector('strong')) box.querySelector('strong').textContent = 'Surplus';
                if ($('pscShortageText')) {
                    $('pscShortageText').textContent = `${qty(item.qty_var)} ${item.uom} (${item.qty_var_pct}%)`;
                }
            }
        }
        renderValidationSummary(item);
    }

    function buildItemChecks(item) {
        const rows = item?.rows || [];
        const hasMaterial = !item || rows.every((r) => String(r.material || item?.material || '').trim() !== '');
        const uoms = new Set(rows.map((r) => String(r.ouom || r.buom || item?.uom || '').toUpperCase()).filter(Boolean));
        const uomOk = !item || uoms.size <= 1;
        const mapped = !item || !itemNeedsMap(item);
        const rateOk = !item || rows.every((r) => {
            const st = rowStatusDisplay(r);
            return st.key === 'ok' || st.key === 'mapping';
        }) || rows.every((r) => {
            const budget = budgetForCompare(r);
            const actual = actualForCompare(r);
            return !(budget > 0 && actual > 0) || actual <= budget + 0.005;
        });
        const qtyOk = item ? item.qty_var >= 0 : false;
        const costOk = mapped && rateOk;
        return [
            { ok: hasMaterial, label: 'Material Code Match' },
            { ok: uomOk, label: 'UOM Match' },
            { ok: mapped, label: 'Costing Mapping' },
            { ok: rateOk, label: 'Rate Validation (Actual vs Budget)' },
            { ok: qtyOk, label: 'Quantity Validation (PO vs BOM)' },
            { ok: costOk, label: 'Costing vs PO Alignment' },
        ];
    }

    function renderChecks(item) {
        const checks = buildItemChecks(item);
        const html = checks.map((c) => `
            <li>
                <span class="material-icons-round ${c.ok ? 'is-ok' : 'is-fail'}" aria-hidden="true">${c.ok ? 'check_circle' : 'error'}</span>
                ${esc(c.label)}
            </li>
        `).join('');
        if ($('pscChecks')) $('pscChecks').innerHTML = html;
        if ($('pscSummaryChecks')) $('pscSummaryChecks').innerHTML = html || '<li class="pwv-item-meta">No checks yet.</li>';
    }

    function renderValidationSummary(item) {
        if (!item) {
            if ($('pscSummaryTitle')) $('pscSummaryTitle').textContent = 'Select a PO item';
            if ($('pscSummaryMeta')) $('pscSummaryMeta').textContent = 'Material · UOM';
            if ($('pscSummaryBadge')) {
                $('pscSummaryBadge').textContent = '—';
                $('pscSummaryBadge').className = 'pwv-result-badge';
            }
            if ($('pscSummaryMetrics')) $('pscSummaryMetrics').innerHTML = '';
            if ($('pscSummaryCosting')) $('pscSummaryCosting').innerHTML = '';
            if ($('pscSummaryBody')) {
                $('pscSummaryBody').innerHTML = '<tr class="pwv-empty-row"><td colspan="8">Select a PO item to view validation summary.</td></tr>';
            }
            if ($('pscSummaryFoot')) $('pscSummaryFoot').hidden = true;
            if ($('pscSummaryChecks')) $('pscSummaryChecks').innerHTML = '';
            return;
        }

        const st = item.status || { key: 'ok', cls: 'is-ok', label: 'Valid' };
        const badgeCls = st.key === 'ok' ? 'is-ok' : (st.key === 'mapping' ? 'is-mapping' : 'is-warn');
        if ($('pscSummaryTitle')) $('pscSummaryTitle').textContent = item.name || `Item ${item.po_item}`;
        if ($('pscSummaryMeta')) {
            $('pscSummaryMeta').textContent = `Item ${item.po_item || '—'} · ${item.material || '—'} · ${item.uom || '—'}`;
        }
        if ($('pscSummaryBadge')) {
            $('pscSummaryBadge').textContent = st.label;
            $('pscSummaryBadge').className = `pwv-result-badge ${badgeCls}`;
        }

        const qtyLabel = item.qty_var < 0 ? 'Shortage' : (item.qty_var > 0 ? 'Surplus' : 'Balanced');
        const qtyTone = item.qty_var < 0 ? 'is-warn' : (item.qty_var > 0 ? 'is-ok' : 'is-ok');
        if ($('pscSummaryMetrics')) {
            $('pscSummaryMetrics').innerHTML = `
                <article class="psc-sum-metric"><span>PO Qty</span><strong>${qty(item.po_qty)} ${esc(item.uom)}</strong></article>
                <article class="psc-sum-metric"><span>BOM Req</span><strong>${qty(item.bom_qty)} ${esc(item.uom)}</strong></article>
                <article class="psc-sum-metric ${qtyTone}"><span>Qty Variance</span><strong>${qty(item.qty_var)} (${qtyLabel})</strong></article>
                <article class="psc-sum-metric"><span>PO Rate</span><strong>${money(item.po_rate)}</strong></article>
                <article class="psc-sum-metric"><span>Avg Costing Rate</span><strong>${money(item.avg_costing_rate)}</strong></article>
                <article class="psc-sum-metric ${st.cls}"><span>Overall</span><strong>${esc(st.label)}</strong></article>
            `;
        }

        const first = (item.rows || [])[0] || {};
        const budget = budgetForCompare(first);
        const actual = actualForCompare(first);
        const quote = first.costing_quotation_no || first.quotation || first.vbeln || '—';
        const zcost = first.zcost_item || first.cost_item || first.costing_item_no || '—';
        const section = first.zsection || first.cost_section || first.matgroup || '—';
        const mappedDesc = first.maktx || first.cost_description || '—';
        const variance = (budget > 0 && actual > 0)
            ? `${(((actual - budget) / budget) * 100).toFixed(2)}%`
            : '—';
        if ($('pscSummaryCosting')) {
            $('pscSummaryCosting').innerHTML = `
                <div><dt>Quotation</dt><dd>${esc(quote)}</dd></div>
                <div><dt>zCOST Item</dt><dd>${esc(zcost)}</dd></div>
                <div><dt>Section</dt><dd>${esc(section)}</dd></div>
                <div><dt>Costing Desc</dt><dd>${esc(mappedDesc)}</dd></div>
                <div><dt>Actual Cost</dt><dd>${actual != null ? num(actual, 4) : '—'}</dd></div>
                <div><dt>Budget (TOTALINR)</dt><dd>${budget != null ? num(budget, 4) : '—'}</dd></div>
                <div><dt>Variance</dt><dd>${esc(variance)}</dd></div>
                <div><dt>Mapping</dt><dd>${itemNeedsMap(item) ? 'Required' : 'Linked'}</dd></div>
            `;
        }

        const rows = item.rows || [];
        const summaryFoot = $('pscSummaryFoot');
        if ($('pscSummaryBody')) {
            let totGarment = 0;
            let totBom = 0;
            let totActual = 0;
            let totBudget = 0;
            $('pscSummaryBody').innerHTML = rows.map((r, i) => {
                const rst = rowStatusDisplay(r);
                const b = budgetForCompare(r);
                const a = actualForCompare(r);
                const garment = Number(r.so_qty || r.prwise_so_qty || r.sale_order_quantity || 0) || 0;
                const bomReq = Number(r.reqqty || r.req_bom_quantity || 0) || 0;
                totGarment += garment;
                totBom += bomReq;
                if (a != null) totActual += Number(a) || 0;
                if (b != null) totBudget += Number(b) || 0;
                const result = String(r.validation_result || rst.label || '—');
                return `<tr>
                    <td>${i + 1}</td>
                    <td>${esc(r.salordno || r.sales_order || '—')}</td>
                    <td>${qty(garment)}</td>
                    <td>${qty(bomReq)}</td>
                    <td>${a != null ? num(a, 4) : '—'}</td>
                    <td>${b != null ? num(b, 4) : '—'}</td>
                    <td><span class="pwv-badge ${rst.cls === 'is-ok' ? 'is-valid' : rst.cls}">${esc(rst.label)}</span></td>
                    <td title="${esc(result)}">${esc(result.length > 48 ? `${result.slice(0, 48)}…` : result)}</td>
                </tr>`;
            }).join('') || '<tr class="pwv-empty-row"><td colspan="8">No SO lines for this item.</td></tr>';
            if (summaryFoot) {
                summaryFoot.hidden = !rows.length;
                if ($('pscSumTotGarment')) $('pscSumTotGarment').textContent = rows.length ? qty(totGarment) : '—';
                if ($('pscSumTotBom')) $('pscSumTotBom').textContent = rows.length ? qty(totBom) : '—';
                if ($('pscSumTotActual')) $('pscSumTotActual').textContent = rows.length ? num(totActual, 4) : '—';
                if ($('pscSumTotBudget')) $('pscSumTotBudget').textContent = rows.length ? num(totBudget, 4) : '—';
            }
        } else if (summaryFoot) {
            summaryFoot.hidden = true;
        }

        renderChecks(item);
    }

    function setTab(tab) {
        activeTab = tab || 'so';
        document.querySelectorAll('#pscDetail .pwv-tab').forEach((btn) => {
            const on = btn.getAttribute('data-tab') === activeTab;
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('#pscDetail .pwv-tab-panel').forEach((panel) => {
            const on = panel.getAttribute('data-panel') === activeTab;
            panel.hidden = !on;
            panel.classList.toggle('is-on', on);
        });
        if (activeTab === 'charges' && activePo) {
            void loadChargesFromSap(activePo);
        }
    }

    function exportCsv() {
        if (!asideItems.length) {
            toast('Nothing to export');
            return;
        }
        const lines = [['PO', 'Item', 'Material', 'Name', 'PO Qty', 'BOM Qty', 'Qty Var', 'PO Rate', 'Costing Rate', 'Status']];
        asideItems.forEach((it) => {
            lines.push([
                activePo, it.po_item, it.material, it.name, it.po_qty, it.bom_qty,
                it.qty_var, it.po_rate, it.avg_costing_rate, it.status.label,
            ]);
        });
        const csv = lines.map((row) => row.map((c) => `"${String(c ?? '').replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `posalecosting-${activePo || 'export'}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    }

    async function passPo() {
        if (!cfg.canPass) {
            toast('You do not have Pass PO permission');
            return;
        }
        if (!activePo) return;
        syncPassButton();
        if ($('pscPassBtn')?.disabled) {
            toast($('pscPassBtn')?.title || 'Cannot Pass PO yet');
            return;
        }
        if (asideItems.some((it) => it.status.key === 'mapping')) {
            toast('Map all Mapping Required items first');
            return;
        }
        if (validatePayload?.summary?.can_pass_po === false) {
            toast('PO has validation exceptions — fix in workbench or remap before Pass PO');
            return;
        }
        // Prefer full workbench e-sign / pass flow (same as purchase-order costing)
        if (!window.confirm(`Open Pass & e-sign for PO ${activePo} in Costing Workbench?`)) return;
        const u = new URL(workbenchUrl(activePo), window.location.origin);
        u.searchParams.set('open', 'pass');
        window.location.href = u.pathname + u.search;
    }

    function backToSearch() {
        activePo = '';
        validatePayload = null;
        costLines = [];
        asideItems = [];
        activeItemKey = '';
        closeMatchPicker();
        setUrlPo('');
        showLookup();
        if ($('pscPoInput')) {
            $('pscPoInput').focus();
            $('pscPoInput').select();
        }
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

    function lineBudgetPerGmt(line) {
        if (!line) return null;
        const tot = nVal(line.total_inr);
        const g = nVal(line.gross);
        if (tot !== null && tot > 0 && g !== null && g > 0) {
            return Math.round((tot / g) * 10000) / 10000;
        }
        return nVal(line.budget_per_gmt) ?? nVal(line.rate) ?? nVal(line.purp) ?? nVal(line.net_price);
    }

    function sectionsForPoRow(r) {
        const g = String(r?.matgroup || r?.material_group || '').toUpperCase();
        const d = String(r?.mat_desc || r?.material_desc || r?.description || '').toUpperCase();
        const blob = `${g} ${d}`;
        if (/PACK/.test(g) || /\bPACKING\b/.test(blob)) return ['Packing'];
        if (/FABRIC|FAB/.test(g)) return ['Fabric'];
        if (/LABEL|HANGTAG|CARE|STICKER|TAG/.test(blob)) return ['Trim', 'Packing', 'Special'];
        if (/BUTTON|ELASTIC|THREAD|ZIPPER|CORD|ACCESSOR|TRIM/.test(blob)) return ['Trim', 'Special'];
        return ['Trim', 'Packing', 'Special'];
    }

    function quotationForRow(r) {
        const fromRow = String(
            r?.costing_quotation_no || r?.matched_quotation || r?.customer_quotation || r?.quotation || ''
        ).replace(/\D/g, '');
        if (fromRow) return fromRow;
        for (const it of asideItems) {
            for (const row of it.rows || []) {
                const q = String(row.costing_quotation_no || row.matched_quotation || '').replace(/\D/g, '');
                if (q) return q;
            }
        }
        const first = costLines.find((l) => String(l.quotation_no || l.costing_quotation_no || '').replace(/\D/g, ''));
        return first
            ? String(first.quotation_no || first.costing_quotation_no || '').replace(/\D/g, '')
            : '';
    }

    function candidateLinesForRow(r) {
        const wantSections = sectionsForPoRow(r).map((s) => s.toLowerCase());
        const wantQuote = quotationForRow(r);
        const matCode = String(r.material || '').trim().toLowerCase();
        const mat = selectedMat();
        let list = (costLines || []).filter((line) => {
            const lq = String(line.quotation_no || line.costing_quotation_no || line.parent_quotation_no || '').replace(/\D/g, '');
            if (wantQuote && lq && lq !== wantQuote) return false;
            if (mat === 'fabric' && String(line.section || '') !== 'Fabric') return false;
            if (mat === 'trims' && String(line.section || '') === 'Fabric') return false;
            const sec = String(line.section || line.zsection || '').toLowerCase();
            if (wantSections.length && sec && !wantSections.includes(sec)) {
                const item = String(line.item || line.material || '').toLowerCase();
                if (!(matCode && item && (item === matCode || item.includes(matCode)))) return false;
            }
            return true;
        });
        if (!list.length && costLines.length) {
            list = costLines.filter((line) => {
                if (wantQuote) {
                    const lq = String(line.quotation_no || line.costing_quotation_no || '').replace(/\D/g, '');
                    if (lq && lq !== wantQuote) return false;
                }
                if (mat === 'trims' && String(line.section || '') === 'Fabric') return false;
                return true;
            });
        }
        return list;
    }

    function matchRemarkForLine(r, line) {
        const matCode = String(r.material || '').trim().toUpperCase();
        const item = String(line.item || line.material || '').trim().toUpperCase();
        const poDesc = String(r.mat_desc || r.material_desc || '').trim().toUpperCase();
        const costDesc = String(line.maktx || line.description || '').trim().toUpperCase();
        if (matCode && item && (matCode === item || item.includes(matCode) || matCode.includes(item))) {
            return 'Exact material code match';
        }
        if (poDesc && costDesc && (poDesc === costDesc || costDesc.includes(poDesc.slice(0, 24)) || poDesc.includes(costDesc.slice(0, 24)))) {
            return 'Exact description match';
        }
        const sec = String(line.section || line.zsection || '').trim();
        return sec ? `Same section (${sec})` : 'Same material group section';
    }

    function openMatchPicker(item) {
        if (!item) {
            toast('Select a PO item first');
            return;
        }
        const row = (item.rows || []).find((r) => needsMapping(r)) || (item.rows || [])[0] || null;
        if (!row) {
            toast('No PO item available for mapping');
            return;
        }
        matchRow = row;
        matchPickKey = String(row.matched_line_key || '');
        const alreadyMapped = !!(row.manual_match || row.matched_line_key || row.cost_item || row.maktx);
        const sections = sectionsForPoRow(row);
        const sectionHint = sections.length === 1 ? sections[0] : sections.join(' / ');
        if ($('pscMatchTitle')) {
            $('pscMatchTitle').textContent = alreadyMapped ? 'Change Costing Mapping' : 'Manual Costing Mapping';
        }
        if ($('pscMatchDesc')) {
            $('pscMatchDesc').textContent = alreadyMapped
                ? `PO Item ${row.poitem || row.po_item || item.po_item || '—'} · ${row.matgroup || '—'} — already mapped; pick a different ${sectionHint} line if wrong.`
                : `PO Item ${row.poitem || row.po_item || item.po_item || '—'} · ${row.matgroup || '—'} — showing ${sectionHint} zCOST lines only.`;
        }
        const quote = quotationForRow(row);
        const meta = $('pscMatchMeta');
        if (meta) {
            meta.innerHTML = `
                <div><small>PO Item</small><b>${esc(row.poitem || row.po_item || item.po_item || '—')}</b></div>
                <div><small>Material Code</small><b title="${esc(row.material || item.material || '')}">${esc(row.material || item.material || '—')}</b></div>
                <div class="is-wide"><small>PO Description</small><b title="${esc(item.name || '')}">${esc(row.mat_desc || row.material_desc || item.name || '—')}</b></div>
                <div><small>SO / Style</small><b>${esc(row.salordno || row.sales_order || '—')} / ${esc(row.style || '—')}</b></div>
                <div><small>PO Qty</small><b>${esc(qty(row.tot_poqty || row.order_qty || item.po_qty || 0))} ${esc(row.ouom || item.uom || '')}</b></div>
                <div><small>PO Price</small><b>${esc(fmtStep(row.porate ?? row.net_price ?? item.po_rate, 4))} ${esc(row.po_currency || row.currency || '')}</b></div>
                <div><small>Mat Group / Section</small><b>${esc(row.matgroup || '—')} · <span class="pcw-match-sec-filter">${esc(sectionHint)}</span></b></div>
            `;
        }
        if ($('pscMatchTableTitle')) {
            $('pscMatchTableTitle').textContent = quote
                ? `Select Matching ${sectionHint} Row · Quotation ${quote}`
                : `Select Matching ${sectionHint} Costing Row`;
        }
        if ($('pscMatchSearch')) $('pscMatchSearch').value = '';
        renderMatchList();
        const pop = $('pscMatchPop');
        if (!pop) {
            toast('Mapping panel is missing — refresh the page');
            return;
        }
        // Keep panel above clipped scroll containers
        if (pop.parentElement !== document.body) {
            document.body.appendChild(pop);
        }
        pop.hidden = false;
        pop.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => pop.classList.add('is-open'));
        document.body.classList.add('pcw-match-offcanvas-open');
        if ($('pscMatchApply')) $('pscMatchApply').disabled = !matchPickKey;
        $('pscMatchSearch')?.focus();
    }

    function closeMatchPicker() {
        const pop = $('pscMatchPop');
        if (pop) {
            pop.classList.remove('is-open');
            pop.setAttribute('aria-hidden', 'true');
            window.setTimeout(() => {
                if (!pop.classList.contains('is-open')) pop.hidden = true;
            }, 280);
        }
        document.body.classList.remove('pcw-match-offcanvas-open');
        matchRow = null;
        matchPickKey = '';
    }

    function renderMatchList() {
        const list = $('pscMatchList');
        if (!list || !matchRow) return;
        const q = String($('pscMatchSearch')?.value || '').trim().toLowerCase();
        let lines = candidateLinesForRow(matchRow);
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
                <span class="material-icons-round" style="font-size:32px;color:#f59e0b;display:block;margin-bottom:6px;">search_off</span>
                <p style="margin:0;font-weight:700;color:#334155;">
                    ${costLines.length
                        ? 'No matching zCOST lines for this filter.'
                        : 'No zCOST candidates found for this PO. Recalculate to reload costing lines.'}
                </p>
            </div>`;
            list._pscLines = [];
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
        const cards = lines.slice(0, 120).map((line, i) => {
            const key = lineKey(line, i);
            const checked = key === matchPickKey ? ' checked' : '';
            const rate = lineBudgetPerGmt(line);
            const section = String(line.section || line.zsection || '—');
            const quote = String(line.quotation_no || line.costing_quotation_no || '—');
            const item = String(line.item || line.material || '—');
            const descTxt = String(line.maktx || line.description || '—');
            const totInr = nVal(line.total_inr);
            const gross = nVal(line.gross);
            const uom = String(line.unit || line.buom || line.uom || '—');
            const curr = String(line.currency || 'INR').toUpperCase() === 'IND' ? 'INR' : String(line.currency || 'INR');
            const remark = matchRemarkForLine(matchRow, line);
            return `<label class="pcw-match-option${checked ? ' is-active' : ''}" data-key="${esc(key)}">
                <span class="pcw-mcol-pick"><input type="radio" name="pscMatchPick" value="${esc(key)}"${checked}></span>
                <span class="pcw-mcol-quote" title="${esc(quote)}">${esc(quote)}</span>
                <span class="pcw-mcol-item" title="${esc(item)}">${esc(item)}</span>
                <span class="pcw-mcol-section"><span class="pcw-nav-chip">${esc(section)}</span></span>
                <span class="pcw-mcol-desc" title="${esc(descTxt)}">${esc(descTxt)}</span>
                <span class="pcw-mcol-total">${totInr !== null ? esc(fmtStep(totInr, 4)) : '—'}</span>
                <span class="pcw-mcol-gross">${gross !== null ? esc(fmtStep(gross, 4)) : '—'}</span>
                <span class="pcw-mcol-uom">${esc(uom)}</span>
                <span class="pcw-mcol-budget"><b>${rate !== null ? esc(fmtStep(rate, 4)) : '—'}</b> <small>${esc(curr)}</small></span>
                <span class="pcw-mcol-remark">${esc(remark)}</span>
            </label>`;
        }).join('');
        list.innerHTML = header + cards;
        list._pscLines = lines.slice(0, 120);
    }

    async function persistManualMapping(r, line) {
        const po = activePo || String(r?.pono || r?.purchase_order || '').replace(/\D/g, '');
        if (!po || !r) return false;
        const payload = {
            purchase_order: po,
            po_item: String(r.poitem || r.po_item || ''),
            material: String(r.material || ''),
            mat_cat: selectedMat(),
            quotation_no: String(line?.quotation_no || line?.costing_quotation_no || r.costing_quotation_no || ''),
            zcost_item: String(line?.item || r.zcost_item || r.cost_item || ''),
            maktx: String(line?.maktx || line?.description || r.maktx || ''),
            zsection: String(line?.section || line?.zsection || r.zsection || ''),
            total_inr: nVal(line?.total_inr ?? r.total_inr),
            gross: nVal(line?.gross ?? r.gross),
            currency: String(line?.currency || r.budget_currency || 'INR'),
            convr: nVal(line?.convr ?? r.budget_conv_rt),
            unit: String(line?.unit || r.buom || ''),
            line_no: String(line?.line_no || r.matched_line_no || ''),
            matched_line_key: String(matchPickKey || r.matched_line_key || ''),
            budget_cost_per_gmt: lineBudgetPerGmt(line),
            budget_cost_per_piece: nVal(line?.total_inr ?? r.total_inr),
            cost_description: String(line?.maktx || line?.description || r.maktx || ''),
        };
        try {
            const res = await fetch(cfg.manualMapUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            return true;
        } catch (err) {
            toast(`Mapped, but save failed: ${err.message || err}`, 4500);
            return false;
        }
    }

    async function onMatchApply() {
        if (!matchRow || !matchPickKey) return;
        const list = $('pscMatchList');
        const lines = list?._pscLines || [];
        const line = lines.find((l, i) => lineKey(l, i) === matchPickKey);
        if (!line) return;
        const applyBtn = $('pscMatchApply');
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.setAttribute('aria-busy', 'true');
        }
        // Same order as workbench: apply locally → persist → refresh
        matchRow.matched_line_key = matchPickKey;
        applyCostLineToRow(matchRow, line);
        const saved = await persistManualMapping(matchRow, line);
        if (applyBtn) {
            applyBtn.disabled = false;
            applyBtn.removeAttribute('aria-busy');
        }
        closeMatchPicker();
        if (saved) {
            toast('Mapping saved — revalidating…');
            if (activePo) await openPo(activePo);
        } else {
            asideItems = buildAsideItems(validatePayload?.records || []);
            renderDetailShell();
            selectItem(activeItemKey || asideItems[0]?.key, { openMap: false });
            syncPassButton();
        }
    }

    async function sapRevertPo() {
        if (!cfg.canSapRevert) {
            toast('You do not have SAP Revert permission');
            return;
        }
        if (!activePo) return;
        if (!window.confirm(
            `SAP Revert PO ${activePo}?\n\nPOApprovalSet Value will be sent as empty ("").`
        )) return;
        const btn = $('pscSapRevertBtn');
        if (btn) btn.disabled = true;
        setLoading(true, `SAP reverting PO ${activePo}…`);
        try {
            const res = await fetch(cfg.passUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ po: activePo, value: '', action: 'revert' }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || !json || json.success === false) {
                throw new Error(json?.message || json?.error || 'SAP Revert failed');
            }
            toast(json.message || `PO ${activePo} reverted`);
            if (validatePayload) {
                validatePayload.approved_status = 'NotApproved';
                if (validatePayload.header) {
                    validatePayload.header.approved_status = 'NotApproved';
                    validatePayload.header.ApprovedStatus = 'NotApproved';
                }
            }
            await openPo(activePo);
        } catch (err) {
            toast(err.message || 'SAP Revert failed');
            setLoading(false);
            syncPassButton();
        } finally {
            syncPassButton();
        }
    }

    async function portalRevertPo() {
        if (!cfg.canPortalRevert) {
            toast('You do not have Portal Revert permission');
            return;
        }
        if (!activePo) return;
        if (!window.confirm(
            `Portal Revert PO ${activePo}?\n\n`
            + '• Deletes rows from po_release_log\n'
            + '• Deletes PO PDF / e-sign files\n\n'
            + 'This does NOT call SAP.'
        )) return;
        const btn = $('pscPortalRevertBtn');
        if (btn) btn.disabled = true;
        setLoading(true, `Portal reverting PO ${activePo}…`);
        try {
            const res = await fetch(cfg.portalRevertUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ po: activePo, action: 'portal_revert' }),
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || !json || json.success === false) {
                throw new Error(json?.message || json?.error || 'Portal revert failed');
            }
            toast(json.message || 'Portal revert completed');
            await openPo(activePo);
        } catch (err) {
            toast(err.message || 'Portal revert failed');
            setLoading(false);
            syncPassButton();
        } finally {
            syncPassButton();
        }
    }

    function bind() {
        $('pscLookupForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            const err = $('pscLookupError');
            if (err) err.hidden = true;
            void openPo($('pscPoInput')?.value || '');
        });

        $('pscHeaderForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            void openPo($('pscHdrPo')?.value || '');
        });
        $('pscHdrPo')?.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            void openPo($('pscHdrPo')?.value || '');
        });
        $('pscSapRevertBtn')?.addEventListener('click', () => void sapRevertPo());
        $('pscPortalRevertBtn')?.addEventListener('click', () => void portalRevertPo());
        $('pscOpenPdfBtn')?.addEventListener('click', () => openPdfTab());
        $('pscRemarksBtn')?.addEventListener('click', () => openWorkbenchAction('remarks'));
        $('pscEsignBtn')?.addEventListener('click', () => openWorkbenchAction('esign'));
        $('pscSentBtn')?.addEventListener('click', () => openWorkbenchAction('sent'));

        $('pscAsideList')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-key]');
            if (!btn) return;
            e.preventDefault();
            const key = btn.getAttribute('data-key');
            const item = asideItems.find((x) => x.key === key);
            // Open mapping modal only for Mapping Required items
            selectItem(key, { openMap: !!(item && itemNeedsMap(item)) });
        });
        const bindMatchUi = () => {
            $('pscMatchClose')?.addEventListener('click', closeMatchPicker);
            $('pscMatchCancel')?.addEventListener('click', closeMatchPicker);
            $('pscMatchBackdrop')?.addEventListener('click', closeMatchPicker);
            $('pscMatchApply')?.addEventListener('click', () => void onMatchApply());
            $('pscMatchSearch')?.addEventListener('input', () => {
                matchPickKey = '';
                if ($('pscMatchApply')) $('pscMatchApply').disabled = true;
                renderMatchList();
            });
            $('pscMatchList')?.addEventListener('change', (e) => {
                const radio = e.target.closest('input[name="pscMatchPick"]');
                if (!radio) return;
                matchPickKey = radio.value;
                if ($('pscMatchApply')) $('pscMatchApply').disabled = !matchPickKey;
                $('pscMatchList')?.querySelectorAll('.pcw-match-option').forEach((el) => {
                    el.classList.toggle('is-active', el.getAttribute('data-key') === matchPickKey);
                });
            });
        };
        bindMatchUi();
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && $('pscMatchPop')?.classList.contains('is-open')) {
                closeMatchPicker();
                return;
            }
            if (e.key === 'Escape' && $('pscFormulaPop') && !$('pscFormulaPop').hidden) {
                closeFormulaPop();
            }
        });
        document.querySelectorAll('#pscDetail .pwv-tab').forEach((btn) => {
            btn.addEventListener('click', () => {
                closeFormulaPop();
                setTab(btn.getAttribute('data-tab'));
            });
        });
        ['pscSoBody', 'pscBomBody', 'pscCostBody'].forEach((id) => {
            $(id)?.addEventListener('click', (e) => {
                const btn = e.target.closest('button.pcw-formula-cell');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                const key = btn.getAttribute('data-explain');
                const idx = Number(btn.getAttribute('data-idx'));
                const item = asideItems.find((x) => x.key === activeItemKey);
                const row = item?.rows?.[idx];
                if (!key || !row) return;
                if (btn.classList.contains('is-active') && !$('pscFormulaPop')?.hidden) {
                    closeFormulaPop();
                    return;
                }
                openFormulaPop(key, row, btn);
            });
        });
        $('pscMetricRow')?.addEventListener('click', (e) => {
            const card = e.target.closest('button.pwv-metric[data-metric]');
            if (!card) return;
            e.preventDefault();
            e.stopPropagation();
            const key = card.getAttribute('data-metric');
            const item = asideItems.find((x) => x.key === activeItemKey);
            if (!key || !item) {
                toast('Select a PO item first');
                return;
            }
            if (card.classList.contains('is-active') && !$('pscFormulaPop')?.hidden) {
                closeFormulaPop();
                return;
            }
            openMetricPop(key, item, card);
        });
        $('pscFormulaClose')?.addEventListener('click', closeFormulaPop);
        document.addEventListener('click', (e) => {
            const pop = $('pscFormulaPop');
            if (!pop || pop.hidden) return;
            if (e.target.closest('#pscFormulaPop')
                || e.target.closest('button.pcw-formula-cell')
                || e.target.closest('button.pwv-metric[data-metric]')) return;
            closeFormulaPop();
        });
        window.addEventListener('scroll', () => {
            if (formulaAnchor && !$('pscFormulaPop')?.hidden) positionFormulaPop(formulaAnchor);
        }, true);
        window.addEventListener('resize', () => {
            if (formulaAnchor && !$('pscFormulaPop')?.hidden) positionFormulaPop(formulaAnchor);
        });

        $('pscPassBtn')?.addEventListener('click', () => void passPo());

        window.addEventListener('popstate', () => {
            try {
                const u = new URL(window.location.href);
                const po = u.searchParams.get('po') || '';
                setMat('all');
                if (po) void openPo(po);
                else backToSearch();
            } catch (_) {}
        });
    }

    function boot() {
        bind();
        setTab('so');
        setMat('all');
        if (cfg.startPo) {
            void openPo(cfg.startPo);
        } else {
            showLookup();
            setLoading(false);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.pocPoSaleCosting = { open: openPo, back: backToSearch, map: openMatchPicker };
})();
    