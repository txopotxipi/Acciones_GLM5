<?php
// ==========================================
// CONFIGURACIÓN DE BASE DE DATOS
// ==========================================
 $host = 'sql308.infinityfree.com';
 $db   = 'if0_37663314_acciones_glm5';
 $user = 'if0_37663314';
 $pass = 'jorNnRX4Ml7VH';
 $charset = 'utf8mb4';

 $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
 $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

 $portfolioData = [];
 $valorTotalCartera = 0;
 $gananciaYTDTotal = 0;
 $gananciaDiaTotal = 0; // Nueva variable para el acumulado del día
 $valorCierreDiaAnterior = 0; // Para calcular el % del día
 $errorConexion = false;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // 1. Comprobar si necesitamos buscar datos históricos de 2025
    $stmtCheck = $pdo->query("SELECT COUNT(*) as total FROM valores WHERE precio_cierre_2025 = 0 OR precio_cierre_2025 IS NULL");
    $resultadoCheck = $stmtCheck->fetch();
    $needsUpdate = $resultadoCheck['total'] > 0;

    // Función MEJORADA para obtener Precio Actual y Variación Diaria
    function obtenerDatosYahoo($ticker) {
        // Pedimos rango de 2 días para obtener el cierre anterior y el actual
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/$ticker?interval=1d&range=2d";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        curl_close($ch);
        
        $json = json_decode($data, true);
        
        $meta = $json['chart']['result'][0]['meta'] ?? null;
        $indicators = $json['chart']['result'][0]['indicators']['quote'][0] ?? null;
        
        $return = [
            'price' => false,
            'previous_close' => false,
            'change_pct' => 0
        ];

        // Obtener precio actual desde META (más fiable para realtime)
        if (isset($meta['regularMarketPrice'])) {
            $return['price'] = $meta['regularMarketPrice'];
            
            // Obtener cierre anterior. Yahoo a veces lo da en meta, si no, buscamos en el histórico
            if (isset($meta['chartPreviousClose'])) {
                $return['previous_close'] = $meta['chartPreviousClose'];
            } elseif ($indicators && isset($indicators['close'])) {
                // Filtrar valores null y coger el penúltimo si existe
                $closes = array_filter($indicators['close'], function($v) { return !is_null($v); });
                if (count($closes) > 1) {
                    $return['previous_close'] = end($closes); // El cierre anterior al actual
                }
            }
            
            // Calcular porcentaje día
            if ($return['previous_close'] > 0 && $return['price'] > 0) {
                $return['change_pct'] = (($return['price'] - $return['previous_close']) / $return['previous_close']) * 100;
            }
        }
        
        return $return;
    }

    // Función para obtener precio HISTÓRICO (por si falta alguno en BD)
    function obtenerHistoricoYahoo($ticker) {
        $start = "1735516800"; // 30 Dic 2025
        $end = "1735689600";   // 2 Ene 2026
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/$ticker?period1=$start&period2=$end&interval=1d";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($data, true);
        return $json['chart']['result'][0]['indicators']['quote'][0]['close'][0] ?? false;
    }

    // 2. Lógica de Auto-Actualización YTD
    if ($needsUpdate) {
        $stmtTickers = $pdo->query("SELECT id, ticker_yahoo FROM valores WHERE precio_cierre_2025 = 0 OR precio_cierre_2025 IS NULL");
        $tickersToUpdate = $stmtTickers->fetchAll();
        
        if (count($tickersToUpdate) > 0) {
            foreach ($tickersToUpdate as $t) {
                $precioHist = obtenerHistoricoYahoo($t['ticker_yahoo']);
                if ($precioHist && $precioHist > 0) {
                    $updateStmt = $pdo->prepare("UPDATE valores SET precio_cierre_2025 = ? WHERE id = ?");
                    $updateStmt->execute([$precioHist, $t['id']]);
                }
            }
        }
    }

    // 3. Obtener datos y calcular
    $stmt = $pdo->query("SELECT * FROM valores");
    $valoresBD = $stmt->fetchAll();

    foreach ($valoresBD as $val) {
        $datos = obtenerDatosYahoo($val['ticker_yahoo']);
        
        $precioActual = $datos['price'];
        $precioCierreAnterior = $datos['previous_close'];
        $pctCambioDia = $datos['change_pct'];
        
        $isRealTime = true;
        if ($precioActual === false) {
            $precioActual = $val['precio_cierre_2025'] ?? $val['precio_compra_medio'];
            $isRealTime = false;
            $pctCambioDia = 0;
        }

        // Cálculos Financieros
        $valorPosicion = $val['num_acciones'] * $precioActual;
        
        // --- CALCULO DÍA ---
        // Ganancia del día = (Precio Actual - Cierre Ayer) * Cantidad
        // Si no tenemos cierre anterior, asumimos 0
        $gananciaDia = 0;
        if ($precioCierreAnterior > 0) {
            $gananciaDia = ($precioActual - $precioCierreAnterior) * $val['num_acciones'];
        }
        
        // --- CALCULO YTD ---
        $precioRefYTD = $val['precio_cierre_2025'];
        if ($precioRefYTD == 0) $precioRefYTD = $val['precio_compra_medio']; 
        
        $gananciaYTD = ($precioActual - $precioRefYTD) * $val['num_acciones'];
        $porcentajeYTD = ($precioRefYTD > 0) ? (($precioActual - $precioRefYTD) / $precioRefYTD) * 100 : 0;
        
        // Acumuladores
        $valorTotalCartera += $valorPosicion;
        $gananciaYTDTotal += $gananciaYTD;
        $gananciaDiaTotal += $gananciaDia;
        if($precioCierreAnterior > 0) {
            $valorCierreDiaAnterior += ($precioCierreAnterior * $val['num_acciones']);
        }
        
        $portfolioData[] = [
            'ticker' => $val['ticker'],
            'nombre' => $val['nombre'],
            'num_acciones' => $val['num_acciones'],
            'precio_actual' => $precioActual,
            'valor_total' => $valorPosicion,
            'ganancia_ytd' => $gananciaYTD,
            'porcentaje_ytd' => $porcentajeYTD,
            'realtime' => $isRealTime,
            'pct_dia' => $pctCambioDia,
            'ganancia_dia' => $gananciaDia
        ];
    }

} catch (Exception $e) {
    $errorConexion = true;
    $errorMessage = $e->getMessage();
}

function formatearEuro($num) {
    return number_format($num, 2, ',', '.') . ' €';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Cartera Pro | Dashboard 2026</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #0f172a; --bg-card: rgba(30, 41, 59, 0.7); --border-card: rgba(255,255,255,0.05);
            --accent: #6366f1; --success: #10b981; --danger: #ef4444;
            --text-main: #f1f5f9; --text-muted: #94a3b8;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-body); color: var(--text-main); min-height: 100vh; padding: 2rem; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; flex-wrap: wrap; gap: 1rem; }
        h1 { font-size: 2rem; font-weight: 800; }
        .highlight { color: var(--accent); }
        
        .btn-group { display: flex; gap: 0.5rem; }
        .btn { padding: 0.75rem 1.5rem; border-radius: 0.5rem; text-decoration: none; font-weight: 600; font-size: 0.875rem; transition: transform 0.2s; border: none; }
        .btn-refresh { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; }
        .btn:hover { transform: translateY(-2px); }

        /* Dashboard Grid: Ahora con 4 columnas si cabe, o auto-fit */
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .card { background: var(--bg-card); backdrop-filter: blur(12px); border: 1px solid var(--border-card); border-radius: 1rem; padding: 1.5rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3); position: relative; overflow: hidden; }
        
        /* Efecto decorativo sutil en las tarjetas principales */
        .card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; opacity: 0.5; }
        .card-market::before { background: var(--accent); }
        .card-day::before { background: var(--success); } /* Cambiar dinámicamente con JS si se quiere */
        .card-ytd::before { background: var(--accent); }

        .card-title { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; }
        .card-value { font-size: 1.9rem; font-weight: 700; line-height: 1.2; }
        .card-sub { font-size: 0.85rem; margin-top: 0.5rem; color: var(--text-muted); }
        
        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        
        /* Small badges inside cards */
        .badge-sub { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600; margin-top: 0.5rem; }
        .badge-sub-success { background: rgba(16, 185, 129, 0.15); color: var(--success); }
        .badge-sub-danger { background: rgba(239, 68, 68, 0.15); color: var(--danger); }

        /* Table */
        .table-container { overflow-x: auto; background: var(--bg-card); border-radius: 1rem; border: 1px solid var(--border-card); }
        table { width: 100%; border-collapse: collapse; min-width: 800px; } /* Aumentado min-width por nueva data */
        th { background: rgba(15, 23, 42, 0.6); padding: 1rem 1.25rem; text-align: left; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; }
        td { padding: 1rem 1.25rem; border-top: 1px solid var(--border-card); font-variant-numeric: tabular-nums; }
        tr:hover { background: rgba(255,255,255,0.02); }
        
        .ticker-name { font-weight: 600; color: white; }
        .ticker-sub { font-size: 0.75rem; color: var(--text-muted); }
        
        /* Daily change indicator next to price */
        .price-wrapper { display: flex; flex-direction: column; align-items: flex-end; }
        .price-main { font-weight: 600; font-size: 1rem; }
        .price-daily { font-size: 0.75rem; margin-top: 2px; font-weight: 500; }

        /* General badges for table */
        .badge { padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; display: inline-block; min-width: 70px; text-align: center; }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }

        @media (max-width: 640px) {
            h1 { font-size: 1.5rem; }
            .btn { padding: 0.5rem 1rem; font-size: 0.8rem; }
            .card-value { font-size: 1.5rem; }
            .dashboard-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                <h1>Mi Cartera <span class="highlight">Pro</span></h1>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Panel de Control en Tiempo Real</p>
            </div>
            <div class="btn-group">
                <a href="?refresh=true" class="btn btn-refresh">● Actualizar Precios</a>
            </div>
        </header>

        <?php if ($errorConexion): ?>
            <div class="card" style="background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); margin-bottom: 2rem;">
                <p class="text-danger">Error: <?= htmlspecialchars($errorMessage) ?></p>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <!-- Valor de Mercado -->
            <div class="card card-market">
                <p class="card-title">Valor de Mercado</p>
                <h2 class="card-value"><?= formatearEuro($valorTotalCartera) ?></h2>
            </div>
            
            <!-- Sesión Hoy (NUEVO) -->
            <div class="card card-day">
                <p class="card-title">📊 Sesión Hoy</p>
                <h2 class="card-value <?= $gananciaDiaTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $gananciaDiaTotal >= 0 ? '+' : '' ?><?= formatearEuro($gananciaDiaTotal) ?>
                </h2>
                <?php 
                $pctDia = ($valorCierreDiaAnterior > 0) ? ($gananciaDiaTotal / $valorCierreDiaAnterior) * 100 : 0;
                $clsDia = $pctDia >= 0 ? 'success' : 'danger';
                ?>
                <div class="badge-sub badge-sub-<?= $clsDia ?>">
                    <?= $pctDia >= 0 ? '+' : '' ?><?= number_format($pctDia, 2) ?>% hoy
                </div>
            </div>

            <!-- Ganancia YTD -->
            <div class="card card-ytd">
                <p class="card-title">Ganancia YTD 2026</p>
                <h2 class="card-value <?= $gananciaYTDTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $gananciaYTDTotal >= 0 ? '+' : '' ?><?= formatearEuro($gananciaYTDTotal) ?>
                </h2>
                <p class="card-sub">vs Cierre 30 Dic 2025</p>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Valor</th>
                        <th style="text-align:right;">Cantidad</th>
                        <!-- P. Actual ahora incluye variación día -->
                        <th style="text-align:right;">P. Actual</th> 
                        <th style="text-align:right;">Valor</th>
                        <th style="text-align:right;">Reval. YTD</th>
                        <th style="text-align:right;">% YTD</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($portfolioData as $row): 
                        $clsYTD = $row['porcentaje_ytd'] >= 0 ? 'success' : 'danger';
                        $clsDia = $row['pct_dia'] >= 0 ? 'success' : 'danger';
                    ?>
                    <tr>
                        <td>
                            <div class="ticker-name"><?= $row['ticker'] ?></div>
                            <div class="ticker-sub"><?= $row['nombre'] ?></div>
                        </td>
                        <td style="text-align:right;"><?= number_format($row['num_acciones'], 0) ?></td>
                        
                        <!-- COLUMNA MODIFICADA: P. Actual + % Día -->
                        <td style="text-align:right;">
                            <div class="price-wrapper">
                                <span class="price-main"><?= number_format($row['precio_actual'], 2) ?> €</span>
                                <span class="price-daily text-<?= $clsDia ?>">
                                    (<?= $row['pct_dia'] >= 0 ? '+' : '' ?><?= number_format($row['pct_dia'], 2) ?>%)
                                </span>
                            </div>
                        </td>
                        
                        <td style="text-align:right;"><?= formatearEuro($row['valor_total']) ?></td>
                        <td style="text-align:right;" class="text-<?= $clsYTD ?>">
                            <?= $row['ganancia_ytd'] >= 0 ? '+' : '' ?><?= formatearEuro($row['ganancia_ytd']) ?>
                        </td>
                        <td style="text-align:right;">
                            <span class="badge badge-<?= $clsYTD ?>">
                                <?= $row['porcentaje_ytd'] >= 0 ? '+' : '' ?><?= number_format($row['porcentaje_ytd'], 2) ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>