/*
 * PO E-Sign Log — report-table view + filters + pagination
 */
(function () {
    const page = document.getElementById('poPassLogPage');
    if (!page) return;

    const passLogUrl = page.getAttribute('data-pass-log') || 'purchase-order/pass-log';
    const poBaseUrl = page.getAttribute('data-po-base') || 'purchase-order';
    const TZ = 'Asia/Kolkata';
    const COLS = 9;

    const els = {
        po: document.getElementById('pplFilterPo'),
        range: document.getElementById('pplFilterRange'),
        custom: document.getElementById('pplCustomDates'),
        from: document.getElementById('pplFilterFrom'),
        to: document.getElementById('pplFilterTo'),
        apply: document.getElementById('pplApplyFilters'),
        reset: document.getElementById('pplResetFilters'),
        refresh: document.getElementById('pplRefreshBtn'),
        search: document.getElementById('pplTableSearch'),
        searchClear: document.getElementById('pplTableSearchClear'),
        body: document.getElementById('pplTableBody'),
        count: document.getElementById('pplTableCount'),
        exportBody: document.getElementById('pplExportBody'),
        filterRow: document.getElementById('pplFilterRow'),
        toggleFilters: document.getElementById('pplToggleColFilters'),
        clearColFilters: document.getElementById('pplClearColFilters'),
        expandBtn: document.getElementById('pplExpandBtn'),
        tableWrap: document.getElementById('pplTableWrap'),
        pager: document.getElementById('pplPager'),
        pagerInfo: document.getElementById('pplPagerInfo'),
        prev: document.getElementById('pplPrevPage'),
        next: document.getElementById('pplNextPage'),
        pageSize: document.getElementById('pplPageSize'),
        detail: document.getElementById('pplDetailModal'),
        detailTitle: document.getElementById('pplDetailTitle'),
        detailMeta: document.getElementById('pplDetailMeta'),
        detailPayload: document.getElementById('pplDetailPayload'),
        detailResponse: document.getElementById('pplDetailResponse'),
        detailFile: document.getElementById('pplDetailFile'),
        detailFileLink: document.getElementById('pplDetailFileLink'),
        detailFileName: document.getElementById('pplDetailFileName'),
        detailClose: document.getElementById('pplDetailClose'),
    };

    /** @type {Array<Record<string, mixed>>} */
    let allRows = [];
    let currentPage = 1;
    const colFilters = {
        po: '',
        user: '',
        status: '',
        request: '',
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
        const lastPrev = new Date(firstThis.getTime() - 86400000);
        const firstPrev = new Date(Date.UTC(lastPrev.getUTCFullYear(), lastPrev.getUTCMonth(), 1));
        return {
            from: firstPrev.toISOString().slice(0, 10),
            to: lastPrev.toISOString().slice(0, 10),
        };
    }

    function rangeDates(preset) {
        const today = todayYmd();
        if (!preset) return { from: '', to: '' };
        if (preset === 'today') return { from: today, to: today };
        if (preset === 'yesterday') {
            const y = addDaysYmd(today, -1);
            return { from: y, to: y };
        }
        if (preset === 'this_week') return { from: startOfWeekMonday(today), to: today };
        if (preset === 'previous_week') {
            const thisMon = startOfWeekMonday(today);
            return { from: addDaysYmd(thisMon, -7), to: addDaysYmd(thisMon, -1) };
        }
        if (preset === 'this_month') return { from: monthStart(today), to: today };
        if (preset === 'previous_month') return previousMonthRange(today);
        if (preset === 'custom') {
            return {
                from: String(els.from?.value || '').trim(),
                to: String(els.to?.value || '').trim(),
            };
        }
        return { from: '', to: '' };
    }

    function syncCustomDates() {
        if (!els.custom || !els.range) return;
        els.custom.hidden = els.range.value !== 'custom';
    }

    function apiFilters() {
        const preset = String(els.range?.value || '').trim();
        const dates = rangeDates(preset);
        return {
            po: String(els.po?.value || '').replace(/\D/g, ''),
            from: dates.from,
            to: dates.to,
        };
    }

    function statusClass(status) {
        const s = String(status || '').toLowerCase();
        if (s.includes('e-sign') || s.includes('esign')) return 'is-esign';
        if (s.includes('approved') || s.includes('pass')) return 'is-pass';
        if (s.includes('fail')) return 'is-fail';
        if (s.includes('revert')) return 'is-revert';
        return 'is-pass';
    }

    function prettyJson(raw) {
        const text = String(raw == null || raw === '' ? '' : raw);
        if (!text) return '—';
        try {
            return JSON.stringify(JSON.parse(text), null, 2);
        } catch (_) {
            return text;
        }
    }

    function pageSize() {
        return Math.max(5, Math.min(100, Number(els.pageSize?.value || 20) || 20));
    }

    function includesQ(hay, q) {
        if (!q) return true;
        return String(hay || '').toLowerCase().includes(q);
    }

    function filteredRows() {
        const q = String(els.search?.value || '').trim().toLowerCase();
        return allRows.filter((r) => {
            const po = String(r.purchase_order || '').replace(/\D/g, '');
            const user = String(r.username || '');
            const status = String(r.status || '');
            const request = String(r.request_id || '');
            const when = r.created_at ? `${r.created_at} IST` : '';
            if (q) {
                const hay = [po, user, status, request, when].join(' ').toLowerCase();
                if (!hay.includes(q)) return false;
            }
            if (!includesQ(po, colFilters.po)) return false;
            if (!includesQ(user, colFilters.user)) return false;
            if (!includesQ(status, colFilters.status)) return false;
            if (!includesQ(request, colFilters.request)) return false;
            if (!includesQ(when, colFilters.when)) return false;
            return true;
        });
    }

    function openDetail(row) {
        if (!els.detail || !row) return;
        if (els.detailTitle) {
            els.detailTitle.textContent = `PO ${row.purchase_order || '—'} · ${row.status || row.action || 'Log'}`;
        }
        if (els.detailMeta) {
            const fields = [
                ['PO', row.purchase_order || '—'],
                ['Status', row.status || '—'],
                ['User', row.username || '—'],
                ['Request Id', row.request_id || '—'],
                ['HTTP', row.http_code != null ? String(row.http_code) : '—'],
                ['Passed At', row.created_at ? `${row.created_at} IST` : '—'],
            ];
            els.detailMeta.innerHTML = fields.map(([k, v]) => (
                `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`
            )).join('');
        }
        if (els.detailPayload) els.detailPayload.textContent = prettyJson(row.payload);
        if (els.detailResponse) els.detailResponse.textContent = prettyJson(row.response);
        const hasFile = !!(row.has_file && row.pdf_url);
        if (els.detailFile) els.detailFile.hidden = !hasFile;
        if (els.detailFileLink) {
            if (hasFile) {
                els.detailFileLink.href = String(row.pdf_url);
                els.detailFileLink.hidden = false;
            } else {
                els.detailFileLink.removeAttribute('href');
                els.detailFileLink.hidden = true;
            }
        }
        if (els.detailFileName) {
            els.detailFileName.textContent = hasFile ? String(row.pdf_file || 'Signed PDF') : '';
        }
        els.detail.hidden = false;
        document.body.classList.add('ppl-detail-open');
    }

    function closeDetail() {
        if (!els.detail) return;
        els.detail.hidden = true;
        document.body.classList.remove('ppl-detail-open');
    }

    function updatePager(total) {
        const size = pageSize();
        const totalPages = Math.max(1, Math.ceil(total / size));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        const from = total === 0 ? 0 : ((currentPage - 1) * size) + 1;
        const to = Math.min(total, currentPage * size);
        if (els.pager) els.pager.hidden = total === 0;
        if (els.pagerInfo) {
            els.pagerInfo.textContent = total
                ? `Page ${currentPage} / ${totalPages} · ${from}–${to} of ${total}`
                : 'Page 0';
        }
        if (els.prev) els.prev.disabled = currentPage <= 1;
        if (els.next) els.next.disabled = currentPage >= totalPages || total === 0;
    }

    function renderExport(list) {
        if (!els.exportBody) return;
        els.exportBody.innerHTML = list.map((r, i) => {
            const po = String(r.purchase_order || '').replace(/\D/g, '');
            return `<tr>
                <td>${i + 1}</td>
                <td>${esc(po)}</td>
                <td>${esc(r.username || '')}</td>
                <td>${esc(r.status || '')}</td>
                <td>${esc(r.request_id || '')}</td>
                <td>${esc(r.created_at ? `${r.created_at} IST` : '')}</td>
                <td>${esc(r.has_file ? (r.pdf_file || 'Signed PDF') : 'No file')}</td>
            </tr>`;
        }).join('');
    }

    function syncSearchClear() {
        if (!els.searchClear) return;
        els.searchClear.hidden = !String(els.search?.value || '').trim();
    }

    function renderTable() {
        const list = filteredRows();
        renderExport(list);
        syncSearchClear();

        if (els.count) {
            els.count.textContent = `Showing ${list.length} of ${allRows.length} lines`;
            els.count.classList.toggle('is-filtered', list.length !== allRows.length);
        }

        if (!els.body) return;
        if (!list.length) {
            els.body.innerHTML = `<tr><td colspan="${COLS}" class="rpt-table-no-match">No passed POs found.</td></tr>`;
            updatePager(0);
            return;
        }

        const size = pageSize();
        const start = (currentPage - 1) * size;
        const pageRows = list.slice(start, start + size);
        updatePager(list.length);

        els.body.innerHTML = pageRows.map((r, i) => {
            const idx = start + i;
            const po = String(r.purchase_order || '').replace(/\D/g, '');
            const href = `${poBaseUrl}?view=costing&po=${encodeURIComponent(po)}`;
            const status = String(r.status || 'Completed');
            const hasFile = !!(r.has_file && r.pdf_url);
            const pdfHref = hasFile
                ? `${String(r.pdf_url)}${String(r.pdf_url).includes('#') ? '' : '#toolbar=0&navpanes=0&view=FitH'}`
                : '';
            const fileCell = hasFile
                ? `<a class="rpt-tb-btn ppl-file-btn" href="${esc(pdfHref)}" target="_blank" rel="noopener" title="${esc(r.pdf_file || 'Signed PDF')}">
                        <span class="material-icons-round">picture_as_pdf</span> Signed PDF
                   </a>`
                : '<span class="ppl-nofile">—</span>';
            return `<tr>
                <td class="num sno">${idx + 1}</td>
                <td><button type="button" class="ppl-po-link" data-open-po="${esc(po)}">${esc(po)}</button></td>
                <td>${esc(r.username || '—')}</td>
                <td><span class="ppl-badge ${statusClass(status)}">${esc(status)}</span></td>
                <td>${esc(r.request_id || '—')}</td>
                <td>${esc(r.created_at ? `${r.created_at} IST` : '—')}</td>
                <td><button type="button" class="rpt-tb-btn ppl-view-log" data-idx="${idx}">View</button></td>
                <td>${fileCell}</td>
                <td><a class="rpt-tb-btn rpt-tb-btn-primary" href="${esc(href)}">Open</a></td>
            </tr>`;
        }).join('');

        els.body.querySelectorAll('.ppl-view-log').forEach((btn) => {
            btn.addEventListener('click', () => {
                const i = Number(btn.getAttribute('data-idx') || -1);
                if (i >= 0 && list[i]) openDetail(list[i]);
            });
        });
        els.body.querySelectorAll('[data-open-po]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const po = btn.getAttribute('data-open-po') || '';
                if (!po) return;
                window.location.href = `${poBaseUrl}?view=costing&po=${encodeURIComponent(po)}`;
            });
        });
    }

    async function load() {
        currentPage = 1;
        if (els.body) els.body.innerHTML = `<tr><td colspan="${COLS}" class="ppl-empty">Loading…</td></tr>`;
        const f = apiFilters();
        if (String(els.range?.value || '') === 'custom' && (!f.from || !f.to)) {
            if (els.body) {
                els.body.innerHTML = `<tr><td colspan="${COLS}" class="ppl-empty">Select both From and To dates for Custom date.</td></tr>`;
            }
            return;
        }
        const params = new URLSearchParams({ limit: '500', _: String(Date.now()) });
        if (f.po) params.set('po', f.po);
        if (f.from) params.set('from', f.from);
        if (f.to) params.set('to', f.to);
        try {
            const res = await fetch(`${passLogUrl}?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const json = await res.json();
            if (!res.ok || json.success === false) {
                throw new Error(json.message || json.error || `HTTP ${res.status}`);
            }
            allRows = Array.isArray(json?.data?.rows) ? json.data.rows : [];
            renderTable();
        } catch (err) {
            allRows = [];
            if (els.body) {
                els.body.innerHTML = `<tr><td colspan="${COLS}" class="ppl-empty">${esc(err.message || 'Could not load log')}</td></tr>`;
            }
            updatePager(0);
            if (els.count) els.count.textContent = 'Error';
        }
    }

    let searchTimer = null;
    els.search?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            currentPage = 1;
            renderTable();
        }, 160);
    });
    els.searchClear?.addEventListener('click', () => {
        if (els.search) els.search.value = '';
        currentPage = 1;
        renderTable();
    });

    els.filterRow?.querySelectorAll('.rpt-col-input').forEach((input) => {
        input.addEventListener('input', () => {
            const key = input.getAttribute('data-col');
            if (!key || !(key in colFilters)) return;
            colFilters[key] = String(input.value || '').trim().toLowerCase();
            currentPage = 1;
            renderTable();
        });
    });

    els.clearColFilters?.addEventListener('click', () => {
        Object.keys(colFilters).forEach((k) => { colFilters[k] = ''; });
        els.filterRow?.querySelectorAll('.rpt-col-input').forEach((input) => { input.value = ''; });
        currentPage = 1;
        renderTable();
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

    els.range?.addEventListener('change', syncCustomDates);
    els.apply?.addEventListener('click', () => { void load(); });
    els.refresh?.addEventListener('click', () => { void load(); });
    els.reset?.addEventListener('click', () => {
        if (els.po) els.po.value = '';
        if (els.range) els.range.value = '';
        if (els.from) els.from.value = '';
        if (els.to) els.to.value = '';
        if (els.search) els.search.value = '';
        Object.keys(colFilters).forEach((k) => { colFilters[k] = ''; });
        els.filterRow?.querySelectorAll('.rpt-col-input').forEach((input) => { input.value = ''; });
        currentPage = 1;
        syncCustomDates();
        void load();
    });
    els.po?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            void load();
        }
    });
    els.prev?.addEventListener('click', () => {
        if (currentPage <= 1) return;
        currentPage -= 1;
        renderTable();
    });
    els.next?.addEventListener('click', () => {
        currentPage += 1;
        renderTable();
    });
    els.pageSize?.addEventListener('change', () => {
        currentPage = 1;
        renderTable();
    });
    els.detailClose?.addEventListener('click', closeDetail);
    els.detail?.addEventListener('click', (e) => {
        if (e.target === els.detail) closeDetail();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeDetail();
    });

    syncCustomDates();
    void load();
})();
