/*
 * AUTHOR : NANDHAKUMAR S V
 * DATE : 03/09/2026
 * DESCRIPTION : Utilization JavaScript
 */
(function () {
    const app = document.querySelector('.rpt-app');
    const boot = document.getElementById('rptBoot');
    const spinner = document.getElementById('rptSpinner');
    const dataNode = document.getElementById('rptChartData');

    function showSpinner() {
        if (!spinner) {
            return;
        }
        spinner.hidden = false;
        document.body.classList.add('rpt-loading');
    }

    function hideBoot() {
        if (app) {
            app.classList.remove('is-first');
        }
        if (boot) {
            boot.hidden = true;
        }
        drawCharts();
    }

    const form = document.getElementById('rptFilterForm');
    form?.addEventListener('submit', function () {
        const so = (document.getElementById('so')?.value || '').trim();
        if (so === '') {
            return;
        }
        showSpinner();
    });

    document.querySelectorAll('.rpt-tab, .rpt-pager a, .lookup-hint a, .lookup-switch a').forEach(function (el) {
        el.addEventListener('click', function () {
            if (el.classList.contains('active') || el.classList.contains('on')) {
                return;
            }
            showSpinner();
        });
    });

    let chartsDrawn = false;
    function drawCharts() {
        if (chartsDrawn || !dataNode || typeof Chart === 'undefined') {
            return;
        }
        chartsDrawn = true;

        let data = {};
        try {
            data = JSON.parse(dataNode.textContent || '{}');
        } catch (e) {
            return;
        }

        const totalsEl = document.getElementById('rptTotalsChart');
        const mixEl = document.getElementById('rptMixChart');

        if (totalsEl) {
            new Chart(totalsEl, {
                type: 'bar',
                data: {
                    labels: data.totals_labels || [],
                    datasets: [{
                        label: 'Quantity',
                        data: data.totals || [],
                        backgroundColor: ['#0f766e', '#0284c7', '#16a34a', '#d97706', '#7c3aed', '#e11d48'],
                        borderRadius: 8,
                        maxBarThickness: 42,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 900 },
                    plugins: {
                        legend: { display: false },
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                    },
                },
            });
        }

        if (mixEl) {
            const mixLabels = data.mix_labels || [];
            const mixValues = data.mix_values || [];
            const mixColors = ['#0f766e', '#0284c7', '#7c3aed', '#d97706', '#16a34a', '#e11d48', '#0ea5e9', '#94a3b8'];
            new Chart(mixEl, {
                type: 'doughnut',
                data: {
                    labels: mixLabels.length ? mixLabels : ['No data'],
                    datasets: [{
                        data: mixValues.length ? mixValues : [1],
                        backgroundColor: mixValues.length ? mixColors : ['#e2e8e0'],
                        borderWidth: 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    animation: { duration: 900 },
                    plugins: {
                        legend: {
                            display: mixValues.length > 0,
                            position: 'right',
                            labels: { boxWidth: 10, font: { size: 11 } },
                        },
                    },
                },
            });
        }
    }

    if (app && app.classList.contains('is-first')) {
        window.setTimeout(hideBoot, 1400);
    } else {
        drawCharts();
    }
})();
