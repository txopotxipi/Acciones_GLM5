<?php
// index.php — Cartera Pro GLM5 v2
require_once 'config.php';

$stmt = $pdo->query("SELECT * FROM valores WHERE num_acciones > 0 ORDER BY (num_acciones * precio_actual) DESC");
$valores = $stmt->fetchAll();

// IBEX35 data from DB
$ibexStmt = $pdo->query("SELECT precio_actual, precio_cierre_anterior FROM valores WHERE ticker = '^IBEX' LIMIT 1");
$ibexData = $ibexStmt->fetch();
$ibexPrice = $ibexData ? $ibexData['precio_actual'] : 0;
$ibexPrev  = $ibexData ? $ibexData['precio_cierre_anterior'] : 0;
$ibexChg   = ($ibexPrev > 0) ? (($ibexPrice - $ibexPrev) / $ibexPrev * 100) : 0;
$ibexDiff  = $ibexPrice - $ibexPrev;
$ibexUp    = $ibexChg >= 0;

$valorTotalCartera = 0;
$gananciaYTDTotal = 0;
$gananciaDiaTotal = 0;
$totalCapitalInicio2026 = 0;
$totalValorDiaAnterior = 0;

$portfolioData = [];

// Sectores mapping
$sectores = [
    'IBE.MC' => ['sector' => 'Energía', 'color' => '#10b981'],
    'TEF.MC' => ['sector' => 'Telecomunicaciones', 'color' => '#3b82f6'],
    'ELE.MC' => ['sector' => 'Energía', 'color' => '#10b981'],
    'BBVA.MC' => ['sector' => 'Banca', 'color' => '#f59e0b'],
    'REP.MC' => ['sector' => 'Petróleo', 'color' => '#ef4444'],
    'MFE-A' => ['sector' => 'Media', 'color' => '#8b5cf6'],
    'A3M.MC' => ['sector' => 'Media', 'color' => '#8b5cf6'],
];

foreach ($valores as $val) {
    $precioActual = ($val['precio_actual'] > 0) ? $val['precio_actual'] : $val['precio_cierre_2025'];
    $precioCierreAnterior = ($val['precio_cierre_anterior'] > 0) ? $val['precio_cierre_anterior'] : $precioActual;

    $valorPosicion = $val['num_acciones'] * $precioActual;
    $gananciaYTD = ($precioActual - $val['precio_cierre_2025']) * $val['num_acciones'];
    $porcentajeYTD = ($val['precio_cierre_2025'] > 0) ? (($precioActual - $val['precio_cierre_2025']) / $val['precio_cierre_2025']) * 100 : 0;

    $gananciaDia = ($precioActual - $precioCierreAnterior) * $val['num_acciones'];
    $porcentajeDia = ($precioCierreAnterior > 0) ? (($precioActual - $precioCierreAnterior) / $precioCierreAnterior) * 100 : 0;



    $valorTotalCartera += $valorPosicion;
    $gananciaYTDTotal += $gananciaYTD;
    $gananciaDiaTotal += $gananciaDia;
    $totalCapitalInicio2026 += ($val['precio_cierre_2025'] * $val['num_acciones']);
    $totalValorDiaAnterior += ($precioCierreAnterior * $val['num_acciones']);


    $sectorInfo = $sectores[$val['ticker']] ?? ['sector' => 'Otro', 'color' => '#64748b'];

    $portfolioData[] = [
        'data' => $val,
        'precio_actual' => $precioActual,
        'valor_total' => $valorPosicion,
        'p_ytd' => $porcentajeYTD,
        'p_dia' => $porcentajeDia,

        'sector' => $sectorInfo['sector'],
        'sector_color' => $sectorInfo['color'],
    ];
}

$porcentajeYTDTotal = ($totalCapitalInicio2026 > 0) ? ($gananciaYTDTotal / $totalCapitalInicio2026) * 100 : 0;
$pctTotalDia = ($totalValorDiaAnterior > 0) ? ($gananciaDiaTotal / $totalValorDiaAnterior) * 100 : 0;


// Sectoral distribution
$sectorDistrib = [];
foreach ($portfolioData as $row) {
    $s = $row['sector'];
    if (!isset($sectorDistrib[$s])) $sectorDistrib[$s] = ['valor' => 0, 'color' => $row['sector_color']];
    $sectorDistrib[$s]['valor'] += $row['valor_total'];
}

// Market status (European markets: 9:00 - 17:30 CET)
date_default_timezone_set('Europe/Madrid');
$hora = (int)date('G');
$min = (int)date('i');
$dow = (int)date('N');
$marketOpen = ($dow <= 5 && (($hora > 9 || ($hora == 9 && $min >= 0)) && ($hora < 17 || ($hora == 17 && $min <= 30))));
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cartera Pro GLM5 — Portfolio Financiero</title>
    <meta name="description" content="Panel de control del portfolio financiero GLM5 con datos en tiempo real, análisis técnico y fundamental.">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <div class="container">
        <!-- HEADER -->
        <header>
            <div class="header-left">
                <h1>Cartera <span>Pro</span></h1>
                <div class="subtitle">
                    Portfolio GLM5
                    <span class="market-status <?= $marketOpen ? 'open' : 'closed' ?>">
                        <span class="dot"></span>
                        <?= $marketOpen ? 'Mercado Abierto' : 'Mercado Cerrado' ?>
                    </span>
                </div>
            </div>
            <div class="header-right">
                <div class="last-update">
                    <?php
                    $update = null;
                    foreach ($valores as $v) {
                        if ($v['ultima_actualizacion']) {
                            $update = $v['ultima_actualizacion'];
                            break;
                        }
                    }
                    if ($update) {
                        $dt = new DateTime($update, new DateTimeZone('UTC'));
                        $dt->setTimezone(new DateTimeZone('Europe/Madrid'));
                        echo 'Última act: ' . $dt->format('d/m H:i');
                    }
                    ?>
                </div>
                <button id="btn-update" onclick="actualizarMercado()" class="btn btn-primary">⟳ Actualizar</button>
            </div>
        </header>

        <!-- LOG AREA -->
        <div id="log-area" style="display:none;"></div>

        <!-- DASHBOARD KPIs -->
        <div class="dashboard-grid">
            <div class="card fade-in stagger-1">
                <div class="card-icon accent">💰</div>
                <p class="card-title">Valor de Mercado</p>
                <h2 class="card-value"><?= number_format($valorTotalCartera, 0, ',', '.') ?> €</h2>
                <p class="card-sub"><?= count($portfolioData) ?> valores en cartera</p>
            </div>
            <div class="card fade-in stagger-2">
                <div class="card-icon <?= $gananciaDiaTotal >= 0 ? 'success' : 'danger' ?>">
                    <?= $gananciaDiaTotal >= 0 ? '📈' : '📉' ?>
                </div>
                <p class="card-title">Sesión Hoy</p>
                <h2 class="card-value <?= $gananciaDiaTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $gananciaDiaTotal >= 0 ? '+' : '' ?><?= number_format($gananciaDiaTotal, 0, ',', '.') ?> €
                </h2>
                <p class="card-sub">
                    <span class="badge <?= $pctTotalDia >= 0 ? 'bg-success' : 'bg-danger' ?>">
                        <?= $pctTotalDia >= 0 ? '▲' : '▼' ?> <?= number_format(abs($pctTotalDia), 2) ?>%
                    </span>
                </p>
            </div>
            <div class="card fade-in stagger-3">
                <div class="card-icon <?= $gananciaYTDTotal >= 0 ? 'success' : 'danger' ?>">📅</div>
                <p class="card-title">YTD 2026</p>
                <h2 class="card-value <?= $gananciaYTDTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $gananciaYTDTotal >= 0 ? '+' : '' ?><?= number_format($gananciaYTDTotal, 0, ',', '.') ?> €
                </h2>
                <p class="card-sub">
                    <span class="badge <?= $porcentajeYTDTotal >= 0 ? 'bg-success' : 'bg-danger' ?>">
                        <?= $porcentajeYTDTotal >= 0 ? '▲' : '▼' ?> <?= number_format(abs($porcentajeYTDTotal), 2) ?>%
                    </span>
                </p>
            </div>
            <div class="card fade-in stagger-4">
                <div class="card-icon accent">🎯</div>
                <p class="card-title">Diversificación</p>
                <h2 class="card-value"><?= count(array_unique(array_column($portfolioData, 'sector'))) ?> sectores</h2>
                <p class="card-sub"><?= count($portfolioData) ?> posiciones activas</p>
            </div>
        </div>

        <!-- IBEX35 BANNER -->
        <div class="ibex-banner card fade-in" id="ibex-banner">
            <div class="ibex-left">
                <span class="ibex-flag">🇪🇸</span>
                <div class="ibex-info">
                    <span class="ibex-label">IBEX 35</span>
                    <span class="ibex-sublabel">Índice de referencia</span>
                </div>
            </div>
            <div class="ibex-center">
                <span class="ibex-price" id="ibex-price"><?= $ibexPrice > 0 ? number_format($ibexPrice, 2, ',', '.') : '---' ?></span>
                <span class="ibex-unit">pts</span>
            </div>
            <div class="ibex-right">
                <?php if ($ibexPrice > 0): ?>
                    <span class="ibex-change <?= $ibexUp ? 'up' : 'down' ?>" id="ibex-change">
                        <span class="ibex-change-icon"><?= $ibexUp ? '▲' : '▼' ?></span>
                        <span class="ibex-change-text"><?= ($ibexUp ? '+' : '') . number_format($ibexChg, 2) ?>%</span>
                    </span>
                    <span class="ibex-variation" id="ibex-variation"><?= ($ibexUp ? '+' : '') . number_format($ibexDiff, 2, ',', '.') ?> pts</span>
                <?php else: ?>
                    <span class="ibex-change" id="ibex-change">
                        <span class="ibex-change-icon">⏳</span>
                        <span class="ibex-change-text">Cargando...</span>
                    </span>
                    <span class="ibex-variation" id="ibex-variation"></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- CHARTS ROW -->
        <div class="charts-section">
            <div class="card fade-in">
                <div class="chart-header">
                    <h3>📈 Evolución del Portfolio</h3>
                    <div class="period-buttons" id="portfolio-periods">
                        <button class="period-btn" data-days="30">1M</button>
                        <button class="period-btn" data-days="90">3M</button>
                        <button class="period-btn active" data-days="180">6M</button>
                        <button class="period-btn" data-days="365">1A</button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="portfolioChart"></canvas>
                </div>
            </div>
            <div class="card fade-in">
                <div class="chart-header">
                    <h3>🥧 Distribución Sectorial</h3>
                </div>
                <div class="chart-container">
                    <canvas id="sectorChart"></canvas>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="table-container fade-in">
            <div class="table-header">
                <h3>📋 Posiciones del Portfolio</h3>
                <span class="text-muted" style="font-size: 0.8rem;"><?= count($portfolioData) ?> valores</span>
            </div>
            <table id="stock-table">
                <thead>
                    <tr>
                        <th data-sort="name">Valor <span class="sort-icon">↕</span></th>
                        <th class="text-right" data-sort="shares">Acciones</th>
                        <th class="text-right" data-sort="price">Precio</th>
                        <th class="text-right" data-sort="value">Valor</th>
                        <th class="text-right" data-sort="day">Día</th>
                        <th class="text-right" data-sort="ytd">YTD</th>

                        <th class="text-center">Análisis</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($portfolioData as $i => $row):
                        $d = $row['data'];
                        $clsYTD = $row['p_ytd'] >= 0 ? 'success' : 'danger';
                        $clsDia = $row['p_dia'] >= 0 ? 'success' : 'danger';
                        // Official short ticker: strip .MC, .MI suffixes
                        $shortTicker = preg_replace('/\.(MC|MI)$/i', '', $d['ticker']);
                        // Handle MFE-A special case
                        if ($shortTicker === 'MFE-A') $shortTicker = 'MFE';
                    ?>
                        <tr>
                            <td>
                                <div class="ticker-cell">
                                    <div class="ticker-logo" style="background: <?= $row['sector_color'] ?>22; color: <?= $row['sector_color'] ?>;">
                                        <?= strtoupper($shortTicker) ?>
                                    </div>
                                    <div class="ticker-info">
                                        <div class="name"><?= htmlspecialchars($d['nombre']) ?></div>
                                        <div class="symbol"><?= strtoupper($shortTicker) ?> · <?= $row['sector'] ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="text-right text-muted"><?= number_format($d['num_acciones'], 0, ',', '.') ?></td>
                            <td class="text-right fw-600 nowrap"><?= number_format($row['precio_actual'], 3, ',', '.') ?> €</td>
                            <td class="text-right fw-600 nowrap"><?= number_format($row['valor_total'], 0, ',', '.') ?> €</td>
                            <td class="text-right">
                                <span class="badge bg-<?= $clsDia ?>">
                                    <?= $row['p_dia'] >= 0 ? '▲' : '▼' ?> <?= number_format(abs($row['p_dia']), 2) ?>%
                                </span>
                            </td>
                            <td class="text-right">
                                <span class="badge bg-<?= $clsYTD ?>">
                                    <?= $row['p_ytd'] >= 0 ? '▲' : '▼' ?> <?= number_format(abs($row['p_ytd']), 2) ?>%
                                </span>
                            </td>

                            <td class="text-center">
                                <button class="btn btn-secondary btn-sm"
                                    data-ticker="<?= $d['ticker'] ?>"
                                    data-name="<?= htmlspecialchars($d['nombre']) ?>"
                                    data-price="<?= $row['precio_actual'] ?>"
                                    data-ref="<?= $d['precio_cierre_2025'] ?>"

                                    data-shares="<?= $d['num_acciones'] ?>"
                                    data-pe="<?= $d['pe_ratio'] ?>"
                                    data-target="<?= $d['target_price'] ?>"
                                    data-cap="<?= $d['market_cap'] ?>"
                                    data-div="<?= $d['dividend_yield'] ?>"
                                    data-beta="<?= $d['beta'] ?>"
                                    data-high52="<?= $d['fifty_two_week_high'] ?>"
                                    data-low52="<?= $d['fifty_two_week_low'] ?>"
                                    data-eps="<?= $d['eps'] ?>"
                                    data-pb="<?= $d['price_to_book'] ?>"
                                    data-vol="<?= $d['avg_volume'] ?>"
                                    data-history='<?= htmlspecialchars($d['history_json'] ?? '[]') ?>'
                                    data-sector="<?= $row['sector'] ?>"
                                    data-sector-color="<?= $row['sector_color'] ?>"
                                    onclick="handleAnalysis(this)">
                                    📊 Ver
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
            <button class="close-btn" onclick="closeModal()">✕</button>

            <div class="modal-header">
                <span class="ticker-badge" id="modal-ticker">---</span>
                <div>
                    <div class="stock-name" id="modal-name">---</div>
                    <div class="text-muted" id="modal-sector" style="font-size: 0.8rem;">---</div>
                </div>
                <div style="margin-left:auto; text-align:right;">
                    <div class="stock-price" id="modal-price">---</div>
                    <div id="modal-change" class="badge" style="font-size:0.8rem;">---</div>
                </div>
            </div>

            <div class="analysis-grid">
                <!-- LEFT: Chart -->
                <div>
                    <div class="card" style="margin-bottom:1.25rem;">
                        <div class="chart-header">
                            <h3 style="font-size:0.9rem;">Historial de Precios</h3>
                            <div class="period-buttons" id="modal-periods">
                                <button class="period-btn" data-days="30" onclick="filterChart(30)">1M</button>
                                <button class="period-btn" data-days="90" onclick="filterChart(90)">3M</button>
                                <button class="period-btn active" data-days="180" onclick="filterChart(180)">6M</button>
                                <button class="period-btn" data-days="365" onclick="filterChart(365)">1A</button>
                            </div>
                        </div>
                        <div class="chart-container"><canvas id="historyChart"></canvas></div>
                    </div>

                    <!-- 52 Week Range -->
                    <div class="card" id="range-card" style="display:none;">
                        <h3 style="font-size: 0.9rem; margin-bottom: 0.75rem;">Rango 52 Semanas</h3>
                        <div class="range-bar-container">
                            <div class="range-bar">
                                <div class="fill" id="range-fill"></div>
                                <div class="marker" id="range-marker"></div>
                            </div>
                            <div class="range-labels">
                                <span id="range-low">---</span>
                                <span id="range-position" style="color:var(--accent-light); font-weight:600;">---</span>
                                <span id="range-high">---</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Fundamentals + Diagnosis -->
                <div>
                    <div class="card" style="margin-bottom: 1rem;">
                        <h3 style="font-size: 0.9rem; margin-bottom: 0.75rem;">Datos Fundamentales</h3>
                        <ul class="fundamental-list" id="fundamental-list">
                            <li><span>PER</span><span id="m-per">-</span></li>
                            <li><span>BPA (EPS)</span><span id="m-eps">-</span></li>
                            <li><span>Precio Objetivo</span><span id="m-target">-</span></li>
                            <li><span>Capitalización</span><span id="m-cap">-</span></li>
                            <li><span>Dividendo</span><span id="m-div">-</span></li>
                            <li><span>Beta</span><span id="m-beta">-</span></li>
                            <li><span>P/B Ratio</span><span id="m-pb">-</span></li>
                            <li><span>Vol. Medio</span><span id="m-vol">-</span></li>
                        </ul>
                    </div>
                    <div class="card" style="margin-bottom: 1rem;">
                        <h3 style="font-size: 0.9rem; margin-bottom: 0.5rem;">📊 Resumen Posición</h3>
                        <ul class="fundamental-list" id="investment-list">
                            <li><span>Acciones</span><span id="m-shares">-</span></li>
                            <li><span>Valor Actual</span><span id="m-current">-</span></li>
                            <li><span>Cierre 2025</span><span id="m-ref">-</span></li>
                            <li><span>Rendimiento YTD</span><span id="m-ytd">-</span></li>
                            <li><span>Peso en Cartera</span><span id="m-weight">-</span></li>
                        </ul>
                    </div>
                </div>
            </div>
            <!-- FULL WIDTH: Analysis & Recommendation -->
            <div class="diagnostic-box" id="decision-box">
                <h4>🎯 Cargando análisis...</h4>
            </div>
        </div>
    </div>

    <!-- SECTOR DATA FOR JS -->
    <script>
        var SECTOR_DATA = <?= json_encode(array_map(function ($k, $v) {
                                return ['name' => $k, 'value' => round($v['valor'], 2), 'color' => $v['color']];
                            }, array_keys($sectorDistrib), array_values($sectorDistrib))) ?>;

        var ALL_STOCKS_HISTORY = {};
        <?php foreach ($valores as $val): ?>
            ALL_STOCKS_HISTORY['<?= $val['ticker'] ?>'] = <?= $val['history_json'] ?: '[]' ?>;
        <?php endforeach; ?>
    </script>
    <script src="script.js"></script>
</body>

</html>