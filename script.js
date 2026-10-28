/* ============================================================
   Cartera Pro GLM5 — script.js v3
   Cambios v3:
   - Modal de análisis corregido (sección "Tu Posición" poblada sin errores)
   - Sin API key en el cliente: actualización same-origin validada en servidor
   - Actualización de KPIs en caliente (sin location.reload())
   - Gráfico Base 100: Portfolio vs IBEX 35
   - Exportación CSV corregida (lee atributos data-* del botón)
   ============================================================ */

'use strict';

/* ---------- Utilidades ---------- */
const fmtEUR = (n, dec = 0) =>
    (n === null || n === undefined || isNaN(n)) ? '—' :
    Number(n).toLocaleString('es-ES', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + ' €';

const fmtNum = (n, dec = 2) =>
    (n === null || n === undefined || isNaN(n)) ? '—' :
    Number(n).toLocaleString('es-ES', { minimumFractionDigits: dec, maximumFractionDigits: dec });

const fmtPct = (n, dec = 2) =>
    (n === null || n === undefined || isNaN(n)) ? '—' :
    (n >= 0 ? '+' : '') + Number(n).toLocaleString('es-ES', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '%';

// Escapa HTML antes de interpolar texto en innerHTML. Imprescindible para
// datos del DOM (p. ej. data-name): el navegador decodifica entidades al
// leer el atributo, así que reinyectarlo sin escapar reintroduciría HTML
// crudo (inyección de XSS almacenado).
const esc = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (m) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

const fmtCompact = (n) => {
    if (n === null || n === undefined || isNaN(n) || n === 0) return '—';
    if (n >= 1e12) return (n / 1e12).toFixed(2) + ' B';
    if (n >= 1e9) return (n / 1e9).toFixed(2) + ' mil M';
    if (n >= 1e6) return (n / 1e6).toFixed(1) + ' M';
    return Number(n).toLocaleString('es-ES');
};

const $ = (id) => document.getElementById(id);

function isDarkTheme() {
    return document.documentElement.getAttribute('data-theme') !== 'light';
}

function chartColors() {
    const dark = isDarkTheme();
    return {
        grid: dark ? 'rgba(148,163,184,0.08)' : 'rgba(15,23,42,0.08)',
        tick: dark ? '#94a3b8' : '#475569',
        line: '#6366f1',
        ibex: '#f59e0b'
    };
}

/* ---------- Tema claro/oscuro ---------- */
function toggleTheme() {
    const html = document.documentElement;
    const next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    if (next === 'dark') html.removeAttribute('data-theme');
    else html.setAttribute('data-theme', 'light');
    try { localStorage.setItem('glm5_theme', next); } catch (e) {}
    const btn = $('theme-toggle-btn');
    if (btn) btn.textContent = next === 'light' ? '☀️' : '🌙';
    rebuildCharts();
}

(function initTheme() {
    try {
        const saved = localStorage.getItem('glm5_theme');
        if (saved === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            document.addEventListener('DOMContentLoaded', () => {
                const b = $('theme-toggle-btn');
                if (b) b.textContent = '☀️';
            });
        }
    } catch (e) {}
})();

/* ---------- Estado global ---------- */
let portfolioChart = null;
let sectorChart = null;
let historyChart = null;
let currentDays = 180;
let chartMode = 'eur'; // 'eur' | 'base100'
let currentStockHistory = [];
let autoRefreshTimer = null;
let autoRefreshCountdown = 120;
let updateInProgress = false;

/* ============================================================
   GRÁFICOS PRINCIPALES & HELPERS
   ============================================================ */
function parseJSONSafe(val) {
    if (!val) return [];
    if (Array.isArray(val)) return val;
    if (typeof val === 'object') return [val];
    if (typeof val === 'string') {
        try {
            let p = JSON.parse(val);
            if (typeof p === 'string') p = JSON.parse(p);
            return Array.isArray(p) ? p : [];
        } catch (e) {
            try {
                let unesc = val.replace(/\\"/g, '"').replace(/\\\\/g, '\\');
                let p = JSON.parse(unesc);
                if (typeof p === 'string') p = JSON.parse(p);
                return Array.isArray(p) ? p : [];
            } catch (e2) {
                return [];
            }
        }
    }
    return [];
}

function normalizeBase100(series) {
    if (!series || !series.length) return [];
    const base = series[0].y;
    if (!base) return series.map(p => ({ x: p.x, y: null }));
    return series.map(p => ({ x: p.x, y: (p.y / base) * 100 }));
}

function sliceSeries(series, days) {
    if (!series || !series.length) return [];
    // Normalizar timestamps a milisegundos para Chart.js
    const normalized = series.map(p => {
        let xVal = p.x;
        if (typeof xVal === 'string') {
            xVal = new Date(xVal).getTime();
        } else if (typeof xVal === 'number' && xVal < 1e11) {
            xVal = xVal * 1000;
        }
        return { ...p, x: xVal, y: parseFloat(p.y) };
    }).filter(p => !isNaN(p.x) && !isNaN(p.y));

    if (!normalized.length) return [];
    if (!days || days <= 0) return normalized;
    const lastX = normalized[normalized.length - 1].x;
    const cutoff = lastX - (days * 86400000);
    const out = normalized.filter(p => p.x >= cutoff);
    return out.length >= 2 ? out : normalized;
}

function getPortfolioHistory() {
    if (typeof PORTFOLIO_HISTORY !== 'undefined' && Array.isArray(PORTFOLIO_HISTORY) && PORTFOLIO_HISTORY.length >= 2) {
        return PORTFOLIO_HISTORY;
    }
    // Fallback: calcular en cliente agregando ALL_STOCKS_HISTORY y acciones de cada valor
    if (typeof ALL_STOCKS_HISTORY !== 'undefined') {
        const dailyMap = {};
        document.querySelectorAll('#stock-table tbody tr').forEach(tr => {
            const btn = tr.querySelector('button[data-ticker]');
            if (!btn) return;
            const ticker = btn.dataset.ticker;
            const shares = parseFloat(btn.dataset.shares || 0);
            if (shares <= 0) return;
            const hist = ALL_STOCKS_HISTORY[ticker] || [];
            hist.forEach(pt => {
                let ts = isNaN(pt.x) ? new Date(pt.x).getTime() : (pt.x < 1e11 ? pt.x * 1000 : pt.x);
                let dayKey = new Date(ts).toISOString().slice(0, 10);
                let price = parseFloat(pt.y || 0);
                if (price > 0) {
                    if (!dailyMap[dayKey]) dailyMap[dayKey] = { x: ts, y: 0, count: 0 };
                    dailyMap[dayKey].y += (price * shares);
                    dailyMap[dayKey].count++;
                }
            });
        });
        const sortedDays = Object.keys(dailyMap).sort();
        const clientPort = [];
        sortedDays.forEach(d => {
            if (dailyMap[d].count >= 3) {
                clientPort.push({ x: dailyMap[d].x, y: Math.round(dailyMap[d].y), date: d });
            }
        });
        if (clientPort.length >= 2) {
            window.PORTFOLIO_HISTORY = clientPort;
            return clientPort;
        }
    }
    return [];
}

function getIbexHistory() {
    if (typeof IBEX_HISTORY !== 'undefined' && Array.isArray(IBEX_HISTORY) && IBEX_HISTORY.length >= 2) {
        return IBEX_HISTORY;
    }
    if (typeof ALL_STOCKS_HISTORY !== 'undefined' && ALL_STOCKS_HISTORY['^IBEX'] && ALL_STOCKS_HISTORY['^IBEX'].length >= 2) {
        window.IBEX_HISTORY = ALL_STOCKS_HISTORY['^IBEX'];
        return window.IBEX_HISTORY;
    }
    return [];
}

function buildPortfolioChart() {
    const canvas = $('portfolioChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const c = chartColors();
    const ctx = canvas.getContext('2d');

    const portRaw = getPortfolioHistory();
    const port = sliceSeries(portRaw, currentDays);

    if (portfolioChart && typeof portfolioChart.destroy === 'function') {
        portfolioChart.destroy();
        portfolioChart = null;
    }

    const hasAdapter = !!(Chart._adapters && Chart._adapters._date);
    const isDark = isDarkTheme();
    let chartConfig;

    const ibexRaw = getIbexHistory();
    if (chartMode === 'base100' && ibexRaw && ibexRaw.length) {
        // Alinear ambas series por fecha común
        const ibexSlice = sliceSeries(ibexRaw, currentDays);
        const ibexMap = {};
        ibexSlice.forEach(p => { ibexMap[new Date(p.x).toISOString().slice(0, 10)] = p.y; });

        const common = port.filter(p => ibexMap[new Date(p.x).toISOString().slice(0, 10)] !== undefined);
        const labels = common.map(p => new Date(p.x).toISOString().slice(0, 10));
        const ibexAligned = labels.map(d => ibexMap[d]);

        const portN = normalizeBase100(common.map(p => ({ x: p.x, y: p.y })));
        const ibexN = normalizeBase100(ibexAligned.map((y, i) => ({ x: common[i].x, y })));

        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        color: c.tick,
                        boxWidth: 12,
                        boxHeight: 12,
                        borderRadius: 3,
                        useBorderRadius: true,
                        font: { size: 11, weight: '500' },
                        padding: 12
                    }
                },
                tooltip: {
                    backgroundColor: isDark ? 'rgba(15, 23, 42, 0.94)' : 'rgba(255, 255, 255, 0.95)',
                    titleColor: isDark ? '#f8fafc' : '#0f172a',
                    bodyColor: isDark ? '#cbd5e1' : '#334155',
                    borderColor: isDark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.08)',
                    borderWidth: 1,
                    padding: 12,
                    boxPadding: 6,
                    usePointStyle: true,
                    cornerRadius: 8,
                    callbacks: {
                        label: (item) => ` ${item.dataset.label}: ${fmtNum(item.parsed.y, 2)}`
                    }
                }
            },
            scales: {
                x: hasAdapter ? {
                    type: 'time',
                    time: { tooltipFormat: 'dd/MM/yyyy' },
                    grid: { color: c.grid, drawBorder: false },
                    ticks: { color: c.tick, maxTicksLimit: 8 }
                } : {
                    grid: { color: c.grid, drawBorder: false },
                    ticks: { color: c.tick, maxTicksLimit: 8 }
                },
                y: {
                    grid: { color: c.grid, drawBorder: false },
                    ticks: {
                        color: c.tick,
                        callback: (v) => fmtNum(v, 1)
                    }
                }
            }
        };

        if (hasAdapter) {
            chartConfig = {
                type: 'line',
                data: {
                    datasets: [
                        {
                            label: 'Portfolio (base 100)',
                            data: portN.map(p => ({ x: p.x, y: p.y })),
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,0.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#ffffff',
                            pointHoverBorderColor: '#6366f1',
                            pointHoverBorderWidth: 3,
                            borderWidth: 2.5
                        },
                        {
                            label: 'IBEX 35 (base 100)',
                            data: ibexN.map(p => ({ x: p.x, y: p.y })),
                            borderColor: '#f59e0b',
                            borderDash: [5, 4],
                            fill: false,
                            tension: 0.35,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            pointHoverBackgroundColor: '#ffffff',
                            pointHoverBorderColor: '#f59e0b',
                            pointHoverBorderWidth: 2,
                            borderWidth: 2
                        }
                    ]
                },
                options: commonOptions
            };
        } else {
            const catLabels = common.map(p => new Date(p.x).toLocaleDateString('es-ES', { day: '2-digit', month: 'short' }));
            chartConfig = {
                type: 'line',
                data: {
                    labels: catLabels,
                    datasets: [
                        {
                            label: 'Portfolio (base 100)',
                            data: portN.map(p => p.y),
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99,102,241,0.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 0,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#ffffff',
                            pointHoverBorderColor: '#6366f1',
                            pointHoverBorderWidth: 3,
                            borderWidth: 2.5
                        },
                        {
                            label: 'IBEX 35 (base 100)',
                            data: ibexN.map(p => p.y),
                            borderColor: '#f59e0b',
                            borderDash: [5, 4],
                            fill: false,
                            tension: 0.35,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            pointHoverBackgroundColor: '#ffffff',
                            pointHoverBorderColor: '#f59e0b',
                            pointHoverBorderWidth: 2,
                            borderWidth: 2
                        }
                    ]
                },
                options: commonOptions
            };
        }
    } else {
        const grad = ctx.createLinearGradient(0, 0, 0, canvas.parentElement.clientHeight || 320);
        grad.addColorStop(0, 'rgba(99,102,241,0.40)');
        grad.addColorStop(0.5, 'rgba(99,102,241,0.12)');
        grad.addColorStop(1, 'rgba(99,102,241,0.00)');

        const datasetDef = {
            label: 'Valor del portfolio',
            borderColor: '#6366f1',
            backgroundColor: grad,
            fill: true,
            tension: 0.35,
            pointRadius: 0,
            pointHoverRadius: 6,
            pointHoverBackgroundColor: '#ffffff',
            pointHoverBorderColor: '#6366f1',
            pointHoverBorderWidth: 3,
            borderWidth: 2.5
        };

        const eurOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: isDark ? 'rgba(15, 23, 42, 0.94)' : 'rgba(255, 255, 255, 0.95)',
                    titleColor: isDark ? '#f8fafc' : '#0f172a',
                    bodyColor: isDark ? '#cbd5e1' : '#334155',
                    borderColor: isDark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.08)',
                    borderWidth: 1,
                    padding: 12,
                    boxPadding: 6,
                    usePointStyle: true,
                    cornerRadius: 8,
                    callbacks: {
                        label: (item) => ` Valor: ${fmtEUR(item.parsed.y, 2)}`
                    }
                }
            },
            scales: {
                x: hasAdapter ? {
                    type: 'time',
                    time: { tooltipFormat: 'dd/MM/yyyy' },
                    grid: { color: c.grid, drawBorder: false },
                    ticks: { color: c.tick, maxTicksLimit: 8 }
                } : {
                    grid: { color: c.grid, drawBorder: false },
                    ticks: { color: c.tick, maxTicksLimit: 8 }
                },
                y: {
                    grid: { color: c.grid, drawBorder: false },
                    ticks: {
                        color: c.tick,
                        callback: (v) => (v >= 1000 ? (v / 1000).toFixed(0) + 'k €' : v + ' €')
                    }
                }
            }
        };

        if (hasAdapter) {
            chartConfig = {
                type: 'line',
                data: {
                    datasets: [{
                        ...datasetDef,
                        data: port
                    }]
                },
                options: eurOptions
            };
        } else {
            const catLabels = port.map(p => new Date(p.x).toLocaleDateString('es-ES', { day: '2-digit', month: 'short' }));
            chartConfig = {
                type: 'line',
                data: {
                    labels: catLabels,
                    datasets: [{
                        ...datasetDef,
                        data: port.map(p => p.y)
                    }]
                },
                options: eurOptions
            };
        }
    }

    portfolioChart = new Chart(ctx, chartConfig);
}

function buildSectorChart() {
    const canvas = $('sectorChart');
    if (!canvas || typeof Chart === 'undefined' || typeof SECTOR_DATA === 'undefined') return;
    const c = chartColors();
    const total = SECTOR_DATA.reduce((s, x) => s + x.value, 0);

    if (sectorChart && typeof sectorChart.destroy === 'function') {
        sectorChart.destroy();
        sectorChart = null;
    }

    const isDark = isDarkTheme();
    const modernColors = {
        'Energía': '#10b981',
        'Banca': '#3b82f6',
        'Telecomunicaciones': '#8b5cf6',
        'Petróleo': '#f59e0b',
        'Media': '#ec4899',
        'Tecnología': '#06b6d4',
        'Construcción': '#f97316',
        'Otro': '#64748b'
    };

    const colors = SECTOR_DATA.map(s => modernColors[s.name] || s.color || '#6366f1');

    sectorChart = new Chart(canvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: SECTOR_DATA.map(s => s.name),
            datasets: [{
                data: SECTOR_DATA.map(s => s.value),
                backgroundColor: colors,
                borderWidth: 3,
                borderColor: isDark ? '#0f172a' : '#ffffff',
                borderRadius: 6,
                spacing: 4,
                hoverOffset: 8,
                hoverBorderColor: isDark ? '#1e293b' : '#f1f5f9'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '72%',
            animation: {
                animateScale: true,
                animateRotate: true,
                duration: 900,
                easing: 'easeOutQuart'
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: c.tick,
                        boxWidth: 10,
                        boxHeight: 10,
                        borderRadius: 3,
                        useBorderRadius: true,
                        font: { size: 11, weight: '500' },
                        padding: 12,
                        generateLabels: (chart) => {
                            const data = chart.data;
                            if (data.labels.length && data.datasets.length) {
                                return data.labels.map((label, i) => {
                                    const val = data.datasets[0].data[i];
                                    const pct = total > 0 ? (val / total * 100).toFixed(1) : 0;
                                    return {
                                        text: `${label} (${pct}%)`,
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        strokeStyle: 'transparent',
                                        lineWidth: 0,
                                        hidden: false,
                                        index: i
                                    };
                                });
                            }
                            return [];
                        }
                    }
                },
                tooltip: {
                    backgroundColor: isDark ? 'rgba(15, 23, 42, 0.94)' : 'rgba(255, 255, 255, 0.95)',
                    titleColor: isDark ? '#f8fafc' : '#0f172a',
                    bodyColor: isDark ? '#cbd5e1' : '#334155',
                    borderColor: isDark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(0, 0, 0, 0.08)',
                    borderWidth: 1,
                    padding: 12,
                    boxPadding: 6,
                    usePointStyle: true,
                    cornerRadius: 8,
                    callbacks: {
                        label: (item) => {
                            const pct = total > 0 ? (item.parsed / total * 100) : 0;
                            return ` ${item.label}: ${fmtEUR(item.parsed, 0)} (${pct.toFixed(1)}%)`;
                        }
                    }
                }
            }
        }
    });
}

function rebuildCharts() {
    buildPortfolioChart();
    buildSectorChart();
    if (historyChart && currentStockHistory.length) buildHistoryChart(currentStockHistory, currentModalDays);
}

function initScrollReveals() {
    const reveals = document.querySelectorAll('.fade-in');
    reveals.forEach(el => el.classList.add('is-visible'));
}

/* ---------- Controles de periodo / modo ---------- */
document.addEventListener('DOMContentLoaded', () => {
    initScrollReveals();
    buildPortfolioChart();
    buildSectorChart();
    initSorting();
    startAutoRefresh();

    // Conectar inmediatamente en primer acceso para comprobar datos en tiempo real
    actualizarMercado(true);

    const periods = $('portfolio-periods');
    if (periods) periods.addEventListener('click', (e) => {
        const btn = e.target.closest('.period-btn');
        if (!btn) return;
        periods.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentDays = parseInt(btn.dataset.days, 10);
        buildPortfolioChart();
    });

    const modes = $('portfolio-mode');
    if (modes) modes.addEventListener('click', (e) => {
        const btn = e.target.closest('.period-btn');
        if (!btn) return;
        modes.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        chartMode = btn.dataset.mode;
        buildPortfolioChart();
    });
});

/* ============================================================
   ORDENACIÓN DE TABLA
   ============================================================ */
let sortKey = 'value', sortDir = -1;

function getRowValue(row, key) {
    const btn = row.querySelector('button[data-ticker]');
    const cells = row.querySelectorAll('td');
    switch (key) {
        case 'name': return btn ? btn.dataset.name.toLowerCase() : '';
        case 'shares': return parseFloat(btn?.dataset.shares || 0);
        case 'price': return parseFloat(btn?.dataset.price || 0);
        case 'value': return parseFloat(btn?.dataset.price || 0) * parseFloat(btn?.dataset.shares || 0);
        case 'weight': return parseFloat(btn?.dataset.peso || 0);
        case 'day': {
            const p = parseFloat(btn?.dataset.price || 0), prev = parseFloat(btn?.dataset.prev || 0);
            return prev > 0 ? (p - prev) / prev : 0;
        }
        case 'ytd': {
            const p = parseFloat(btn?.dataset.price || 0), ref = parseFloat(btn?.dataset.ref || 0);
            return ref > 0 ? (p - ref) / ref : 0;
        }
        case 'pnl': {
            const p = parseFloat(btn?.dataset.price || 0), cost = parseFloat(btn?.dataset.cost || 0);
            return cost > 0 ? (p - cost) / cost : -Infinity;
        }
        case 'signal': return parseFloat(btn?.dataset.techscore || 0);
        default: return 0;
    }
}

function initSorting() {
    const table = $('stock-table');
    if (!table) return;
    table.querySelectorAll('th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const key = th.dataset.sort;
            if (sortKey === key) sortDir *= -1;
            else { sortKey = key; sortDir = -1; }
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const va = getRowValue(a, key), vb = getRowValue(b, key);
                if (typeof va === 'string') return va.localeCompare(vb) * sortDir;
                return (va - vb) * sortDir;
            });
            rows.forEach(r => tbody.appendChild(r));
            table.querySelectorAll('.sort-icon').forEach(i => i.textContent = '↕');
            const icon = th.querySelector('.sort-icon');
            if (icon) icon.textContent = sortDir === -1 ? '↓' : '↑';
        });
    });
}

/* ============================================================
   EXPORTAR CSV (corregido: lee data-* del botón, no de la celda)
   ============================================================ */
function exportarCSV() {
    const rows = [['Ticker', 'Nombre', 'Acciones', 'Coste Medio', 'Precio', 'Valor (EUR)', 'Peso (%)', 'Dia (%)', 'YTD (%)', 'P&L Real (%)', 'Senal', 'RSI']];
    document.querySelectorAll('#stock-table tbody tr').forEach(tr => {
        const b = tr.querySelector('button[data-ticker]');
        if (!b) return;
        const price = parseFloat(b.dataset.price || 0);
        const shares = parseFloat(b.dataset.shares || 0);
        const prev = parseFloat(b.dataset.prev || 0);
        const ref = parseFloat(b.dataset.ref || 0);
        const cost = parseFloat(b.dataset.cost || 0);
        const dia = prev > 0 ? ((price - prev) / prev * 100).toFixed(2) : '';
        const ytd = ref > 0 ? ((price - ref) / ref * 100).toFixed(2) : '';
        const pnl = cost > 0 ? ((price - cost) / cost * 100).toFixed(2) : '';
        rows.push([
            b.dataset.ticker, b.dataset.name, shares, cost || '', price,
            (price * shares).toFixed(2), b.dataset.peso, dia, ytd, pnl,
            b.dataset.techsignal || '', b.dataset.rsi || ''
        ]);
    });
    const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(';')).join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'cartera_glm5_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
}

/* ============================================================
   MODAL DE ANÁLISIS (v3 — corregido)
   ============================================================ */
let currentModalDays = 180;

function handleAnalysis(btn) {
    if (!btn) return;
    try {
        const d = btn.dataset;
        const price = parseFloat(d.price || 0);
        const prev = parseFloat(d.prev || 0);
        const ref = parseFloat(d.ref || 0);
        const shares = parseFloat(d.shares || 0);
        const cost = parseFloat(d.cost || 0);
        const peso = parseFloat(d.peso || 0);
        const divAnual = parseFloat(d.divAnual || 0);
        const chg = prev > 0 ? (price - prev) / prev * 100 : 0;
        const ytd = ref > 0 ? (price - ref) / ref * 100 : 0;

        const safeSet = (id, txt, cls) => {
            const el = $(id);
            if (!el) return;
            if (txt !== undefined && txt !== null) el.textContent = txt;
            if (cls !== undefined) el.className = cls;
        };

        const tickerStr = (d.ticker || '').toUpperCase();
        safeSet('modal-ticker', tickerStr);
        safeSet('modal-name', d.name || tickerStr);
        safeSet('modal-sector', d.sector || '');
        safeSet('modal-price', fmtEUR(price, 3));

        const chgEl = $('modal-change');
        if (chgEl) {
            chgEl.textContent = (chg >= 0 ? '▲ ' : '▼ ') + fmtPct(chg);
            chgEl.className = 'badge ' + (chg >= 0 ? 'bg-success' : 'bg-danger');
        }

        /* --- Tu Posición --- */
        safeSet('m-shares', shares ? shares.toLocaleString('es-ES') : '—', 'pv');
        safeSet('m-current', fmtEUR(price * shares, 0), 'pv');
        safeSet('m-weight', peso ? fmtNum(peso, 1) + '%' : '—', 'pv');
        safeSet('m-cost', cost > 0 ? fmtEUR(cost, 3) : 'Sin configurar', 'pv');
        if (cost > 0) {
            const pnl = (price - cost) * shares;
            const pnlPct = (price - cost) / cost * 100;
            safeSet('m-pnl', (pnl >= 0 ? '+' : '') + fmtEUR(pnl, 0) + ' (' + fmtPct(pnlPct, 1) + ')',
                'pv ' + (pnl >= 0 ? 'text-success' : 'text-danger'));
        } else {
            safeSet('m-pnl', '—', 'pv');
        }
        safeSet('m-divy', divAnual > 0 ? '~' + fmtEUR(divAnual, 0) : '—', 'pv');

        /* --- Fundamentales --- */
        safeSet('m-per', d.pe && parseFloat(d.pe) > 0 ? fmtNum(parseFloat(d.pe), 1) : '—');
        safeSet('m-eps', d.eps ? fmtNum(parseFloat(d.eps), 2) + ' €' : '—');
        safeSet('m-target', d.target && parseFloat(d.target) > 0 ? fmtEUR(parseFloat(d.target), 2) : '—');
        safeSet('m-cap', fmtCompact(parseFloat(d.cap || 0)));
        safeSet('m-div', d.div && parseFloat(d.div) > 0 ? fmtNum(parseFloat(d.div), 2) + '%' : '—');
        safeSet('m-beta', d.beta ? fmtNum(parseFloat(d.beta), 2) : '—');
        safeSet('m-pb', d.pb && parseFloat(d.pb) > 0 ? fmtNum(parseFloat(d.pb), 2) : '—');
        safeSet('m-vol', fmtCompact(parseFloat(d.vol || 0)));

        /* --- Técnicos --- */
        const rsi = parseFloat(d.rsi || NaN);
        const sma20 = parseFloat(d.sma20 || NaN);
        const sma50 = parseFloat(d.sma50 || NaN);
        const rsiEl = $('m-rsi');
        if (rsiEl) {
            if (!isNaN(rsi)) {
                rsiEl.textContent = rsi.toFixed(1) + (rsi > 70 ? ' 🔴 Sobrecompra' : rsi < 30 ? ' 🟢 Sobreventa' : '');
                rsiEl.className = rsi > 70 ? 'text-danger' : (rsi < 30 ? 'text-success' : '');
            } else {
                rsiEl.textContent = '—';
                rsiEl.className = '';
            }
        }
        safeSet('m-sma20', !isNaN(sma20) ? fmtEUR(sma20, 3) : '—');
        safeSet('m-sma50', !isNaN(sma50) ? fmtEUR(sma50, 3) : '—');

        // MACD y Bollinger calculados del historial
        let hist = [];
        if (typeof ALL_STOCKS_HISTORY !== 'undefined' && Array.isArray(ALL_STOCKS_HISTORY[d.ticker]) && ALL_STOCKS_HISTORY[d.ticker].length) {
            hist = ALL_STOCKS_HISTORY[d.ticker];
        } else if (typeof ALL_STOCKS_HISTORY !== 'undefined' && d.tickerYahoo && Array.isArray(ALL_STOCKS_HISTORY[d.tickerYahoo]) && ALL_STOCKS_HISTORY[d.tickerYahoo].length) {
            hist = ALL_STOCKS_HISTORY[d.tickerYahoo];
        } else if (d.history) {
            hist = parseJSONSafe(d.history);
        }

        const macdInfo = calcMACD(hist);
        const bollInfo = calcBollinger(hist);
        safeSet('m-macd', macdInfo || '—');
        safeSet('m-bollinger', bollInfo || '—');

        /* --- Rango 52 semanas --- */
        const high52 = parseFloat(d.high52 || 0), low52 = parseFloat(d.low52 || 0);
        const rangeCard = $('range-card');
        if (rangeCard) {
            if (high52 > 0 && low52 > 0 && high52 > low52) {
                rangeCard.style.display = 'block';
                const pos = Math.min(100, Math.max(0, (price - low52) / (high52 - low52) * 100));
                safeSet('range-low', fmtEUR(low52, 2));
                safeSet('range-high', fmtEUR(high52, 2));
                safeSet('range-position', pos.toFixed(0) + '% del rango');
                const marker = $('range-marker');
                if (marker) marker.style.left = pos + '%';
                const fill = $('range-fill');
                if (fill) fill.style.width = pos + '%';
            } else {
                rangeCard.style.display = 'none';
            }
        }

        /* --- Diagnóstico --- */
        renderDiagnosis({
            price, prev, ref, rsi, sma20, sma50,
            target: parseFloat(d.target || 0),
            techScore: parseInt(d.techscore || 0, 10),
            techSignal: d.techsignal,
            name: d.name || tickerStr,
            cost, ytd
        });

        /* --- Historial del gráfico --- */
        currentStockHistory = hist;
        currentModalDays = 180;
        document.querySelectorAll('#modal-periods .period-btn').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.days, 10) === 180);
        });
        buildHistoryChart(hist, 180);

        const m = $('analysisModal');
        if (m) {
            m.classList.add('open');
            m.style.display = 'block';
        }
        document.body.style.overflow = 'hidden';
    } catch (err) {
        console.error("Error al abrir modal de análisis:", err);
    }
}

function closeModal() {
    const m = $('analysisModal');
    if (m) {
        m.classList.remove('open');
        m.style.display = 'none';
    }
    document.body.style.overflow = '';
}

function filterChart(days) {
    currentModalDays = days;
    document.querySelectorAll('#modal-periods .period-btn').forEach(b => {
        b.classList.toggle('active', parseInt(b.dataset.days, 10) === days);
    });
    buildHistoryChart(currentStockHistory, days);
}

function buildHistoryChart(history, days) {
    const canvas = $('historyChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const c = chartColors();
    const data = sliceSeries(history, days);

    if (historyChart && typeof historyChart.destroy === 'function') {
        historyChart.destroy();
        historyChart = null;
    }

    const hasAdapter = !!(Chart._adapters && Chart._adapters._date);
    let chartConfig;

    if (hasAdapter) {
        chartConfig = {
            type: 'line',
            data: {
                datasets: [{
                    label: 'Precio',
                    data: data,
                    borderColor: c.line,
                    backgroundColor: 'rgba(99,102,241,0.12)',
                    fill: true, tension: 0.25, pointRadius: 0, borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (i) => fmtEUR(i.parsed.y, 3) } }
                },
                scales: {
                    x: { type: 'time', grid: { color: c.grid }, ticks: { color: c.tick, maxTicksLimit: 7 } },
                    y: { grid: { color: c.grid }, ticks: { color: c.tick, callback: v => fmtNum(v, 2) + ' €' } }
                }
            }
        };
    } else {
        const labels = data.map(p => {
            const d = new Date(p.x);
            return d.toLocaleDateString('es-ES', { day: '2-digit', month: 'short' });
        });
        const prices = data.map(p => p.y);
        chartConfig = {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Precio',
                    data: prices,
                    borderColor: c.line,
                    backgroundColor: 'rgba(99,102,241,0.12)',
                    fill: true, tension: 0.25, pointRadius: 0, borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (i) => fmtEUR(i.parsed.y, 3) } }
                },
                scales: {
                    x: { grid: { color: c.grid }, ticks: { color: c.tick, maxTicksLimit: 7 } },
                    y: { grid: { color: c.grid }, ticks: { color: c.tick, callback: v => fmtNum(v, 2) + ' €' } }
                }
            }
        };
    }

    historyChart = new Chart(canvas.getContext('2d'), chartConfig);
}

/* ---------- Cálculos técnicos en cliente ---------- */
function closesFromHistory(hist) {
    return (hist || []).map(p => p.y).filter(v => v > 0);
}

function emaSeries(values, period) {
    if (values.length < period) return [];
    const k = 2 / (period + 1);
    let ema = values.slice(0, period).reduce((a, b) => a + b, 0) / period;
    const out = [ema];
    for (let i = period; i < values.length; i++) {
        ema = values[i] * k + ema * (1 - k);
        out.push(ema);
    }
    return out;
}

function calcMACD(hist) {
    const closes = closesFromHistory(hist);
    if (closes.length < 35) return null;
    const ema12 = emaSeries(closes, 12);
    const ema26 = emaSeries(closes, 26);
    const macdLine = [];
    for (let i = 0; i < ema26.length; i++) macdLine.push(ema12[i + (ema12.length - ema26.length)] - ema26[i]);
    const signal = emaSeries(macdLine, 9);
    if (!signal.length) return null;
    const macd = macdLine[macdLine.length - 1];
    const sig = signal[signal.length - 1];
    const trend = macd > sig ? 'alcista ▲' : 'bajista ▼';
    return macd.toFixed(3) + ' (' + trend + ')';
}

function calcBollinger(hist) {
    const closes = closesFromHistory(hist);
    if (closes.length < 20) return null;
    const last20 = closes.slice(-20);
    const mean = last20.reduce((a, b) => a + b, 0) / 20;
    const sd = Math.sqrt(last20.reduce((s, v) => s + Math.pow(v - mean, 2), 0) / 20);
    const price = closes[closes.length - 1];
    const upper = mean + 2 * sd, lower = mean - 2 * sd;
    let zona = 'zona media';
    if (price >= upper) zona = '⚠️ sobre banda superior';
    else if (price <= lower) zona = '💡 bajo banda inferior';
    return fmtNum(lower, 2) + ' – ' + fmtNum(upper, 2) + ' · ' + zona;
}

/* ---------- Diagnóstico ---------- */
function renderDiagnosis(o) {
    const box = $('decision-box');
    const reasons = [];
    let score = o.techScore || 0;

    if (!isNaN(o.rsi)) {
        if (o.rsi > 70) reasons.push('RSI en ' + o.rsi.toFixed(0) + ': sobrecompra, riesgo de corrección a corto plazo');
        else if (o.rsi < 30) reasons.push('RSI en ' + o.rsi.toFixed(0) + ': sobreventa, posible rebote técnico');
        else reasons.push('RSI en ' + o.rsi.toFixed(0) + ': zona neutral');
    }
    if (!isNaN(o.sma20) && !isNaN(o.sma50)) {
        if (o.sma20 > o.sma50) reasons.push('SMA20 por encima de SMA50: tendencia alcista de medio plazo');
        else reasons.push('SMA20 por debajo de SMA50: tendencia bajista de medio plazo');
    }
    if (o.target > 0 && o.price > 0) {
        const pot = (o.target - o.price) / o.price * 100;
        reasons.push(pot >= 0
            ? 'Potencial del +' + pot.toFixed(0) + '% hasta el objetivo del consenso (' + fmtEUR(o.target, 2) + ')'
            : 'Cotiza un ' + Math.abs(pot).toFixed(0) + '% POR ENCIMA del objetivo del consenso (' + fmtEUR(o.target, 2) + ')');
    }
    if (o.cost > 0) {
        const pnlPct = (o.price - o.cost) / o.cost * 100;
        reasons.push(pnlPct >= 0
            ? 'Tu posición gana un ' + pnlPct.toFixed(1) + '% desde tu coste medio (' + fmtEUR(o.cost, 3) + ')'
            : 'Tu posición pierde un ' + Math.abs(pnlPct).toFixed(1) + '% desde tu coste medio (' + fmtEUR(o.cost, 3) + ')');
    }

    const signal = o.techSignal || 'MANTENER';
    let sigCls = 'hold';
    if (score >= 10) sigCls = 'buy'; else if (score < -10) sigCls = 'sell';

    box.innerHTML = `
        <div style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.6rem;">
            <h4 style="margin:0;">🎯 Diagnóstico: ${esc(o.name)}</h4>
            <span class="signal-pill signal-${sigCls}">${signal}</span>
            <span class="text-muted" style="font-size:0.75rem;">Score técnico: ${score > 0 ? '+' : ''}${score}</span>
        </div>
        <ul style="margin:0; padding-left:1.2rem; line-height:1.7; font-size:0.85rem;">
            ${reasons.map(r => '<li>' + r + '</li>').join('')}
        </ul>
        <p style="margin:0.6rem 0 0; font-size:0.72rem; color:var(--text-muted);">
            Análisis automático basado en indicadores técnicos y fundamentales. No constituye asesoramiento financiero.
        </p>`;
}

/* ============================================================
   ACTUALIZACIÓN DE MERCADO (sin API key en cliente, sin reload)
   ============================================================ */
function showToast(title, detail, progress) {
    const toast = $('update-toast');
    if (!toast) return;
    toast.classList.add('visible');
    $('toast-title').textContent = title;
    if (detail !== undefined) $('toast-detail').textContent = detail;
    if (progress !== undefined) $('toast-bar').style.width = progress + '%';
}

function hideToast(delay = 2500) {
    setTimeout(() => $('update-toast')?.classList.remove('visible'), delay);
}

async function actualizarMercado(force = false) {
    if (updateInProgress) return;
    updateInProgress = true;
    const btn = $('btn-update');
    if (btn) { btn.disabled = true; btn.textContent = '⟳ Actualizando…'; }

    showToast('Actualizando...', 'Conectando con el servidor', 10);

    try {
        // v3: petición same-origin. quick_update.php valida el origen
        // (Sec-Fetch-Site / Referer) en el servidor: sin clave en el JS.
        const url = 'quick_update.php?ibex=1' + (force ? '&force=1' : '');
        const resp = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json();
        showToast('Procesando datos...', '', 60);

        if (data && data.stocks && Object.keys(data.stocks).length) {
            applyMarketData(data.stocks, data.ibex);
            const src = data.source === 'cache' ? 'caché' : (data.source === 'database' ? 'último cierre' : 'mercado');
            showToast('✅ Actualizado', 'Fuente: ' + src + ' · ' + new Date().toLocaleTimeString('es-ES'), 100);
        } else {
            showToast('⚠️ Actualización parcial', (data && data.error) || 'Se reintentará en el próximo ciclo', 100);
        }
    } catch (err) {
        console.error('Update error:', err);
        showToast('❌ Error de conexión', 'Se reintentará automáticamente', 100);
    } finally {
        updateInProgress = false;
        if (btn) { btn.disabled = false; btn.textContent = '⟳ Actualizar'; }
        autoRefreshCountdown = 120;
        hideToast(3000);
    }
}

/* Recalcula KPIs y tabla en caliente con los nuevos precios.
   `stocks` viene de quick_update.php, indexado por ticker_yahoo:
   { ticker: {price, previous_close, high, low, history} } */
function applyMarketData(stocks, ibex) {
    let total = 0, totalPrev = 0, totalRef = 0;

    document.querySelectorAll('#stock-table tbody tr').forEach(tr => {
        const b = tr.querySelector('button[data-ticker]');
        if (!b) return;
        const key = b.dataset.tickerYahoo || b.dataset.ticker;
        const v = stocks[key];
        if (!v || !(v.price > 0)) return;
        const price = parseFloat(v.price);
        const prev = parseFloat(v.previous_close || b.dataset.prev);
        const ref = parseFloat(b.dataset.ref);
        const shares = parseFloat(b.dataset.shares);

        b.dataset.price = price;
        b.dataset.prev = prev;

        total += price * shares;
        totalPrev += prev * shares;
        totalRef += ref * shares;

        const cells = tr.querySelectorAll('td');
        // Precio (col 2) y Valor (col 3)
        if (cells[2]) cells[2].textContent = fmtEUR(price, 3);
        if (cells[3]) cells[3].textContent = fmtEUR(price * shares, 0);
        // Día (col 5)
        const pDia = prev > 0 ? (price - prev) / prev * 100 : 0;
        if (cells[5]) cells[5].innerHTML = `<span class="badge bg-${pDia >= 0 ? 'success' : 'danger'}">${pDia >= 0 ? '▲' : '▼'} ${Math.abs(pDia).toFixed(2)}%</span>`;
        // YTD (col 6)
        const pYtd = ref > 0 ? (price - ref) / ref * 100 : 0;
        if (cells[6]) cells[6].innerHTML = `<span class="badge bg-${pYtd >= 0 ? 'success' : 'danger'}">${pYtd >= 0 ? '▲' : '▼'} ${Math.abs(pYtd).toFixed(2)}%</span>`;
        // P&L real (col 7)
        const cost = parseFloat(b.dataset.cost || 0);
        if (cells[7] && cost > 0) {
            const pnlPct = (price - cost) / cost * 100;
            const pnl = (price - cost) * shares;
            cells[7].innerHTML = `<span class="badge bg-${pnl >= 0 ? 'success' : 'danger'}">${pnl >= 0 ? '▲' : '▼'} ${Math.abs(pnlPct).toFixed(2)}%</span><br><small class="mono text-muted">${pnl >= 0 ? '+' : ''}${fmtEUR(pnl, 0)}</small>`;
        }
    });

    // KPIs en caliente
    const kpiTotal = $('kpi-total');
    if (kpiTotal && total > 0) kpiTotal.textContent = fmtEUR(total, 0);
    const dia = total - totalPrev;
    const diaPct = totalPrev > 0 ? dia / totalPrev * 100 : 0;
    const kpiDia = $('kpi-dia');
    if (kpiDia && totalPrev > 0) {
        kpiDia.textContent = (dia >= 0 ? '+' : '') + fmtEUR(dia, 0);
        kpiDia.className = 'card-value ' + (dia >= 0 ? 'text-success' : 'text-danger');
    }
    const kpiDiaPct = $('kpi-dia-pct');
    if (kpiDiaPct) {
        kpiDiaPct.textContent = `${diaPct >= 0 ? '▲' : '▼'} ${Math.abs(diaPct).toFixed(2)}%`;
        kpiDiaPct.className = 'badge ' + (diaPct >= 0 ? 'bg-success' : 'bg-danger');
    }
    const ytd = total - totalRef;
    const ytdPct = totalRef > 0 ? ytd / totalRef * 100 : 0;
    const kpiYtd = $('kpi-ytd');
    if (kpiYtd && totalRef > 0) {
        kpiYtd.textContent = (ytd >= 0 ? '+' : '') + fmtEUR(ytd, 0);
        kpiYtd.className = 'card-value ' + (ytd >= 0 ? 'text-success' : 'text-danger');
    }
    const kpiYtdPct = $('kpi-ytd-pct');
    if (kpiYtdPct) {
        kpiYtdPct.textContent = `${ytdPct >= 0 ? '▲' : '▼'} ${Math.abs(ytdPct).toFixed(2)}%`;
        kpiYtdPct.className = 'badge ' + (ytdPct >= 0 ? 'bg-success' : 'bg-danger');
    }

    // P&L Real y resumen global en caliente
    if (typeof PORTFOLIO_SUMMARY !== 'undefined') {
        PORTFOLIO_SUMMARY.totalValue = Math.round(total);
        PORTFOLIO_SUMMARY.dayChange = Math.round(dia);
        PORTFOLIO_SUMMARY.dayChangePct = parseFloat(diaPct.toFixed(2));
        PORTFOLIO_SUMMARY.ytdChange = Math.round(ytd);
        PORTFOLIO_SUMMARY.ytdChangePct = parseFloat(ytdPct.toFixed(2));
        if (PORTFOLIO_SUMMARY.costeInvertido > 0) {
            const pnl = total - PORTFOLIO_SUMMARY.costeInvertido;
            const pnlPct = (pnl / PORTFOLIO_SUMMARY.costeInvertido) * 100;
            PORTFOLIO_SUMMARY.pnlReal = Math.round(pnl);
            const kpiPnl = $('kpi-pnl');
            if (kpiPnl) {
                kpiPnl.textContent = (pnl >= 0 ? '+' : '') + fmtEUR(pnl, 0);
                kpiPnl.className = 'card-value ' + (pnl >= 0 ? 'text-success' : 'text-danger');
            }
            const kpiPnlSub = $('kpi-pnl-sub');
            if (kpiPnlSub) {
                kpiPnlSub.innerHTML = `${pnlPct >= 0 ? '+' : ''}${fmtNum(pnlPct, 2)}% sobre ${fmtEUR(PORTFOLIO_SUMMARY.costeInvertido, 0)} invertidos`;
            }
        }
    }

    // IBEX
    if (ibex && parseFloat(ibex.price) > 0) {
        const p = parseFloat(ibex.price);
        const pv = parseFloat(ibex.previous_close);
        const chg = pv > 0 ? (p - pv) / pv * 100 : 0;
        if ($('ibex-price')) $('ibex-price').textContent = fmtNum(p, 2);
        if ($('ibex-change')) {
            $('ibex-change').innerHTML = `<span class="ibex-change-icon">${chg >= 0 ? '▲' : '▼'}</span><span class="ibex-change-text">${(chg >= 0 ? '+' : '') + chg.toFixed(2)}%</span>`;
            $('ibex-change').className = 'ibex-change ' + (chg >= 0 ? 'up' : 'down');
        }
        if ($('ibex-variation')) $('ibex-variation').textContent = (p - pv >= 0 ? '+' : '') + fmtNum(p - pv, 2) + ' pts';

        const vsIbex = $('kpi-vs-ibex');
        if (vsIbex) {
            const diffDia = diaPct - chg;
            vsIbex.textContent = `vs IBEX ${(diffDia >= 0 ? '+' : '') + diffDia.toFixed(2)}%`;
            vsIbex.className = 'badge ' + (diffDia >= 0 ? 'bg-success' : 'bg-danger');
        }
    }

    // Actualizar gráfico de queso sectorial en caliente
    try { buildSectorChart(); } catch (e) {}
}

/* ---------- Auto-refresh con cuenta atrás ---------- */
function startAutoRefresh() {
    const status = $('auto-refresh-status');
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
    autoRefreshTimer = setInterval(() => {
        autoRefreshCountdown--;
        if (status) status.textContent = '⟳ ' + autoRefreshCountdown + 's';
        if (autoRefreshCountdown <= 0) {
            autoRefreshCountdown = 120;
            actualizarMercado();
        }
    }, 1000);
}

/* ============================================================
   INFORME IA (Groq vía perplexity_report.php)
   ============================================================ */
async function generarInformeAI() {
    const modal = $('aiReportModal');
    if (modal) {
        modal.classList.add('open');
        modal.style.display = 'block';
    }
    document.body.style.overflow = 'hidden';
    const content = $('ai-report-content');
    if (content) {
        content.innerHTML = `
            <div class="ai-loading">
                <div class="ai-loading-dots"><span></span><span></span><span></span></div>
                <p>Analizando movimientos del mercado...</p>
            </div>`;
    }

    try {
        const resp = await fetch('perplexity_report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ portfolio: PORTFOLIO_SUMMARY })
        });
        const data = await resp.json();
        // perplexity_report.php devuelve {report, date, model} o {error}
        if (data && data.report) {
            content.innerHTML = renderMarkdownLite(data.report);
        } else {
            content.innerHTML = '<p class="text-danger">No se pudo generar el informe: ' +
                (data && data.error ? data.error + (data.details ? ' — ' + data.details : '') : 'error desconocido') + '</p>';
        }
    } catch (err) {
        content.innerHTML = '<p class="text-danger">Error de conexión con el servicio de análisis.</p>';
    }
}

function closeAIReport() {
    const modal = $('aiReportModal');
    if (modal) {
        modal.classList.remove('open');
        modal.style.display = 'none';
    }
    document.body.style.overflow = '';
}

/* Markdown muy básico para el informe */
function renderMarkdownLite(md) {
    return md
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/^### (.*)$/gm, '<h4>$1</h4>')
        .replace(/^## (.*)$/gm, '<h3>$1</h3>')
        .replace(/^# (.*)$/gm, '<h2>$1</h2>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/^- (.*)$/gm, '<li>$1</li>')
        .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
        .replace(/\n{2,}/g, '</p><p>')
        .replace(/^/, '<p>').replace(/$/, '</p>')
        .replace(/<p><\/p>/g, '');
}

/* ---------- Cerrar modales con Escape / click fuera ---------- */
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeModal(); closeAIReport(); }
});
document.addEventListener('click', (e) => {
    if (e.target.id === 'analysisModal') closeModal();
    if (e.target.id === 'aiReportModal') closeAIReport();
});
