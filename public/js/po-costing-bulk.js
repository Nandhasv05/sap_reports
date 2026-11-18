/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/10/2026
 * DESCRIPTION : Bulk PO Costing Validation — fetch, validate, drill-down, workbench
*/
(function () {
    let cfg = {};
    try { cfg = JSON.parse(document.getElementById('poConfig')?.textContent || '{}'); } catch (e) { cfg = {}; }

    const $ = (id) => document.getElementById(id);
    const spinner = $('rptSpinner');
    const money = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const money2 = (n) => (n === null || n === undefined || n === '') ? '—' : Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qty = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
    const num = (n, dig = 4) => {
        if (n === null || n === undefined || n === '') return '—';
        const v = Number(n);
        if (!Number.isFinite(v)) return '—';
        return v.toLocaleString('en-IN', { maximumFractionDigits: dig });
    };
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));
    const fmtDate = (iso) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        return m ? `${m[3]}-${m[2]}-${m[1]}` : '—';
    };

    /*
    * Handle zCOST View URL Functionality
    */
    function padPosnr(v) {
        const digits = String(v ?? '').replace(/\D/g, '');
        if (!digits) return '000010';
        return digits.padStart(6, '0').slice(-6);
    }

    /*
    * Handle zCOST View URL Functionality
    */
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

    function quotationLinkHtml(quote, itemNo) {
        const q = String(quote ?? '').trim();
        if (!q) return '—';
        const href = zcostViewUrl(q, itemNo);
        return `<a class="pcw-quote-link" href="${esc(href)}" data-quote="${esc(q)}" target="_blank" rel="noopener noreferrer" title="Open zCOST Sales Costing Sheet">${esc(q)}</a>`;
    }

    const FABRIC = new Set(['ZFRI', 'ZFRD']);
    const TRIMS = new Set(['ZTRD', 'ZTRI']);
    const RUNTIME_CONCURRENCY = 2;

    let allRows = [];
    let filtered = [];
    let page = 1;
    const pageSize = 25;
    let serverTotal = 0;
    let serverPageCount = 1;
    let listTotals = null;
    let activePo = null;
    let itemCache = {};
    let toastTimer = null;
    let runtimeToken = 0;
    let runtimeRunning = false;
    let validateQueue = [];
    let autoValidatePage = true;
    let listFetching = false;

    const COL_DEFS = [
        { key: 'sno', label: 'S.No', locked: true },
        { key: 'status', label: 'System Validation' },
        { key: 'po', label: 'PO Number' },
        { key: 'date', label: 'PO Date' },
        { key: 'material', label: 'Material Type' },
        { key: 'brand_name', label: 'Brand Name' },
        { key: 'items', label: 'No. of Items' },
        { key: 'vendor_name', label: 'Vendor Name' },
        { key: 'vendor_code', label: 'Vendor Code' },
        { key: 'passed', label: 'Passed' },
        { key: 'exceptions', label: 'Unmatched' },
        { key: 'pending', label: 'Pending' },
        { key: 'totvalue', label: 'Total PO INR Value' },
        { key: 'esign', label: 'E-Sign' },
        { key: 'remarks', label: 'Remarks' },
        { key: 'mail_status', label: 'Sent / Receive' },
        { key: 'pdf', label: 'View PDF' },
    ];
    let hiddenCols = new Set();
    let pcbTablePrefManager = null;
    let pcbItemTablePrefManager = null;
    let currentSort = { key: null, dir: null };
    const NUMERIC_SORT_KEYS = new Set([
        'sno', 'items', 'passed', 'exceptions', 'pending', 'totvalue',
    ]);
    const MONEY_KEYS = new Set(['totvalue']);
    const RATE_KEYS = new Set();
    const colFilterState = Object.create(null);
    let assignedUser = { id: '', username: '' };
    let validateTargetPos = [];

    /*
    * Handle Current User Functionality
    */
    function currentUserName() {
        const raw = String(cfg.currentUser || cfg.username || cfg.user_name || '').trim();
        if (!raw) return '';
        return raw.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }

    /*
    * Handle Spinner Functionality
    */
    function showSpinner(on) {
        if (window.rptLoader && typeof window.rptLoader.show === 'function') {
            if (on) window.rptLoader.show({ solid: true });
            else window.rptLoader.hide(true);
            return;
        }
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    /*
    * Handle Toast Functionality
    */
    function toast(msg, kind = 'info') {
        const el = $('pcbToast');
        if (!el) return;
        el.hidden = false;
        el.className = 'pcb-toast is-' + kind;
        el.textContent = msg;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.hidden = true; }, kind === 'error' ? 8000 : 4500);
    }

    /*
     * Full-screen spinner while page POs validate (progress bar removed).
     */
    function setProgress(on) {
        if (on) {
            showSpinner(true);
            return;
        }
        if (!listFetching && !runtimeRunning) showSpinner(false);
    }

    /*************
     * Handldew Selceted Material Functionality
     */
    function selectedMat() {
        const radio = document.querySelector('input[name="pcb_mat"]:checked');
        const val = radio?.value || $('pcbMat')?.value || 'all';
        if ($('pcbMat') && $('pcbMat').value !== val) $('pcbMat').value = val;
        return val || 'all';
    }

    function selectedApprovedStatus() {
        cfg.approvedStatus = '';
        return '';
    }

    function selectedBrand() {
        const val = String($('pcbBrand')?.value || cfg.brand || '').trim();
        if ($('pcbBrand') && $('pcbBrand').value !== val) $('pcbBrand').value = val;
        cfg.brand = val;
        return val;
    }

    function selectedBrands() {
        return selectedBrand().split(',').map((s) => s.trim()).filter(Boolean);
    }

    function bindBrandDropdown() {
        const dd = $('pcbBrandDropdown');
        if (!dd) return;

        const trigger = $('pcbBrandTrigger');
        const menu = $('pcbBrandMenu');
        const hiddenInput = $('pcbBrand');
        const labelEl = $('pcbBrandLabel');
        const badgeEl = $('pcbBrandBadge');
        const countInfoEl = $('pcbBrandCountInfo');
        const searchInput = $('pcbBrandSearch');
        const searchClear = $('pcbBrandSearchClear');
        const selectAllBtn = $('pcbBrandSelectAll');
        const clearAllBtn = $('pcbBrandClearAll');
        const applyBtn = $('pcbBrandApply');
        const cancelBtn = $('pcbBrandCancel');
        const items = () => Array.from(dd.querySelectorAll('.po-multi-item'));
        const checkboxes = () => Array.from(dd.querySelectorAll('.po-multi-checkbox'));

        let initialChecked = checkboxes().filter((c) => c.checked).map((c) => c.value);

        function updateItemHighlight() {
            items().forEach((item) => {
                const cb = item.querySelector('.po-multi-checkbox');
                item.classList.toggle('is-checked', !!cb?.checked);
            });
        }

        function updateCountInfo() {
            const cbs = checkboxes();
            const checkedCount = cbs.filter((c) => c.checked).length;
            if (countInfoEl) {
                countInfoEl.textContent = `${checkedCount} of ${cbs.length} selected`;
            }
            updateItemHighlight();
        }

        function updateTriggerUI(selected) {
            if (selected.length === 0) {
                if (labelEl) labelEl.textContent = 'All Brands';
                if (badgeEl) { badgeEl.style.display = 'none'; badgeEl.textContent = '0'; }
            } else if (selected.length === 1) {
                const checkedCb = checkboxes().find((c) => c.value === selected[0]);
                const name = checkedCb
                    ? checkedCb.closest('.po-multi-item')?.querySelector('.po-multi-item-name')?.textContent
                    : selected[0];
                if (labelEl) labelEl.textContent = name || selected[0];
                if (badgeEl) { badgeEl.style.display = 'inline-flex'; badgeEl.textContent = '1'; }
            } else {
                if (labelEl) labelEl.textContent = `${selected.length} Brands Selected`;
                if (badgeEl) { badgeEl.style.display = 'inline-flex'; badgeEl.textContent = String(selected.length); }
            }
        }

        function filterList(q) {
            const query = q.trim().toLowerCase();
            if (searchClear) searchClear.style.display = query ? 'flex' : 'none';
            items().forEach((item) => {
                const code = (item.getAttribute('data-code') || '').toLowerCase();
                const name = (item.getAttribute('data-name') || '').toLowerCase();
                const match = !query || code.includes(query) || name.includes(query);
                item.style.display = match ? 'flex' : 'none';
            });
        }

        function openDropdown(open) {
            dd.classList.toggle('is-open', open);
            trigger?.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                initialChecked = checkboxes().filter((c) => c.checked).map((c) => c.value);
                if (searchInput) {
                    searchInput.value = '';
                    filterList('');
                    setTimeout(() => searchInput.focus(), 60);
                }
            }
        }

        function restoreInitial() {
            checkboxes().forEach((c) => { c.checked = initialChecked.includes(c.value); });
            updateCountInfo();
        }

        trigger?.addEventListener('click', (e) => {
            e.stopPropagation();
            openDropdown(!dd.classList.contains('is-open'));
        });
        menu?.addEventListener('click', (e) => e.stopPropagation());
        checkboxes().forEach((cb) => cb.addEventListener('change', updateCountInfo));
        searchInput?.addEventListener('input', () => filterList(searchInput.value));
        searchClear?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            filterList('');
            searchInput?.focus();
        });
        selectAllBtn?.addEventListener('click', () => {
            items().forEach((item) => {
                if (item.style.display === 'none') return;
                const cb = item.querySelector('.po-multi-checkbox');
                if (cb) cb.checked = true;
            });
            updateCountInfo();
        });
        clearAllBtn?.addEventListener('click', () => {
            items().forEach((item) => {
                if (item.style.display === 'none') return;
                const cb = item.querySelector('.po-multi-checkbox');
                if (cb) cb.checked = false;
            });
            updateCountInfo();
        });
        cancelBtn?.addEventListener('click', () => {
            restoreInitial();
            openDropdown(false);
        });
        applyBtn?.addEventListener('click', () => {
            const selected = checkboxes().filter((c) => c.checked).map((c) => c.value);
            const val = selected.join(',');
            const oldVal = hiddenInput ? hiddenInput.value : '';
            if (hiddenInput) hiddenInput.value = val;
            cfg.brand = val;
            updateTriggerUI(selected);
            openDropdown(false);
            if (val !== oldVal) void fetchPos();
        });
        document.addEventListener('click', (e) => {
            if (dd.classList.contains('is-open') && !dd.contains(e.target)) {
                restoreInitial();
                openDropdown(false);
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && dd.classList.contains('is-open')) {
                restoreInitial();
                openDropdown(false);
            }
        });

        updateCountInfo();
        updateTriggerUI(selectedBrands());

        return {
            clear() {
                checkboxes().forEach((c) => { c.checked = false; });
                if (hiddenInput) hiddenInput.value = '';
                cfg.brand = '';
                initialChecked = [];
                updateCountInfo();
                updateTriggerUI([]);
            },
        };
    }

    function workbenchUrl(po, opts = {}) {
        const u = new URL(cfg.pageUrl || 'purchase-order', window.location.origin);
        u.searchParams.set('view', 'costing');
        u.searchParams.set('po', po);
        const mat = selectedMat();
        if (mat !== 'all') u.searchParams.set('mat_cat', mat);
        const brand = selectedBrand();
        if (brand) u.searchParams.set('brand', brand);
        if (opts.open) u.searchParams.set('open', String(opts.open));
        return u.pathname + u.search;
    }

    function pdfUrl(po) {
        const u = new URL(cfg.pdfUrl || 'purchase-order/pdf', window.location.origin);
        u.searchParams.set('po', po);
        return u.pathname + u.search;
    }

    /*
     * Fallback when costing validate has not run yet (e-sign / workflow enrichment).
     */
    function workflowListStatus(rec) {
        const bucket = String(rec?.status_bucket || '').toLowerCase();
        if (bucket === 'completed') return 'PASSED';
        if (bucket === 'in_review' || bucket === 'rejected') return 'NEED_TO_REVIEW';
        if (bucket === 'not_started' || bucket === 'pending') return 'MAPPING_REQUIRED';

        const raw = String(rec?.validation_status || rec?.workflow_stage || '').toLowerCase();
        if (raw.includes('validated') || raw.includes('completed') || raw === 'passed') return 'PASSED';
        if (raw.includes('review') || raw.includes('rejected')) return 'NEED_TO_REVIEW';
        return 'MAPPING_REQUIRED';
    }

    /**
     * Same rollup as workbench KPI cards (PASS / Mapping Required / Need to Review).
     * Prefer this over workflow enrichment once purchase-order/validate has run.
     */
    function costingListStatusFromRecords(records) {
        let passed = 0;
        let mapping = 0;
        let review = 0;
        let detail = 0;
        (records || []).forEach((r) => {
            if (isFooterLine(r)) return;
            detail += 1;
            const st = String(r?.status || '').toUpperCase().replace(/\s+/g, '_');
            const hasLink = !!(r.manual_match || r.matched_line_key || r.cost_item || r.maktx || r.costing_item_no || r.zcost_item);
            const unmatched = !hasLink && (
                st === 'NOT_MATCHED'
                || st === 'UNMATCHED'
                || !!r.needs_manual_match
                || !!r.family_mismatch
                || /no matching|no linked|not matched|mapping required/i.test(String(r?.validation_result || ''))
            );
            if (unmatched || st === 'NOT_MATCHED') {
                mapping += 1;
                return;
            }
            // Live RESULT compare (same rule as workbench statusDisplay)
            const budget = nNum(r.budget_cost_per_gmt ?? r.expected_unit_cost ?? r.budget_inr);
            const actual = nNum(r.actual_cost ?? r.po_unit_cost ?? r.porate ?? r.net_price);
            if (budget > 0 && actual > 0) {
                if (actual > budget + 0.005) {
                    review += 1;
                    return;
                }
                passed += 1;
                return;
            }
            if (budget > 0 && actual <= 0) {
                review += 1;
                return;
            }
            if (
                st === 'NEEDS_REVIEW'
                || st === 'NOT_VALIDATED'
                || st === 'EXCEPTION'
                || st === 'FAIL'
                || st === 'AMBIGUOUS'
                || st === 'AMBIGUOUS_MATCH'
                || !!r.cost_mismatch
            ) {
                review += 1;
                return;
            }
            if (st === 'PASS') {
                passed += 1;
                return;
            }
            // Linked but uncertain → review; else mapping (same as workbench statusDisplay)
            if (hasLink) review += 1;
            else mapping += 1;
        });
        if (!detail) return { status: 'MAPPING_REQUIRED', passed: 0, mapping: 0, review: 0 };
        if (mapping > 0) return { status: 'MAPPING_REQUIRED', passed, mapping, review };
        if (review > 0) return { status: 'NEED_TO_REVIEW', passed, mapping, review };
        if (passed > 0) return { status: 'PASSED', passed, mapping, review };
        return { status: 'MAPPING_REQUIRED', passed, mapping, review };
    }

    function statusBadge(st) {
        const s = String(st || 'MAPPING_REQUIRED').toUpperCase().replace(/\s+/g, '_');
        let cls = 'is-pending';
        let label = 'MAPPING REQUIRED';
        if (s === 'PASSED') {
            cls = 'is-pass';
            label = 'Valid';
        } else if (s === 'NEED_TO_REVIEW' || s === 'NEEDS_REVIEW') {
            cls = 'is-review';
            label = 'NEED TO REVIEW';
        } else if (
            s === 'MAPPING_REQUIRED'
            || s === 'MISSING_REQUIRED'
            || s === 'PENDING'
            || s === 'EXCEPTION'
            || s === 'UNMATCHED'
        ) {
            cls = 'is-pending';
            label = 'MAPPING REQUIRED';
        }
        return `<span class="pcb-badge ${cls}" title="Workflow validation status">${esc(label)}</span>`;
    }

    function openRulesPopover() {
        const pop = $('pcbRulesPopover');
        const kpi = $('pcbUnmatchedKpi');
        if (!pop) return;
        pop.hidden = false;
        if (kpi) kpi.setAttribute('aria-expanded', 'true');
    }

    function closeRulesPopover() {
        const pop = $('pcbRulesPopover');
        const kpi = $('pcbUnmatchedKpi');
        if (!pop) return;
        pop.hidden = true;
        if (kpi) kpi.setAttribute('aria-expanded', 'false');
    }

    function findListRowByPo(po) {
        const key = String(po || '').replace(/\D/g, '').replace(/^0+/, '') || String(po || '');
        const pool = [...(Array.isArray(filtered) ? filtered : []), ...(Array.isArray(allRows) ? allRows : [])];
        return pool.find((r) => {
            const p = String(r.purchase_order || '').replace(/\D/g, '').replace(/^0+/, '') || String(r.purchase_order || '');
            return p === key || String(r.purchase_order || '') === String(po || '');
        }) || null;
    }

    function closeRemarksPopover() {
        const pop = $('pcbRemarksPopover');
        if (pop) pop.hidden = true;
    }

    function closeMailPopover() {
        const pop = $('pcbMailPopover');
        if (pop) pop.hidden = true;
    }

    function openRemarksPopover(po) {
        const pop = $('pcbRemarksPopover');
        const title = $('pcbRemarksTitle');
        const kicker = $('pcbRemarksKicker');
        const meta = $('pcbRemarksMeta');
        const list = $('pcbRemarksList');
        if (!pop || !list) return;

        const row = findListRowByPo(po);
        const all = row && Array.isArray(row.remarks_all)
            ? row.remarks_all.map((x) => String(x || '').trim()).filter(Boolean)
            : [];
        const fallback = String(row?.remarks || '').trim();
        const notes = all.length ? all : (fallback ? [fallback] : []);
        const by = String(row?.remarks_by || '').trim();
        const at = String(row?.remarks_at || '').trim();

        if (kicker) kicker.textContent = 'Status & Remarks';
        if (title) title.textContent = `PO ${po || '—'} · Remarks`;
        if (meta) {
            meta.innerHTML = [
                `<span><strong>${notes.length}</strong> remark${notes.length === 1 ? '' : 's'}</span>`,
                by ? `<span>Latest by <strong>${esc(by)}</strong></span>` : '',
                at ? `<span>${esc(at)}</span>` : '',
            ].filter(Boolean).join('');
        }

        if (!notes.length) {
            list.innerHTML = `<div class="pcb-remarks-empty">No remarks for this PO.</div>`;
        } else {
            list.innerHTML = notes.map((note, idx) => `
                <article class="pcb-remarks-item" role="listitem">
                    <div class="pcb-remarks-item-head">
                        <span class="pcb-remarks-item-idx">#${idx + 1}</span>
                        ${idx === 0 ? '<span class="pcb-remarks-item-tag">Latest</span>' : ''}
                    </div>
                    <div class="pcb-remarks-item-body">${formatCommentHtml(note)}</div>
                </article>
            `).join('');
        }

        closeRulesPopover();
        closeMailPopover();
        pop.hidden = false;
    }

    function openMailPopover(po) {
        const pop = $('pcbMailPopover');
        const title = $('pcbMailTitle');
        const body = $('pcbMailBody');
        if (!pop || !body) return;

        const row = findListRowByPo(po);
        const status = String(row?.sent_receive_status || '').trim() || '—';
        const from = String(row?.mail_from || '').trim();
        const to = String(row?.mail_to || '').trim();
        const email = String(row?.mail_email || '').trim();
        const note = String(row?.mail_status || '').trim();
        const at = String(row?.mail_at || '').trim();
        const sendNote = String(row?.mail_note || '').trim();
        const isRecv = status.toLowerCase().includes('receiv');
        const user = to || from || '—';
        const statusCls = isRecv ? 'is-receive' : 'is-sent';

        if (title) title.textContent = `PO ${po || '—'}`;
        const ico = pop.querySelector('.pcb-mail-head-ico');
        if (ico) ico.textContent = isRecv ? 'move_to_inbox' : 'send';

        body.innerHTML = `
            <div class="pcb-mail-hero ${statusCls}">
                <span class="pcb-mail-avatar" aria-hidden="true">${esc(avatarLetter(user))}</span>
                <div class="pcb-mail-hero-text">
                    <div class="pcb-mail-hero-top">
                        <strong class="pcb-mail-hero-name">${esc(user)}</strong>
                        <span class="pcb-mail-status ${statusCls}">${esc(status)}</span>
                    </div>
                    <a class="pcb-mail-hero-email" href="${email ? `mailto:${esc(email)}` : '#'}" ${email ? '' : 'tabindex="-1" aria-disabled="true"'}>${esc(email || 'No mail ID')}</a>
                    ${at ? `<time class="pcb-mail-hero-when">${esc(at)}</time>` : ''}
                </div>
            </div>
            <div class="pcb-mail-grid">
                <div class="pcb-mail-field">
                    <span class="pcb-mail-label">Username</span>
                    <span class="pcb-mail-value">${esc(user)}</span>
                </div>
                <div class="pcb-mail-field">
                    <span class="pcb-mail-label">Mail ID</span>
                    <span class="pcb-mail-value">${email ? `<a href="mailto:${esc(email)}">${esc(email)}</a>` : '—'}</span>
                </div>
                <div class="pcb-mail-field">
                    <span class="pcb-mail-label">From</span>
                    <span class="pcb-mail-value">${esc(from || '—')}</span>
                </div>
                <div class="pcb-mail-field">
                    <span class="pcb-mail-label">Status note</span>
                    <span class="pcb-mail-value">${esc(note || '—')}</span>
                </div>
            </div>
            ${sendNote ? `
                <div class="pcb-mail-note">
                    <span class="pcb-mail-label">Note</span>
                    <div class="pcb-mail-note-body">${formatCommentHtml(sendNote)}</div>
                </div>
            ` : ''}
        `;

        closeRulesPopover();
        closeRemarksPopover();
        pop.hidden = false;
    }

    /*****
     * Materail Type Label Functionality
     */
    function materialTypeLabel(rec) {
        const cat = String(rec.mat_category || '').toLowerCase();
        if (cat === 'fabric') return 'Fabric';
        if (cat === 'trims') return 'Trims';
        if (cat === 'mixed') return 'Mixed';

        const cats = Array.isArray(rec.mat_categories) ? rec.mat_categories.map((c) => String(c).toLowerCase()) : [];
        if (cats.includes('fabric') && cats.includes('trims')) return 'Mixed';
        if (cats.includes('fabric')) return 'Fabric';
        if (cats.includes('trims')) return 'Trims';

        const types = Array.isArray(rec.material_types)
            ? rec.material_types
            : String(rec.material_type || '').split(',').map((s) => s.trim()).filter(Boolean);
        let hasFab = false;
        let hasTrim = false;
        types.forEach((t) => {
            const u = String(t).toUpperCase();
            if (FABRIC.has(u)) hasFab = true;
            if (TRIMS.has(u)) hasTrim = true;
        });
        if (hasFab && hasTrim) return 'Mixed';
        if (hasFab) return 'Fabric';
        if (hasTrim) return 'Trims';
        return '—';
    }

    function normalizeRow(rec) {
        const po = String(rec.purchase_order || '');
        const cached = itemCache[po] || itemCache[po.replace(/^0+/, '')] || null;
        let passed = 0, exceptions = 0, needsReview = 0, pending = 0;
        // Prefer workbench costing validate result; fall back to workflow enrichment
        let validationStatus = workflowListStatus(rec);
        let lastValidated = '';
        let validatedBy = '';
        const hasCache = !!(cached && Array.isArray(cached.records) && cached.records.length);
        if (hasCache) {
            const rollup = costingListStatusFromRecords(cached.records);
            validationStatus = rollup.status;
            passed = rollup.passed;
            needsReview = rollup.review;
            exceptions = rollup.mapping;
            pending = 0;
            lastValidated = cached._validated_at || '';
            validatedBy = cached._validated_by || '';
        } else {
            pending = Number(rec.items_count || 0) || 1;
        }
        // Only replace list totals from validate cache — never wipe API total_value with 0
        const apiValue = Number(rec.total_value ?? rec.totvalue ?? rec.net_value ?? 0) || 0;
        const cachedTotals = hasCache ? (totalsFromRecords(cached.records) || emptyTotals()) : null;
        const totals = cachedTotals || rec._totals || emptyTotals();
        const totvalue = (cachedTotals && Number(cachedTotals.totvalue) > 0)
            ? Number(cachedTotals.totvalue)
            : apiValue;
        return {
            ...rec,
            _checked: !!rec._checked,
            _mat_label: materialTypeLabel(rec),
            _passed: passed,
            _exceptions: exceptions,
            _needs_review: needsReview,
            _pending: pending,
            _validation_status: validationStatus,
            _last_validated: lastValidated,
            _validated_by: validatedBy,
            _totals: { ...totals, totvalue },
            so_qty: hasCache ? totals.so_qty : (Number(rec.so_qty || 0) || totals.so_qty),
            prwise_so_qty: hasCache ? totals.prwise_so_qty : (Number(rec.prwise_so_qty || 0) || totals.prwise_so_qty),
            consumption: hasCache ? totals.consumption : (Number(rec.consumption || 0) || totals.consumption),
            reqqty: hasCache ? totals.reqqty : (Number(rec.reqqty || 0) || totals.reqqty),
            tot_poqty: hasCache ? totals.tot_poqty : (Number(rec.total_qty || rec.tot_poqty || 0) || totals.tot_poqty),
            so_wise_poqty: hasCache ? totals.so_wise_poqty : (Number(rec.so_wise_poqty || 0) || totals.so_wise_poqty),
            so_wise_po_val: hasCache ? totals.so_wise_po_val : (Number(rec.so_wise_po_val || 0) || totals.so_wise_po_val),
            totvalue,
            total_value: totvalue > 0 ? totvalue : apiValue,
            po_currency: totals.po_currency || rec.currency || rec.po_currency || 'INR',
            budgeted_cons: hasCache ? totals.budgeted_cons : (Number(rec.budgeted_cons || 0) || totals.budgeted_cons),
            budget_inr: hasCache ? totals.budget_inr : (Number(rec.budget_inr || 0) || totals.budget_inr),
            budget_cost_per_gmt: hasCache ? totals.budget_cost_per_gmt : (Number(rec.budget_cost_per_gmt || 0) || totals.budget_cost_per_gmt),
            conv: hasCache ? totals.conv : (Number(rec.conv || 0) || totals.conv),
            actual_cost: hasCache ? totals.actual_cost : (Number(rec.actual_cost || 0) || totals.actual_cost),
            _po_items: hasCache ? totals.po_items : (Number(rec.items_count || 0) || totals.po_items),
            _po_materials: hasCache ? totals.po_materials : (Number(rec.materials_count || 0) || totals.po_materials),
            _z_items: totals.z_items,
            _z_materials: totals.z_materials,
            _z_so_qty: totals.z_so_qty,
            _z_net_value: totals.z_net_value,
        };
    }

    function emptyTotals() {
        return {
            so_qty: 0, prwise_so_qty: 0, consumption: 0, reqqty: 0, tot_poqty: 0,
            so_wise_poqty: 0, so_wise_po_val: 0, totvalue: 0, po_currency: 'INR',
            budgeted_cons: 0, budget_inr: 0, budget_cost_per_gmt: 0, conv: 0, actual_cost: 0,
            po_items: 0, po_materials: 0,
            z_items: 0, z_materials: 0, z_so_qty: 0, z_net_value: 0,
        };
    }

    function nNum(v) {
        const n = Number(v);
        return Number.isFinite(n) ? n : 0;
    }

    function isFooterLine(r) {
        const kind = String(r?.row_kind || '').toLowerCase();
        return kind === 'footer' || kind === 'subtotal' || kind === 'total' || kind === 'charge' || kind === 'tax';
    }

    const ITEM_FOOTER_SUM_KEYS = [
        'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'totvalue', 'budgeted_cons', 'budget_inr',
        'budget_cost_per_gmt', 'conv', 'actual_cost',
    ];

    function itemFooterLabel(r) {
        return String(r?.pono || r?.purchase_order || r?.matgroup || r?.validation_result || '')
            .toUpperCase()
            .replace(/\s+/g, ' ')
            .trim();
    }

    function hasItemClassicFooter(list) {
        return (list || []).some((r) => {
            const label = itemFooterLabel(r).replace(/-/g, '');
            return label === 'SUBTOTAL' || label === 'TOTAL' || label.includes('SUB TOTAL');
        });
    }

    function buildItemClassicFooter(detailRows) {
        const sums = Object.fromEntries(ITEM_FOOTER_SUM_KEYS.map((k) => [k, 0]));
        (detailRows || []).forEach((r) => {
            ITEM_FOOTER_SUM_KEYS.forEach((k) => {
                const n = Number(itemCellValue(k, r));
                if (Number.isFinite(n)) sums[k] += n;
            });
        });
        const mk = (label, kind, withSums) => {
            const row = {
                pono: label,
                purchase_order: label,
                matgroup: label,
                row_kind: kind,
                status: 'FOOTER',
                validation_result: label,
                needs_manual_match: false,
                _synthetic_footer: true,
            };
            ITEM_FOOTER_SUM_KEYS.forEach((k) => {
                row[k] = withSums ? Number(sums[k] || 0) : null;
            });
            return row;
        };
        return [
            mk('SUB-TOTAL', 'footer', true),
            mk('OTHER CHARGES', 'charge', false),
            mk('MOQ CHARGES', 'charge', false),
            mk('FREIGHT CHARGES', 'charge', false),
            mk('SCREEN CHARGES', 'charge', false),
            mk('BANK CHARGES', 'charge', false),
            mk('TOTAL', 'total', true),
        ];
    }

    function isMatchedLine(r) {
        if (!r || isFooterLine(r)) return false;
        const budget = nNum(r.budget_cost_per_gmt ?? r.expected_unit_cost ?? r.budget_inr);
        if (r.manual_match || r.matched_line_key || r.cost_item || r.maktx || r.costing_item_no) {
            return budget > 0;
        }
        if (r.needs_manual_match === true) return false;
        const actual = nNum(r.actual_cost ?? r.po_unit_cost);
        if (budget <= 0 || !actual) return false;
        const st = String(r.status || '').toUpperCase();
        if (st === 'EXCEPTION' && /no matching|no linked|cannot load|no budget/i.test(String(r.validation_result || ''))) {
            return false;
        }
        return true;
    }

    function totalsFromRecords(records) {
        if (!Array.isArray(records) || !records.length) return emptyTotals();
        const t = emptyTotals();
        const seenItems = new Set();
        const poMats = new Set();
        const zItems = new Set();
        const zMats = new Set();
        const seenZItem = new Set();
        let convSum = 0;
        let convN = 0;
        records.forEach((r) => {
            if (isFooterLine(r)) return;
            const soQty = nNum(r.so_qty ?? r.prwise_so_qty);
            const orderQty = nNum(r.tot_poqty ?? r.order_qty ?? r.po_qty);
            const costQty = soQty > 0 ? soQty : (orderQty > 0 ? orderQty : 1);
            const budget = nNum(r.budget_cost_per_gmt ?? r.expected_unit_cost);
            const actual = nNum(r.actual_cost ?? r.po_unit_cost);
            const budgetInr = nNum(r.budget_inr ?? r.cost_total_inr);

            t.so_qty += soQty;
            t.prwise_so_qty += nNum(r.prwise_so_qty ?? r.so_qty);
            t.consumption += nNum(r.consumption);
            t.reqqty += nNum(r.reqqty);
            t.so_wise_poqty += nNum(r.so_wise_poqty);
            t.so_wise_po_val += nNum(r.so_wise_po_val);
            t.budgeted_cons += nNum(r.budgeted_cons);
            t.budget_cost_per_gmt += budget;
            t.actual_cost += actual * costQty;
            t.budget_inr += budgetInr > 0 ? budgetInr : (budget * costQty);
            const cv = nNum(r.conv ?? r.convr ?? r.budget_conv_rt);
            if (cv) { convSum += cv; convN += 1; }

            const item = String(r.poitem || r.po_item || '').trim();
            const mat = String(r.material || '').trim();
            if (item) seenItems.add(item);
            if (mat) poMats.add(mat);

            const poRate = nNum(r.porate ?? r.po_unit_cost ?? r.net_price ?? r.actual_cost);
            const poLineNet = (poRate > 0 && orderQty > 0)
                ? (poRate * orderQty)
                : nNum(r.totvalue ?? r.net_value ?? r.so_wise_po_val);

            if (item) {
                if (!t._seenValue) t._seenValue = new Set();
                if (!t._seenValue.has(item)) {
                    t._seenValue.add(item);
                    t.tot_poqty += orderQty;
                    t.totvalue += poLineNet;
                }
            } else {
                t.tot_poqty += orderQty;
                t.totvalue += poLineNet;
            }

            if (isMatchedLine(r)) {
                if (item) zItems.add(item);
                if (mat) zMats.add(mat);
                // Once per matched PO item — avoid double-count across SO lines
                if (item && !seenZItem.has(item)) {
                    seenZItem.add(item);
                    t.z_so_qty += orderQty > 0 ? orderQty : soQty;
                    t.z_net_value += (budget > 0 ? budget : budgetInr) * (budget > 0 ? costQty : 1);
                } else if (!item) {
                    t.z_so_qty += orderQty > 0 ? orderQty : soQty;
                    t.z_net_value += (budget > 0 ? budget : budgetInr) * (budget > 0 ? costQty : 1);
                }
            }

            if (!t.po_currency) t.po_currency = String(r.po_currency || r.currency || 'INR');
        });
        t.conv = convN ? convSum / convN : 0;
        t.po_items = seenItems.size;
        t.po_materials = poMats.size;
        t.z_items = zItems.size;
        t.z_materials = zMats.size;
        delete t._seenValue;
        if (!t.po_currency) t.po_currency = 'INR';
        return t;
    }

    function formatCol(key, r, rowIndex) {
        switch (key) {
            case 'check':
                return `<input type="checkbox" class="pcb-row-check" data-po="${esc(r.purchase_order)}" ${r._checked ? 'checked' : ''}>`;
            case 'sno':
                return String(rowIndex);
            case 'po':
                const canWb = cfg.canWorkbench !== false;
                return `<button type="button" class="pcb-po-link${canWb ? '' : ' is-disabled'}" data-po="${esc(r.purchase_order)}"
                    title="${canWb ? 'Open workbench in new tab' : 'Workbench permission required'}"
                    ${canWb ? '' : 'disabled'}>${esc(r.purchase_order)}</button>`;
            case 'date':
                return fmtDate(r.date);
            case 'material':
                return `<span class="pcb-mat-chip">${dash(r._mat_label)}</span>`;
            case 'brand_name': {
                const label = r.brand_name || r.brand_code || '';
                const code = r.brand_code || '';
                return label
                    ? `<span title="${esc(code || label)}">${dash(label)}</span>`
                    : '—';
            }
            case 'items':
                return qty(r.items_count);
            case 'vendor_name':
                return dash(r.supplier_full_name);
            case 'vendor_code':
                return dash(r.supplier);
            case 'passed':
                return qty(r._passed);
            case 'exceptions':
                return qty(r._exceptions);
            case 'pending':
                return qty(r._pending);
            case 'status':
                return statusBadge(r._validation_status);
            case 'totvalue': {
                const amt = Number(r.totvalue ?? r.total_value ?? r.net_value ?? 0) || 0;
                return `<span class="pcb-inr-val">₹ ${money2(amt)}</span>`;
            }
            case 'validate': {
                const po = String(r.purchase_order || '');
                const done = !!itemCache[po];
                const busy = runtimeRunning && validateQueue.includes(po);
                if (done) {
                    return `<button type="button" class="pcb-validate-btn is-done" data-po="${esc(po)}" title="Already validated" disabled>
                        <span class="material-icons-round">check_circle</span>
                        Validated
                    </button>`;
                }
                return `<button type="button" class="pcb-validate-btn${busy ? ' is-busy' : ''}" data-po="${esc(po)}" title="Validate this PO" ${busy ? 'disabled' : ''}>
                    <span class="material-icons-round">${busy ? 'hourglass_top' : 'verified'}</span>
                    ${busy ? 'Validating…' : 'Validate'}
                </button>`;
            }
            case 'po_currency':
                return dash(r.po_currency || 'INR');
            case 'esign': {
                let s = String(r.esign_status || '').trim();
                if (!s || s === '—') s = 'Not Started';
                const low = s.toLowerCase();
                let tone = 'is-pending';
                if (low.includes('complet') || (low.includes('signed') && !low.includes('partial'))) tone = 'is-done';
                else if (low.includes('reject') || low.includes('fail')) tone = 'is-fail';
                else if (low.includes('pending') || low.includes('partial') || low.includes('checker') || low.includes('authoris')) tone = 'is-review';
                const noAccess = cfg.canEsign === false;
                return `<button type="button" class="pcb-esign-btn ${tone}" data-po="${esc(r.purchase_order)}"
                    title="${noAccess ? 'eSign permission required' : `Open eSign · ${esc(s)}`}"
                    ${noAccess ? 'disabled' : ''}>
                    <span class="material-icons-round" aria-hidden="true">draw</span>
                    <span>eSign</span>
                </button>`;
            }
            case 'remarks': {
                const all = Array.isArray(r.remarks_all)
                    ? r.remarks_all.map((x) => String(x || '').trim()).filter(Boolean)
                    : [];
                const note = all[0] || String(r.remarks || '').trim();
                if (!note) return '—';
                const count = Math.max(all.length, Number(r.remarks_count) || 0, 1);
                const short = note.length > 40 ? `${note.slice(0, 37)}…` : note;
                const more = count > 1
                    ? `<span class="pcb-remarks-count">+${count - 1}</span>`
                    : '';
                return `<button type="button" class="pcb-remarks pcb-remarks-btn" data-po="${esc(r.purchase_order)}" title="Click to view all remarks">
                    <span class="pcb-remarks-text">${esc(short)}</span>${more}
                </button>`;
            }
            case 'mail_status': {
                const s = String(r.sent_receive_status || '').trim();
                const to = String(r.mail_to || '').trim();
                const email = String(r.mail_email || '').trim();
                const from = String(r.mail_from || '').trim();
                if (!s && !to && !email) return '—';
                const note = String(r.mail_status || '').trim();
                const at = String(r.mail_at || '').trim();
                const tip = [
                    s,
                    from && to ? `${from} → ${to}` : (to || from),
                    email,
                    note,
                    at,
                ].filter(Boolean).join(' · ');
                const cls = String(s).toLowerCase().includes('receiv') ? 'is-receive' : 'is-sent';
                const userLine = to || from || s || '—';
                const mailLine = email || '—';
                return `<button type="button" class="pcb-mail-cell pcb-mail-btn ${cls}" data-po="${esc(r.purchase_order)}" title="${esc(tip)}">
                    <span class="pcb-mail-user">${esc(userLine)}</span>
                    <span class="pcb-mail-email">${esc(mailLine)}</span>
                </button>`;
            }
            case 'pdf':
                return `<button type="button" class="pcb-pdf-btn" data-po="${esc(r.purchase_order)}" title="View PO PDF">
                    <span class="material-icons-round">picture_as_pdf</span>
                    View PDF
                </button>`;
            case 'last':
                return dash(r._last_validated);
            default: {
                const v = r[key];
                if (MONEY_KEYS.has(key)) return money2(v);
                if (RATE_KEYS.has(key)) return num(v, 4);
                if (NUMERIC_SORT_KEYS.has(key)) return qty(v);
                return dash(v);
            }
        }
    }

    function pctLabel(n, total) {
        if (!total) return '0%';
        const p = (n / total) * 100;
        if (p > 0 && p < 1) return '<1%';
        return Math.round(p) + '%';
    }

    function colFilterMap() {
        const map = {};
        Object.keys(colFilterState).forEach((key) => {
            const val = String(colFilterState[key] || '').trim();
            if (val) map[key] = val;
        });
        if (Object.keys(map).length) return map;
        document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => {
            const key = inp.getAttribute('data-key');
            const val = String(inp.value || '').trim();
            if (key && val) map[key] = val;
        });
        return map;
    }

    function rowField(r, key) {
        switch (key) {
            case 'po': return String(r.purchase_order || '');
            case 'date': return [fmtDate(r.date), r.date].join(' ');
            case 'vendor_code': return String(r.supplier || '');
            case 'vendor_name': return String(r.supplier_full_name || '');
            case 'brand_name': return String(r.brand_name || '');
            case 'material': return String(r._mat_label || '');
            case 'items': return [r.items_count, qty(r.items_count)].join(' ');
            case 'passed': return [r._passed, qty(r._passed)].join(' ');
            case 'exceptions': return [r._exceptions, qty(r._exceptions)].join(' ');
            case 'pending': return [r._pending, qty(r._pending)].join(' ');
            case 'esign': return String(r.esign_status || '');
            case 'remarks': return String(r.remarks || '');
            case 'mail_status': return [r.sent_receive_status, r.mail_from, r.mail_to, r.mail_email, r.mail_status].filter(Boolean).join(' ');
            case 'status': {
                const s = String(r._validation_status || '');
                return s + ' ' + String(r.validation_status || '') + ' ' + String(r.workflow_stage || '')
                    + (s === 'PASSED' ? ' pass ok validated' : '')
                    + (s === 'NEED_TO_REVIEW' ? ' review need needs' : '')
                    + (s === 'MAPPING_REQUIRED' || s === 'MISSING_REQUIRED' ? ' mapping required missing pending' : '');
            }
            case 'last': return String(r._last_validated || '');
            case 'po_currency': return String(r.po_currency || 'INR');
            default: {
                const v = r[key];
                return [v, qty(v), money2(v), num(v, 4)].join(' ');
            }
        }
    }

    function updateFilterUi() {
        const q = String($('pcbQuickSearch')?.value || '').trim();
        const colMap = colFilterMap();
        const activeCols = Object.keys(colMap).length;
        const clearBtn = $('pcbQuickSearchClear');
        if (clearBtn) clearBtn.style.display = q ? '' : 'none';
        const badge = $('pcbActiveFilterBadge');
        if (badge) {
            badge.style.display = activeCols ? '' : 'none';
            badge.textContent = String(activeCols);
        }
        const clearAll = $('pcbClearAllFilters');
        if (clearAll) clearAll.style.display = (q || activeCols) ? '' : 'none';
        document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => {
            inp.classList.toggle('has-val', !!String(inp.value || '').trim());
        });
        const count = $('pcbCount');
        if (count) count.classList.toggle('is-filtered', !!(q || activeCols));
    }

    function extractQuickSearchPo() {
        const raw = String($('pcbQuickSearch')?.value || '').trim();
        if (!raw) return '';
        // Pure digits (or PO-like) → server PO lookup
        const digits = raw.replace(/\D/g, '');
        if (digits.length >= 6 && digits.length <= 10 && /^\d+$/.test(raw.replace(/[\s#-]/g, ''))) {
            return digits;
        }
        return '';
    }

    let quickSearchTimer = null;
    let lastServerPoSearch = '';

    function scheduleQuickSearchFetch() {
        const po = extractQuickSearchPo();
        clearTimeout(quickSearchTimer);
        // Text search (vendor/brand) stays client-side on current page
        if (!po) {
            if (lastServerPoSearch) {
                lastServerPoSearch = '';
                quickSearchTimer = setTimeout(() => {
                    void fetchPos(null, { page: 1, resetCache: true, po: '' });
                }, 280);
            }
            return;
        }
        if (po === lastServerPoSearch && allRows.some((r) => {
            const rowPo = String(r.purchase_order || '').replace(/\D/g, '').replace(/^0+/, '');
            const needle = po.replace(/^0+/, '');
            return rowPo === needle || String(r.purchase_order || '').includes(po);
        })) {
            return;
        }
        quickSearchTimer = setTimeout(() => {
            lastServerPoSearch = po;
            void fetchPos(null, { page: 1, resetCache: true, po });
        }, 350);
    }

    function applyFilter() {
        const q = String($('pcbQuickSearch')?.value || '').trim().toLowerCase();
        const serverPo = extractQuickSearchPo();
        const vs = String($('pcbValStatus')?.value || 'all');
        const cols = colFilterMap();
        filtered = allRows.filter((r) => {
            if (vs !== 'all') {
                const st = String(r._validation_status || '');
                const needle = String(vs).toUpperCase().replace(/\s+/g, '_');
                const aliases = {
                    PASSED: ['PASSED'],
                    NEED_TO_REVIEW: ['NEED_TO_REVIEW', 'NEEDS_REVIEW'],
                    MAPPING_REQUIRED: ['MAPPING_REQUIRED', 'MISSING_REQUIRED', 'PENDING', 'EXCEPTION', 'UNMATCHED'],
                    MISSING_REQUIRED: ['MAPPING_REQUIRED', 'MISSING_REQUIRED'],
                    EXCEPTION: ['NEED_TO_REVIEW', 'NEEDS_REVIEW'],
                    PENDING: ['MAPPING_REQUIRED', 'MISSING_REQUIRED'],
                    UNMATCHED: ['MAPPING_REQUIRED', 'MISSING_REQUIRED'],
                };
                const ok = (aliases[needle] || [needle]).includes(st);
                if (!ok) return false;
            }
            // Server already filtered exact PO — keep light client check
            if (q && !serverPo) {
                const hay = [
                    r.purchase_order, r.supplier, r.supplier_full_name, r.brand_name, r._mat_label,
                    r._validation_status, r._validated_by, r.esign_status, r.remarks, r.sent_receive_status, r.mail_to, r.mail_email,
                ].join(' ').toLowerCase();
                if (!hay.includes(q)) return false;
            } else if (serverPo) {
                const rowPo = String(r.purchase_order || '').replace(/\D/g, '');
                const normRow = rowPo.replace(/^0+/, '') || rowPo;
                const normQ = serverPo.replace(/^0+/, '') || serverPo;
                if (normRow !== normQ && !rowPo.includes(serverPo)) return false;
            }
            for (const [key, val] of Object.entries(cols)) {
                const needle = String(val || '').toLowerCase().replace(/,/g, '').trim();
                if (!needle) continue;
                const hay = String(rowField(r, key) || '').toLowerCase();
                if (!(hay.includes(String(val).toLowerCase()) || hay.replace(/,/g, '').includes(needle))) return false;
            }
            return true;
        });
        applySort();
        // Server page stays as-is; current page rows are already one API page.
        updateFilterUi();
        updateSortUi();
        renderTable();
        fillKpis();
    }

    function sortValue(r, key) {
        switch (key) {
            case 'po': return String(r.purchase_order || '');
            case 'date': return String(r.date || ''); // ISO yyyy-mm-dd sorts correctly
            case 'vendor_code': return String(r.supplier || '');
            case 'vendor_name': return String(r.supplier_full_name || '');
            case 'brand_name': return String(r.brand_name || '');
            case 'material': return String(r._mat_label || '');
            case 'items': return Number(r.items_count || 0);
            case 'passed': return Number(r._passed || 0);
            case 'exceptions': return Number(r._exceptions || 0);
            case 'pending': return Number(r._pending || 0);
            case 'esign': return String(r.esign_status || '');
            case 'remarks': return String(r.remarks || '');
            case 'mail_status': return [r.mail_to, r.mail_email, r.sent_receive_status].filter(Boolean).join(' ');
            case 'status': return String(r._validation_status || '');
            case 'last': return String(r._last_validated || '');
            case 'po_currency': return String(r.po_currency || '');
            default: return NUMERIC_SORT_KEYS.has(key) ? Number(r[key] || 0) : String(r[key] || '');
        }
    }

    function applySort() {
        if (!currentSort.key || !currentSort.dir) return;
        const key = currentSort.key;
        const dir = currentSort.dir === 'desc' ? -1 : 1;
        const numeric = NUMERIC_SORT_KEYS.has(key);
        filtered.sort((a, b) => {
            const av = sortValue(a, key);
            const bv = sortValue(b, key);
            if (numeric) {
                const an = Number(av); const bn = Number(bv);
                if (an === bn) return 0;
                return an > bn ? dir : -dir;
            }
            const as = String(av).toLowerCase();
            const bs = String(bv).toLowerCase();
            if (as === bs) return 0;
            return as > bs ? dir : -dir;
        });
    }

    function updateSortUi() {
        document.querySelectorAll('#pcbTable th.is-sortable').forEach((th) => {
            const key = th.getAttribute('data-sort');
            const asc = currentSort.key === key && currentSort.dir === 'asc';
            const desc = currentSort.key === key && currentSort.dir === 'desc';
            th.classList.toggle('is-sorted-asc', asc);
            th.classList.toggle('is-sorted-desc', desc);
            th.setAttribute('aria-sort', asc ? 'ascending' : (desc ? 'descending' : 'none'));
        });
    }

    function toggleSort(key) {
        if (!key) return;
        if (currentSort.key !== key) currentSort = { key, dir: 'asc' };
        else if (currentSort.dir === 'asc') currentSort = { key, dir: 'desc' };
        else currentSort = { key: null, dir: null };
        applySort();
        page = 1;
        updateSortUi();
        renderTable();
    }

    function initTablePreferences({
        scriptId,
        tableId,
        menuId,
        toggleBtnId,
        dropdownId,
        statusId,
        colAttr = 'data-col-key',
    }) {
        const scriptNode = document.getElementById(scriptId);
        if (!scriptNode) return null;
        let config = null;
        try { config = JSON.parse(scriptNode.textContent || 'null'); } catch (e) { return null; }
        if (!config || !Array.isArray(config.columns)) return null;

        const table = document.getElementById(tableId);
        if (!table) return null;
        const menu = document.getElementById(menuId);
        const dropdown = document.getElementById(dropdownId);
        const toggleBtn = document.getElementById(toggleBtnId);
        const statusEl = document.getElementById(statusId);

        const columns = config.columns;
        const defaultOrder = columns.map((c) => c.id);
        const isLocked = (id) => columns.some((c) => c.id === id && c.locked);
        const labels = Object.fromEntries(columns.map((c) => [c.id, c.label]));

        let currentState = {
            columnOrder: [...defaultOrder],
            hiddenColumns: Array.isArray(config.defaultHidden) ? [...config.defaultHidden] : [],
        };
        let appliedOrder = [...defaultOrder];

        let styleEl = document.getElementById(tableId + 'ColStyle');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = tableId + 'ColStyle';
            document.head.appendChild(styleEl);
        }

        function applyHidden(hidden) {
            if (!hidden || !hidden.length) {
                styleEl.textContent = '';
            } else {
                styleEl.textContent = hidden.map((id) =>
                    `#${tableId} [${colAttr}="${id}"], #${tableId} [data-icol="${id}"] { display: none !important; }`
                ).join('\n');
            }
            if (toggleBtn) toggleBtn.classList.toggle('is-active', !!(hidden && hidden.length > 0));
            if (tableId === 'pcbItemTable' && typeof syncItemGroupHeaders === 'function') {
                syncItemGroupHeaders();
            }
        }

        function applyOrder(order) {
            appliedOrder = [...order];
            const rows = Array.from(table.querySelectorAll('thead tr, tbody tr')).filter(
                (tr) =>
                    !tr.classList.contains('pcb-empty') &&
                    !tr.classList.contains('pcw-empty') &&
                    !tr.classList.contains('pcw-group-row')
            );
            rows.forEach((tr) => {
                const byId = {};
                Array.from(tr.children).forEach((cell) => {
                    const key = cell.getAttribute(colAttr) || cell.getAttribute('data-icol') || cell.getAttribute('data-col-key');
                    if (key) byId[key] = cell;
                });
                const frag = document.createDocumentFragment();
                order.forEach((id) => {
                    if (byId[id]) frag.appendChild(byId[id]);
                });
                tr.appendChild(frag);
            });
        }

        function renderStatus(state) {
            if (!statusEl) return;
            let html = '';
            let kind = '';
            if (state && state.error) {
                kind = 'error';
                html = `<span class="material-icons-round">error_outline</span><span>${esc(state.error.message)}</span>`
                    + (state.error.retry ? '<button type="button" data-col-retry>Retry</button>' : '');
            } else if (state && state.saving) {
                kind = 'saving';
                html = '<span class="rpt-col-save-spin" aria-hidden="true"></span><span>Saving columns…</span>';
            } else if (state && state.saved) {
                kind = 'saved';
                html = '<span class="material-icons-round">check_circle</span><span>Saved</span>';
            }
            statusEl.hidden = kind === '';
            statusEl.className = 'rpt-col-save' + (kind ? ' is-' + kind : '');
            statusEl.title = (state && state.error) ? state.error.message : '';
            if (statusEl.innerHTML !== html) statusEl.innerHTML = html;
        }

        function buildMenu() {
            if (!menu) return;
            const order = currentState.columnOrder;
            const hidden = new Set(currentState.hiddenColumns);
            const movable = order.filter((id) => !isLocked(id));
            const visibleCount = order.length - hidden.size;

            menu.innerHTML = `
                <div class="rpt-colmgr-head">
                    <span class="rpt-cat-dd-header">Manage columns</span>
                    <span class="rpt-colmgr-count">${visibleCount} of ${order.length} shown</span>
                </div>
                <p class="rpt-colmgr-hint">Drag or use arrows to reorder. Saved to your account automatically.</p>
                <ul class="rpt-colmgr-list">
                    ${order.map((id) => {
                        const label = esc(labels[id] || id);
                        if (isLocked(id)) {
                            return `<li class="rpt-colmgr-item is-locked" data-col-item="${id}" title="Always shown first">
                                <span class="material-icons-round rpt-colmgr-grip">lock</span>
                                <span class="rpt-colmgr-label"><input type="checkbox" checked disabled><span>${label}</span></span>
                            </li>`;
                        }
                        const pos = movable.indexOf(id);
                        return `<li class="rpt-colmgr-item${hidden.has(id) ? ' is-hidden' : ''}" data-col-item="${id}" draggable="true">
                            <span class="material-icons-round rpt-colmgr-grip" title="Drag to reorder">drag_indicator</span>
                            <label class="rpt-colmgr-label"><input type="checkbox" data-col-toggle="${id}"${hidden.has(id) ? '' : ' checked'}><span>${label}</span></label>
                            <span class="rpt-colmgr-move">
                                <button type="button" data-col-move="-1" aria-label="Move ${label} up"${pos === 0 ? ' disabled' : ''}><span class="material-icons-round">keyboard_arrow_up</span></button>
                                <button type="button" data-col-move="1" aria-label="Move ${label} down"${pos === movable.length - 1 ? ' disabled' : ''}><span class="material-icons-round">keyboard_arrow_down</span></button>
                            </span>
                        </li>`;
                    }).join('')}
                </ul>
                <div class="rpt-cat-dd-divider"></div>
                <button type="button" class="rpt-cat-dd-item" data-col-reset>
                    <span class="material-icons-round">restart_alt</span>
                    <span class="rpt-cat-dd-item-text">Reset to default</span>
                </button>
            `;
        }

        function moveColumn(from, target) {
            if (from === target || isLocked(from)) return;
            const order = currentState.columnOrder.filter((id) => id !== from);
            const fromPos = currentState.columnOrder.indexOf(from);
            const toPos = order.indexOf(target);
            const insertAt = isLocked(target) ? toPos + 1 : (fromPos <= toPos ? toPos + 1 : toPos);
            order.splice(insertAt, 0, from);
            if (prefsStore) {
                prefsStore.setPreferences({ columnOrder: order, hiddenColumns: currentState.hiddenColumns });
            } else {
                currentState.columnOrder = order;
                applyOrder(order);
                buildMenu();
            }
        }

        function bindDrag(root, itemSelector, idOf) {
            let dragFrom = null;
            const clearMarks = () => root.querySelectorAll('.is-drag-over, .is-dragging').forEach((el) => el.classList.remove('is-drag-over', 'is-dragging'));
            root.addEventListener('dragstart', (e) => {
                const item = e.target.closest?.(itemSelector);
                const id = item ? idOf(item) : null;
                if (!id || isLocked(id)) return;
                dragFrom = id;
                item.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', id); } catch (err) {}
                }
            });
            root.addEventListener('dragover', (e) => {
                const item = e.target.closest?.(itemSelector);
                if (dragFrom === null || !item) return;
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                root.querySelectorAll('.is-drag-over').forEach((el) => { if (el !== item) el.classList.remove('is-drag-over'); });
                if (idOf(item) !== dragFrom) item.classList.add('is-drag-over');
            });
            root.addEventListener('drop', (e) => {
                const item = e.target.closest?.(itemSelector);
                if (dragFrom === null || !item) return;
                e.preventDefault();
                const from = dragFrom;
                dragFrom = null;
                clearMarks();
                moveColumn(from, idOf(item));
            });
            root.addEventListener('dragend', () => { dragFrom = null; clearMarks(); });
        }

        if (menu) {
            bindDrag(menu, '[data-col-item]', (li) => li.getAttribute('data-col-item'));
            menu.addEventListener('change', (e) => {
                const cb = e.target.closest('input[data-col-toggle]');
                if (!cb) return;
                const id = cb.getAttribute('data-col-toggle');
                const hidden = currentState.hiddenColumns.filter((h) => h !== id);
                if (!cb.checked) hidden.push(id);
                if (prefsStore) {
                    prefsStore.setPreferences({ columnOrder: currentState.columnOrder, hiddenColumns: hidden });
                } else {
                    currentState.hiddenColumns = hidden;
                    applyHidden(hidden);
                    buildMenu();
                }
            });
            menu.addEventListener('click', (e) => {
                e.stopPropagation();
                if (e.target.closest('[data-col-reset]')) {
                    if (prefsStore) {
                        prefsStore.reset();
                    } else {
                        currentState.columnOrder = [...defaultOrder];
                        currentState.hiddenColumns = Array.isArray(config.defaultHidden) ? [...config.defaultHidden] : [];
                        applyOrder(currentState.columnOrder);
                        applyHidden(currentState.hiddenColumns);
                        buildMenu();
                    }
                    return;
                }
                const moveBtn = e.target.closest('[data-col-move]');
                if (!moveBtn) return;
                const id = moveBtn.closest('[data-col-item]').getAttribute('data-col-item');
                const dir = parseInt(moveBtn.getAttribute('data-col-move'), 10);
                const movable = currentState.columnOrder.filter((c) => !isLocked(c));
                const neighbour = movable[movable.indexOf(id) + dir];
                if (!neighbour) return;
                moveColumn(id, neighbour);
            });
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdown?.classList.toggle('is-open');
                const open = dropdown?.classList.contains('is-open');
                toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) buildMenu();
            });
        }
        document.addEventListener('click', (e) => {
            if (dropdown && !dropdown.contains(e.target)) {
                dropdown.classList.remove('is-open');
                toggleBtn?.setAttribute('aria-expanded', 'false');
            }
        });

        if (statusEl) {
            statusEl.addEventListener('click', (e) => {
                if (e.target.closest('[data-col-retry]')) currentState?.error?.retry?.();
            });
        }

        const theadFirstRow = table.querySelector('thead tr.pcb-item-header-row, thead tr.pcb-header-row, thead tr.rpt-header-row')
            || table.querySelector('thead tr:not(.pcw-group-row)');
        if (theadFirstRow) {
            Array.from(theadFirstRow.children).forEach((th) => {
                const key = th.getAttribute(colAttr) || th.getAttribute('data-icol') || th.getAttribute('data-col-key');
                if (isLocked(key)) return;
                th.setAttribute('draggable', 'true');
                th.title = (th.title ? th.title + ' — ' : '') + 'drag to move column';
            });
            bindDrag(theadFirstRow, 'th', (th) => th.getAttribute(colAttr) || th.getAttribute('data-icol') || th.getAttribute('data-col-key'));
        }

        function onPreferenceChange(next) {
            const prev = currentState;
            currentState = next;
            const orderKey = next.columnOrder.join(',');
            const hiddenKey = next.hiddenColumns.join(',');
            const prevOrderKey = prev?.columnOrder?.join(',');
            const prevHiddenKey = prev?.hiddenColumns?.join(',');

            if (orderKey !== prevOrderKey || orderKey !== appliedOrder.join(',')) {
                applyOrder(next.columnOrder);
            }
            if (hiddenKey !== prevHiddenKey) {
                applyHidden(next.hiddenColumns);
            }
            buildMenu();
            renderStatus(next);
        }

        const prefsStore = (typeof window.rptCreateTablePreferences === 'function')
            ? window.rptCreateTablePreferences(config, onPreferenceChange)
            : null;

        if (!prefsStore) {
            applyOrder(currentState.columnOrder);
            applyHidden(currentState.hiddenColumns);
            buildMenu();
        }

        return {
            getOrder() { return currentState.columnOrder; },
            getHidden() { return new Set(currentState.hiddenColumns); },
            refresh() {
                applyOrder(currentState.columnOrder);
                applyHidden(currentState.hiddenColumns);
            },
        };
    }

    function applyColumnVisibility() {
        if (pcbTablePrefManager) {
            pcbTablePrefManager.refresh();
            return;
        }
        const table = $('pcbTable');
        if (!table) return;
        table.querySelectorAll('[data-col-key]').forEach((cell) => {
            const key = cell.getAttribute('data-col-key');
            const hide = hiddenCols.has(key);
            cell.style.display = hide ? 'none' : '';
        });
    }

    function buildColumnMenu() {
        if (pcbTablePrefManager) return;
        const menu = $('pcbColumnToggleMenu');
        if (!menu) return;
        const visible = COL_DEFS.filter((c) => !c.locked && !hiddenCols.has(c.key)).length;
        const optional = COL_DEFS.filter((c) => !c.locked).length;
        menu.innerHTML =
            `<div class="pcb-col-menu-head">
                <div>
                    <strong>Columns</strong>
                    <small>${visible} of ${optional} visible</small>
                </div>
                <div class="pcb-col-menu-actions">
                    <button type="button" class="pcb-col-menu-btn" data-pcb-col-act="all">Show all</button>
                    <button type="button" class="pcb-col-menu-btn" data-pcb-col-act="reset">Reset</button>
                </div>
            </div>
            <div class="pcb-col-menu-list">` +
            COL_DEFS.map((c) => {
                const checked = !hiddenCols.has(c.key);
                const disabled = c.locked ? ' disabled' : '';
                return `<label class="rpt-cat-dd-item pcb-col-menu-item${c.locked ? ' is-locked' : ''}">
                    <input type="checkbox" data-col-key="${esc(c.key)}" ${checked ? 'checked' : ''}${disabled}>
                    <span>${esc(c.label)}</span>
                    ${c.locked ? '<em>Fixed</em>' : ''}
                </label>`;
            }).join('') +
            `</div>`;
    }

    function applyListStickyMode(enabled) {
        const wrap = $('pcbTableWrap');
        const btn = $('pcbToggleStickyScroll');
        const label = $('pcbStickyScrollLabel');
        if (!wrap) return;
        wrap.classList.toggle('is-scroll-body', !!enabled);
        wrap.classList.toggle('is-expanded', !enabled);
        if (enabled) {
            wrap.style.maxHeight = '';
            wrap.style.height = '';
            wrap.style.overflow = '';
        } else {
            wrap.style.maxHeight = 'none';
            wrap.style.height = 'auto';
            wrap.style.overflow = 'visible';
        }
        btn?.classList.toggle('is-active', !!enabled);
        if (label) label.textContent = enabled ? 'Scroll Body Only' : 'Expand Table';
    }

    function fillKpis() {
        const total = serverTotal || filtered.length;
        let passed = 0;
        let review = 0;
        let missing = 0;
        let value = 0;

        // Row badges use costing validate when cached; KPIs follow the same source.
        // Workflow listTotals are only used before any page validate has run.
        const source = filtered.length ? filtered : allRows;
        const anyCosting = source.some((r) => {
            const po = String(r.purchase_order || '');
            return !!(po && itemCache[po] && Array.isArray(itemCache[po].records));
        });

        if (anyCosting) {
            source.forEach((r) => {
                const st = String(r._validation_status || workflowListStatus(r));
                if (st === 'PASSED') passed++;
                else if (st === 'NEED_TO_REVIEW' || st === 'NEEDS_REVIEW') review++;
                else missing++;
            });
            if (total > source.length) {
                missing += Math.max(0, total - source.length);
            }
        } else {
            const hasWorkflowTotals = listTotals
                && (listTotals.validation_enriched
                    || listTotals.status_completed != null
                    || listTotals.status_in_review != null
                    || listTotals.status_not_started != null);

            if (hasWorkflowTotals) {
                passed = Number(listTotals.status_completed || listTotals.status_validated || 0) || 0;
                review = (Number(listTotals.status_in_review || 0) || 0)
                    + (Number(listTotals.status_rejected || 0) || 0);
                missing = (Number(listTotals.status_not_started || 0) || 0)
                    + (Number(listTotals.status_pending || 0) || 0);
                const accounted = passed + review + missing;
                if (accounted === 0 && total > 0) {
                    missing = total;
                } else if (accounted < total) {
                    missing += (total - accounted);
                }
            } else {
                source.forEach((r) => {
                    const st = String(r._validation_status || workflowListStatus(r));
                    if (st === 'PASSED') passed++;
                    else if (st === 'NEED_TO_REVIEW') review++;
                    else missing++;
                });
                if (!filtered.length && total > source.length) {
                    missing += Math.max(0, total - source.length);
                }
            }
        }

        value = Number(listTotals?.total_value ?? 0);
        if (!listTotals) {
            filtered.forEach((r) => { value += Number(r.total_value || 0); });
        }
        const barPct = (n) => (total ? Math.min(100, Math.round((n / total) * 100)) : 0);
        if ($('pcbKpiTotal')) $('pcbKpiTotal').textContent = qty(total);
        if ($('pcbKpiPassed')) $('pcbKpiPassed').textContent = qty(passed);
        if ($('pcbKpiExceptions')) $('pcbKpiExceptions').textContent = qty(review);
        if ($('pcbKpiPending')) $('pcbKpiPending').textContent = qty(missing);
        if ($('pcbKpiPassedPct')) $('pcbKpiPassedPct').textContent = `Valid (${pctLabel(passed, total)})`;
        if ($('pcbKpiExcPct')) {
            $('pcbKpiExcPct').innerHTML = `Need to Review (${pctLabel(review, total)}) <span class="material-icons-round pcb-kpi-help" aria-hidden="true">info</span>`;
        }
        if ($('pcbKpiPendPct')) $('pcbKpiPendPct').textContent = `Mapping Required (${pctLabel(missing, total)})`;
        if ($('pcbKpiValue')) $('pcbKpiValue').textContent = '₹ ' + money(value);
        if ($('pcbKpiPassedBar')) $('pcbKpiPassedBar').style.width = barPct(passed) + '%';
        if ($('pcbKpiExcBar')) $('pcbKpiExcBar').style.width = barPct(review) + '%';
        if ($('pcbKpiPendBar')) $('pcbKpiPendBar').style.width = barPct(missing) + '%';
        if ($('pcbKpiTotalBar')) $('pcbKpiTotalBar').style.width = total ? '100%' : '0%';

        document.querySelectorAll('.pcb-kpi').forEach((card) => {
            card.classList.remove('is-pulse');
            // force reflow so animation can replay on data refresh
            void card.offsetWidth;
            card.classList.add('is-pulse');
        });

        const showing = filtered.length;
        if ($('pcbCount')) $('pcbCount').textContent = total
            ? `Showing ${showing} of ${total} purchase orders`
            : 'No purchase orders';

        let poItems = 0;
        let poMats = 0;
        let poSoQty = 0;
        let poValue = 0;
        let zItems = 0;
        let zMats = 0;
        let zSoQty = 0;
        let zValue = 0;
        filtered.forEach((r) => {
            const hasLines = Number(r._po_items || 0) > 0;
            poItems += hasLines ? Number(r._po_items) : Number(r.items_count || 0);
            poMats += Number(r._po_materials || 0);
            poSoQty += Number(r.tot_poqty || r.so_qty || 0);
            // Prefer PORATE × TOT_POQTY when available (same as workbench)
            const rate = Number(r.porate ?? r.po_unit_cost ?? 0) || 0;
            const oq = Number(r.tot_poqty || 0) || 0;
            poValue += (rate > 0 && oq > 0)
                ? (rate * oq)
                : Number(r.totvalue || r.total_value || 0);
            zItems += Number(r._z_items || 0);
            zMats += Number(r._z_materials || 0);
            zSoQty += Number(r._z_so_qty || 0);
            zValue += Number(r._z_net_value || 0);
        });
        const setCmp = (id, text) => {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        };
        setCmp('pcbCmpPoItems', qty(poItems));
        setCmp('pcbCmpPoMaterials', qty(poMats));
        setCmp('pcbCmpPoQty', qty(poSoQty));
        setCmp('pcbCmpPoValue', money(poValue || value));
        setCmp('pcbCmpZcItems', qty(zItems));
        setCmp('pcbCmpZcMaterials', qty(zMats));
        setCmp('pcbCmpZcQty', qty(zSoQty));
        setCmp('pcbCmpZcValue', money(zValue));
    }

    function pageRows() {
        // API returns the current page only — do not re-slice client-side.
        return filtered;
    }

    function renderTable() {
        const tbody = $('pcbTableBody');
        const cards = $('pcbCards');
        if (!tbody) return;
        const rows = pageRows();
        if (!rows.length) {
            tbody.innerHTML = `<tr class="pcb-empty"><td colspan="${COL_DEFS.length}">No purchase orders match the filters.</td></tr>`;
            if (cards) cards.innerHTML = '<div class="pcb-card-empty">No purchase orders match the filters.</div>';
            $('pcbPager') && ($('pcbPager').hidden = true);
            fillKpis();
            return;
        }

        const startIdx = (page - 1) * pageSize;
        tbody.innerHTML = rows.map((r, i) => {
            const active = activePo === r.purchase_order ? ' is-active' : '';
            const sno = startIdx + i + 1;
            return `<tr class="pcb-row${active}" data-po="${esc(r.purchase_order)}">${
                COL_DEFS.map((c) => {
                    const numCls = NUMERIC_SORT_KEYS.has(c.key) ? ' num' : '';
                    const extra = c.key === 'check' ? ' pcb-check' : (c.key === 'po' ? ' pcb-sticky-po' : (c.key === 'vendor_name' ? ' pcb-vendor' : ''));
                    return `<td class="${(numCls + extra).trim()}" data-col-key="${esc(c.key)}">${formatCol(c.key, r, sno)}</td>`;
                }).join('')
            }</tr>`;
        }).join('');

        if (cards) {
            cards.innerHTML = rows.map((r) => {
                const active = activePo === r.purchase_order ? ' is-active' : '';
                return `<article class="pcb-card${active}" data-po="${esc(r.purchase_order)}">
                    <div class="pcb-card-top">
                        ${formatCol('po', r)}
                        ${statusBadge(r._validation_status)}
                    </div>
                    <div class="pcb-card-meta">
                        <span>${fmtDate(r.date)}</span>
                        <span class="pcb-mat-chip">${dash(r._mat_label)}</span>
                        <span title="Brand Name">${dash(r.brand_name)}</span>
                    </div>
                    <div class="pcb-card-vendor" title="${esc(r.supplier_full_name)}">${dash(r.supplier)} · ${dash(r.supplier_full_name)}</div>
                    <div class="pcb-card-stats">
                        <span><b>${qty(r.items_count)}</b> items</span>
                        <span><b>₹ ${money(r.total_value)}</b></span>
                        <span class="is-pass">${qty(r._passed)} pass</span>
                        <span class="is-fail">${qty(r._exceptions)} unmatched</span>
                    </div>
                    <div class="pcb-card-meta">
                        ${formatCol('esign', r)}
                        ${formatCol('mail_status', r)}
                    </div>
                    <div class="pcb-card-meta">${formatCol('remarks', r)}</div>
                    <div class="pcb-card-actions">
                        <button type="button" class="pcb-pdf-btn" data-po="${esc(r.purchase_order)}">
                            <span class="material-icons-round">picture_as_pdf</span>
                            View PDF
                        </button>
                    </div>
                </article>`;
            }).join('');
        }

        const pages = Math.max(1, serverPageCount || 1);
        if ($('pcbPager')) {
            $('pcbPager').hidden = pages <= 1;
            if ($('pcbPageInfo')) $('pcbPageInfo').textContent = `Page ${page} of ${pages}`;
            if ($('pcbPrev')) $('pcbPrev').disabled = page <= 1 || runtimeRunning || listFetching;
            if ($('pcbNext')) $('pcbNext').disabled = page >= pages || runtimeRunning || listFetching;
        }
        applyColumnVisibility();
        updateSortUi();
        fillKpis();
    }

    function presetDates() {
        return cfg.presetDates || {};
    }

    function ymdDaysAgo(days) {
        const d = new Date();
        d.setDate(d.getDate() - days);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    let lastNonCustomRange = 'today';

    function setCustomDateError(message) {
        const modalError = $('poCustomDateError');
        if (!modalError) return;
        if (!message) {
            modalError.hidden = true;
            modalError.textContent = '';
            return;
        }
        modalError.textContent = message;
        modalError.hidden = false;
    }

    function openCustomDateModal() {
        const modal = $('poCustomDateModal');
        if (!modal) return;
        const fromVal = $('pcbFrom')?.value || ymdDaysAgo(7);
        const toVal = $('pcbTo')?.value || ymdDaysAgo(0);
        if ($('poCustomModalFrom')) $('poCustomModalFrom').value = fromVal;
        if ($('poCustomModalTo')) $('poCustomModalTo').value = toVal;
        setCustomDateError('');
        modal.hidden = false;
        document.body.classList.add('po-modal-open');
        $('poCustomModalFrom')?.focus();
    }

    function closeCustomDateModal({ revertSelect = false } = {}) {
        const modal = $('poCustomDateModal');
        if (modal) modal.hidden = true;
        document.body.classList.remove('po-modal-open');
        if (revertSelect && $('pcbRange')) {
            $('pcbRange').value = lastNonCustomRange || 'today';
            applyRangePreset($('pcbRange').value, { fillDates: true });
        }
    }

    function applyCustomDatesAndLoad() {
        const fVal = $('poCustomModalFrom')?.value.trim() || '';
        const tVal = $('poCustomModalTo')?.value.trim() || '';
        if (!fVal || !tVal) {
            setCustomDateError(!fVal ? 'Select a From date.' : 'Select a To date.');
            (!fVal ? $('poCustomModalFrom') : $('poCustomModalTo'))?.focus();
            return;
        }
        if (fVal > tVal) {
            setCustomDateError('To date must be on or after From date.');
            $('poCustomModalTo')?.focus();
            return;
        }
        const maxDays = Number($('poCustomDateModal')?.getAttribute('data-max-days')) || 100;
        const minYmd = ymdDaysAgo(maxDays);
        if (fVal < minYmd) {
            setCustomDateError(`Select dates within the last ${maxDays} days.`);
            $('poCustomModalFrom')?.focus();
            return;
        }
        if ($('pcbFrom')) $('pcbFrom').value = fVal;
        if ($('pcbTo')) $('pcbTo').value = tVal;
        lastNonCustomRange = 'custom';
        closeCustomDateModal({ revertSelect: false });
        void fetchPos();
    }

    function applyRangePreset(key, { fillDates = true } = {}) {
        const isCustom = key === 'custom';
        if (!isCustom && fillDates) {
            const dates = presetDates()[key];
            if (dates) {
                if ($('pcbFrom')) $('pcbFrom').value = dates.from || '';
                if ($('pcbTo')) $('pcbTo').value = dates.to || '';
            }
        }
    }

    function currentRange() {
        return 'all';
    }

    async function fetchPos(e, opts = {}) {
        e?.preventDefault?.();
        if (listFetching) return;
        // No date filter — list all POs for approved/material selection
        const range = 'all';
        const from = '';
        const to = '';
        if ($('pcbRange')) $('pcbRange').value = 'all';
        if ($('pcbFrom')) $('pcbFrom').value = '';
        if ($('pcbTo')) $('pcbTo').value = '';
        const supplier = $('pcbSupplier')?.value || '';
        const mat = selectedMat();
        const approvedStatus = selectedApprovedStatus();
        const brand = selectedBrand();
        const resetCache = opts.resetCache === true || (opts.resetCache !== false && opts.page == null);
        const pageToLoad = Math.max(1, Number(opts.page != null ? opts.page : (resetCache ? 1 : page)) || 1);
        const searchPo = String(opts.po != null ? opts.po : extractQuickSearchPo() || '').replace(/\D/g, '');
        const params = new URLSearchParams({
            list: '1',
            range,
            from,
            to,
            supplier,
            mat_cat: mat,
            view: 'costing',
            page: String(pageToLoad),
            page_size: String(pageSize),
            _: String(Date.now()),
        });
        // Exact PO search ignores Approved/Unapproved so the PO can be found
        if (!searchPo && (approvedStatus === 'Approved' || approvedStatus === 'NotApproved')) {
            params.set('approved_status', approvedStatus);
        }
        if (brand) params.set('brand', brand);
        if (searchPo) params.set('po', searchPo);
        stopRuntimeValidation();
        listFetching = true;
        showSpinner(true);
        setProgress(false);
        if ($('pcbPrev')) $('pcbPrev').disabled = true;
        if ($('pcbNext')) $('pcbNext').disabled = true;
        try {
            const res = await fetch(`${cfg.dataUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const raw = await res.text();
            let json = null;
            try {
                json = raw ? JSON.parse(raw) : null;
            } catch (parseErr) {
                throw new Error(
                    raw
                        ? 'Server returned an invalid response while loading POs. Please retry.'
                        : 'Server returned an empty response while loading POs (timeout or overload). Please retry.'
                );
            }
            if (!json || typeof json !== 'object') {
                throw new Error('Server returned an empty response while loading POs. Please retry.');
            }
            if (!res.ok || json.success === false) throw new Error(json.message || json.error || 'Fetch failed');
            const data = json.data || {};
            const records = Array.isArray(data.records) ? data.records : [];
            if (resetCache) itemCache = {};
            page = Number(data.page || pageToLoad) || 1;
            serverTotal = Number(data.total || records.length) || 0;
            serverPageCount = Number(data.page_count || Math.max(1, Math.ceil(serverTotal / pageSize))) || 1;
            listTotals = data.totals && typeof data.totals === 'object' ? data.totals : null;
            if (Array.isArray(data.suppliers) && data.suppliers.length) {
                // Prefer full supplier list from API (all pages)
                const sel = $('pcbSupplier');
                if (sel) {
                    const current = String(sel.value || '').trim();
                    const keep = sel.querySelector('option[value=""]');
                    sel.innerHTML = '';
                    if (keep) sel.appendChild(keep);
                    else {
                        const opt = document.createElement('option');
                        opt.value = '';
                        opt.textContent = 'All suppliers';
                        sel.appendChild(opt);
                    }
                    data.suppliers.forEach((s) => {
                        const code = String(s.code || s.supplier || '').trim();
                        if (!code) return;
                        const opt = document.createElement('option');
                        opt.value = code;
                        opt.textContent = String(s.name || code);
                        if (code === current) opt.selected = true;
                        sel.appendChild(opt);
                    });
                }
            }
            allRows = records.map((r) => normalizeRow(r));
            if (!Array.isArray(data.suppliers) || !data.suppliers.length) {
                refreshSupplierOptions(allRows);
            }
            activePo = null;
            closeOffcanvas();
            applyFilter();
            updateValidatedBtn();
            const u = new URL(cfg.pageUrl || 'purchase-order', window.location.origin);
            u.searchParams.set('view', 'costing');
            u.searchParams.set('range', range);
            u.searchParams.set('page', String(page));
            if (from) u.searchParams.set('from', from);
            if (to) u.searchParams.set('to', to);
            if (supplier) u.searchParams.set('supplier', supplier);
            if (mat !== 'all') u.searchParams.set('mat_cat', mat);
            if (brand) u.searchParams.set('brand', brand);
            if (approvedStatus === 'Approved' || approvedStatus === 'NotApproved') {
                u.searchParams.set('approved_status', approvedStatus);
            }
            window.history.replaceState({}, '', u.pathname + u.search);

            // Always auto-validate this page for list status/value (SAP compare is read-only).
            // Manual "Validated" button stays gated by canValidate.
            if (autoValidatePage) {
                const pagePos = allRows
                    .map((r) => String(r.purchase_order || ''))
                    .filter((po) => po && !itemCache[po]);
                if (pagePos.length) {
                    enqueueSelectedValidation(pagePos);
                }
            }
        } catch (err) {
            stopRuntimeValidation();
            allRows = [];
            serverTotal = 0;
            serverPageCount = 1;
            listTotals = null;
            applyFilter();
            toast(err.message || 'Unable to fetch POs', 'error');
        } finally {
            listFetching = false;
            // Keep full-screen spinner if page validation is running
            if (!runtimeRunning) showSpinner(false);
            renderTable();
        }
    }

    async function goToPage(nextPage) {
        const target = Math.max(1, Math.min(serverPageCount || 1, Number(nextPage) || 1));
        if (listFetching) return;
        if (target === page && allRows.length) return;
        // Stop in-flight page validation; fetchPos will validate the new page.
        await fetchPos(null, { page: target, resetCache: false });
    }

    function refreshSupplierOptions(rows) {
        const sel = $('pcbSupplier');
        if (!sel) return;
        const current = String(sel.value || '').trim();
        const map = new Map();
        // Keep currently selected vendor even if not in this result set
        if (current) {
            const selectedOpt = sel.querySelector(`option[value="${CSS.escape(current)}"]`);
            const label = selectedOpt ? selectedOpt.textContent : `Supplier ${current}`;
            map.set(current, label.replace(/\s*\(\d+\)\s*$/, '').trim() || `Supplier ${current}`);
        }
        rows.forEach((r) => {
            const c = String(r.supplier || '').trim();
            if (!c) return;
            if (!map.has(c)) map.set(c, String(r.supplier_full_name || `Supplier ${c}`));
        });
        const opts = ['<option value="">All Vendors</option>'];
        [...map.entries()].sort((a, b) => a[1].localeCompare(b[1])).forEach(([c, n]) => {
            opts.push(`<option value="${esc(c)}"${c === current ? ' selected' : ''}>${esc(n)} (${esc(c)})</option>`);
        });
        sel.innerHTML = opts.join('');
        if (current) sel.value = current;
    }

    async function searchPoHeader() {
        const raw = String($('pcbPoSearch')?.value || '').trim();
        if (!raw) {
            if ($('pcbQuickSearch') && $('pcbQuickSearch').value) {
                $('pcbQuickSearch').value = '';
                lastServerPoSearch = '';
                applyFilter();
                void fetchPos(null, { page: 1, resetCache: true, po: '' });
            }
            return;
        }
        const po = raw.replace(/\D/g, '');
        if (!po || !/^\d{1,10}$/.test(po)) {
            toast('Enter a valid PO number (up to 10 digits).', 'error');
            return;
        }
        if ($('pcbQuickSearch')) $('pcbQuickSearch').value = po;
        lastServerPoSearch = po;

        // Always search SAP for this PO (not only current page)
        await fetchPos(null, { page: 1, resetCache: true, po });

        const exact = allRows.find((r) => {
            const rowPo = String(r.purchase_order || '');
            return rowPo === po || rowPo.replace(/^0+/, '') === po.replace(/^0+/, '');
        });
        if (exact) {
            applyFilter();
            void openDrill(exact.purchase_order);
            return;
        }

        applyFilter();
        if (po.length >= 8 && cfg.canWorkbench !== false) {
            toast(`PO ${po} not in list filters — opening workbench.`, 'info');
            openDrill(po);
            return;
        }
        toast(`No purchase order found for "${po}".`, 'info');
    }

    async function validateOne(po) {
        const mat = selectedMat();
        const params = new URLSearchParams({ po, mat_cat: mat, _: String(Date.now()) });
        const res = await fetch(`${cfg.validateUrl}?${params}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const json = await res.json();
        if (!res.ok || json.success === false) throw new Error(json.message || json.error || `Validate failed for ${po}`);
        const data = json.data || {};
        data._validated_at = new Date().toLocaleString('en-IN');
        data._validated_by = currentUserName() || '—';
        itemCache[po] = data;
        return data;
    }

    function syncRow(po) {
        const poKey = String(po || '');
        const idx = allRows.findIndex((r) => String(r.purchase_order || '') === poKey);
        if (idx >= 0) {
            const checked = !!allRows[idx]._checked;
            allRows[idx] = normalizeRow({ ...allRows[idx], _checked: checked });
        }
        const fIdx = filtered.findIndex((r) => String(r.purchase_order || '') === poKey);
        if (fIdx >= 0) {
            const src = allRows.find((r) => String(r.purchase_order || '') === poKey);
            if (src) filtered[fIdx] = src;
        }
    }

    function setRuntimeStatus(on, text = '') {
        const wrap = $('pcbRuntimeStatus');
        const label = $('pcbRuntimeText');
        if (wrap) wrap.hidden = !on;
        if (label && text) label.textContent = text;
        updateValidatedBtn(on ? text : '');
    }

    function pendingValidatePos() {
        return allRows
            .map((r) => String(r.purchase_order || ''))
            .filter((po) => po && !itemCache[po]);
    }

    function updateValidatedBtn(runtimeText = '') {
        const btn = $('pcbValidatedBtn');
        const label = $('pcbValidatedBtnLabel');
        if (!btn) return;
        const pending = pendingValidatePos().length;
        const total = allRows.length;
        const done = Math.max(0, total - pending);
        if (!total) {
            btn.disabled = true;
            if (label) label.textContent = 'Validated';
            btn.classList.remove('is-running');
            return;
        }
        if (runtimeRunning) {
            btn.disabled = true;
            btn.classList.add('is-running');
            if (label) label.textContent = runtimeText || `Validating ${done}/${total}`;
            return;
        }
        btn.classList.remove('is-running');
        if (pending <= 0) {
            btn.disabled = true;
            if (label) label.textContent = `Validated ${done}/${total}`;
            return;
        }
        btn.disabled = false;
        if (label) label.textContent = done > 0 ? `Validated (${pending} left)` : 'Validated';
    }

    function runBulkValidation(extraPos = null) {
        // Manual trigger only — page auto-validate bypasses this gate
        if (cfg.canValidate === false) {
            toast('You do not have permission to validate.', 'error');
            return;
        }
        if (runtimeRunning) {
            toast('Validation is already running.', 'info');
            return;
        }
        const targets = Array.isArray(extraPos)
            ? extraPos.map(String).filter((po) => po && !itemCache[po])
            : pendingValidatePos();
        if (!targets.length) {
            toast(allRows.length ? 'All loaded POs are already validated.' : 'Load purchase orders first.', 'info');
            updateValidatedBtn();
            return;
        }
        enqueueSelectedValidation(targets);
    }

    function stopRuntimeValidation() {
        runtimeToken += 1;
        runtimeRunning = false;
        validateQueue = [];
        setRuntimeStatus(false);
        if (!listFetching) showSpinner(false);
        updateValidatedBtn();
    }

    function selectedPendingPos() {
        return allRows
            .filter((r) => r._checked && r.purchase_order && !itemCache[r.purchase_order])
            .map((r) => String(r.purchase_order));
    }

    function enqueueSelectedValidation(extraPos = []) {
        const next = [...selectedPendingPos(), ...extraPos.map(String)].filter((po) => po && !itemCache[po]);
        next.forEach((po) => {
            if (!validateQueue.includes(po)) validateQueue.push(po);
        });
        if (validateQueue.length) void drainValidateQueue();
    }

    async function drainValidateQueue() {
        if (runtimeRunning) return;
        if (!validateQueue.length) {
            setRuntimeStatus(false);
            setProgress(false);
            return;
        }

        const token = ++runtimeToken;
        runtimeRunning = true;
        let done = 0;
        let fail = 0;
        const startedWith = validateQueue.length;
        setRuntimeStatus(true, `Validating 0/${startedWith}`);
        showSpinner(true);

        const workers = Array.from({ length: Math.min(RUNTIME_CONCURRENCY, Math.max(1, validateQueue.length)) }, async () => {
            while (token === runtimeToken) {
                const po = validateQueue.shift();
                if (!po) return;
                if (itemCache[po]) {
                    done++;
                    continue;
                }
                try {
                    await validateOne(po);
                    if (token !== runtimeToken) return;
                    syncRow(po);
                } catch (err) {
                    fail++;
                }
                done++;
                if (token !== runtimeToken) return;
                const remaining = validateQueue.length;
                const total = done + remaining;
                setRuntimeStatus(true, `Validating ${done}/${total}`);
                if (done % 2 === 0 || remaining === 0 || pageRows().some((r) => r.purchase_order === po)) {
                    fillKpis();
                    renderTable();
                    if (activePo === po) renderDrill(po);
                }
            }
        });

        await Promise.all(workers);
        if (token !== runtimeToken) return;

        runtimeRunning = false;
        fillKpis();
        renderTable();
        if (activePo && itemCache[activePo]) renderDrill(activePo);

        if (validateQueue.length) {
            void drainValidateQueue();
            return;
        }

        showSpinner(false);
        setRuntimeStatus(false);
        updateValidatedBtn();
        toast(
            fail
                ? `Validated ${done - fail} PO(s) · ${fail} failed`
                : `Validated ${done} PO(s)`,
            fail ? 'info' : 'ok'
        );
    }

    function varianceCell(pct) {
        if (pct === null || pct === undefined || pct === '') return '—';
        const n = Number(pct);
        const cls = n > 0 ? 'is-bad' : 'is-good';
        return `<span class="pcb-var ${cls}">${n > 0 ? '+' : ''}${n.toFixed(1)}%</span>`;
    }

    function resultPill(status) {
        const s = String(status || '').toUpperCase();
        let cls = 'is-fail';
        let label = s || '—';
        if (s === 'PASS') {
            cls = 'is-pass';
            label = 'PASS';
        } else if (s === 'NEEDS_REVIEW') {
            cls = 'is-review';
            label = 'NEEDS REVIEW';
        } else if (s === 'FAIL' || s === 'EXCEPTION' || s === 'UNMATCHED') {
            cls = 'is-fail';
            label = 'UNMATCHED';
        }
        return `<span class="pcw-pill ${cls}">${esc(label)}</span>`;
    }

    function openOffcanvas() {
        const el = $('pcbOffcanvas');
        if (!el) return;
        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => el.classList.add('is-open'));
        document.body.classList.add('pcb-offcanvas-open');
        expandItemTableFully();
    }

    function closeOffcanvas() {
        const el = $('pcbOffcanvas');
        if (!el) return;
        el.classList.remove('is-open');
        el.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pcb-offcanvas-open');
        window.setTimeout(() => {
            if (!el.classList.contains('is-open')) el.hidden = true;
        }, 280);
    }

    let activeItemIdx = -1;
    let activeItemRows = [];
    let filteredItemRows = [];
    const DEFAULT_HIDDEN_ITEM_COLS = [
        'prno',
        'prwise_so_qty',
        'consumption',
        'mat_desc',
        'budgeted_cons',
        'budget_inr',
        'budget_currency',
        'budget_conv_rt',
        'conv',
        'variance_pct',
    ];
    let hiddenItemCols = new Set(DEFAULT_HIDDEN_ITEM_COLS);
    /** @type {Record<string, string>} live column-filter values (source of truth) */
    const itemColFilterState = Object.create(null);

    const ITEM_COL_DEFS = [
        { key: 'status', label: 'Status', locked: true },
        { key: 'pono', label: 'PONO' },
        { key: 'poitem', label: 'POITEM' },
        { key: 'prno', label: 'PRNO' },
        { key: 'salordno', label: 'SALORDNO' },
        { key: 'style', label: 'STYLE' },
        { key: 'material', label: 'MATERIAL' },
        { key: 'matgroup', label: 'MATGROUP' },
        { key: 'so_qty', label: 'SO_QTY' },
        { key: 'prwise_so_qty', label: 'PRWISE_SO_QTY' },
        { key: 'consumption', label: 'CONSUMPTION' },
        { key: 'reqqty', label: 'REQQTY' },
        { key: 'tot_poqty', label: 'TOT_POQTY' },
        { key: 'so_wise_poqty', label: 'SO_WISE_POQTY' },
        { key: 'so_wise_po_val', label: 'SO_WISE_PO_VAL' },
        { key: 'ouom', label: 'OUOM' },
        { key: 'porate', label: 'PORATE' },
        { key: 'totvalue', label: 'TOTVALUE' },
        { key: 'mat_desc', label: 'MAT_DESC' },
        { key: 'po_currency', label: 'PO_CURRENCY' },
        { key: 'costing_quotation_no', label: 'Quotation No' },
        { key: 'costing_item_no', label: 'zCOST Item' },
        { key: 'zsection', label: 'ZSECTION' },
        { key: 'maktx', label: 'Material Desc' },
        { key: 'budgeted_cons', label: 'BUDGETED_CONS' },
        { key: 'budget_inr', label: 'BUDGET_INR' },
        { key: 'budget_currency', label: 'BUDGET_CURRENCY' },
        { key: 'budget_conv_rt', label: 'BUDGET_CONV_RT' },
        { key: 'budget_cost_per_gmt', label: 'BUDGET_COST_PER_PIECE' },
        { key: 'conv', label: 'CONV' },
        { key: 'buom', label: 'BUOM' },
        { key: 'actual_cost', label: 'ACTUAL_COST' },
        { key: 'variance_pct', label: 'Variance %' },
    ];
    const ITEM_NUM_COLS = new Set([
        'poitem', 'so_qty', 'prwise_so_qty', 'consumption', 'reqqty', 'tot_poqty',
        'so_wise_poqty', 'so_wise_po_val', 'porate', 'totvalue', 'budgeted_cons', 'budget_inr',
        'budget_conv_rt', 'budget_cost_per_gmt', 'conv', 'actual_cost', 'variance_pct',
    ]);
    const ITEM_ZCOST_COLS = new Set([
        'costing_quotation_no', 'costing_item_no', 'zsection', 'maktx',
        'budgeted_cons', 'budget_inr', 'budget_currency', 'budget_conv_rt', 'budget_cost_per_gmt',
        'conv', 'buom', 'actual_cost', 'variance_pct',
    ]);
    const ITEM_HIGHLIGHT_COLS = new Set(['budget_cost_per_gmt', 'actual_cost']);
    const itemColGroup = (key) => (ITEM_ZCOST_COLS.has(key) ? 'zcost' : 'po');

    function itemCellValue(key, r) {
        switch (key) {
            case 'status':
                return r.status || '';
            case 'pono':
                return r.pono || r.purchase_order || activePo || '';
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
                return r.matgroup || r.material_group || r.cost_item || '';
            case 'so_qty': {
                const so = Number(r.so_qty);
                if (Number.isFinite(so) && so > 0) return so;
                const pr = Number(r.prwise_so_qty);
                return Number.isFinite(pr) && pr > 0 ? pr : (r.so_qty ?? r.prwise_so_qty);
            }
            case 'prwise_so_qty': {
                const pr = Number(r.prwise_so_qty);
                if (Number.isFinite(pr) && pr > 0) return pr;
                const so = Number(r.so_qty);
                return Number.isFinite(so) && so > 0 ? so : (r.prwise_so_qty ?? r.so_qty);
            }
            case 'consumption': {
                // Prefer TEMPFIN CONSUMPTION / BUDGETED_CONS — never invent REQQTY÷SO
                const cons = Number(r.consumption);
                if (Number.isFinite(cons) && cons > 0) return cons;
                const bc = Number(r.budgeted_cons);
                if (Number.isFinite(bc) && bc > 0) return bc;
                return null;
            }
            case 'reqqty':
                return r.reqqty;
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
                return r.mat_desc || r.material_desc || r.description || '';
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
                return r.budget_cost_per_gmt ?? r.expected_unit_cost ?? r.rate;
            case 'conv':
                return r.conv ?? r.convr ?? 1;
            case 'buom':
                return r.buom || r.ouom || r.unit || '';
            case 'actual_cost':
                // Show PORATE in ACTUAL_COST column
                return r.porate ?? r.po_unit_cost ?? r.net_price ?? r.actual_cost;
            case 'variance_pct':
                return r.variance_pct;
            default:
                return r[key] ?? '';
        }
    }

    function renderItemCell(key, r) {
        const group = itemColGroup(key);
        const classes = [];
        if (ITEM_NUM_COLS.has(key)) classes.push('num');
        classes.push(`pcw-col-${group}`);
        if (ITEM_HIGHLIGHT_COLS.has(key) && !isFooterLine(r)) classes.push('pcw-hl-cost');
        const cls = classes.length ? ` class="${classes.join(' ')}"` : '';
        let inner = '—';

        if (isFooterLine(r)) {
            if (key === 'pono') {
                inner = `<strong class="pcw-footer-label">${esc(r.pono || r.matgroup || r.validation_result || '—')}</strong>`;
            } else if (key === 'status') {
                inner = '';
            } else if (r.row_kind === 'charge' || r.row_kind === 'tax') {
                inner = '';
            } else if (ITEM_FOOTER_SUM_KEYS.includes(key)) {
                const v = itemCellValue(key, r);
                if (key === 'so_wise_po_val' || key === 'totvalue' || key === 'budget_inr') inner = money2(v);
                else if (key === 'actual_cost' || key === 'budget_cost_per_gmt' || key === 'consumption' || key === 'conv' || key === 'budgeted_cons') inner = num(v, 4);
                else inner = qty(v);
            } else {
                inner = '';
            }
            return `<td${cls} data-col-key="${esc(key)}" data-col-group="${esc(group)}">${inner}</td>`;
        }

        switch (key) {
            case 'status':
                inner = resultPill(r.status);
                break;
            case 'pono':
                inner = `<span class="pcw-mat">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'material':
                inner = `<span class="pcw-mat">${dash(r.material)}</span>`;
                break;
            case 'mat_desc':
                inner = `<span class="pcw-compare-po" title="${esc(itemCellValue(key, r))}">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'costing_quotation_no':
                inner = quotationLinkHtml(
                    itemCellValue(key, r),
                    itemCellValue('costing_item_no', r)
                );
                break;
            case 'costing_item_no':
                inner = `<span class="pcw-nav-chip" title="zCOST ITEM">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'zsection':
                inner = `<span class="pcw-nav-chip" title="ZSECTION">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'maktx':
                inner = `<span class="pcw-compare-maktx" title="${esc(itemCellValue(key, r))}">${dash(itemCellValue(key, r))}</span>`;
                break;
            case 'so_qty':
            case 'prwise_so_qty':
            case 'reqqty':
            case 'tot_poqty':
            case 'so_wise_poqty':
                inner = qty(itemCellValue(key, r));
                break;
            case 'so_wise_po_val':
            case 'totvalue':
            case 'budget_inr':
                inner = money2(itemCellValue(key, r));
                break;
            case 'ouom':
            case 'buom':
            case 'po_currency':
            case 'budget_currency':
                inner = dash(itemCellValue(key, r));
                break;
            case 'consumption':
            case 'porate':
            case 'budgeted_cons':
            case 'budget_conv_rt':
            case 'budget_cost_per_gmt':
            case 'conv':
            case 'actual_cost': {
                const mismatch = !!r.cost_mismatch && (key === 'budget_cost_per_gmt' || key === 'actual_cost');
                const shown = num(itemCellValue(key, r), 4);
                inner = mismatch ? `<span class="pcw-cost-mismatch">${shown}</span>` : shown;
                break;
            }
            case 'variance_pct':
                inner = varianceCell(itemCellValue(key, r));
                break;
            default:
                inner = dash(itemCellValue(key, r));
        }
        return `<td${cls} data-col-key="${esc(key)}" data-col-group="${esc(group)}">${inner}</td>`;
    }

    function dlRow(label, value, extraClass) {
        return `<div class="pcw-kv"><dt>${esc(label)}</dt><dd class="${extraClass || ''}">${value}</dd></div>`;
    }

    function itemSearchQuery() {
        return String($('pcbItemSearch')?.value || '').trim().toLowerCase();
    }

    function itemColFilterMap() {
        // Prefer live state; fall back to DOM (e.g. after page restore)
        const map = {};
        Object.keys(itemColFilterState).forEach((key) => {
            const val = String(itemColFilterState[key] || '').trim();
            if (val) map[key] = val;
        });
        if (Object.keys(map).length) return map;
        document.querySelectorAll('#pcbItemFilterRow .pcb-item-col-filter').forEach((inp) => {
            const key = inp.getAttribute('data-key');
            const val = String(inp.value || '').trim();
            if (key && val) map[key] = val;
        });
        return map;
    }

    function setItemColFilterValue(inp) {
        if (!inp) return;
        const key = inp.getAttribute('data-key');
        if (!key) return;
        const val = String(inp.value || '').trim();
        if (val) itemColFilterState[key] = val;
        else delete itemColFilterState[key];
        inp.classList.toggle('has-val', !!val);
    }

    function expandItemTableFully() {
        const wrap = $('pcbItemTableWrap');
        if (!wrap) return;
        wrap.classList.remove('is-scroll-body', 'pcw-table-wrap');
        wrap.classList.add('is-expanded', 'pcb-oc-table-wrap');
        const detailOpen = !!document.querySelector('.pcb-offcanvas-body.has-item-detail');
        // When comparison is open, keep a compact scrollable table above it.
        // Otherwise show all rows; only horizontal scroll on the inner wrapper.
        if (detailOpen) {
            wrap.style.cssText = [
                'max-height:min(28vh,260px)!important',
                'height:auto!important',
                'width:100%!important',
                'max-width:100%!important',
                'min-width:0!important',
                'overflow:hidden!important',
            ].join(';');
            const x = wrap.querySelector('.pcb-oc-table-scrollx');
            if (x) {
                x.style.cssText = 'width:100%;max-width:100%;max-height:min(28vh,260px)!important;height:auto!important;overflow:auto!important;';
            }
            return;
        }
        wrap.style.cssText = [
            'max-height:none!important',
            'height:auto!important',
            'min-height:min-content!important',
            'width:100%!important',
            'max-width:100%!important',
            'min-width:0!important',
            'overflow:hidden!important',
        ].join(';');
        const x = wrap.querySelector('.pcb-oc-table-scrollx');
        if (x) {
            x.style.cssText = 'width:100%;max-width:100%;max-height:none!important;height:auto!important;overflow-x:auto;overflow-y:hidden;';
        }
    }

    function normNeedle(s) {
        return String(s || '').toLowerCase().replace(/,/g, '').replace(/\s+/g, ' ').trim();
    }

    function textMatches(haystack, needle) {
        const n = normNeedle(needle);
        if (!n) return true;
        const h = String(haystack ?? '');
        if (h.toLowerCase().includes(n)) return true;
        const h2 = normNeedle(h);
        if (h2.includes(n)) return true;
        // allow "exception" / "fail" / "pass" aliases for status pills
        return false;
    }

    function itemFieldCandidates(r, key) {
        const v = r?.[key];
        const raw = (v === null || v === undefined) ? '' : String(v);
        const out = [raw];
        switch (key) {
            case 'status':
                out.push(raw, raw === 'PASS' ? 'passed ok' : '', raw === 'EXCEPTION' ? 'fail failed exception' : '');
                break;
            case 'po_qty':
            case 'so_qty':
            case 'prwise_so_qty':
            case 'reqqty':
            case 'tot_poqty':
            case 'so_wise_poqty':
                out.push(qty(v), String(Number(v || 0)));
                break;
            case 'net_value':
            case 'expected_unit_cost':
            case 'po_unit_cost':
            case 'difference':
            case 'sell_price':
            case 'making':
            case 'so_wise_po_val':
            case 'totvalue':
            case 'budget_inr':
                out.push(money(v), money2(v), String(Number(v || 0)));
                break;
            case 'consumption':
            case 'porate':
            case 'budgeted_cons':
            case 'budget_conv_rt':
            case 'budget_cost_per_gmt':
            case 'conv':
            case 'actual_cost':
                out.push(num(v, 4), String(Number(v || 0)));
                break;
            case 'variance_pct':
                if (v !== null && v !== undefined && v !== '') {
                    const n = Number(v);
                    out.push(`${n}`, `${n.toFixed(1)}`, `${n.toFixed(1)}%`, `${n > 0 ? '+' : ''}${n.toFixed(1)}%`);
                }
                break;
            default:
                break;
        }
        return out.filter((x) => x !== '');
    }

    function itemSearchHay(r) {
        return ITEM_COL_DEFS.map((c) => itemCellValue(c.key, r)).join(' ');
    }

    function itemMatchesCol(r, key, val) {
        const candidates = itemFieldCandidates(r, key);
        const mapped = itemCellValue(key, r);
        if (mapped !== null && mapped !== undefined && mapped !== '') candidates.push(String(mapped));
        return candidates.some((c) => textMatches(c, val));
    }

    function updateItemFilterUi() {
        const q = itemSearchQuery();
        const cols = itemColFilterMap();
        const n = Object.keys(cols).length;
        const badge = $('pcbItemFilterBadge');
        if (badge) {
            badge.style.display = n ? '' : 'none';
            badge.textContent = String(n);
        }
        const clear = $('pcbItemClearFilters');
        if (clear) clear.style.display = (q || n) ? '' : 'none';
        document.querySelectorAll('#pcbItemFilterRow .pcb-item-col-filter').forEach((inp) => {
            inp.classList.toggle('has-val', !!String(inp.value || '').trim());
        });
    }

    function filterItemRows() {
        const q = itemSearchQuery();
        const cols = itemColFilterMap();
        filteredItemRows = activeItemRows.filter((r) => {
            if (q && !textMatches(itemSearchHay(r), q)) return false;
            for (const [key, val] of Object.entries(cols)) {
                if (!itemMatchesCol(r, key, val)) return false;
            }
            return true;
        });
        return filteredItemRows;
    }

    /** Strip trailing garment size so M/L/XL variants share one group key. */
    function styleBaseKey(desc) {
        let s = String(desc || '').toUpperCase().trim();
        s = s.replace(/[,.\s]+(XXXL|XXL|XL|XXS|XS|2XL|3XL|4XL|S|M|L)\s*$/i, '');
        s = s.replace(/[^A-Z0-9]+/g, ' ').trim().replace(/\s+/g, ' ');
        return s;
    }

    function normGroupToken(v) {
        return String(v || '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').trim();
    }

    function tokenLooksLikeMaterial(token, material) {
        const a = normGroupToken(token);
        const b = normGroupToken(material);
        if (!a || !b) return false;
        return a === b || b.includes(a) || a.includes(b);
    }

    /**
     * Group key: ZCOST Item (Label/Button/…) first, else SAP material group,
     * else same style base + unit cost (size variants).
     * Skip keys that are just the line’s own material code so size variants still collapse.
     */
    function itemGroupKey(r) {
        const costItem = String(r.cost_item || '').trim().toUpperCase();
        const costSection = String(r.cost_section || '').trim().toUpperCase();
        const matGroup = String(r.material_group || '').trim().toUpperCase();
        const material = String(r.material || '').trim();
        const expected = (r.expected_unit_cost === null || r.expected_unit_cost === undefined || r.expected_unit_cost === '')
            ? 'x'
            : String(Number(r.expected_unit_cost));
        const poUnit = String(Number(r.po_unit_cost || 0));
        const so = String(r.sales_order || '');
        if (costItem && !tokenLooksLikeMaterial(costItem, material)) {
            return ['CI', costSection, costItem, expected, so].join('|');
        }
        if (matGroup && !tokenLooksLikeMaterial(matGroup, material)) {
            return ['MG', matGroup, poUnit, so].join('|');
        }
        const base = styleBaseKey(r.description || r.material || '') || normGroupToken(material);
        return ['ST', base, poUnit, so].join('|');
    }

    function formatPoItemRange(items) {
        const nums = items
            .map((v) => Number(String(v).replace(/\D/g, '')))
            .filter((n) => !Number.isNaN(n))
            .sort((a, b) => a - b);
        if (!nums.length) return items.join(', ');
        const unique = [...new Set(nums)];
        if (unique.length === 1) return String(unique[0]);
        const sequential = unique.every((n, i) => i === 0 || n === unique[i - 1] + 10 || n === unique[i - 1] + 1);
        if (sequential && unique.length > 2) return `${unique[0]}–${unique[unique.length - 1]}`;
        return unique.join(', ');
    }

    function buildGroupedItemRow(members) {
        const rows = members.map((m) => m.r);
        const idxs = members.map((m) => m.idx);
        if (rows.length === 1) {
            return {
                ...rows[0],
                _group: false,
                _group_size: 1,
                _members: rows,
                _memberIdxs: idxs,
                _srcIdx: idxs[0],
            };
        }
        const materials = [...new Set(rows.map((r) => String(r.material || '').trim()).filter(Boolean))];
        const poItems = rows.map((r) => String(r.po_item || '').trim()).filter(Boolean);
        const anyFail = rows.some((r) => String(r.status || '').toUpperCase() !== 'PASS');
        const results = [...new Set(rows.map((r) => String(r.validation_result || '').trim()).filter(Boolean))];
        const qtySum = rows.reduce((s, r) => s + Number(r.po_qty || 0), 0);
        const netSum = rows.reduce((s, r) => s + Number(r.net_value || 0), 0);
        const first = rows[0];
        const groupLabel = String(first.cost_item || first.material_group || '').trim();
        const groupLabelIsMat = tokenLooksLikeMaterial(groupLabel, first.material || '');
        const labelForUi = groupLabel && !groupLabelIsMat ? groupLabel : '';
        const baseDesc = styleBaseKey(first.description || '') || String(first.description || '');
        const desc = first.cost_description
            || (labelForUi ? `${labelForUi} · ${baseDesc}` : baseDesc)
            || materials.join(', ');
        return {
            ...first,
            po_item: formatPoItemRange(poItems),
            material: materials.join(', '),
            description: desc,
            po_qty: qtySum,
            net_value: Math.round(netSum * 100) / 100,
            status: anyFail ? 'EXCEPTION' : 'PASS',
            validation_result: results.length <= 1 ? (results[0] || first.validation_result) : (anyFail ? 'Grouped — has exceptions' : 'PASS'),
            material_group: labelForUi || first.material_group || '',
            cost_item: labelForUi || first.cost_item || '',
            _checked: rows.every((r) => !!r._checked),
            _group: true,
            _group_size: rows.length,
            _members: rows,
            _memberIdxs: idxs,
            _srcIdx: idxs[0],
        };
    }

    /** Collapse matching material-group / Label / size-variant lines into one display row. */
    function groupItemRows(rows) {
        const map = new Map();
        const order = [];
        rows.forEach((r) => {
            const idx = activeItemRows.indexOf(r);
            const key = itemGroupKey(r);
            if (!map.has(key)) {
                map.set(key, []);
                order.push(key);
            }
            map.get(key).push({ r, idx });
        });
        return order.map((key) => buildGroupedItemRow(map.get(key)));
    }

    function clearItemFilters({ keepSearch = false } = {}) {
        if (!keepSearch && $('pcbItemSearch')) $('pcbItemSearch').value = '';
        Object.keys(itemColFilterState).forEach((k) => { delete itemColFilterState[k]; });
        document.querySelectorAll('#pcbItemFilterRow .pcb-item-col-filter').forEach((inp) => {
            inp.value = '';
            inp.classList.remove('has-val');
        });
    }

    function runItemFilters() {
        renderItemTable();
        afterItemFilterRender();
    }

    function afterItemFilterRender() {
        if (filteredItemRows.length) {
            // Only keep/update an already-open comparison; don't auto-open on filter type
            if (activeItemIdx >= 0) {
                if (filteredItemRows.includes(activeItemRows[activeItemIdx])) {
                    showItemDetail(activeItemIdx, { scroll: false });
                } else {
                    const firstIdx = activeItemRows.indexOf(filteredItemRows[0]);
                    if (firstIdx >= 0) showItemDetail(firstIdx, { scroll: true });
                }
            }
        } else if (activeItemIdx >= 0) {
            hideItemDetail();
        }
    }

    function applyItemColumnVisibility() {
        if (pcbItemTablePrefManager) {
            pcbItemTablePrefManager.refresh();
            syncItemGroupHeaders();
            return;
        }
        const table = $('pcbItemTable');
        if (!table) return;
        const headerCells = table.querySelectorAll('tr.pcb-item-header-row th');
        const filterCells = table.querySelectorAll('tr.pcb-item-filter-row th');
        headerCells.forEach((th, i) => {
            const key = th.getAttribute('data-icol') || th.getAttribute('data-col-key');
            const hide = key ? hiddenItemCols.has(key) : false;
            th.style.display = hide ? 'none' : '';
            if (filterCells[i]) filterCells[i].style.display = hide ? 'none' : '';
        });
        table.querySelectorAll('tbody tr').forEach((tr) => {
            if (tr.classList.contains('pcw-empty')) return;
            const tds = tr.children;
            headerCells.forEach((th, i) => {
                const key = th.getAttribute('data-icol') || th.getAttribute('data-col-key');
                if (!tds[i]) return;
                tds[i].style.display = (key && hiddenItemCols.has(key)) ? 'none' : '';
            });
        });
        syncItemGroupHeaders();
    }

    function syncItemGroupHeaders() {
        const table = $('pcbItemTable');
        const groupRow = table?.querySelector('tr.pcw-group-row');
        const headerRow = table?.querySelector('tr.pcb-item-header-row');
        if (!groupRow || !headerRow) return;
        const visible = Array.from(headerRow.children).filter((th) => {
            if (!(th instanceof HTMLElement)) return false;
            return window.getComputedStyle(th).display !== 'none';
        });
        const runs = [];
        visible.forEach((th) => {
            const g = th.getAttribute('data-col-group') || itemColGroup(th.getAttribute('data-col-key') || '');
            const last = runs[runs.length - 1];
            if (last && last.group === g) last.span += 1;
            else runs.push({ group: g, span: 1 });
        });
        if (!runs.length) {
            groupRow.innerHTML = '';
            return;
        }
        groupRow.innerHTML = runs.map((run) => {
            const isPo = run.group !== 'zcost';
            const label = isPo ? 'PO Data' : 'Costing Data';
            const icon = isPo ? 'receipt_long' : 'calculate';
            const cls = isPo ? 'is-po' : 'is-zcost';
            return `<th class="pcw-group-th ${cls}" data-group="${esc(run.group)}" colspan="${run.span}">
                <span class="pcw-group-label">
                    <span class="material-icons-round">${icon}</span>
                    ${label}
                </span>
            </th>`;
        }).join('');
    }

    function buildItemColumnMenu() {
        if (pcbItemTablePrefManager) return;
        const menu = $('pcbItemColumnMenu');
        if (!menu) return;
        const visible = ITEM_COL_DEFS.filter((c) => !c.locked && !hiddenItemCols.has(c.key)).length;
        const optional = ITEM_COL_DEFS.filter((c) => !c.locked).length;
        menu.innerHTML =
            `<div class="pcb-col-menu-head">
                <div>
                    <strong>Columns</strong>
                    <small>${visible} of ${optional} visible</small>
                </div>
                <div class="pcb-col-menu-actions">
                    <button type="button" class="pcb-col-menu-btn" data-pcb-icol-act="all">Show all</button>
                    <button type="button" class="pcb-col-menu-btn" data-pcb-icol-act="reset">Reset</button>
                </div>
            </div>
            <div class="pcb-col-menu-list">` +
            ITEM_COL_DEFS.map((c) => {
                const checked = !hiddenItemCols.has(c.key);
                const disabled = c.locked ? ' disabled' : '';
                return `<label class="rpt-cat-dd-item pcb-col-menu-item${c.locked ? ' is-locked' : ''}">
                    <input type="checkbox" data-icol="${esc(c.key)}" ${checked ? 'checked' : ''}${disabled}>
                    <span>${esc(c.label)}</span>
                    ${c.locked ? '<em>Fixed</em>' : ''}
                </label>`;
            }).join('') +
            `</div>`;

        menu.querySelectorAll('[data-pcb-icol-act]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const act = btn.getAttribute('data-pcb-icol-act');
                if (act === 'all') {
                    hiddenItemCols.clear();
                    buildItemColumnMenu();
                    applyItemColumnVisibility();
                } else if (act === 'reset') {
                    hiddenItemCols = new Set(DEFAULT_HIDDEN_ITEM_COLS);
                    buildItemColumnMenu();
                    applyItemColumnVisibility();
                }
            });
        });
        menu.querySelectorAll('input[data-icol]').forEach((inp) => {
            inp.addEventListener('change', () => {
                const key = inp.getAttribute('data-icol');
                if (!key) return;
                if (inp.checked) hiddenItemCols.delete(key);
                else hiddenItemCols.add(key);
                applyItemColumnVisibility();
                buildItemColumnMenu();
            });
        });
    }

    function renderItemTable() {
        const body = $('pcbItemBody');
        if (!body) return;
        const filtered = filterItemRows();
        const items = filtered;
        const detailTotal = activeItemRows.filter((r) => !isFooterLine(r)).length;
        const detailFiltered = items.filter((r) => !isFooterLine(r));
        updateItemFilterUi();
        if ($('pcbItemCount')) {
            if (!detailFiltered.length) {
                $('pcbItemCount').textContent = 'No items';
            } else {
                $('pcbItemCount').textContent = `Showing ${detailFiltered.length} of ${detailTotal} items`;
            }
            $('pcbItemCount').classList.toggle('is-filtered', !!(itemSearchQuery() || Object.keys(itemColFilterMap()).length));
        }
        const detailItems = items.filter((r) => !isFooterLine(r));
        if (!detailItems.length) {
            body.innerHTML = `<tr class="pcw-empty"><td colspan="${ITEM_COL_DEFS.length}">${
                activeItemRows.length ? 'No items match the filters.' : (runtimeRunning ? 'Validation in progress…' : 'Not validated yet. Select this PO to validate.')
            }</td></tr>`;
            hideItemDetail();
            applyItemColumnVisibility();
            return;
        }

        const apiFooters = items.filter((r) => isFooterLine(r));
        const footerRows = (apiFooters.length && hasItemClassicFooter(apiFooters))
            ? apiFooters
            : buildItemClassicFooter(detailItems);

        body.innerHTML = [...detailItems, ...footerRows].map((r) => {
            const idx = r._srcIdx != null ? r._srcIdx : activeItemRows.indexOf(r);
            const active = idx === activeItemIdx ? ' is-active' : '';
            const st = String(r.status || '').toUpperCase();
            const kind = String(r.row_kind || 'detail');
            const isFooter = isFooterLine(r);
            const unmatched = !isFooter && (!!r.needs_manual_match
                || (!r.costing_quotation_no && !r.costing_quotation && !r.matched_quotation && !r.maktx && !r.cost_item));
            const pass = !unmatched && !isFooter && st === 'PASS' ? ' is-pass-row' : '';
            const review = !unmatched && !isFooter && st === 'NEEDS_REVIEW' ? ' is-review-row' : '';
            const fail = !unmatched && !isFooter && (st === 'EXCEPTION' || st === 'FAIL') ? ' is-fail-row' : '';
            const unmatchedCls = unmatched ? ' is-unmatched-row' : '';
            const mismatch = r.cost_mismatch && !unmatched && st !== 'PASS' ? ' is-cost-mismatch' : '';
            const footerLabel = itemFooterLabel(r).replace(/\s+/g, '');
            const footer = isFooter
                ? ` is-footer-row is-${kind}${kind === 'total' || footerLabel === 'TOTAL' ? ' is-total' : ''}`
                : '';
            return `<tr class="pcw-row${active}${pass}${review}${fail}${unmatchedCls}${mismatch}${footer}" data-idx="${idx}" data-row-kind="${esc(kind)}">${
                ITEM_COL_DEFS.map((c) => renderItemCell(c.key, r)).join('')
            }</tr>`;
        }).join('');

        if ($('pcbItemSelectAll')) {
            const allOn = filtered.length && filtered.every((r) => r._checked);
            $('pcbItemSelectAll').checked = allOn;
            $('pcbItemSelectAll').indeterminate = !allOn && filtered.some((r) => r._checked);
        }
        applyItemColumnVisibility();
        expandItemTableFully();
    }

    function exportItemCsv() {
        const rows = filterItemRows();
        if (!rows.length) {
            toast('Nothing to export.', 'error');
            return;
        }
        const headers = ITEM_COL_DEFS.map((c) => c.label);
        const lines = [headers.join(',')];
        rows.forEach((r) => {
            lines.push(ITEM_COL_DEFS.map((c) => `"${String(itemCellValue(c.key, r) ?? '').replace(/"/g, '""')}"`).join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `po-costing-items-${activePo || 'export'}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
        toast('Item Excel/CSV downloaded.', 'ok');
    }

    function fillOffcanvasKpis(summary, fallbackValue) {
        const s = summary || {};
        if ($('pcbOcKpiItems')) $('pcbOcKpiItems').textContent = qty(s.po_items || 0);
        if ($('pcbOcKpiPassed')) $('pcbOcKpiPassed').textContent = qty(s.passed || 0);
        if ($('pcbOcKpiExceptions')) $('pcbOcKpiExceptions').textContent = qty(s.exceptions || 0);
        if ($('pcbOcKpiReview')) $('pcbOcKpiReview').textContent = qty(s.needs_review || 0);
        if ($('pcbOcKpiValue')) {
            const val = s.total_po_value != null ? s.total_po_value : fallbackValue;
            const curr = (s.currency || 'INR').toUpperCase();
            const sym = curr === 'EUR' ? '€ ' : (curr === 'USD' ? '$ ' : '₹ ');
            $('pcbOcKpiValue').textContent = sym + money2(val || 0);
        }
    }

    function setItemDetailBodyState(on) {
        const body = document.querySelector('.pcb-offcanvas-body');
        if (body) body.classList.toggle('has-item-detail', !!on);
    }

    function hideItemDetail() {
        activeItemIdx = -1;
        const detail = $('pcbItemDetail');
        if (detail) {
            detail.hidden = true;
            detail.classList.remove('is-open');
        }
        setItemDetailBodyState(false);
        document.querySelectorAll('#pcbItemBody tr.pcw-row').forEach((tr) => tr.classList.remove('is-active'));
        expandItemTableFully();
    }

    function revealItemDetail(detail) {
        if (!detail) return;
        const body = detail.closest('.pcb-offcanvas-body');
        requestAnimationFrame(() => {
            // Prefer keeping the full comparison cards visible under the table
            detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (body) {
                const pad = 8;
                const top = Math.max(0, detail.offsetTop - pad);
                const bottom = detail.offsetTop + detail.offsetHeight + pad;
                const viewTop = body.scrollTop;
                const viewBottom = viewTop + body.clientHeight;
                if (bottom > viewBottom || top < viewTop) {
                    body.scrollTo({ top: Math.max(0, bottom - body.clientHeight), behavior: 'smooth' });
                }
            }
            const active = document.querySelector('#pcbItemBody tr.pcw-row.is-active');
            active?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }

    function showItemDetail(idx, opts = {}) {
        activeItemIdx = idx;
        const r = activeItemRows[idx];
        const detail = $('pcbItemDetail');
        if (!r || !detail) {
            if (detail) {
                detail.hidden = true;
                detail.classList.remove('is-open');
            }
            setItemDetailBodyState(false);
            return;
        }
        detail.hidden = false;
        detail.classList.add('is-open');
        setItemDetailBodyState(true);

        const groupRows = (() => {
            const key = itemGroupKey(r);
            return activeItemRows.filter((x) => itemGroupKey(x) === key);
        })();
        const groupMats = [...new Set(groupRows.map((x) => String(x.material || '').trim()).filter(Boolean))];
        const groupItems = groupRows.map((x) => String(x.po_item || '').trim()).filter(Boolean);
        const groupQty = groupRows.reduce((s, x) => s + Number(x.po_qty || 0), 0);
        const groupLabel = String(r.cost_item || r.material_group || '').trim();
        const matLabel = groupRows.length > 1
            ? (groupLabel || groupMats.join(', ') || r.description || '—')
            : ([r.material, r.description].filter(Boolean).join(' - ') || '—');
        if ($('pcbItemDetailMeta')) {
            $('pcbItemDetailMeta').textContent = groupRows.length > 1
                ? `Group: ${groupLabel || 'Matching items'} · ${groupRows.length} lines · PO Items ${formatPoItemRange(groupItems)} · SO ${r.sales_order || '—'}`
                : `PO Item: ${r.po_item || '—'} | Material: ${matLabel} | Sales Order: ${r.sales_order || '—'}`;
        }
        if ($('pcbItemDetailSub')) {
            $('pcbItemDetailSub').textContent = groupRows.length > 1
                ? `${groupLabel || 'Group'} · ${groupMats.slice(0, 4).join(', ')}${groupMats.length > 4 ? '…' : ''} · SO ${r.sales_order || '—'}`
                : `PO Item ${r.po_item || '—'} · ${r.material || '—'} · SO ${r.sales_order || '—'}`;
        }

        const quote = $('pcbPanelQuote');
        const po = $('pcbPanelPo');
        const diff = $('pcbPanelDiff');
        const info = $('pcbPanelInfo');
        const cache = itemCache[activePo] || {};
        const quoteDate = r.quotation_date || r.quote_date || r.costing_date || '';
        const updatedAt = cache._validated_at || new Date().toLocaleString('en-IN');
        const updatedBy = cache._validated_by || currentUserName() || '—';

        if (quote) {
            quote.innerHTML =
                dlRow('Quotation Reference', dash(r.costing_ref)) +
                dlRow('Quoted Unit Cost', '<strong>₹ ' + money2(r.expected_unit_cost) + '</strong>') +
                dlRow('Currency', dash(r.quoted_currency || 'INR')) +
                dlRow('UOM', dash(r.quoted_uom || r.unit)) +
                dlRow('Quotation Date', quoteDate ? (fmtDate(quoteDate) !== '—' ? fmtDate(quoteDate) : dash(quoteDate)) : '—');
        }
        if (po) {
            po.innerHTML =
                dlRow('PO Number', dash(r.purchase_order || activePo)) +
                dlRow('PO Item', dash(groupRows.length > 1 ? formatPoItemRange(groupItems) : r.po_item)) +
                dlRow('PO Unit Cost', '<strong>₹ ' + money2(r.po_unit_cost) + '</strong>') +
                dlRow('Currency', dash(r.currency || 'INR')) +
                dlRow('UOM', dash(r.unit)) +
                dlRow('PO Qty', qty(groupRows.length > 1 ? groupQty : r.po_qty));
        }
        if (diff) {
            const d = r.difference;
            const dCls = d === null || d === undefined || d === '' ? '' : (Number(d) > 0 ? 'is-bad' : 'is-good');
            const dText =
                d === null || d === undefined || d === ''
                    ? '—'
                    : `${Number(d) > 0 ? '+ ' : '- '}₹ ${money2(Math.abs(Number(d)))} [${r.variance_pct > 0 ? '+' : ''}${r.variance_pct ?? '—'}%]`;
            diff.innerHTML =
                dlRow('Expected Unit Cost', '₹ ' + money2(r.expected_unit_cost)) +
                dlRow('PO Unit Cost', '₹ ' + money2(r.po_unit_cost)) +
                dlRow('Difference', dText, dCls) +
                dlRow('Variance %', varianceCell(r.variance_pct)) +
                dlRow('Validation Result', resultPill(r.status));
        }
        if (info) {
            info.innerHTML =
                dlRow('Sales Order', dash(r.sales_order)) +
                dlRow('Material Group', dash(groupLabel || '—')) +
                dlRow('Material Description', dash(r.description)) +
                (groupRows.length > 1
                    ? dlRow('Grouped PO Items', dash(formatPoItemRange(groupItems))) +
                      dlRow('Grouped Materials', dash(groupMats.join(', '))) +
                      dlRow('Lines in group', String(groupRows.length))
                    : '') +
                dlRow('Last Updated', dash(updatedAt)) +
                dlRow('Updated By', dash(updatedBy));
        }

        document.querySelectorAll('#pcbItemBody tr.pcw-row').forEach((tr) => {
            const members = String(tr.getAttribute('data-member-idxs') || tr.getAttribute('data-idx') || '')
                .split(',')
                .map((v) => Number(v));
            tr.classList.toggle('is-active', members.includes(idx));
        });

        expandItemTableFully();
        if (opts.scroll !== false) revealItemDetail(detail);
    }

    function renderDrill(po) {
        const prevPo = activePo;
        const keepIdx = (prevPo === po && activeItemIdx >= 0) ? activeItemIdx : -1;
        activePo = po;
        openOffcanvas();
        const row = allRows.find((r) => r.purchase_order === po);
        const data = itemCache[po];
        if ($('pcbDrillTitle')) $('pcbDrillTitle').textContent = `PO ${po}`;
        if ($('pcbDrillSub')) {
            const s = data?.summary || {};
            const parts = [];
            if (s.passed != null) parts.push(`${s.passed} passed`);
            if (s.exceptions != null) parts.push(`${s.exceptions} exceptions`);
            if (s.needs_review != null && s.needs_review > 0) parts.push(`${s.needs_review} needs review`);
            if (data?._validated_at) parts.push(data._validated_at);
            $('pcbDrillSub').textContent = data
                ? parts.join(' · ')
                : (runtimeRunning ? 'Validation in progress…' : 'Loading ZCOST validation…');
        }
        const wb = $('pcbOpenWorkbench');
        if (wb) {
            wb.hidden = false;
            wb.href = workbenchUrl(po);
        }

        const items = Array.isArray(data?.records) ? data.records.map((r) => ({ ...r, _checked: !!r._checked })) : [];
        activeItemRows = items;
        fillOffcanvasKpis(data?.summary, row?.total_value);
        renderItemTable();
        // Keep comparison open across refresh of the same PO; otherwise wait for row click
        if (keepIdx >= 0 && items[keepIdx]) {
            showItemDetail(keepIdx, { scroll: false });
        } else if (prevPo !== po) {
            hideItemDetail();
        }
        renderTable();
    }

    function openDrill(po) {
        if (!po) return;
        if (cfg.canWorkbench === false) {
            alert('You do not have Workbench access for PO Costing Validation.');
            return;
        }
        const href = workbenchUrl(po);
        let abs = href;
        try {
            abs = new URL(href, window.location.origin).href;
        } catch (_) {
            abs = href;
        }
        const win = window.open(abs, '_blank', 'noopener');
        if (!win) {
            // Popup blocked — fall back to same tab
            window.location.href = abs;
        }
    }

    function selectedPos() {
        const po = allRows.find((r) => !!r._checked);
        return po && po.purchase_order ? [String(po.purchase_order)] : [];
    }

    function openActionOffcanvas(shellId) {
        const shell = $(shellId);
        if (!shell) return;
        shell.hidden = false;
        shell.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pcw-action-offcanvas-open');
        requestAnimationFrame(() => shell.classList.add('is-open'));
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

    function closeHistoryModal() {
        closeValidateModal();
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
        body.innerHTML = grouped.map((log) => {
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
        }).join('');
    }

    async function loadActivityFeed(po) {
        const body = $('pcwHistoryBody');
        const countEl = $('pcwHistoryCount');
        if (!po) {
            if (countEl) countEl.textContent = '—';
            if (body) body.innerHTML = `<div class="pcw-history-empty">Select a PO to see activity.</div>`;
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
            const json = await res.json();
            const logs = Array.isArray(json?.data?.logs) ? json.data.logs : [];
            renderActivityFeed(groupValidationLogs(logs), po);
        } catch (err) {
            if (countEl) countEl.textContent = 'Error';
            if (body) body.innerHTML = `<div class="pcw-history-empty">${esc(err.message || 'Failed to load activity')}</div>`;
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
        return { start: pos - query.length - 1, end: pos, query };
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
        menu.innerHTML = mentionUsers.map((u, i) => {
            const initial = avatarLetter(u.username || '?');
            return `<button type="button" class="pcw-mention-option${i === 0 ? ' is-active' : ''}" role="option"
                data-idx="${i}" data-id="${esc(u.id)}" data-username="${esc(u.username)}" data-email="${esc(u.email || '')}">
                <span class="pcw-activity-avatar" aria-hidden="true">${esc(initial)}</span>
                <span class="pcw-mention-meta">
                    <strong>@${esc(u.username)}</strong>
                    <small>${esc([u.email, u.department, u.role].filter(Boolean).join(' · ') || '—')}</small>
                </span>
            </button>`;
        }).join('');
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
        const ctx = getMentionContext($('pcwValidateRemarks'));
        if (!ctx) {
            hideMentionMenu();
            return;
        }
        clearTimeout(mentionTimer);
        mentionTimer = setTimeout(() => { void searchMentionUsers(ctx.query); }, 120);
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
        const selected = selectedPos();
        if (!selected.length) {
            toast('Select one PO row for comments & activity.', 'error');
            return;
        }
        validateTargetPos = selected.slice(0, 1);
        const po = validateTargetPos[0];
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
            menu.innerHTML = users.map((u) => `<button type="button" class="pcw-user-option" role="option"
                data-id="${esc(u.id)}" data-username="${esc(u.username)}">
                <strong>${esc(u.username)}</strong>
                <small>${esc([u.department, u.role, u.email].filter(Boolean).join(' · ') || '—')}</small>
            </button>`).join('');
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
    }

    async function saveValidationLog() {
        const description = String($('pcwValidateRemarks')?.value || '').trim();
        if (!description) {
            toast('Write a comment first.', 'error');
            $('pcwValidateRemarks')?.focus();
            return;
        }
        const targets = (validateTargetPos.length ? validateTargetPos : selectedPos()).slice(0, 1);
        if (!targets.length) {
            toast('Select one PO row.', 'error');
            return;
        }
        const loginUser = String(cfg.currentUser || $('pcwLoginUserName')?.textContent || 'User').trim();
        const mentions = extractMentionsFromText(description);
        const endpoint = cfg.validationLogUrl || 'purchase-order/validation-log';
        const btn = $('pcwValidateSave');
        if (btn) btn.disabled = true;
        let ok = 0;
        let fail = 0;
        let mailed = 0;
        try {
            for (const po of targets) {
                const payload = {
                    purchase_order: po,
                    mat_cat: selectedMat(),
                    assigned_user_id: null,
                    assigned_username: loginUser || 'system',
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
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (!res.ok || json.success === false) {
                    fail += 1;
                    continue;
                }
                ok += 1;
                mailed += Number(json?.data?.mail?.sent || 0);
            }
            setComposeOpen(false);
            hideMentionMenu();
            const po = targets[0];
            if (po) await loadActivityFeed(po);
            toast(
                fail
                    ? `Saved ${ok} comment(s), ${fail} failed.`
                    : mailed > 0
                      ? `Comment saved · mailed ${mailed} from apps@evolvclothing.com`
                      : 'Comment saved (set SMTP password in config/mail.php if mail is missing)',
                fail ? 'error' : 'ok'
            );
        } catch (err) {
            toast(err.message || 'Could not save comment', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    function exportCsv() {
        if (!filtered.length) {
            toast('Nothing to export.', 'error');
            return;
        }
        const headers = COL_DEFS.filter((c) => c.key !== 'check' && c.key !== 'pdf').map((c) => c.label);
        const lines = [headers.join(',')];
        filtered.forEach((r, i) => {
            lines.push(COL_DEFS.filter((c) => c.key !== 'check' && c.key !== 'pdf').map((c) => {
                const raw = c.key === 'sno' ? i + 1 : (c.key === 'po' ? r.purchase_order : (c.key === 'status' ? r._validation_status : rowField(r, c.key)));
                return `"${String(raw ?? '').replace(/"/g, '""')}"`;
            }).join(','));
        });
        const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'bulk-po-costing-validation.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        toast('Export downloaded.', 'ok');
    }

    function setChecked(po, on) {
        allRows.forEach((r) => {
            r._checked = on && String(r.purchase_order || '') === String(po || '');
        });
        if (on && po) activePo = po;
    }

    function bind() {
        $('pcbBackBtn')?.addEventListener('click', (e) => {
            const ref = String(document.referrer || '');
            if (ref && ref.startsWith(window.location.origin) && /po-costing/i.test(ref)) {
                e.preventDefault();
                window.history.back();
            }
        });
        $('pcbFilterForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            // Enter in PO search should open that PO, not refetch list
            if (document.activeElement && document.activeElement.id === 'pcbPoSearch') {
                searchPoHeader();
                return;
            }
            void fetchPos(e);
        });
        document.querySelectorAll('input[name="pcb_mat"]').forEach((el) => {
            el.addEventListener('change', () => {
                selectedMat();
                void fetchPos();
            });
        });
        const brandDropdownApi = bindBrandDropdown();
        $('pcbSupplier')?.addEventListener('change', () => void fetchPos());
        $('pcbPoSearchBtn')?.addEventListener('click', () => searchPoHeader());
        $('pcbPoSearch')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchPoHeader();
            }
        });
        $('pcbResetBtn')?.addEventListener('click', () => {
            if ($('pcbRange')) $('pcbRange').value = 'all';
            if ($('pcbFrom')) $('pcbFrom').value = '';
            if ($('pcbTo')) $('pcbTo').value = '';
            if ($('pcbSupplier')) $('pcbSupplier').value = '';
            if ($('pcbValStatus')) $('pcbValStatus').value = 'all';
            if ($('pcbMat')) $('pcbMat').value = 'all';
            document.querySelectorAll('input[name="pcb_mat"]').forEach((el) => {
                el.checked = el.value === 'all';
            });
            cfg.approvedStatus = '';
            if (brandDropdownApi) brandDropdownApi.clear();
            else {
                if ($('pcbBrand')) $('pcbBrand').value = '';
                cfg.brand = '';
            }
            if ($('pcbPoSearch')) $('pcbPoSearch').value = '';
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => { inp.value = ''; });
            Object.keys(colFilterState).forEach((k) => { delete colFilterState[k]; });
            stopRuntimeValidation();
            void fetchPos();
        });
        $('pcbQuickSearch')?.addEventListener('input', () => {
            applyFilter();
            scheduleQuickSearchFetch();
        });
        $('pcbQuickSearch')?.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            clearTimeout(quickSearchTimer);
            const po = extractQuickSearchPo();
            if (po) {
                lastServerPoSearch = po;
                void fetchPos(null, { page: 1, resetCache: true, po });
            } else {
                applyFilter();
            }
        });
        $('pcbQuickSearchClear')?.addEventListener('click', () => {
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            lastServerPoSearch = '';
            clearTimeout(quickSearchTimer);
            applyFilter();
            void fetchPos(null, { page: 1, resetCache: true, po: '' });
        });
        document.addEventListener('input', (e) => {
            const inp = e.target && e.target.closest ? e.target.closest('#pcbFilterRow .pcb-col-filter') : null;
            if (!inp) return;
            const key = inp.getAttribute('data-key');
            if (!key) return;
            const val = String(inp.value || '').trim();
            if (val) colFilterState[key] = val;
            else delete colFilterState[key];
            applyFilter();
        }, true);
        $('pcbColFilterReset')?.addEventListener('click', () => {
            document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => { inp.value = ''; });
            Object.keys(colFilterState).forEach((k) => { delete colFilterState[k]; });
            applyFilter();
        });
        $('pcbClearAllFilters')?.addEventListener('click', () => {
            const hadPoSearch = !!lastServerPoSearch || !!extractQuickSearchPo();
            if ($('pcbQuickSearch')) $('pcbQuickSearch').value = '';
            document.querySelectorAll('#pcbFilterRow .pcb-col-filter').forEach((inp) => { inp.value = ''; });
            Object.keys(colFilterState).forEach((k) => { delete colFilterState[k]; });
            lastServerPoSearch = '';
            clearTimeout(quickSearchTimer);
            applyFilter();
            if (hadPoSearch) void fetchPos(null, { page: 1, resetCache: true, po: '' });
        });
        $('pcbToggleColFilters')?.addEventListener('click', () => {
            const row = $('pcbFilterRow');
            const btn = $('pcbToggleColFilters');
            if (!row) return;
            const hidden = window.getComputedStyle(row).display === 'none';
            row.style.display = hidden ? 'table-row' : 'none';
            btn?.classList.toggle('is-active', hidden);
        });
        $('pcbToggleStickyScroll')?.addEventListener('click', () => {
            const wrap = $('pcbTableWrap');
            if (!wrap) return;
            applyListStickyMode(!wrap.classList.contains('is-scroll-body'));
        });
        applyListStickyMode(false); // default: expand — show all page rows
        $('pcbTable')?.querySelector('thead')?.addEventListener('click', (e) => {
            const th = e.target.closest('th.is-sortable');
            if (!th || e.target.closest('input,button,a,label')) return;
            const key = th.getAttribute('data-sort');
            if (key) toggleSort(key);
        });
        pcbTablePrefManager = initTablePreferences({
            scriptId: 'pcbTablePrefs',
            tableId: 'pcbTable',
            menuId: 'pcbColumnToggleMenu',
            toggleBtnId: 'pcbColumnToggleBtn',
            dropdownId: 'pcbColDropdown',
            statusId: 'pcbColSaveStatus',
            colAttr: 'data-col-key',
        });
        $('pcbExportBtn')?.addEventListener('click', exportCsv);
        $('pcbPrev')?.addEventListener('click', () => {
            if (page > 1) void goToPage(page - 1);
        });
        $('pcbNext')?.addEventListener('click', () => {
            if (page < (serverPageCount || 1)) void goToPage(page + 1);
        });
        const onListClick = (e) => {
            const remarksBtn = e.target.closest('button.pcb-remarks-btn');
            if (remarksBtn) {
                e.preventDefault();
                e.stopPropagation();
                const po = remarksBtn.getAttribute('data-po');
                if (po) openRemarksPopover(po);
                return;
            }
            const mailBtn = e.target.closest('button.pcb-mail-btn');
            if (mailBtn) {
                e.preventDefault();
                e.stopPropagation();
                const po = mailBtn.getAttribute('data-po');
                if (po) openMailPopover(po);
                return;
            }
            const esignBtn = e.target.closest('button.pcb-esign-btn');
            if (esignBtn) {
                e.preventDefault();
                e.stopPropagation();
                if (esignBtn.disabled || cfg.canEsign === false) {
                    alert('You do not have eSign access for PO Costing.');
                    return;
                }
                const po = esignBtn.getAttribute('data-po');
                if (po) window.open(workbenchUrl(po, { open: 'esign' }), '_blank', 'noopener');
                return;
            }
            const pdfBtn = e.target.closest('button.pcb-pdf-btn');
            if (pdfBtn) {
                e.preventDefault();
                e.stopPropagation();
                const po = pdfBtn.getAttribute('data-po');
                if (po) window.open(pdfUrl(po), '_blank', 'noopener');
                return;
            }
            const validateBtn = e.target.closest('button.pcb-validate-btn');
            if (validateBtn) {
                e.preventDefault();
                e.stopPropagation();
                if (validateBtn.disabled || validateBtn.classList.contains('is-done')) return;
                const po = validateBtn.getAttribute('data-po');
                if (po) runBulkValidation([po]);
                return;
            }
            const checkCell = e.target.closest('.pcb-check');
            const check = e.target.closest('.pcb-row-check, .pcb-card-check');
            if (check || checkCell) {
                const input = (check && check.matches('input')) ? check : (checkCell || check).querySelector('input');
                if (input) {
                    if (e.target !== input) input.checked = !input.checked;
                    setChecked(input.getAttribute('data-po'), input.checked);
                    renderTable();
                }
                e.stopPropagation();
                return;
            }
            // Only PO Number opens the workbench (new tab) — Workbench access required
            const poLink = e.target.closest('button.pcb-po-link');
            if (!poLink) return;
            e.preventDefault();
            e.stopPropagation();
            if (poLink.disabled || cfg.canWorkbench === false) {
                alert('You do not have Workbench access for PO Costing Validation.');
                return;
            }
            const po = poLink.getAttribute('data-po');
            if (po) openDrill(po);
        };
        $('pcbTableBody')?.addEventListener('click', onListClick);
        $('pcbCards')?.addEventListener('click', onListClick);

        $('pcbValidatedBtn')?.addEventListener('click', () => runBulkValidation());
        if ($('pcbValidatedBtn') && cfg.canValidate === false) {
            $('pcbValidatedBtn').hidden = true;
        }
        updateValidatedBtn();

        const unmatchedKpi = $('pcbUnmatchedKpi');
        unmatchedKpi?.addEventListener('click', (e) => {
            e.preventDefault();
            const pop = $('pcbRulesPopover');
            if (pop && !pop.hidden) closeRulesPopover();
            else openRulesPopover();
        });
        unmatchedKpi?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                unmatchedKpi.click();
            }
        });
        $('pcbRulesClose')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            closeRulesPopover();
        });
        $('pcbRulesPopover')?.addEventListener('click', (e) => {
            if (e.target === $('pcbRulesPopover')) closeRulesPopover();
        });
        $('pcbRemarksClose')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            closeRemarksPopover();
        });
        $('pcbRemarksPopover')?.addEventListener('click', (e) => {
            if (e.target === $('pcbRemarksPopover')) closeRemarksPopover();
        });
        $('pcbMailClose')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            closeMailPopover();
        });
        $('pcbMailPopover')?.addEventListener('click', (e) => {
            if (e.target === $('pcbMailPopover')) closeMailPopover();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeRulesPopover();
                closeRemarksPopover();
                closeMailPopover();
            }
        });

        $('pcbOpenValidateBtn')?.addEventListener('click', () => {
            if (cfg.canValidate === false) return;
            openValidateModal();
        });
        if ($('pcbOpenValidateBtn') && cfg.canValidate === false) {
            $('pcbOpenValidateBtn').hidden = true;
        }
        $('pcbHistoryBtn')?.addEventListener('click', () => { void openHistoryModal(); });
        $('pcwValidateClose')?.addEventListener('click', closeValidateModal);
        $('pcwValidateCancel')?.addEventListener('click', () => setComposeOpen(false));
        $('pcwValidateBackdrop')?.addEventListener('click', closeValidateModal);
        $('pcwValidateSave')?.addEventListener('click', () => { void saveValidationLog(); });
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
        const userSearch = $('pcwUserSearch');
        userSearch?.addEventListener('input', () => {
            const q = String(userSearch.value || '').trim();
            if (assignedUser.username && q !== assignedUser.username) {
                assignedUser = { id: '', username: '' };
                if ($('pcwUserId')) $('pcwUserId').value = '';
                if ($('pcwUserName')) $('pcwUserName').value = '';
            }
            void searchUsers(q);
        });
        userSearch?.addEventListener('focus', () => {
            void searchUsers(String(userSearch.value || '').trim());
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
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            if ($('pcwValidateModal') && !$('pcwValidateModal').hidden) closeValidateModal();
            if ($('pcwHistoryModal') && !$('pcwHistoryModal').hidden) closeHistoryModal();
        });

        $('pcbOffcanvasClose')?.addEventListener('click', closeOffcanvas);
        $('pcbOffcanvasBackdrop')?.addEventListener('click', closeOffcanvas);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeOffcanvas();
        });
        $('pcbItemSearch')?.addEventListener('input', () => runItemFilters());
        // Capture-phase so column filters always update state + re-render
        document.addEventListener('input', (e) => {
            const inp = e.target && e.target.closest ? e.target.closest('#pcbItemFilterRow .pcb-item-col-filter') : null;
            if (!inp) return;
            setItemColFilterValue(inp);
            runItemFilters();
        }, true);
        $('pcbItemFilterRow')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') e.preventDefault();
            if (e.key === 'Escape') {
                const inp = e.target.closest?.('.pcb-item-col-filter');
                if (inp) {
                    inp.value = '';
                    setItemColFilterValue(inp);
                    runItemFilters();
                }
            }
        });
        $('pcbItemColFilterReset')?.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearItemFilters({ keepSearch: true });
            runItemFilters();
        });
        $('pcbItemClearFilters')?.addEventListener('click', () => {
            clearItemFilters();
            runItemFilters();
        });
        // Default: clean item table — filters open only on button click
        if ($('pcbItemFilterRow')) $('pcbItemFilterRow').style.display = 'none';
        $('pcbItemToggleFilters')?.classList.remove('is-active');
        $('pcbItemToggleFilters')?.addEventListener('click', () => {
            const row = $('pcbItemFilterRow');
            const btn = $('pcbItemToggleFilters');
            if (!row) return;
            const show = row.style.display === 'none';
            row.style.display = show ? '' : 'none';
            btn?.classList.toggle('is-active', show);
        });
        expandItemTableFully();
        pcbItemTablePrefManager = initTablePreferences({
            scriptId: 'pcbItemTablePrefs',
            tableId: 'pcbItemTable',
            menuId: 'pcbItemColumnMenu',
            toggleBtnId: 'pcbItemColToggle',
            dropdownId: 'pcbItemColDropdown',
            statusId: 'pcbItemColSaveStatus',
            colAttr: 'data-col-key',
        });
        syncItemGroupHeaders();
        $('pcbItemExcelBtn')?.addEventListener('click', exportItemCsv);
        $('pcbItemSelectAll')?.addEventListener('change', (e) => {
            const on = !!e.target.checked;
            filterItemRows().forEach((r) => { r._checked = on; });
            renderItemTable();
        });
        $('pcbItemDetailClose')?.addEventListener('click', () => hideItemDetail());
        $('pcbItemBody')?.addEventListener('click', (e) => {
            const quoteHit = e.target.closest('a.pcw-quote-link, td[data-col-id="costing_quotation_no"]');
            if (quoteHit) {
                e.preventDefault();
                e.stopPropagation();
                const href = quoteHit.getAttribute('href') || zcostViewUrl(quoteHit.getAttribute('data-quote') || '');
                if (href) window.open(href, '_blank', 'noopener,noreferrer');
                return;
            }
            const check = e.target.closest('.pcb-item-check');
            if (check) {
                const idx = Number(check.getAttribute('data-idx'));
                if (!Number.isNaN(idx) && activeItemRows[idx]) {
                    activeItemRows[idx]._checked = check.checked;
                    if (check.checked) {
                        showItemDetail(idx, { scroll: true });
                    } else {
                        renderItemTable();
                    }
                }
                e.stopPropagation();
                return;
            }
            const tr = e.target.closest('tr.pcw-row');
            if (!tr) return;
            const idx = Number(tr.getAttribute('data-idx'));
            if (Number.isNaN(idx)) return;
            // Toggle closed if same row clicked again
            if (idx === activeItemIdx && !$('pcbItemDetail')?.hidden) {
                hideItemDetail();
                return;
            }
            showItemDetail(idx, { scroll: true });
        });
    }

    bind();
    {
        const urlPage = Number(new URLSearchParams(window.location.search).get('page') || 1);
        const startPage = Number.isFinite(urlPage) && urlPage > 1 ? Math.floor(urlPage) : 1;
        void fetchPos(null, startPage > 1 ? { page: startPage, resetCache: true } : {});
    }
})();
