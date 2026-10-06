/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 28/09/2026
 * DESCRIPTION : Cycles the SAP loading overlay status text and elapsed time while #rptSpinner is visible
 */
(function () {
    const overlay = document.getElementById('rptSpinner');
    const stepEl = document.getElementById('rptLoaderStep');
    const timeEl = document.getElementById('rptLoaderTime');
    const barEl = document.getElementById('rptLoaderBar');
    const countEl = document.getElementById('rptLoaderCount');
    if (!overlay || !stepEl) return;

    let steps = [];
    try { steps = JSON.parse(stepEl.getAttribute('data-steps') || '[]'); } catch (e) { steps = []; }
    if (!steps.length) steps = [stepEl.textContent];

    const STEP_MS = 1700;
    let timer = null;
    let startedAt = 0;
    let progressText = '';

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
        setStep(progressText || steps[Math.min(steps.length - 1, Math.floor(elapsed / STEP_MS))]);
        if (timeEl) {
            const secs = Math.floor(elapsed / 1000);
            timeEl.textContent = secs >= 60 ? `${Math.floor(secs / 60)}m ${secs % 60}s` : secs + 's';
        }
    }

    function start() {
        if (timer) return;
        startedAt = Date.now();
        progressText = '';
        overlay.classList.remove('is-progress');
        if (barEl) barEl.style.width = '';
        if (countEl) countEl.textContent = '';
        setStep(steps[0], true);
        if (timeEl) timeEl.textContent = '0s';
        timer = setInterval(tick, 500);
    }

    function stop() {
        clearInterval(timer);
        timer = null;
    }

    // Determinate progress for multi-request loads, e.g. rptLoader.progress(17, 102, 'Fetching production…', 'sales orders')
    window.rptLoader = {
        progress(done, total, text, unit) {
            const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 100;
            progressText = text || progressText;
            overlay.classList.add('is-progress');
            if (barEl) barEl.style.width = pct + '%';
            if (countEl) countEl.textContent = `${done.toLocaleString('en-US')} of ${total.toLocaleString('en-US')} ${unit || ''} · ${pct}%`.replace(/\s+·/, ' ·');
            setStep(progressText);
        },
    };

    new MutationObserver(() => (overlay.hidden ? stop() : start()))
        .observe(overlay, { attributes: true, attributeFilter: ['hidden'] });
    if (!overlay.hidden) start();

    document.querySelectorAll('#rptFilterForm, [data-date-filter]').forEach((form) => {
        form.addEventListener('submit', () => {
            overlay.hidden = false;
            document.body.classList.add('rpt-loading');
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
