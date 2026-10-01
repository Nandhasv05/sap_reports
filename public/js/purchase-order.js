/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : Purchase Order report page - live SAP header and items, quick search, totals and Excel export (helpers in po-report.js)
 */
(function () {
    const P = window.PoReport;
    if (!P) return;
    const cfg = P.cfg;
    const $ = (id) => document.getElementById(id);
    const spinner = $('rptSpinner');

    function showSpinner(on) {
        if (spinner) spinner.hidden = !on;
        document.body.classList.toggle('rpt-loading', !!on);
    }

    /* Both the lookup card and the header bar submit ?po=; validate before leaving the page. */
    document.querySelectorAll('#poFilterForm').forEach((form) => {
        const input = form.querySelector('[name="po"]');
        const error = form.classList.contains('lookup-form') ? $('poFieldError') : null;
        const setError = (message) => {
            input.closest('.lookup-so, .rpt-field')?.classList.toggle('is-invalid', !!message);
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
            if (error) {
                error.textContent = message;
                error.hidden = !message;
                return;
            }
            input.setCustomValidity(message);
            if (message) input.reportValidity();
        };
        input.addEventListener('input', () => setError(''));
        form.addEventListener('submit', (e) => {
            const po = P.cleanPo(input.value);
            input.value = po;
            if (po === '') { e.preventDefault(); setError('Enter a purchase order number.'); input.focus(); return; }
            if (!P.validPo(po)) { e.preventDefault(); setError('Purchase order must be a number of up to 10 digits.'); input.focus(); return; }
            showSpinner(true);
        });
    });

    const tbody = $('rptTableBody');
    if (!cfg.po || !tbody) return;

    const noMatchRow = $('rptNoMatchRow');
    const searchInput = $('rptTableSearch');
    const searchClear = $('rptTableSearchClear');
    const countBadge = $('rptTableCount');
    const colCount = document.querySelectorAll('#rptDataTable thead tr.rpt-header-row > th').length;
    let total = 0;

    function showMessage(message) {
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach((tr) => tr.remove());
        const tr = document.createElement('tr');
        tr.className = 'rpt-table-loading';
        tr.innerHTML = `<td colspan="${colCount}" class="rpt-table-empty">${message}</td>`;
        tbody.insertBefore(tr, noMatchRow);
        if (countBadge) countBadge.textContent = 'Showing 0 items';
    }

    function fillHero(h, t) {
        $('poHeroSub').textContent = [
            h.date ? `Dated ${P.fmtDate(h.date)}` : '',
            h.created_by ? `Created by ${h.created_by}` : '',
            `${P.qty(t.items)} item${t.items === 1 ? '' : 's'}`,
        ].filter(Boolean).join(' · ');
        const meta = P.infoItems(h).filter(([label]) => label !== 'Purchase Order');
        $('poHeroMeta').innerHTML = meta.map(([label, value]) => `<div><dt>${label}</dt><dd>${P.dash(value)}</dd></div>`).join('');
        $('poHeroSos').innerHTML = (h.sales_orders || []).length ? P.soChips(h.sales_orders) : '<span class="po-muted">None</span>';
    }

    const palette = ['#4338ca', '#0f766e', '#0284c7', '#d97706', '#be123c', '#7c3aed', '#16a34a', '#64748b'];
    const charts = [];

    function fillCharts(records, t) {
        if (typeof Chart === 'undefined') return;
        charts.forEach((c) => c.destroy());
        charts.length = 0;
        const font = { family: 'Inter, sans-serif', size: 11 };
        charts.push(new Chart($('poItemChart'), {
            data: {
                labels: records.map((r) => `Item ${r.item}`),
                datasets: [
                    { type: 'bar', label: `Order Qty${t.unit ? ` (${t.unit})` : ''}`, data: records.map((r) => r.order_qty), backgroundColor: 'rgba(67, 56, 202, 0.85)', borderRadius: 6, maxBarThickness: 46, yAxisID: 'y' },
                    { type: 'line', label: `Net Value${t.currency ? ` (${t.currency})` : ''}`, data: records.map((r) => r.net_value), borderColor: '#16a34a', backgroundColor: '#16a34a', tension: 0.3, pointRadius: 4, yAxisID: 'y1' },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { font, boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            title: (items) => { const r = records[items[0].dataIndex]; return `Item ${r.item} · ${r.material}`; },
                            label: (c) => `${c.dataset.label}: ${c.dataset.yAxisID === 'y1' ? P.money(c.raw) : P.qty(c.raw)}`,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font } },
                    y: { beginAtZero: true, ticks: { font }, title: { display: true, text: 'Qty', font } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { font }, title: { display: true, text: 'Value', font } },
                },
            },
        }));

        const byMaterial = new Map();
        records.forEach((r) => byMaterial.set(r.material, (byMaterial.get(r.material) || 0) + Number(r.net_value || 0)));
        const entries = [...byMaterial.entries()].sort((a, b) => b[1] - a[1]);
        charts.push(new Chart($('poShareChart'), {
            type: 'doughnut',
            data: {
                labels: entries.map(([m]) => m || '—'),
                datasets: [{ data: entries.map(([, v]) => v), backgroundColor: entries.map((_, i) => palette[i % palette.length]), borderWidth: 2, borderColor: '#fff' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'right', labels: { font, boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            label: (c) => {
                                const sum = entries.reduce((s, [, v]) => s + v, 0);
                                return `${c.label}: ${P.money(c.raw)} ${t.currency || ''} (${sum > 0 ? ((c.raw / sum) * 100).toFixed(1) : 0}%)`;
                            },
                        },
                    },
                },
            },
        }));
    }

    function fill(data) {
        const h = data.header || {};
        const t = data.totals || {};
        document.title = `EVOLV | Purchase Order ${h.purchase_order}`;
        fillHero(h, t);
        P.statItems(data).forEach((s) => {
            const el = $(`poStat-${s.id}`);
            const unit = $(`poStatUnit-${s.id}`);
            if (el) el.textContent = s.value;
            if (unit) unit.textContent = s.unit;
        });

        const valueSum = data.records.reduce((s, r) => s + Number(r.net_value || 0), 0);
        tbody.querySelectorAll('tr.rpt-table-loading, tr[data-orig-sno]').forEach((tr) => tr.remove());
        const html = data.records.map((r, i) => {
            const share = valueSum > 0 && t.net_value !== null ? (Number(r.net_value || 0) / valueSum) * 100 : null;
            return `
            <tr data-orig-sno="${i + 1}">
                <td class="num sno">${i + 1}</td>
                <td><span class="po-item-tag">${P.dash(r.item)}</span></td>
                <td class="rpt-mat">${P.dash(r.material)}</td>
                <td class="po-desc" title="${P.esc(r.description)}">${P.dash(r.description)}</td>
                <td>${P.dash(r.plant)}</td>
                <td>${P.dash(r.storage_location)}</td>
                <td class="num"><span class="qty-val">${P.qty(r.order_qty)}</span></td>
                <td>${P.dash(r.unit)}</td>
                <td class="num">${P.money(r.net_price)}</td>
                <td class="num po-value">${P.money(r.net_value)}</td>
                <td>${P.dash(r.currency)}</td>
                <td class="num po-share">${share === null ? '—' : `<span class="po-share-bar"><i style="width:${share.toFixed(1)}%"></i></span><span class="qty-val">${share.toFixed(1)}%</span>`}</td>
                <td class="po-col-sos">${P.soChips(r.sales_orders)}</td>
            </tr>`;
        }).join('');
        noMatchRow.insertAdjacentHTML('beforebegin', html);

        $('rptTableFoot').innerHTML = `
            <th id="footTotalLabel">Total</th><th></th><th></th><th></th><th></th><th></th>
            <th class="num">${t.order_qty === null ? '' : P.qty(t.order_qty)}</th>
            <th>${P.esc(t.unit || '')}</th>
            <th></th>
            <th class="num">${t.net_value === null ? '' : P.money(t.net_value)}</th>
            <th>${P.esc(t.currency || '')}</th>
            <th class="num">${t.net_value === null ? '' : '100%'}</th>
            <th></th>`;
        total = data.records.length;
        applySearch();
        fillCharts(data.records, t);
    }

    function applySearch() {
        const term = (searchInput?.value || '').trim().toLowerCase();
        let shown = 0;
        tbody.querySelectorAll('tr[data-orig-sno]').forEach((tr) => {
            const hit = term === '' || tr.textContent.toLowerCase().includes(term);
            tr.style.display = hit ? '' : 'none';
            if (hit) shown++;
        });
        if (noMatchRow) noMatchRow.style.display = total > 0 && shown === 0 ? '' : 'none';
        if (searchClear) searchClear.style.display = term ? '' : 'none';
        if (countBadge) countBadge.textContent = `Showing ${shown} of ${total} items`;
    }

    searchInput?.addEventListener('input', applySearch);
    searchClear?.addEventListener('click', () => { searchInput.value = ''; applySearch(); searchInput.focus(); });

    showSpinner(true);
    P.fetchPo(cfg.po)
        .then((data) => {
            if (!Array.isArray(data.records) || !data.records.length) {
                $('poHeroSub').textContent = 'Not found in SAP';
                showMessage(`No purchase order <b>${P.esc(cfg.po)}</b> was found in SAP.`);
                return;
            }
            fill(data);
        })
        .catch((err) => {
            const message = err.message || 'Unable to load SAP data.';
            $('poHeroSub').textContent = 'Could not be loaded';
            showMessage(P.esc(message));
            const alertBox = $('poAlert');
            if (alertBox) { alertBox.textContent = message; alertBox.hidden = false; }
        })
        .finally(() => showSpinner(false));
})();
