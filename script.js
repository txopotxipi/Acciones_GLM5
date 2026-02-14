// script.js
let myChart = null;

function loadAnalysis(ticker, name, peCached, targetCached) {
    // 1. Mostramos datos inmediatamente (los que ya teníamos en la tabla)
    document.getElementById('modal-ticker').innerText = ticker;
    document.getElementById('modal-name').innerText = name;
    document.getElementById('m-per').innerText = peCached > 0 ? peCached : '-';
    document.getElementById('m-target').innerText = targetCached > 0 ? targetCached + ' €' : '-';

    // 2. Abrimos el modal
    document.getElementById('analysisModal').style.display = 'block';

    // 3. Petición AJAX para obtener el gráfico y datos frescos
    fetch(`ajax_analisis.php?ticker=${ticker}`)
        .then(response => response.json())
        .then(data => {
            // Actualizar fundamentales si vinieron
            if (data.fundamentals) {
                if (data.fundamentals.pe_ratio) document.getElementById('m-per').innerText = data.fundamentals.pe_ratio;
                if (data.fundamentals.target_price) document.getElementById('m-target').innerText = data.fundamentals.target_price + ' €';
                if (data.fundamentals.market_cap) document.getElementById('m-cap').innerText = formatCurrency(data.fundamentals.market_cap);
            }

            // Pintar Gráfico
            if (data.history && data.history.length > 0) {
                const labels = data.history.map(h => h.date);
                const prices = data.history.map(h => h.close);
                createChart(labels, prices);
            }
        })
        .catch(err => console.log("Error cargando análisis:", err));
}

function createChart(labels, data) {
    const ctx = document.getElementById('historyChart').getContext('2d');
    if (myChart) myChart.destroy();

    const smaData = calculateSMA(data, 20);

    myChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Precio', data: data, borderColor: '#6366f1', backgroundColor: 'rgba(99, 102, 241, 0.1)', borderWidth: 2, fill: true, tension: 0.4 },
                { label: 'Media 20', data: smaData, borderColor: '#f59e0b', borderWidth: 2, borderDash: [5, 5], fill: false, tension: 0.4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(255,255,255,0.05)' } }
            }
        }
    });
}

function calculateSMA(data, period) {
    let sma = [];
    for (let i = 0; i < data.length; i++) {
        if (i < period - 1) sma.push(null);
        else {
            let sum = 0;
            for (let j = 0; j < period; j++) sum += data[i - j];
            sma.push(sum / period);
        }
    }
    return sma;
}

function formatCurrency(num) {
    if (num >= 1e9) return (num / 1e9).toFixed(2) + ' MMM €';
    if (num >= 1e6) return (num / 1e6).toFixed(2) + ' MM €';
    return num + ' €';
}

function closeModal() { document.getElementById('analysisModal').style.display = 'none'; }
window.onclick = function (event) {
    const modal = document.getElementById('analysisModal');
    if (event.target == modal) closeModal();
}