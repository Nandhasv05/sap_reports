/*
 * PO Validation History — report-table view, Mail under user, pagination
 */
(function () {
    const page = document.getElementById('poValidationHistoryPage');
    if (!page) return;

    const logUrl = page.getAttribute('data-validation-log') || 'purchase-order/validation-log';
    const poBaseUrl = page.getAttribute('data-po-base') || 'purchase-order';
    const TZ = 'Asia/Kolkata';
    const COLS = 7;

    const els = {
        po: document.getElementById('pvhFilterPo'),
        range: document.getElementById('pvhFilterRange'),
        custom: document.getElementById('pvhCustomDates'),
        from: document.getElementById('pvhFilterFrom'),
        to: document.getElementById('pvhFilterTo'),
        apply: document.getElementById('pvhApplyFilters'),
        reset: document.getElementById('pvhResetFilters'),
        refresh: document.getElementById('pvhRefreshBtn'),
        search: document.getElementById('pvhTableSearch'),
        searchClear: document.getElementById('pvhTableSearchClear'),
        body: document.getElementById('pvhTableBody'),
        count: document.getElementById('pvhTableCount'),
        exportBody: document.getElementById('pvhExportBody'),
        pager: document.getElementById('pvhPager'),
        pagerInfo: document.getElementById('pvhPagerInfo'),
        prev: document.getElementById('pvhPrevPage'),
        next: document.getElementById('pvhNextPage'),
        pageSize: document.getElementById('pvhPageSize'),
        filterRow: document.getElementById('pvhFilterRow'),
        toggleFilters: document.getElementById('pvhToggleColFilters'),
        clearColFilters: document.getElementById('pvhClearColFilters'),
        expandBtn: document.getElementById('pvhExpandBtn'),
        tableWrap: document.getElementById('pvhTableWrap'),
    };

    /** @type {Array<Record<string, mixed>>} */
    let allLogs = [];
    let currentPage = 1;
    const colFilters = {
        po: '',
        status: '',
        assigned: '',
        created: '',
        remarks: '',
        when: '',
    };

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function ymdInTz(date) {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: TZ,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(date);
        const get = (t) => parts.find((p) => p.type === t)?.value || '00';
        return `${get('year')}-${get('month')}-${get('day')}`;
    }

    function todayYmd() {
        return ymdInTz(new Date());
    }

    function addDaysYmd(ymd, days) {
        const [y, m, d] = ymd.split('-').map(Number);
        const dt = new Date(Date.UTC(y, m - 1, d));
        dt.setUTCDate(dt.getUTCDate() + days);
        return dt.toISOString().slice(0, 10);
    }

    function startOfWeekMonday(ymd) {
        const [y, m, d] = ymd.split('-').map(Number);
        const dt = new Date(Date.UTC(y, m - 1, d));
        const dow = dt.getUTCDay();
        const offset = dow === 0 ? -6 : 1 - dow;
        dt.setUTCDate(dt.getUTCDate() + offset);
        return dt.toISOString().slice(0, 10);
    }

    function monthStart(ymd) {
        return ymd.slice(0, 8) + '01';
    }

    function previousMonthRange(ymd) {
        const [y, m] = ymd.split('-').map(Number);
        const firstThis = new Date(Date.UTC(y, m - 1, 1));
        const lastPrev = new Date(firstThis);
        lastPrev.setUTCDate(0);
        const firstPrev = new Date(Date.UTC(lastPrev.getUTCFullYear(), lastPrev.getUTCMonth(), 1));
        return {
            from: firstPrev.toISOString().slice(0, 10),
            to: lastPrev.toISOString().slice(0, 10),
        };
    }

    function resolveRange() {
        const key = (els.range?.value || '').trim();
        const today = todayYmd();
        if (!key) return { from: '', to: '' };
        if (key === 'custom') {
            return {
                from: (els.from?.value || '').trim(),
                to: (els.to?.value || '').trim(),
            };
        }
        if (key === 'today') return { from: today, to: today };
        if (key === 'yesterday') {
            const y = addDaysYmd(today, -1);
            return { from: y, to: y };
        }
        if (key === 'this_week') return { from: startOfWeekMonday(today), to: today };
        if (key === 'previous_week') {
            const thisMon = startOfWeekMonday(today);
            return { from: addDaysYmd(thisMon, -7), to: addDaysYmd(thisMon, -1) };
        }
        if (key === 'this_month') return { from: monthStart(today), to: today };
        if (key === 'previous_month') return previousMonthRange(today);
        return { from: '', to: '' };
    }

    function statusLabel(raw) {
        const s = String(raw || '').toUpperCase();
        if (s === 'VALIDATE_PO') return 'Validate';
        if (s === 'NOT_VALIDATE') return 'Not validate';
        if (s === 'COMMENT') return 'Comment';
        return s || '—';
    }

    function statusClass(raw) {
        const s = String(raw || '').toUpperCase();
        if (s === 'VALIDATE_PO') return 'is-pass';
        if (s === 'NOT_VALIDATE') return 'is-fail';
        if (s === 'COMMENT') return 'is-esign';
        return 'is-revert';
    }

    function userInitial(name) {
        const s = String(name || '').trim();
        return (s[0] || '?').toUpperCase();
    }

    function formatWhen(v) {
        if (!v) return '—';
        return String(v).replace('T', ' ').slice(0, 19) || '—';
    }

    function normalizePo(v) {
        return String(v || '').replace(/\D/g, '');
    }

    function mailOf(row) {
        return String(row.assigned_email || row.email || row.mail_id || '').trim();
    }

    function openWorkbench(po) {
        const n = normalizePo(po);
        if (!n) return;
        window.location.href = `${poBaseUrl}?view=costing&po=${encodeURIComponent(n)}`;
    }

    function pageSize() {
        return Math.max(5, Math.min(100, Number(els.pageSize?.value || 20) || 20));
    }

    function includesQ(hay, q) {
        if (!q) return true;
        return String(hay || '').toLowerCase().includes(q);
    }

    function filteredLogs() {
        const q = String(els.search?.value || '').trim().toLowerCase();
        return allLogs.filter((row) => {
            const po = normalizePo(row.purchase_order) || String(row.purchase_order || '');
            const status = statusLabel(row.validation_status);
            const assigned = String(row.assigned_username || '');
            const mail = mailOf(row);
            const created = String(row.created_by || '');
            const remarks = String(row.description || '');
            const when = formatWhen(row.created_at);

            if (q) {
                const hay = [po, status, assigned, mail, created, remarks, when].join(' ').toLowerCase();
                if (!hay.includes(q)) return false;
            }
            if (!includesQ(po, colFilters.po)) return false;
            if (!includesQ(status, colFilters.status)) return false;
            if (!includesQ(`${assigned} ${mail}`, colFilters.assigned)) return false;
            if (!includesQ(created, colFilters.created)) return false;
            if (!includesQ(remarks, colFilters.remarks)) return false;
            if (!includesQ(when, colFilters.when)) return false;
            return true;
        });
    }

    function sortedLogs(logs) {
        return logs.slice().sort((a, b) => {
            const poA = normalizePo(a.purchase_order);
            const poB = normalizePo(b.purchase_order);
            if (poA !== poB) return poB.localeCompare(poA);
            return String(b.created_at || '').localeCompare(String(a.created_at || ''));
        });
    }

    function renderExport(logs) {
        if (!els.exportBody) return;
        let sno = 0;
        els.exportBody.innerHTML = logs.map((row) => {
            sno += 1;
            return `<tr>
                <td>${sno}</td>
                <td>${esc(normalizePo(row.purchase_order) || row.purchase_order || '')}</td>
                <td>${esc(statusLabel(row.validation_status))}</td>
                <td>${esc(row.assigned_username || '')}</td>
                <td>${esc(mailOf(row))}</td>
                <td>${esc(row.created_by || '')}</td>
                <td>${esc(row.description || '')}</td>
                <td>${esc(formatWhen(row.created_at))}</td>
            </tr>`;
        }).join('');
    }

    function updatePager(totalEntries) {
        const size = pageSize();
        const totalPages = Math.max(1, Math.ceil(totalEntries / size));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        const from = totalEntries === 0 ? 0 : ((currentPage - 1) * size) + 1;
        const to = Math.min(totalEntries, currentPage * size);
        if (els.pager) els.pager.hidden = totalEntries === 0;
        if (els.pagerInfo) {
            els.pagerInfo.textContent = totalEntries
                ? `Page ${currentPage} / ${totalPages} · ${from}–${to} of ${totalEntries}`
                : 'Page 0';
        }
        if (els.prev) els.prev.disabled = currentPage <= 1;
        if (els.next) els.next.disabled = currentPage >= totalPages || totalEntries === 0;
    }

    function syncSearchClear() {
        if (!els.searchClear) return;
        els.searchClear.hidden = !String(els.search?.value || '').trim();
    }

    function render() {
        if (!els.body) return;
        const logs = sortedLogs(filteredLogs());
        renderExport(logs);
        syncSearchClear();

        if (els.count) {
            els.count.textContent = `Showing ${logs.length} of ${allLogs.length} lines`;
            els.count.classList.toggle('is-filtered', logs.length !== allLogs.length);
        }

        if (!logs.length) {
            els.body.innerHTML = `<tr><td colspan="${COLS}" class="rpt-table-no-match">No validation history found.</td></tr>`;
            updatePager(0);
            return;
        }

        const size = pageSize();
        const start = (currentPage - 1) * size;
        const pageRows = logs.slice(start, start + size);
        updatePager(logs.length);

        let sno = start;
        let lastPo = null;
        const html = [];

        pageRows.forEach((row) => {
            sno += 1;
            const po = normalizePo(row.purchase_order) || String(row.purchase_order || '—');
            const assigned = row.assigned_username || '—';
            const createdBy = row.created_by || '—';
            const mail = mailOf(row);
            const mailHtml = mail
                ? `<small class="pvh-user-mail" title="${esc(mail)}">${esc(mail)}</small>`
                : `<small class="pvh-user-mail is-empty">No mail id</small>`;
            const poChanged = lastPo !== po;
            lastPo = po;

            html.push(`<tr class="pvh-detail-row${poChanged ? ' is-po-start' : ''}">
                <td class="num sno">${sno}</td>
                <td>
                    <button type="button" class="pvh-po-link" data-po="${esc(po)}" title="Open workbench">${esc(po)}</button>
                </td>
                <td>
                    <span class="ppl-badge pvh-status ${statusClass(row.validation_status)}">${esc(statusLabel(row.validation_status))}</span>
                </td>
                <td>
                    <span class="pvh-user is-assigned">
                        <span class="pvh-avatar">${esc(userInitial(assigned))}</span>
                        <span class="pvh-user-text">
                            <strong>${esc(assigned)}</strong>
                            ${mailHtml}
                        </span>
                    </span>
                </td>
                <td>
                    <span class="pvh-user is-created">
                        <span class="pvh-avatar">${esc(userInitial(createdBy))}</span>
                        <span class="pvh-user-text"><strong>${esc(createdBy)}</strong></span>
                    </span>
                </td>
                <td class="pvh-remarks-cell">${esc(row.description || '—')}</td>
                <td class="pvh-when-cell">${esc(formatWhen(row.created_at))}</td>
            </tr>`);
        });

        els.body.innerHTML = html.join('');
    }

    async function load() {
        currentPage = 1;
        if (els.body) {
            els.body.innerHTML = `<tr><td colspan="${COLS}" class="ppl-empty">Loading…</td></tr>`;
        }
        const po = (els.po?.value || '').replace(/\D/g, '');
        const range = resolveRange();
        const q = new URLSearchParams();
        q.set('limit', '500');
        if (po) q.set('po', po);
        if (range.from) q.set('from', range.from);
        if (range.to) q.set('to', range.to);
        try {
            const res = await fetch(`${logUrl}?${q.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = await res.json();
            const logs = (json && json.data && Array.isArray(json.data.logs)) ? json.data.logs : [];
            if (!json.success) {
                allLogs = [];
                els.body.innerHTML = `<tr><td colspan="${COLS}" class="ppl-empty">${esc(json.message || json.error || 'Failed to load')}</td></tr>`;
                updatePager(0);
                if (els.count) els.count.textContent = 'Showing 0 of 0 lines';
                return;
            }
            allLogs = logs;
            render();
        } catch (err) {
            allLogs = [];
            els.body.innerHTML = `<tr><td colspan="${COLS}" class="ppl-empty">${esc(err.message || 'Network error')}</td></tr>`;
            updatePager(0);
            if (els.count) els.count.textContent = 'Showing 0 of 0 lines';
        }
    }

    function syncCustomVisibility() {
        if (!els.custom) return;
        els.custom.hidden = (els.range?.value || '') !== 'custom';
    }

    let searchTimer = null;
    els.search?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            currentPage = 1;
            render();
        }, 160);
    });
    els.searchClear?.addEventListener('click', () => {
        if (els.search) els.search.value = '';
        currentPage = 1;
        render();
    });

    els.filterRow?.querySelectorAll('.rpt-col-input').forEach((input) => {
        input.addEventListener('input', () => {
            const key = input.getAttribute('data-col');
            if (!key || !(key in colFilters)) return;
            colFilters[key] = String(input.value || '').trim().toLowerCase();
            currentPage = 1;
            render();
        });
    });

    els.clearColFilters?.addEventListener('click', () => {
        Object.keys(colFilters).forEach((k) => { colFilters[k] = ''; });
        els.filterRow?.querySelectorAll('.rpt-col-input').forEach((input) => { input.value = ''; });
        currentPage = 1;
        render();
    });

    els.toggleFilters?.addEventListener('click', () => {
        const row = els.filterRow;
        if (!row) return;
        const hide = !row.classList.contains('is-hidden');
        row.classList.toggle('is-hidden', hide);
        els.toggleFilters.classList.toggle('is-active', !hide);
    });

    els.expandBtn?.addEventListener('click', () => {
        const wrap = els.tableWrap;
        if (!wrap) return;
        wrap.classList.toggle('is-expanded');
        const on = wrap.classList.contains('is-expanded');
        els.expandBtn.classList.toggle('is-active', on);
        const label = els.expandBtn.querySelector('span');
        if (label) label.textContent = on ? 'Collapse Table' : 'Expand Table';
    });

    els.range?.addEventListener('change', syncCustomVisibility);
    els.apply?.addEventListener('click', load);
    els.refresh?.addEventListener('click', load);
    els.reset?.addEventListener('click', () => {
        if (els.po) els.po.value = '';
        if (els.range) els.range.value = '';
        if (els.from) els.from.value = '';
        if (els.to) els.to.value = '';
        if (els.search) els.search.value = '';
        Object.keys(colFilters).forEach((k) => { colFilters[k] = ''; });
        els.filterRow?.querySelectorAll('.rpt-col-input').forEach((input) => { input.value = ''; });
        currentPage = 1;
        syncCustomVisibility();
        load();
    });
    els.po?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            load();
        }
    });
    els.prev?.addEventListener('click', () => {
        if (currentPage <= 1) return;
        currentPage -= 1;
        render();
    });
    els.next?.addEventListener('click', () => {
        currentPage += 1;
        render();
    });
    els.pageSize?.addEventListener('change', () => {
        currentPage = 1;
        render();
    });
    els.body?.addEventListener('click', (e) => {
        const openBtn = e.target.closest('[data-po]');
        if (!openBtn) return;
        e.preventDefault();
        openWorkbench(openBtn.getAttribute('data-po'));
    });

    syncCustomVisibility();
    load();
})();
