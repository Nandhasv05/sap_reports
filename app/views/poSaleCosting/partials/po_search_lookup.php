<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 24/11/2026
 * DESCRIPTION : PO Sale Costing — search a particular PO number (no list table)
 */
?>
<section class="psc-lookup" id="pscLookup" aria-label="Search purchase order">
    <div class="psc-lookup-orbs" aria-hidden="true"><span></span><span></span><span></span></div>
    <div class="psc-lookup-card">
        <p class="psc-lookup-kicker">PO Wise Costing Validation</p>
        <h1>Search purchase order</h1>
        <p class="psc-lookup-lede">Enter a PO number to open item-wise costing validation with PO Items aside.</p>

        <form id="pscLookupForm" class="psc-lookup-form" autocomplete="off" novalidate>
            <input type="hidden" id="pscMat" name="mat_cat" value="all">

            <label class="psc-lookup-field" title="Purchase order number">
                <span class="material-icons-round" aria-hidden="true">receipt_long</span>
                <input type="search" id="pscPoInput" name="po" value=""
                       placeholder="e.g. 1000028206"
                       inputmode="numeric" maxlength="10" autocomplete="off"
                       aria-label="Purchase order number" required>
            </label>

            <p class="psc-lookup-error" id="pscLookupError" role="alert" hidden></p>

            <button class="psc-lookup-submit" type="submit" id="pscShowBtn">
                <span class="material-icons-round" aria-hidden="true">search</span>
                Show validation
            </button>
        </form>
    </div>
</section>
