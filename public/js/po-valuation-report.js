/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : PO Validation Report — KPIs + PO activity table / detail modal
 */
(function () {
    'use strict';

    const page = document.getElementById('poCostingPage');
    const panel = document.getElementById('pocPanelValuation');
    if (!page || !panel) return;

    const dataUrl = page.getAttribute('data-valuation-report-url') || 'po-costing/valuation-report';
    const poBase = (page.getAttribute('data-po-base') || 'purchase-order').replace(/\/$/, '');

    const els = {
        po: document.getElementById('pvrFilterPo'),
        range: document.getElementById('pvrFilterRange'),
        custom: document.getElementById('pvrCustomDates'),
        from: document.getElementById('pvrFilterFrom'),
        to: document.getElementById('pvrFilterTo'),
        search: document.getElementById('pvrTableSearch'),
        searchClear: document.getElementById('pvrTableSearchClear'),
        list: document.getElementById('pvrActivityList'),
        excelBody: document.getElementById('pvrTableBody'),
        title: document.getElementById('pocPageTitle'),
        modal: document.getElementById('pvrActivityModal'),
        modalTitle: document.getElementById('pvrModalTitle'),
        modalBody: document.getElementById('pvrModalBody'),
        modalClose: document.getElementById('pvrModalClose'),
        modalBackdrop: document.getElementById('pvrModalBackdrop'),
        statusFilters: document.getElementById('pvrStatusFilters'),
        showingCount: document.getElementById('pvrShowingCount'),
        refresh: document.getElementById('pvrRefreshBtn'),
        wrap: document.getElementById('pvrTableWrap'),
    };

    let rows = [];
    let groups = [];
    let loaded = false;
    let loading = false;
    let poTimer = null;
    let statusFilter = '';

    function esc(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtN(n) {
        return Number(n || 0).toLocaleString('en-IN');
    }

    function ymd(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function startOfWeek(d) {
        const x = new Date(d);
        const day = (x.getDay() + 6) % 7;
        x.setDate(x.getDate() - day);
        return x;
    }

    function resolveRange() {
        const key = String(els.range?.value || '');
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        let from = '';
        let to = '';
        if (key === 'today') {
            from = to = ymd(today);
        } else if (key === 'yesterday') {
            const y = new Date(today);
            y.setDate(y.getDate() - 1);
            from = to = ymd(y);
        } else if (key === 'this_week') {
            from = ymd(startOfWeek(today));
            to = ymd(today);
        } else if (key === 'previous_week') {
            const end = startOfWeek(today);
            end.setDate(end.getDate() - 1);
            const start = startOfWeek(end);
            from = ymd(start);
            to = ymd(end);
        } else if (key === 'this_month') {
            from = ymd(new Date(today.getFullYear(), today.getMonth(), 1));
            to = ymd(today);
        } else if (key === 'previous_month') {
            const first = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const last = new Date(today.getFullYear(), today.getMonth(), 0);
            from = ymd(first);
            to = ymd(last);
        } else if (key === 'custom') {
            from = String(els.from?.value || '');
            to = String(els.to?.value || '');
        }
        return { from, to };
    }

    function syncCustomVisibility() {
        if (!els.custom) return;
        els.custom.hidden = String(els.range?.value || '') !== 'custom';
    }

    function setStats(summary) {
        const s = summary || {};
        ['draft', 'review', 'released', 'approved', 'total'].forEach((k) => {
            const el = document.getElementById('pvrStat-' + k);
            if (el) el.textContent = fmtN(s[k] || 0);
        });
        // Legacy pending card id (if present)
        const pendingEl = document.getElementById('pvrStat-pending');
        if (pendingEl) pendingEl.textContent = fmtN(s.draft || s.pending || 0);
    }

    /** KPI cards must match the same PO set shown in the activity table. */
    function summaryFromGroups(poGroups) {
        const summary = { draft: 0, review: 0, released: 0, approved: 0, total: poGroups.length };
        poGroups.forEach((g) => {
            const key = String(g.stage?.filterKey || g.bucket || 'draft');
            if (Object.prototype.hasOwnProperty.call(summary, key) && key !== 'total') {
                summary[key] += 1;
            } else {
                summary.draft += 1;
            }
        });
        return summary;
    }

    function titleCase(s) {
        const t = String(s || '').trim();
        if (!t) return '';
        if (t === t.toUpperCase() && t.length <= 12) {
            return t.charAt(0).toUpperCase() + t.slice(1).toLowerCase();
        }
        return t.replace(/\w\S*/g, (w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase());
    }

    function stepMeta(r) {
        const type = String(r.event_type || '').toLowerCase();
        const status = String(r.status || '').toLowerCase();
        if (status.includes('comment')) {
            return { icon: 'chat_bubble', tone: 'orange', label: 'Commented by' };
        }
        if (type === 'sent') {
            return { icon: 'swap_horiz', tone: 'blue', label: 'Routed by' };
        }
        if (type === 'validation') {
            return { icon: 'edit_note', tone: 'orange', label: 'Prepared by' };
        }
        if (type === 'pass') {
            return { icon: 'verified_user', tone: 'green', label: 'Approved by' };
        }
        if (type === 'release') {
            if (status.includes('review')) {
                return { icon: 'rate_review', tone: 'sky', label: 'Reviewed by' };
            }
            if (status.includes('release')) {
                return { icon: 'task_alt', tone: 'teal', label: 'Released by' };
            }
            if (status.includes('draft') || status.includes('pending')) {
                return { icon: 'pending_actions', tone: 'amber', label: 'Submitted by' };
            }
            return { icon: 'person', tone: 'slate', label: 'Updated by' };
        }
        return { icon: 'history', tone: 'slate', label: 'Updated by' };
    }

    function stageMeta(latest, bucketHint) {
        const status = String(latest?.status || '').trim();
        const type = String(latest?.event_type || '').toLowerCase();
        const low = status.toLowerCase();
        const bucket = String(bucketHint || latest?.bucket || '').toLowerCase();

        if (bucket === 'approved' || low === 'approved' || (type === 'pass' && low.includes('approv'))) {
            return {
                stage: 'Approved',
                badge: 'Approved',
                badgeTone: 'teal',
                waiting: false,
                filterKey: 'approved',
            };
        }
        if (bucket === 'released' || low.includes('release')) {
            return {
                stage: 'Released',
                badge: 'Released',
                badgeTone: 'teal',
                waiting: false,
                filterKey: 'released',
            };
        }
        if (bucket === 'review' || low.includes('review') || low.includes('checker')) {
            return {
                stage: 'Review',
                badge: 'Review',
                badgeTone: 'amber',
                waiting: true,
                waitingLabel: 'Awaiting Checker Review',
                filterKey: 'review',
            };
        }
        if (low.includes('comment')) {
            return {
                stage: 'Comment',
                badge: 'Draft',
                badgeTone: 'slate',
                waiting: false,
                actionLabel: 'Commented by',
                filterKey: 'draft',
            };
        }
        // Draft (default) — includes pending / no status
        return {
            stage: 'Draft',
            badge: 'Draft',
            badgeTone: 'slate',
            waiting: false,
            filterKey: 'draft',
        };
    }

    function lastActionText(g) {
        const latest = g.latest || null;
        const stage = g.stage || {};
        const name = String(latest?.user || '—').trim() || '—';
        if (stage.waiting) return stage.waitingLabel || 'Awaiting action';
        if (stage.actionLabel) return `${stage.actionLabel} ${name}`;
        const meta = latest ? stepMeta(latest) : null;
        return meta ? `${meta.label} ${name}` : '—';
    }

    function updateReportMeta(visibleCount) {
        if (els.showingCount) {
            els.showingCount.textContent = `Showing ${fmtN(visibleCount)} purchase order${visibleCount === 1 ? '' : 's'}`;
        }
        const allGroups = groupByPo(filteredRows());
        const counts = { all: allGroups.length, draft: 0, review: 0, released: 0, approved: 0 };
        allGroups.forEach((g) => {
            const key = String(g.stage?.filterKey || 'draft');
            if (counts[key] !== undefined) counts[key] += 1;
        });
        const setPill = (id, n) => {
            const el = document.getElementById(id);
            if (el) el.textContent = fmtN(n);
        };
        setPill('pvrPillAll', counts.all);
        setPill('pvrPillDraft', counts.draft);
        setPill('pvrPillPending', counts.draft);
        setPill('pvrPillReview', counts.review);
        setPill('pvrPillReleased', counts.released);
        setPill('pvrPillApproved', counts.approved);
    }

    function relativeWhen(raw) {
        const s = String(raw || '').trim();
        if (!s) return '';
        const d = new Date(s.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return s;
        const diff = Date.now() - d.getTime();
        const mins = Math.floor(diff / 60000);
        const hours = Math.floor(mins / 60);
        const days = Math.floor(hours / 24);
        let ago = '';
        if (mins < 1) ago = 'just now';
        else if (mins < 60) ago = `${mins} min ago`;
        else if (hours < 24) ago = `${hours} hour${hours === 1 ? '' : 's'} ago`;
        else ago = `${days} day${days === 1 ? '' : 's'} ago`;
        const dd = String(d.getDate()).padStart(2, '0');
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const yyyy = d.getFullYear();
        const hh = String(d.getHours()).padStart(2, '0');
        const mi = String(d.getMinutes()).padStart(2, '0');
        return `${dd}/${mm}/${yyyy} ${hh}:${mi} — ${ago}`;
    }

    function groupByPo(list) {
        // New API: one record per PO (with optional nested events)
        if (list.length && Array.isArray(list[0]?.events)) {
            const out = list.map((r) => {
                const po = String(r.purchase_order || '').replace(/\D/g, '') || '—';
                const events = Array.isArray(r.events) && r.events.length
                    ? r.events.slice()
                    : [r];
                events.sort((a, b) => String(a.created_at || '').localeCompare(String(b.created_at || '')));
                const latest = {
                    purchase_order: r.purchase_order,
                    status: r.status,
                    event_type: r.event_type,
                    event_label: r.event_label,
                    user: r.user,
                    remarks: r.remarks,
                    created_at: r.created_at,
                    bucket: r.bucket,
                };
                return {
                    po,
                    bucket: r.bucket || '',
                    events,
                    latest,
                    stage: stageMeta(latest, r.bucket),
                };
            });
            out.sort((a, b) => String(b.latest?.created_at || '').localeCompare(String(a.latest?.created_at || '')));
            return out;
        }

        // Legacy flat activity rows
        const map = new Map();
        list.forEach((r) => {
            const po = String(r.purchase_order || '').replace(/\D/g, '') || '—';
            if (!map.has(po)) map.set(po, []);
            map.get(po).push(r);
        });
        const out = [];
        map.forEach((events, po) => {
            const sorted = events.slice().sort((a, b) => {
                const ta = String(a.created_at || '');
                const tb = String(b.created_at || '');
                return ta.localeCompare(tb);
            });
            const latest = sorted[sorted.length - 1] || null;
            out.push({
                po,
                events: sorted,
                latest,
                stage: stageMeta(latest),
            });
        });
        out.sort((a, b) => String(b.latest?.created_at || '').localeCompare(String(a.latest?.created_at || '')));
        return out;
    }

    function filteredRows() {
        const q = String(els.search?.value || '').trim().toLowerCase();
        if (!q) return rows;
        return rows.filter((r) => {
            const blob = [
                r.purchase_order, r.event_label, r.event_type, r.status, r.bucket, r.user, r.remarks, r.created_at,
            ].join(' ').toLowerCase();
            return blob.includes(q);
        });
    }

    function progressExportText(g) {
        const events = Array.isArray(g.events) ? g.events : [];
        if (!events.length) return String(g.stage?.badge || g.stage?.stage || '');
        return events.map((r) => {
            const meta = stepMeta(r);
            const name = String(r.user || '').trim();
            return name ? `${meta.label} ${name}` : meta.label;
        }).join(' → ');
    }

    function renderExcelBody(poGroups) {
        if (!els.excelBody) return;
        els.excelBody.innerHTML = poGroups.map((g, i) => {
            const owner = String(g.latest?.user || '').trim();
            const whenParts = relativeWhenParts(g.latest?.created_at);
            const when = whenParts.absolute === '—' ? '' : whenParts.absolute;
            const action = lastActionText(g);
            return `<tr data-orig-sno="${i + 1}">
                <td>${i + 1}</td>
                <td>${esc(g.po === '—' ? '' : g.po)}</td>
                <td>${esc(g.stage.badge || g.stage.stage || '')}</td>
                <td>${esc(when)}</td>
                <td>${esc(owner && owner !== '—' ? owner : '')}</td>
                <td>${esc(action === '—' ? '' : action)}</td>
                <td>${esc(progressExportText(g))}</td>
            </tr>`;
        }).join('');
    }

    function renderSteps(events, opts) {
        const compact = !!(opts && opts.compact);
        const max = compact ? 5 : 12;
        const list = events.length > max ? events.slice(events.length - max) : events;
        const stage = stageMeta(events[events.length - 1] || null);
        const steps = list.map((r) => {
            const meta = stepMeta(r);
            const name = String(r.user || '—').trim() || '—';
            return `<div class="pvr-step is-${esc(meta.tone)}">
                <span class="pvr-step-ico"><span class="material-icons-round">${esc(meta.icon)}</span></span>
                <div class="pvr-step-txt">
                    <small>${esc(meta.label)}</small>
                    <b>${esc(name)}</b>
                </div>
            </div>`;
        });
        if (stage.waiting) {
            steps.push(`<div class="pvr-step is-waiting">
                <span class="pvr-step-ico"><span class="material-icons-round">hourglass_empty</span></span>
                <div class="pvr-step-txt">
                    <b class="is-waiting-label">${esc(stage.waitingLabel || 'Awaiting action')}</b>
                </div>
            </div>`);
        }
        return `<div class="pvr-steps">${steps.join('<span class="pvr-step-sep material-icons-round">play_arrow</span>')}</div>`;
    }

    function renderTrail(events) {
        const stage = stageMeta(events[events.length - 1] || null);
        const max = 4;
        const hidden = Math.max(0, events.length - max);
        const list = events.length > max ? events.slice(events.length - max) : events;
        const dots = [];
        if (hidden > 0) {
            dots.push(`<span class="pvr-trail-more" title="${esc(hidden + ' earlier step' + (hidden === 1 ? '' : 's'))}">+${hidden}</span>`);
        }
        list.forEach((r) => {
            const meta = stepMeta(r);
            const name = String(r.user || '—').trim() || '—';
            const tip = `${meta.label} ${name}`;
            dots.push(`<span class="pvr-trail-dot is-${esc(meta.tone)}" title="${esc(tip)}">
                <span class="material-icons-round">${esc(meta.icon)}</span>
            </span>`);
        });
        if (stage.waiting) {
            dots.push(`<span class="pvr-trail-dot is-waiting" title="${esc(stage.waitingLabel || 'Awaiting action')}">
                <span class="material-icons-round">hourglass_empty</span>
            </span>`);
        }
        const last = events[events.length - 1] || null;
        const lastMeta = last ? stepMeta(last) : null;
        const lastName = String(last?.user || '').trim();
        let caption;
        if (stage.waiting) {
            caption = esc(stage.waitingLabel || 'Awaiting action');
        } else if (stage.actionLabel) {
            caption = `${esc(stage.actionLabel)} ${esc(lastName || '—')}`;
        } else if (lastMeta) {
            caption = `${esc(lastMeta.label)} ${esc(lastName || '—')}`;
        } else {
            caption = '—';
        }
        const captionClass = stage.waiting ? 'is-waiting' : '';
        return `<div class="pvr-trail">
            <div class="pvr-trail-dots">${dots.join('<span class="pvr-trail-line" aria-hidden="true"></span>')}</div>
            <div class="pvr-trail-cap ${captionClass}">${caption}</div>
        </div>`;
    }

    function relativeWhenParts(raw) {
        const s = String(raw || '').trim();
        if (!s) return { absolute: '—', ago: '' };
        const d = new Date(s.replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return { absolute: s, ago: '' };
        const diff = Date.now() - d.getTime();
        const mins = Math.floor(diff / 60000);
        const hours = Math.floor(mins / 60);
        const days = Math.floor(hours / 24);
        let ago = '';
        if (mins < 1) ago = 'just now';
        else if (mins < 60) ago = `${mins} min ago`;
        else if (hours < 24) ago = `${hours}h ago`;
        else ago = `${days}d ago`;
        const dd = String(d.getDate()).padStart(2, '0');
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const yyyy = d.getFullYear();
        const hh = String(d.getHours()).padStart(2, '0');
        const mi = String(d.getMinutes()).padStart(2, '0');
        return { absolute: `${dd}/${mm}/${yyyy} ${hh}:${mi}`, ago };
    }

    function renderCard(g, opts) {
        const compact = !!(opts && opts.compact);
        const latest = g.latest || {};
        const owner = String(latest.user || '—').trim() || '—';
        const when = relativeWhen(latest.created_at);
        const href = g.po && g.po !== '—'
            ? `${poBase}?po=${encodeURIComponent(g.po)}&view=costing`
            : '#';
        return `<article class="pvr-card" data-po="${esc(g.po)}" role="button" tabindex="0" title="View activity">
            <div class="pvr-card-top">
                <a class="pvr-po-link" href="${esc(href)}" onclick="event.stopPropagation()">${esc(g.po === '—' ? 'PO —' : 'PO-' + g.po)}</a>
                <span class="pvr-badge is-${esc(g.stage.badgeTone)}">${esc(g.stage.badge)}</span>
            </div>
            ${renderSteps(g.events, { compact })}
            <div class="pvr-card-foot">
                <div class="pvr-owner">
                    <span class="pvr-step-ico is-owner"><span class="material-icons-round">person</span></span>
                    <div class="pvr-step-txt">
                        <small>Current owner</small>
                        <b>${esc(owner)}</b>
                    </div>
                </div>
                <div class="pvr-stage">
                    <span class="material-icons-round">place</span>
                    <div class="pvr-step-txt">
                        <small>Current stage</small>
                        <b>${esc(g.stage.stage)}</b>
                    </div>
                </div>
            </div>
            ${when ? `<div class="pvr-when">${esc(when)}</div>` : ''}
        </article>`;
    }

    function emptyRowHtml(message) {
        return `<tr class="rpt-table-no-match">
            <td colspan="7" class="rpt-table-empty">
                <div class="rpt-no-match-card">
                    <span class="material-icons-round">filter_alt_off</span>
                    <p>${esc(message)}</p>
                </div>
            </td>
        </tr>`;
    }

    function renderRow(g, index) {
        const latest = g.latest || {};
        const owner = String(latest.user || '—').trim() || '—';
        const when = relativeWhenParts(latest.created_at);
        const href = g.po && g.po !== '—'
            ? `${poBase}?po=${encodeURIComponent(g.po)}&view=costing`
            : '#';
        const poLabel = g.po === '—' ? '—' : String(g.po);
        return `<tr class="pvr-row" data-po="${esc(g.po)}" data-orig-sno="${index + 1}" tabindex="0" title="View validation timeline">
            <td class="sno">${index + 1}</td>
            <td>
                <a class="po-num-link pvr-po-link" href="${esc(href)}" onclick="event.stopPropagation()" title="${esc(g.po === '—' ? 'PO —' : 'PO-' + g.po)}">${esc(poLabel)}</a>
            </td>
            <td>
                <span class="pvr-badge is-${esc(g.stage.badgeTone)}">${esc(g.stage.badge)}</span>
            </td>
            <td>
                <div class="pvr-when-cell">
                    <span class="pvr-when-abs">${esc(when.absolute)}</span>
                    ${when.ago ? `<span class="pvr-when-ago">${esc(when.ago)}</span>` : ''}
                </div>
            </td>
            <td>
                <div class="pvr-owner is-table">
                    <span class="pvr-avatar" aria-hidden="true">${esc((owner || '?').charAt(0).toUpperCase())}</span>
                    <span class="pvr-owner-name">${esc(owner)}</span>
                </div>
            </td>
            <td>
                <span class="pvr-action-txt ${g.stage.waiting ? 'is-waiting' : ''}">${esc(lastActionText(g))}</span>
            </td>
            <td class="pvr-td-flow">${renderTrail(g.events)}</td>
        </tr>`;
    }

    function renderList() {
        const allGroups = groupByPo(filteredRows());
        setStats(summaryFromGroups(allGroups));
        groups = statusFilter
            ? allGroups.filter((g) => String(g.stage?.filterKey || '') === statusFilter)
            : allGroups;
        if (els.searchClear) els.searchClear.hidden = !String(els.search?.value || '').trim();
        renderExcelBody(groups);
        updateReportMeta(groups.length);
        if (!els.list) return;
        if (!groups.length) {
            const msg = !loaded
                ? 'Apply filters to load PO validation activity.'
                : (statusFilter || String(els.search?.value || '').trim()
                    ? 'No purchase orders match these filters.'
                    : 'No PO validation activity for this period.');
            els.list.innerHTML = emptyRowHtml(msg);
            return;
        }
        els.list.innerHTML = groups.map((g, i) => renderRow(g, i)).join('');
    }

    function openModal(po) {
        const g = groups.find((x) => x.po === po) || groupByPo(rows.filter((r) => String(r.purchase_order || '').replace(/\D/g, '') === po))[0];
        if (!g || !els.modal) return;
        if (els.modalTitle) els.modalTitle.textContent = g.po === '—' ? 'PO —' : `PO-${g.po}`;
        if (els.modalBody) els.modalBody.innerHTML = renderCard(g, { compact: false });
        els.modal.hidden = false;
        els.modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('pvr-modal-open');
        setTimeout(() => els.modalClose?.focus(), 30);
    }

    function closeModal() {
        if (!els.modal) return;
        els.modal.hidden = true;
        els.modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('pvr-modal-open');
    }

    async function load() {
        if (loading) return;
        loading = true;
        if (els.wrap) els.wrap.classList.add('is-loading');
        if (els.list) {
            els.list.innerHTML = emptyRowHtml('Loading…');
        }
        const { from, to } = resolveRange();
        const params = new URLSearchParams();
        const po = String(els.po?.value || '').replace(/\D/g, '');
        if (po) params.set('po', po);
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        params.set('_', String(Date.now()));
        try {
            const url = `${dataUrl}${dataUrl.includes('?') ? '&' : '?'}${params.toString()}`;
            const res = await fetch(url, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const json = await res.json().catch(() => ({}));
            const data = json.data || json;
            if (!json.success && json.success !== undefined && !data.summary) {
                throw new Error(json.message || json.error || 'Failed to load report');
            }
            rows = Array.isArray(data.records) ? data.records : [];
            loaded = true;
            renderList();
        } catch (err) {
            rows = [];
            loaded = true;
            setStats({});
            updateReportMeta(0);
            if (els.list) {
                els.list.innerHTML = emptyRowHtml(err.message || 'Load failed');
            }
        } finally {
            loading = false;
            if (els.wrap) els.wrap.classList.remove('is-loading');
        }
    }

    function setStatusFilter(key) {
        statusFilter = String(key || '') === 'pending' ? 'draft' : String(key || '');
        if (!els.statusFilters) return;
        els.statusFilters.querySelectorAll('.pvr-status-pill').forEach((btn) => {
            const on = String(btn.getAttribute('data-status') || '') === statusFilter;
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        renderList();
    }

    function open() {
        if (els.title) els.title.innerHTML = '<strong>PO Validation Report</strong>';
        page.classList.add('is-valuation-report');
        page.classList.remove(
            'is-related-po', 'is-related-results', 'is-related-lookup',
            'is-search-po', 'is-costing-bulk', 'is-released-po', 'is-lookup'
        );
        const header = document.getElementById('pocValuationHeaderFilters');
        if (header) header.hidden = false;
        if (els.search) els.search.value = '';
        if (els.searchClear) els.searchClear.hidden = true;
        statusFilter = '';
        setStatusFilter('');
        syncCustomVisibility();
        updateReportMeta(0);
        load();
        setTimeout(() => { els.po?.focus(); }, 40);
    }

    function close() {
        page.classList.remove('is-valuation-report');
        const header = document.getElementById('pocValuationHeaderFilters');
        if (header) header.hidden = true;
        closeModal();
    }

    els.range?.addEventListener('change', () => {
        syncCustomVisibility();
        if (String(els.range.value || '') !== 'custom') load();
    });
    els.from?.addEventListener('change', () => load());
    els.to?.addEventListener('change', () => load());
    els.po?.addEventListener('input', () => {
        clearTimeout(poTimer);
        poTimer = setTimeout(() => load(), 400);
    });
    els.po?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(poTimer);
            load();
        }
    });
    els.search?.addEventListener('input', renderList);
    els.searchClear?.addEventListener('click', () => {
        if (els.search) els.search.value = '';
        renderList();
    });
    els.statusFilters?.addEventListener('click', (e) => {
        const btn = e.target.closest('.pvr-status-pill');
        if (!btn) return;
        setStatusFilter(btn.getAttribute('data-status') || '');
    });
    els.refresh?.addEventListener('click', () => load());

    els.list?.addEventListener('click', (e) => {
        if (e.target.closest('a')) return;
        const row = e.target.closest('.pvr-row');
        if (!row) return;
        openModal(row.getAttribute('data-po') || '');
    });
    els.list?.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const row = e.target.closest('.pvr-row');
        if (!row) return;
        e.preventDefault();
        openModal(row.getAttribute('data-po') || '');
    });
    els.modalClose?.addEventListener('click', closeModal);
    els.modalBackdrop?.addEventListener('click', closeModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && els.modal && !els.modal.hidden) closeModal();
    });

    window.pocValuationReport = { open, close, load };
})();
