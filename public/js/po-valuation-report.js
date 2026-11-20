/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 20/11/2026
 * DESCRIPTION : PO Valuation Report — KPIs + Recent Activity cards / modal (no table view)
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
        empty: document.getElementById('pvrActivityEmpty'),
        excelBody: document.getElementById('pvrTableBody'),
        title: document.getElementById('pocPageTitle'),
        modal: document.getElementById('pvrActivityModal'),
        modalTitle: document.getElementById('pvrModalTitle'),
        modalBody: document.getElementById('pvrModalBody'),
        modalClose: document.getElementById('pvrModalClose'),
        modalBackdrop: document.getElementById('pvrModalBackdrop'),
    };

    let rows = [];
    let groups = [];
    let loaded = false;
    let loading = false;
    let poTimer = null;

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
        ['pending', 'review', 'released', 'total', 'approved'].forEach((k) => {
            const el = document.getElementById('pvrStat-' + k);
            if (el) el.textContent = fmtN(s[k] || 0);
        });
    }

    function stepMeta(r) {
        const type = String(r.event_type || '').toLowerCase();
        const status = String(r.status || '').toLowerCase();
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
            return { icon: 'person', tone: 'slate', label: 'Updated by' };
        }
        return { icon: 'history', tone: 'slate', label: 'Updated by' };
    }

    function stageMeta(latest) {
        const status = String(latest?.status || '').trim();
        const type = String(latest?.event_type || '').toLowerCase();
        const low = status.toLowerCase();
        if (low.includes('release') || type === 'pass' && low.includes('e-sign')) {
            return { stage: status || 'Released', badge: 'Released', badgeTone: 'teal', waiting: false };
        }
        if (low.includes('review') || low.includes('checker')) {
            return { stage: status || 'Review', badge: 'Pending', badgeTone: 'amber', waiting: true, waitingLabel: 'Awaiting Checker Review' };
        }
        if (low.includes('draft') || low.includes('pending') || low === 'comment') {
            return { stage: status || 'Pending', badge: 'Pending', badgeTone: 'amber', waiting: true, waitingLabel: status || 'Pending' };
        }
        if (type === 'sent') {
            return { stage: status || 'Routed', badge: 'Pending', badgeTone: 'amber', waiting: true, waitingLabel: 'Awaiting next action' };
        }
        return {
            stage: status || (latest?.event_label || 'In progress'),
            badge: status || 'Active',
            badgeTone: 'slate',
            waiting: false,
        };
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
                r.purchase_order, r.event_label, r.event_type, r.status, r.user, r.remarks, r.created_at,
            ].join(' ').toLowerCase();
            return blob.includes(q);
        });
    }

    function renderExcelBody(list) {
        if (!els.excelBody) return;
        els.excelBody.innerHTML = list.map((r, i) => `<tr>
            <td>${i + 1}</td>
            <td>${esc(r.purchase_order || '')}</td>
            <td>${esc(r.event_label || '')}</td>
            <td>${esc(r.status || '')}</td>
            <td>${esc(r.user || '')}</td>
            <td>${esc(r.remarks || '')}</td>
            <td>${esc(r.created_at || '')}</td>
        </tr>`).join('');
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

    function renderList() {
        const list = filteredRows();
        groups = groupByPo(list);
        if (els.searchClear) els.searchClear.hidden = !String(els.search?.value || '').trim();
        renderExcelBody(list);
        if (!els.list) return;
        if (!groups.length) {
            els.list.innerHTML = `<div class="pvr-activity-empty">${
                loaded ? 'No PO log activity for these filters.' : 'Apply filters to load PO activity.'
            }</div>`;
            return;
        }
        els.list.innerHTML = groups.map((g) => renderCard(g, { compact: true })).join('');
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
        if (els.list) {
            els.list.innerHTML = '<div class="pvr-activity-empty">Loading…</div>';
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
            setStats(data.summary || {});
            rows = Array.isArray(data.records) ? data.records : [];
            loaded = true;
            renderList();
        } catch (err) {
            setStats({});
            rows = [];
            loaded = true;
            if (els.list) {
                els.list.innerHTML = `<div class="pvr-activity-empty">${esc(err.message || 'Load failed')}</div>`;
            }
        } finally {
            loading = false;
        }
    }

    function open() {
        if (els.title) els.title.innerHTML = '<strong>poValaition Report</strong>';
        page.classList.add('is-valuation-report');
        page.classList.remove(
            'is-related-po', 'is-related-results', 'is-related-lookup',
            'is-search-po', 'is-costing-bulk', 'is-released-po', 'is-lookup'
        );
        const header = document.getElementById('pocValuationHeaderFilters');
        if (header) header.hidden = false;
        syncCustomVisibility();
        if (!loaded) load();
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

    els.list?.addEventListener('click', (e) => {
        const card = e.target.closest('.pvr-card');
        if (!card) return;
        openModal(card.getAttribute('data-po') || '');
    });
    els.list?.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const card = e.target.closest('.pvr-card');
        if (!card) return;
        e.preventDefault();
        openModal(card.getAttribute('data-po') || '');
    });
    els.modalClose?.addEventListener('click', closeModal);
    els.modalBackdrop?.addEventListener('click', closeModal);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && els.modal && !els.modal.hidden) closeModal();
    });

    window.pocValuationReport = { open, close, load };
})();
