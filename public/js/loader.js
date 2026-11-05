/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : Minimal app-wide spinner + percent (#rptSpinner)
 */
(function () {
    if (window.rptLoader && window.rptLoader.__bound) return;

    const overlay = document.getElementById('rptSpinner');
    const pctEl = document.getElementById('rptLoaderPct');
    if (!overlay || !pctEl) return;

    let timer = null;
    let showCount = 0;
    let pct = 0;
    let determinate = false;

    function setPct(value) {
        pct = Math.max(0, Math.min(100, Math.round(value)));
        pctEl.textContent = pct + '%';
    }

    function tick() {
        if (determinate) return;
        // Ease toward ~90% while waiting; finish to 100% on hide.
        if (pct < 90) {
            const step = pct < 40 ? 3.2 : (pct < 70 ? 1.6 : 0.55);
            setPct(pct + step);
        }
    }

    function start() {
        if (timer) return;
        determinate = false;
        setPct(0);
        timer = setInterval(tick, 180);
    }

    function stop() {
        clearInterval(timer);
        timer = null;
    }

    function show(options) {
        showCount += 1;
        if (options && options.solid) overlay.classList.add('is-solid');
        overlay.hidden = false;
        document.body.classList.add('rpt-loading');
        if (!(options && typeof options.percent === 'number')) {
            start();
        } else {
            determinate = true;
            setPct(options.percent);
        }
    }

    function hide(force) {
        showCount = force ? 0 : Math.max(0, showCount - 1);
        if (showCount > 0) return;
        setPct(100);
        stop();
        window.setTimeout(function () {
            if (showCount > 0) return;
            overlay.hidden = true;
            overlay.classList.remove('is-solid', 'is-progress');
            document.body.classList.remove('rpt-loading');
            setPct(0);
        }, 120);
    }

    window.rptLoader = {
        __bound: true,
        show: show,
        hide: hide,
        progress(done, total) {
            if (overlay.hidden) show();
            determinate = true;
            overlay.classList.add('is-progress');
            const next = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 100;
            setPct(next);
        },
    };

    new MutationObserver(function () {
        if (overlay.hidden) stop();
        else if (!timer && !determinate) start();
    }).observe(overlay, { attributes: true, attributeFilter: ['hidden'] });

    if (!overlay.hidden) start();

    document.querySelectorAll('#rptFilterForm, [data-date-filter]').forEach(function (form) {
        form.addEventListener('submit', function () {
            show({ solid: true });
        });
    });

    document.addEventListener('click', function (e) {
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (a.target && a.target !== '_self') return;
        const href = a.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        try {
            const url = new URL(a.href, window.location.href);
            if (url.origin !== window.location.origin) return;
            if (!/\/sap_reports(\/|$)/i.test(url.pathname)) return;
            if (url.href.split('#')[0] === window.location.href.split('#')[0]) return;
            show({ solid: true });
        } catch (err) { /* ignore */ }
    }, true);

    window.addEventListener('pageshow', function (e) {
        if (e.persisted) hide(true);
    });
})();
