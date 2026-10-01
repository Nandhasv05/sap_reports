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
            { id: 'items', icon: 'format_list_numbered', tone: 'indigo', label: 'Items', value: qty(t.items), unit: '' },
            { id: 'materials', icon: 'category', tone: 'teal', label: 'Materials', value: qty(t.materials), unit: '' },
            { id: 'qty', icon: 'straighten', tone: 'sky', label: 'Total Order Qty', value: t.order_qty === null ? 'Mixed units' : qty(t.order_qty), unit: t.order_qty === null ? '' : (t.unit || '') },
            { id: 'value', icon: 'payments', tone: 'mint', label: 'Total Net Value', value: t.net_value === null ? 'Mixed currency' : money(t.net_value), unit: t.net_value === null ? '' : (t.currency || '') },
            { id: 'so', icon: 'receipt_long', tone: 'amber', label: 'Sales Orders', value: qty((h.sales_orders || []).length), unit: '' },
        ];
    }

    function soChips(list) {
        return (list || []).length ? list.map((so) => `<span class="so-chip po-so-chip" data-export="${esc(so)}">${esc(so)}</span>`).join('') : '—';
    }

    window.PoReport = { cfg, esc, qty, money, dash, fmtDate, cleanPo, validPo, fetchPo, infoItems, statItems, soChips };

    /* ---------- off-canvas drawer ---------- */
    let drawer = null;
    let controller = null;
    let current = { po: '', item: '' };

    function buildDrawer() {
        const el = document.createElement('div');
        el.className = 'po-drawer';
        el.hidden = true;
        el.innerHTML = `
            <div class="po-drawer-backdrop" data-po-close></div>
            <aside class="po-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="poDrawerTitle">
                <header class="po-drawer-head">
                    <div>
                        <span class="po-drawer-kicker">Purchase Order</span>
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
                        <div class="po-info" data-po-info></div>
                        <div class="po-stats" data-po-stats></div>
                        <h4 class="po-section-title">Items</h4>
                        <div class="po-table-wrap">
                            <table class="po-table">
                                <thead><tr>
                                    <th>Item</th><th>Material</th><th>Description</th><th class="num">Order Qty</th><th>Unit</th>
                                    <th class="num">Net Price</th><th class="num">Net Value</th><th>Sales Orders</th>
                                </tr></thead>
                                <tbody data-po-rows></tbody>
                                <tfoot><tr data-po-foot></tr></tfoot>
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
        drawer.querySelector('#poDrawerSub').textContent = [fmtDate(h.date), h.supplier ? `Supplier ${h.supplier}` : '', (h.plants || []).join(', ')].filter((v) => v && v !== '—').join(' · ');
        drawer.querySelector('[data-po-info]').innerHTML = infoItems(h).map(([l, v]) => `<div class="po-info-item"><small>${l}</small><b>${dash(v)}</b></div>`).join('');
        drawer.querySelector('[data-po-stats]').innerHTML = statItems(data).map((s) => `<div class="po-stat"><span class="material-icons-round">${s.icon}</span><div><b>${esc(s.value)}${s.unit ? ` <em>${esc(s.unit)}</em>` : ''}</b><small>${s.label}</small></div></div>`).join('');
        drawer.querySelector('[data-po-rows]').innerHTML = data.records.map((r) => `
            <tr class="${current.item !== '' && String(r.item) === current.item ? 'is-current' : ''}">
                <td>${dash(r.item)}</td>
                <td class="mono">${dash(r.material)}</td>
                <td class="po-desc">${dash(r.description)}</td>
                <td class="num">${qty(r.order_qty)}</td>
                <td>${dash(r.unit)}</td>
                <td class="num">${money(r.net_price)}</td>
                <td class="num">${money(r.net_value)} ${esc(r.currency)}</td>
                <td>${soChips(r.sales_orders)}</td>
            </tr>`).join('');
        drawer.querySelector('[data-po-foot]').innerHTML = `
            <th colspan="3">Total (${qty(t.items)} items)</th>
            <th class="num">${t.order_qty === null ? '' : qty(t.order_qty)}</th>
            <th>${esc(t.unit || '')}</th>
            <th></th>
            <th class="num">${t.net_value === null ? '' : `${money(t.net_value)} ${esc(t.currency || '')}`}</th>
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
