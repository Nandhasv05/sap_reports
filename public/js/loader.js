/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Cycles the SAP loading overlay status text and elapsed time while #rptSpinner is visible
 */
(function () {
    const overlay = document.getElementById('rptSpinner');
    const stepEl = document.getElementById('rptLoaderStep');
    const timeEl = document.getElementById('rptLoaderTime');
    if (!overlay || !stepEl) return;

    let steps = [];
    try { steps = JSON.parse(stepEl.getAttribute('data-steps') || '[]'); } catch (e) { steps = []; }
    if (!steps.length) steps = [stepEl.textContent];

    const STEP_MS = 1700;
    let timer = null;
    let startedAt = 0;

    function setStep(text, force) {
        if (!force && stepEl.textContent === text) return;
        stepEl.classList.remove('is-in');
        void stepEl.offsetWidth;
        stepEl.textContent = text;
        stepEl.classList.add('is-in');
    }

    function tick() {
        const elapsed = Date.now() - startedAt;
        // Walk through the steps once, then stay on the last one ("Preparing report…").
        setStep(steps[Math.min(steps.length - 1, Math.floor(elapsed / STEP_MS))]);
        if (timeEl) timeEl.textContent = Math.floor(elapsed / 1000) + 's';
    }

    function start() {
        if (timer) return;
        startedAt = Date.now();
        setStep(steps[0], true);
        if (timeEl) timeEl.textContent = '0s';
        timer = setInterval(tick, 500);
    }

    function stop() {
        clearInterval(timer);
        timer = null;
    }

    new MutationObserver(() => (overlay.hidden ? stop() : start()))
        .observe(overlay, { attributes: true, attributeFilter: ['hidden'] });
    if (!overlay.hidden) start();

    document.querySelectorAll('#rptFilterForm').forEach((form) => {
        form.addEventListener('submit', () => {
            const so = form.querySelector('[name="so"]');
            if (so && so.value.trim() !== '') {
                overlay.hidden = false;
                document.body.classList.add('rpt-loading');
            }
        });
    });

    // Coming back with the browser Back button can restore the page with the overlay still open.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            overlay.hidden = true;
            document.body.classList.remove('rpt-loading');
        }
    });
})();
