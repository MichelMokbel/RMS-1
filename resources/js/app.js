import ApexCharts from 'apexcharts';

const dashboardChartRegistry = new Map();
let dashboardObserverInitialized = false;
let renderTimer = null;

function parseDashboardPayload(raw) {
    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        return null;
    }
}

function formatCurrency(value, currency, digits) {
    const n = Number.isFinite(value) ? value : 0;
    return `${n.toFixed(digits)} ${currency}`;
}

function destroyChart(chartKey) {
    const existing = dashboardChartRegistry.get(chartKey);
    if (existing) {
        existing.chart.destroy();
        dashboardChartRegistry.delete(chartKey);
    }
}

function createChart(chartKey, element, options, signature) {
    if (!element) {
        destroyChart(chartKey);
        return;
    }

    const existing = dashboardChartRegistry.get(chartKey);
    if (
        existing &&
        existing.signature === signature &&
        existing.element === element
    ) {
        return;
    }

    destroyChart(chartKey);

    const chart = new ApexCharts(element, options);
    chart.render();
    dashboardChartRegistry.set(chartKey, { chart, signature, element });
}

function trendOptions(payload) {
    const currency = payload.currency || 'QAR';
    const digits = Number.isInteger(payload.digits) ? payload.digits : 2;

    return {
        chart: {
            type: 'line',
            height: 340,
            parentHeightOffset: 0,
            toolbar: { show: false },
            animations: { speed: 350, easing: 'easeinout' },
            redrawOnParentResize: true,
            redrawOnWindowResize: true,
        },
        series: payload?.trend?.series || [],
        xaxis: {
            categories: payload?.trend?.categories || [],
            labels: {
                style: { colors: '#71717a', fontSize: '11px' },
            },
        },
        yaxis: {
            labels: {
                formatter: (value) => Number(value || 0).toFixed(digits),
                style: { colors: '#71717a', fontSize: '11px' },
            },
        },
        stroke: {
            curve: 'smooth',
            width: 3,
        },
        grid: {
            borderColor: '#e4e4e7',
        },
        colors: ['#2563eb', '#f97316'],
        dataLabels: { enabled: false },
        legend: {
            position: 'top',
            horizontalAlign: 'left',
            fontSize: '12px',
        },
        tooltip: {
            y: {
                formatter: (value) => formatCurrency(Number(value || 0), currency, digits),
            },
        },
    };
}

function donutOptions(payload, donutKey, colors) {
    const currency = payload.currency || 'QAR';
    const digits = Number.isInteger(payload.digits) ? payload.digits : 2;
    const donut = payload?.donuts?.[donutKey] || {};

    return {
        chart: {
            type: 'donut',
            height: 300,
            parentHeightOffset: 0,
            toolbar: { show: false },
            redrawOnParentResize: true,
            redrawOnWindowResize: true,
        },
        series: donut.series || [],
        labels: donut.labels || [],
        colors,
        dataLabels: { enabled: false },
        legend: {
            position: 'bottom',
            fontSize: '12px',
            formatter: (seriesName, opts) => {
                const value = Number(opts?.w?.globals?.series?.[opts?.seriesIndex] ?? 0);
                return `${seriesName} (${formatCurrency(value, currency, digits)})`;
            },
        },
        stroke: {
            colors: ['#ffffff'],
        },
        tooltip: {
            y: {
                formatter: (value) => formatCurrency(Number(value || 0), currency, digits),
            },
        },
        plotOptions: {
            pie: {
                customScale: 0.95,
                donut: {
                    size: '68%',
                    labels: {
                        show: true,
                        total: {
                            show: true,
                            label: 'Total',
                            formatter: (w) => {
                                const total = (w?.globals?.seriesTotals || []).reduce((sum, n) => sum + Number(n || 0), 0);
                                return formatCurrency(total, currency, digits);
                            },
                        },
                    },
                },
            },
        },
        noData: {
            text: 'No data in range',
            align: 'center',
            verticalAlign: 'middle',
            style: { fontSize: '12px' },
        },
    };
}

function ensureRootUid(root) {
    if (!root.dataset.dashboardChartUid) {
        root.dataset.dashboardChartUid = `dashboard-${Math.random().toString(36).slice(2, 12)}`;
    }

    return root.dataset.dashboardChartUid;
}

function renderDashboardRoot(root) {
    const payload = parseDashboardPayload(root.dataset.dashboardCharts);
    if (!payload) {
        return new Set();
    }

    const uid = ensureRootUid(root);
    const activeKeys = new Set();

    const trendEl = root.querySelector('[data-chart-target="trend"]');
    const receivablesEl = root.querySelector('[data-chart-target="receivables"]');
    const payablesEl = root.querySelector('[data-chart-target="payables"]');
    const invoiceStatusEl = root.querySelector('[data-chart-target="invoice-status"]');

    const trendKey = `${uid}:trend`;
    const receivablesKey = `${uid}:receivables`;
    const payablesKey = `${uid}:payables`;
    const statusKey = `${uid}:invoice-status`;
    const payloadSignature = root.dataset.dashboardCharts || '';

    createChart(trendKey, trendEl, trendOptions(payload), `${payloadSignature}:trend`);
    createChart(
        receivablesKey,
        receivablesEl,
        donutOptions(payload, 'receivables', ['#22c55e', '#ef4444']),
        `${payloadSignature}:receivables`
    );
    createChart(
        payablesKey,
        payablesEl,
        donutOptions(payload, 'payables', ['#0ea5e9', '#f97316']),
        `${payloadSignature}:payables`
    );
    createChart(
        statusKey,
        invoiceStatusEl,
        donutOptions(payload, 'invoiceStatusMix', ['#16a34a', '#f59e0b', '#6366f1']),
        `${payloadSignature}:invoice-status`
    );

    activeKeys.add(trendKey);
    activeKeys.add(receivablesKey);
    activeKeys.add(payablesKey);
    activeKeys.add(statusKey);

    return activeKeys;
}

function renderDashboardCharts() {
    const roots = document.querySelectorAll('[data-dashboard-charts]');
    const activeKeys = new Set();

    roots.forEach((root) => {
        const rootKeys = renderDashboardRoot(root);
        rootKeys.forEach((key) => activeKeys.add(key));
    });

    for (const [chartKey] of dashboardChartRegistry.entries()) {
        if (!activeKeys.has(chartKey)) {
            destroyChart(chartKey);
        }
    }
}

function scheduleRenderDashboardCharts() {
    if (renderTimer) {
        clearTimeout(renderTimer);
    }

    renderTimer = setTimeout(() => {
        renderDashboardCharts();
    }, 40);
}

function initDashboardObserver() {
    if (dashboardObserverInitialized) {
        return;
    }

    const observer = new MutationObserver((mutations) => {
        const hasRelevantChanges = mutations.some((mutation) => {
            if (mutation.type === 'attributes') {
                return mutation.attributeName === 'data-dashboard-charts';
            }

            if (mutation.type !== 'childList') {
                return false;
            }

            const nodes = [...mutation.addedNodes, ...mutation.removedNodes];
            return nodes.some((node) => {
                if (!(node instanceof HTMLElement)) {
                    return false;
                }

                return (
                    node.hasAttribute('data-dashboard-charts') ||
                    !!node.querySelector?.('[data-dashboard-charts]')
                );
            });
        });

        if (hasRelevantChanges) {
            scheduleRenderDashboardCharts();
        }
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['data-dashboard-charts'],
    });

    dashboardObserverInitialized = true;
}

function initDashboardCharts() {
    scheduleRenderDashboardCharts();
    initDashboardObserver();
}

if (document.readyState !== 'loading') {
    initDashboardCharts();
} else {
    document.addEventListener('DOMContentLoaded', initDashboardCharts);
}

document.addEventListener('livewire:init', scheduleRenderDashboardCharts);
document.addEventListener('livewire:navigated', scheduleRenderDashboardCharts);
