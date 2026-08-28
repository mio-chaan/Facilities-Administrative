/**
 * dashboard.js
 * Renders the "Reservation Trend" card using Chart.js.
 * Loaded conditionally by templates/footer.php when $page === 'dashboard'.
 * Requires:
 *   - Chart.js (loaded in templates/header.php on the dashboard page)
 *   - window.t8TrendData = { labels: [...], data: [...] }
 *     (set inline by modules/dashboard/index.php from $trendCounts —
 *      see that file's <script> block right before this file loads)
 *
 * Falls back to a plain "no data" message if Chart.js or the data
 * global didn't load, instead of throwing.
 *
 * DASHBOARD UPDATE: a second, independent block below adds the
 * Recent Activities meatballs menu (#t8ActivityMenuBtn) and the
 * Activity History modal (#t8ActivityHistoryModal) — search box,
 * activity-type filter, and a "Load more" reveal over the
 * server-rendered (already capped) history rows. None of this touches
 * the Chart.js block above it.
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
            if (nonZero > 0) {
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

    window.t8TrendChart = chart;
});

document.addEventListener('DOMContentLoaded', function () {
    var menuBtn = document.getElementById('t8ActivityMenuBtn');
    var menuDropdown = document.getElementById('t8ActivityMenuDropdown');
    var viewHistoryBtn = document.getElementById('t8ViewActivityHistory');
    var modal = document.getElementById('t8ActivityHistoryModal');
    var closeBtn = document.getElementById('t8ActivityHistoryClose');
    var searchInput = document.getElementById('t8ActivitySearch');
    var typeFilter = document.getElementById('t8ActivityTypeFilter');
    var loadMoreBtn = document.getElementById('t8ActivityLoadMore');

    if (!menuBtn || !menuDropdown) {
        return; // not on the dashboard page
    }

    menuBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var isOpen = menuDropdown.classList.toggle('t8-open');
        menuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
        if (!menuDropdown.contains(e.target) && e.target !== menuBtn) {
            menuDropdown.classList.remove('t8-open');
            menuBtn.setAttribute('aria-expanded', 'false');
        }
    });

    if (viewHistoryBtn && modal) {
        viewHistoryBtn.addEventListener('click', function () {
            menuDropdown.classList.remove('t8-open');
            modal.showModal();
        });
    }

    if (closeBtn && modal) {
        closeBtn.addEventListener('click', function () {
            modal.close();
        });
    }

    if (!modal) {
        return;
    }

    function applyActivityFilters() {
        var query = (searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
        var type = typeFilter ? typeFilter.value : '';
        var rows = modal.querySelectorAll('[data-activity-row]');

        rows.forEach(function (row) {
            var matchesQuery = !query || (row.getAttribute('data-activity-search') || '').indexOf(query) !== -1;
            var matchesType = !type || row.getAttribute('data-activity-action') === type;
            // Filtering overrides the "load more" hidden state — a
            // matching row is shown regardless of how many rows are
            // currently revealed.
            row.hidden = !(matchesQuery && matchesType);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyActivityFilters);
    }
    if (typeFilter) {
        typeFilter.addEventListener('change', applyActivityFilters);
    }

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function () {
            var query = (searchInput && searchInput.value ? searchInput.value : '').trim();
            var type = typeFilter ? typeFilter.value : '';
            // Only auto-reveal more rows when no filter is active —
            // otherwise "load more" would show rows that don't match.
            if (query || type) {
                return;
            }
            var hiddenRows = modal.querySelectorAll('[data-activity-row][hidden]');
            for (var i = 0; i < Math.min(20, hiddenRows.length); i++) {
                hiddenRows[i].hidden = false;
            }
            if (modal.querySelectorAll('[data-activity-row][hidden]').length === 0) {
                loadMoreBtn.style.display = 'none';
            }
        });
    }
});
