/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 18/11/2026
 * DESCRIPTION : Related PO — header Material/Brand filters + full-screen NotApproved list
*/
(function () {
    'use strict';

    const page = document.getElementById('poCostingPage');
    const panel = document.getElementById('pocPanelRelated');
    if (!page || !panel) return;

    const dataUrl = page.getAttribute('data-related-po-url') || '';
    const sentLogUrl = page.getAttribute('data-related-po-sent-log-url') || '';
    const poBase = page.getAttribute('data-po-base') || '';
    const pdfBase = page.getAttribute('data-pdf-url') || '';
    const canEsign = page.getAttribute('data-can-esign') !== '0';
    const canSent = page.getAttribute('data-can-sent') !== '0';
    const $ = (id) => document.getElementById(id);
    const headerFilters = $('pocRelatedHeaderFilters');

    const COLS = Array.from(panel.querySelectorAll('#pocRelatedTable thead .rpt-header-row th')).map((th) => ({
        key: th.getAttribute('data-key') || '',
        type: th.getAttribute('data-type') || 'text',
        index: Number(th.getAttribute('data-col') || 0),
    }));

    const money = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const qty = (n) => Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 3 });
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));
    const fmtDate = (iso) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
        return m ? `${m[3]}-${m[2]}-${m[1]}` : (iso ? esc(iso) : '—');
    };

    let allRows = [];
    let items = [];
    let loaded = false;
    let loading = false;
    let sortCol = -1;
    let sortDir = 1;
    let filtersVisible = false;
    let activeMat = 'all';
    let activeBrand = '';
    let columnPrefManager = null;
    let lastQueryKey = '';

    function matLabel(v) {
        if (v === 'fabric') return 'Fabric';
        if (v === 'trims') return 'Trims';
        return 'All Materials';
    }

    function selectedBrandCodes() {
        return String($('pocRelatedBrand')?.value || '')
            .split(',')
            .map((v) => v.trim())
            .filter(Boolean);
    }

    function brandPillLabel(codes) {
        if (!codes.length) return 'All Brands';
        if (codes.length === 1) {
            const item = document.querySelector(`#pocRelatedBrandList .po-multi-item[data-code="${CSS.escape(codes[0])}"] .po-multi-item-name`);
            return item?.textContent?.trim() || codes[0];
        }
        return `${codes.length} Brands`;
    }

    function readHeaderFilters() {
        activeMat = String($('pocRelatedMatCat')?.value || 'all').toLowerCase();
        if (!['all', 'fabric', 'trims'].includes(activeMat)) activeMat = 'all';
        activeBrand = selectedBrandCodes().join(',');
    }

    function updateFilterPill() {
        const el = $('pocRelatedActiveFilters');
        if (!el) return;
        el.textContent = `${matLabel(activeMat)} · ${brandPillLabel(selectedBrandCodes())}`;
    }

    function bindBrandMultiSelect() {
        const dd = $('pocRelatedBrandDropdown');
        if (!dd) return null;

        const trigger = $('pocRelatedBrandTrigger');
        const menu = $('pocRelatedBrandMenu');
        const hiddenInput = $('pocRelatedBrand');
        const labelEl = $('pocRelatedBrandLabel');
        const badgeEl = $('pocRelatedBrandBadge');
        const countInfoEl = $('pocRelatedBrandCountInfo');
        const searchInput = $('pocRelatedBrandSearch');
        const searchClear = $('pocRelatedBrandSearchClear');
        const selectAllBtn = $('pocRelatedBrandSelectAll');
        const clearAllBtn = $('pocRelatedBrandClearAll');
        const applyBtn = $('pocRelatedBrandApply');
        const cancelBtn = $('pocRelatedBrandCancel');
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
            if (countInfoEl) countInfoEl.textContent = `${checkedCount} of ${cbs.length} selected`;
            updateItemHighlight();
        }

        function updateTriggerUI(selected) {
            if (!selected.length) {
                if (labelEl) labelEl.textContent = 'All Brands';
                if (badgeEl) { badgeEl.style.display = 'none'; badgeEl.textContent = '0'; }
            } else if (selected.length === 1) {
                const checkedCb = checkboxes().find((c) => c.value === selected[0]);
                const name = checkedCb
                    ?.closest('.po-multi-item')
                    ?.querySelector('.po-multi-item-name')
                    ?.textContent;
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
                item.style.display = (!query || code.includes(query) || name.includes(query)) ? 'flex' : 'none';
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
            updateTriggerUI(selected);
            openDropdown(false);
            if (val !== oldVal) void loadRelated(true);
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
        updateTriggerUI(selectedBrandCodes());
        return true;
    }

    function setHeaderFiltersVisible(on) {
        if (!headerFilters) return;
        headerFilters.hidden = !on;
        headerFilters.style.display = on ? '' : 'none';
        headerFilters.setAttribute('aria-hidden', on ? 'false' : 'true');
    }

    function setResultsMode() {
        panel.setAttribute('data-related-mode', 'results');
        page.classList.add('is-related-po', 'is-related-results', 'is-workflow');
        page.classList.remove('is-related-lookup', 'is-lookup');
        const results = $('pocRelatedResults');
        if (results) {
            results.hidden = false;
            results.style.display = '';
        }
        setHeaderFiltersVisible(true);
        updateFilterPill();
    }

    function hideRelatedChrome() {
        setHeaderFiltersVisible(false);
        page.classList.remove('is-related-po', 'is-related-results', 'is-related-lookup');
    }

    function relatedReturnUrl() {
        try {
            const u = new URL('po-costing', window.location.origin);
            u.searchParams.set('panel', 'related');
            return u.pathname + u.search;
        } catch (_) {
            return 'po-costing?panel=related';
        }
    }

    function esignUrl(po) {
        try {
            const u = new URL(poBase || 'purchase-order', window.location.origin);
            u.searchParams.set('view', 'costing');
            u.searchParams.set('po', String(po || ''));
            u.searchParams.set('open', 'esign');
            u.searchParams.set('from', 'related');
            u.searchParams.set('return', relatedReturnUrl());
            return u.pathname + u.search;
        } catch (_) {
            return `${poBase || 'purchase-order'}?view=costing&po=${encodeURIComponent(po)}&open=esign&from=related&return=${encodeURIComponent(relatedReturnUrl())}`;
        }
    }

    function pdfUrl(po) {
        try {
            const u = new URL(pdfBase || 'purchase-order/pdf', window.location.origin);
            u.searchParams.set('po', String(po || ''));
            return u.pathname + u.search;
        } catch (_) {
            return `${pdfBase || 'purchase-order/pdf'}?po=${encodeURIComponent(po)}`;
        }
    }

    function normalizeStatus(v) {
        const raw = String(v || '').trim().toLowerCase();
        if (!raw) return '';
        if (raw === 'draft') return 'Draft';
        if (raw === 'review' || raw === 'passed' || raw === 'pass' || raw === 'reviewed') return 'Review';
        if (['released', 'release', 'related', 'relasted', 'moved', 'move'].includes(raw)) return 'Released';
        if (['draft', 'review', 'released'].includes(raw)) {
            return raw.charAt(0).toUpperCase() + raw.slice(1);
        }
        const pretty = String(v || '').trim();
        return pretty;
    }

    function poDigits(v) {
        return String(v || '').replace(/\D/g, '').replace(/^0+/, '') || '';
    }

    /**
     * Patch Status / Remarks on an already-loaded Related PO row (after Save Signed PDF).
     */
    function applyStatusUpdate(detail) {
        if (!detail || !loaded) return false;
        const target = poDigits(detail.po);
        if (!target) return false;
        const status = normalizeStatus(detail.status);
        const remarks = detail.remarks != null ? String(detail.remarks).trim() : null;
        let hit = false;
        allRows.forEach((rec) => {
            if (poDigits(rec.purchase_order) !== target) return;
            if (status) rec.status_update = status;
            if (remarks != null) rec.status_remarks = remarks;
            if (detail.updated_by) rec.status_updated_by = String(detail.updated_by);
            if (detail.updated_on) rec.status_updated_on = String(detail.updated_on);
            hit = true;
        });
        if (hit) {
            renderRows(allRows);
        }
        return hit;
    }

    function applyPendingStatusFromStorage() {
        try {
            const raw = localStorage.getItem('poc-related-status-v1');
            if (!raw) return;
            const payload = JSON.parse(raw);
            if (!payload || !payload.po) return;
            // Ignore stale broadcasts older than 30 minutes
            if (payload.t && (Date.now() - Number(payload.t)) > 30 * 60 * 1000) return;
            applyStatusUpdate(payload);
        } catch (_) {}
    }

    function bindStatusSync() {
        window.addEventListener('poc-related-status', (e) => {
            applyStatusUpdate(e?.detail || {});
        });
        window.addEventListener('storage', (e) => {
            if (e.key !== 'poc-related-status-v1' || !e.newValue) return;
            try {
                applyStatusUpdate(JSON.parse(e.newValue));
            } catch (_) {}
        });
        try {
            const bc = new BroadcastChannel('poc-related-status');
            bc.onmessage = (ev) => applyStatusUpdate(ev?.data || {});
        } catch (_) {}
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') return;
            if (!panel.classList.contains('is-active') || panel.hidden) return;
            applyPendingStatusFromStorage();
        });
        window.addEventListener('focus', () => {
            if (!panel.classList.contains('is-active') || panel.hidden) return;
            applyPendingStatusFromStorage();
        });
    }

    function materialTypeHtml(rec) {
        const raw = String(rec.material_type || '').trim();
        if (!raw || raw === '—') {
            return `<span class="poc-related-mat-badge is-empty">—</span>`;
        }
        const parts = raw.split(',').map((p) => p.trim()).filter(Boolean);
        if (!parts.length) {
            return `<span class="poc-related-mat-badge is-empty">—</span>`;
        }
        return parts.map((part) => {
            const tone = part.toLowerCase() === 'fabric' ? 'fabric'
                : (part.toLowerCase() === 'trims' ? 'trims' : 'other');
            const icon = tone === 'fabric' ? 'texture' : (tone === 'trims' ? 'sell' : 'category');
            return `<span class="poc-related-mat-badge is-${tone}">
                <span class="material-icons-round" aria-hidden="true">${icon}</span>
                <em>${esc(part)}</em>
            </span>`;
        }).join(' ');
    }

    function statusIcon(status) {
        if (status === 'Draft') return 'edit_note';
        if (status === 'Review') return 'rate_review';
        if (status === 'Released') return 'verified';
        return 'radio_button_unchecked';
    }

    function statusBadgeHtml(rec) {
        // Default Status column to Draft when no po_release_log row yet
        const status = normalizeStatus(rec.status_update) || 'Draft';
        const tone = status.toLowerCase().replace(/[^a-z]/g, '') || 'draft';
        const titleBits = [status];
        if (rec.status_updated_by) titleBits.push(`By ${rec.status_updated_by}`);
        if (rec.status_updated_on) titleBits.push(String(rec.status_updated_on));
        else if (!normalizeStatus(rec.status_update)) titleBits.push('Default');
        return `<td class="poc-related-status-cell" data-col-id="status_update">
            <span class="poc-related-status-badge is-${esc(tone)}" title="${esc(titleBits.join(' · '))}">
                <span class="material-icons-round" aria-hidden="true">${statusIcon(status)}</span>
                <em>${esc(status)}</em>
            </span>
        </td>`;
    }

    function remarksReadOnlyHtml(rec) {
        const saved = String(rec.status_remarks || '').trim();
        if (!saved) {
            return `<td class="poc-related-remarks-cell" data-col-id="status_remarks">
                <span class="poc-related-remarks-chip is-empty" title="No remarks">
                    <span class="material-icons-round" aria-hidden="true">chat_bubble_outline</span>
                    <em>—</em>
                </span>
            </td>`;
        }
        return `<td class="poc-related-remarks-cell" data-col-id="status_remarks">
            <span class="poc-related-remarks-chip" title="${esc(saved)}">
                <span class="material-icons-round" aria-hidden="true">forum</span>
                <em class="poc-related-remarks-view">${esc(saved)}</em>
            </span>
        </td>`;
    }

    function cellHtml(key, rec, index) {
        switch (key) {
            case 'sno':
                return `<td class="num sno" data-col-id="sno">${index + 1}</td>`;
            case 'purchase_order':
                return `<td data-col-id="purchase_order"><span class="po-item-tag">${esc(rec.purchase_order)}</span></td>`;
            case 'date':
                return `<td data-col-id="date">${fmtDate(rec.date)}</td>`;
            case 'plant':
                return `<td data-col-id="plant"><span class="po-plant-badge">${dash(rec.plant)}</span></td>`;
            case 'material_type':
                return `<td data-col-id="material_type">${materialTypeHtml(rec)}</td>`;
            case 'items_count':
                return `<td class="num" data-col-id="items_count"><span class="po-items-badge">${qty(rec.items_count)}</span></td>`;
            case 'total_qty':
                return `<td class="num" data-col-id="total_qty"><span class="qty-val">${qty(rec.total_qty)}</span></td>`;
            case 'total_value':
                return `<td class="num po-value" data-col-id="total_value"><span class="qty-val">${money(rec.total_value)}</span></td>`;
            case 'status_update':
                return statusBadgeHtml(rec);
            case 'status_remarks':
                return remarksReadOnlyHtml(rec);
            case 'sent':
                return sentCellHtml(rec);
            case 'sent_receive_log':
                return sentReceiveLogHtml(rec);
            case 'esign':
                return `<td data-col-id="esign"><button type="button" class="pcb-esign-btn is-pending" data-po="${esc(rec.purchase_order)}"
                    title="${canEsign ? 'Open eSign' : 'eSign permission required'}"
                    ${canEsign ? '' : 'disabled'}>
                    <span class="material-icons-round" aria-hidden="true">draw</span>
                    <span>eSign</span>
                </button></td>`;
            case 'pdf':
                return `<td data-col-id="pdf"><button type="button" class="pcb-pdf-btn" data-po="${esc(rec.purchase_order)}" title="View PO PDF">
                    <span class="material-icons-round">picture_as_pdf</span>
                    View PDF
                </button></td>`;
            default:
                return `<td data-col-id="${esc(key)}">${dash(rec[key])}</td>`;
        }
    }

    function sentCellHtml(rec) {
        const status = String(rec.sent_receive_status || '').trim();
        const to = String(rec.mail_to || '').trim();
        const email = String(rec.mail_email || '').trim();
        const note = String(rec.mail_status || rec.mail_remarks || '').trim();
        const at = String(rec.mail_at || '').trim();
        const done = !!status || !!to || !!email;
        const tip = [
            status || 'Send PO mail',
            to ? `To ${to}` : '',
            email,
            note,
            at,
        ].filter(Boolean).join(' · ');
        const label = done ? (to || status || 'Sent') : 'Sent';
        const icon = done ? 'mark_email_read' : 'send';
        const tone = done ? 'is-done' : 'is-pending';
        return `<td data-col-id="sent">
            <button type="button" class="pcb-sent-btn ${tone}" data-po="${esc(rec.purchase_order)}"
                title="${esc(canSent ? tip : 'Sent permission required')}"
                ${canSent ? '' : 'disabled'}>
                <span class="material-icons-round" aria-hidden="true">${icon}</span>
                <span>${esc(label)}</span>
            </button>
        </td>`;
    }

    function sentReceiveLogHtml(rec) {
        const status = String(rec.sent_receive_status || '').trim();
        const to = String(rec.mail_to || '').trim();
        const from = String(rec.mail_from || '').trim();
        const email = String(rec.mail_email || '').trim();
        const hasLog = !!status || !!to || !!from || !!email;
        const cls = String(status).toLowerCase().includes('receiv') ? 'is-receive' : (hasLog ? 'is-sent' : 'is-empty');
        const userLine = to || from || (hasLog ? status : 'No log');
        const mailLine = email || (hasLog ? status : '—');
        const tip = hasLog
            ? [status, from && to ? `${from} → ${to}` : (to || from), email, rec.mail_at].filter(Boolean).join(' · ')
            : 'View sent / receive log';
        return `<td data-col-id="sent_receive_log">
            <button type="button" class="pcb-mail-cell pcb-mail-btn poc-related-mail-btn ${cls}"
                data-po="${esc(rec.purchase_order)}" title="${esc(tip)}">
                <span class="pcb-mail-user">${esc(userLine)}</span>
                <span class="pcb-mail-email">${esc(mailLine)}</span>
            </button>
        </td>`;
    }

    function closeSentReceiveLog() {
        const pop = $('pocRelatedMailPopover');
        if (pop) pop.hidden = true;
    }

    async function openSentReceiveLog(po) {
        const pop = $('pocRelatedMailPopover');
        const title = $('pocRelatedMailTitle');
        const body = $('pocRelatedMailBody');
        if (!pop || !body || !po) return;
        if (title) title.textContent = `PO ${po}`;
        body.innerHTML = `<div class="pcb-mail-note"><span class="pcb-mail-label">Loading…</span></div>`;
        pop.hidden = false;

        const row = allRows.find((r) => String(r.purchase_order) === String(po)) || {};
        let rows = [];
        try {
            if (sentLogUrl) {
                const res = await fetch(`${sentLogUrl}?po=${encodeURIComponent(po)}&_=${Date.now()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                const json = await res.json().catch(() => ({}));
                if (res.ok && json.success !== false && Array.isArray(json.data?.rows)) {
                    rows = json.data.rows;
                }
            }
        } catch (_) {
            rows = [];
        }

        if (!rows.length) {
            const status = String(row.sent_receive_status || '').trim();
            if (status || row.mail_to || row.mail_from) {
                rows = [{
                    sent_receive_status: status || 'Sent',
                    from_username: row.mail_from || '',
                    to_username: row.mail_to || '',
                    to_email: row.mail_email || '',
                    status: row.mail_status || '',
                    remarks: row.mail_remarks || row.mail_note || '',
                    created_at: row.mail_at || '',
                }];
            }
        }

        if (!rows.length) {
            body.innerHTML = `<div class="pcb-remarks-empty">No sent / receive log for this PO.</div>`;
            return;
        }

        body.innerHTML = rows.map((item, idx) => {
            const status = String(item.sent_receive_status || item.status || 'Sent').trim();
            const isRecv = status.toLowerCase().includes('receiv');
            const from = String(item.from_username || '').trim();
            const to = String(item.to_username || '').trim();
            const email = String(item.to_email || '').trim();
            const note = String(item.remarks || '').trim();
            const at = String(item.created_at || '').trim();
            const user = to || from || '—';
            const statusCls = isRecv ? 'is-receive' : 'is-sent';
            return `
                <article class="poc-related-mail-item ${statusCls}">
                    <div class="pcb-mail-hero ${statusCls}">
                        <span class="pcb-mail-avatar" aria-hidden="true">${esc((user || '?').charAt(0).toUpperCase())}</span>
                        <div class="pcb-mail-hero-text">
                            <div class="pcb-mail-hero-top">
                                <strong class="pcb-mail-hero-name">${esc(user)}</strong>
                                <span class="pcb-mail-status ${statusCls}">${esc(status)}</span>
                                ${idx === 0 ? '<span class="pcb-remarks-item-tag">Latest</span>' : ''}
                            </div>
                            <span class="pcb-mail-hero-email">${esc(email || 'No mail ID')}</span>
                            ${at ? `<time class="pcb-mail-hero-when">${esc(at)}</time>` : ''}
                        </div>
                    </div>
                    <div class="pcb-mail-grid">
                        <div class="pcb-mail-field">
                            <span class="pcb-mail-label">From</span>
                            <span class="pcb-mail-value">${esc(from || '—')}</span>
                        </div>
                        <div class="pcb-mail-field">
                            <span class="pcb-mail-label">To</span>
                            <span class="pcb-mail-value">${esc(to || '—')}</span>
                        </div>
                        <div class="pcb-mail-field">
                            <span class="pcb-mail-label">Mail ID</span>
                            <span class="pcb-mail-value">${email ? `<a href="mailto:${esc(email)}">${esc(email)}</a>` : '—'}</span>
                        </div>
                        <div class="pcb-mail-field">
                            <span class="pcb-mail-label">Status</span>
                            <span class="pcb-mail-value">${esc(String(item.status || status || '—'))}</span>
                        </div>
                    </div>
                    ${note ? `<div class="pcb-mail-note"><span class="pcb-mail-label">Note</span><div class="pcb-mail-note-body">${esc(note)}</div></div>` : ''}
                </article>
            `;
        }).join('');
    }

    function fillStats(t, visibleCount, statusCounts) {
        const pos = visibleCount != null ? visibleCount : (t.pos_count || 0);
        if ($('pocRelStat-pos')) $('pocRelStat-pos').textContent = qty(pos);
        if ($('pocRelStat-items')) $('pocRelStat-items').textContent = qty(t.items_count || 0);
        if ($('pocRelStat-qty')) $('pocRelStat-qty').textContent = qty(t.total_qty || 0);
        if ($('pocRelStat-value')) $('pocRelStat-value').textContent = money(t.total_value || 0);
        if ($('pocRelStatUnit-value')) $('pocRelStatUnit-value').textContent = t.currency || 'INR';
        if ($('pocRelStat-suppliers')) $('pocRelStat-suppliers').textContent = qty(t.suppliers_count || 0);
        if ($('pocRelatedCount')) {
            $('pocRelatedCount').textContent = `Showing ${qty(pos)} purchase order${pos === 1 ? '' : 's'}`;
        }
        const sc = statusCounts || t.status_counts || { released: 0, draft: 0, review: 0 };
        if ($('pocRelatedStatReleased')) $('pocRelatedStatReleased').textContent = qty(sc.released || 0);
        if ($('pocRelatedStatDraft')) $('pocRelatedStatDraft').textContent = qty(sc.draft || 0);
        if ($('pocRelatedStatReview')) $('pocRelatedStatReview').textContent = qty(sc.review || 0);
    }

    function countStatusTotals(list) {
        const out = { released: 0, draft: 0, review: 0 };
        (list || []).forEach((it) => {
            const rec = it?.rec || it || {};
            const st = normalizeStatus(rec.status_update) || 'Draft';
            if (st === 'Released') out.released += 1;
            else if (st === 'Review') out.review += 1;
            else out.draft += 1;
        });
        return out;
    }

    function setFoot(key, html) {
        const th = panel.querySelector(`tfoot [data-foot-key="${key}"]`);
        if (th) th.innerHTML = html;
    }

    function updateFooter(visible) {
        let itemsSum = 0;
        let qtySum = 0;
        let valueSum = 0;
        visible.forEach((it) => {
            itemsSum += Number(it.rec.items_count || 0);
            qtySum += Number(it.rec.total_qty || 0);
            valueSum += Number(it.rec.total_value || 0);
        });
        setFoot('items_count', qty(itemsSum));
        setFoot('total_qty', qty(qtySum));
        setFoot('total_value', money(valueSum));
        ['purchase_order', 'purchase_order_type', 'po_type_name', 'date', 'plant',
            'supplier', 'supplier_full_name', 'brand_name', 'material_type', 'mat_type_desc', 'material_group', 'unit', 'currency',
            'status_update', 'status_remarks', 'sent', 'sent_receive_log', 'esign', 'pdf'
        ].forEach((k) => setFoot(k, ''));
    }

    function parseNumCond(q) {
        const m = String(q || '').trim().match(/^([><]=?|=)\s*(-?\d+(?:\.\d+)?)$/);
        return m ? { op: m[1], val: parseFloat(m[2]) } : null;
    }

    function activeColFilters() {
        const filters = [];
        let active = 0;
        panel.querySelectorAll('#pocRelatedFilterRow .rpt-col-input').forEach((input) => {
            const raw = input.value.trim();
            const cb = input.parentElement?.querySelector('.rpt-col-clear');
            input.classList.toggle('has-val', raw !== '');
            if (cb) cb.style.display = raw !== '' ? 'block' : 'none';
            if (raw === '') return;
            active += 1;
            filters.push({
                col: parseInt(input.getAttribute('data-col'), 10),
                key: input.getAttribute('data-key') || COLS[parseInt(input.getAttribute('data-col'), 10)]?.key,
                lower: raw.toLowerCase(),
                cond: input.hasAttribute('data-numeric') ? parseNumCond(raw) : null,
            });
        });
        const badge = $('pocRelatedFilterBadge');
        if (badge) {
            badge.hidden = active === 0;
            badge.textContent = String(active);
        }
        return filters;
    }

    function matches(item, query, filters) {
        if (query && !item.searchText.includes(query)) return false;
        for (const f of filters) {
            if (f.cond) {
                const n = Number(item.rec[f.key]) || 0;
                const { op, val } = f.cond;
                if ((op === '>' && !(n > val)) || (op === '>=' && !(n >= val)) || (op === '<' && !(n < val)) || (op === '<=' && !(n <= val)) || (op === '=' && n !== val)) {
                    return false;
                }
            } else {
                const idx = COLS.findIndex((c) => c.key === f.key);
                const hay = String(
                    (idx >= 0 ? item.texts[idx] : item.texts[f.col]) || ''
                ).toLowerCase();
                if (!hay.includes(f.lower)) return false;
            }
        }
        return true;
    }

    function updateSortHeaders() {
        panel.querySelectorAll('#pocRelatedTable thead .rpt-header-row th').forEach((th, i) => {
            th.classList.toggle('is-sorted-asc', i === sortCol && sortDir === 1);
            th.classList.toggle('is-sorted-desc', i === sortCol && sortDir === -1);
        });
    }

    function applyFilter() {
        const query = String($('pocRelatedSearch')?.value || '').trim().toLowerCase();
        const clearBtn = $('pocRelatedSearchClear');
        if (clearBtn) clearBtn.hidden = query === '';
        const filters = activeColFilters();
        let visible = items.filter((it) => {
            it.visible = matches(it, query, filters);
            return it.visible;
        });

        if (sortCol >= 0) {
            const key = COLS[sortCol]?.key;
            const type = COLS[sortCol]?.type;
            visible = visible.slice().sort((a, b) => {
                let av = a.rec[key];
                let bv = b.rec[key];
                if (key === 'sno') {
                    av = a.origIndex;
                    bv = b.origIndex;
                }
                if (type === 'num') return (Number(av) - Number(bv)) * sortDir;
                return String(av ?? '').localeCompare(String(bv ?? ''), undefined, { numeric: true }) * sortDir;
            });
        }

        const body = $('pocRelatedBody');
        const empty = $('pocRelatedEmpty');
        body.querySelectorAll('tr[data-orig-sno]').forEach((tr) => tr.remove());
        if (!visible.length) {
            if (empty) empty.hidden = false;
        } else {
            if (empty) empty.hidden = true;
            const frag = document.createDocumentFragment();
            visible.forEach((it, idx) => {
                const snoTd = it.tr.querySelector('td.sno');
                if (snoTd) snoTd.textContent = String(idx + 1);
                frag.appendChild(it.tr);
            });
            if (empty) body.insertBefore(frag, empty);
            else body.appendChild(frag);
        }

        const suppliers = new Set();
        let itemsSum = 0;
        let qtySum = 0;
        let valueSum = 0;
        let currency = 'INR';
        visible.forEach((it) => {
            itemsSum += Number(it.rec.items_count || 0);
            qtySum += Number(it.rec.total_qty || 0);
            valueSum += Number(it.rec.total_value || 0);
            if (it.rec.supplier) suppliers.add(String(it.rec.supplier));
            if (it.rec.currency) currency = it.rec.currency;
        });
        fillStats({
            pos_count: visible.length,
            items_count: itemsSum,
            total_qty: qtySum,
            total_value: valueSum,
            suppliers_count: suppliers.size,
            currency,
        }, visible.length, countStatusTotals(visible));
        updateFooter(visible);
        updateSortHeaders();
        // Reorder body/footer only — never touch the filter row while typing (keeps focus)
        if (columnPrefManager && typeof columnPrefManager.refresh === 'function') {
            columnPrefManager.refresh({ skipFilterRow: true });
        }
    }

    function initRelatedColumnManager() {
        const scriptNode = $('pocRelatedTablePrefs');
        const table = $('pocRelatedTable');
        const menu = $('pocRelatedColumnToggleMenu');
        const dropdown = $('pocRelatedColDropdown');
        const toggleBtn = $('pocRelatedColumnToggleBtn');
        const statusEl = $('pocRelatedColSaveStatus');
        if (!scriptNode || !table || !menu || !toggleBtn) return null;

        let config = null;
        try { config = JSON.parse(scriptNode.textContent || 'null'); } catch (_) { return null; }
        if (!config || !Array.isArray(config.columns)) return null;

        const columns = config.columns;
        const defaultOrder = columns.map((c) => c.id);
        const isLocked = (id) => columns.some((c) => c.id === id && c.locked);
        const labels = Object.fromEntries(columns.map((c) => [c.id, c.label]));
        let currentState = {
            columnOrder: [...defaultOrder],
            hiddenColumns: Array.isArray(config.defaultHidden) ? [...config.defaultHidden] : [],
        };
        let appliedOrder = [...defaultOrder];

        let styleEl = $('pocRelatedTableColStyle');
        if (!styleEl) {
            styleEl = document.createElement('style');
            styleEl.id = 'pocRelatedTableColStyle';
            document.head.appendChild(styleEl);
        }

        function applyHidden(hidden) {
            styleEl.textContent = (hidden || []).map((id) =>
                `#pocRelatedTable [data-col-id="${id}"]{display:none !important;}`
            ).join('\n');
            toggleBtn.classList.toggle('is-active', !!(hidden && hidden.length));
        }

        function applyOrder(order, opts = {}) {
            appliedOrder = [...order];
            const rows = Array.from(table.querySelectorAll('thead tr, tbody tr, tfoot tr')).filter(
                (tr) => !tr.classList.contains('rpt-table-loading')
                    && !tr.classList.contains('rpt-table-no-match')
                    && tr.children.length > 1
                    && !(opts.skipFilterRow && tr.id === 'pocRelatedFilterRow')
            );
            rows.forEach((tr) => {
                const byId = {};
                Array.from(tr.children).forEach((cell) => {
                    const key = cell.getAttribute('data-col-id') || cell.getAttribute('data-key');
                    if (key) byId[key] = cell;
                });
                const frag = document.createDocumentFragment();
                order.forEach((id) => { if (byId[id]) frag.appendChild(byId[id]); });
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
            const order = currentState.columnOrder;
            const hidden = new Set(currentState.hiddenColumns);
            const movable = order.filter((id) => !isLocked(id));
            menu.innerHTML = `
                <div class="rpt-colmgr-head">
                    <span class="rpt-cat-dd-header">Manage columns</span>
                    <span class="rpt-colmgr-count">${order.length - hidden.size} of ${order.length} shown</span>
                </div>
                <p class="rpt-colmgr-hint">Drag or use the arrows to reorder. Saved to your account automatically.</p>
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
                </button>`;
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
            const clearMarks = () => root.querySelectorAll('.is-drag-over, .is-dragging')
                .forEach((el) => el.classList.remove('is-drag-over', 'is-dragging'));
            root.addEventListener('dragstart', (e) => {
                const item = e.target.closest?.(itemSelector);
                const id = item ? idOf(item) : null;
                if (!id || isLocked(id)) return;
                dragFrom = id;
                item.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', id); } catch (_) {}
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
                if (prefsStore) prefsStore.reset();
                else {
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

        toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown?.classList.toggle('is-open');
            const open = dropdown?.classList.contains('is-open');
            toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) buildMenu();
        });
        document.addEventListener('click', (e) => {
            if (dropdown && !dropdown.contains(e.target)) {
                dropdown.classList.remove('is-open');
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
        });
        statusEl?.addEventListener('click', (e) => {
            if (e.target.closest('[data-col-retry]')) currentState?.error?.retry?.();
        });

        const headerRow = table.querySelector('thead tr.rpt-header-row');
        if (headerRow) {
            Array.from(headerRow.children).forEach((th) => {
                const key = th.getAttribute('data-col-id');
                if (!key || isLocked(key)) return;
                th.setAttribute('draggable', 'true');
                th.title = (th.title ? `${th.title} — ` : '') + 'drag to move column';
            });
            bindDrag(headerRow, 'th', (th) => th.getAttribute('data-col-id'));
        }

        function onPreferenceChange(next) {
            const prev = currentState;
            currentState = next;
            if (next.columnOrder.join(',') !== prev?.columnOrder?.join(',')
                || next.columnOrder.join(',') !== appliedOrder.join(',')) {
                applyOrder(next.columnOrder);
            }
            if (next.hiddenColumns.join(',') !== prev?.hiddenColumns?.join(',')) {
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
            refresh(opts = {}) {
                applyOrder(currentState.columnOrder, opts);
                applyHidden(currentState.hiddenColumns);
            },
        };
    }

    function renderRows(records) {
        const body = $('pocRelatedBody');
        const empty = $('pocRelatedEmpty');
        const loadingRow = $('pocRelatedLoading');
        if (loadingRow) loadingRow.hidden = true;
        body.querySelectorAll('tr[data-orig-sno], tr.rpt-table-loading').forEach((tr) => {
            if (tr.id !== 'pocRelatedEmpty') tr.remove();
        });
        items = records.map((rec, index) => {
            const tr = document.createElement('tr');
            tr.className = 'po-row';
            tr.setAttribute('data-orig-sno', String(index + 1));
            tr.innerHTML = COLS.map((c) => cellHtml(c.key, rec, index)).join('');
            const texts = COLS.map((c, i) => {
                if (c.key === 'sno') return String(index + 1);
                if (c.key === 'status_update') return normalizeStatus(rec.status_update) || 'Draft';
                if (c.key === 'status_remarks') return String(rec.status_remarks || '').trim();
                if (c.key === 'sent' || c.key === 'sent_receive_log') {
                    return [
                        rec.sent_receive_status,
                        rec.mail_from,
                        rec.mail_to,
                        rec.mail_email,
                        rec.mail_status,
                        rec.mail_remarks,
                        c.key === 'sent' ? (rec.sent_receive_status || rec.mail_to || rec.mail_email ? 'Sent' : 'Send') : '',
                        c.key === 'sent_receive_log' ? (rec.sent_receive_status || rec.mail_to || rec.mail_from || rec.mail_email ? 'Log' : 'No log') : '',
                    ].filter(Boolean).join(' ');
                }
                if (c.key === 'esign') return 'eSign';
                if (c.key === 'pdf') return 'View PDF PDF';
                return (tr.children[i]?.textContent || '').replace(/\s+/g, ' ').trim();
            });
            return { rec, tr, origIndex: index, texts, searchText: texts.join(' ').toLowerCase(), visible: true };
        });
        if (empty) empty.hidden = true;
        applyFilter();
    }

    function setRelatedLoadingUi(on) {
        const overlay = $('pocRelatedLoadingOverlay');
        const wrap = $('pocRelatedTableWrap');
        if (overlay) overlay.hidden = !on;
        wrap?.classList.toggle('is-loading', !!on);
        if ($('pocRelatedCount')) {
            $('pocRelatedCount').textContent = on ? 'Loading…' : ($('pocRelatedCount').textContent || '');
        }
    }

    function showLoading() {
        const body = $('pocRelatedBody');
        const empty = $('pocRelatedEmpty');
        if (empty) empty.hidden = true;
        body.querySelectorAll('tr[data-orig-sno]').forEach((tr) => tr.remove());
        let loadingRow = $('pocRelatedLoading');
        if (!loadingRow) {
            loadingRow = document.createElement('tr');
            loadingRow.id = 'pocRelatedLoading';
            loadingRow.className = 'rpt-table-loading';
            loadingRow.innerHTML = `<td colspan="${COLS.length}" class="rpt-table-skeleton">
                <div class="poc-related-inline-load" aria-label="Loading">
                    <span class="poc-related-mini-loader" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span>Loading Related POs…</span>
                </div>
                <div class="rpt-skel-rows"><i></i><i></i><i></i><i></i><i></i></div>
            </td>`;
            if (empty) body.insertBefore(loadingRow, empty);
            else body.appendChild(loadingRow);
        }
        loadingRow.hidden = false;
        setRelatedLoadingUi(true);
    }

    function queryKey() {
        return `${activeMat}|${activeBrand}`;
    }

    async function loadRelated(force) {
        if (!dataUrl) return;
        if (loading) return;
        readHeaderFilters();
        updateFilterPill();
        const key = queryKey();
        if (loaded && !force && key === lastQueryKey) {
            applyFilter();
            applyPendingStatusFromStorage();
            return;
        }
        loading = true;
        setResultsMode();
        // Keep page usable: dismiss full-page boot spinner; show in-table skeleton only
        try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}
        showLoading();
        try {
            const params = new URLSearchParams({ _: String(Date.now()) });
            if (activeMat && activeMat !== 'all') params.set('mat_cat', activeMat);
            if (activeBrand) params.set('brand', activeBrand);
            const res = await fetch(`${dataUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || 'Failed to load Related POs');
            }
            const data = json.data || {};
            allRows = Array.isArray(data.records) ? data.records : [];
            loaded = true;
            lastQueryKey = key;
            renderRows(allRows);
            applyPendingStatusFromStorage();
            if (!allRows.length) {
                fillStats(data.totals || {}, 0);
                const empty = $('pocRelatedEmpty');
                if (empty) empty.hidden = false;
            }
        } catch (err) {
            loaded = false;
            lastQueryKey = '';
            allRows = [];
            items = [];
            const body = $('pocRelatedBody');
            const empty = $('pocRelatedEmpty');
            body.querySelectorAll('tr[data-orig-sno], tr.rpt-table-loading').forEach((tr) => {
                if (tr.id !== 'pocRelatedEmpty') tr.remove();
            });
            if (empty) {
                empty.hidden = false;
                const p = empty.querySelector('p');
                if (p) p.textContent = err.message || 'Unable to load Related POs.';
            }
            fillStats({}, 0);
            if ($('pocRelatedCount')) $('pocRelatedCount').textContent = 'Load failed';
        } finally {
            loading = false;
            setRelatedLoadingUi(false);
            const loadingRow = $('pocRelatedLoading');
            if (loadingRow) loadingRow.hidden = true;
            try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}
        }
    }

    function openPanel() {
        setResultsMode();
        void loadRelated(false);
    }

    function clearFilters() {
        if ($('pocRelatedSearch')) $('pocRelatedSearch').value = '';
        panel.querySelectorAll('#pocRelatedFilterRow .rpt-col-input').forEach((input) => {
            input.value = '';
        });
        sortCol = -1;
        sortDir = 1;
        applyFilter();
    }

    function bindUi() {
        columnPrefManager = initRelatedColumnManager();
        headerFilters?.addEventListener('submit', (e) => {
            e.preventDefault();
        });
        $('pocRelatedMatCat')?.addEventListener('change', () => { void loadRelated(true); });
        bindBrandMultiSelect();
        $('pocRelatedClearHeaderBtn')?.addEventListener('click', () => {
            if ($('pocRelatedMatCat')) $('pocRelatedMatCat').value = 'all';
            const dd = $('pocRelatedBrandDropdown');
            if (dd) {
                dd.querySelectorAll('.po-multi-checkbox').forEach((c) => { c.checked = false; });
            }
            if ($('pocRelatedBrand')) $('pocRelatedBrand').value = '';
            if ($('pocRelatedBrandLabel')) $('pocRelatedBrandLabel').textContent = 'All Brands';
            if ($('pocRelatedBrandBadge')) {
                $('pocRelatedBrandBadge').style.display = 'none';
                $('pocRelatedBrandBadge').textContent = '0';
            }
            if ($('pocRelatedBrandCountInfo')) {
                const total = dd ? dd.querySelectorAll('.po-multi-checkbox').length : 0;
                $('pocRelatedBrandCountInfo').textContent = `0 of ${total} selected`;
            }
            dd?.querySelectorAll('.po-multi-item').forEach((item) => item.classList.remove('is-checked'));
            void loadRelated(true);
        });

        $('pocRelatedSearch')?.addEventListener('input', () => applyFilter());
        $('pocRelatedSearchClear')?.addEventListener('click', () => {
            if ($('pocRelatedSearch')) $('pocRelatedSearch').value = '';
            applyFilter();
        });
        $('pocRelatedRefresh')?.addEventListener('click', () => { void loadRelated(true); });
        $('pocRelatedClearFilters')?.addEventListener('click', clearFilters);
        $('pocRelatedFilterReset')?.addEventListener('click', clearFilters);

        panel.querySelectorAll('#pocRelatedFilterRow .rpt-col-input').forEach((input) => {
            input.addEventListener('input', () => applyFilter());
            input.addEventListener('keydown', (e) => {
                // Stop Enter from submitting / jumping focus to the first filter
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    applyFilter();
                }
            });
            const clear = input.parentElement?.querySelector('.rpt-col-clear');
            clear?.addEventListener('click', () => {
                input.value = '';
                input.focus();
                applyFilter();
            });
        });

        const filterRow = $('pocRelatedFilterRow');
        if (filterRow) filterRow.style.display = 'none';
        $('pocRelatedColFilters')?.classList.remove('is-active');
        $('pocRelatedColFilters')?.addEventListener('click', () => {
            filtersVisible = !filtersVisible;
            const row = $('pocRelatedFilterRow');
            if (row) row.style.display = filtersVisible ? '' : 'none';
            $('pocRelatedColFilters')?.classList.toggle('is-active', filtersVisible);
        });

        const applyRelatedSticky = (enabled) => {
            const wrap = $('pocRelatedTableWrap');
            if (!wrap) return;
            const on = !!enabled;
            wrap.classList.toggle('is-scroll-body', on);
            wrap.classList.toggle('is-expanded', !on);
            if (on) {
                wrap.style.maxHeight = '';
                wrap.style.overflow = '';
            } else {
                wrap.style.maxHeight = 'none';
                wrap.style.overflow = 'visible';
            }
            $('pocRelatedStickyScroll')?.classList.toggle('is-active', on);
            if ($('pocRelatedStickyLabel')) {
                $('pocRelatedStickyLabel').textContent = on ? 'Scroll Body Only' : 'Expand Table';
            }
            try {
                localStorage.setItem('poc_related_sticky_scroll', on ? '1' : '0');
            } catch (_) {}
        };
        let relatedStickyOn = true;
        try {
            const saved = localStorage.getItem('poc_related_sticky_scroll');
            if (saved === '0' || saved === '1') relatedStickyOn = saved === '1';
        } catch (_) {}
        applyRelatedSticky(relatedStickyOn);
        $('pocRelatedStickyScroll')?.addEventListener('click', () => {
            const wrap = $('pocRelatedTableWrap');
            if (!wrap) return;
            applyRelatedSticky(!wrap.classList.contains('is-scroll-body'));
        });

        panel.querySelectorAll('#pocRelatedTable thead .rpt-header-row th.is-sortable').forEach((th) => {
            th.addEventListener('click', () => {
                const key = th.getAttribute('data-key') || '';
                if (key === 'esign' || key === 'pdf' || key === 'sent' || key === 'sent_receive_log') return;
                const col = parseInt(th.getAttribute('data-col'), 10);
                if (sortCol === col) sortDir = -sortDir;
                else {
                    sortCol = col;
                    sortDir = 1;
                }
                applyFilter();
            });
        });

        $('pocRelatedMailClose')?.addEventListener('click', closeSentReceiveLog);
        $('pocRelatedMailPopover')?.addEventListener('click', (e) => {
            if (e.target === $('pocRelatedMailPopover')) closeSentReceiveLog();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSentReceiveLog();
        });

        $('pocRelatedBody')?.addEventListener('click', (e) => {
            const logBtn = e.target.closest('button.poc-related-mail-btn');
            if (logBtn) {
                e.preventDefault();
                const po = logBtn.getAttribute('data-po');
                if (po) void openSentReceiveLog(po);
                return;
            }
            const sentBtn = e.target.closest('button.pcb-sent-btn');
            if (sentBtn) {
                e.preventDefault();
                if (sentBtn.disabled || !canSent) {
                    alert('You do not have Sent access for PO Costing.');
                    return;
                }
                const po = sentBtn.getAttribute('data-po');
                if (!po) return;
                if (typeof window.pocOpenSentModal === 'function') {
                    window.pocOpenSentModal(po);
                } else {
                    alert('Send PO dialog is not available on this page.');
                }
                return;
            }
            const esignBtn = e.target.closest('button.pcb-esign-btn');
            if (esignBtn) {
                e.preventDefault();
                if (esignBtn.disabled || !canEsign) {
                    alert('You do not have eSign access for PO Costing.');
                    return;
                }
                const po = esignBtn.getAttribute('data-po');
                // Keep opener so cancel can close this tab if return navigation fails
                if (po) window.open(esignUrl(po), '_blank');
                return;
            }
            const pdfBtn = e.target.closest('button.pcb-pdf-btn');
            if (pdfBtn) {
                e.preventDefault();
                const po = pdfBtn.getAttribute('data-po');
                if (po) window.open(pdfUrl(po), '_blank', 'noopener');
            }
        });
    }

    window.pocRelatedPo = {
        open: openPanel,
        close: hideRelatedChrome,
        load: (force) => loadRelated(!!force),
        isLoaded: () => loaded,
        applyStatusUpdate,
    };

    bindUi();
    bindStatusSync();
    setHeaderFiltersVisible(false);

    if (panel.classList.contains('is-active') && !panel.hidden) {
        openPanel();
    }
})();
