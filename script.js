// script.js — Cartera Pro GLM5 v2
// ========================================

let myChart = null;
let sectorChartInstance = null;
let portfolioChartInstance = null;
let currentHistoryData = [];

// Google Apps Script proxy URL
var GOOGLE_SCRIPT_URL = "https://script.google.com/macros/s/AKfycbxAyD6TzPYziOFUKrlwO928C93wYQ_Pv6nRJH1WMdwe2Rbiojx3RY2a1LkCu412k_5s/exec";

// ==========================================
// INIT
// ==========================================
document.addEventListener('DOMContentLoaded', function () {
    initSectorChart();
    initPortfolioChart(180);
    initTableSort();
    initPortfolioPeriodButtons();
    // Load IBEX35 in background on page load
    fetchIBEX35();
});

// ==========================================
// SECTOR DONUT CHART
// ==========================================
function initSectorChart() {
    var ctx = document.getElementById('sectorChart');
    if (!ctx || !window.SECTOR_DATA || SECTOR_DATA.length === 0) return;

    var labels = SECTOR_DATA.map(function (s) { return s.name; });
    var values = SECTOR_DATA.map(function (s) { return s.value; });
    var colors = SECTOR_DATA.map(function (s) { return s.color; });
    var total = values.reduce(function (a, b) { return a + b; }, 0);

    sectorChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderColor: '#0a0f1e',
                borderWidth: 3,
                hoverBorderWidth: 0,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#94a3b8',
                        padding: 15,
                        font: { size: 11 },
                        usePointStyle: true,
                        pointStyleWidth: 8
                    }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    borderColor: '#6366f1',
                    borderWidth: 1,
                    titleColor: '#f1f5f9',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: function (ctx) {
                            var pct = ((ctx.raw / total) * 100).toFixed(1);
                            return ctx.label + ': ' + formatEUR(ctx.raw) + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
}

// ==========================================
// PORTFOLIO EVOLUTION CHART
// ==========================================
function initPortfolioChart(days) {
    var ctx = document.getElementById('portfolioChart');
    if (!ctx || !window.ALL_STOCKS_HISTORY) return;

    // Collect all dates across all stocks
    var dateMap = {};
    var tickers = Object.keys(ALL_STOCKS_HISTORY);
    tickers.forEach(function (t) {
        var hist = ALL_STOCKS_HISTORY[t];
        if (!hist || !Array.isArray(hist)) return;
        hist.forEach(function (p) {
            if (!dateMap[p.x]) dateMap[p.x] = {};
            dateMap[p.x][t] = p.y;
        });
    });

    var timestamps = Object.keys(dateMap).map(Number).sort();
    // Filter by days
    if (timestamps.length > 0) {
        var cutoff = timestamps[timestamps.length - 1] - (days * 86400);
        timestamps = timestamps.filter(function (t) { return t >= cutoff; });
    }

    // Build portfolio value per date
    var labels = [];
    var portfolioValues = [];

    // Get shares mapping from table
    var sharesMap = {};
    document.querySelectorAll('#stock-table tbody tr').forEach(function (row) {
        var btn = row.querySelector('button[data-ticker]');
        if (btn) {
            sharesMap[btn.getAttribute('data-ticker')] = parseFloat(btn.getAttribute('data-shares')) || 0;
        }
    });

    // Carry forward last known price
    var lastPrices = {};
    timestamps.forEach(function (ts) {
        var date = new Date(ts * 1000);
        labels.push(date.toLocaleDateString('es-ES', { day: '2-digit', month: 'short' }));

        tickers.forEach(function (t) {
            if (dateMap[ts] && dateMap[ts][t]) {
                lastPrices[t] = dateMap[ts][t];
            }
        });

        var totalValue = 0;
        tickers.forEach(function (t) {
            var shares = sharesMap[t] || 0;
            var price = lastPrices[t] || 0;
            totalValue += shares * price;
        });
        portfolioValues.push(Math.round(totalValue));
    });

    if (portfolioChartInstance) portfolioChartInstance.destroy();

    var gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.25)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

    portfolioChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Valor Portfolio',
                data: portfolioValues,
                borderColor: '#6366f1',
                backgroundColor: gradient,
                fill: true,
                tension: 0.3,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: '#6366f1',
                borderWidth: 2.5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    borderColor: '#6366f1',
                    borderWidth: 1,
                    titleColor: '#f1f5f9',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: function (ctx) { return 'Portfolio: ' + formatEUR(ctx.raw); }
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: 'rgba(255,255,255,0.04)', drawBorder: false },
                    ticks: {
                        color: '#64748b', font: { size: 10 },
                        callback: function (v) { return (v / 1000).toFixed(0) + 'k'; }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', maxTicksLimit: 8, font: { size: 10 } }
                }
            }
        }
    });
}

function initPortfolioPeriodButtons() {
    document.querySelectorAll('#portfolio-periods .period-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#portfolio-periods .period-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            initPortfolioChart(parseInt(btn.getAttribute('data-days')));
        });
    });
}

// ==========================================
// TABLE SORTING
// ==========================================
function initTableSort() {
    document.querySelectorAll('#stock-table th[data-sort]').forEach(function (th) {
        th.addEventListener('click', function () {
            var field = th.getAttribute('data-sort');
            var tbody = document.querySelector('#stock-table tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var asc = th.classList.contains('sort-asc');

            // Reset all
            document.querySelectorAll('#stock-table th').forEach(function (h) {
                h.classList.remove('sort-asc', 'sort-desc');
            });
            th.classList.add(asc ? 'sort-desc' : 'sort-asc');

            rows.sort(function (a, b) {
                var va = getSortValue(a, field);
                var vb = getSortValue(b, field);
                return asc ? (vb > va ? 1 : -1) : (va > vb ? 1 : -1);
            });

            rows.forEach(function (r) { tbody.appendChild(r); });
        });
    });
}

function getSortValue(row, field) {
    var btn = row.querySelector('button[data-ticker]');
    switch (field) {
        case 'name': return btn ? btn.getAttribute('data-name').toLowerCase() : '';
        case 'shares': return btn ? parseFloat(btn.getAttribute('data-shares')) || 0 : 0;
        case 'price': return btn ? parseFloat(btn.getAttribute('data-price')) || 0 : 0;
        case 'value':
            if (!btn) return 0;
            return (parseFloat(btn.getAttribute('data-shares')) || 0) * (parseFloat(btn.getAttribute('data-price')) || 0);
        case 'day':
        case 'ytd':
            var idx = { 'day': 4, 'ytd': 5 }[field];
            var cell = row.cells[idx];
            if (!cell) return 0;
            var text = cell.textContent.replace(/[^0-9.,\-]/g, '').replace(',', '.');
            return parseFloat(text) || 0;
        default: return 0;
    }
}

// ==========================================
// HANDLE ANALYSIS (VER BUTTON) — BUG FIX
// ==========================================
function handleAnalysis(btn) {
    try {
        var data = {
            ticker: btn.getAttribute('data-ticker'),
            name: btn.getAttribute('data-name'),
            price: parseFloat(btn.getAttribute('data-price')) || 0,
            ref: parseFloat(btn.getAttribute('data-ref')) || 0,

            shares: parseFloat(btn.getAttribute('data-shares')) || 0,
            pe: parseFloat(btn.getAttribute('data-pe')) || 0,
            target: parseFloat(btn.getAttribute('data-target')) || 0,
            cap: parseFloat(btn.getAttribute('data-cap')) || 0,
            div: parseFloat(btn.getAttribute('data-div')) || 0,
            beta: parseFloat(btn.getAttribute('data-beta')) || 0,
            high52: parseFloat(btn.getAttribute('data-high52')) || 0,
            low52: parseFloat(btn.getAttribute('data-low52')) || 0,
            eps: parseFloat(btn.getAttribute('data-eps')) || 0,
            pb: parseFloat(btn.getAttribute('data-pb')) || 0,
            vol: parseFloat(btn.getAttribute('data-vol')) || 0,
            sector: btn.getAttribute('data-sector') || '',
            sectorColor: btn.getAttribute('data-sector-color') || '#6366f1'
        };

        // Parse history from data attribute
        var historyStr = btn.getAttribute('data-history');
        try {
            data.history = JSON.parse(historyStr);
        } catch (e) {
            // Fallback: get from global object
            data.history = window.ALL_STOCKS_HISTORY[data.ticker] || [];
        }

        openAnalysis(data);
    } catch (e) {
        console.error("Error en análisis:", e);
        alert("Error al cargar datos. Intenta actualizar el mercado.");
    }
}

// ==========================================
// OPEN ANALYSIS MODAL
// ==========================================
function openAnalysis(data) {
    // Header
    document.getElementById('modal-ticker').innerText = data.ticker;
    document.getElementById('modal-name').innerText = data.name;
    document.getElementById('modal-sector').innerText = data.sector;
    document.getElementById('modal-price').innerText = formatPrice(data.price) + ' €';

    // Change badge
    var dayChg = data.ref > 0 ? ((data.price - data.ref) / data.ref * 100) : 0;
    var changeBadge = document.getElementById('modal-change');
    changeBadge.className = 'badge ' + (dayChg >= 0 ? 'bg-success' : 'bg-danger');
    changeBadge.innerText = (dayChg >= 0 ? '▲ +' : '▼ ') + dayChg.toFixed(2) + '% YTD';

    // Fundamentals
    document.getElementById('m-per').innerText = data.pe > 0 ? data.pe.toFixed(2) : '-';
    document.getElementById('m-eps').innerText = data.eps > 0 ? data.eps.toFixed(2) + ' €' : '-';
    document.getElementById('m-target').innerText = data.target > 0 ? formatPrice(data.target) + ' €' : '-';
    document.getElementById('m-cap').innerText = formatCurrency(data.cap);
    document.getElementById('m-div').innerText = data.div > 0 ? data.div.toFixed(2) + '%' : '-';
    document.getElementById('m-beta').innerText = data.beta > 0 ? data.beta.toFixed(2) : '-';
    document.getElementById('m-pb').innerText = data.pb > 0 ? data.pb.toFixed(2) : '-';
    document.getElementById('m-vol').innerText = data.vol > 0 ? formatNumber(data.vol) : '-';

    // Position Summary (real data only)
    document.getElementById('m-shares').innerText = formatNumber(data.shares);
    var currentVal = data.shares * data.price;
    document.getElementById('m-current').innerText = formatEUR(currentVal);
    document.getElementById('m-ref').innerText = data.ref > 0 ? formatPrice(data.ref) + ' €' : '-';
    // YTD
    var ytdPct = data.ref > 0 ? ((data.price - data.ref) / data.ref * 100) : 0;
    var ytdEl = document.getElementById('m-ytd');
    ytdEl.innerHTML = '<span style="color:' + (ytdPct >= 0 ? 'var(--success)' : 'var(--danger)') + '">'
        + (ytdPct >= 0 ? '+' : '') + ytdPct.toFixed(2) + '%</span>';
    // Portfolio weight
    var totalPortfolio = 0;
    document.querySelectorAll('#stock-table tbody tr').forEach(function (row) {
        var b = row.querySelector('button[data-ticker]');
        if (b) totalPortfolio += (parseFloat(b.getAttribute('data-shares')) || 0) * (parseFloat(b.getAttribute('data-price')) || 0);
    });
    var weight = totalPortfolio > 0 ? (currentVal / totalPortfolio * 100) : 0;
    document.getElementById('m-weight').innerText = weight.toFixed(1) + '%';

    // 52 Week Range
    var rangeCard = document.getElementById('range-card');
    if (data.high52 > 0 && data.low52 > 0) {
        rangeCard.style.display = 'block';
        var rangeWidth = data.high52 - data.low52;
        var position = rangeWidth > 0 ? ((data.price - data.low52) / rangeWidth) * 100 : 50;
        position = Math.max(0, Math.min(100, position));

        document.getElementById('range-fill').style.width = position + '%';
        document.getElementById('range-marker').style.left = position + '%';
        document.getElementById('range-low').innerText = formatPrice(data.low52) + ' €';
        document.getElementById('range-high').innerText = formatPrice(data.high52) + ' €';
        document.getElementById('range-position').innerText = position.toFixed(0) + '% del rango';
    } else {
        rangeCard.style.display = 'none';
    }

    // Diagnostic
    generateDiagnostic(data);

    // Chart
    currentHistoryData = data.history || [];
    filterChart(180);

    // Show modal
    document.getElementById('analysisModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// ==========================================
// TECHNICAL ANALYSIS FUNCTIONS
// ==========================================
function calcSMA(prices, period) {
    var sma = [];
    for (var i = 0; i < prices.length; i++) {
        if (i < period - 1) { sma.push(null); continue; }
        var sum = 0;
        for (var j = i - (period - 1); j <= i; j++) sum += prices[j];
        sma.push(sum / period);
    }
    return sma;
}

function calcRSI(prices, period) {
    if (prices.length < period + 1) return null;
    var gains = 0, losses = 0;
    for (var i = 1; i <= period; i++) {
        var diff = prices[i] - prices[i - 1];
        if (diff > 0) gains += diff; else losses -= diff;
    }
    var avgGain = gains / period;
    var avgLoss = losses / period;
    for (var i = period + 1; i < prices.length; i++) {
        var diff = prices[i] - prices[i - 1];
        avgGain = (avgGain * (period - 1) + (diff > 0 ? diff : 0)) / period;
        avgLoss = (avgLoss * (period - 1) + (diff < 0 ? -diff : 0)) / period;
    }
    if (avgLoss === 0) return 100;
    var rs = avgGain / avgLoss;
    return 100 - (100 / (1 + rs));
}

function detectTrend(prices) {
    if (prices.length < 50) return { trend: 'neutral', label: 'Indeterminada', strength: 0 };
    var sma20 = calcSMA(prices, 20);
    var sma50 = calcSMA(prices, 50);
    var last20 = sma20[sma20.length - 1];
    var last50 = sma50[sma50.length - 1];
    var price = prices[prices.length - 1];
    if (price > last20 && last20 > last50) return { trend: 'bullish', label: 'Alcista ↑', strength: 2 };
    if (price > last20 || last20 > last50) return { trend: 'mild-bull', label: 'Ligeramente Alcista', strength: 1 };
    if (price < last20 && last20 < last50) return { trend: 'bearish', label: 'Bajista ↓', strength: -2 };
    if (price < last20 || last20 < last50) return { trend: 'mild-bear', label: 'Ligeramente Bajista', strength: -1 };
    return { trend: 'neutral', label: 'Lateral', strength: 0 };
}

function calcVolatility(prices) {
    if (prices.length < 20) return 0;
    var recent = prices.slice(-20);
    var returns = [];
    for (var i = 1; i < recent.length; i++) {
        returns.push((recent[i] - recent[i - 1]) / recent[i - 1]);
    }
    var mean = returns.reduce(function (a, b) { return a + b; }, 0) / returns.length;
    var variance = returns.reduce(function (a, b) { return a + Math.pow(b - mean, 2); }, 0) / returns.length;
    return Math.sqrt(variance) * Math.sqrt(252) * 100; // annualized %
}

function findSupportResistance(history) {
    if (!history || history.length < 20) return { support: 0, resistance: 0 };
    var recent = history.slice(-30);
    var lows = recent.map(function (p) { return p.l || p.y; });
    var highs = recent.map(function (p) { return p.h || p.y; });
    lows.sort(function (a, b) { return a - b; });
    highs.sort(function (a, b) { return b - a; });
    return {
        support: lows[Math.floor(lows.length * 0.1)],
        resistance: highs[Math.floor(highs.length * 0.1)]
    };
}

// ==========================================
// ANALYSIS & RECOMMENDATION ENGINE
// ==========================================
function generateDiagnostic(data) {
    var prices = (data.history || []).map(function (p) { return p.y; });
    var score = 0; // -100 to +100
    var techSignals = [];
    var fundSignals = [];

    // === TECHNICAL ANALYSIS ===
    var rsi = calcRSI(prices, 14);
    var trendInfo = detectTrend(prices);
    var volatility = calcVolatility(prices);
    var sr = findSupportResistance(data.history);

    // RSI
    if (rsi !== null) {
        if (rsi < 30) { score += 20; techSignals.push({ icon: '🟢', text: 'RSI ' + rsi.toFixed(0) + ' — Sobreventa', cls: 'signal-buy' }); }
        else if (rsi < 40) { score += 10; techSignals.push({ icon: '🟡', text: 'RSI ' + rsi.toFixed(0) + ' — Zona baja', cls: 'signal-hold' }); }
        else if (rsi > 70) { score -= 20; techSignals.push({ icon: '🔴', text: 'RSI ' + rsi.toFixed(0) + ' — Sobrecompra', cls: 'signal-sell' }); }
        else if (rsi > 60) { score -= 5; techSignals.push({ icon: '🟡', text: 'RSI ' + rsi.toFixed(0) + ' — Zona alta', cls: 'signal-hold' }); }
        else { techSignals.push({ icon: '⚪', text: 'RSI ' + rsi.toFixed(0) + ' — Neutral', cls: 'signal-neutral' }); }
    }

    // Trend
    score += trendInfo.strength * 10;
    var trendCls = trendInfo.strength > 0 ? 'signal-buy' : (trendInfo.strength < 0 ? 'signal-sell' : 'signal-neutral');
    var trendIcon = trendInfo.strength > 0 ? '🟢' : (trendInfo.strength < 0 ? '🔴' : '⚪');
    techSignals.push({ icon: trendIcon, text: 'Tendencia: ' + trendInfo.label, cls: trendCls });

    // SMA crossover
    if (prices.length >= 50) {
        var sma20 = calcSMA(prices, 20);
        var sma50 = calcSMA(prices, 50);
        var l20 = sma20[sma20.length - 1], l50 = sma50[sma50.length - 1];
        var prev20 = sma20[sma20.length - 6], prev50 = sma50[sma50.length - 6];
        if (prev20 <= prev50 && l20 > l50) {
            score += 15;
            techSignals.push({ icon: '🟢', text: 'Golden Cross (SMA20 > SMA50)', cls: 'signal-buy' });
        } else if (prev20 >= prev50 && l20 < l50) {
            score -= 15;
            techSignals.push({ icon: '🔴', text: 'Death Cross (SMA20 < SMA50)', cls: 'signal-sell' });
        } else if (l20 > l50) {
            techSignals.push({ icon: '🟢', text: 'SMA20 sobre SMA50', cls: 'signal-buy' });
        } else {
            techSignals.push({ icon: '🔴', text: 'SMA20 bajo SMA50', cls: 'signal-sell' });
        }
    }

    // Support/Resistance
    if (sr.support > 0) {
        var distSupport = ((data.price - sr.support) / sr.support * 100);
        var distResist = sr.resistance > 0 ? ((sr.resistance - data.price) / data.price * 100) : 0;
        if (distSupport < 3) {
            techSignals.push({ icon: '🟢', text: 'Cerca de soporte (' + formatPrice(sr.support) + '€)', cls: 'signal-buy' });
            score += 8;
        }
        if (distResist < 2 && sr.resistance > 0) {
            techSignals.push({ icon: '🟡', text: 'Cerca de resistencia (' + formatPrice(sr.resistance) + '€)', cls: 'signal-hold' });
            score -= 5;
        }
    }

    // Volatility
    if (volatility > 0) {
        var volCls = volatility > 30 ? 'signal-sell' : (volatility > 20 ? 'signal-hold' : 'signal-buy');
        var volIcon = volatility > 30 ? '🔴' : (volatility > 20 ? '🟡' : '🟢');
        techSignals.push({ icon: volIcon, text: 'Volatilidad: ' + volatility.toFixed(1) + '% anual', cls: volCls });
    }

    // === FUNDAMENTAL ANALYSIS ===
    // Target Price
    if (data.target > 0) {
        var potential = ((data.target - data.price) / data.price) * 100;
        if (potential > 15) {
            score += 20; fundSignals.push({ icon: '🟢', text: 'Potencial +' + potential.toFixed(1) + '% (obj: ' + formatPrice(data.target) + '€)', cls: 'signal-buy' });
        } else if (potential > 5) {
            score += 8; fundSignals.push({ icon: '🟡', text: 'Potencial +' + potential.toFixed(1) + '% (obj: ' + formatPrice(data.target) + '€)', cls: 'signal-hold' });
        } else if (potential > -5) {
            fundSignals.push({ icon: '🟡', text: 'En precio objetivo (' + formatPrice(data.target) + '€)', cls: 'signal-hold' });
        } else {
            score -= 15; fundSignals.push({ icon: '🔴', text: 'Sobre precio objetivo ' + potential.toFixed(1) + '%', cls: 'signal-sell' });
        }
    }

    // PER
    if (data.pe > 0) {
        if (data.pe < 10) { score += 15; fundSignals.push({ icon: '🟢', text: 'PER ' + data.pe.toFixed(1) + ' — Muy atractivo', cls: 'signal-buy' }); }
        else if (data.pe < 15) { score += 8; fundSignals.push({ icon: '🟢', text: 'PER ' + data.pe.toFixed(1) + ' — Atractivo', cls: 'signal-buy' }); }
        else if (data.pe < 22) { fundSignals.push({ icon: '🟡', text: 'PER ' + data.pe.toFixed(1) + ' — Razonable', cls: 'signal-hold' }); }
        else { score -= 10; fundSignals.push({ icon: '🔴', text: 'PER ' + data.pe.toFixed(1) + ' — Elevado', cls: 'signal-sell' }); }
    }

    // Dividend
    if (data.div > 0) {
        if (data.div > 5) { score += 10; fundSignals.push({ icon: '🟢', text: 'Dividendo ' + data.div.toFixed(2) + '% — Excelente', cls: 'signal-buy' }); }
        else if (data.div > 3) { score += 5; fundSignals.push({ icon: '🟢', text: 'Dividendo ' + data.div.toFixed(2) + '% — Bueno', cls: 'signal-buy' }); }
        else { fundSignals.push({ icon: '🟡', text: 'Dividendo ' + data.div.toFixed(2) + '%', cls: 'signal-hold' }); }
    }

    // Beta
    if (data.beta > 0) {
        if (data.beta > 1.3) { fundSignals.push({ icon: '🔴', text: 'Beta ' + data.beta.toFixed(2) + ' — Alta volatilidad', cls: 'signal-sell' }); }
        else if (data.beta < 0.7) { fundSignals.push({ icon: '🟢', text: 'Beta ' + data.beta.toFixed(2) + ' — Defensivo', cls: 'signal-buy' }); score += 5; }
        else { fundSignals.push({ icon: '🟡', text: 'Beta ' + data.beta.toFixed(2) + ' — Normal', cls: 'signal-hold' }); }
    }

    // P/B
    if (data.pb > 0) {
        if (data.pb < 1) { score += 5; fundSignals.push({ icon: '🟢', text: 'P/B ' + data.pb.toFixed(2) + ' — Infravalorado', cls: 'signal-buy' }); }
        else if (data.pb > 3) { score -= 5; fundSignals.push({ icon: '🟡', text: 'P/B ' + data.pb.toFixed(2) + ' — Elevado', cls: 'signal-hold' }); }
    }

    // 52-week position
    if (data.high52 > 0 && data.low52 > 0) {
        var rangePos = ((data.price - data.low52) / (data.high52 - data.low52)) * 100;
        if (rangePos < 20) { score += 10; fundSignals.push({ icon: '🟢', text: 'En mínimos 52s (' + rangePos.toFixed(0) + '% del rango)', cls: 'signal-buy' }); }
        else if (rangePos > 90) { score -= 10; fundSignals.push({ icon: '🔴', text: 'En máximos 52s (' + rangePos.toFixed(0) + '% del rango)', cls: 'signal-sell' }); }
    }

    // YTD
    if (data.ref > 0) {
        var ytdChg = ((data.price - data.ref) / data.ref) * 100;
        if (ytdChg > 10) score += 5;
        else if (ytdChg < -10) score -= 5;
    }

    // === CLAMP SCORE ===
    score = Math.max(-100, Math.min(100, score));

    // === VERDICT ===
    var verdict, verdictClass, verdictIcon;
    if (score >= 30) { verdict = 'COMPRAR'; verdictClass = 'verdict-buy'; verdictIcon = '🚀'; }
    else if (score >= 10) { verdict = 'COMPRA MODERADA'; verdictClass = 'verdict-buy'; verdictIcon = '📈'; }
    else if (score >= -10) { verdict = 'MANTENER'; verdictClass = 'verdict-hold'; verdictIcon = '⏸️'; }
    else if (score >= -30) { verdict = 'PRECAUCIÓN'; verdictClass = 'verdict-sell'; verdictIcon = '⚠️'; }
    else { verdict = 'VENDER'; verdictClass = 'verdict-sell'; verdictIcon = '📉'; }

    // === RENDER ===
    var html = '';

    // VERDICT GAUGE
    var gaugeAngle = ((score + 100) / 200) * 180 - 90; // -90 to +90 degrees
    html += '<div class="verdict-panel ' + verdictClass + '">';
    html += '  <div class="verdict-gauge">';
    html += '    <div class="gauge-bg"><div class="gauge-fill" style="transform:rotate(' + gaugeAngle + 'deg)"></div><div class="gauge-center"></div></div>';
    html += '    <div class="gauge-labels"><span>Vender</span><span>Mantener</span><span>Comprar</span></div>';
    html += '  </div>';
    html += '  <div class="verdict-label">' + verdictIcon + ' ' + verdict + '</div>';
    html += '  <div class="verdict-score">Puntuación: ' + score + '/100</div>';
    html += '</div>';

    // TECHNICAL ANALYSIS
    html += '<div class="analysis-section">';
    html += '  <h4 class="analysis-title">📈 Análisis Técnico</h4>';
    html += '  <div class="signal-grid">';
    techSignals.forEach(function (s) {
        html += '<div class="signal-item ' + s.cls + '"><span class="signal-icon">' + s.icon + '</span><span>' + s.text + '</span></div>';
    });
    if (techSignals.length === 0) html += '<div class="signal-item signal-neutral"><span>Sin datos técnicos suficientes</span></div>';
    html += '  </div>';
    html += '</div>';

    // FUNDAMENTAL ANALYSIS
    html += '<div class="analysis-section">';
    html += '  <h4 class="analysis-title">📊 Análisis Fundamental</h4>';
    html += '  <div class="signal-grid">';
    fundSignals.forEach(function (s) {
        html += '<div class="signal-item ' + s.cls + '"><span class="signal-icon">' + s.icon + '</span><span>' + s.text + '</span></div>';
    });
    if (fundSignals.length === 0) html += '<div class="signal-item signal-neutral"><span>⚠️ Ejecuta el SQL de datos fundamentales en phpMyAdmin</span></div>';
    html += '  </div>';
    html += '</div>';

    // Disclaimer
    html += '<p class="analysis-disclaimer">⚠️ Análisis algorítmico orientativo. No constituye asesoramiento financiero.</p>';

    document.getElementById('decision-box').innerHTML = html;
}

// ==========================================
// CHART FUNCTIONS
// ==========================================
function filterChart(days) {
    // Update period buttons
    document.querySelectorAll('#modal-periods .period-btn').forEach(function (b) {
        b.classList.toggle('active', parseInt(b.getAttribute('data-days')) === days);
    });

    var history = currentHistoryData;
    if (!history || history.length === 0) {
        renderEmptyChart();
        return;
    }

    // Filter by days
    var now = history[history.length - 1].x;
    var cutoff = now - (days * 86400);
    var filtered = history.filter(function (p) { return p.x >= cutoff; });

    if (filtered.length === 0) filtered = history;
    renderProChart(filtered);
}

function renderProChart(history) {
    var ctx = document.getElementById('historyChart').getContext('2d');
    if (myChart) myChart.destroy();

    var labels = [], prices = [], highs = [], lows = [];

    history.forEach(function (p) {
        labels.push(new Date(p.x * 1000).toLocaleDateString('es-ES', { day: '2-digit', month: 'short' }));
        prices.push(p.y);
        if (p.h) highs.push(p.h);
        if (p.l) lows.push(p.l);
    });

    var gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, 'rgba(99, 102, 241, 0.2)');
    gradient.addColorStop(1, 'rgba(99, 102, 241, 0.0)');

    var first = prices[0] || 0;
    var last = prices[prices.length - 1] || 0;
    var lineColor = last >= first ? '#10b981' : '#ef4444';
    var fillColor = last >= first ? 'rgba(16, 185, 129, 0.12)' : 'rgba(239, 68, 68, 0.12)';

    var datasets = [{
        label: 'Precio',
        data: prices,
        borderColor: lineColor,
        backgroundColor: fillColor,
        fill: true,
        tension: 0.2,
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBackgroundColor: lineColor,
        borderWidth: 2.5
    }];

    // SMA 20
    if (prices.length >= 20) {
        var sma20 = calcSMA(prices, 20);
        datasets.push({
            label: 'SMA 20',
            data: sma20,
            borderColor: 'rgba(251, 191, 36, 0.7)',
            borderWidth: 1.5,
            borderDash: [4, 4],
            fill: false,
            pointRadius: 0,
            pointHoverRadius: 0
        });
    }
    // SMA 50
    if (prices.length >= 50) {
        var sma50 = calcSMA(prices, 50);
        datasets.push({
            label: 'SMA 50',
            data: sma50,
            borderColor: 'rgba(139, 92, 246, 0.6)',
            borderWidth: 1.5,
            borderDash: [6, 3],
            fill: false,
            pointRadius: 0,
            pointHoverRadius: 0
        });
    }

    myChart = new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: {
                    display: true,
                    labels: { color: '#64748b', font: { size: 10 }, usePointStyle: true, pointStyleWidth: 8 }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    borderColor: '#6366f1',
                    borderWidth: 1,
                    titleColor: '#f1f5f9',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: function (ctx) {
                            return ctx.dataset.label + ': ' + formatPrice(ctx.raw) + ' €';
                        }
                    }
                }
            },
            scales: {
                y: {
                    grid: { color: 'rgba(255,255,255,0.04)', drawBorder: false },
                    ticks: { color: '#64748b', font: { size: 10 } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#64748b', maxTicksLimit: 8, font: { size: 10 } }
                }
            }
        }
    });
}

function renderEmptyChart() {
    var ctx = document.getElementById('historyChart').getContext('2d');
    if (myChart) myChart.destroy();
    myChart = new Chart(ctx, {
        type: 'line',
        data: { labels: ['Sin datos'], datasets: [{ data: [0], borderColor: '#64748b' }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });
}

// ==========================================
// MARKET UPDATE (parallel with concurrency limit)
// ==========================================
function actualizarMercado() {
    var btn = document.getElementById('btn-update');
    var log = document.getElementById('log-area');
    log.style.display = 'block';
    log.innerHTML = "<p>🔄 Conectando con Google Apps Script...</p>";
    btn.disabled = true;

    var tickers = ['IBE.MC', 'TEF.MC', 'ELE.MC', 'BBVA.MC', 'REP.MC', 'MFEA.MI', 'A3M.MC'];
    procesarTickersParalelo(tickers, 7, log, btn);
}

function procesarTickersParalelo(tickers, maxConcurrent, log, btn) {
    var completed = 0;
    var running = 0;
    var nextIndex = 0;

    function launchNext() {
        while (running < maxConcurrent && nextIndex < tickers.length) {
            var idx = nextIndex++;
            running++;
            fetchTicker(tickers[idx], idx, tickers.length, log, function () {
                running--;
                completed++;
                if (completed === tickers.length) {
                    log.innerHTML += "<p><strong>✅ ¡Portfolio actualizado! Cargando IBEX 35...</strong></p>";
                    log.scrollTop = log.scrollHeight;
                    // Fetch IBEX35 in background AFTER portfolio is done
                    fetchIBEX35(function () {
                        log.innerHTML += "<p><strong>🏁 ¡Proceso completo! Recargando en 2s...</strong></p>";
                        log.scrollTop = log.scrollHeight;
                        setTimeout(function () { location.reload(); }, 2000);
                    });
                } else {
                    launchNext();
                }
            });
        }
    }

    launchNext();
}

function fetchTicker(ticker, index, total, log, onDone) {
    log.innerHTML += "<p>📊 Procesando " + ticker + " (" + (index + 1) + "/" + total + ")...</p>";
    log.scrollTop = log.scrollHeight;

    var callbackName = 'jsonp_' + Math.round(100000 * Math.random());
    var script;

    window[callbackName] = function (result) {
        delete window[callbackName];
        if (script && script.parentNode) document.body.removeChild(script);

        if (result.chart && result.chart.result && result.chart.result[0]) {
            var res = result.chart.result[0];
            var quotes = res.indicators.quote[0];
            var timestamps = res.timestamp;

            var historyArray = [];
            timestamps.forEach(function (t, i) {
                if (quotes.close[i]) {
                    historyArray.push({
                        x: t,
                        y: quotes.close[i],
                        h: quotes.high[i] || quotes.close[i],
                        l: quotes.low[i] || quotes.close[i]
                    });
                }
            });

            var price = 0, previous = 0;
            if (historyArray.length > 0) price = historyArray[historyArray.length - 1].y;
            if (historyArray.length > 1) previous = historyArray[historyArray.length - 2].y;

            var fund = res.meta.fundamentals || {};

            fetch('save_data.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ticker: ticker,
                    price: price,
                    previous_close: previous,
                    high_last: fund.dayHigh || 0,
                    low_last: fund.dayLow || 0,
                    history: JSON.stringify(historyArray),
                    pe: fund.pe || 0,
                    cap: fund.marketCap || 0,
                    target: fund.target || 0,
                    div: fund.dividend || 0,
                    beta: fund.beta || 0,
                    high52: fund.fiftyTwoWeekHigh || 0,
                    low52: fund.fiftyTwoWeekLow || 0,
                    vol: fund.avgVolume || 0,
                    pb: fund.priceToBook || 0,
                    eps: fund.eps || 0,
                    rev: fund.revenue || 0
                })
            });

            var chg = previous > 0 ? ((price - previous) / previous * 100).toFixed(2) : '0.00';
            log.innerHTML += "<p style='color:#10b981'>✅ " + ticker + ": " + price.toFixed(3) + "€ (" + (chg >= 0 ? '+' : '') + chg + "%)</p>";
        } else {
            log.innerHTML += "<p style='color:#ef4444'>⚠️ " + ticker + ": Sin datos</p>";
        }
        log.scrollTop = log.scrollHeight;
        onDone();
    };

    script = document.createElement('script');
    script.src = GOOGLE_SCRIPT_URL + "?callback=" + callbackName + "&symbols=" + ticker;
    script.onerror = function () {
        log.innerHTML += "<p style='color:#ef4444'>❌ Error de red en " + ticker + "</p>";
        log.scrollTop = log.scrollHeight;
        onDone();
    };
    document.body.appendChild(script);
}

// ==========================================
// IBEX35 BACKGROUND FETCH
// ==========================================
function fetchIBEX35(onDone) {
    var banner = document.getElementById('ibex-banner');
    var priceEl = document.getElementById('ibex-price');
    var changeEl = document.getElementById('ibex-change');
    var variationEl = document.getElementById('ibex-variation');

    if (!banner) { if (onDone) onDone(); return; }
    banner.classList.add('loading');

    fetch('ibex_update.php')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            banner.classList.remove('loading');

            if (data.price && data.price > 0) {
                var isUp = data.change >= 0;

                priceEl.textContent = data.price.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                changeEl.className = 'ibex-change ' + (isUp ? 'up' : 'down');
                changeEl.innerHTML =
                    '<span class="ibex-change-icon">' + (isUp ? '▲' : '▼') + '</span>' +
                    '<span class="ibex-change-text">' + (isUp ? '+' : '') + data.change.toFixed(2) + '%</span>';

                variationEl.textContent = (isUp ? '+' : '') + data.diff.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' pts';
            } else {
                priceEl.textContent = 'N/D';
                changeEl.innerHTML = '<span class="ibex-change-icon">⚠️</span><span class="ibex-change-text">' + (data.error || 'Sin datos') + '</span>';
                changeEl.className = 'ibex-change';
            }

            if (onDone) onDone();
        })
        .catch(function () {
            banner.classList.remove('loading');
            priceEl.textContent = 'Error';
            changeEl.innerHTML = '<span class="ibex-change-icon">❌</span><span class="ibex-change-text">Red</span>';
            changeEl.className = 'ibex-change';
            if (onDone) onDone();
        });
}

// ==========================================
// MODAL CONTROLS
// ==========================================
function closeModal() {
    document.getElementById('analysisModal').style.display = 'none';
    document.body.style.overflow = '';
}

window.onclick = function (e) {
    if (e.target === document.getElementById('analysisModal')) closeModal();
};

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
});

// ==========================================
// FORMATTING UTILITIES
// ==========================================
function formatPrice(num) {
    if (!num || num === 0) return '0.000';
    return num.toFixed(3).replace('.', ',');
}

function formatEUR(num) {
    if (!num || num === 0) return '0 €';
    return num.toLocaleString('es-ES', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' €';
}

function formatCurrency(num) {
    if (!num || num === 0) return '-';
    if (num >= 1e12) return (num / 1e12).toFixed(2) + ' B€';
    if (num >= 1e9) return (num / 1e9).toFixed(2) + ' MMM€';
    if (num >= 1e6) return (num / 1e6).toFixed(2) + ' MM€';
    return num.toLocaleString('es-ES') + ' €';
}

function formatNumber(num) {
    if (!num || num === 0) return '-';
    return num.toLocaleString('es-ES');
}
