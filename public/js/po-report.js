/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 01/10/2026
 * DESCRIPTION : Purchase order helpers shared by the Purchase Order report page and the PO drawer.
 *               Any element with [data-po-open="4000006524"] (optional data-po-item="10") opens the PO in an off-canvas drawer.
 *               Expects <script type="application/json" id="poConfig">{"dataUrl": "...", "pageUrl": "..."}</script>.
 */
(function () {
    let cfg = {};
    try { cfg = JSON.parse(document.getElementById('poConfig')?.textContent || '{}'); } catch (e) { cfg = {}; }

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const qty = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 3 });
    const money = (n) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtRound = (n) => Math.round(Number(n || 0)).toLocaleString('en-US');
    const dash = (v) => (v === '' || v === null || v === undefined ? '—' : esc(v));
    const fmtDate = (iso) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        return m ? `${m[3]}-${m[2]}-${m[1]}` : '—';
    };
    const cleanPo = (v) => String(v ?? '').replace(/[\s,]/g, '');
    const validPo = (v) => /^\d{1,10}$/.test(v);

    const cache = new Map();

    /* Resolves with the report payload ({header, records, totals}); rejects with a readable message. */
    async function fetchPo(po, signal) {
        if (cache.has(po)) return cache.get(po);
        const res = await fetch(`${cfg.dataUrl}?${new URLSearchParams({ po, _: String(Date.now()) })}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal,
        });
        const raw = await res.text();
        let json;
        try { json = JSON.parse(raw); } catch (e) {
            if (res.redirected || /login/i.test(res.url)) throw new Error('Your portal session has expired. Please log in again and reopen the report.');
            throw new Error(`SAP data could not be read (HTTP ${res.status}). Please retry.`);
        }
        if (!res.ok || json.success === false) throw new Error(json.message || json.error || `SAP API failed (HTTP ${res.status}).`);
        const data = json.data || {};
        if (Array.isArray(data.records) && data.records.length) cache.set(po, data);
        return data;
    }

    // Handle Info Items Functions
    function infoItems(h) {
        return [
            ['Purchase Order', h.purchase_order],
            ['PO Date', fmtDate(h.date)],
            ['Supplier', h.supplier],
            ['Company Code', h.company_code],
            ['Purchasing Org.', h.purchasing_org],
            ['Purchasing Group', h.purchasing_group],
            ['Created By', h.created_by],
            ['Plant', (h.plants || []).join(', ')],
        ];
    }

    function statItems(data) {
        const t = data.totals || {};
        const h = data.header || {};
        return [
            { id: 'items', icon: 'format_list_numbered', tone: 'indigo', label: 'Items', value: fmtRound(t.items), unit: '' },
            { id: 'materials', icon: 'category', tone: 'teal', label: 'Materials', value: fmtRound(t.materials), unit: '' },
            { id: 'qty', icon: 'straighten', tone: 'sky', label: 'Total Order Qty', value: t.order_qty === null ? 'Mixed units' : fmtRound(t.order_qty), unit: t.order_qty === null ? '' : (t.unit || ''), rawQty: t.order_qty },
            { id: 'value', icon: 'payments', tone: 'mint', label: 'Total Net Value', value: t.net_value === null ? 'Mixed currency' : fmtRound(t.net_value), unit: t.net_value === null ? '' : (t.currency || ''), rawValue: t.net_value },
            { id: 'so', icon: 'receipt_long', tone: 'amber', label: 'Sales Orders', value: fmtRound((h.sales_orders || []).length), unit: '' },
        ];
    }

    function soChips(list, max = 3) {
        if (!list || !list.length) return '—';
        if (typeof max === 'number' && max > 0 && list.length > max) {
            const shown = list.slice(0, max);
            const remaining = list.length - max;
            const fullList = list.join(', ');
            return shown.map((so) => `<span class="so-chip po-so-chip" data-export="${esc(so)}">${esc(so)}</span>`).join('') +
                `<span class="so-chip po-so-chip po-so-more" title="${esc(fullList)}" data-export="${esc(fullList)}">+${remaining}</span>`;
        }
        return list.map((so) => `<span class="so-chip po-so-chip" data-export="${esc(so)}">${esc(so)}</span>`).join('');
    }

    window.PoReport = { cfg, esc, qty, money, fmtRound, dash, fmtDate, cleanPo, validPo, fetchPo, infoItems, statItems, soChips };

    /* ---------- off-canvas drawer ---------- */
    let drawer = null;
    let controller = null;
    let current = { po: '', item: '' };
    let drawerChartInstances = [];
    const palette = ['#4338ca', '#0f766e', '#0284c7', '#d97706', '#be123c', '#7c3aed', '#16a34a', '#64748b'];

    function buildDrawer() {
        const el = document.createElement('div');
        el.className = 'po-drawer';
        el.hidden = true;
        el.innerHTML = `
            <div class="po-drawer-backdrop" data-po-close></div>
            <aside class="po-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="poDrawerTitle">
                <header class="po-drawer-head">
                    <div>
                        <span class="po-drawer-kicker">Purchase Order Dashboard</span>
                        <h3 id="poDrawerTitle">—</h3>
                        <p id="poDrawerSub"></p>
                    </div>
                    <div class="po-drawer-actions">
                        <a class="rpt-tb-btn" id="poDrawerFull" href="#" target="_blank" rel="noopener" title="Open the full Purchase Order report in a new tab">
                            <i class="fas fa-up-right-from-square"></i><span>Full report</span>
                        </a>
                        <button type="button" class="modal-close" data-po-close aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                </header>
                <div class="po-drawer-body">
                    <div class="po-state" data-po-state="loading"><span class="po-spinner" aria-hidden="true"></span><p>Fetching purchase order from SAP…</p></div>
                    <div class="po-state is-error" data-po-state="error" hidden><i class="fas fa-triangle-exclamation"></i><p data-po-error></p><button type="button" class="po-retry" data-po-retry>Try again</button></div>
                    <div class="po-state" data-po-state="empty" hidden><i class="fas fa-magnifying-glass"></i><p data-po-empty></p></div>
                    <div data-po-state="result" hidden>
                        <!-- Hero Section matching detail dashboard -->
                        <section class="po-hero" id="poDrawerHero" style="margin-bottom: 18px;">
                            <div class="po-hero-main">
                                <span class="po-hero-icon material-icons-round" aria-hidden="true">receipt</span>
                                <div class="po-hero-title">
                                    <span class="po-hero-kicker">Purchase Order</span>
                                    <h1 id="poDrawerHeroTitle">—</h1>
                                    <p id="poDrawerHeroSub"></p>
                                </div>
                            </div>
                            <dl class="po-hero-meta" id="poDrawerHeroMeta"></dl>
                            <div class="po-hero-sos">
                                <span>Linked Sales Orders</span>
                                <div id="poDrawerHeroSos"><span class="po-muted">—</span></div>
                            </div>
                        </section>

                        <!-- KPI Summary Stat Cards -->
                        <div class="rpt-stats po-stat-row" id="poDrawerStats" style="margin-bottom: 18px;"></div>

                        <!-- 2 Detail Charts -->
                        <div class="rpt-charts po-charts" style="margin-bottom: 22px;">
                            <section class="rpt-chart-card">
                                <div class="rpt-chart-head">
                                    <span class="material-icons-round">bar_chart</span>
                                    <div>
                                        <h2>Order Qty &amp; Net Value By Item</h2>
                                        <p>Bars show order quantity, the line shows net value</p>
                                    </div>
                                </div>
                                <div class="rpt-chart-body" style="height: 280px;"><canvas id="poDrawerItemChart"></canvas></div>
                            </section>
                            <section class="rpt-chart-card">
                                <div class="rpt-chart-head">
                                    <span class="material-icons-round">donut_large</span>
                                    <div>
                                        <h2>Net Value Share By Material</h2>
                                        <p>Share of the purchase order value</p>
                                    </div>
                                </div>
                                <div class="rpt-chart-body rpt-chart-donut" style="height: 280px;"><canvas id="poDrawerShareChart"></canvas></div>
                            </section>
                        </div>

                        <!-- Items Table -->
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                            <h4 class="po-section-title" style="margin: 0;">Items Breakdown</h4>
                            <span id="poDrawerItemCount" style="font-size: 13px; font-weight: 600; color: #64748b;"></span>
                        </div>
                        <div class="rpt-table-wrap is-scroll-body" style="border: 1px solid #e2e8f0; border-radius: 12px; max-height: 480px; overflow: auto; background: #fff;">
                            <table class="rpt-table prod-table po-page-table text-nowrap">
                                <thead>
                                    <tr class="rpt-header-row">
                                        <th class="num sno">S.No</th>
                                        <th>Item</th>
                                        <th>Material</th>
                                        <th>Description</th>
                                        <th class="num">Order Qty</th>
                                        <th>Unit</th>
                                        <th class="num">Net Price</th>
                                        <th class="num">Net Value</th>
                                        <th class="num po-share">Value Share</th>
                                        <th>Sales Orders</th>
                                    </tr>
                                </thead>
                                <tbody data-po-rows></tbody>
                                <tfoot><tr id="poDrawerFoot" class="rpt-table-foot"></tr></tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </aside>`;
        document.body.appendChild(el);
        el.addEventListener('click', (e) => {
            if (e.target.closest('[data-po-close]')) close();
            if (e.target.closest('[data-po-retry]')) load();
        });
        return el;
    }

    function setState(name) {
        drawer.querySelectorAll('[data-po-state]').forEach((s) => { s.hidden = s.getAttribute('data-po-state') !== name; });
    }

    function render(data) {
        const h = data.header || {};
        const t = data.totals || {};
        const records = Array.isArray(data.records) ? data.records : [];
        const poNum = h.purchase_order || current.po;

        // Subtitle
        const sub = [fmtDate(h.date), h.supplier ? `Supplier ${h.supplier}` : '', (h.plants || []).join(', ')].filter((v) => v && v !== '—').join(' · ');
        drawer.querySelector('#poDrawerTitle').textContent = `PO ${poNum}`;
        drawer.querySelector('#poDrawerSub').textContent = sub;

        // Hero
        drawer.querySelector('#poDrawerHeroTitle').textContent = poNum;
        drawer.querySelector('#poDrawerHeroSub').textContent = [
            h.date ? `Dated ${fmtDate(h.date)}` : '',
            h.created_by ? `Created by ${h.created_by}` : '',
            `${qty(t.items || records.length)} item${(t.items || records.length) === 1 ? '' : 's'}`,
        ].filter(Boolean).join(' · ');

        const metaEntries = infoItems(h).filter(([label]) => label !== 'Purchase Order');
        drawer.querySelector('#poDrawerHeroMeta').innerHTML = metaEntries.map(([l, v]) => `<div><dt>${l}</dt><dd>${dash(v)}</dd></div>`).join('');
        drawer.querySelector('#poDrawerHeroSos').innerHTML = (h.sales_orders || []).length ? soChips(h.sales_orders, 10) : '<span class="po-muted">None</span>';

        // 5 KPI Stat Cards
        drawer.querySelector('#poDrawerStats').innerHTML = statItems(data).map((s) => `
            <article class="rpt-stat tone-${s.tone}">
                <span class="material-icons-round">${s.icon}</span>
                <div>
                    <div class="stat-value-wrap">
                        <b>${esc(s.value)}</b>
                        <em class="po-stat-unit">${esc(s.unit || '')}</em>
                    </div>
                    <small>${esc(s.label)}</small>
                </div>
            </article>`).join('');

        // Clean previous chart instances
        drawerChartInstances.forEach((c) => { try { c.destroy(); } catch (e) {} });
        drawerChartInstances = [];

        // Detail Charts
        const itemCanvas = drawer.querySelector('#poDrawerItemChart');
        const shareCanvas = drawer.querySelector('#poDrawerShareChart');
        if (typeof Chart !== 'undefined' && itemCanvas && shareCanvas) {
            const font = { family: 'Inter, sans-serif', size: 11 };

            // 1. Order Qty & Net Value By Item
            const c1 = new Chart(itemCanvas, {
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
                                title: (list) => { const r = records[list[0].dataIndex]; return `Item ${r.item} · ${r.material}`; },
                                label: (c) => `${c.dataset.label}: ${c.dataset.yAxisID === 'y1' ? money(c.raw) : qty(c.raw)}`,
                            },
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { font } },
                        y: { beginAtZero: true, ticks: { font }, title: { display: true, text: 'Qty', font } },
                        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { font }, title: { display: true, text: 'Value', font } },
                    },
                },
            });
            drawerChartInstances.push(c1);

            // 2. Net Value Share By Material
            const byMaterial = new Map();
            records.forEach((r) => byMaterial.set(r.material, (byMaterial.get(r.material) || 0) + Number(r.net_value || 0)));
            const entries = [...byMaterial.entries()].sort((a, b) => b[1] - a[1]);
            const sum = entries.reduce((s, [, v]) => s + v, 0);

            const c2 = new Chart(shareCanvas, {
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
                        tooltip: { callbacks: { label: (c) => `${c.label}: ${money(c.raw)} ${t.currency || ''} (${sum > 0 ? ((c.raw / sum) * 100).toFixed(1) : 0}%)` } },
                    },
                },
            });
            drawerChartInstances.push(c2);
        }

        // Items Table
        const valSum = records.reduce((s, r) => s + Number(r.net_value || 0), 0);
        drawer.querySelector('#poDrawerItemCount').textContent = `${records.length} items`;
        drawer.querySelector('[data-po-rows]').innerHTML = records.map((r, i) => {
            const share = valSum > 0 ? ((Number(r.net_value || 0) / valSum) * 100) : 0;
            return `
                <tr class="${current.item !== '' && String(r.item) === current.item ? 'is-current' : ''}">
                    <td class="num sno">${i + 1}</td>
                    <td><span class="po-item-tag">${dash(r.item)}</span></td>
                    <td class="mono rpt-mat">${dash(r.material)}</td>
                    <td class="po-desc" title="${esc(r.description)}">${dash(r.description)}</td>
                    <td class="num"><span class="qty-val">${qty(r.order_qty)}</span></td>
                    <td>${dash(r.unit)}</td>
                    <td class="num"><span class="qty-val">${money(r.net_price)}</span></td>
                    <td class="num po-value"><span class="qty-val">${money(r.net_value)} ${esc(r.currency || '')}</span></td>
                    <td class="num po-share"><span class="po-share-bar"><i style="width:${share.toFixed(1)}%"></i></span><span class="qty-val">${share.toFixed(1)}%</span></td>
                    <td class="po-col-sos"><div class="po-sos-inline">${soChips(r.sales_orders, 5)}</div></td>
                </tr>`;
        }).join('');

        drawer.querySelector('#poDrawerFoot').innerHTML = `
            <th colspan="4">Total (${qty(t.items || records.length)} items)</th>
            <th class="num">${t.order_qty === null ? '' : qty(t.order_qty)}</th>
            <th>${esc(t.unit || '')}</th>
            <th></th>
            <th class="num">${t.net_value === null ? '' : `${money(t.net_value)} ${esc(t.currency || '')}`}</th>
            <th class="num">100.0%</th>
            <th></th>`;

        drawer.querySelector('tr.is-current')?.scrollIntoView({ block: 'nearest' });
    }

    async function load() {
        controller?.abort();
        controller = new AbortController();
        setState('loading');
        try {
            const data = await fetchPo(current.po, controller.signal);
            if (!Array.isArray(data.records) || !data.records.length) {
                drawer.querySelector('[data-po-empty]').innerHTML = `No purchase order <b>${esc(current.po)}</b> was found in SAP.`;
                setState('empty');
                return;
            }
            render(data);
            setState('result');
        } catch (err) {
            if (err.name === 'AbortError') return;
            drawer.querySelector('[data-po-error]').textContent = err.message || 'Unable to load the purchase order.';
            setState('error');
        }
    }

    function open(po, item) {
        po = cleanPo(po);
        if (!validPo(po) || !cfg.dataUrl) return;
        drawer = drawer || buildDrawer();
        current = { po, item: String(item ?? '').replace(/^0+(?=\d)/, '') };
        drawer.querySelector('#poDrawerTitle').textContent = po;
        drawer.querySelector('#poDrawerSub').textContent = current.item ? `Item ${current.item}` : '';
        const full = drawer.querySelector('#poDrawerFull');
        full.hidden = !cfg.pageUrl;
        if (cfg.pageUrl) full.href = `${cfg.pageUrl}?${new URLSearchParams({ po })}`;
        drawer.hidden = false;
        requestAnimationFrame(() => drawer.classList.add('is-open'));
        document.body.classList.add('po-drawer-open');
        drawer.querySelector('.modal-close').focus();
        load();
    }

    function close() {
        if (!drawer || drawer.hidden) return;
        controller?.abort();
        drawerChartInstances.forEach((c) => { try { c.destroy(); } catch (e) {} });
        drawerChartInstances = [];
        drawer.classList.remove('is-open');
        document.body.classList.remove('po-drawer-open');
        setTimeout(() => { if (!drawer.classList.contains('is-open')) drawer.hidden = true; }, 250);
    }

    window.PoReport.openDrawer = open;
    window.PoReport.closeDrawer = close;

    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-po-open]');
        if (!trigger) return;
        e.preventDefault();
        open(trigger.getAttribute('data-po-open'), trigger.getAttribute('data-po-item'));
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && drawer && !drawer.hidden) close();
    });
})();
