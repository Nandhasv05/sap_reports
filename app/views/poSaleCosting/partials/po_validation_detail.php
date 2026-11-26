<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Wise Costing Validation detail — summary + PO Items aside + tabs
 */
$canPassPoDetail = !empty($canPassPo);
?>
<section class="pwv-detail" id="pscDetail" aria-label="PO Wise Costing Validation" hidden>
    <div class="pwv-detail-top">
        <div class="pwv-summary-row" id="pscSummaryRow">
            <article class="pwv-sum-card is-green">
                <span class="pwv-sum-ico material-icons-round" aria-hidden="true">check_circle</span>
                <div>
                    <span class="pwv-sum-label">Valid</span>
                    <strong id="pscSumValid">—</strong>
                </div>
            </article>
            <article class="pwv-sum-card is-rose">
                <span class="pwv-sum-ico material-icons-round" aria-hidden="true">error</span>
                <div>
                    <span class="pwv-sum-label">Mapping Required</span>
                    <strong id="pscSumMapping">—</strong>
                </div>
            </article>
            <article class="pwv-sum-card is-amber">
                <span class="pwv-sum-ico material-icons-round" aria-hidden="true">warning</span>
                <div>
                    <span class="pwv-sum-label">Need to Review</span>
                    <strong id="pscSumReview">—</strong>
                </div>
            </article>
            <article class="pwv-sum-card is-blue">
                <span class="pwv-sum-ico material-icons-round" aria-hidden="true">payments</span>
                <div>
                    <span class="pwv-sum-label">Total PO Value</span>
                    <strong id="pscSumValue">—</strong>
                </div>
            </article>
        </div>
    </div>

    <div class="pwv-detail-body">
        <aside class="pwv-aside" id="pscAside" aria-label="PO Items">
            <header class="pwv-aside-head">
                <strong>PO Items (<span id="pscAsideCount">0</span>)</strong>
            </header>
            <div class="pwv-aside-list" id="pscAsideList" role="listbox" aria-label="PO item list"></div>
        </aside>

        <div class="pwv-main pwv-main-card" id="pscMainPanel">
            <header class="pwv-item-head">
                <div>
                    <h2 id="pscItemTitle">PO Item Validation</h2>
                    <p class="pwv-item-meta" id="pscItemMeta">Material Code: — | UOM: —</p>
                </div>
                <div class="pwv-item-head-right">
                    <div class="pwv-item-actions" id="pscItemActions" role="group" aria-label="PO actions">
                        <button type="button" class="rpt-tb-btn pcw-pdf-btn pcw-pdf-btn-open" id="pscOpenPdfBtn"
                                title="Open PO PDF in a new tab" disabled>
                            <span class="material-icons-round" aria-hidden="true">open_in_new</span>
                            <span>Open PDF</span>
                        </button>
                        <button type="button" class="rpt-tb-btn rpt-tb-btn-primary" id="pscRemarksBtn"
                                title="Status &amp; Remarks" disabled>
                            <span class="material-icons-round" aria-hidden="true">forum</span>
                            <span>Remarks</span>
                        </button>
                        <button type="button" class="rpt-tb-btn pcw-esign-toolbar-btn" id="pscEsignBtn"
                                title="Open PO E-Signature" disabled>
                            <span class="material-icons-round" aria-hidden="true">draw</span>
                            <span>eSign</span>
                        </button>
                        <button type="button" class="rpt-tb-btn pcw-sent-toolbar-btn" id="pscSentBtn"
                                title="Send this PO to a user" disabled>
                            <span class="material-icons-round" aria-hidden="true">send</span>
                            <span>Sent</span>
                        </button>
                    </div>
                    <em class="pwv-result-badge" id="pscItemStatus">—</em>
                </div>
            </header>

            <div class="pwv-metric-row" id="pscMetricRow">
                <button type="button" class="pwv-metric is-mint" data-metric="po_qty" title="Click for details">
                    <span class="pwv-metric-ico material-icons-round" aria-hidden="true">shopping_bag</span>
                    <div>
                        <span class="pwv-metric-label">PO Quantity</span>
                        <strong id="pscMetPoQty">—</strong>
                    </div>
                </button>
                <button type="button" class="pwv-metric is-lilac" data-metric="bom_qty" title="Click for details">
                    <span class="pwv-metric-ico material-icons-round" aria-hidden="true">precision_manufacturing</span>
                    <div>
                        <span class="pwv-metric-label">Total BOM Requirement</span>
                        <strong id="pscMetBom">—</strong>
                    </div>
                </button>
                <button type="button" class="pwv-metric is-coral" data-metric="qty_var" title="Click for details">
                    <span class="pwv-metric-ico material-icons-round" aria-hidden="true">swap_vert</span>
                    <div>
                        <span class="pwv-metric-label">Quantity Variance</span>
                        <strong id="pscMetVar">—</strong>
                    </div>
                </button>
                <button type="button" class="pwv-metric is-peach" data-metric="po_rate" title="Click for details">
                    <span class="pwv-metric-ico material-icons-round" aria-hidden="true">sell</span>
                    <div>
                        <span class="pwv-metric-label">PO Rate</span>
                        <strong id="pscMetPoRate">—</strong>
                    </div>
                </button>
                <button type="button" class="pwv-metric is-cyan" data-metric="cost_rate" title="Click for details">
                    <span class="pwv-metric-ico material-icons-round" aria-hidden="true">trending_up</span>
                    <div>
                        <span class="pwv-metric-label">Avg. Costing Rate</span>
                        <strong id="pscMetCostRate">—</strong>
                    </div>
                </button>
            </div>

            <div class="pwv-tabs" role="tablist">
                <button type="button" class="pwv-tab is-on" role="tab" aria-selected="true" data-tab="so">Sales Order wise Validation</button>
                <button type="button" class="pwv-tab" role="tab" aria-selected="false" data-tab="bom">BOM Details</button>
                <button type="button" class="pwv-tab" role="tab" aria-selected="false" data-tab="costing">Costing Details</button>
                <button type="button" class="pwv-tab" role="tab" aria-selected="false" data-tab="charges">Additional Charges</button>
                <button type="button" class="pwv-tab" role="tab" aria-selected="false" data-tab="summary">Validation Summary</button>
            </div>

            <div class="pwv-tab-panels">
                <div class="pwv-tab-panel is-on" data-panel="so" role="tabpanel">
                    <div class="pwv-table-wrap">
                        <table class="pwv-table is-detail">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Sales Order</th>
                                    <th title="style">FG Material</th>
                                    <th title="so_qty">Garment Qty (EA)</th>
                                    <th>BOM Cons. (Piece)</th>
                                    <th>Wastage %</th>
                                    <th>BOM Req. Qty (EA)</th>
                                    <th>Costing Req. Qty (EA)</th>
                                    <th>Costing Rate (₹)</th>
                                    <th>PO Rate (₹)</th>
                                    <th>Qty Variance (EA)</th>
                                    <th>Rate Variance (₹)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="pscSoBody"></tbody>
                            <tfoot id="pscSoFoot" hidden>
                                <tr class="pwv-table-foot">
                                    <th colspan="3">Total</th>
                                    <th id="pscSoTotGarment">—</th>
                                    <th>—</th>
                                    <th>—</th>
                                    <th id="pscSoTotBomReq">—</th>
                                    <th id="pscSoTotCostReq">—</th>
                                    <th>—</th>
                                    <th>—</th>
                                    <th id="pscSoTotQtyVar">—</th>
                                    <th>—</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="pwv-tab-panel" data-panel="bom" role="tabpanel" hidden>
                    <div class="pwv-table-wrap">
                        <table class="pwv-table is-detail">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Sales Order</th>
                                    <th>Material</th>
                                    <th title="SaleOrderQuantity">FG Order QTY</th>
                                    <th title="FG_HEAD_QTY">FG QTY (BOM)</th>
                                    <th title="ReqBOMQuantity">Required QTY (BOM)</th>
                                    <th title="ReqBOMQuantity / FG QTY (BOM)">CONSUMPTION</th>
                                    <th title="FG QTY (BOM) × CONSUMPTION">Total Req.Qty with Consumption</th>
                                    <th title="Σ ReqBOMQuantity">Total SO QTY</th>
                                    <th title="OrderQuantity × Total Req.Qty with Consumption / Total SO QTY">SO Wise QTY</th>
                                    <th title="PO Rate × SO Wise QTY × CONSUMPTION">SO Wise Value</th>
                                    <th>Waste %</th>
                                    <th>UOM</th>
                                </tr>
                            </thead>
                            <tbody id="pscBomBody"></tbody>
                            <tfoot id="pscBomFoot" hidden>
                                <tr class="pwv-table-foot">
                                    <th colspan="3">Total</th>
                                    <th id="pscBomTotFgOrder">—</th>
                                    <th id="pscBomTotFgBom">—</th>
                                    <th id="pscBomTotReq">—</th>
                                    <th>—</th>
                                    <th id="pscBomTotReqCons">—</th>
                                    <th id="pscBomTotSoQty">—</th>
                                    <th id="pscBomTotSoWiseQty">—</th>
                                    <th id="pscBomTotSoWiseVal">—</th>
                                    <th>—</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="pwv-tab-panel" data-panel="costing" role="tabpanel" hidden>
                    <div class="pwv-table-wrap">
                        <table class="pwv-table is-detail">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Quotation</th>
                                    <th>Section</th>
                                    <th>Description</th>
                                    <th>Budget Cons.</th>
                                    <th>Budget Rate</th>
                                    <th>Actual Cost</th>
                                    <th>Variance %</th>
                                </tr>
                            </thead>
                            <tbody id="pscCostBody"></tbody>
                            <tfoot id="pscCostFoot" hidden>
                                <tr class="pwv-table-foot">
                                    <th colspan="4">Total</th>
                                    <th id="pscCostTotCons">—</th>
                                    <th>—</th>
                                    <th id="pscCostTotActual">—</th>
                                    <th>—</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="pwv-tab-panel" data-panel="charges" role="tabpanel" hidden>
                    <div class="psc-charges-panel" id="pscChargesPanel" aria-label="PO additional charges">
                        <span id="pscChargeGrandCurr" hidden>INR</span>
                        <span id="pscChargesStatus" class="psc-charges-status" hidden></span>
                        <div class="pwv-table-wrap">
                            <table class="pwv-table is-detail psc-charges-table is-wide">
                                <thead>
                                    <tr>
                                        <th class="num" title="PO Net">Subtotal</th>
                                        <th class="num" title="Discount">Discount<span class="psc-ch-tag">[Charge]</span></th>
                                        <th class="num" title="to_Cond Freight">Freight<span class="psc-ch-tag">[Charge]</span></th>
                                        <th class="num" title="to_Cond MOQ">MOQ<span class="psc-ch-tag">[Charge]</span></th>
                                        <th class="num" title="to_Cond Other">Other<span class="psc-ch-tag">[Charge]</span></th>
                                        <th class="num" title="to_Cond Screen">Screen<span class="psc-ch-tag">[Charge]</span></th>
                                        <th class="num" title="to_Cond Bank">Bank<span class="psc-ch-tag">[Charge]</span></th>
                                        <th class="num" title="to_GST.sgst">SGST<span class="psc-ch-tag">[Tax]</span></th>
                                        <th class="num" title="to_GST.cgst">CGST<span class="psc-ch-tag">[Tax]</span></th>
                                        <th class="num" title="to_GST.igst">IGST<span class="psc-ch-tag">[Tax]</span></th>
                                        <th class="num">Total P.O Value</th>
                                    </tr>
                                </thead>
                                <tbody id="pscChargesBody"></tbody>
                                <tfoot id="pscChargesFoot" hidden>
                                    <tr class="pwv-table-foot">
                                        <th colspan="10" class="num">Grand Total</th>
                                        <th class="num is-total" id="pscChargesTotGrand">—</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="pwv-tab-panel" data-panel="summary" role="tabpanel" hidden>
                    <div class="psc-summary-panel" id="pscSummaryPanel">
                        <div class="psc-summary-hero" id="pscSummaryHero">
                            <div>
                                <span class="psc-summary-kicker">Validation Summary</span>
                                <strong id="pscSummaryTitle">Select a PO item</strong>
                                <p class="pwv-item-meta" id="pscSummaryMeta">Material · UOM</p>
                            </div>
                            <em class="pwv-result-badge is-ok" id="pscSummaryBadge">—</em>
                        </div>
                        <div class="psc-summary-metrics" id="pscSummaryMetrics"></div>
                        <div class="psc-summary-grid">
                            <article class="pwv-card psc-summary-card">
                                <h3>Checks</h3>
                                <ul class="pwv-checks" id="pscSummaryChecks"></ul>
                            </article>
                            <article class="pwv-card psc-summary-card">
                                <h3>Costing Snapshot</h3>
                                <dl class="psc-summary-dl" id="pscSummaryCosting"></dl>
                            </article>
                        </div>
                        <div class="pwv-table-wrap">
                            <table class="pwv-table is-detail">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Sales Order</th>
                                        <th>Garment Qty</th>
                                        <th>BOM Req</th>
                                        <th>Actual Cost</th>
                                        <th>Budget</th>
                                        <th>Status</th>
                                        <th>Result</th>
                                    </tr>
                                </thead>
                                <tbody id="pscSummaryBody"></tbody>
                                <tfoot id="pscSummaryFoot" hidden>
                                    <tr class="pwv-table-foot">
                                        <th colspan="2">Total</th>
                                        <th id="pscSumTotGarment">—</th>
                                        <th id="pscSumTotBom">—</th>
                                        <th id="pscSumTotActual">—</th>
                                        <th id="pscSumTotBudget">—</th>
                                        <th></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pwv-lower-grid">
                <article class="pwv-card">
                    <h3>PO Coverage (Material Level)</h3>
                    <div class="pwv-coverage">
                        <div class="pwv-bar-row">
                            <span>PO Quantity</span>
                            <div class="pwv-bar-track"><div class="pwv-bar is-po" id="pscBarPo" style="width:0%"></div></div>
                            <em id="pscBarPoLabel">0</em>
                        </div>
                        <div class="pwv-bar-row">
                            <span>BOM Requirement</span>
                            <div class="pwv-bar-track"><div class="pwv-bar is-bom" id="pscBarBom" style="width:0%"></div></div>
                            <em id="pscBarBomLabel">0</em>
                        </div>
                        <div class="pwv-shortage" id="pscShortageBox" hidden>
                            <strong>Shortage</strong>
                            <span id="pscShortageText">—</span>
                        </div>
                    </div>
                </article>
                <article class="pwv-card">
                    <h3>Validation Checks</h3>
                    <ul class="pwv-checks" id="pscChecks"></ul>
                </article>
            </div>
        </div>
    </div>

</section>
