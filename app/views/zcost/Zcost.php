<?php
/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/11/2026
 * DESCRIPTION : SAP Zcost Sales Costing Sheet View
 */
$user = function_exists('portal_user') ? portal_user() : null;
$userName = !empty($user['username']) ? $user['username'] : 'Admin';
$userRole = !empty($user['role']) ? $user['role'] : 'ADMIN';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Costing Sheet |EVOLV</title>
  
  <!-- Fonts & Core Theme Styles -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('css/loader.css')) ?>?v=<?= filemtime(sap_reports_public_file('css/loader.css')) ?: time() ?>">
  <link rel="stylesheet" href="<?= e(asset('css/zcost/sap_theme.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/zcost/sap_initial.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/zcost/evolv_landing.css')) ?>">
  <script>
    (function () {
      try {
        var p = new URLSearchParams(location.search);
        var v = (p.get('vbeln') || '').trim();
        if (!v) return;
        document.documentElement.classList.add('zcost-boot-sheet');
        if ((p.get('view') || '') === '1' || (p.get('mode') || '') === 'view') {
          document.documentElement.classList.add('zcost-view-only');
        }
      } catch (e) {}
    })();
  </script>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'>
    <rect width='100' height='100' rx='15' fill='%23003366'/> <text x='50' y='68' fill='white' font-size='55' font-family='Arial' font-weight='bold' text-anchor='middle'>SAP</text></svg>">
</head>

<body class="sap-body">
  <?php
    $loaderIcon = 'request_quote';
    $loaderVisible = false;
    $loaderSteps = ['Connecting to SAP...', 'Loading costing sheet...', 'Preparing workspace...'];
    require base_path('app/views/partials/loader.php');
  ?>
  <div class="sap-window" id="sap-window" style="display:none;">

    <!-- Top Studio Navigation Bar -->
    <header class="studio-navbar">
      <div class="studio-nav-left">
        <a href="<?= e(sap_reports_evol_url('portal_dashboard.php')) ?>" class="studio-logo-link" title="EVOLV Portal Dashboard">
          <img src="<?= e(asset('css/zcost/logo.png')) ?>" alt="EVOLV" class="studio-logo-img" style="height:28px; max-height:28px; width:auto; max-width:120px; object-fit:contain;" onerror="this.style.display='none'; document.getElementById('studio-logo-fallback').style.display='inline';">
          <span id="studio-logo-fallback" class="evolv-logo-text" style="display:none;">evolv</span>
        </a>

        <div class="studio-nav-divider"></div>
        <!-- Quick Nav: Reports Catalog (Grid) & Portal Dashboard (Home) -->
        <a href="<?= e(url('/')) ?>" class="studio-home-btn" title="Back to SAP Insight Catalog" aria-label="SAP Insight Catalog" style="margin-right:4px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
            <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
            <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
            <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
          </svg>
        </a>
        <a href="<?= e(sap_reports_evol_url('portal_dashboard.php')) ?>" class="studio-home-btn" title="Back to EVOLV Portal Dashboard" aria-label="Portal Dashboard">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
        </a>

        <div class="studio-nav-divider"></div>

        <button type="button" class="studio-initial-screen-btn" onclick="showInitialScreen()" title="Back to Initial Action Screen (Create / Copy / Change / Approve)">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"></polyline></svg>
          <span>Initial Screen</span>
        </button>

        <span class="nav-title-wrap">
          <span class="studio-page-title">Sales Costing Sheet</span>
          <span class="sap-screen-title not-approved" id="sap-screen-title">- NOT APPROVED</span>
        </span>
        <span id="sap-window-title" style="display:none;"></span>
        <input type="hidden" id="sap-cmd-input" value="">

        <!-- Hidden hooks -->
        <span id="studio-so-num" style="display:none;"></span>
        <span id="studio-posnr-num" style="display:none;"></span>
      </div>

      <div class="studio-nav-right">
        <!-- Search Box Pill -->
        <div class="studio-nav-search" title="Search Quotation">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="nav-search-input" class="studio-nav-search-input" placeholder="Search Quotation..." onkeydown="if(event.key==='Enter'){const v=this.value.trim();if(v){quickSelectQuotation(v);}}">
          <button type="button" class="studio-nav-search-btn" onclick="const v=document.getElementById('nav-search-input')?.value.trim();if(v){quickSelectQuotation(v);}else{openSearchModal();}" title="Search Quotation">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <polyline points="9 18 15 12 9 6"/>
            </svg>
  
        </div>

        <!-- User Profile Pill & Dropdown -->
        <div class="evolv-user-menu-container">
          <div class="evolv-user-pill" id="studio-user-pill" onclick="toggleUserDropdown(event)" role="button" tabindex="0" title="User Menu">
            <div class="evolv-avatar-wrapper">
              <div class="evolv-avatar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
              </div>
              <span class="evolv-status-dot"></span>
            </div>
            <div class="evolv-user-info">
              <span class="evolv-user-name">Admin</span>
              <span class="evolv-user-role">ADMIN â-¾</span>
            </div>
          </div>

          <div class="evolv-user-dropdown" id="evolv-user-dropdown-studio">
            <div class="evolv-dropdown-header">
              <div class="evolv-dropdown-user-name">Administrator</div>
              <div class="evolv-dropdown-user-email">admin@evolvclothing.com</div>
            </div>
            <div class="evolv-dropdown-divider"></div>
            <a href="audit.php" class="evolv-dropdown-item admin-audit-link" hidden>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
              </svg>
              <span>Audit Log</span>
            </a>
            <a href="<?= e(sap_reports_evol_url('portal_dashboard.php')) ?>" class="evolv-dropdown-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
              </svg>
              <span>Back to Portal</span>
            </a>
            <a href="EVOL/logout.php" class="evolv-dropdown-item logout">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
              </svg>
              <span>Logout</span>
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Sheet Command Bar (White Card Container) -->
    <section class="sheet-command">
      <div class="sheet-bar">
        <div class="sheet-tools">
          <div class="sheet-doc-fields">
            <label class="sheet-field">
              <span>SO</span>
              <input type="text" id="hdr-vbeln" value="" placeholder="Number" maxlength="10" oninput="validateField(this, 'vbeln')" onchange="collectHeaderInputs()">
            </label>
            <label class="sheet-field">
              <span>Item</span>
              <input type="text" id="hdr-posnr" value="000010" placeholder="000010" maxlength="6" oninput="validateField(this, 'numc6')" onblur="if(this.value && /^\d+$/.test(this.value)) this.value = this.value.padStart(6, '0'); collectHeaderInputs();" onchange="collectHeaderInputs()">
            </label>
            <label class="sheet-field" title="HD_ZCOST-KWMENG">
              <span>Qty</span>
              <input type="text" id="hdr-kwmeng" value="0.000" placeholder="0.000" oninput="validateField(this, 'decimal', 3)" onchange="collectHeaderInputs()">
            </label>
          </div>

          <div class="sheet-toolbar-meta">
            <label class="sheet-field sheet-field-curr" title="Currency Code / Conversion">
              <span>Curr</span>
              <input type="text" id="hdr-cnv" value="USD" maxlength="5" style="text-transform:uppercase;" oninput="this.value = this.value.toUpperCase(); validateField(this, 'waers');" onchange="collectHeaderInputs(); renderAll();">
            </label>
            <label class="sheet-field sheet-field-rate" title="Conversion Rate">
              <span>Rate</span>
              <input type="text" id="hdr-cnvf" value="87.00" oninput="validateField(this, 'decimal', 3)" onchange="collectHeaderInputs(); recalculateAll();">
            </label>
          </div>

        </div>

        <div class="sheet-toolbar-actions" role="group" aria-label="Sheet actions">
          <button type="button" class="studio-btn studio-btn-approve" id="btn-approve" onclick="approveCostSheet()" hidden>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6L9 17l-5-5"/></svg>
            <span>Approve</span>
  
          <button type="button" class="studio-btn studio-btn-edit" id="btn-change" onclick="toggleChangeMode()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
            <span>Change</span>
  
          <button type="button" class="studio-btn studio-btn-save" id="btn-save-primary" onclick="saveCostSheet()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
            <span>Save</span>
  
        </div>
      </div>
    </section>

    <!-- Scrollable Sheet Content -->
    <div class="sheet-scroll">

      <!-- KPI Bento Cards (Exact Pastel Palette & Layout) -->
      <section class="total-board" aria-label="Cost totals">
        <!-- 1: Fabric (Soft Pastel Blue) -->
        <article class="total-card card-fabric">
          <span class="total-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 7l8-4 8 4-8 4-8-4z"/>
              <path d="M4 12l8 4 8-4"/>
              <path d="M4 17l8 4 8-4"/>
            </svg>
          </span>
          <div class="total-copy">
            <strong id="kpi-fabric-tot">â‚¹ 0.00</strong>
            <span class="total-kicker">FABRIC</span>
            <small><span id="badge-fabric-count">0</span> lines</small>
          </div>
        </article>

        <!-- 2: Trims (Soft Pastel Peach / Orange) -->
        <article class="total-card card-trim">
          <span class="total-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M20 12l-8 8-8-8V4h8l8 8z"/>
              <circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/>
            </svg>
          </span>
          <div class="total-copy">
            <strong id="kpi-trim-tot">â‚¹ 0.00</strong>
            <span class="total-kicker">TRIMS</span>
            <small><span id="badge-trim-count">0</span> lines</small>
          </div>
        </article>

        <!-- 3: Packing (Soft Pastel Mint / Green) -->
        <article class="total-card card-pack">
          <span class="total-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 8l-9-5-9 5 9 5 9-5z"/>
              <path d="M3 8v8l9 5 9-5V8"/>
              <path d="M12 13v8"/>
            </svg>
          </span>
          <div class="total-copy">
            <strong id="kpi-packing-tot">â‚¹ 0.00</strong>
            <span class="total-kicker">PACKING</span>
            <small><span id="badge-packing-count">0</span> lines</small>
          </div>
        </article>

        <!-- 4: Sub Total (Soft Pastel Periwinkle / Lavender) -->
        <article class="total-card card-sub">
          <span class="total-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="4" y="3" width="16" height="18" rx="2"/>
              <path d="M8 7h8M8 11h8M8 15h5"/>
            </svg>
          </span>
          <div class="total-copy">
            <strong id="kpi-subtot">â‚¹ 0.00</strong>
            <span class="total-kicker">SUB TOTAL</span>
            <small id="kpi-margin-tot">0.0% / 0.0%</small>
          </div>
        </article>

        <!-- 5: Making + OH (Soft Pastel Purple) -->
        <article class="total-card card-ops">
          <span class="total-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="3"/>
              <path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1"/>
            </svg>
          </span>
          <div class="total-copy">
            <strong id="kpi-ops-tot">â‚¹ 0.00</strong>
            <span class="total-kicker">MAKING + OH</span>
            <small><span id="kpi-sam-tot">0.00 min</span> | <span id="kpi-mach-tot">0</span> machines</small>
          </div>
        </article>

        <!-- 6: Selling Price (Dark Navy Hero Card) -->
        <article class="total-card card-sell">
          <span class="total-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="9"/>
              <path d="M12 7v10M15 9.5c0-1.2-1.3-2-3-2s-3 .8-3 2 1.2 1.8 3 2 3 .8 3 2-1.3 2-3 2-3-.8-3-2"/>
            </svg>
          </span>
          <div class="total-copy">
            <strong id="kpi-sellprice">â‚¹ 0.00</strong>
            <span class="total-kicker">SELLING PRICE</span>
            <small id="kpi-sellprice-fr">USD 0.00</small>
            <em id="kpi-quota-val">Quote: 0.000</em>
          </div>
        </article>
      </section>

      <!-- Table 1: Fabric (Blue Accent Bar) -->
      <section class="grid-block studio-content-section" id="section-fabric">
        <div class="grid-block-head">
          <div class="grid-head-left">
            <div class="grid-title-wrap">
              <h2>
                <span class="sec-icon icon-fabric">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 7l8-4 8 4-8 4-8-4z"/>
                    <path d="M4 12l8 4 8-4"/>
                  </svg>
                </span>
                Fabric
              </h2>
              <span class="grid-header-total" id="head-tot-fabric">â‚¹ 0.00</span>
              <span class="grid-header-count" id="head-cnt-fabric">0 lines</span>
            </div>
            <div class="grid-sections-strip" id="strip-sections-fabric" style="display:none;"></div>
          </div>
          <div class="grid-actions">
            <button type="button" class="btn-add" onclick="addRow('fabric')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
              Add row
    
            <button type="button" class="btn-delete" onclick="deleteRow('fabric')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>
              Delete
    
          </div>
        </div>
        <div class="sap-table-container">
          <table class="sap-alv-table" id="table-fabric">
            <thead>
              <tr>
                <th class="col-center">SL</th>
                <th>Section</th>
                <th title="WA_ZCOST_S-ITEM">Item<small>ITEM</small></th>
                <th title="WA_ZCOST_S-MAKTX">Material desc.<small>MAKTX</small></th>
                <th class="col-center" title="WA_ZCOST_S-WID_SIZ">Width<small>WID_SIZ</small></th>
                <th class="col-num" title="WA_ZCOST_S-NET">Net<small>NET</small></th>
                <th class="col-num" title="WA_ZCOST_S-WSTE">Waste%<small>WSTE</small></th>
                <th class="col-num" title="WA_ZCOST_S-GROSS">Gross<small>GROSS</small></th>
                <th class="col-center" title="WA_ZCOST_S-CURUNIT">Curr<small>CURUNIT</small></th>
                <th class="col-num" title="WA_ZCOST_S-CONVR">E.curr<small>CONVR</small></th>
                <th class="col-num" title="WA_ZCOST_S-PURP">P.Price<small>PURP</small></th>
                <th class="col-num" title="WA_ZCOST_S-FRIGHT">Freight<small>FRIGHT</small></th>
                <th class="col-num" title="WA_ZCOST_S-DUTY">Duty<small>DUTY</small></th>
                <th class="col-num" title="WA_ZCOST_S-RATE">Cost<small>RATE</small></th>
                <th class="col-num" title="WA_ZCOST_S-TOTALINR">Total in INR<small>TOTALINR</small></th>
                <th class="col-num" id="th-ttcnv-fabric" title="WA_ZCOST_S-TOTALFR">TT in-USD<small>TOTALFR</small></th>
                <th class="col-num" title="WA_ZCOST_S-FINCOST">Fin<small>FINCOST</small></th>
              </tr>
            </thead>
            <tbody id="tbody-fabric"></tbody>
            <tfoot>
              <tr>
                <td colspan="13" class="total-label">Total</td>
                <td class="col-num" title="TRATE5" id="tot-cost-fabric">0.0000</td>
                <td class="col-num" title="TINR2" id="tot-inr-fabric">0.0000</td>
                <td class="col-num" title="TFRC2" id="tot-fr-fabric">0.0000</td>
                <td class="col-num" id="tot-fin-fabric">0.00</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <!-- Table 2: Trims (Orange Accent Bar) -->
      <section class="grid-block studio-content-section" id="section-trim">
        <div class="grid-block-head">
          <div class="grid-head-left">
            <div class="grid-title-wrap">
              <h2>
                <span class="sec-icon icon-trim">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 12l-8 8-8-8V4h8l8 8z"/>
                  </svg>
                </span>
                Trims
              </h2>
              <span class="grid-header-total" id="head-tot-trim">â‚¹ 0.00</span>
              <span class="grid-header-count" id="head-cnt-trim">0 lines</span>
            </div>
            <div class="grid-sections-strip" id="strip-sections-trim" style="display:none;"></div>
          </div>
          <div class="grid-actions">
            <button type="button" class="btn-add" onclick="addRow('trim')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
              Add row
    
            <button type="button" class="btn-delete" onclick="deleteRow('trim')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>
              Delete
    
          </div>
        </div>
        <div class="sap-table-container">
          <table class="sap-alv-table" id="table-trim">
            <thead>
              <tr>
                <th class="col-center">SL</th>
                <th>Section</th>
                <th title="WA_ZCOST_S-ITEM">Item<small>ITEM</small></th>
                <th title="WA_ZCOST_S-MAKTX">Material desc.<small>MAKTX</small></th>
                <th class="col-center" title="WA_ZCOST_S-WID_SIZ">Width<small>WID_SIZ</small></th>
                <th class="col-num" title="WA_ZCOST_S-NET">Net<small>NET</small></th>
                <th class="col-num" title="WA_ZCOST_S-WSTE">Waste%<small>WSTE</small></th>
                <th class="col-num" title="WA_ZCOST_S-GROSS">Gross<small>GROSS</small></th>
                <th class="col-center" title="WA_ZCOST_S-CURUNIT">Curr<small>CURUNIT</small></th>
                <th class="col-num" title="WA_ZCOST_S-CONVR">E.curr<small>CONVR</small></th>
                <th class="col-num" title="WA_ZCOST_S-PURP">P.Price<small>PURP</small></th>
                <th class="col-num" title="WA_ZCOST_S-FRIGHT">Freight<small>FRIGHT</small></th>
                <th class="col-num" title="WA_ZCOST_S-DUTY">Duty<small>DUTY</small></th>
                <th class="col-num" title="WA_ZCOST_S-RATE">Cost<small>RATE</small></th>
                <th class="col-num" title="WA_ZCOST_S-TOTALINR">Total in INR<small>TOTALINR</small></th>
                <th class="col-num" id="th-ttcnv-trim" title="WA_ZCOST_S-TOTALFR">TT in-USD<small>TOTALFR</small></th>
                <th class="col-num" title="WA_ZCOST_S-FINCOST">Fin<small>FINCOST</small></th>
              </tr>
            </thead>
            <tbody id="tbody-trim"></tbody>
            <tfoot>
              <tr>
                <td colspan="13" class="total-label">Total</td>
                <td class="col-num" title="TRATE5" id="tot-cost-trim">0.0000</td>
                <td class="col-num" title="TINR2" id="tot-inr-trim">0.0000</td>
                <td class="col-num" title="TFRC2" id="tot-fr-trim">0.0000</td>
                <td class="col-num" id="tot-fin-trim">0.00</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <!-- Table 3: Packing (Green Accent Bar) -->
      <section class="grid-block studio-content-section" id="section-packing">
        <div class="grid-block-head">
          <div class="grid-head-left">
            <div class="grid-title-wrap">
              <h2>
                <span class="sec-icon icon-pack">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 8l-9-5-9 5 9 5 9-5z"/>
                    <path d="M3 8v8l9 5 9-5V8"/>
                  </svg>
                </span>
                Packing
              </h2>
              <span class="grid-header-total" id="head-tot-packing">â‚¹ 0.00</span>
              <span class="grid-header-count" id="head-cnt-packing">0 lines</span>
            </div>
            <div class="grid-sections-strip" id="strip-sections-packing" style="display:none;"></div>
          </div>
          <div class="grid-actions">
            <button type="button" class="btn-add" onclick="addRow('packing')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
              Add row
    
            <button type="button" class="btn-delete" onclick="deleteRow('packing')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>
              Delete
    
          </div>
        </div>
        <div class="sap-table-container">
          <table class="sap-alv-table" id="table-packing">
            <thead>
              <tr>
                <th class="col-center">SL</th>
                <th>Section</th>
                <th title="WA_ZCOST_S-ITEM">Item<small>ITEM</small></th>
                <th title="WA_ZCOST_S-MAKTX">Material desc.<small>MAKTX</small></th>
                <th class="col-center" title="WA_ZCOST_S-WID_SIZ">Width<small>WID_SIZ</small></th>
                <th class="col-num" title="WA_ZCOST_S-NET">Net<small>NET</small></th>
                <th class="col-num" title="WA_ZCOST_S-WSTE">Waste%<small>WSTE</small></th>
                <th class="col-num" title="WA_ZCOST_S-GROSS">Gross<small>GROSS</small></th>
                <th class="col-center" title="WA_ZCOST_S-CURUNIT">Curr<small>CURUNIT</small></th>
                <th class="col-num" title="WA_ZCOST_S-CONVR">E.curr<small>CONVR</small></th>
                <th class="col-num" title="WA_ZCOST_S-PURP">P.Price<small>PURP</small></th>
                <th class="col-num" title="WA_ZCOST_S-FRIGHT">Freight<small>FRIGHT</small></th>
                <th class="col-num" title="WA_ZCOST_S-DUTY">Duty<small>DUTY</small></th>
                <th class="col-num" title="WA_ZCOST_S-RATE">Cost<small>RATE</small></th>
                <th class="col-num" title="WA_ZCOST_S-TOTALINR">Total in INR<small>TOTALINR</small></th>
                <th class="col-num" id="th-ttcnv-packing" title="WA_ZCOST_S-TOTALFR">TT in-USD<small>TOTALFR</small></th>
                <th class="col-num" title="WA_ZCOST_S-FINCOST">Fin<small>FINCOST</small></th>
              </tr>
            </thead>
            <tbody id="tbody-packing"></tbody>
            <tfoot>
              <tr>
                <td colspan="13" class="total-label">Total</td>
                <td class="col-num" title="TRATE5" id="tot-cost-packing">0.0000</td>
                <td class="col-num" title="TINR2" id="tot-inr-packing">0.0000</td>
                <td class="col-num" title="TFRC2" id="tot-fr-packing">0.0000</td>
                <td class="col-num" id="tot-fin-packing">0.00</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <!-- Table 4: Special / Others (matches SAP strip above summary) -->
      <section class="grid-block studio-content-section" id="section-special">
        <div class="grid-block-head">
          <h2>
            <span class="sec-icon icon-special">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2l2.4 7.4H22l-6 4.6 2.3 7-6.3-4.6L5.7 21l2.3-7-6-4.6h7.6L12 2z"/>
              </svg>
            </span>
            Special / Others
          </h2>
          <div class="grid-actions">
            <button type="button" class="btn-add" onclick="addRow('special')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
              Add row
    
            <button type="button" class="btn-delete" onclick="deleteRow('special')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>
              Delete
    
          </div>
        </div>
        <div class="sap-table-container">
          <table class="sap-alv-table" id="table-special">
            <thead>
              <tr>
                <th class="col-center">SL</th>
                <th>Section</th>
                <th title="WA_ZCOST_S-ITEM">Item<small>ITEM</small></th>
                <th title="WA_ZCOST_S-MAKTX">Material desc.<small>MAKTX</small></th>
                <th class="col-center" title="WA_ZCOST_S-WID_SIZ">Width<small>WID_SIZ</small></th>
                <th class="col-num" title="WA_ZCOST_S-NET">Net<small>NET</small></th>
                <th class="col-num" title="WA_ZCOST_S-WSTE">Waste%<small>WSTE</small></th>
                <th class="col-num" title="WA_ZCOST_S-GROSS">Gross<small>GROSS</small></th>
                <th class="col-center" title="WA_ZCOST_S-CURUNIT">Curr<small>CURUNIT</small></th>
                <th class="col-num" title="WA_ZCOST_S-CONVR">E.curr<small>CONVR</small></th>
                <th class="col-num" title="WA_ZCOST_S-PURP">P.Price<small>PURP</small></th>
                <th class="col-num" title="WA_ZCOST_S-FRIGHT">Freight<small>FRIGHT</small></th>
                <th class="col-num" title="WA_ZCOST_S-DUTY">Duty<small>DUTY</small></th>
                <th class="col-num" title="WA_ZCOST_S-RATE">Cost<small>RATE</small></th>
                <th class="col-num" title="WA_ZCOST_S-TOTALINR">Total in INR<small>TOTALINR</small></th>
                <th class="col-num" id="th-ttcnv-special" title="WA_ZCOST_S-TOTALFR">TT in-USD<small>TOTALFR</small></th>
                <th class="col-num" title="WA_ZCOST_S-FINCOST">Fin<small>FINCOST</small></th>
              </tr>
            </thead>
            <tbody id="tbody-special"></tbody>
            <tfoot>
              <tr>
                <td colspan="13" class="total-label">Total</td>
                <td class="col-num" title="TRATE5" id="tot-cost-special">0.0000</td>
                <td class="col-num" title="TINR2" id="tot-inr-special">0.0000</td>
                <td class="col-num" title="TFRC2" id="tot-fr-special">0.0000</td>
                <td class="col-num" id="tot-fin-special">0.00</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <!-- Bottom Costing & Commercial Parameters (4 Columns) -->
      <section class="cost-form" id="section-params">
        <!-- Col 1: Production -->
        <div class="cost-form-col">
          <label class="cost-field" title="HD_ZCOST-NMACHREQ"><span>No Of Machines Req<small>NMACHREQ</small></span><input type="number" id="form-nmachreq" min="0" max="255" value="37" readonly oninput="validateField(this, 'int', 255)" onchange="collectHeaderInputs()"></label>
          <label class="cost-field" title="HD_ZCOST-NPEICBND"><span>No Of Pieces per Band<small>NPEICBND</small></span><input type="number" id="form-npeicbnd" min="0" max="32767" value="0" readonly oninput="validateField(this, 'int', 32767)" onchange="collectHeaderInputs()"></label>
          <label class="cost-field" title="HD_ZCOST-SAM"><span>SAM in minutes<small>SAM</small></span><input type="text" id="form-sam" value="27.55" readonly oninput="validateField(this, 'decimal', 2)" onchange="collectHeaderInputs()"></label>
        </div>

        <!-- Col 2: Making & Waste -->
        <div class="cost-form-col">
          <label class="cost-field" title="HD_ZCOST-MAKING"><span>Making<small>MAKING</small></span><input type="text" id="form-making" value="150.000" readonly oninput="validateField(this, 'decimal', 3); collectHeaderInputs(); recalculateAll();" onchange="collectHeaderInputs(); recalculateAll();"></label>
          <label class="cost-field" title="HD_ZCOST-OVERHEAD"><span>Over head<small>OVERHEAD</small></span><input type="text" id="form-overhead" value="0.000" readonly oninput="validateField(this, 'decimal', 3); collectHeaderInputs(); recalculateAll();" onchange="collectHeaderInputs(); recalculateAll();"></label>
          <label class="cost-field" title="HD_ZCOST-ZWAST"><span>Garment Wast<small>ZWAST</small></span><input type="text" id="form-zwast" value="3.000" readonly oninput="validateField(this, 'percent'); collectHeaderInputs(); recalculateAll();" onchange="collectHeaderInputs(); recalculateAll();"></label>
        </div>

        <!-- Col 3: Markup & Commercials -->
        <div class="cost-form-col">
          <label class="cost-field" title="SUBTOT"><span>Sub total<small>SUBTOT</small></span><input type="text" id="form-subtot" value="565.5698" readonly></label>
          <label class="cost-field" title="HD_ZCOST-MARKUP_P"><span>Markup %<small>MARKUP_P</small></span><input type="text" id="form-markupp" value="24.850" readonly oninput="validateField(this, 'percent'); collectHeaderInputs(); recalculateAll();" onchange="collectHeaderInputs(); recalculateAll();"></label>
          <label class="cost-field" title="HD_ZCOST-MARKUP"><span>Mark up<small>MARKUP</small></span><input type="text" id="form-markup" value="140.54" readonly></label>
          <label class="cost-field" title="HD_ZCOST-COMMISION"><span>Commission %<small>COMMISION</small></span><input type="text" id="form-commision" value="4.500" readonly oninput="validateField(this, 'percent'); collectHeaderInputs(); recalculateAll();" onchange="collectHeaderInputs(); recalculateAll();"></label>
          <label class="cost-field" title="HD_ZCOST-COMM_VAL"><span>Commission<small>COMM_VAL</small></span><input type="text" id="form-commval" value="31.78" readonly></label>
        </div>

        <!-- Col 4: Price & Quote (Green Highlighted Inputs) -->
        <div class="cost-form-col cost-form-price">
          <label class="cost-field" title="HD_ZCOST-ADD_COST"><span>Additional cost<small>ADD_COST</small></span><input type="text" id="form-addcost" value="0.000" readonly oninput="validateField(this, 'decimal', 3); collectHeaderInputs(); recalculateAll();" onchange="collectHeaderInputs(); recalculateAll();"></label>
          <label class="cost-field" title="HD_ZCOST-DISCOUNT"><span>Discount %<small>DISCOUNT</small></span><input type="text" id="form-discount" value="0.000" readonly oninput="validateField(this, 'percent'); collectHeaderInputs(); recalculateAll();" onchange="collectHeaderInputs(); recalculateAll();"></label>
          <label class="cost-field cost-field-strong" title="HD_ZCOST-SELLPRICE"><span>SELLING PRICE<small>SELLPRICE</small></span><input type="text" id="form-sellprice" class="calc-sellprice" value="887.89" readonly></label>
          <label class="cost-field cost-field-strong" title="QOUC"><span id="label-sellprice-fr">QOUC<small>QOUC</small></span><input type="text" id="form-sellprice-fr" value="10.21" readonly></label>
          <label class="cost-field cost-field-strong" title="HD_ZCOST-QUOTA"><span>FINAL-QUOTE<small>QUOTA</small></span><input type="text" id="form-quota" value="11.910" readonly oninput="validateField(this, 'decimal', 3); collectHeaderInputs();" onchange="collectHeaderInputs()"></label>
        </div>

      </section>

      <!-- Save Audit Log -->
      <section class="audit-panel" id="audit-panel" hidden>
        <div class="audit-panel-head">
          <h2>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
            Save Audit Log
          </h2>
          <div class="audit-panel-actions">
            <button type="button" class="studio-btn studio-btn-outline" id="btn-audit-refresh" onclick="loadAuditLog()">Refresh</button>
            <button type="button" class="studio-btn studio-btn-outline" onclick="toggleAuditPanel(false)">Close</button>
          </div>
        </div>
        <div class="audit-table-wrap">
          <table class="audit-table" id="audit-table">
            <thead>
              <tr>
                <th>When</th>
                <th>User</th>
                <th>Method</th>
                <th>URL</th>
                <th>HTTP</th>
                <th>SO / Item</th>
                <th>Result</th>
                <th>Payload</th>
              </tr>
            </thead>
            <tbody id="audit-tbody">
              <tr><td colspan="8" class="audit-empty">No save activity yet</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Hidden compatibility hooks -->
      <div class="sheet-kpi-hooks" hidden>
        <span id="badge-all-count"></span>
        <span id="kpi-pcs-tot"></span>
        <span id="count-fabric"></span>
        <span id="count-trim"></span>
        <span id="count-packing"></span>
        <span id="count-special"></span>
        <span id="badge-special-count"></span>
      </div>

    </div>

    <!-- Studio Status Bar -->
    <footer class="studio-statusbar">
      <div class="studio-status-left">
        <span class="studio-status-icon info" id="sap-status-icon">âœ”</span>
        <span id="sap-status-text">Costing Sheet loaded: SO 22005882 Item 000010 [SAP Connected (Live)]</span>
      </div>
      <div class="studio-status-right">
        <span class="studio-sys-chip">EVOLV ZCOST v2.4</span>
        <span class="studio-sys-chip">Client 200</span>
        <span class="studio-sys-chip">APP-PROD</span>
      </div>
    </footer>

  </div>

  <!-- SAP Initial Action Screen (Matching Exact SAP GUI Layout) -->
  <div class="sap-initial-screen" id="sap-initial-screen">
    <header class="sap-initial-navbar">
      <div class="sap-initial-nav-left">
        <a href="<?= e(sap_reports_evol_url('portal_dashboard.php')) ?>" class="studio-logo-link" title="EVOLV Portal Dashboard">
          <img src="<?= e(asset('css/zcost/logo.png')) ?>" alt="EVOLV" class="studio-logo-img" style="height:28px; max-height:28px; width:auto; max-width:120px; object-fit:contain;" onerror="this.style.display='none'; document.getElementById('init-logo-fallback').style.display='inline';">
          <span id="init-logo-fallback" class="evolv-logo-text" style="display:none;">evolv</span>
        </a>
        <div class="studio-nav-divider"></div>
        <!-- Quick Nav: Reports Catalog (Grid) & Portal Dashboard (Home) -->
        <a href="<?= e(url('/')) ?>" class="studio-home-btn" title="Back to SAP Insight Catalog" aria-label="SAP Insight Catalog" style="margin-right:4px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
            <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
            <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
            <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
          </svg>
        </a>
        <a href="<?= e(sap_reports_evol_url('portal_dashboard.php')) ?>" class="studio-home-btn" title="Back to EVOLV Portal Dashboard" aria-label="Portal Dashboard">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
        </a>
        <span class="nav-title-wrap">
          <span class="studio-page-title">Sales Costing Sheet</span>
          <span class="sap-screen-title" style="background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; font-weight:700;">- INITIAL SCREEN</span>
        </span>
      </div>

      <div class="sap-initial-nav-right">
        <!-- Quick Search Box -->
        <div class="studio-nav-search" title="Quick Open Quotation">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="init-search-input" class="studio-nav-search-input" placeholder="Quick SO #..." onkeydown="if(event.key==='Enter'){const v=this.value.trim();if(v){quickSelectQuotation(v);}}">
        </div>

        <!-- User Menu -->
        <div class="evolv-user-menu-container">
          <div class="evolv-user-pill" id="init-user-pill" onclick="toggleUserDropdown(event)" role="button" tabindex="0" title="User Menu">
            <div class="evolv-avatar-wrapper">
              <div class="evolv-avatar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
              </div>
              <span class="evolv-status-dot"></span>
            </div>
            <div class="evolv-user-info">
              <span class="evolv-user-name">Admin</span>
              <span class="evolv-user-role">ADMIN â-¾</span>
            </div>
          </div>

          <div class="evolv-user-dropdown" id="evolv-user-dropdown-init">
            <div class="evolv-dropdown-header">
              <div class="evolv-dropdown-user-name">Administrator</div>
              <div class="evolv-dropdown-user-email">admin@evolvclothing.com</div>
            </div>
            <div class="evolv-dropdown-divider"></div>
            <a href="audit.php" class="evolv-dropdown-item admin-audit-link" hidden>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
              </svg>
              <span>Audit Log</span>
            </a>
            <a href="<?= e(sap_reports_evol_url('portal_dashboard.php')) ?>" class="evolv-dropdown-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
              </svg>
              <span>Back to Portal</span>
            </a>
            <a href="EVOL/logout.php" class="evolv-dropdown-item logout">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
              </svg>
              <span>Logout</span>
            </a>
          </div>
        </div>
      </div>
    </header>

    <!-- Colorful Modern Workspace Area -->
    <main class="sap-initial-workspace">
      <!-- Ambient colorful background glow orbs -->
      <div class="sap-initial-bg-glow" aria-hidden="true">
        <div class="glow-orb glow-orb-blue"></div>
        <div class="glow-orb glow-orb-purple"></div>
        <div class="glow-orb glow-orb-emerald"></div>
        <div class="glow-orb glow-orb-amber"></div>
      </div>

      <div class="sap-initial-container">
        
        <!-- Top Title / Hero Banner -->
        <div class="sap-initial-hero">
          <h1 class="sap-hero-title">Sales Costing Management</h1>
          <p class="sap-hero-subtitle">Create new quotation costing sheets, clone materials from existing orders, modify parameters, and register approval sign-off.</p>
        </div>

        <!-- Main Action Bento Grid (2 Groups: Create/Copy and Change/Approve) -->
        <div class="sap-action-bento-grid">
          
          <!-- Group 1: Create / Copy -->
          <div class="sap-bento-section" id="grp-create-copy">
            <div class="sap-bento-section-head">
              <h2 class="bento-section-title">Create / Copy</h2>
              <span class="bento-head-pill pill-blue">INITIALIZE &amp; CLONE</span>
            </div>

            <div class="sap-bento-cards-stack">
              <!-- Action Card 1: NEW COSTING -->
              <button type="button" class="sap-action-card card-new" id="btn-init-new" onclick="openActionModal('new')" title="Create New Sales Costing Sheet">
                <div class="action-card-accent-strip"></div>
                <div class="action-card-icon-wrap icon-wrap-blue">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="12" y1="18" x2="12" y2="12"></line>
                    <line x1="9" y1="15" x2="15" y2="15"></line>
                  </svg>
                </div>
                <div class="action-card-content">
                  <span class="action-card-tag tag-blue">NEW SHEET</span>
                  <span class="action-card-btn-text">NEW COSTING</span>
                  <p class="action-card-desc">Initialize a blank costing sheet for a quotation or sales order</p>
                </div>
                <div class="action-card-arrow">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                    <polyline points="9 18 15 12 9 6"></polyline>
                  </svg>
                </div>
      

              <!-- Action Card 2: COPY COSTING -->
              <button type="button" class="sap-action-card card-copy" id="btn-init-copy" onclick="openActionModal('copy')" title="Copy Costing from Existing Quotation">
                <div class="action-card-accent-strip"></div>
                <div class="action-card-icon-wrap icon-wrap-purple">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                  </svg>
                </div>
                <div class="action-card-content">
                  <span class="action-card-tag tag-purple">DUPLICATE</span>
                  <span class="action-card-btn-text">COPY COSTING</span>
                  <p class="action-card-desc">Clone fabrics, trims, packing, SAM &amp; overheads from reference SO</p>
                </div>
                <div class="action-card-arrow">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                    <polyline points="9 18 15 12 9 6"></polyline>
                  </svg>
                </div>
      
            </div>
          </div>

          <!-- Group 2: Change / Approve -->
          <div class="sap-bento-section" id="grp-change-approve">
            <div class="sap-bento-section-head">
              <h2 class="bento-section-title">Change / Approve</h2>
              <span class="bento-head-pill pill-amber">UPDATE &amp; REVIEW</span>
            </div>

            <div class="sap-bento-cards-stack">
              <!-- Action Card 3: CHANGE COSTING -->
              <button type="button" class="sap-action-card card-change" id="btn-init-change" onclick="openActionModal('change')" title="Change / Edit Costing Sheet">
                <div class="action-card-accent-strip"></div>
                <div class="action-card-icon-wrap icon-wrap-amber">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                  </svg>
                </div>
                <div class="action-card-content">
                  <span class="action-card-tag tag-amber">EDIT &amp; MODIFY</span>
                  <span class="action-card-btn-text">CHANGE COSTING</span>
                  <p class="action-card-desc">Open an existing quotation to modify fabrics, trims, and pricing</p>
                </div>
                <div class="action-card-arrow">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                    <polyline points="9 18 15 12 9 6"></polyline>
                  </svg>
                </div>
      

              <!-- Action Card 4: APPROVE COSTING -->
              <button type="button" class="sap-action-card card-approve" id="btn-init-approve" onclick="openActionModal('approve')" title="Review & Approve Costing Sheet">
                <div class="action-card-accent-strip"></div>
                <div class="action-card-icon-wrap icon-wrap-emerald">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <polyline points="9 12 11.5 14.5 15.5 9.5"></polyline>
                  </svg>
                </div>
                <div class="action-card-content">
                  <span class="action-card-tag tag-emerald">AUTHORIZED SIGN-OFF</span>
                  <span class="action-card-btn-text">APPROVE COSTING</span>
                  <p class="action-card-desc">Review commercial quotes, lock costing, and register approval</p>
                </div>
                <div class="action-card-arrow">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                    <polyline points="9 18 15 12 9 6"></polyline>
                  </svg>
                </div>
      
            </div>
          </div>
        </div> 
      </div>
    </main>
    <!-- Bottom SAP Status Bar -->
    <footer class="sap-initial-statusbar">
      <div class="studio-status-left">
        <span class="studio-status-icon info" id="init-status-icon">âœ”</span>
        <span id="init-status-text">Select an option to begin: Create, Copy, Change, or Approve a Sales Costing Sheet.</span>
      </div>
      <div class="studio-status-right">
        <span class="studio-sys-chip">EVOLV ZCOST v2.4</span>
        <span class="studio-sys-chip">Client 200</span>
        <span class="studio-sys-chip">SAP Live</span>
      </div>
    </footer>
  </div>

  <!-- Action Modal Dialog (For New, Change, Approve actions) -->
  <div class="sap-action-modal-overlay" id="modal-action-dialog" hidden>
    <div class="sap-action-modal-card" role="dialog" aria-modal="true" aria-labelledby="action-modal-title">
      <div class="sap-action-modal-header">
        <div class="sap-action-modal-head-left">
          <div class="sap-action-modal-badge" id="action-modal-badge">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
              <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
          </div>
          <div>
            <h3 class="sap-action-modal-title" id="action-modal-title">New Costing</h3>
            <p class="sap-action-modal-desc" id="action-modal-desc">Enter quotation details to continue</p>
          </div>
        </div>
        <button type="button" class="sap-action-modal-close" onclick="closeActionModal()" title="Close">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6L6 18M6 6l12 12"/></svg>

      </div>

      <form id="form-action-dialog" onsubmit="event.preventDefault(); handleActionDialogSubmit();">
        <div class="sap-action-modal-body">
          <div class="sap-action-field-group">
            <label class="sap-action-field-lbl" for="action-input-vbeln" id="action-lbl-vbeln">Quotation (Sales Order #)</label>
            <div class="sap-action-dual-inputs">
              <div class="sap-action-input-wrap">
                <span class="sap-action-input-prefix">SO</span>
                <input type="text" id="action-input-vbeln" class="sap-action-input" placeholder="e.g. 22005882" maxlength="10" autocomplete="off" required>
              </div>
              <div class="sap-action-input-wrap sap-action-posnr-wrap">
                <span class="sap-action-input-prefix">ITEM</span>
                <input type="text" id="action-input-posnr" class="sap-action-input" placeholder="000010" value="000010" maxlength="6">
              </div>
            </div>

            <!-- Quick presets for easy one-click testing -->
            <div class="sap-action-presets">
              <span class="sap-action-preset-lbl">Quick Presets:</span>
              <button type="button" class="sap-preset-pill" onclick="setActionDialogSO('22005882', '000010')">22005882</button>
              <button type="button" class="sap-preset-pill" onclick="setActionDialogSO('22000915', '000010')">22000915</button>
              <button type="button" class="sap-preset-pill" onclick="setActionDialogSO('3000008', '000010')">3000008</button>
            </div>
          </div>

          <div id="action-modal-error" class="sap-action-error-banner"></div>
        </div>

        <div class="sap-action-modal-footer">
          <button type="button" class="sap-modal-btn-cancel" onclick="closeActionModal()">Cancel</button>
          <button type="submit" class="sap-modal-btn-submit" id="action-modal-submit-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><polyline points="20 6 9 17 4 12"/></svg>
            <span id="action-modal-submit-text">Create Costing</span>
  
        </div>
      </form>
    </div>
  </div>
  <!-- Quotation Master Confirmation Modal (Yes / No) -->
  <div class="evolv-modal-overlay" id="modal-not-found" hidden>
    <div class="evolv-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="modal-not-found-title">
      <div class="evolv-modal-icon-badge">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="12" y1="18" x2="12" y2="12"/>
          <line x1="9" y1="15" x2="15" y2="15"/>
        </svg>
      </div>
      <h3 class="evolv-modal-title" id="modal-not-found-title">Quotation Master Not Found</h3>
      <p class="evolv-modal-desc">
        No Quotation Master exists in SAP or ZCOST records for:
      </p>
      <div class="evolv-modal-card-meta">
        <div class="meta-item">
          <span class="meta-lbl">Quotation (SO)</span>
          <span class="meta-val" id="modal-nf-vbeln">22005882</span>
        </div>
        <div class="meta-item">
          <span class="meta-lbl">Item (POSNR)</span>
          <span class="meta-val" id="modal-nf-posnr">000010</span>
        </div>
      </div>
      <p class="evolv-modal-question">
        Do you want to create a new quotation master for this?
      </p>
      <div class="evolv-modal-actions">
        <button type="button" class="btn-modal-yes" id="btn-modal-yes" onclick="handleConfirmCreateMaster()">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          <span>Yes, Create Master</span>

        <button type="button" class="btn-modal-copy" id="btn-modal-copy-from" onclick="handleCopyFromNotFound()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
          <span>Copy Existing</span>

        <button type="button" class="btn-modal-no" id="btn-modal-no" onclick="handleCancelSearchAgain()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <span>No, Search Again</span>

      </div>
    </div>
  </div>


<!-- Copy Costing Modal -->
  <div class="evolv-modal-overlay" id="modal-copy-costing" hidden>
  <div class="evolv-copy-modal" role="dialog" aria-modal="true" aria-labelledby="modal-copy-title">
    <div class="copy-modal-header">
      <div class="copy-modal-badge">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
          <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
        </svg>
      </div>
      <div class="copy-modal-title-box">
        <h3 class="copy-modal-title" id="modal-copy-title">Copy Costing Option</h3>
        <p class="copy-modal-desc">Copy materials, lines, and rate parameters from or to another Quotation</p>
      </div>
      <button type="button" class="copy-modal-close" onclick="closeCopyCostingModal()" title="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- Mode Selector Tabs -->
    <div class="copy-mode-tabs">
      <button type="button" class="copy-tab active" id="tab-copy-from" onclick="switchCopyMode('from')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        <span>Copy FROM Quotation</span>
      </button>
      <button type="button" class="copy-tab" id="tab-copy-to" onclick="switchCopyMode('to')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <span>Copy TO Another Quotation</span>
      </button>
    </div>

    <form id="form-copy-costing" onsubmit="event.preventDefault(); handleExecuteCopyCosting();">
      <!-- Error / Notice Box -->
      <div id="copy-modal-error" class="copy-error-banner" style="display: none;"></div>

      <!-- Mode 1: Copy FROM -->
      <div id="copy-panel-from" class="copy-tab-panel">
        <div class="copy-source-group">
          <label class="copy-field-lbl" for="copy-from-vbeln">Source Quotation (Reference SO #)</label>
          <div class="copy-dual-input">
            <div class="copy-input-wrap">
              <span class="copy-input-icon">SO</span>
              <input type="text" id="copy-from-vbeln" class="copy-input" placeholder="e.g. 22005882" maxlength="10" autocomplete="off">
            </div>
            <div class="copy-input-wrap copy-posnr-wrap">
              <span class="copy-input-icon">Item</span>
              <input type="text" id="copy-from-posnr" class="copy-input" placeholder="000010" value="000010" maxlength="6">
            </div>
          </div>
          <small class="copy-help">Enter existing quotation number to copy fabrics, trims, packing, special, and overheads from.</small>
        </div>

        <div class="copy-source-group" id="copy-from-target-group">
          <label class="copy-field-lbl" for="copy-from-target-vbeln">Target Quotation (Destination SO #)</label>
          <div class="copy-dual-input">
            <div class="copy-input-wrap">
              <span class="copy-input-icon">SO</span>
              <input type="text" id="copy-from-target-vbeln" class="copy-input" placeholder="e.g. 22005883" maxlength="10" autocomplete="off">
            </div>
            <div class="copy-input-wrap copy-posnr-wrap">
              <span class="copy-input-icon">Item</span>
              <input type="text" id="copy-from-target-posnr" class="copy-input" placeholder="000010" value="000010" maxlength="6">
            </div>
          </div>
          <small class="copy-help">The copied materials and overhead parameters will be assigned to this quotation.</small>
        </div>

        <div class="copy-target-info-pill" id="copy-target-info-box" style="display:none;">
          <span class="info-tag">TARGET</span>
          <span class="info-text">Costing will be applied to: <strong id="copy-target-display">--</strong></span>
        </div>
      </div>

      <!-- Mode 2: Copy TO -->
      <div id="copy-panel-to" class="copy-tab-panel" style="display: none;">
        <div class="copy-target-info-pill" style="margin-bottom: 14px;">
          <span class="info-tag">SOURCE</span>
          <span class="info-text">Current sheet costing will be duplicated: <strong id="copy-source-display">--</strong></span>
        </div>

        <div class="copy-source-group">
          <label class="copy-field-lbl" for="copy-to-vbeln">Target Quotation (New SO #)</label>
          <div class="copy-dual-input">
            <div class="copy-input-wrap">
              <span class="copy-input-icon">SO</span>
              <input type="text" id="copy-to-vbeln" class="copy-input" placeholder="e.g. 22005883" maxlength="10" autocomplete="off">
            </div>
            <div class="copy-input-wrap copy-posnr-wrap">
              <span class="copy-input-icon">Item</span>
              <input type="text" id="copy-to-posnr" class="copy-input" placeholder="000010" value="000010" maxlength="6">
            </div>
          </div>
          <small class="copy-help">A new costing sheet will be created with these numbers based on the current sheet.</small>
        </div>
      </div>

      <!-- Scope Section Selection -->
      <div class="copy-scope-box">
        <label class="copy-scope-title">Sections to Copy</label>
        <div class="copy-checkbox-grid">
          <label class="copy-check-item">
            <input type="checkbox" id="copy-opt-fabric" checked>
            <span>Fabric Materials</span>
          </label>
          <label class="copy-check-item">
            <input type="checkbox" id="copy-opt-trim" checked>
            <span>Trims &amp; Accessories</span>
          </label>
          <label class="copy-check-item">
            <input type="checkbox" id="copy-opt-packing" checked>
            <span>Packing Materials</span>
          </label>
          <label class="copy-check-item">
            <input type="checkbox" id="copy-opt-special" checked>
            <span>Special Operations</span>
          </label>
          <label class="copy-check-item" style="grid-column: 1 / -1;">
            <input type="checkbox" id="copy-opt-overheads" checked>
            <span>Rates, SAM, Making &amp; Overheads</span>
          </label>
        </div>
      </div>

      <!-- Actions -->
      <div class="copy-modal-actions">
        <button type="button" class="btn-copy-cancel" onclick="closeCopyCostingModal()">Cancel</button>
        <button type="submit" class="btn-copy-submit" id="btn-execute-copy">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
          </svg>
          <span id="btn-copy-submit-text">Copy Costing</span>

      </div>
    </form>
  </div>
  </div>

<!-- Interactive Engine -->
<script>
  window.ZCOST_API_URL = "<?= e(url('zcost/api')) ?>";
</script>
<script src="<?= e(asset('js/loader.js')) ?>?v=<?= filemtime(sap_reports_public_file('js/loader.js')) ?: time() ?>"></script>
<script src="<?= e(asset('js/zcost/sap_costing.js')) ?>?v=62"></script>

</body>
</html>
