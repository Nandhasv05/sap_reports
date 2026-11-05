function _zcostApiUrl(query) {
  const base = (typeof window !== 'undefined' && window.ZCOST_API_URL) ? window.ZCOST_API_URL : 'api.php';
  if (!query) return base;
  const sep = base.includes('?') ? '&' : '?';
  const q = String(query).startsWith('?') ? String(query).slice(1) : String(query);
  return `${base}${sep}${q}`;
}

/**
 * SAP ZCOST Sales Costing Sheet Application
 * Authentic SAP GUI ALV Grid Experience & Real-Time Calculation Engine
*/

let costData = null;
let isEditMode = false;
let currentVbeln = '3000008';
let currentPosnr = '000010';

/**
 * The IDs of the header editable fields.
 * @type {string[]}
*/
const HEADER_EDITABLE_IDS = [
  'hdr-vbeln', 'hdr-posnr', 'hdr-kwmeng',
  'hdr-cnv', 'hdr-cnvf'
];

/**
 * The IDs of the form editable fields.
 * @type {string[]}
 */
const FORM_EDITABLE_IDS = [
  'form-nmachreq', 'form-npeicbnd', 'form-sam', 'form-making', 'form-overhead',
  'form-zwast', 'form-markupp', 'form-commision', 'form-addcost', 'form-discount', 'form-quota'
];

/**
 * Checks if the sheet is approved.
 * @returns {boolean} True if the sheet is approved, false otherwise.
 */
function isSheetApproved() {
  return !!(costData && (costData.Approve === 'X' || costData.Approve === 'x'));
}

/**
 * Checks if the sheet can be edited.
 * @returns {boolean} True if the sheet can be edited, false otherwise.
 */
function canEditSheet() {
  if (typeof portalUser !== 'undefined' && zcostHas('edit') === false) return false;
  return !!costData && !isSheetApproved() && isEditMode;
}

function prefersReducedMotion() {
  return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Replay status badge / sheet entrance animations */
function pulseStatusBadge() {
  const screenTitle = document.getElementById('sap-screen-title');
  if (!screenTitle || prefersReducedMotion()) return;
  screenTitle.classList.remove('status-in');
  void screenTitle.offsetWidth;
  screenTitle.classList.add('status-in');
  setTimeout(() => screenTitle.classList.remove('status-in'), 1600);
}

function playSheetEntrance() {
  if (prefersReducedMotion()) return;
  document.body.classList.remove('sheet-animating');
  void document.body.offsetWidth;
  document.body.classList.add('sheet-animating');
  setTimeout(() => document.body.classList.remove('sheet-animating'), 700);
}

/**
 * Applies the UI lock state to the document body.
 * @returns {void}
 */
function applyUiLockState() {
  const approved = isSheetApproved();
  const editable = canEditSheet();

  document.body.classList.toggle('is-approved', approved);
  document.body.classList.toggle('is-editing', editable);

  const btnChange = document.getElementById('btn-change');
  const btnSave = document.getElementById('btn-save-primary');
  const btnApprove = document.getElementById('btn-approve');

  if (btnChange) {
    btnChange.disabled = approved || !costData;
    const changeIcon = editable
      ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
      : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
    btnChange.innerHTML = changeIcon + (editable ? '<span>Display</span>' : '<span>Change</span>');
    btnChange.className = 'studio-btn studio-btn-edit' + (editable ? ' active' : '');
    btnChange.title = approved ? 'Approved sheet is view only.' : '';
  }

  const btnCopy = document.getElementById('btn-copy-costing');
  const allowCopy = zcostHas('copy') !== false;
  const allowEdit = zcostHas('edit') !== false;
  if (btnCopy) {
    btnCopy.hidden = !allowCopy;
    btnCopy.disabled = !allowCopy || !costData;
    btnCopy.title = !allowCopy
      ? 'Copy Costing is not granted for this user.'
      : (!costData ? 'Load or search a quotation first to copy costing.' : 'Copy Costing lines & rates from or to another Quotation');
  }

  if (btnChange) {
    btnChange.disabled = approved || !costData || !allowEdit;
  }

  if (btnSave) {
    btnSave.disabled = approved || !costData || !allowEdit;
    btnSave.title = !allowEdit ? 'Update Costing is not granted for this user.' : (approved ? 'Approved sheet is view only.' : '');
  }

  if (btnApprove) {
    // Approve only for authorized approvers; hide when already approved
    const allowApprove = canApproveSheet();
    btnApprove.hidden = approved || !costData || !allowApprove;
    btnApprove.disabled = approved || !costData || !allowApprove;
    btnApprove.title = !allowApprove
      ? 'Approve allowed only for authorized approvers.'
      : (approved ? '' : 'Approve sheet (locks to view only)');
  }

  HEADER_EDITABLE_IDS.forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.type === 'checkbox') el.disabled = !editable;
    else el.readOnly = !editable;
  });

  FORM_EDITABLE_IDS.forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.readOnly = !editable;
  });

  document.querySelectorAll('.sap-table-cell-input').forEach((inp) => {
    inp.readOnly = !editable;
  });
  document.querySelectorAll('.sap-table-cell-select').forEach((sel) => {
    sel.disabled = !editable;
  });

  // Approved = disable Delete (and Add) on every section
  document.querySelectorAll('.btn-add, .btn-delete').forEach((btn) => {
    const lock = approved || !editable;
    btn.disabled = lock;
    btn.hidden = approved; // hide row actions entirely when approved
    btn.title = approved ? 'Approved sheet is view only — delete disabled.' : '';
    btn.setAttribute('aria-disabled', lock ? 'true' : 'false');
  });
}

/**
 * Formats a number to a string with the specified number of decimal places.
 * @param {number} val - The number to format.
 * @param {number} [dec=2] - The number of decimal places.
 * @returns {string} The formatted number.
 */
function num(val, dec = 2) {
  if (val === null || val === undefined || val === '') return '-';
  const n = parseFloat(val);
  if (isNaN(n)) return val;
  return n.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}

/**
 * Parses a number from a string.
 * @param {string} val - The string to parse.
 * @returns {number} The parsed number.
 */
function parseNum(val) {
  if (typeof val === 'number') return val;
  if (!val) return 0;
  const cleaned = String(val).replace(/,/g, '').trim();
  const n = parseFloat(cleaned);
  return isNaN(n) ? 0 : n;
}

/**
 * The options for the section dropdown.
 * @type {string[]}
 */
const SECTION_OPTIONS = [
  'Label',
  'Tags',
  'Packing',
  'Others',
  'Threads',
  'Button',
  'Zips',
  'Lining',
  'Trim Fabric',
  'Interlining',
  'Washing',
  'Embroidery',
  'Fabric'
];

/**
 * Gets the default section type for a given type.
 * @param {string} type - The type to get the default section for.
 * @returns {string} The default section type.
 */
function getDefaultSection(type) {
  if (type === 'fabric') return 'Fabric';
  if (type === 'trim') return 'Trim Fabric';
  if (type === 'packing') return 'Packing';
  if (type === 'special') return 'Others';
  return 'Fabric';
}

/**
 * Normalizes a section value to a standard format.
 * @param {string} val - The section value to normalize.
 * @param {string} type - The type of section to normalize.
 * @returns {string} The normalized section value.
 */
function normalizeSection(val, type) {
  if (!val || String(val).trim() === '') {
    return getDefaultSection(type);
  }
  const str = String(val).trim();
  const matched = SECTION_OPTIONS.find(opt => opt.toLowerCase() === str.toLowerCase());
  if (matched) return matched;
  const upper = str.toUpperCase();
  if (upper === 'TRIM' || upper === 'TRIM FABRIC') return 'Trim Fabric';
  if (upper === 'FABRIC' || upper === 'FAB') return 'Fabric';
  if (upper === 'PACKING' || upper === 'PACK') return 'Packing';
  if (upper === 'LABEL' || upper === 'LABELS') return 'Label';
  if (upper === 'TAG' || upper === 'TAGS') return 'Tags';
  if (upper === 'OTHER' || upper === 'OTHERS') return 'Others';
  if (upper === 'THREAD' || upper === 'THREADS') return 'Threads';
  if (upper === 'BUTTON' || upper === 'BUTTONS') return 'Button';
  if (upper === 'ZIP' || upper === 'ZIPS') return 'Zips';
  if (upper === 'LINNING' || upper === 'LINING') return 'Lining';
  if (upper === 'WASHING' || upper === 'WASH') return 'Washing';
  if (upper === 'EMBROIDERY' || upper === 'EMB') return 'Embroidery';
  return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
}

/**
 * The timestamp of the loader start time.
 * @type {number}
 */
let loaderStarted = 0;

/**
 * Shows the loader.
 * @param {string} msg - The message to display.
 */
function showLoader(msg) {
  loaderStarted = Date.now();
  if (window.rptLoader && typeof window.rptLoader.show === 'function') {
    window.rptLoader.show({
      solid: true,
      title: 'Loading from SAP',
      steps: [msg || 'Loading costing sheet…', 'Fetching SAP data…', 'Preparing sheet…'],
    });
    return;
  }
  const el = document.getElementById('load-overlay');
  const text = document.getElementById('load-overlay-text');
  if (text) text.textContent = msg || 'Loading costing sheet';
  if (el) el.hidden = false;
}

/**
 * Hides the loader.
 */
async function hideLoader() {
  const wait = 500 - (Date.now() - loaderStarted);
  if (wait > 0) await new Promise((resolve) => setTimeout(resolve, wait));
  if (window.rptLoader && typeof window.rptLoader.hide === 'function') {
    window.rptLoader.hide(true);
    return;
  }
  const el = document.getElementById('load-overlay');
  if (el) el.hidden = true;
}

/**
 * Updates the status of the application.
 * @param {string} msg - The message to display.
 * @param {string} type - The type of status.
 */
function updateStatus(msg, type = 'info') {
  const msgEl = document.getElementById('sap-status-text');
  const iconEl = document.getElementById('sap-status-icon');
  if (msgEl) msgEl.textContent = msg;
  if (iconEl) {
    iconEl.className = 'studio-status-icon ' + type;
    iconEl.textContent = (type === 'error') ? '!' : (type === 'warn' ? '!' : '✔');
  }
}

/**
 * Shows a toast notification.
 * @param {string} msg - The message to display.
 * @param {string} type - The type of toast.
 */
function showToast(msg, type = null) {
  let toastType = 'info';
  let title = 'Information';

  // Determine type
  if (typeof type === 'boolean') {
    toastType = type ? 'error' : 'info';
  } else if (typeof type === 'string' && type) {
    toastType = type.toLowerCase();
  } else {
    // Smart deduction from message content
    const lower = String(msg).toLowerCase();
    if (lower.includes('error') || lower.includes('fail') || lower.includes('rejected') || lower.includes('aborted') || lower.includes('cannot') || lower.includes('limit of')) {
      toastType = 'error';
    } else if (lower.includes('success') || lower.includes('saved') || lower.includes('approved') || lower.includes('created') || lower.includes('loaded')) {
      toastType = 'success';
    } else if (lower.includes('search') || lower.includes('warn') || lower.includes('please') || lower.includes('not found') || lower.includes('required') || lower.includes('must be')) {
      toastType = 'warning';
    } else {
      toastType = 'info';
    }
  }

  // Titles
  if (toastType === 'success') title = 'Success';
  else if (toastType === 'error') title = 'Validation Error';
  else if (toastType === 'warning' || toastType === 'warn') { toastType = 'warning'; title = 'Notice'; }
  else { toastType = 'info'; title = 'Information'; }

  // Header-Side Toast Container
  let container = document.getElementById('sap-toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'sap-toast-container';
    container.className = 'sap-toast-container';
    document.body.appendChild(container);
  }

  // Clear existing toasts if stack grows beyond 3
  while (container.children.length >= 3) {
    container.removeChild(container.firstChild);
  }

  // Choose Icon SVG
  let iconSvg = '';
  if (toastType === 'success') {
    iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
  } else if (toastType === 'error') {
    iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
  } else if (toastType === 'warning') {
    iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`;
  } else {
    iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`;
  }

  const toastCard = document.createElement('div');
  toastCard.className = `sap-toast-card sap-toast-${toastType}`;
  toastCard.innerHTML = `
    <div class="sap-toast-icon-badge">${iconSvg}</div>
    <div class="sap-toast-content">
      <div class="sap-toast-title">${escapeHtml(title)}</div>
      <div class="sap-toast-msg">${escapeHtml(msg)}</div>
    </div>
    <button type="button" class="sap-toast-close" title="Dismiss" aria-label="Dismiss">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"></line>
        <line x1="6" y1="6" x2="18" y2="18"></line>
      </svg>
    </button>
  `;

  const dismiss = () => {
    toastCard.classList.remove('show');
    setTimeout(() => {
      if (toastCard.parentElement) toastCard.remove();
    }, 250);
  };

  const closeBtn = toastCard.querySelector('.sap-toast-close');
  if (closeBtn) closeBtn.addEventListener('click', dismiss);

  container.appendChild(toastCard);

  requestAnimationFrame(() => {
    toastCard.classList.add('show');
  });

  setTimeout(dismiss, 4200);
}

/**
 * The pending quotation that was not found.
 * @type {object}
 */
let pendingNotFoundQuotation = null;

/**
 * Fetches the cost sheet from the backend.
 * @param {string} vbeln - The sales order number.
 * @param {string} posnr - The item number.
 * @returns {Promise<boolean>} True if the cost sheet was fetched successfully, false otherwise.
 */
async function fetchCostSheet(vbeln, posnr) {
  currentVbeln = String(vbeln || currentVbeln || '').trim();
  currentPosnr = String(posnr || currentPosnr || '000010').trim();

  updateStatus(`Reading Quotation ${currentVbeln} / Item ${currentPosnr}...`, 'info');
  showLoader('Loading costing sheet');

  try {
    const res = await fetch(_zcostApiUrl(`vbeln=${encodeURIComponent(currentVbeln)}&posnr=${encodeURIComponent(currentPosnr)}`));
    const json = await res.json();

    if (json.success && json.data) {
      hideNotFoundModal();
      costData = json.data;
      const mainWin = document.getElementById('sap-window');
      if (mainWin) mainWin.style.display = 'flex';
      const landing = document.getElementById('evolv-landing-page');
      if (landing) landing.classList.add('hidden');
      hideInitialScreen();
      renderAll();
      const srcText = json.is_new
        ? 'New Quotation Master Created'
        : (json.source === 'LIVE_SAP' ? 'SAP Connected (Live)' : (json.note || 'Snapshot loaded'));
      updateStatus(`Costing Sheet loaded: SO ${costData.Vbeln} Item ${costData.Posnr} [${srcText}]`, 'info');
      showToast(json.is_new ? `Created new quotation master for ${costData.Vbeln}` : `Loaded ${costData.Vbeln} - ${srcText}`);
      return true;
    } else if (json.not_found) {
      if (isZcostViewOnly()) {
        hideInitialScreen();
        updateStatus(`Quotation ${currentVbeln} (Item ${currentPosnr}) not found.`, 'warn');
        showToast('Quotation not found', true);
        return false;
      }
      // Prompt user with Yes / No dialog: "Do you want to create new quotation master?"
      pendingNotFoundQuotation = { vbeln: currentVbeln, posnr: currentPosnr };
      showNotFoundModal(currentVbeln, currentPosnr);
      updateStatus(`Quotation ${currentVbeln} (Item ${currentPosnr}) not found. Please confirm creation.`, 'warn');
      return false;
    } else {
      updateStatus(`Error: ${json.error || 'Failed to load Cost Sheet'}`, 'error');
      showToast(json.error || 'Quotation not found', true);
      return false;
    }
  } catch (err) {
    updateStatus(`Network Error: ${err.message}`, 'error');
    return false;
  } finally {
    await hideLoader();
  }
}

/**
 * Validates a field.
 * @param {HTMLInputElement} inputEl - The input element to validate.
 * @param {string} type - The type of field to validate.
 * @param {number} extra - The extra value to validate.
 * @returns {boolean} True if the field is valid, false otherwise.
 */
function validateField(inputEl, type, extra) {
  if (!inputEl) return true;
  let val = inputEl.value;
  let isValid = true;
  let message = '';

  switch (type) {
    case 'char4': // wid_siz (CHAR4) - strictly limit to 4 characters
      if (val && val.length > 4) {
        inputEl.value = val.slice(0, 4);
        val = inputEl.value;
      }
      if (val && val.length > 4) {
        isValid = false;
        message = 'Max 4 characters allowed (SAP CHAR4)';
      }
      break;

    case 'matnr': // item (CHAR40)
    case 'maktx': // maktx (CHAR40)
      if (val && val.length > 40) {
        inputEl.value = val.slice(0, 40);
        val = inputEl.value;
      }
      if (val && val.length > 40) {
        isValid = false;
        message = 'Max 40 characters allowed (SAP CHAR40)';
      }
      break;

    case 'vbeln': // Sales Order (CHAR10)
      if (val && val.length > 10) {
        inputEl.value = val.slice(0, 10);
        val = inputEl.value;
      }
      if (!val || val.trim() === '') {
        isValid = false;
        message = 'Sales Order Number is required (SAP CHAR10)';
      }
      break;

    case 'numc6': // Item/Posnr (NUMC6)
      if (!/^\d*$/.test(val)) {
        inputEl.value = val.replace(/\D/g, '').slice(0, 6);
        val = inputEl.value;
      }
      if (val && val.length > 6) {
        inputEl.value = val.slice(0, 6);
      }
      if (!val || val.length === 0) {
        isValid = false;
        message = 'Item number required (6 digits NUMC)';
      }
      break;

    case 'waers': // Currency code (WAERS CHAR5)
      val = val.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 5);
      inputEl.value = val;
      if (val.length < 2 || val.length > 5) {
        isValid = false;
        message = 'Currency must be 2-5 uppercase letters (e.g. USD, EUR, INR)';
      }
      break;

    case 'int': { // Integer with max limit (e.g. 255 for INT1, 32767 for INT2)
      const numVal = parseInt(val, 10);
      const maxLimit = typeof extra === 'number' ? extra : 32767;
      if (isNaN(numVal) || numVal < 0 || numVal > maxLimit) {
        isValid = false;
        message = `Must be an integer between 0 and ${maxLimit}`;
      }
      break;
    }

    case 'percent': { // Percentage 0 - 100
      const numVal = parseFloat(val);
      if (isNaN(numVal) || numVal < 0 || numVal > 100) {
        isValid = false;
        message = 'Percentage must be between 0.00% and 100.00%';
      }
      break;
    }

    case 'decimal': { // Non-negative decimal number
      const numVal = parseFloat(val);
      if (isNaN(numVal) || numVal < 0) {
        isValid = false;
        message = 'Must be a valid positive number';
      }
      break;
    }

    default:
      break;
  }

  if (!isValid) {
    inputEl.classList.add('is-invalid');
    inputEl.setAttribute('title', message);
  } else {
    inputEl.classList.remove('is-invalid');
    inputEl.removeAttribute('title');
  }

  return isValid;
}

/**
 * Validates the entire costing sheet before sending to SAP.
 * @returns {boolean} True if the costing sheet is valid, false otherwise.
 */
function validateCostSheet() {
  // 1. Check all current DOM inputs with is-invalid
  const invalidEls = document.querySelectorAll('.is-invalid');
  if (invalidEls.length > 0) {
    const firstInvalid = invalidEls[0];
    firstInvalid.focus();
    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const msg = firstInvalid.getAttribute('title') || 'Please fix highlighted validation errors.';
    showToast(`Validation error: ${msg}`, true);
    return false;
  }

  // 2. Validate Header Fields
  if (!costData.Vbeln || costData.Vbeln.trim().length === 0 || costData.Vbeln.length > 10) {
    showToast('Validation Error: Sales Order (VBELN) must be 1 to 10 characters', true);
    document.getElementById('hdr-vbeln')?.focus();
    return false;
  }
  if (!costData.Posnr || !/^\d{1,6}$/.test(costData.Posnr.trim())) {
    showToast('Validation Error: Item (POSNR) must be numeric up to 6 digits', true);
    document.getElementById('hdr-posnr')?.focus();
    return false;
  }
  if (!costData.Cnv || costData.Cnv.trim().length < 2 || costData.Cnv.length > 5) {
    showToast('Validation Error: Currency (CNV) must be 2 to 5 letters', true);
    document.getElementById('hdr-cnv')?.focus();
    return false;
  }
  if (parseNum(costData.Cnvf) <= 0) {
    showToast('Validation Error: Currency Rate (CNVF) must be greater than 0', true);
    document.getElementById('hdr-cnvf')?.focus();
    return false;
  }

  // 3. Validate Commercial Parameters
  if (parseInt(costData.Nmachreq, 10) < 0 || parseInt(costData.Nmachreq, 10) > 255) {
    showToast('Validation Error: No Of Machines Req must be between 0 and 255 (SAP INT1)', true);
    document.getElementById('form-nmachreq')?.focus();
    return false;
  }
  if (parseInt(costData.Npeicbnd, 10) < 0 || parseInt(costData.Npeicbnd, 10) > 32767) {
    showToast('Validation Error: No Of Pieces per Band must be between 0 and 32767 (SAP INT2)', true);
    document.getElementById('form-npeicbnd')?.focus();
    return false;
  }

  // 4. Validate Line Items (Fabric, Trim, Packing)
  const categories = [
    { type: 'fabric', label: 'Fabric', items: costData.to_Fabric?.results || [] },
    { type: 'trim', label: 'Trim', items: costData.to_Trim?.results || [] },
    { type: 'packing', label: 'Packing', items: costData.to_Packing?.results || [] }
  ];

  for (const cat of categories) {
    for (let i = 0; i < cat.items.length; i++) {
      const row = cat.items[i];
      const sl = row.Slno || (i + 1);

      // Width / Size (WidSiz) strict max 4 chars
      if (row.WidSiz && String(row.WidSiz).length > 4) {
        showToast(`${cat.label} Row ${sl}: Width/Size "${row.WidSiz}" exceeds SAP limit of 4 characters (CHAR4)`, true);
        return false;
      }
      // Material Description max 40 chars
      if (row.Maktx && String(row.Maktx).length > 40) {
        showToast(`${cat.label} Row ${sl}: Material description exceeds 40 characters (CHAR40)`, true);
        return false;
      }
      // Waste % 0 - 100
      const waste = parseNum(row.Wste);
      if (waste < 0 || waste > 100) {
        showToast(`${cat.label} Row ${sl}: Waste% must be between 0 and 100%`, true);
        return false;
      }
      // Freight % 0 - 100
      const fright = parseNum(row.Fright);
      if (fright < 0 || fright > 100) {
        showToast(`${cat.label} Row ${sl}: Freight% must be between 0 and 100%`, true);
        return false;
      }
      // Duty % 0 - 100
      const duty = parseNum(row.Duty);
      if (duty < 0 || duty > 100) {
        showToast(`${cat.label} Row ${sl}: Duty% must be between 0 and 100%`, true);
        return false;
      }
      // Net must be non-negative
      if (parseNum(row.Net) < 0) {
        showToast(`${cat.label} Row ${sl}: Net consumption cannot be negative`, true);
        return false;
      }
    }
  }

  return true;
}

// POST / Save Cost Sheet to SAP
async function saveCostSheet(options = {}) {
  if (!costData) return;

  // Approved = view only (Approve/Un-Approve still posts via { force: true })
  if (isSheetApproved() && !options.force) {
    updateStatus('Sheet is APPROVED — view only.', 'warn');
    showToast('Approved sheet is view only.', true);
    return;
  }

  // Gather current inputs from UI
  collectHeaderInputs();
  recalculateAll();

  // Validate before sending to SAP
  if (!validateCostSheet()) {
    updateStatus('Save aborted: please resolve validation errors.', 'warn');
    return;
  }

  updateStatus(`Posting Costing Sheet ${costData.Vbeln} to SAP ZCOST_SRV...`, 'info');
  showToast('Posting to SAP ZCOST_SRV/ZCOSTHSet...');
  showLoader('Saving costing sheet');

  try {
    const res = await fetch(_zcostApiUrl(''), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: options.action || 'save', data: costData })
    });
    const json = await res.json();

    if (json.success) {
      if (json.source === 'LIVE_SAP') {
        costData = json.data || costData;
        renderAll();
        updateStatus(`Saved successfully to live SAP! (HTTP ${json.http_code})`, 'info');
        showToast('Successfully posted and updated in SAP!');
      } else {
        updateStatus(json.message || 'Saved in local session.', 'warn');
        showToast(json.message || 'Saved locally in active session');
      }
      if (json.audit) {
        prependAuditEntry(json.audit);
        const panel = document.getElementById('audit-panel');
        if (panel && !panel.hidden) loadAuditLog();
      }
    } else {
      const sapMsg = json.error || json.sap_error || json.message || 'SAP rejected save request';
      updateStatus(`Save error: ${sapMsg}`, 'error');
      showToast(`Save error: ${sapMsg}`, true);
      if (json.audit) prependAuditEntry(json.audit);
    }
  } catch (err) {
    updateStatus(`Save request failed: ${err.message}`, 'error');
    showToast(`Error: ${err.message}`, true);
  } finally {
    await hideLoader();
  }
}

// Render All Screens & Tables
function renderAll() {
  if (!costData) return;

  // Ensure arrays exist
  if (!costData.to_Fabric || !Array.isArray(costData.to_Fabric.results)) costData.to_Fabric = { results: [] };
  if (!costData.to_Trim || !Array.isArray(costData.to_Trim.results)) costData.to_Trim = { results: [] };
  if (!costData.to_Packing || !Array.isArray(costData.to_Packing.results)) costData.to_Packing = { results: [] };
  if (!costData.to_Special || !Array.isArray(costData.to_Special.results)) costData.to_Special = { results: [] };


  renderHeader();
  renderTable('fabric', costData.to_Fabric.results);
  renderTable('trim', costData.to_Trim.results);
  renderTable('packing', costData.to_Packing.results);
  renderTable('special', costData.to_Special.results);
  renderBottomForm();
  recalculateAll();
  applyUiLockState();
  playSheetEntrance();
  pulseStatusBadge();

  // Sync Stage Tab Badge Counts
  const fabCount = costData.to_Fabric?.results?.length || 0;
  const trimCount = costData.to_Trim?.results?.length || 0;
  const packCount = costData.to_Packing?.results?.length || 0;
  const specialCount = costData.to_Special?.results?.length || 0;
  const allCount = fabCount + trimCount + packCount + specialCount;

  const elAll = document.getElementById('badge-all-count');
  if (elAll) elAll.textContent = allCount;
  const elFab = document.getElementById('badge-fabric-count');
  if (elFab) elFab.textContent = fabCount;
  const elTrim = document.getElementById('badge-trim-count');
  if (elTrim) elTrim.textContent = trimCount;
  const elPack = document.getElementById('badge-packing-count');
  if (elPack) elPack.textContent = packCount;
  const elSpecial = document.getElementById('badge-special-count');
  if (elSpecial) elSpecial.textContent = specialCount;
}

// Render Window Header & Controls
function renderHeader() {
  if (!costData) return;
  const isApproved = (costData.Approve === 'X' || costData.Approve === 'x');
  const titleText = isApproved ? 'Sales Costing Sheet : APPROVED' : 'Sales Costing Sheet : NOT APPROVED';

  // Window titlebar
  const winTitle = document.getElementById('sap-window-title');
  if (winTitle) winTitle.textContent = titleText;

  // Screen header title
  const screenTitle = document.getElementById('sap-screen-title');
  if (screenTitle) {
    screenTitle.textContent = isApproved ? '• APPROVED' : '• NOT APPROVED';
    screenTitle.className = 'sap-screen-title ' + (isApproved ? 'approved' : 'not-approved');
  }

  // Approve button: only authorized approvers, and only when NOT approved
  const btnApprove = document.getElementById('btn-approve');
  if (btnApprove) {
    const approveIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6L9 17l-5-5"/></svg>';
    const allowApprove = canApproveSheet();
    btnApprove.innerHTML = approveIcon + '<span>Approve</span>';
    btnApprove.className = 'studio-btn studio-btn-approve';
    btnApprove.hidden = isApproved || !allowApprove;
    btnApprove.disabled = isApproved || !costData || !allowApprove;
    btnApprove.title = !allowApprove
      ? 'Approve allowed only for authorized approvers.'
      : (isApproved ? '' : 'Approve sheet (locks to view only)');
  }

  // Studio header SO badge sync
  const studioSo = document.getElementById('studio-so-num');
  if (studioSo) studioSo.textContent = costData.Vbeln || currentVbeln;

  const studioPos = document.getElementById('studio-posnr-num');
  if (studioPos) studioPos.textContent = costData.Posnr || currentPosnr;

  // Header input values
  const vbelnInput = document.getElementById('hdr-vbeln');
  if (vbelnInput) vbelnInput.value = String(costData.Vbeln || currentVbeln || '').trim();

  const navSearch = document.getElementById('nav-search-input');
  if (navSearch && (costData.Vbeln || currentVbeln)) navSearch.value = costData.Vbeln || currentVbeln;

  const posnrInput = document.getElementById('hdr-posnr');
  if (posnrInput) posnrInput.value = costData.Posnr || currentPosnr;

  const zzallCheckbox = document.getElementById('hdr-zzall');
  if (zzallCheckbox) zzallCheckbox.checked = (costData.Zzall === 'X' || costData.Zzall === 'x');

  const matnrInput = document.getElementById('hdr-matnr');
  if (matnrInput) matnrInput.value = costData.Matnr || costData.Style || '';

  const maktxInput = document.getElementById('hdr-maktx');
  if (maktxInput) maktxInput.value = costData.Maktxh || costData.StyleName || costData.MaktxHdr || '';

  const kwmengInput = document.getElementById('hdr-kwmeng');
  if (kwmengInput) kwmengInput.value = num(costData.Kwmeng ?? costData.Menge ?? costData.Qty ?? 0, 3);

  const cnvInput = document.getElementById('hdr-cnv');
  if (cnvInput) cnvInput.value = costData.Cnv || 'EUR';

  const cnvfInput = document.getElementById('hdr-cnvf');
  if (cnvfInput) cnvfInput.value = num(costData.Cnvf, 2);

  const cmdInput = document.getElementById('sap-cmd-input');
  if (cmdInput && !cmdInput.value) cmdInput.value = costData.Vbeln || '';

  // Approved sheets are always view-only (after values are painted)
  if (isApproved && isEditMode) isEditMode = false;
  applyUiLockState();
}

// Render ALV Table
function renderTable(type, rows) {
  const tbody = document.getElementById(`tbody-${type}`);
  if (!tbody) return;

  const cnv = costData.Cnv || 'EUR';
  // Update header column label
  const colCnvHeader = document.getElementById(`th-ttcnv-${type}`);
  if (colCnvHeader) colCnvHeader.textContent = `TT in-${cnv}`;

  if (!rows || rows.length === 0) {
    tbody.innerHTML = `<tr><td colspan="17" style="text-align:center; color:#888; padding:12px;">No ${type} line items recorded</td></tr>`;
    return;
  }

  let html = '';
  rows.forEach((r, idx) => {
    const sl = r.Slno ? r.Slno : (idx + 1);
    const section = normalizeSection(r.Zsection, type);
    if (!r.Zsection) {
      r.Zsection = section;
    }
    const item = r.Item || '';
    const maktx = r.Maktx || '';
    const width = r.WidSiz || '';
    const net = parseNum(r.Net);
    const waste = parseNum(r.Wste);
    const gross = parseNum(r.Gross) || (net * (1 + waste / 100));
    const curr = r.Curunit || 'INR';
    const convr = parseNum(r.Convr) || 1.0;
    const purp = parseNum(r.Purp);
    const freight = parseNum(r.Fright);
    const duty = parseNum(r.Duty);
    const cost = parseNum(r.Rate);
    const fincost = parseNum(r.Fincost);
    const totalinr = parseNum(r.Totalinr) || (gross * cost);
    const totalfr = parseNum(r.Totalfr) || (totalinr / (parseNum(costData.Cnvf) || 1));

    html += `
      <tr class="${r._isNew ? 'row-enter' : ''}" data-type="${type}" data-idx="${idx}">
        <td class="col-center sl-col">${sl}</td>
        <td class="col-text cell-section-col">
          <select class="sap-table-cell-select cell-section-select" title="${escapeHtml(section)}"
                  onchange="updateRowField('${type}', ${idx}, 'Zsection', this.value)" ${!isEditMode ? 'disabled' : ''}>
            ${SECTION_OPTIONS.map(opt => `
              <option value="${escapeHtml(opt)}" ${opt.toLowerCase() === section.toLowerCase() ? 'selected' : ''}>${escapeHtml(opt)}</option>
            `).join('')}
            ${section && !SECTION_OPTIONS.some(o => o.toLowerCase() === section.toLowerCase()) ? `
              <option value="${escapeHtml(section)}" selected>${escapeHtml(section)}</option>
            ` : ''}
          </select>
        </td>
        <td class="cell-coral col-text">
          <div class="cell-dropdown-wrap">
            <span class="cell-arrow">▼</span>
            <input type="text" class="sap-table-cell-input cell-coral cell-text" title="${escapeHtml(item)}" value="${escapeHtml(item)}"
                   maxlength="40"
                   style="min-width:${Math.max(String(item).length, 4)}ch"
                   oninput="validateField(this, 'matnr')"
                   onchange="updateRowField('${type}', ${idx}, 'Item', this.value)" ${!isEditMode ? 'readonly' : ''}>
          </div>
        </td>
        <td class="col-desc">
          <input type="text" class="sap-table-cell-input cell-coral cell-text" title="${escapeHtml(maktx)}" value="${escapeHtml(maktx)}"
                   maxlength="40"
                   style="min-width:${Math.max(String(maktx).length, 12)}ch"
                   oninput="validateField(this, 'maktx')"
                   onchange="updateRowField('${type}', ${idx}, 'Maktx', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-text">
          <input type="text" class="sap-table-cell-input col-center cell-amber cell-text" title="WA_ZCOST_S-WID_SIZ" value="${escapeHtml(width)}"
                 maxlength="4" placeholder="W/S"
                 style="min-width:${Math.max(String(width).length, 4)}ch"
                 oninput="validateField(this, 'char4')"
                 onchange="updateRowField('${type}', ${idx}, 'WidSiz', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num cell-coral" title="WA_ZCOST_S-NET" value="${num(net, 4)}" 
                 oninput="validateField(this, 'decimal', 4)"
                 onchange="updateRowField('${type}', ${idx}, 'Net', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num cell-coral" title="WA_ZCOST_S-WSTE" value="${num(waste, 2)}" 
                 oninput="validateField(this, 'percent')"
                 onchange="updateRowField('${type}', ${idx}, 'Wste', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num cell-cyan" id="cell-gross-${type}-${idx}">${num(gross, 4)}</td>
        <td class="col-center">
          <input type="text" class="sap-table-cell-input col-center cell-curr-badge" style="width:48px; text-transform:uppercase;" value="${escapeHtml(curr)}" 
                 maxlength="5"
                 oninput="this.value = this.value.toUpperCase(); validateField(this, 'waers')"
                 onchange="updateRowField('${type}', ${idx}, 'Curunit', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num" style="width:50px;" value="${num(convr, 2)}" 
                 oninput="validateField(this, 'decimal', 2)"
                 onchange="updateRowField('${type}', ${idx}, 'Convr', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num" value="${num(purp, 4)}" 
                 oninput="validateField(this, 'decimal', 4)"
                 onchange="updateRowField('${type}', ${idx}, 'Purp', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num" value="${num(freight, 2)}" 
                 oninput="validateField(this, 'percent')"
                 onchange="updateRowField('${type}', ${idx}, 'Fright', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num" value="${num(duty, 2)}" 
                 oninput="validateField(this, 'percent')"
                 onchange="updateRowField('${type}', ${idx}, 'Duty', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num cell-blue" value="${num(cost, 4)}" 
                 oninput="validateField(this, 'decimal', 4)"
                 onchange="updateRowField('${type}', ${idx}, 'Rate', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
        <td class="col-num cell-dark" id="cell-totalinr-${type}-${idx}">${num(totalinr, 4)}</td>
        <td class="col-num cell-blue" id="cell-totalfr-${type}-${idx}">${num(totalfr, 4)}</td>
        <td class="col-num">
          <input type="text" class="sap-table-cell-input col-num cell-fin" value="${num(fincost, 2)}" 
                 oninput="validateField(this, 'decimal', 2)"
                 onchange="updateRowField('${type}', ${idx}, 'Fincost', this.value)" ${!isEditMode ? 'readonly' : ''}>
        </td>
      </tr>
    `;
  });

  tbody.innerHTML = html;
}

// Update Single Row Field and Recalculate
function updateRowField(type, idx, field, rawValue) {
  if (!canEditSheet()) return;
  const list = getRowList(type);
  if (!list || !list[idx]) return;

  const row = list[idx];
  const numFields = ['Net', 'Wste', 'Gross', 'Convr', 'Purp', 'Fright', 'Duty', 'Rate', 'Totalinr', 'Totalfr', 'Fincost'];
  if (numFields.includes(field)) {
    let n = parseNum(rawValue);
    if (['Wste', 'Fright', 'Duty'].includes(field)) {
      if (n < 0) n = 0;
      if (n > 100) n = 100;
    } else {
      if (n < 0) n = 0;
    }
    row[field] = n.toFixed(4);
  } else if (field === 'WidSiz') {
    row[field] = String(rawValue || '').slice(0, 4);
  } else if (field === 'Item' || field === 'Maktx') {
    row[field] = String(rawValue || '').slice(0, 40);
  } else if (field === 'Curunit') {
    row[field] = String(rawValue || 'INR').toUpperCase().slice(0, 5);
  } else {
    row[field] = rawValue;
  }

  // Auto-calculate Gross if Net or Waste changed
  if (field === 'Net' || field === 'Wste') {
    const net = parseNum(row.Net);
    const waste = parseNum(row.Wste);
    const gross = net * (1 + waste / 100);
    row.Gross = gross.toFixed(4);
    const cellGross = document.getElementById(`cell-gross-${type}-${idx}`);
    if (cellGross) cellGross.textContent = num(gross, 4);
  }

  // Auto-calculate Rate/Cost if Purp, Freight, Duty, Fincost, Convr changed
  if (['Purp', 'Convr', 'Fright', 'Duty', 'Fincost'].includes(field)) {
    const purp = parseNum(row.Purp);
    const convr = parseNum(row.Convr) || 1.0;
    const freight = parseNum(row.Fright);
    const duty = parseNum(row.Duty);
    const fincost = parseNum(row.Fincost);

    // Cost formula: (Purp * Convr) * (1 + (Freight + Duty + Fincost)/100) or similar
    const freightAmt = (purp * convr) * (freight / 100);
    const dutyAmt = (purp * convr) * (duty / 100);
    const calculatedRate = (purp * convr) + freightAmt + dutyAmt + fincost;
    if (calculatedRate > 0) {
      row.Rate = calculatedRate.toFixed(4);
    }
  }

  // Recalculate Total INR and Total FR for row
  const gross = parseNum(row.Gross) || parseNum(row.Net);
  const rate = parseNum(row.Rate);
  const totalinr = gross * rate;
  row.Totalinr = totalinr.toFixed(4);

  const cnvf = parseNum(costData.Cnvf) || 1.0;
  const totalfr = cnvf > 0 ? (totalinr / cnvf) : 0;
  row.Totalfr = totalfr.toFixed(4);

  // Update DOM cells
  const cellTotalInr = document.getElementById(`cell-totalinr-${type}-${idx}`);
  if (cellTotalInr) cellTotalInr.textContent = num(totalinr, 4);

  const cellTotalFr = document.getElementById(`cell-totalfr-${type}-${idx}`);
  if (cellTotalFr) cellTotalFr.textContent = num(totalfr, 4);

  recalculateAll();
}

function getRowList(type) {
  if (!costData) return null;
  if (type === 'fabric') return costData.to_Fabric.results;
  if (type === 'trim') return costData.to_Trim.results;
  if (type === 'packing') return costData.to_Packing.results;
  if (type === 'special') return costData.to_Special.results;
  return null;
}

// Render Bottom 4-Column Form Grid
function renderBottomForm() {
  if (!costData) return;

  // Column 1
  setInputValue('form-nmachreq', costData.Nmachreq || 0);
  setInputValue('form-npeicbnd', costData.Npeicbnd || 0);
  setInputValue('form-sam', num(costData.Sam, 2));

  // Column 2
  setInputValue('form-making', num(costData.Making, 3));
  setInputValue('form-overhead', num(costData.Overhead, 3));
  setInputValue('form-zwast', num(costData.Zwast, 3));

  // Column 3
  setInputValue('form-subtot', num(costData.Subtot, 4));
  setInputValue('form-markupp', num(costData.MarkupP, 3));
  setInputValue('form-markup', num(costData.Markup, 2));
  setInputValue('form-commision', num(costData.Commision, 3));
  setInputValue('form-commval', num(costData.CommVal, 2));

  // Column 4
  setInputValue('form-addcost', num(costData.AddCost, 3));
  setInputValue('form-discount', num(costData.Discount, 3));
  setInputValue('form-sellprice', num(costData.Sellprice, 2));

  const cnvRate = parseNum(costData.Cnvf) || 1.0;
  const sellPriceFr = parseNum(costData.Sellprice) / cnvRate;
  setInputValue('form-sellprice-fr', num(sellPriceFr, 2));
  setInputValue('form-quota', num(costData.Quota, 3));

  // Currency label for column 4
  const sellPriceFrLabel = document.getElementById('label-sellprice-fr');
  if (sellPriceFrLabel) {
    sellPriceFrLabel.innerHTML = `QOUC ${costData.Cnv || 'EUR'}<small>QOUC</small>`;
  }
}

function setInputValue(id, val) {
  const el = document.getElementById(id);
  if (el) el.value = val;
}

// Collect input values from header
function collectHeaderInputs() {
  if (!costData) return;
  const vbeln = document.getElementById('hdr-vbeln')?.value.trim();
  const posnr = document.getElementById('hdr-posnr')?.value.trim();
  const matnrEl = document.getElementById('hdr-matnr');
  const maktxEl = document.getElementById('hdr-maktx');
  const zzallEl = document.getElementById('hdr-zzall');
  const kwmeng = parseNum(document.getElementById('hdr-kwmeng')?.value);
  const cnv = document.getElementById('hdr-cnv')?.value.trim() || 'EUR';
  const cnvf = parseNum(document.getElementById('hdr-cnvf')?.value) || 1.0;

  if (vbeln) costData.Vbeln = vbeln;
  if (posnr) costData.Posnr = posnr;
  // Style / Style desc / same-price UI removed — keep SAP values unless fields exist
  if (matnrEl) costData.Matnr = matnrEl.value.trim();
  if (maktxEl) costData.Maktxh = maktxEl.value.trim();
  if (zzallEl) costData.Zzall = zzallEl.checked ? 'X' : '';
  costData.Kwmeng = kwmeng.toFixed(3);
  costData.Cnv = cnv;
  costData.Cnvf = cnvf.toFixed(3);

  // Bottom form fields
  costData.Nmachreq = parseInt(document.getElementById('form-nmachreq')?.value || 0, 10);
  costData.Npeicbnd = parseInt(document.getElementById('form-npeicbnd')?.value || 0, 10);
  costData.Sam = parseNum(document.getElementById('form-sam')?.value).toFixed(2);

  costData.Making = parseNum(document.getElementById('form-making')?.value).toFixed(3);
  costData.Overhead = parseNum(document.getElementById('form-overhead')?.value).toFixed(3);
  costData.Zwast = parseNum(document.getElementById('form-zwast')?.value).toFixed(3);

  costData.MarkupP = parseNum(document.getElementById('form-markupp')?.value).toFixed(3);
  costData.Commision = parseNum(document.getElementById('form-commision')?.value).toFixed(3);
  costData.AddCost = parseNum(document.getElementById('form-addcost')?.value).toFixed(3);
  costData.Discount = parseNum(document.getElementById('form-discount')?.value).toFixed(3);
  costData.Quota = parseNum(document.getElementById('form-quota')?.value).toFixed(3);
}

// Full Recalculation Engine
function recalculateAll() {
  if (!costData) return;

  const cnvf = parseNum(costData.Cnvf) || 1.0;

  // 1. Fabric Category Sums & Sections
  let fabricInr = 0, fabricFr = 0, fabricCostSum = 0, fabricFinSum = 0;
  const fabricSecMap = {};
  (costData.to_Fabric.results || []).forEach(r => {
    const rowInr = parseNum(r.Totalinr);
    fabricInr += rowInr;
    fabricFr += parseNum(r.Totalfr);
    fabricCostSum += parseNum(r.Rate);
    fabricFinSum += parseNum(r.Fincost);

    const sec = normalizeSection(r.Zsection, 'fabric');
    if (!fabricSecMap[sec]) fabricSecMap[sec] = { section: sec, inr: 0, count: 0 };
    fabricSecMap[sec].inr += rowInr;
    fabricSecMap[sec].count++;
  });
  setTableTotals('fabric', fabricCostSum, fabricInr, fabricFr, fabricFinSum);
  updateTableHeaderInfo('fabric', fabricInr, (costData.to_Fabric.results || []).length, fabricSecMap);

  // 2. Trim Category Sums & Sections
  let trimInr = 0, trimFr = 0, trimCostSum = 0, trimFinSum = 0;
  const trimSecMap = {};
  (costData.to_Trim.results || []).forEach(r => {
    const rowInr = parseNum(r.Totalinr);
    trimInr += rowInr;
    trimFr += parseNum(r.Totalfr);
    trimCostSum += parseNum(r.Rate);
    trimFinSum += parseNum(r.Fincost);

    const sec = normalizeSection(r.Zsection, 'trim');
    if (!trimSecMap[sec]) trimSecMap[sec] = { section: sec, inr: 0, count: 0 };
    trimSecMap[sec].inr += rowInr;
    trimSecMap[sec].count++;
  });
  setTableTotals('trim', trimCostSum, trimInr, trimFr, trimFinSum);
  updateTableHeaderInfo('trim', trimInr, (costData.to_Trim.results || []).length, trimSecMap);

  // 3. Packing Category Sums & Sections
  let packingInr = 0, packingFr = 0, packingCostSum = 0, packingFinSum = 0;
  const packingSecMap = {};
  (costData.to_Packing.results || []).forEach(r => {
    const rowInr = parseNum(r.Totalinr);
    packingInr += rowInr;
    packingFr += parseNum(r.Totalfr);
    packingCostSum += parseNum(r.Rate);
    packingFinSum += parseNum(r.Fincost);

    const sec = normalizeSection(r.Zsection, 'packing');
    if (!packingSecMap[sec]) packingSecMap[sec] = { section: sec, inr: 0, count: 0 };
    packingSecMap[sec].inr += rowInr;
    packingSecMap[sec].count++;
  });
  setTableTotals('packing', packingCostSum, packingInr, packingFr, packingFinSum);
  updateTableHeaderInfo('packing', packingInr, (costData.to_Packing.results || []).length, packingSecMap);

  // 4. Special / Others Category Sums & Sections
  let specialInr = 0, specialFr = 0, specialCostSum = 0, specialFinSum = 0;
  const specialSecMap = {};
  (costData.to_Special.results || []).forEach(r => {
    const rowInr = parseNum(r.Totalinr);
    specialInr += rowInr;
    specialFr += parseNum(r.Totalfr);
    specialCostSum += parseNum(r.Rate);
    specialFinSum += parseNum(r.Fincost);

    const sec = normalizeSection(r.Zsection, 'special');
    if (!specialSecMap[sec]) specialSecMap[sec] = { section: sec, inr: 0, count: 0 };
    specialSecMap[sec].inr += rowInr;
    specialSecMap[sec].count++;
  });
  setTableTotals('special', specialCostSum, specialInr, specialFr, specialFinSum);
  updateTableHeaderInfo('special', specialInr, (costData.to_Special.results || []).length, specialSecMap);

  // -------------------------------------------------------------
  // SAP ABAP Costing Sheet Calculation Engine
  // SUBTOT = TINR1 + TINR + TINR2 + WA_ZCOST_S-TOTALINR + HD_ZCOST-MAKING + HD_ZCOST-OVERHEAD
  //          + ( ( TINR1 + TINR + TINR2 + WA_ZCOST_S-TOTALINR ) * HD_ZCOST-ZWAST * PER ).
  // -------------------------------------------------------------
  const materialsTotal = fabricInr + trimInr + packingInr + specialInr;
  const making = parseNum(costData.Making);
  const overhead = parseNum(costData.Overhead);
  const zwast = parseNum(costData.Zwast);
  const garmentWastage = materialsTotal * (zwast / 100);

  const calculatedSubtot = materialsTotal + making + overhead + garmentWastage;
  costData.Subtot = calculatedSubtot.toFixed(4);
  setInputValue('form-subtot', num(calculatedSubtot, 4));

  // HD_ZCOST-MARKUP = ( SUBTOT / ( 100 - HD_ZCOST-MARKUP_P ) ) * HD_ZCOST-MARKUP_P.
  const markupP = parseNum(costData.MarkupP);
  const markupVal = (markupP > 0 && markupP < 100) 
    ? (calculatedSubtot / (100 - markupP)) * markupP 
    : 0;
  costData.Markup = markupVal.toFixed(2);
  setInputValue('form-markup', num(markupVal, 2));

  // HD_ZCOST-SELLPRICE = SUBTOT + HD_ZCOST-MARKUP.
  const intermediateSellPrice = calculatedSubtot + markupVal;

  // HD_ZCOST-COMM_VAL = ( HD_ZCOST-SELLPRICE / ( 100 - HD_ZCOST-COMMISION ) ) * HD_ZCOST-COMMISION.
  const commP = parseNum(costData.Commision);
  const commVal = (commP > 0 && commP < 100) 
    ? (intermediateSellPrice / (100 - commP)) * commP 
    : 0;
  costData.CommVal = commVal.toFixed(2);
  setInputValue('form-commval', num(commVal, 2));

  // HD_ZCOST-SELLPRICE = SUBTOT + HD_ZCOST-MARKUP + HD_ZCOST-COMM_VAL.
  let sellingPrice = calculatedSubtot + markupVal + commVal;

  // LV_DIS = ( HD_ZCOST-SELLPRICE * HD_ZCOST-DISCOUNT ) / 100.
  const addCost = parseNum(costData.AddCost);
  const discountP = parseNum(costData.Discount);
  const lvDis = (sellingPrice * discountP) / 100;

  // HD_ZCOST-SELLPRICE = HD_ZCOST-SELLPRICE + HD_ZCOST-ADD_COST - LV_DIS.
  sellingPrice = sellingPrice + addCost - lvDis;
  if (sellingPrice < 0) sellingPrice = 0;

  // Preserve existing Sellprice if entire sheet is blank but existing cost header is loaded
  if (calculatedSubtot === 0 && parseNum(costData.Sellprice) > 0) {
    sellingPrice = parseNum(costData.Sellprice);
  }

  costData.Sellprice = sellingPrice.toFixed(3);
  setInputValue('form-sellprice', num(sellingPrice, 2));

  // QOUC = HD_ZCOST-SELLPRICE / CNVF.
  const qouc = cnvf > 0 ? (sellingPrice / cnvf) : 0;
  setInputValue('form-sellprice-fr', num(qouc, 2));

  // If Quota is empty, initialize it to QOUC
  if (!costData.Quota || parseNum(costData.Quota) === 0) {
    costData.Quota = qouc.toFixed(3);
    setInputValue('form-quota', num(qouc, 3));
  }

  paintKpi('kpi-sellprice', '₹ ' + num(sellingPrice, 2));
  paintKpi('kpi-sellprice-fr', (costData.Cnv || 'EUR') + ' ' + num(qouc, 2));
  paintKpi('kpi-quota-val', 'Quote: ' + num(costData.Quota || 0, 3));
  paintKpi('kpi-subtot', '₹ ' + num(calculatedSubtot, 2));
  paintKpi('kpi-fabric-tot', '₹ ' + num(fabricInr, 2));
  paintKpi('kpi-trim-tot', '₹ ' + num(trimInr, 2));
  paintKpi('kpi-packing-tot', '₹ ' + num(packingInr, 2));
  paintKpi('kpi-ops-tot', '₹ ' + num(making + overhead, 2));
  paintKpi('kpi-margin-tot', num(markupP, 1) + '% / ' + num(commP, 1) + '%');
  paintKpi('kpi-sam-tot', num(costData.Sam || 0, 2) + ' min');
  paintKpi('kpi-mach-tot', String(costData.Nmachreq || 0));
  paintKpi('kpi-pcs-tot', String(costData.Npeicbnd || 0));

  // Update tab badge counts
  const countFabric = document.getElementById('count-fabric');
  if (countFabric) countFabric.textContent = (costData.to_Fabric.results || []).length;

  const countTrim = document.getElementById('count-trim');
  if (countTrim) countTrim.textContent = (costData.to_Trim.results || []).length;

  const countPacking = document.getElementById('count-packing');
  if (countPacking) countPacking.textContent = (costData.to_Packing.results || []).length;

  const countSpecial = document.getElementById('count-special');
  if (countSpecial) countSpecial.textContent = (costData.to_Special.results || []).length;
}

// Active section filters for all tables
const activeSectionFilters = {
  fabric: 'ALL',
  trim: 'ALL',
  packing: 'ALL',
  special: 'ALL'
};

/**
 * Filter any table by clicked section chip.
 * @param {string} type - 'fabric' | 'trim' | 'packing' | 'special'
 * @param {string} secName - Section name or 'ALL'
 */
function filterTableSection(type, secName) {
  if ((activeSectionFilters[type] || 'ALL').toLowerCase() === (secName || '').toLowerCase()) {
    activeSectionFilters[type] = 'ALL';
  } else {
    activeSectionFilters[type] = secName;
  }
  applyTableSectionFilter(type);
}
window.filterTableSection = filterTableSection;

// Backwards compatibility alias for trim
function filterTrimSection(secName) {
  filterTableSection('trim', secName);
}
window.filterTrimSection = filterTrimSection;

/**
 * Updates table header total, line count, and section-wise breakdown strip.
 */
function updateTableHeaderInfo(type, totalInr, count, sectionMap) {
  const elTot = document.getElementById(`head-tot-${type}`);
  const elCnt = document.getElementById(`head-cnt-${type}`);
  const strip = document.getElementById(`strip-sections-${type}`);
  if (!strip) return;

  const sections = Object.values(sectionMap || {});
  // If 0 sections or 1 section identical to type, hide strip and show clean inline badges
  if (sections.length <= 1 && (!sections[0] || sections[0].section.toLowerCase() === type.toLowerCase())) {
    strip.style.display = 'none';
    strip.innerHTML = '';
    if (elTot) {
      elTot.style.display = 'inline-flex';
      elTot.textContent = '₹ ' + num(totalInr, 2);
    }
    if (elCnt) {
      elCnt.style.display = 'inline-flex';
      elCnt.textContent = count + ' lines';
    }
    applyTableSectionFilter(type);
    return;
  }

  // Multi-section breakdown: hide redundant single-value pills so single row shows the section cards
  if (elTot) elTot.style.display = 'none';
  if (elCnt) elCnt.style.display = 'none';
  strip.style.display = 'flex';

  const priority = ['Label', 'Tags', 'Packing', 'Others', 'Threads', 'Lining', 'Button', 'Zips', 'Washing', 'Embroidery'];
  sections.sort((a, b) => {
    let pa = priority.findIndex(p => p.toLowerCase() === a.section.toLowerCase());
    let pb = priority.findIndex(p => p.toLowerCase() === b.section.toLowerCase());
    if (pa === -1) pa = 999;
    if (pb === -1) pb = 999;
    if (pa !== pb) return pa - pb;
    return b.inr - a.inr;
  });

  const getDotColor = (secName) => {
    const s = secName.toLowerCase();
    if (s.includes('label')) return '#2563eb';
    if (s.includes('tag')) return '#d97706';
    if (s.includes('pack')) return '#059669';
    if (s.includes('other')) return '#7c3aed';
    if (s.includes('thread')) return '#0d9488';
    if (s.includes('lin')) return '#db2777';
    if (s.includes('wash')) return '#0284c7';
    if (s.includes('emb')) return '#9333ea';
    return '#64748b';
  };

  const currentFilter = activeSectionFilters[type] || 'ALL';
  const isAllActive = currentFilter === 'ALL';

  let html = `
    <button type="button" class="sec-chip-btn ${isAllActive ? 'active' : ''}" 
            data-sec="ALL" onclick="filterTableSection('${type}', 'ALL')" title="Show all ${count} lines">
      <span class="sec-chip-dot" style="background:#64748b;"></span>
      <span class="sec-chip-name">All</span>
      <span class="sec-chip-count">(${count})</span>
      <span class="sec-chip-amt">₹ ${num(totalInr, 2)}</span>
    </button>
  `;

  html += sections.map(sec => {
    const isActive = currentFilter.toLowerCase() === sec.section.toLowerCase();
    const dotColor = getDotColor(sec.section);
    return `
      <button type="button" class="sec-chip-btn ${isActive ? 'active' : ''}" 
              data-sec="${escapeHtml(sec.section)}" 
              onclick="filterTableSection('${type}', '${escapeHtml(sec.section)}')" 
              title="Filter by ${escapeHtml(sec.section)} (${sec.count} items · ₹ ${num(sec.inr, 2)})">
        <span class="sec-chip-dot" style="background:${dotColor};"></span>
        <span class="sec-chip-name">${escapeHtml(sec.section)}</span>
        <span class="sec-chip-count">(${sec.count})</span>
        <span class="sec-chip-amt">₹ ${num(sec.inr, 2)}</span>
      </button>
    `;
  }).join('');

  strip.innerHTML = html;
  applyTableSectionFilter(type);
}

/**
 * Filter visibility of rows in table-{type} based on activeSectionFilters[type].
 */
function applyTableSectionFilter(type) {
  const table = document.getElementById(`table-${type}`);
  if (!table) return;

  const currentFilter = activeSectionFilters[type] || 'ALL';
  const rows = table.querySelectorAll('tbody tr');
  let visibleCount = 0;
  let visibleInr = 0;

  rows.forEach(tr => {
    const idx = parseInt(tr.getAttribute('data-idx'), 10);
    const rowList = getRowList(type);
    const rowData = rowList?.[idx];
    if (!rowData) return;

    const sec = normalizeSection(rowData.Zsection, type);
    const isVisible = (!currentFilter || currentFilter === 'ALL' || sec.toLowerCase() === currentFilter.toLowerCase());

    if (isVisible) {
      tr.style.display = '';
      visibleCount++;
      visibleInr += parseNum(rowData.Totalinr);
    } else {
      tr.style.display = 'none';
    }
  });

  // Sync active states on buttons
  const strip = document.getElementById(`strip-sections-${type}`);
  if (strip) {
    strip.querySelectorAll('.sec-chip-btn').forEach(btn => {
      const dataSec = btn.getAttribute('data-sec') || '';
      if (dataSec.toLowerCase() === currentFilter.toLowerCase()) {
        btn.classList.add('active');
      } else {
        btn.classList.remove('active');
      }
    });
  }

  // Update table summary footer
  const elTotInr = document.getElementById(`tot-inr-${type}`);
  if (elTotInr) {
    if (currentFilter && currentFilter !== 'ALL') {
      elTotInr.textContent = num(visibleInr, 4);
    } else {
      const rowList = getRowList(type) || [];
      const fullTotal = rowList.reduce((s, r) => s + parseNum(r.Totalinr), 0);
      elTotInr.textContent = num(fullTotal, 4);
    }
  }

  // Update Top Card View (Total Cards) to reflect this place
  if (type === 'trim') {
    const elKpiTrim = document.getElementById('kpi-trim-tot');
    const elBadgeTrim = document.getElementById('badge-trim-count');
    if (elKpiTrim) {
      if (currentFilter && currentFilter !== 'ALL') {
        elKpiTrim.textContent = '₹ ' + num(visibleInr, 2);
        if (elBadgeTrim) elBadgeTrim.textContent = `${visibleCount} (${currentFilter})`;
      } else {
        const rowList = getRowList('trim') || [];
        const fullTot = rowList.reduce((s, r) => s + parseNum(r.Totalinr), 0);
        elKpiTrim.textContent = '₹ ' + num(fullTot, 2);
        if (elBadgeTrim) elBadgeTrim.textContent = `${rowList.length}`;
      }
    }
  } else if (type === 'packing') {
    const elKpiPack = document.getElementById('kpi-packing-tot');
    const elBadgePack = document.getElementById('badge-packing-count');
    if (elKpiPack) {
      if (currentFilter && currentFilter !== 'ALL') {
        elKpiPack.textContent = '₹ ' + num(visibleInr, 2);
        if (elBadgePack) elBadgePack.textContent = `${visibleCount} (${currentFilter})`;
      } else {
        const rowList = getRowList('packing') || [];
        const fullTot = rowList.reduce((s, r) => s + parseNum(r.Totalinr), 0);
        elKpiPack.textContent = '₹ ' + num(fullTot, 2);
        if (elBadgePack) elBadgePack.textContent = `${rowList.length}`;
      }
    }
  } else if (type === 'fabric') {
    const elKpiFab = document.getElementById('kpi-fabric-tot');
    const elBadgeFab = document.getElementById('badge-fabric-count');
    if (elKpiFab) {
      if (currentFilter && currentFilter !== 'ALL') {
        elKpiFab.textContent = '₹ ' + num(visibleInr, 2);
        if (elBadgeFab) elBadgeFab.textContent = `${visibleCount} (${currentFilter})`;
      } else {
        const rowList = getRowList('fabric') || [];
        const fullTot = rowList.reduce((s, r) => s + parseNum(r.Totalinr), 0);
        elKpiFab.textContent = '₹ ' + num(fullTot, 2);
        if (elBadgeFab) elBadgeFab.textContent = `${rowList.length}`;
      }
    }
  }
}

function setTableTotals(type, costSum, inrSum, frSum, finSum) {
  const elCost = document.getElementById(`tot-cost-${type}`);
  if (elCost) elCost.textContent = num(costSum, 4);

  const elInr = document.getElementById(`tot-inr-${type}`);
  if (elInr) elInr.textContent = num(inrSum, 4);

  const elFr = document.getElementById(`tot-fr-${type}`);
  if (elFr) elFr.textContent = num(frSum, 4);

  const elFin = document.getElementById(`tot-fin-${type}`);
  if (elFin) elFin.textContent = num(finSum, 2);
}

/**
 * Approves the costing sheet: sends Approve='X' and Approvedby=loginuserid to SAP.
 */
async function approveCostSheet() {
  if (!costData) return;
  if (!canApproveSheet()) {
    showToast('Approve allowed only for authorized approvers.', true);
    applyUiLockState();
    return;
  }

  const loginUserId = getLoginUserId();
  costData.Approve = 'X';
  costData.Approvedby = loginUserId;
  isEditMode = false; // lock to view-only on approve

  renderHeader();
  applyUiLockState();
  pulseStatusBadge();

  updateStatus(`Approving Costing Sheet ${costData.Vbeln} by ${loginUserId}...`, 'info');
  showToast(`Approving Quotation ${costData.Vbeln} by ${loginUserId}...`);

  await saveCostSheet({ force: true, action: 'approve' });
}
window.approveCostSheet = approveCostSheet;

/**
 * Toggles the approval status of the sheet.
 * @returns {void}
 */
function toggleApprove() {
  if (!costData) return;
  if (!canApproveSheet()) {
    showToast('Approve allowed only for authorized approvers.', true);
    applyUiLockState();
    return;
  }
  const currentApprove = costData.Approve;
  if (currentApprove === 'X' || currentApprove === 'x') {
    costData.Approve = '';
    costData.Approvedby = '';
    showToast('Status changed to NOT APPROVED');
  } else {
    const by = getLoginUserId();
    costData.Approve = 'X';
    costData.Approvedby = by;
    isEditMode = false; // force view-only on approve
    showToast('Status changed to APPROVED by ' + by);
  }
  renderHeader();
  applyUiLockState(); // immediately lock Delete / hide Print
  pulseStatusBadge();
  saveCostSheet({ force: true, action: (costData.Approve === 'X' ? 'approve' : 'save') });
}

/**
 * Toggles the edit/change mode of the sheet.
 * @returns {void}
 */
function toggleChangeMode() {
  if (!costData) return;
  if (isSheetApproved()) {
    updateStatus('Cannot edit: sheet is APPROVED (view only).', 'warn');
    showToast('Approved sheet is view only.', true);
    applyUiLockState();
    return;
  }

  isEditMode = !isEditMode;
  applyUiLockState();

  updateStatus(isEditMode ? 'Changed to EDIT mode. Inputs are now editable.' : 'Changed to DISPLAY mode.', 'info');
  showToast(isEditMode ? 'Edit Mode Activated' : 'Display Mode Active');
}

/**
 * Ensures a blank sheet is loaded.
 * @returns {void}
 */
function ensureBlankSheet() {
  if (costData) {
    ['to_Fabric', 'to_Trim', 'to_Packing', 'to_Special'].forEach(key => {
      if (!costData[key]) costData[key] = { results: [] };
      if (!Array.isArray(costData[key].results)) costData[key].results = [];
    });
    return;
  }
  costData = {
    Vbeln: document.getElementById('hdr-vbeln')?.value.trim() || '',
    Posnr: document.getElementById('hdr-posnr')?.value.trim() || '000010',
    Matnr: document.getElementById('hdr-matnr')?.value.trim() || '',
    Maktxh: document.getElementById('hdr-maktx')?.value.trim() || '',
    Kwmeng: document.getElementById('hdr-kwmeng')?.value || '0.000',
    Cnv: document.getElementById('hdr-cnv')?.value.trim() || 'EUR',
    Cnvf: document.getElementById('hdr-cnvf')?.value || '90.00',
    Approve: '',
    Sellprice: '0',
    Quota: '0',
    to_Fabric: { results: [] },
    to_Trim: { results: [] },
    to_Packing: { results: [] },
    to_Special: { results: [] }
  };
}

function paintKpi(id, text) {
  const el = document.getElementById(id);
  if (!el || el.textContent === text) return;
  el.textContent = text;
  el.classList.remove('kpi-pop');
  void el.offsetWidth;
  el.classList.add('kpi-pop');
}

// Add an empty line the user can fill in
function addRow(type) {
  ensureBlankSheet();
  if (isSheetApproved()) {
    showToast('Approved sheet is view only.', true);
    return;
  }
  if (!isEditMode) toggleChangeMode();
  const list = getRowList(type);
  if (!list) return;

  const newRow = {
    Vbeln: costData.Vbeln || '',
    Posnr: costData.Posnr || '000010',
    Zline: String(list.length + 1).padStart(6, '0'),
    Item: '',
    Maktx: '',
    WidSiz: '',
    Net: '0',
    Wste: '0',
    Gross: '0',
    Curunit: 'INR',
    Rate: '0',
    Totalinr: '0',
    Totalfr: '0',
    Totalper: '0',
    Convr: '1',
    Fright: '0',
    Purp: '0',
    Duty: '0',
    Fincost: '0',
    Slno: list.length + 1,
    Zsection: getDefaultSection(type),
    Zdeli: '',
    Uname: '',
    Udate: null,
    Utime: 'PT00H00M00S',
    _isNew: true
  };

  list.push(newRow);
  renderTable(type, list);
  delete newRow._isNew;
  recalculateAll();

  const badge = document.getElementById(`badge-${type}-count`);
  if (badge) badge.textContent = String(list.length);
  const allBadge = document.getElementById('badge-all-count');
  if (allBadge && costData) {
    const n = (costData.to_Fabric?.results?.length || 0)
      + (costData.to_Trim?.results?.length || 0)
      + (costData.to_Packing?.results?.length || 0)
      + (costData.to_Special?.results?.length || 0);
    allBadge.textContent = String(n);
  }

  const entered = document.querySelector(`#tbody-${type} tr.row-enter`);
  if (entered) {
    entered.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    const focusEl = entered.querySelector('input, select');
    if (focusEl) focusEl.focus();
  }
  showToast(`Empty ${type} row added`);
}

// Delete Row from Table
function deleteRow(type) {
  if (!costData) return;
  if (isSheetApproved() || !isEditMode) {
    showToast(isSheetApproved()
      ? 'Approved sheet is view only.'
      : 'Switch to Change mode to delete rows.', true);
    return;
  }
  const list = getRowList(type);
  if (!list || list.length === 0) return;

  const finishDelete = () => {
    list.pop();
    renderTable(type, list);
    recalculateAll();
    const badge = document.getElementById(`badge-${type}-count`);
    if (badge) badge.textContent = String(list.length);
    showToast(`Removed last row from ${type.toUpperCase()}`);
  };

  const tbody = document.getElementById(`tbody-${type}`);
  const lastRow = tbody ? tbody.querySelector('tr:last-child') : null;
  if (lastRow && !prefersReducedMotion() && !lastRow.querySelector('td[colspan]')) {
    lastRow.classList.add('row-leave');
    setTimeout(finishDelete, 320);
    return;
  }
  finishDelete();
}

// Export Table to CSV
function exportTableToCSV(type) {
  if (!costData) return;
  const list = getRowList(type);
  if (!list || list.length === 0) {
    showToast('No data to export', true);
    return;
  }

  const headers = ['SL', 'Section', 'ITEM', 'MAKTX', 'WID_SIZ', 'NET', 'WSTE', 'GROSS', 'CURUNIT', 'CONVR', 'PURP', 'FRIGHT', 'DUTY', 'RATE', 'TOTALINR', 'TOTALFR', 'FINCOST'];
  const rows = list.map((r, i) => [
    r.Slno || (i + 1),
    `"${(r.Zsection || '').replace(/"/g, '""')}"`,
    `"${(r.Item || '').replace(/"/g, '""')}"`,
    `"${(r.Maktx || '').replace(/"/g, '""')}"`,
    r.WidSiz || '',
    r.Net || 0,
    r.Wste || 0,
    r.Gross || 0,
    r.Curunit || '',
    r.Convr || 1,
    r.Purp || 0,
    r.Fright || 0,
    r.Duty || 0,
    r.Rate || 0,
    r.Totalinr || 0,
    r.Totalfr || 0,
    r.Fincost || 0
  ]);

  const csvContent = 'data:text/csv;charset=utf-8,' + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
  const encodedUri = encodeURI(csvContent);
  const link = document.createElement('a');
  link.setAttribute('href', encodedUri);
  link.setAttribute('download', `ZCOST_${costData.Vbeln}_${type}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  showToast(`Exported ${type.toUpperCase()} to CSV`);
}

// Command Box Handler
function handleCommand(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    executeCommand();
  }
}

function executeCommand() {
  const cmd = document.getElementById('sap-cmd-input')?.value.trim();
  if (!cmd) return;

  if (cmd.startsWith('/n') || cmd.startsWith('/N')) {
    const sub = cmd.substring(2).trim();
    if (sub.toUpperCase() === 'ZCOST') {
      fetchCostSheet(currentVbeln, currentPosnr);
      return;
    }
  }

  // If user entered a number, query that Sales Order
  if (isNumeric(cmd)) {
    fetchCostSheet(cmd, '');
  } else {
    showToast(`Command ${cmd} not recognized. Querying SO...`);
    fetchCostSheet(currentVbeln, currentPosnr);
  }
}

function isNumeric(val) {
  return /^\d+$/.test(val);
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// EVOLV Landing Search Controller (Matching Image 2)
function showLandingSearch() {
  const landing = document.getElementById('evolv-landing-page');
  const mainWin = document.getElementById('sap-window');
  const searchInput = document.getElementById('landing-search-input');
  const errorBanner = document.getElementById('landing-error-banner');

  if (mainWin) mainWin.style.display = 'none';
  if (landing) landing.classList.remove('hidden');
  if (errorBanner) {
    errorBanner.classList.remove('active');
    errorBanner.textContent = '';
  }
  if (searchInput) {
    if (costData && costData.Vbeln) {
      searchInput.value = costData.Vbeln;
    }
    setTimeout(() => searchInput.focus(), 100);
  }
}

// Aliases for toolbar buttons
function openSearchModal() {
  showLandingSearch();
}

function closeSearchModal() {
  const landing = document.getElementById('evolv-landing-page');
  const mainWin = document.getElementById('sap-window');
  if (costData && landing && mainWin) {
    landing.classList.add('hidden');
    mainWin.style.display = 'flex';
  }
}

function quickSelectQuotation(vbeln) {
  const cleanVbeln = (vbeln || '').trim();
  if (!cleanVbeln) return;
  hideInitialScreen();
  fetchCostSheet(cleanVbeln, '000010');
}

function selectPresetQuotation(vbeln) {
  quickSelectQuotation(vbeln);
}

// Show "Quotation Master Not Found" Confirmation Modal (Yes / No)
function showNotFoundModal(vbeln, posnr) {
  const modal = document.getElementById('modal-not-found');
  const vbelnEl = document.getElementById('modal-nf-vbeln');
  const posnrEl = document.getElementById('modal-nf-posnr');

  if (vbelnEl) vbelnEl.textContent = vbeln;
  if (posnrEl) posnrEl.textContent = posnr;
  if (modal) modal.hidden = false;
}

function hideNotFoundModal() {
  const modal = document.getElementById('modal-not-found');
  if (modal) modal.hidden = true;
}

// User clicked "Yes, Create Master"
async function handleConfirmCreateMaster() {
  hideNotFoundModal();
  if (!pendingNotFoundQuotation) return;

  const { vbeln, posnr } = pendingNotFoundQuotation;
  showLoader('Creating new quotation master...');

  try {
    const res = await fetch(_zcostApiUrl(`vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}&action=create`));
    const json = await res.json();
    if (json.success && json.data) {
      costData = json.data;
    } else {
      createBlankQuotationMaster(vbeln, posnr);
    }
  } catch (err) {
    createBlankQuotationMaster(vbeln, posnr);
  } finally {
    await hideLoader();
  }

  // Auto enable edit mode so user can immediately add rows
  isEditMode = true;

  const landing = document.getElementById('evolv-landing-page');
  const mainWin = document.getElementById('sap-window');
  if (landing) landing.classList.add('hidden');
  if (mainWin) mainWin.style.display = 'flex';

  // Keep nav search bar in sync
  const navSearch = document.getElementById('nav-search-input');
  if (navSearch) navSearch.value = vbeln;

  renderAll();
  applyUiLockState();
    updateStatus(`New Quotation Master created for ${vbeln} [Item ${posnr}]`, 'info');
  showToast(`Created new quotation master for ${vbeln}`);

  // Update browser URL
  try {
    const newUrl = `${window.location.pathname}?vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`;
    window.history.pushState({ vbeln, posnr }, '', newUrl);
  } catch (e) {}

  pendingNotFoundQuotation = null;
}

/**
 * Handles the cancellation of the search again.
 * @returns {void}
 */
function handleCancelSearchAgain() {
  hideNotFoundModal();
  pendingNotFoundQuotation = null;

  // Make sure Landing Search Page is visible and costing sheet is hidden
  showInitialScreen();

  // Clear search input and focus
  const searchInput = document.getElementById('landing-search-input');
  if (searchInput) {
    searchInput.value = '';
    setTimeout(() => searchInput.focus(), 150);
  }

  // Clear URL query parameters
  try {
    window.history.pushState({}, '', window.location.pathname);
  } catch (e) {}

  showToast('Search again. Please enter a quotation number.');
}

/**
 * Creates a blank quotation master template.
 * @param {string} vbeln - The sales order number.
 * @param {string} posnr - The item number.
 * @returns {void}
 */
function createBlankQuotationMaster(vbeln, posnr = '000010') {
  costData = {
    _is_user_created: true,
    Vbeln: vbeln,
    Posnr: posnr,
    Matnr: '',
    Maktxh: '',
    Kwmeng: '0.000',
    Approve: '',
    Zzall: 'X',
    Cnv: 'USD',
    Cnvf: '87.000',
    Nmachreq: 0,
    Npeicbnd: 0,
    Sam: '0.00',
    Making: '0.000',
    Overhead: '0.000',
    Zwast: '3.000',
    Subtot: '0.0000',
    MarkupP: '20.000',
    Markup: '0.00',
    Commision: '0.000',
    CommVal: '0.00',
    AddCost: '0.000',
    Discount: '0.000',
    Sellprice: '0.000',
    Quota: '0.000',
    to_Fabric: { results: [] },
    to_Trim: { results: [] },
    to_Packing: { results: [] },
    to_Special: { results: [] }
  };
  currentVbeln = vbeln;
  currentPosnr = posnr;
}

/**
 * Handles the submission of the landing search.
 * @returns {void}
 */
async function handleLandingSearchSubmit() {
  const searchInput = document.getElementById('landing-search-input');
  const submitBtn = document.getElementById('landing-submit-btn');
  const errorBanner = document.getElementById('landing-error-banner');

  const vbeln = (searchInput?.value || '').trim();
  const posnr = '000010';

  if (!vbeln) {
    if (errorBanner) {
      errorBanner.textContent = 'Please enter a quotation number.';
      errorBanner.classList.add('active');
    }
    searchInput?.focus();
    return;
  }

  if (errorBanner) {
    errorBanner.classList.remove('active');
    errorBanner.textContent = '';
  }
  if (submitBtn) {
    submitBtn.classList.add('loading');
    submitBtn.disabled = true;
  }

  // Attempt fetch from backend
  const success = await fetchCostSheet(vbeln, posnr);

  if (submitBtn) {
    submitBtn.classList.remove('loading');
    submitBtn.disabled = false;
  }

  if (success) {
    const landing = document.getElementById('evolv-landing-page');
    const mainWin = document.getElementById('sap-window');
    if (landing) landing.classList.add('hidden');
    if (mainWin) mainWin.style.display = 'flex';

    // Keep nav search bar in sync
    const navSearch = document.getElementById('nav-search-input');
    if (navSearch) navSearch.value = vbeln;

    // Update URL query parameters cleanly without reload
    try {
      const newUrl = `${window.location.pathname}?vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`;
      window.history.pushState({ vbeln, posnr }, '', newUrl);
    } catch (e) {}
  }
}



/**
 * Switches the studio tab.
 * @param {string} tabId - The ID of the tab to switch to.
 * @returns {void}
 */
function switchStudioTab(tabId) {
  // Update active tab buttons
  document.querySelectorAll('.stage-nav-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.tab === tabId);
  });

  const sections = document.querySelectorAll('.studio-content-section');
  if (tabId === 'all') {
    sections.forEach(sec => {
      sec.style.display = 'block';
    });
  } else {
    sections.forEach(sec => {
      if (sec.id === `section-${tabId}`) {
        sec.style.display = 'block';
        sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        sec.style.display = 'none';
      }
    });
  }
}

/**
 * Toggles the user dropdown.
 * @param {Event} e - The event object.
 * @returns {void}
 */
function toggleUserDropdown(e) {
  if (e) {
    e.preventDefault();
    e.stopPropagation();
  }
  // Toggle in studio or landing header
  const target = e?.currentTarget;
  const container = target ? target.closest('.evolv-user-menu-container') : null;
  const dd = container ? container.querySelector('.evolv-user-dropdown') : (document.getElementById('evolv-user-dropdown-studio') || document.getElementById('evolv-user-dropdown'));
  if (dd) {
    dd.classList.toggle('show');
  }
}

// Close user dropdown when clicking anywhere outside
document.addEventListener('click', (e) => {
  document.querySelectorAll('.evolv-user-dropdown.show').forEach(dd => {
    const container = dd.closest('.evolv-user-menu-container');
    if (!container || !container.contains(e.target)) {
      dd.classList.remove('show');
    }
  });
});

const APPROVE_USER_IDS = [1, 21, 26, 27, 37, 49, 1048, 2106, 42, 2147, 23];
const APPROVE_USERNAMES = [
  'admin',
  'it_admin',
  'suparna',
  'pushpa',
  'saravanan',
  'nandhakumar',
  'saravanan_6870',
];
let portalUser = { logged_in: false, is_admin: false, can_approve: false };

function zcostHas(flag) {
  if (portalUser?.is_admin === true) return true;
  if (portalUser?.permissions && typeof portalUser.permissions[flag] === 'boolean') {
    return portalUser.permissions[flag];
  }
  const key = 'can_' + flag;
  if (typeof portalUser?.[key] === 'boolean') {
    return portalUser[key];
  }
  return null;
}

function applyZcostActionCards() {
  const map = {
    'btn-init-new': 'add',
    'btn-init-copy': 'copy',
    'btn-init-change': 'edit',
    'btn-init-approve': 'approve',
  };
  Object.keys(map).forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    const ok = zcostHas(map[id]) !== false;
    el.hidden = !ok;
    el.disabled = !ok;
    el.style.display = ok ? '' : 'none';
  });
}

function canApproveSheet() {
  if (portalUser?.can_approve === true || portalUser?.is_admin === true) {
    return true;
  }
  const role = String(portalUser?.role || '').toLowerCase();
  const username = String(portalUser?.username || '').toLowerCase();
  if (role === 'admin' || username === 'admin') {
    return true;
  }
  if (portalUser && typeof portalUser.can_approve === 'boolean') {
    return !!portalUser.can_approve;
  }
  const uid = Number(portalUser?.user_id || portalUser?.userid || portalUser?.id || 0);
  if (uid > 0 && APPROVE_USER_IDS.includes(uid)) {
    return true;
  }
  if (username && APPROVE_USERNAMES.includes(username)) {
    return true;
  }

  // Fallback: check DOM elements in navbar
  const domRole = (document.querySelector('.evolv-user-role')?.textContent || '').toLowerCase();
  const domUser = (document.querySelector('.evolv-user-name')?.textContent || '').toLowerCase();
  if (domRole.includes('admin') || domUser === 'admin' || APPROVE_USERNAMES.includes(domUser)) {
    return true;
  }
  return false;
}

function getLoginUserId() {
  const fromUser = portalUser?.loginuserid || portalUser?.user_id || portalUser?.username;
  if (fromUser && String(fromUser).trim() !== '' && String(fromUser).toLowerCase() !== 'guest') {
    return String(fromUser).trim().toUpperCase();
  }
  const domUser = document.querySelector('.evolv-user-name')?.textContent?.trim();
  if (domUser && domUser !== '' && domUser.toLowerCase() !== 'guest') {
    return domUser.toUpperCase();
  }
  return 'ADMIN';
}
window.getLoginUserId = getLoginUserId;

function applyPortalSession(user) {
  portalUser = user || { logged_in: false, is_admin: false, can_approve: false };
  if (typeof portalUser.can_approve !== 'boolean') {
    portalUser.can_approve = canApproveSheet();
  }
  const isAdmin = !!portalUser.is_admin;

  document.querySelectorAll('.admin-audit-link').forEach((el) => {
    el.hidden = !isAdmin;
  });

  const panel = document.getElementById('audit-panel');
  if (panel && !isAdmin) {
    panel.hidden = true;
  }

  const display = portalUser.display_name || portalUser.username || 'Admin';
  const role = String(portalUser.role || 'ADMIN').toUpperCase();
  const email = portalUser.email || 'admin@evolvclothing.com';

  document.querySelectorAll('.evolv-user-name').forEach((el) => {
    el.textContent = display;
  });
  document.querySelectorAll('.evolv-user-role').forEach((el) => {
    el.textContent = role + ' ▾';
  });
  document.querySelectorAll('.evolv-dropdown-user-name').forEach((el) => {
    el.textContent = display;
  });
  document.querySelectorAll('.evolv-dropdown-user-email').forEach((el) => {
    el.textContent = email;
  });

  // Re-apply Approve button visibility after session loads
  if (typeof renderHeader === 'function') {
    try { renderHeader(); } catch (e) {}
  }
  applyZcostActionCards();
  applyUiLockState();
}

async function syncPortalSession() {
  try {
    const res = await fetch(_zcostApiUrl('action=session'));
    const json = await res.json();
    if (json.success && json.user) {
      applyPortalSession(json.user);
      return json.user;
    }
  } catch (err) {
    // Keep guest UI — Audit Log stays hidden.
  }
  applyPortalSession({ logged_in: false, is_admin: false });
  return null;
}

function toggleAuditPanel(forceOpen) {
  if (!portalUser.is_admin) {
    showToast('Audit Log is available only for live admin logins.', true);
    return;
  }
  const panel = document.getElementById('audit-panel');
  const btn = document.getElementById('btn-audit-toggle');
  if (!panel) return;
  const open = typeof forceOpen === 'boolean' ? forceOpen : panel.hidden;
  panel.hidden = !open;
  if (btn) btn.classList.toggle('active', open);
  if (open) {
    panel.classList.remove('audit-panel-in');
    void panel.offsetWidth;
    panel.classList.add('audit-panel-in');
    loadAuditLog();
    panel.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'nearest' });
  }
}

function formatAuditWhen(ts) {
  if (!ts) return '—';
  const d = new Date(ts);
  if (Number.isNaN(d.getTime())) return String(ts);
  return d.toLocaleString();
}

function auditStatusLabel(entry) {
  if (!entry) return '—';
  if (entry.approve === 'X' || entry.approve === 'x') return 'Approved';
  if (entry.action === 'approve_save') return 'Approve save';
  return 'Draft';
}

function auditPayloadText(entry) {
  if (!entry || entry.payload == null) return '';
  try {
    return typeof entry.payload === 'string'
      ? entry.payload
      : JSON.stringify(entry.payload, null, 2);
  } catch (e) {
    return String(entry.payload);
  }
}

function auditRowHtml(entry) {
  const ok = !!entry.success;
  const so = `${escapeHtml(entry.vbeln || '—')} / ${escapeHtml(entry.posnr || '—')}`;
  const method = String(entry.method || 'POST').toUpperCase();
  const url = entry.url || '—';
  const http = entry.http_code || '—';
  const payload = auditPayloadText(entry);
  const id = `audit-payload-js-${entry.id || Math.random().toString(36).slice(2, 9)}`;
  const payloadCell = payload
    ? `<button type="button" class="audit-payload-btn" onclick="document.getElementById('${id}').hidden=!document.getElementById('${id}').hidden">View</button>`
    : '—';
  const detail = payload
    ? `<tr id="${id}" class="audit-payload-row" hidden><td colspan="8"><div class="audit-payload-box">
        <div class="audit-payload-meta">
          <div><strong>Method:</strong> ${escapeHtml(method)}</div>
          <div><strong>URL:</strong> <code>${escapeHtml(url)}</code></div>
          <div><strong>HTTP:</strong> ${escapeHtml(String(http))}</div>
          ${entry.csrf_url ? `<div><strong>CSRF:</strong> ${escapeHtml(String(entry.csrf_method || 'GET'))} <code>${escapeHtml(entry.csrf_url)}</code></div>` : ''}
        </div>
        <pre class="audit-payload-json">${escapeHtml(payload)}</pre>
      </div></td></tr>`
    : '';
  return `<tr class="audit-row-enter">
    <td>${escapeHtml(formatAuditWhen(entry.ts))}</td>
    <td>${escapeHtml(entry.user || '—')}</td>
    <td><span class="audit-method audit-method-${escapeHtml(method.toLowerCase())}">${escapeHtml(method)}</span></td>
    <td class="audit-mono audit-url" title="${escapeHtml(url)}">${escapeHtml(url)}</td>
    <td class="audit-mono">${escapeHtml(String(http))}</td>
    <td class="audit-mono">${so}</td>
    <td><span class="audit-result ${ok ? 'ok' : 'fail'}">${ok ? 'OK' : 'FAIL'}</span>
      <small class="audit-msg">${escapeHtml(entry.message || entry.source || '')}</small></td>
    <td>${payloadCell}</td>
  </tr>${detail}`;
}

function prependAuditEntry(entry) {
  const tbody = document.getElementById('audit-tbody');
  if (!tbody || !entry) return;
  const empty = tbody.querySelector('.audit-empty');
  if (empty) tbody.innerHTML = '';
  tbody.insertAdjacentHTML('afterbegin', auditRowHtml(entry));
}

async function loadAuditLog() {
  const tbody = document.getElementById('audit-tbody');
  if (!tbody) return;
  if (!portalUser.is_admin) {
    tbody.innerHTML = `<tr><td colspan="8" class="audit-empty">Audit Log is available only for live admin logins</td></tr>`;
    return;
  }
  tbody.innerHTML = `<tr><td colspan="8" class="audit-empty">Loading audit log…</td></tr>`;
  try {
    const vbeln = costData?.Vbeln || document.getElementById('hdr-vbeln')?.value.trim() || '';
    const qs = new URLSearchParams({ action: 'audit', limit: '40' });
    if (vbeln) qs.set('vbeln', vbeln);
    const res = await fetch(_zcostApiUrl(qs.toString()));
    const json = await res.json();
    if (!res.ok || json.success === false) {
      tbody.innerHTML = `<tr><td colspan="8" class="audit-empty">${escapeHtml(json.error || 'Access denied')}</td></tr>`;
      return;
    }
    const rows = Array.isArray(json.data) ? json.data : [];
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="8" class="audit-empty">No save activity yet for this sheet</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(auditRowHtml).join('');
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="8" class="audit-empty">Could not load audit log</td></tr>`;
  }
}

/**
 * Initializes the page on load.
 * @returns {void}
 */
/**
 * ============================================================================
 * SAP INITIAL SCREEN CONTROLLER (Matching Exact SAP GUI Layout)
 * ============================================================================
 */

function isZcostViewOnly() {
  return document.documentElement.classList.contains('zcost-view-only');
}

function showInitialScreen() {
  if (isZcostViewOnly() || document.documentElement.classList.contains('zcost-boot-sheet')) {
    return;
  }
  const initScreen = document.getElementById('sap-initial-screen');
  const mainWin = document.getElementById('sap-window');
  const landing = document.getElementById('evolv-landing-page');

  if (initScreen) initScreen.classList.remove('hidden');
  if (mainWin) mainWin.style.display = 'none';
  if (landing) landing.classList.add('hidden');

  closeActionModal();
  closeCopyCostingModal();
  hideNotFoundModal();

  // Reset document title / window state
  document.title = 'Sales Costing Sheet · EVOLV';

  // Clear query parameters from URL without reloading
  try {
    window.history.pushState({}, '', window.location.pathname);
  } catch (e) {}

  const statusText = document.getElementById('init-status-text');
  if (statusText) {
    statusText.textContent = 'Select an option to begin: Create, Copy, Change, or Approve a Sales Costing Sheet.';
  }
}

function hideInitialScreen() {
  const initScreen = document.getElementById('sap-initial-screen');
  const mainWin = document.getElementById('sap-window');
  const landing = document.getElementById('evolv-landing-page');

  if (initScreen) initScreen.classList.add('hidden');
  if (landing) landing.classList.add('hidden');
  if (mainWin) mainWin.style.display = 'flex';
}

function handleInitQuickSearch(vbeln) {
  const clean = (vbeln || '').trim();
  if (!clean) return;
  hideInitialScreen();
  fetchCostSheet(clean, '000010');
}

/**
 * ============================================================================
 * ACTION MODAL CONTROLLER (New / Change / Approve)
 * ============================================================================
 */
let currentActionMode = 'new'; // 'new' | 'change' | 'approve'

function openActionModal(mode) {
  const need = { new: 'add', copy: 'copy', change: 'edit', approve: 'approve' };
  if (zcostHas(need[mode] || '') === false) {
    if (typeof showToast === 'function') {
      showToast('You do not have permission for this costing action.', 'error');
    }
    return;
  }
  if (mode === 'copy') {
    openCopyCostingModal();
    return;
  }

  currentActionMode = mode;
  const modal = document.getElementById('modal-action-dialog');
  const badge = document.getElementById('action-modal-badge');
  const title = document.getElementById('action-modal-title');
  const desc = document.getElementById('action-modal-desc');
  const submitText = document.getElementById('action-modal-submit-text');
  const submitBtn = document.getElementById('action-modal-submit-btn');
  const errBox = document.getElementById('action-modal-error');
  const vbelnInput = document.getElementById('action-input-vbeln');
  const posnrInput = document.getElementById('action-input-posnr');

  if (errBox) {
    errBox.style.display = 'none';
    errBox.textContent = '';
  }

  if (vbelnInput) {
    vbelnInput.value = '';
  }
  if (posnrInput) {
    posnrInput.value = '000010';
  }

  if (badge) {
    badge.className = 'sap-action-modal-badge';
  }
  if (submitBtn) {
    submitBtn.className = 'sap-modal-btn-submit';
  }

  if (mode === 'new') {
    if (badge) {
      badge.classList.add('badge-new');
      badge.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="2.2"><rect x="4" y="3" width="16" height="18" rx="2" fill="#eff6ff"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/></svg>`;
    }
    if (title) title.textContent = 'New Costing';
    if (desc) desc.textContent = 'Enter Quotation Number & Item to initialize a new costing sheet';
    if (submitText) submitText.textContent = 'Create Costing';
    if (submitBtn) submitBtn.classList.add('btn-submit-new');
  } else if (mode === 'change') {
    if (badge) {
      badge.classList.add('badge-change');
      badge.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" fill="#f0f9ff"/></svg>`;
    }
    if (title) title.textContent = 'Change Costing';
    if (desc) desc.textContent = 'Enter Quotation Number & Item to open in Change (Edit) mode';
    if (submitText) submitText.textContent = 'Open in Change Mode';
    if (submitBtn) submitBtn.classList.add('btn-submit-change');
  } else if (mode === 'approve') {
    if (badge) {
      badge.classList.add('badge-approve');
      badge.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2"><circle cx="12" cy="12" r="9" fill="#f0fdf4"/><polyline points="9 12 11.5 14.5 15.5 9.5"/></svg>`;
    }
    if (title) title.textContent = 'Approve Costing';
    if (desc) desc.textContent = 'Enter Quotation Number & Item to review and approve';
    if (submitText) submitText.textContent = 'Open for Approval';
    if (submitBtn) submitBtn.classList.add('btn-submit-approve');
  }

  if (modal) modal.hidden = false;
  setTimeout(() => {
    if (vbelnInput) vbelnInput.focus();
  }, 100);
}

function closeActionModal() {
  const modal = document.getElementById('modal-action-dialog');
  if (modal) modal.hidden = true;
}

function setActionDialogSO(vbeln, posnr = '000010') {
  const vbelnInput = document.getElementById('action-input-vbeln');
  const posnrInput = document.getElementById('action-input-posnr');
  if (vbelnInput) vbelnInput.value = vbeln;
  if (posnrInput) posnrInput.value = posnr;
}

async function handleActionDialogSubmit() {
  const errBox = document.getElementById('action-modal-error');
  const vbelnInput = document.getElementById('action-input-vbeln');
  const posnrInput = document.getElementById('action-input-posnr');
  const submitBtn = document.getElementById('action-modal-submit-btn');

  const showModalErr = (html) => {
    if (errBox) {
      errBox.innerHTML = html;
      errBox.style.display = 'block';
    }
  };

  if (errBox) {
    errBox.style.display = 'none';
    errBox.innerHTML = '';
  }

  let vbeln = (vbelnInput?.value || '').trim();
  let posnr = (posnrInput?.value || '').trim() || '000010';

  if (!vbeln) {
    showModalErr('<div style="font-weight:600;">Please enter a Quotation / Sales Order number.</div>');
    if (vbelnInput) vbelnInput.focus();
    return;
  }

  if (/^\d+$/.test(posnr) && posnr.length < 6) {
    posnr = posnr.padStart(6, '0');
  }

  if (submitBtn) submitBtn.disabled = true;

  try {
    // =========================================================================
    // 1. NEW COSTING:
    // First call GET API: if values already exist -> DO NOT ALLOW CREATING!
    // If no values / not found -> Proceed to create brand new costing sheet!
    // =========================================================================
    if (currentActionMode === 'new') {
      showLoader(`Checking Quotation ${vbeln}...`);
      let checkJson = null;
      try {
        const checkRes = await fetch(_zcostApiUrl(`vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`));
        checkJson = await checkRes.json();
      } catch (e) {
        checkJson = { success: false, not_found: true };
      }
      hideLoader();

      // Check if costing sheet ALREADY EXISTS with values
      if (checkJson && checkJson.success && checkJson.data && (checkJson.data.Vbeln || checkJson.data.to_Fabric)) {
        if (submitBtn) submitBtn.disabled = false;
        const isAppr = (checkJson.data.Approve === 'X' || checkJson.data.Approve === 'x');
        const approver = checkJson.data.Approvedby || '';
        const statusBadge = isAppr
          ? `<span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">APPROVED${approver ? ' by ' + escapeHtml(approver) : ''}</span>`
          : `<span style="background:#fef3c7;color:#b45309;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">DRAFT / OPEN</span>`;

        showModalErr(`
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <span style="font-size:18px;line-height:1;">⚠️</span>
            <div style="flex:1;">
              <div style="font-weight:700;margin-bottom:3px;color:#991b1b;">Costing Sheet Already Exists</div>
              <div style="font-size:12.5px;line-height:1.45;color:#7f1d1d;margin-bottom:10px;">
                Quotation <strong>${escapeHtml(vbeln)}</strong> (Item <strong>${escapeHtml(posnr)}</strong>) already exists in the system ${statusBadge}. Duplicate creation is not allowed.
              </div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                ${!isAppr ? `<button type="button" class="sap-error-action-btn" style="background:#0284c7;color:#fff;" onclick="openActionModal('change'); setActionDialogSO('${escapeHtml(vbeln)}', '${escapeHtml(posnr)}');">Switch to Change Costing</button>` : ''}
                <button type="button" class="sap-error-action-btn" style="background:#16a34a;color:#fff;" onclick="openActionModal('approve'); setActionDialogSO('${escapeHtml(vbeln)}', '${escapeHtml(posnr)}');">Open for Approval Review</button>
              </div>
            </div>
          </div>
        `);
        showToast(`Quotation ${vbeln} already exists! Cannot create duplicate.`, 'warn');
        return;
      }

      // NO DATA EXISTS -> Proceed to create brand new costing sheet!
      showLoader('Initializing New Costing Sheet...');
      try {
        const res = await fetch(_zcostApiUrl(`vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}&action=create`));
        const json = await res.json();
        if (json.success && json.data) {
          costData = json.data;
        } else {
          createBlankQuotationMaster(vbeln, posnr);
        }
      } catch (e) {
        createBlankQuotationMaster(vbeln, posnr);
      }
      hideLoader();

      currentVbeln = vbeln;
      currentPosnr = posnr;

      closeActionModal();
      hideInitialScreen();

      const mainWin = document.getElementById('sap-window');
      if (mainWin) mainWin.style.display = 'flex';
      const landing = document.getElementById('evolv-landing-page');
      if (landing) landing.classList.add('hidden');

      // Automatically enable Edit / Change mode for entering materials
      isEditMode = true;
      renderAll();
      applyUiLockState();

      // Update URL
      try {
        const newUrl = `${window.location.pathname}?vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`;
        window.history.pushState({ vbeln, posnr }, '', newUrl);
      } catch (e) {}

      updateStatus(`New Costing Sheet initialized for SO ${vbeln} (Item ${posnr}) [Edit Mode Active]`, 'info');
      showToast(`New costing sheet ready for SO ${vbeln}. Add items and click Save.`, 'success');

    // =========================================================================
    // 2. CHANGE COSTING:
    // First call GET API:
    // - If no data found -> DO NOT OPEN! Show notification to create costing first.
    // - If data found & is APPROVED -> DO NOT OPEN! Show message "already approved".
    // - If data found & is NOT approved -> Open in Change/Edit mode!
    // =========================================================================
    } else if (currentActionMode === 'change') {
      showLoader(`Checking Quotation ${vbeln} for Change...`);
      let checkJson = null;
      try {
        const checkRes = await fetch(_zcostApiUrl(`vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`));
        checkJson = await checkRes.json();
      } catch (e) {
        checkJson = { success: false, not_found: true };
      }
      hideLoader();

      // Case A: NO DATA FOUND -> Do NOT open, tell user to create first
      if (!checkJson || !checkJson.success || !checkJson.data || checkJson.not_found) {
        if (submitBtn) submitBtn.disabled = false;
        showModalErr(`
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <span style="font-size:18px;line-height:1;">🔍</span>
            <div style="flex:1;">
              <div style="font-weight:700;margin-bottom:3px;color:#991b1b;">No Costing Sheet Found</div>
              <div style="font-size:12.5px;line-height:1.45;color:#7f1d1d;margin-bottom:10px;">
                No costing sheet records exist in SAP for Quotation <strong>${escapeHtml(vbeln)}</strong> (Item <strong>${escapeHtml(posnr)}</strong>). Please create the costing sheet first.
              </div>
              <div>
                <button type="button" class="sap-error-action-btn" style="background:#1d4ed8;color:#fff;" onclick="openActionModal('new'); setActionDialogSO('${escapeHtml(vbeln)}', '${escapeHtml(posnr)}');">Go to New Costing</button>
              </div>
            </div>
          </div>
        `);
        showToast(`No costing sheet found for Quotation ${vbeln}. Please create it first.`, 'warn');
        return;
      }

      // Case B: DATA EXISTS, but IS APPROVED -> Do NOT open! Show message
      const isApproved = (checkJson.data.Approve === 'X' || checkJson.data.Approve === 'x');
      if (isApproved) {
        if (submitBtn) submitBtn.disabled = false;
        const approver = checkJson.data.Approvedby || 'Authorized Approver';
        showModalErr(`
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <span style="font-size:18px;line-height:1;">🔒</span>
            <div style="flex:1;">
              <div style="font-weight:700;margin-bottom:3px;color:#991b1b;">Quotation is Already Approved</div>
              <div style="font-size:12.5px;line-height:1.45;color:#7f1d1d;margin-bottom:10px;">
                Quotation <strong>${escapeHtml(vbeln)}</strong> (Item <strong>${escapeHtml(posnr)}</strong>) has already been <strong>APPROVED</strong> by <strong>${escapeHtml(approver)}</strong>. Editing and modifications are locked for approved costing sheets.
              </div>
              <div>
                <button type="button" class="sap-error-action-btn" style="background:#16a34a;color:#fff;" onclick="openActionModal('approve'); setActionDialogSO('${escapeHtml(vbeln)}', '${escapeHtml(posnr)}');">Open for Approval Review</button>
              </div>
            </div>
          </div>
        `);
        showToast(`Quotation ${vbeln} is already approved! Changes are locked.`, 'error');
        return;
      }

      // Case C: DATA EXISTS and is NOT APPROVED -> Open in Edit / Change mode!
      costData = checkJson.data;
      currentVbeln = vbeln;
      currentPosnr = posnr;

      closeActionModal();
      hideInitialScreen();

      const mainWin = document.getElementById('sap-window');
      if (mainWin) mainWin.style.display = 'flex';
      const landing = document.getElementById('evolv-landing-page');
      if (landing) landing.classList.add('hidden');

      isEditMode = true;
      renderAll();
      applyUiLockState();

      showToast(`Quotation ${vbeln} loaded in Change mode. You can edit and save.`, 'success');
      updateStatus(`Quotation ${vbeln} Item ${posnr} opened in Change Mode. Ready to edit.`, 'info');

      try {
        const newUrl = `${window.location.pathname}?vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`;
        window.history.pushState({ vbeln, posnr }, '', newUrl);
      } catch (e) {}

    // =========================================================================
    // 3. APPROVE COSTING:
    // First call GET API:
    // - If no data found -> DO NOT OPEN! Show notification.
    // - If data found -> Open in Approval review mode!
    // =========================================================================
    } else if (currentActionMode === 'approve') {
      showLoader(`Loading Quotation ${vbeln} for Approval...`);
      let checkJson = null;
      try {
        const checkRes = await fetch(_zcostApiUrl(`vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`));
        checkJson = await checkRes.json();
      } catch (e) {
        checkJson = { success: false, not_found: true };
      }
      hideLoader();

      if (!checkJson || !checkJson.success || !checkJson.data || checkJson.not_found) {
        if (submitBtn) submitBtn.disabled = false;
        showModalErr(`
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <span style="font-size:18px;line-height:1;">🔍</span>
            <div style="flex:1;">
              <div style="font-weight:700;margin-bottom:3px;color:#991b1b;">No Costing Sheet Found</div>
              <div style="font-size:12.5px;line-height:1.45;color:#7f1d1d;margin-bottom:10px;">
                No costing sheet records exist in SAP for Quotation <strong>${escapeHtml(vbeln)}</strong> (Item <strong>${escapeHtml(posnr)}</strong>). Please create the costing sheet first.
              </div>
              <div>
                <button type="button" class="sap-error-action-btn" style="background:#1d4ed8;color:#fff;" onclick="openActionModal('new'); setActionDialogSO('${escapeHtml(vbeln)}', '${escapeHtml(posnr)}');">Go to New Costing</button>
              </div>
            </div>
          </div>
        `);
        showToast(`No costing sheet found for Quotation ${vbeln}. Please create it first.`, 'warn');
        return;
      }

      costData = checkJson.data;
      currentVbeln = vbeln;
      currentPosnr = posnr;

      closeActionModal();
      hideInitialScreen();

      const mainWin = document.getElementById('sap-window');
      if (mainWin) mainWin.style.display = 'flex';
      const landing = document.getElementById('evolv-landing-page');
      if (landing) landing.classList.add('hidden');

      isEditMode = false;
      renderAll();
      applyUiLockState();

      if (isSheetApproved()) {
        showToast(`Quotation ${vbeln} is already approved by ${costData.Approvedby || 'Approver'}.`, 'info');
        updateStatus(`Quotation ${vbeln} Item ${posnr} is already APPROVED by ${costData.Approvedby || 'Approver'}.`, 'info');
      } else {
        const btnApprove = document.getElementById('btn-approve');
        if (btnApprove) {
          btnApprove.hidden = false;
          btnApprove.disabled = !canApproveSheet();
          btnApprove.scrollIntoView({ behavior: 'smooth', block: 'center' });
          btnApprove.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.4)';
          setTimeout(() => {
            if (btnApprove) btnApprove.style.boxShadow = '';
          }, 3000);
        }
        showToast(`Quotation ${vbeln} loaded for Approval review.`, 'info');
        updateStatus(`Quotation ${vbeln} Item ${posnr} loaded for approval review.`, 'info');
      }

      try {
        const newUrl = `${window.location.pathname}?vbeln=${encodeURIComponent(vbeln)}&posnr=${encodeURIComponent(posnr)}`;
        window.history.pushState({ vbeln, posnr }, '', newUrl);
      } catch (e) {}
    }
  } catch (err) {
    showModalErr(`<div style="font-weight:600;">Error: ${escapeHtml(err.message)}</div>`);
  } finally {
    if (submitBtn) submitBtn.disabled = false;
    await hideLoader();
  }
}

/**
 * Initializes the page on load.
 * @returns {void}
 */
window.addEventListener('DOMContentLoaded', async () => {
  const urlParams = new URLSearchParams(window.location.search);
  const vbeln = (urlParams.get('vbeln') || '').trim();
  const posnr = urlParams.get('posnr') || '000010';
  const viewOnly = urlParams.get('view') === '1' || urlParams.get('mode') === 'view';

  if (viewOnly) {
    isEditMode = false;
    document.documentElement.classList.add('zcost-view-only');
  }

  if (vbeln) {
    document.documentElement.classList.add('zcost-boot-sheet');
    hideInitialScreen();
    const sheetLoad = fetchCostSheet(vbeln, posnr);
    try { await syncPortalSession(); } catch (e) {}
    await sheetLoad;
    hideInitialScreen();
    if (viewOnly) {
      isEditMode = false;
      applyUiLockState();
    }
    const navSearch = document.getElementById('nav-search-input');
    if (navSearch) navSearch.value = vbeln;
    const vbelnInput = document.getElementById('hdr-vbeln');
    if (vbelnInput && !String(vbelnInput.value || '').trim()) vbelnInput.value = vbeln;
  } else {
    try { await syncPortalSession(); } catch (e) {}
    showInitialScreen();
  }
});

/**
 * ============================================================================
 * COPY COSTING ENGINE
 * Allows copying costing lines (Fabric, Trim, Packing, Special) & rates
 * from a reference Quotation/Item into the current sheet, or duplicating
 * to a new Target Quotation/Item.
 * ============================================================================
 */

let currentCopyMode = 'from'; // 'from' or 'to'

/**
 * Open Copy Costing Modal
 */
function openCopyCostingModal(initialSourceVbeln = null) {
  const modal = document.getElementById('modal-copy-costing');
  if (!modal) return;

  const currentV = costData?.Vbeln || document.getElementById('hdr-vbeln')?.value.trim() || currentVbeln || '';
  const currentP = costData?.Posnr || document.getElementById('hdr-posnr')?.value.trim() || currentPosnr || '000010';

  const isOnInitial = !costData || (document.getElementById('sap-initial-screen') && !document.getElementById('sap-initial-screen').classList.contains('hidden'));

  const targetGroup = document.getElementById('copy-from-target-group');
  const targetInfoBox = document.getElementById('copy-target-info-box');
  const targetDisplay = document.getElementById('copy-target-display');
  const sourceDisplay = document.getElementById('copy-source-display');

  if (isOnInitial) {
    if (targetGroup) targetGroup.style.display = 'block';
    if (targetInfoBox) targetInfoBox.style.display = 'none';
  } else {
    if (targetGroup) targetGroup.style.display = 'none';
    if (targetInfoBox) targetInfoBox.style.display = 'flex';
    if (targetDisplay) targetDisplay.textContent = `SO ${currentV} (Item ${currentP})`;
  }

  if (sourceDisplay) {
    sourceDisplay.textContent = currentV ? `SO ${currentV} (Item ${currentP})` : 'Current Sheet';
  }

  const errBox = document.getElementById('copy-modal-error');
  if (errBox) {
    errBox.style.display = 'none';
    errBox.textContent = '';
  }

  if (initialSourceVbeln) {
    const srcInput = document.getElementById('copy-from-vbeln');
    if (srcInput) srcInput.value = initialSourceVbeln;
  }

  switchCopyMode('from');

  modal.hidden = false;
  setTimeout(() => {
    const focusEl = document.getElementById('copy-from-vbeln');
    if (focusEl) focusEl.focus();
  }, 100);
}

/**
 * Close Copy Costing Modal
 */
function closeCopyCostingModal() {
  const modal = document.getElementById('modal-copy-costing');
  if (modal) modal.hidden = true;
}

/**
 * Switch Copy Mode ('from' | 'to')
 */
function switchCopyMode(mode) {
  currentCopyMode = mode;

  const tabFrom = document.getElementById('tab-copy-from');
  const tabTo = document.getElementById('tab-copy-to');
  const panelFrom = document.getElementById('copy-panel-from');
  const panelTo = document.getElementById('copy-panel-to');
  const btnSubmitText = document.getElementById('btn-copy-submit-text');

  if (tabFrom) tabFrom.classList.toggle('active', mode === 'from');
  if (tabTo) tabTo.classList.toggle('active', mode === 'to');
  if (panelFrom) panelFrom.style.display = mode === 'from' ? 'block' : 'none';
  if (panelTo) panelTo.style.display = mode === 'to' ? 'block' : 'none';

  if (btnSubmitText) {
    btnSubmitText.textContent = mode === 'from' ? 'Copy Costing' : 'Duplicate to New Quotation';
  }

  const errBox = document.getElementById('copy-modal-error');
  if (errBox) errBox.style.display = 'none';

  setTimeout(() => {
    const focusEl = mode === 'from'
      ? document.getElementById('copy-from-vbeln')
      : document.getElementById('copy-to-vbeln');
    if (focusEl) focusEl.focus();
  }, 50);
}

/**
 * Triggered from "Quotation Master Not Found" dialog
 */
function handleCopyFromNotFound() {
  const missingVbeln = pendingNotFoundQuotation?.vbeln || '';
  const missingPosnr = pendingNotFoundQuotation?.posnr || '000010';

  hideNotFoundModal();

  createBlankQuotationMaster(missingVbeln, missingPosnr);
  openCopyCostingModal();
}

/**
 * Executes the Copy Costing operation
 */
async function handleExecuteCopyCosting() {
  const errBox = document.getElementById('copy-modal-error');
  const btnSubmit = document.getElementById('btn-execute-copy');
  const btnText = document.getElementById('btn-copy-submit-text');

  const showCopyError = (msg) => {
    if (errBox) {
      errBox.textContent = msg;
      errBox.style.display = 'block';
    }
  };

  if (errBox) errBox.style.display = 'none';

  let srcVbeln = '';
  let srcPosnr = '000010';
  let targetVbeln = '';
  let targetPosnr = '000010';

  const isOnInitial = !costData || (document.getElementById('sap-initial-screen') && !document.getElementById('sap-initial-screen').classList.contains('hidden'));

  if (currentCopyMode === 'from') {
    srcVbeln = (document.getElementById('copy-from-vbeln')?.value || '').trim();
    srcPosnr = (document.getElementById('copy-from-posnr')?.value || '').trim() || '000010';

    if (isOnInitial) {
      targetVbeln = (document.getElementById('copy-from-target-vbeln')?.value || '').trim();
      targetPosnr = (document.getElementById('copy-from-target-posnr')?.value || '').trim() || '000010';
      if (!targetVbeln) {
        showCopyError('Please enter the Target Quotation (Destination SO #).');
        document.getElementById('copy-from-target-vbeln')?.focus();
        return;
      }
    } else {
      targetVbeln = costData?.Vbeln || document.getElementById('hdr-vbeln')?.value.trim() || currentVbeln;
      targetPosnr = costData?.Posnr || document.getElementById('hdr-posnr')?.value.trim() || currentPosnr || '000010';
    }

    if (!srcVbeln) {
      showCopyError('Please enter the Source Quotation (Reference SO #).');
      document.getElementById('copy-from-vbeln')?.focus();
      return;
    }
  } else {
    srcVbeln = costData?.Vbeln || document.getElementById('hdr-vbeln')?.value.trim() || currentVbeln;
    srcPosnr = costData?.Posnr || document.getElementById('hdr-posnr')?.value.trim() || currentPosnr || '000010';
    targetVbeln = (document.getElementById('copy-to-vbeln')?.value || '').trim();
    targetPosnr = (document.getElementById('copy-to-posnr')?.value || '').trim() || '000010';

    if (!targetVbeln) {
      showCopyError('Please enter the Target Quotation (New SO #).');
      document.getElementById('copy-to-vbeln')?.focus();
      return;
    }
  }

  // Format posnr to 6 digits
  if (/^\d+$/.test(srcPosnr) && srcPosnr.length < 6) {
    srcPosnr = srcPosnr.padStart(6, '0');
  }
  if (/^\d+$/.test(targetPosnr) && targetPosnr.length < 6) {
    targetPosnr = targetPosnr.padStart(6, '0');
  }

  // Prevent copying quotation onto itself
  if (srcVbeln.toLowerCase() === targetVbeln.toLowerCase() && srcPosnr === targetPosnr) {
    showCopyError('Source and Target Quotation / Item cannot be the same.');
    return;
  }

  // Scope checkboxes
  const copyFabric = document.getElementById('copy-opt-fabric')?.checked ?? true;
  const copyTrim = document.getElementById('copy-opt-trim')?.checked ?? true;
  const copyPacking = document.getElementById('copy-opt-packing')?.checked ?? true;
  const copySpecial = document.getElementById('copy-opt-special')?.checked ?? true;
  const copyOverheads = document.getElementById('copy-opt-overheads')?.checked ?? true;

  if (!copyFabric && !copyTrim && !copyPacking && !copySpecial && !copyOverheads) {
    showCopyError('Please select at least one component to copy.');
    return;
  }

  // Set loading state
  if (btnSubmit) btnSubmit.disabled = true;
  if (btnText) btnText.textContent = 'Fetching from SAP...';

  try {
    let sourceData = null;

    if (currentCopyMode === 'to' && costData) {
      sourceData = JSON.parse(JSON.stringify(costData));
    } else {
      const res = await fetch(_zcostApiUrl(`vbeln=${encodeURIComponent(srcVbeln)}&posnr=${encodeURIComponent(srcPosnr)}`));
      const json = await res.json();

      if (!json.success || !json.data) {
        showCopyError(json.error || `Source Quotation ${srcVbeln} (Item ${srcPosnr}) was not found in SAP.`);
        return;
      }
      sourceData = json.data;
    }

    let newCostData = {};

    if (currentCopyMode === 'from' && costData && !isOnInitial) {
      newCostData = JSON.parse(JSON.stringify(costData));
    } else {
      newCostData = JSON.parse(JSON.stringify(sourceData));
    }

    // Set Target Identity
    newCostData.Vbeln = targetVbeln;
    newCostData.Posnr = targetPosnr;
    newCostData.Approve = '';
    newCostData.Approvedby = '';

    const cloneLines = (items) => {
      return (items || []).map(item => {
        const row = { ...item };
        delete row.__metadata;
        row.Vbeln = targetVbeln;
        row.Posnr = targetPosnr;
        return row;
      });
    };

    if (copyFabric) {
      newCostData.to_Fabric = { results: cloneLines(sourceData.to_Fabric?.results) };
    }
    if (copyTrim) {
      newCostData.to_Trim = { results: cloneLines(sourceData.to_Trim?.results) };
    }
    if (copyPacking) {
      newCostData.to_Packing = { results: cloneLines(sourceData.to_Packing?.results) };
    }
    if (copySpecial) {
      newCostData.to_Special = { results: cloneLines(sourceData.to_Special?.results) };
    }

    if (copyOverheads) {
      const overheadFields = [
        'Cnv', 'Cnvf', 'Making', 'Overhead', 'Sam', 'Zwast',
        'MarkupP', 'Markup', 'Commision', 'CommVal', 'AddCost',
        'Discount', 'Quota', 'Nmachreq', 'Npeicbnd'
      ];
      overheadFields.forEach(f => {
        if (sourceData[f] !== undefined && sourceData[f] !== null) {
          newCostData[f] = sourceData[f];
        }
      });
    }

    costData = newCostData;
    currentVbeln = targetVbeln;
    currentPosnr = targetPosnr;

    hideInitialScreen();

    // Force Edit mode so user can review and edit
    isEditMode = true;

    renderAll();
    recalculateAll();
    applyUiLockState();

    closeCopyCostingModal();

    showToast(`Costing copied from SO ${srcVbeln} (${srcPosnr}) to SO ${targetVbeln}! In Edit Mode - click Save to post to SAP.`, false);
    updateStatus(`Costing copied from SO ${srcVbeln} / ${srcPosnr} -> ${targetVbeln} / ${targetPosnr}. Ready to Save.`, 'info');

    try {
      const newUrl = `${window.location.pathname}?vbeln=${encodeURIComponent(targetVbeln)}&posnr=${encodeURIComponent(targetPosnr)}`;
      window.history.pushState({ vbeln: targetVbeln, posnr: targetPosnr }, '', newUrl);
    } catch (e) {}

  } catch (err) {
    showCopyError(`Error copying costing: ${err.message}`);
  } finally {
    if (btnSubmit) btnSubmit.disabled = false;
    if (btnText) btnText.textContent = currentCopyMode === 'from' ? 'Copy Costing' : 'Duplicate to New Quotation';
  }
}