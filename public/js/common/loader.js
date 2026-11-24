/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 05/11/2026
 * DESCRIPTION : App-wide evolv boot spinner (#rptSpinner)
 */
(function () {
    if (window.rptLoader && window.rptLoader.__bound) return;

    const overlay = document.getElementById('rptSpinner');
    const msgEl = document.getElementById('rptLoaderMsg');
    const pctEl = document.getElementById('rptLoaderPct');
    const stepEl = document.getElementById('rptLoaderStep');
    const barEl = document.getElementById('rptLoaderBar');
    if (!overlay) return;

    let timer = null;
    let stepTimer = null;
    let safetyTimer = null;
    let pct = 0;
    let determinate = false;
    let steps = [];
    let stepIdx = 0;
    let visible = !overlay.hidden;
    let bootAutoHide = visible && overlay.getAttribute('data-autohide') === '1';

    function readSteps(from) {
        if (Array.isArray(from) && from.length) {
            return from.map(String).filter(Boolean);
        }
        if (stepEl) {
            try {
                const raw = stepEl.getAttribute('data-steps') || '[]';
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) return parsed.map(String).filter(Boolean);
            } catch (e) { /* ignore */ }
        }
        return [];
    }

    function setMessage(text) {
        if (!msgEl || text == null || text === '') return;
        msgEl.textContent = String(text);
        overlay.setAttribute('aria-label', String(text));
    }

    function setPct(value) {
        pct = Math.max(0, Math.min(100, Math.round(value)));
        if (pctEl) pctEl.textContent = pct + '%';
        if (determinate && barEl) {
            barEl.style.width = pct + '%';
        }
    }

    function clearStepCycle() {
        clearInterval(stepTimer);
        stepTimer = null;
    }

    function clearSafety() {
        clearTimeout(safetyTimer);
        safetyTimer = null;
    }

    function startStepCycle() {
        clearStepCycle();
        if (!steps.length || determinate) return;
        stepIdx = 0;
        setMessage(steps[0]);
        if (steps.length < 2) return;
        stepTimer = setInterval(function () {
            if (!visible) return;
            stepIdx = (stepIdx + 1) % steps.length;
            setMessage(steps[stepIdx]);
        }, 700);
    }

    function tick() {
        if (determinate || !visible) return;
        if (pct < 90) {
            // Faster ramp so boot spinner does not linger on light pages
            const step = pct < 40 ? 8 : (pct < 70 ? 4.5 : 1.8);
            setPct(pct + step);
        }
    }

    function start() {
        if (timer) return;
        determinate = false;
        if (barEl) barEl.style.width = '';
        setPct(0);
        timer = setInterval(tick, 120);
        startStepCycle();
    }

    function stop() {
        clearInterval(timer);
        timer = null;
        clearStepCycle();
    }

    function syncContentScope() {
        const page = document.getElementById('poCostingPage');
        const scoped = !!(page || overlay.getAttribute('data-scope') === 'content'
            || document.body.classList.contains('is-poc-loader-scoped'));
        overlay.classList.toggle('is-content-scoped', scoped);
        if (!scoped || !page) return;
        document.body.classList.add('is-poc-loader-scoped');
        const bar = page.querySelector(':scope > .rpt-bar');
        const nav = page.querySelector('.poc-sidenav');
        const landing = page.querySelector('.poc-landing');
        let top = 0;
        let left = 0;
        if (landing) {
            const r = landing.getBoundingClientRect();
            top = Math.max(0, Math.round(r.top));
            left = Math.max(0, Math.round(r.left));
        } else {
            top = bar ? Math.round(bar.getBoundingClientRect().bottom) : 64;
            left = nav ? Math.round(nav.getBoundingClientRect().right) : 72;
            if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
                left = 0;
                if (nav) top = Math.round(nav.getBoundingClientRect().bottom);
            }
        }
        document.documentElement.style.setProperty('--poc-loader-top', top + 'px');
        document.documentElement.style.setProperty('--poc-loader-left', left + 'px');
    }

    function show(options) {
        bootAutoHide = false;
        options = options || {};
        if (options.solid !== false) overlay.classList.add('is-solid');
        if (Array.isArray(options.steps)) {
            steps = readSteps(options.steps);
            if (stepEl) stepEl.setAttribute('data-steps', JSON.stringify(steps));
        } else if (!steps.length) {
            steps = readSteps();
        }
        const msg = options.message || options.title || options.label || '';
        if (msg) setMessage(msg);
        else if (steps.length) setMessage(steps[0]);

        visible = true;
        overlay.hidden = false;
        document.body.classList.add('rpt-loading');
        syncContentScope();

        if (typeof options.percent === 'number') {
            determinate = true;
            overlay.classList.add('is-progress');
            clearStepCycle();
            setPct(options.percent);
        } else {
            stop();
            start();
        }

        // Never leave the full-page overlay stuck forever
        clearSafety();
        safetyTimer = window.setTimeout(function () {
            if (visible) hide(true);
        }, 45000);
    }

    function hide(force) {
        // Full-page overlay: always dismiss (force kept for API compatibility)
        void force;
        if (!visible && overlay.hidden) {
            stop();
            clearSafety();
            return;
        }
        visible = false;
        bootAutoHide = false;
        setPct(100);
        stop();
        clearSafety();
        window.setTimeout(function () {
            if (visible) return;
            overlay.hidden = true;
            overlay.classList.remove('is-solid', 'is-progress');
            document.body.classList.remove('rpt-loading');
            if (barEl) barEl.style.width = '';
            setPct(0);
        }, 120);
    }

    window.rptLoader = {
        __bound: true,
        show: show,
        hide: hide,
        message: setMessage,
        progress(done, total, message) {
            if (!visible || overlay.hidden) show();
            determinate = true;
            clearStepCycle();
            overlay.classList.add('is-progress');
            if (message) setMessage(message);
            const next = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 100;
            setPct(next);
        },
        isVisible() {
            return visible && !overlay.hidden;
        },
    };

    steps = readSteps();

    new MutationObserver(function () {
        if (overlay.hidden) {
            visible = false;
            stop();
            clearSafety();
            document.body.classList.remove('rpt-loading');
        } else if (!timer && !determinate) {
            visible = true;
            start();
        }
    }).observe(overlay, { attributes: true, attributeFilter: ['hidden'] });

    if (visible) {
        start();
        clearSafety();
        // Always clear a stuck boot overlay eventually (even when page JS owns hide)
        safetyTimer = window.setTimeout(function () {
            if (visible) hide(true);
        }, 45000);
    }

    function onDomReadyScope() {
        syncContentScope();
        if (visible) syncContentScope();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onDomReadyScope);
    } else {
        onDomReadyScope();
    }
    window.addEventListener('resize', function () {
        if (visible) syncContentScope();
    });

    function autoHideBoot() {
        if (!bootAutoHide) return;
        hide(true);
        bootAutoHide = false;
    }

    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        window.setTimeout(autoHideBoot, 80);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(autoHideBoot, 80);
        });
        window.addEventListener('load', function () {
            window.setTimeout(autoHideBoot, 120);
        });
    }
    // Hard cap — never block the UI longer than a couple of seconds on boot
    window.setTimeout(autoHideBoot, 2200);

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
        if (a.hasAttribute('data-poc-panel') || a.hasAttribute('data-rpt-no-loader')) return;
        const href = a.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        try {
            const url = new URL(a.href, window.location.href);
            if (url.origin !== window.location.origin) return;
            if (!/\/sap_reports(\/|$)/i.test(url.pathname)) return;
            if (url.href.split('#')[0] === window.location.href.split('#')[0]) return;
            if (url.pathname === window.location.pathname) {
                const nextPanel = url.searchParams.get('panel');
                const curPanel = new URL(window.location.href).searchParams.get('panel');
                if (nextPanel !== null || curPanel !== null) return;
            }
            show({ solid: true });
        } catch (err) { /* ignore */ }
    }, true);

    window.addEventListener('pageshow', function (e) {
        if (e.persisted) hide(true);
    });
})();
