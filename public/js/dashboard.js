/**
 * dashboard.js
 * Renders the "Monthly Reservation Trend" card using Chart.js.
 * Loaded conditionally by templates/footer.php when $page === 'dashboard'.
 * Requires:
 *   - Chart.js (loaded in templates/header.php on the dashboard page)
 *   - window.t8TrendData = { labels: [...], data: [...] }
 *     (set inline by modules/dashboard/index.php from $trendCounts —
 *      see that file's <script> block right before this file loads)
 *
 * Falls back to a plain "no data" message if Chart.js or the data
 * global didn't load, instead of throwing.
 */

document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('t8TrendChart');
    if (!canvas) {
        return; // not on the dashboard page
    }

    var shell = canvas.closest('.t8-chart-shell');
    var trendData = window.t8TrendData || { labels: [], data: [] };

    if (typeof Chart === 'undefined' || !trendData.data || trendData.data.length === 0) {
        if (shell) {
            shell.innerHTML = '<div class="t8-empty">No trend data available.</div>';
        }
        return;
    }

    var ctx = canvas.getContext('2d');

    // ---- Soft gradient fill under the line ----
    function buildGradient(chartCtx, area) {
        var gradient = chartCtx.createLinearGradient(0, area.top, 0, area.bottom);
        gradient.addColorStop(0, 'rgba(178, 34, 34, 0.22)');
        gradient.addColorStop(0.6, 'rgba(178, 34, 34, 0.05)');
        gradient.addColorStop(1, 'rgba(178, 34, 34, 0)');
        return gradient;
    }

    // ---- Custom rounded/shadowed tooltip (external HTML tooltip) ----
    function externalTooltip(context) {
        var chart = context.chart;
        var tooltip = context.tooltip;
        var el = shell.querySelector('.t8-chart-tooltip');
        if (!el) {
            el = document.createElement('div');
            el.className = 't8-chart-tooltip';
            shell.appendChild(el);
        }

        if (tooltip.opacity === 0) {
            el.style.opacity = 0;
            return;
        }

        var dp = tooltip.dataPoints && tooltip.dataPoints[0];
        if (dp) {
            var val = dp.raw;
            el.innerHTML =
                '<div style="opacity:.65;font-weight:500;font-size:10.5px;margin-bottom:2px;">Day ' +
                dp.label + '</div>' + val + ' reservation' + (val === 1 ? '' : 's');
        }

        el.style.opacity = 1;
        el.style.left = tooltip.caretX + 'px';
        el.style.top = tooltip.caretY + 'px';
    }

    // ---- "Not much activity yet" hint when data is very sparse ----
    var sparseHintPlugin = {
        id: 't8SparseHint',
        afterDraw: function (chart) {
            var data = chart.data.datasets[0].data;
            var nonZero = data.filter(function (v) { return v > 0; }).length;
            if (nonZero > 1) {
                return;
            }
            var chartCtx = chart.ctx;
            var area = chart.chartArea;
            chartCtx.save();
            chartCtx.font = "500 11px 'Poppins', sans-serif";
            chartCtx.fillStyle = '#b8a9a9';
            chartCtx.textAlign = 'right';
            chartCtx.textBaseline = 'top';
            chartCtx.fillText('Not much activity yet', area.right, area.top - 2);
            chartCtx.restore();
        }
    };

    var maxValue = Math.max.apply(null, trendData.data);

    var chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: trendData.labels,
            datasets: [{
                data: trendData.data,
                borderColor: '#B22222',
                borderWidth: 2.5,
                pointRadius: function (context) {
                    var v = context.raw;
                    var isLast = context.dataIndex === context.dataset.data.length - 1;
                    return v > 0 ? (isLast ? 5 : 3.5) : 0;
                },
                pointHoverRadius: 6,
                pointBackgroundColor: '#ffffff',
                pointBorderColor: '#B22222',
                pointBorderWidth: 2,
                pointHoverBackgroundColor: '#B22222',
                pointHoverBorderColor: '#ffffff',
                pointHoverBorderWidth: 2,
                tension: 0.42,
                cubicInterpolationMode: 'monotone',
                fill: true,
                backgroundColor: function (context) {
                    var chartArea = context.chart.chartArea;
                    if (!chartArea) {
                        return null;
                    }
                    return buildGradient(context.chart.ctx, chartArea);
                }
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 900,
                easing: 'easeOutQuart'
            },
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { top: 14, right: 12, bottom: 0, left: 4 } },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: {
                        color: '#b8a9a9',
                        font: { family: 'Poppins', size: 10.5, weight: '500' },
                        maxTicksLimit: 6,
                        padding: 6
                    }
                },
                y: {
                    // Y-axis title intentionally removed — minimal ticks only.
                    title: { display: false },
                    beginAtZero: true,
                    suggestedMax: maxValue <= 1 ? 3 : undefined,
                    grid: {
                        color: 'rgba(36,17,17,0.045)',
                        drawTicks: false
                    },
                    border: { display: false },
                    ticks: {
                        color: '#c9bcbc',
                        font: { family: 'Poppins', size: 10.5, weight: '500' },
                        padding: 8,
                        maxTicksLimit: 4,
                        precision: 0
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: false,
                    external: externalTooltip
                }
            }
        },
        plugins: [sparseHintPlugin]
    });

    // Chart.js instances aren't auto-cleaned on SPA-style navigation in
    // this app (full page loads per request), so no explicit destroy()
    // is needed here — the canvas/context is thrown away on navigation.
    window.t8TrendChart = chart;
});
