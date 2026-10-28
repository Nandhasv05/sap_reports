<?php
/*
 * PO Costing Validation Workbench (detail mode when view=costing)
 */
$wbPo = (string) ($purchaseOrder ?? '');
$wbSo = (string) ($salesOrder ?? '');
$wbMat = (string) ($matCat ?? 'trims');
if (!in_array($wbMat, ['all', 'fabric', 'trims'], true)) {
    $wbMat = 'trims';
}
?>
<section class="pcw" id="poCostingWorkbench" data-po="<?= e($wbPo) ?>" data-so="<?= e($wbSo) ?>" data-mat="<?= e($wbMat) ?>">
    <form class="pcw-controls" id="pcwFetchForm" autocomplete="off">
        <label class="pcw-field">
            <span>PO Number</span>
            <input type="text" id="pcwPoInput" name="po" value="<?= e($wbPo) ?>" inputmode="numeric" maxlength="10" placeholder="e.g. 1000027905" required>
        </label>
        <label class="pcw-field">
            <span>Sales Order (optional)</span>
            <input type="text" id="pcwSoInput" name="so" value="<?= e($wbSo) ?>" inputmode="numeric" maxlength="10" placeholder="ZCOST Vbeln">
        </label>
        <div class="pcw-field pcw-field-seg">
            <span>Material Type</span>
            <fieldset class="pcb-seg" role="radiogroup" aria-label="Material Type">
                <legend class="pcb-sr">Material Type</legend>
                <label class="pcb-seg-opt"><input type="radio" name="pcw_mat" value="fabric"<?= $wbMat === 'fabric' ? ' checked' : '' ?>><span>Fabric</span></label>
                <label class="pcb-seg-opt"><input type="radio" name="pcw_mat" value="trims"<?= $wbMat === 'trims' ? ' checked' : '' ?>><span>Trims</span></label>
                <label class="pcb-seg-opt"><input type="radio" name="pcw_mat" value="all"<?= !in_array($wbMat, ['fabric', 'trims'], true) ? ' checked' : '' ?>><span>All</span></label>
            </fieldset>
        </div>
        <button type="submit" class="pcw-btn pcw-btn-primary" id="pcwFetchBtn">
            <span class="material-icons-round">download</span>
            Fetch PO
        </button>
        <button type="button" class="pcw-btn pcw-btn-ghost" id="pcwValidateBtn">
            <span class="material-icons-round">verified</span>
            Validate All
        </button>
    </form>

    <div class="pcw-kpis" id="pcwKpis">
        <article class="pcw-kpi">
            <span class="material-icons-round">format_list_numbered</span>
            <div><b id="pcwKpiItems">—</b><small>PO Items</small></div>
        </article>
        <article class="pcw-kpi is-pass">
            <span class="material-icons-round">check_circle</span>
            <div><b id="pcwKpiPassed">—</b><small>Passed</small></div>
        </article>
        <article class="pcw-kpi is-fail">
            <span class="material-icons-round">error</span>
            <div><b id="pcwKpiExceptions">—</b><small>Exceptions</small></div>
        </article>
        <article class="pcw-kpi is-value">
            <span class="material-icons-round">payments</span>
            <div><b id="pcwKpiValue">—</b><small>Total PO Value</small></div>
        </article>
    </div>

    <div class="pcw-table-wrap">
        <table class="pcw-table" id="pcwTable">
            <thead>
                <tr>
                    <th class="pcw-check"><input type="checkbox" id="pcwSelectAll" title="Select all"></th>
                    <th>Status</th>
                    <th>PO Item</th>
                    <th>Material Code</th>
                    <th>Material Description</th>
                    <th>Sales Order</th>
                    <th class="num">PO Qty</th>
                    <th>Costing Ref</th>
                    <th class="num">Expected Unit Cost (₹)</th>
                    <th class="num">PO Unit Cost (₹)</th>
                    <th class="num">Variance %</th>
                    <th>Validation Result</th>
                </tr>
            </thead>
            <tbody id="pcwTableBody">
                <tr class="pcw-empty"><td colspan="12">Enter a PO number and click Fetch PO to validate against ZCOST.</td></tr>
            </tbody>
        </table>
    </div>

    <section class="pcw-detail" id="pcwDetail" hidden>
        <div class="pcw-detail-head">
            <h2>Item Details — Costing Comparison</h2>
            <p id="pcwDetailSub">Select a row to compare merchant quotation vs SAP PO.</p>
        </div>
        <div class="pcw-detail-grid">
            <article class="pcw-panel">
                <h3>Merchant Quotation (ZCOST)</h3>
                <dl id="pcwPanelQuote"></dl>
            </article>
            <article class="pcw-panel">
                <h3>SAP PO</h3>
                <dl id="pcwPanelPo"></dl>
            </article>
            <article class="pcw-panel">
                <h3>Difference</h3>
                <dl id="pcwPanelDiff"></dl>
            </article>
            <article class="pcw-panel">
                <h3>Additional Information</h3>
                <dl id="pcwPanelInfo"></dl>
            </article>
        </div>
    </section>

    <div class="pcw-actions">
        <button type="button" class="pcw-btn pcw-btn-primary" id="pcwRevalidateBtn">
            <span class="material-icons-round">refresh</span>
            Revalidate Exceptions
        </button>
        <button type="button" class="pcw-btn pcw-btn-ghost" id="pcwExportBtn">
            <span class="material-icons-round">table_view</span>
            Export Excel
        </button>
        <button type="button" class="pcw-btn pcw-btn-pass" id="pcwPassPoBtn" disabled title="Enabled when all lines PASS">
            <span class="material-icons-round">done_all</span>
            Pass PO
        </button>
    </div>
</section>
