/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 19/11/2026
 * DESCRIPTION : Search PO — header search navigates to Costing Workbench (view=costing&po=…)
 */
(function () {
    'use strict';

    const page = document.getElementById('poCostingPage');
    const panel = document.getElementById('pocPanelSearchPo');
    if (!page || !panel) return;

    const poBase = page.getAttribute('data-po-base') || '';
    const $ = (id) => document.getElementById(id);

    function workbenchUrl(po, mat) {
        try {
            const u = new URL(poBase || 'purchase-order', window.location.origin);
            u.searchParams.set('view', 'costing');
            u.searchParams.set('po', String(po || ''));
            if (mat === 'fabric' || mat === 'trims') u.searchParams.set('mat_cat', mat);
            u.searchParams.set('from', 'search');
            try {
                const ret = new URL('po-costing', window.location.origin);
                ret.searchParams.set('panel', 'search');
                u.searchParams.set('return', ret.pathname + ret.search);
            } catch (_) {}
            return u.pathname + u.search;
        } catch (_) {
            const q = new URLSearchParams({ view: 'costing', po: String(po || ''), from: 'search' });
            if (mat === 'fabric' || mat === 'trims') q.set('mat_cat', mat);
            return `${poBase || 'purchase-order'}?${q}`;
        }
    }

    function readHeaderPo() {
        const el = $('pocSearchPoInput');
        return String(el?.value || '').replace(/\D/g, '').slice(0, 10);
    }

    function openWorkbench(po) {
        const n = String(po || '').replace(/\D/g, '').slice(0, 10);
        if (!n) {
            $('pocSearchPoInput')?.focus();
            return;
        }
        window.location.assign(workbenchUrl(n));
    }

    function openPanel() {
        page.classList.add('is-search-po', 'is-lookup');
        page.classList.remove('is-released-po', 'is-costing-bulk', 'is-related-po', 'is-related-results', 'is-workflow');
        const header = $('pocSearchPoHeaderFilters');
        if (header) header.hidden = false;
        const titleEl = $('pocPageTitle');
        if (titleEl) titleEl.innerHTML = '<strong>Search PO</strong>';
        try { if (window.rptLoader) window.rptLoader.hide(true); } catch (_) {}
        setTimeout(() => { $('pocSearchPoInput')?.focus(); }, 40);
    }

    function closePanel() {
        page.classList.remove('is-search-po', 'is-lookup');
        const header = $('pocSearchPoHeaderFilters');
        if (header) header.hidden = true;
    }

    function bindUi() {
        const goWorkbench = (e) => {
            if (e) e.preventDefault();
            openWorkbench(readHeaderPo());
        };
        $('pocSearchPoHeaderFilters')?.addEventListener('submit', goWorkbench);
        $('pocSearchPoBtn')?.addEventListener('click', goWorkbench);
        $('pocSearchPoInput')?.addEventListener('input', () => {
            const el = $('pocSearchPoInput');
            if (!el) return;
            el.value = String(el.value || '').replace(/\D/g, '');
        });
    }

    window.pocSearchPo = {
        open: openPanel,
        close: closePanel,
        search: (po) => openWorkbench(po),
    };

    bindUi();
})();
