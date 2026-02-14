<?php
// index.php
require_once 'api.php';

$portfolioData = [];
$valorTotalCartera = 0;
$gananciaYTDTotal = 0;
$gananciaDiaTotal = 0;
$totalValorDiaAnterior = 0;

$stmt = $pdo->query("SELECT * FROM valores");
$valoresBD = $stmt->fetchAll();

foreach ($valoresBD as $val) {
    // --- 1. DATOS RÁPIDOS (PRECIO) ---
    // Siempre intentamos traer precio real
    $quote = StockAPI::getQuickQuote($val['ticker_yahoo']);

    if ($quote) {
        $precioActual = $quote['price'];
        $precioCierreAnterior = $quote['previous_close'];

        // Actualizamos cierre anterior en BD si cambió
        if ($precioCierreAnterior != $val['precio_cierre_anterior']) {
            $pdo->prepare("UPDATE valores SET precio_cierre_anterior=? WHERE id=?")
                ->execute([$precioCierreAnterior, $val['id']]);
        }
    } else {
        // Fallback
        $precioActual = $val['precio_cierre_2025'];
        $precioCierreAnterior = $val['precio_cierre_anterior'] > 0 ? $val['precio_cierre_anterior'] : $val['precio_cierre_2025'];
    }

    // --- 2. ACTUALIZAR FUNDAMENTALES EN SEGUNDO PLANO (Solo si son 0 o viejos) ---
    // Si el PER es 0, intentamos actualizarlo una vez para que no esté vacío
    if ($val['pe_ratio'] == 0) {
        $funds = StockAPI::getFundamentals($val['ticker_yahoo']);
        if ($funds) {
            $pdo->prepare("UPDATE valores SET pe_ratio=?, target_price=?, market_cap=?, fifty_two_week_high=?, fifty_two_week_low=? WHERE id=?")
                ->execute([$funds['pe_ratio'], $funds['target_price'], $funds['market_cap'], $funds['fifty_two_week_high'], $funds['fifty_two_week_low'], $val['id']]);
            // Actualizamos array local
            $val['pe_ratio'] = $funds['pe_ratio'];
            $val['target_price'] = $funds['target_price'];
            $val['market_cap'] = $funds['market_cap'];
        }
    }

    // --- 3. CÁLCULOS ---
    // A. Día
    $gananciaDia = ($precioActual - $precioCierreAnterior) * $val['num_acciones'];
    $porcentajeDia = ($precioCierreAnterior > 0) ? (($precioActual - $precioCierreAnterior) / $precioCierreAnterior) * 100 : 0;

    // B. YTD
    $precioRefYTD = $val['precio_cierre_2025'];
    $valorPosicion = $val['num_acciones'] * $precioActual;
    $gananciaYTD = ($precioActual - $precioRefYTD) * $val['num_acciones'];
    $porcentajeYTD = ($precioRefYTD > 0) ? (($precioActual - $precioRefYTD) / $precioRefYTD) * 100 : 0;

    // Acumuladores
    $valorTotalCartera += $valorPosicion;
    $gananciaYTDTotal += $gananciaYTD;
    $gananciaDiaTotal += $gananciaDia;
    $totalValorDiaAnterior += ($precioCierreAnterior * $val['num_acciones']);

    $portfolioData[] = array_merge($val, [
        'precio_actual' => $precioActual,
        'valor_total' => $valorPosicion,
        'ganancia_ytd' => $gananciaYTD,
        'porcentaje_ytd' => $porcentajeYTD,
        'ganancia_dia' => $gananciaDia,
        'porcentaje_dia' => $porcentajeDia
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cartera Pro | Completa</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <div class="container">
        <header>
            <div>
                <h1>Cartera <span style="color:var(--accent)">Pro</span></h1>
                <p style="color:var(--text-muted);">v3.0 Estable</p>
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-primary">⟳ Actualizar Todo</a>
            </div>
        </header>

        <div class="dashboard-grid">
            <div class="card">
                <p class="card-title">Valor de Mercado</p>
                <h2 class="card-value"><?= number_format($valorTotalCartera, 2, ',', '.') ?> €</h2>
            </div>

            <div class="card">
                <p class="card-title">Sesión Hoy</p>
                <h2 class="card-value" style="color: <?= $gananciaDiaTotal >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                    <?= $gananciaDiaTotal >= 0 ? '+' : '' ?><?= number_format($gananciaDiaTotal, 2, ',', '.') ?> €
                </h2>
                <?php $pctTotalDia = ($totalValorDiaAnterior > 0) ? ($gananciaDiaTotal / $totalValorDiaAnterior) * 100 : 0; ?>
                <span style="font-size:0.9rem; color:var(--text-muted);"><?= $pctTotalDia >= 0 ? '+' : '' ?><?= number_format($pctTotalDia, 2) ?>%</span>
            </div>

            <div class="card">
                <p class="card-title">Ganancia YTD 2026</p>
                <h2 class="card-value" style="color: <?= $gananciaYTDTotal >= 0 ? 'var(--success)' : 'var(--danger)' ?>">
                    <?= $gananciaYTDTotal >= 0 ? '+' : '' ?><?= number_format($gananciaYTDTotal, 2, ',', '.') ?> €
                </h2>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Valor</th>
                        <th style="text-align:right;">P. Actual</th>
                        <th style="text-align:right;">Valor</th>
                        <th style="text-align:right;">Día %</th>
                        <th style="text-align:right;">YTD %</th>
                        <th style="text-align:center;">Análisis</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($portfolioData as $row):
                        $clsYTD = $row['porcentaje_ytd'] >= 0 ? 'success' : 'danger';
                        $clsDia = $row['porcentaje_dia'] >= 0 ? 'success' : 'danger';
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight:600; color:white"><?= htmlspecialchars($row['ticker']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted)"><?= htmlspecialchars($row['nombre']) ?></div>
                            </td>
                            <td style="text-align:right;">
                                <div style="font-weight:600;"><?= number_format($row['precio_actual'], 2) ?> €</div>
                            </td>
                            <td style="text-align:right;"><?= number_format($row['valor_total'], 2, ',', '.') ?> €</td>
                            <td style="text-align:right;">
                                <span class="badge bg-<?= $clsDia ?>">
                                    <?= $row['porcentaje_dia'] >= 0 ? '+' : '' ?><?= number_format($row['porcentaje_dia'], 2) ?>%
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <span class="badge bg-<?= $clsYTD ?>">
                                    <?= $row['porcentaje_ytd'] >= 0 ? '+' : '' ?><?= number_format($row['porcentaje_ytd'], 2) ?>%
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <button class="btn btn-secondary"
                                    onclick="loadAnalysis('<?= $row['ticker_yahoo'] ?>', '<?= htmlspecialchars($row['nombre']) ?>', '<?= $row['pe_ratio'] ?>', '<?= $row['target_price'] ?>')">
                                    Ver Análisis
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL -->
    <div id="analysisModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">×</span>
            <div style="margin-bottom: 20px;">
                <h2 id="modal-ticker" style="margin-bottom:5px;">Ticker</h2>
                <span id="modal-name" style="color:var(--text-muted)"></span>
            </div>

            <div class="analysis-section">
                <!-- Gráfico -->
                <div class="card">
                    <div class="chart-container"><canvas id="historyChart"></canvas></div>
                </div>
                <!-- Datos -->
                <div>
                    <div class="card" style="margin-bottom: 1rem;">
                        <h3 style="margin-bottom: 1rem; font-size: 1rem;">Fundamentales</h3>
                        <ul class="fundamental-list" id="fund-list">
                            <li><span>PER</span><span id="m-per">Cargando...</span></li>
                            <li><span>Precio Objetivo</span><span id="m-target">Cargando...</span></li>
                            <li><span>Cap. Mercado</span><span id="m-cap">Cargando...</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>

</html>