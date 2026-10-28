<?php
// index.php — Cartera Pro GLM5 v3
// Forzar a navegadores móviles y de escritorio a no cachear el HTML inicial
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');

require_once 'config.php';

// json_ronda — elimina el ruido binario de coma flotante antes de serializar.// Sin esto, PHP emite literales tipo 323988.739999999990686… (14+ dígitos)
// por cada float acumulado, inflando el HTML inline en cientos de KB.
function roundFloats($x, $dec = 4) {
    if (is_float($x)) return round($x, $dec);
    if (is_int($x) || is_string($x) || $x === null || is_bool($x)) return $x;
    if (is_array($x)) return array_map(fn($v) => roundFloats($v, $dec), $x);
    return $x;
}

function cleanDbJson($raw) {
    if (empty($raw)) return '[]';
    if (is_array($raw)) return json_encode($raw, JSON_UNESCAPED_SLASHES);
    $str = (string)$raw;
    if (strpos($str, '\"') !== false || strpos($str, '\\\\') !== false) {
        $str = stripslashes($str);
    }
    $dec = json_decode($str, true);
    if (is_string($dec)) {
        $dec = json_decode(stripslashes($dec), true);
    }
    return is_array($dec) ? json_encode(roundFloats($dec), JSON_UNESCAPED_SLASHES) : '[]';
}

$cachedData = [];
if (file_exists(__DIR__ . '/cache_data.json')) {
    $cachedData = json_decode(file_get_contents(__DIR__ . '/cache_data.json'), true) ?: [];
}

$stmt = $pdo->query("SELECT * FROM valores WHERE num_acciones > 0 ORDER BY (num_acciones * precio_actual) DESC");
$valores = $stmt->fetchAll();

// IBEX35 data from DB (full row including YTD reference + history for Base 100)
$ibexStmt = $pdo->query("SELECT precio_actual, precio_cierre_anterior, precio_cierre_2025, history_json FROM valores WHERE ticker = '^IBEX' LIMIT 1");
$ibexData = $ibexStmt->fetch();
$ibexPrice = $ibexData ? (float)$ibexData['precio_actual'] : 0;
$ibexPrev = $ibexData ? (float)$ibexData['precio_cierre_anterior'] : 0;

if (!empty($cachedData['ibex']['price']) && (float)$cachedData['ibex']['price'] > 0) {
    $ibexPrice = (float)$cachedData['ibex']['price'];
    if (!empty($cachedData['ibex']['previous_close'])) {
        $ibexPrev = (float)$cachedData['ibex']['previous_close'];
    }
}

$ibexHistory = ($ibexData && !empty($ibexData['history_json'])) ? json_decode(cleanDbJson($ibexData['history_json']), true) : [];
if (!is_array($ibexHistory)) $ibexHistory = [];
if (empty($ibexHistory) && !empty($cachedData['ibex_history'])) {
    $ibexHistory = $cachedData['ibex_history'];
}

// Sanity check: si el cierre anterior es anómalo (>12% de desviación por desfase en BD), usar sesión previa
if ($ibexPrev <= 0 || ($ibexPrice > 0 && abs($ibexPrice - $ibexPrev) / $ibexPrev > 0.12)) {
    if (!empty($ibexHistory) && count($ibexHistory) >= 2) {
        $ibexPrev = (float)$ibexHistory[count($ibexHistory) - 2]['y'];
    } else {
        $ibexPrev = $ibexPrice;
    }
}

$ibexChg = ($ibexPrev > 0) ? (($ibexPrice - $ibexPrev) / $ibexPrev * 100) : 0;
$ibexDiff = $ibexPrice - $ibexPrev;
$ibexUp = $ibexChg >= 0;
$ibexCierre2025 = ($ibexData && $ibexData['precio_cierre_2025'] > 0) ? (float)$ibexData['precio_cierre_2025'] : 17180.0;
$ibexYTD = ($ibexCierre2025 > 0) ? (($ibexPrice - $ibexCierre2025) / $ibexCierre2025 * 100) : 0;
$ibexYTDUp = $ibexYTD >= 0;
if (empty($ibexHistory)) {
    try {
        $stmtIbexH = $pdo->query("SELECT date, price FROM ibex35_price_history ORDER BY date ASC");
        $rowsH = $stmtIbexH ? $stmtIbexH->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rowsH as $rH) {
            $dateVal = $rH['date'] ?? ($rH['fecha'] ?? null);
            $ts = $dateVal ? strtotime($dateVal) : (is_numeric($rH['x'] ?? null) ? (int)$rH['x'] : null);
            $priceVal = (float)($rH['price'] ?? ($rH['y'] ?? 0));
            if ($ts && $priceVal > 0) {
                $ibexHistory[] = ['x' => $ts > 1e11 ? $ts : $ts * 1000, 'y' => $priceVal, 'date' => $dateVal ?: date('Y-m-d', $ts)];
            }
        }
    } catch (Exception $e) {}
}

$valorTotalCartera = 0;
$gananciaYTDTotal = 0;
$gananciaDiaTotal = 0;
$totalCapitalInicio2026 = 0;
$totalValorDiaAnterior = 0;

// v3: P&L real y dividendos
$costeTotalInvertido = 0;
$posicionesConCoste = 0;
$dividendosAnuales = 0;
$betaPonderada = 0;
$betasDisponibles = 0;

$portfolioData = [];

// Sectores: prioridad a la columna de BD (editable en gestionar_cartera.php), fallback al mapa
$sectoresFallback = [
    'IBE.MC' => ['sector' => 'Energía', 'color' => '#10b981'],
    'TEF.MC' => ['sector' => 'Telecomunicaciones', 'color' => '#3b82f6'],
    'ELE.MC' => ['sector' => 'Energía', 'color' => '#10b981'],
    'BBVA.MC' => ['sector' => 'Banca', 'color' => '#f59e0b'],
    'REP.MC' => ['sector' => 'Petróleo', 'color' => '#ef4444'],
    'MFE-A' => ['sector' => 'Media', 'color' => '#8b5cf6'],
    'MFEA.MI' => ['sector' => 'Media', 'color' => '#8b5cf6'],
    'A3M.MC' => ['sector' => 'Media', 'color' => '#8b5cf6'],
];
$sectorColors = [
    'Energía' => '#10b981', 'Telecomunicaciones' => '#3b82f6', 'Banca' => '#f59e0b',
    'Petróleo' => '#ef4444', 'Media' => '#8b5cf6', 'Tecnología' => '#06b6d4',
    'Construcción' => '#f97316', 'Utilities' => '#22c55e', 'Otro' => '#64748b',
];

foreach ($valores as $val) {
    $t = $val['ticker'];
    $tY = $val['ticker_yahoo'] ?: $t;

    $precioActual = (float)$val['precio_actual'];
    $precioCierreAnterior = (float)$val['precio_cierre_anterior'];
    $precioCierre2025 = (float)($val['precio_cierre_2025'] ?? 0);

    if (!empty($cachedData['stocks'][$tY]['price']) && (float)$cachedData['stocks'][$tY]['price'] > 0) {
        $precioActual = (float)$cachedData['stocks'][$tY]['price'];
        if (!empty($cachedData['stocks'][$tY]['previous_close'])) {
            $precioCierreAnterior = (float)$cachedData['stocks'][$tY]['previous_close'];
        }
        if (!empty($cachedData['stocks'][$tY]['close_2025'])) {
            $precioCierre2025 = (float)$cachedData['stocks'][$tY]['close_2025'];
        }
    } elseif (!empty($cachedData['stocks'][$t]['price']) && (float)$cachedData['stocks'][$t]['price'] > 0) {
        $precioActual = (float)$cachedData['stocks'][$t]['price'];
        if (!empty($cachedData['stocks'][$t]['previous_close'])) {
            $precioCierreAnterior = (float)$cachedData['stocks'][$t]['previous_close'];
        }
        if (!empty($cachedData['stocks'][$t]['close_2025'])) {
            $precioCierre2025 = (float)$cachedData['stocks'][$t]['close_2025'];
        }
    }

    if ($precioActual <= 0) $precioActual = ($precioCierre2025 > 0 ? $precioCierre2025 : (float)$val['precio_cierre_2025']);
    if ($precioCierre2025 <= 0) $precioCierre2025 = (float)$val['precio_cierre_2025'];
    
    // Sanity check: si el cierre anterior es anómalo (>12% de desviación por desfase en BD), usar sesión previa
    if ($precioCierreAnterior <= 0 || ($precioActual > 0 && abs($precioActual - $precioCierreAnterior) / $precioCierreAnterior > 0.12)) {
        $hArr = json_decode(cleanDbJson($val['history_json'] ?? ''), true);
        if (is_array($hArr) && count($hArr) >= 2) {
            $precioCierreAnterior = (float)$hArr[count($hArr) - 2]['y'];
        } else {
            $precioCierreAnterior = $precioActual;
        }
    }

    $valorPosicion = $val['num_acciones'] * $precioActual;
    $gananciaYTD = ($precioActual - $precioCierre2025) * $val['num_acciones'];
    $porcentajeYTD = ($precioCierre2025 > 0) ? (($precioActual - $precioCierre2025) / $precioCierre2025) * 100 : 0;

    $gananciaDia = ($precioActual - $precioCierreAnterior) * $val['num_acciones'];
    $porcentajeDia = ($precioCierreAnterior > 0) ? (($precioActual - $precioCierreAnterior) / $precioCierreAnterior) * 100 : 0;

    $valorTotalCartera += $valorPosicion;
    $gananciaYTDTotal += $gananciaYTD;
    $gananciaDiaTotal += $gananciaDia;
    $totalCapitalInicio2026 += ($precioCierre2025 * $val['num_acciones']);
    $totalValorDiaAnterior += ($precioCierreAnterior * $val['num_acciones']);

    // v3: coste medio → P&L real
    $costeMedio = (float)($val['precio_medio_compra'] ?? 0);
    $pnlReal = null; $pnlRealPct = null; $costePosicion = null;
    if ($costeMedio > 0) {
        $costePosicion = $costeMedio * $val['num_acciones'];
        $pnlReal = $valorPosicion - $costePosicion;
        $pnlRealPct = $costePosicion > 0 ? ($pnlReal / $costePosicion) * 100 : 0;
        $costeTotalInvertido += $costePosicion;
        $posicionesConCoste++;
    }

    // v3: dividendos estimados anuales (dividend_yield guardado en %)
    $divYield = (float)($val['dividend_yield'] ?? 0);
    $divAnualPosicion = $divYield > 0 ? $valorPosicion * $divYield / 100 : 0;
    $dividendosAnuales += $divAnualPosicion;

    // v3: beta ponderada
    $betaVal = (float)($val['beta'] ?? 0);
    if ($betaVal != 0) { $betaPonderada += $betaVal * $valorPosicion; $betasDisponibles += $valorPosicion; }

    // Sector: BD primero, fallback al mapa
    if (!empty($val['sector'])) {
        $sectorInfo = ['sector' => $val['sector'], 'color' => !empty($val['sector_color']) ? $val['sector_color'] : ($sectorColors[$val['sector']] ?? '#64748b')];
    } else {
        $sectorInfo = $sectoresFallback[$val['ticker']] ?? ['sector' => 'Otro', 'color' => '#64748b'];
    }

    $portfolioData[] = [
        'data' => $val,
        'precio_actual' => $precioActual,
        'precio_prev' => $precioCierreAnterior,
        'precio_cierre_2025' => $precioCierre2025,
        'valor_total' => $valorPosicion,
        'p_ytd' => $porcentajeYTD,
        'p_dia' => $porcentajeDia,
        'coste_medio' => $costeMedio,
        'pnl_real' => $pnlReal,
        'pnl_real_pct' => $pnlRealPct,
        'div_anual' => $divAnualPosicion,
        'sector' => $sectorInfo['sector'],
        'sector_color' => $sectorInfo['color'],
    ];
}

$porcentajeYTDTotal = ($totalCapitalInicio2026 > 0) ? ($gananciaYTDTotal / $totalCapitalInicio2026) * 100 : 0;
$pctTotalDia = ($totalValorDiaAnterior > 0) ? ($gananciaDiaTotal / $totalValorDiaAnterior) * 100 : 0;

// v3: P&L total real
$pnlTotal = $costeTotalInvertido > 0 ? $valorTotalCartera - $costeTotalInvertido : null;
$pnlTotalPct = ($pnlTotal !== null && $costeTotalInvertido > 0) ? ($pnlTotal / $costeTotalInvertido) * 100 : null;
$betaPondFinal = $betasDisponibles > 0 ? $betaPonderada / $betasDisponibles : null;
$divYieldCartera = $valorTotalCartera > 0 ? ($dividendosAnuales / $valorTotalCartera) * 100 : 0;

// Alpha: Portfolio vs IBEX (YTD and day)
$alphaYTD = $porcentajeYTDTotal - $ibexYTD;
$alphaYTDUp = $alphaYTD >= 0;
$alphaDia = $pctTotalDia - $ibexChg;
$alphaDiaUp = $alphaDia >= 0;

// Pesos y distribución sectorial
foreach ($portfolioData as &$row) {
    $row['peso'] = $valorTotalCartera > 0 ? ($row['valor_total'] / $valorTotalCartera) * 100 : 0;
}
unset($row);

$sectorDistrib = [];
foreach ($portfolioData as $row) {
    $s = $row['sector'];
    if (!isset($sectorDistrib[$s]))
        $sectorDistrib[$s] = ['valor' => 0, 'color' => $row['sector_color']];
    $sectorDistrib[$s]['valor'] += $row['valor_total'];
}

// v3: métricas de riesgo e histórico real del portfolio
$portfolioHistory = [];

// 1. Cargar histórico fiel desde caché si está disponible
$cacheFile = __DIR__ . '/cache_data.json';
if (file_exists($cacheFile)) {
    $cachedData = json_decode(file_get_contents($cacheFile), true);
    if (!empty($cachedData['portfolio_history']) && count($cachedData['portfolio_history']) >= 10) {
        $portfolioHistory = $cachedData['portfolio_history'];
    }
}

// 2. Reconstruir agregando sumas de history_json si la caché no está disponible
if (empty($portfolioHistory)) {
    $dailyTotals = [];
    foreach ($valores as $v) {
        $shares = (float)($v['num_acciones'] ?? 0);
        if ($shares <= 0 || empty($v['history_json'])) continue;
        $hist = json_decode(cleanDbJson($v['history_json'] ?? ''), true);
        if (!is_array($hist)) continue;
        foreach ($hist as $pt) {
            $ts = is_numeric($pt['x']) ? (int)$pt['x'] : strtotime($pt['x']);
            if ($ts < 1e11) $ts = $ts * 1000;
            $dayKey = date('Y-m-d', (int)($ts / 1000));
            $p = (float)($pt['y'] ?? 0);
            if ($p > 0) {
                if (!isset($dailyTotals[$dayKey])) {
                    $dailyTotals[$dayKey] = ['x' => $ts, 'total' => 0, 'count' => 0];
                }
                $dailyTotals[$dayKey]['total'] += ($p * $shares);
                $dailyTotals[$dayKey]['count']++;
            }
        }
    }
    if (!empty($dailyTotals)) {
        ksort($dailyTotals);
        foreach ($dailyTotals as $dayKey => $dt) {
            if ($dt['total'] > 0 && $dt['count'] >= 3) {
                $portfolioHistory[] = [
                    'x' => $dt['x'],
                    'y' => round($dt['total'], 2),
                    'date' => $dayKey
                ];
            }
        }
    }
}

// 3. Fallback: Reconstruir desde stock_historical_data
if (empty($portfolioHistory)) {
    try {
        $stmtH = $pdo->query("
            SELECT s.date, SUM(s.close_price * v.num_acciones) AS total_val
            FROM stock_historical_data s
            JOIN valores v ON (
                v.ticker = CONCAT(s.symbol, '.MC') 
                OR v.ticker = CONCAT(s.symbol, '.MI')
                OR v.ticker = s.symbol
                OR (s.symbol = 'MFEA' AND v.ticker = 'MFEA.MI')
                OR (s.symbol = 'IBE' AND v.ticker = 'IBE.MC')
                OR (s.symbol = 'ELE' AND v.ticker = 'ELE.MC')
                OR (s.symbol = 'BBVA' AND v.ticker = 'BBVA.MC')
                OR (s.symbol = 'TEF' AND v.ticker = 'TEF.MC')
                OR (s.symbol = 'REP' AND v.ticker = 'REP.MC')
                OR (s.symbol = 'A3M' AND v.ticker = 'A3M.MC')
            )
            WHERE v.num_acciones > 0
            GROUP BY s.date
            HAVING COUNT(DISTINCT s.symbol) >= 3
            ORDER BY s.date ASC
        ");
        $rowsH = $stmtH ? $stmtH->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rowsH as $rH) {
            $dateVal = $rH['date'] ?? ($rH['fecha'] ?? null);
            $ts = $dateVal ? strtotime($dateVal) : (is_numeric($rH['x'] ?? null) ? (int)$rH['x'] : null);
            $valPos = (float)($rH['total_val'] ?? ($rH['y'] ?? 0));
            if ($ts && $valPos > 0) {
                $portfolioHistory[] = [
                    'x' => $ts > 1e11 ? $ts : $ts * 1000,
                    'y' => round($valPos, 2),
                    'date' => $dateVal ?: date('Y-m-d', $ts)
                ];
            }
        }
    } catch (Exception $e) {}
}

// Añadir punto en tiempo real de la sesión de hoy
if (!empty($portfolioHistory) && $valorTotalCartera > 0) {
    $todayDate = date('Y-m-d');
    $lastIdx = count($portfolioHistory) - 1;
    $lastDate = $portfolioHistory[$lastIdx]['date'] ?? (isset($portfolioHistory[$lastIdx]['x']) ? date('Y-m-d', (int)($portfolioHistory[$lastIdx]['x'] > 1e11 ? $portfolioHistory[$lastIdx]['x']/1000 : $portfolioHistory[$lastIdx]['x'])) : '');
    if ($lastDate !== $todayDate) {
        $portfolioHistory[] = [
            'x' => time() * 1000,
            'y' => round($valorTotalCartera, 2),
            'date' => $todayDate
        ];
    } else {
        $portfolioHistory[$lastIdx]['y'] = round($valorTotalCartera, 2);
    }
}
$volCartera = null; $maxDrawdown = null; $hhi = 0; $rentTotal = null;
if (count($portfolioHistory) > 30) {
    $vals = array_column($portfolioHistory, 'y');
    $rets = [];
    for ($i = 1; $i < count($vals); $i++) {
        if ($vals[$i-1] > 0) $rets[] = ($vals[$i] - $vals[$i-1]) / $vals[$i-1];
    }
    if (count($rets) > 10) {
        $mean = array_sum($rets) / count($rets);
        $varSum = 0;
        foreach ($rets as $r) $varSum += pow($r - $mean, 2);
        $volCartera = sqrt($varSum / count($rets)) * sqrt(252) * 100;
    }
    $peak = $vals[0]; $mdd = 0;
    foreach ($vals as $v) { $peak = max($peak, $v); $mdd = min($mdd, ($v - $peak) / $peak * 100); }
    $maxDrawdown = $mdd;
    if ($vals[0] > 0) $rentTotal = ($vals[count($vals)-1] - $vals[0]) / $vals[0] * 100;

    // Rentabilidad 1 año (últimos 12 meses / 365 días)
    $rent1A = null;
    $nowTs = time();
    $cutoff1Y = $nowTs - (365 * 86400);
    $pt1Y = null;
    foreach ($portfolioHistory as $pt) {
        $pts = is_numeric($pt['x']) ? (int)($pt['x'] > 1e11 ? $pt['x']/1000 : $pt['x']) : strtotime($pt['x']);
        if ($pts >= $cutoff1Y) {
            $pt1Y = $pt;
            break;
        }
    }
    if ($pt1Y && (float)$pt1Y['y'] > 0 && $valorTotalCartera > 0) {
        $rent1A = ($valorTotalCartera - (float)$pt1Y['y']) / (float)$pt1Y['y'] * 100;
    }
}
foreach ($portfolioData as $row) { $hhi += pow($row['peso'], 2); }

// v3: CENTRO DE DECISIONES — alertas automáticas con datos reales
$alertas = [];

// 1. Concentración
$top = $portfolioData[0] ?? null;
if ($top && $top['peso'] > 40) {
    $alertas[] = [
        'nivel' => 'danger', 'icono' => '⚠️',
        'titulo' => 'Concentración alta: ' . $top['data']['nombre'] . ' ' . number_format($top['peso'], 1, ',', '.') . '%',
        'texto' => 'Un solo valor supera el 40% de la cartera (' . number_format($top['valor_total'], 0, ',', '.') . ' €). Un -10% en este valor mueve tu cartera un ' . number_format($top['peso']/10, 1, ',', '.') . '%. Valora rebalancear.'
    ];
}

// 2-5. Alertas por valor (RSI, objetivo, máximos 52s, soporte)
foreach ($portfolioData as $row) {
    $d = $row['data'];
    $nombre = $d['nombre'];
    $precio = $row['precio_actual'];
    $rsi = isset($d['rsi_14']) && $d['rsi_14'] !== null ? (float)$d['rsi_14'] : null;
    $target = (float)($d['target_mean_price'] ?? 0);
    $h52 = (float)($d['fifty_two_week_high'] ?? 0);

    if ($rsi !== null && $rsi > 70) {
        $alertas[] = ['nivel' => 'warn', 'icono' => '🔴', 'titulo' => "$nombre en sobrecompra (RSI " . number_format($rsi, 0) . ")",
            'texto' => 'El RSI supera 70. Estadísticamente es mala zona para comprar y razonable para recoger beneficios parciales.'];
    } elseif ($rsi !== null && $rsi < 35) {
        $alertas[] = ['nivel' => 'ok', 'icono' => '🟢', 'titulo' => "$nombre cerca de sobreventa (RSI " . number_format($rsi, 0) . ")",
            'texto' => 'RSI por debajo de 35: zona históricamente favorable para acumular si tu tesis de largo plazo se mantiene.'];
    }
    if ($target > 0 && $precio > 0) {
        $potencial = ($target - $precio) / $precio * 100;
        if ($potencial < -10) {
            $alertas[] = ['nivel' => 'warn', 'icono' => '📊', 'titulo' => "$nombre supera el consenso un " . number_format(abs($potencial), 0) . '%',
                'texto' => 'Cotiza a ' . number_format($precio, 2, ',', '.') . ' € frente a un objetivo medio de ' . number_format($target, 2, ',', '.') . ' €. El mercado ya descuenta más de lo que esperan los analistas.'];
        } elseif ($potencial > 15) {
            $alertas[] = ['nivel' => 'ok', 'icono' => '🎯', 'titulo' => "$nombre: potencial del +" . number_format($potencial, 0) . '% según consenso',
                'texto' => 'El objetivo medio de analistas (' . number_format($target, 2, ',', '.') . ' €) queda un ' . number_format($potencial, 0) . '% por encima del precio actual.'];
        }
    }
    if ($h52 > 0 && $precio >= $h52 * 0.98) {
        $alertas[] = ['nivel' => 'info', 'icono' => '📈', 'titulo' => "$nombre en máximos de 52 semanas",
            'texto' => 'Cotiza a menos de un 2% de su máximo anual (' . number_format($h52, 2, ',', '.') . ' €). Momentum fuerte, pero sin margen de seguridad para nuevas compras.'];
    }
}

// 6. Valores rezagados YTD con peso relevante
foreach ($portfolioData as $row) {
    if ($row['p_ytd'] < -10) {
        $alertas[] = ['nivel' => 'info', 'icono' => '🔎', 'titulo' => $row['data']['nombre'] . ': ' . number_format($row['p_ytd'], 1, ',', '.') . '% YTD',
            'texto' => 'Es el valor más rezagado del año. Revisa si la tesis de inversión sigue intacta o conviene reasignar capital.'];
    }
}

// 7. Nota de dividendos
if ($dividendosAnuales > 0) {
    $alertas[] = ['nivel' => 'info', 'icono' => '💸', 'titulo' => number_format($dividendosAnuales, 0, ',', '.') . ' €/año en dividendos estimados',
        'texto' => 'Tu cartera genera ~' . number_format($dividendosAnuales/12, 0, ',', '.') . ' €/mes de renta pasiva (yield ' . number_format($dividendYield ?? $divYieldCartera, 2, ',', '.') . '%). Reinvertirlos en los valores con más potencial acelera el interés compuesto.'];
}

// Limitar a 6 alertas, priorizando danger > warn > ok > info
$prioridad = ['danger' => 0, 'warn' => 1, 'ok' => 2, 'info' => 3];
usort($alertas, function ($a, $b) use ($prioridad) { return $prioridad[$a['nivel']] <=> $prioridad[$b['nivel']]; });
$alertas = array_slice($alertas, 0, 6);

// Market status (European markets: 9:00 - 17:30 CET)
date_default_timezone_set('Europe/Madrid');
$hora = (int) date('G');
$min = (int) date('i');
$dow = (int) date('N');
$marketOpen = ($dow <= 5 && (($hora > 9 || ($hora == 9 && $min >= 0)) && ($hora < 17 || ($hora == 17 && $min <= 30))));

?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cartera Pro GLM5 — Portfolio Financiero</title>
    <meta name="description"
        content="Panel de control del portfolio financiero GLM5 con datos en tiempo real, análisis técnico y fundamental.">
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=4.0">
    <link rel="stylesheet" href="mejoras_v3.css?v=4.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.6.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js"></script>

    <!-- PWA & Favicon Support: el manifiesto es un fichero estático (.webmanifest):
         el anti-bot de InfinityFree responde HTML a la petición de Manifest que hace
         el parser de Chrome a un recurso .php, y Chrome la rechaza como "Syntax error" -->
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="icon" type="image/png" href="icon-192.png">
    <link rel="shortcut icon" href="favicon.ico">
    <meta name="theme-color" content="#6366f1">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Cartera Pro">
    <link rel="apple-touch-icon" href="icon-192.png">
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
                    if ($update)
                        echo 'Última act: ' . date('d/m H:i', strtotime($update));
                    ?>
                </div>
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <span id="auto-refresh-status"
                        style="font-size:0.75rem; color:#94a3b8; font-family:'Inter',sans-serif;"></span>
                    <button id="theme-toggle-btn" onclick="toggleTheme()" class="btn-icon" aria-label="Cambiar tema claro/oscuro" title="Cambiar tema claro/oscuro">🌙</button>
                    <button id="btn-update" onclick="actualizarMercado(true)" class="btn btn-primary" aria-label="Actualizar datos del mercado">⟳ Actualizar</button>
                    <button id="btn-ai-report" onclick="generarInformeAI()" class="btn btn-ai" aria-label="Generar informe técnico con IA">
                        <span class="btn-text-full">📊 Análisis técnico</span>
                        <span class="btn-text-short">📊 Técnico</span>
                    </button>
                </div>
            </div>
        </header>

        <!-- TOAST NOTIFICATION -->
        <div id="update-toast" class="update-toast">
            <div class="toast-content">
                <div class="toast-spinner"></div>
                <div class="toast-text">
                    <span id="toast-title">Actualizando...</span>
                    <span id="toast-detail">Conectando con el servidor</span>
                </div>
                <span id="toast-count" class="toast-count">0/7</span>
            </div>
            <div class="toast-progress">
                <div id="toast-bar" class="toast-bar"></div>
            </div>
        </div>

        <!-- DASHBOARD KPIs -->
        <div class="dashboard-grid dashboard-grid-v3">
            <div class="card fade-in stagger-1">
                <div class="card-icon accent">💰</div>
                <p class="card-title">Valor de Mercado</p>
                <h2 class="card-value" id="kpi-total"><?= number_format($valorTotalCartera, 0, ',', '.') ?> €</h2>
                <p class="card-sub"><?= count($portfolioData) ?> valores en cartera</p>
            </div>
            <div class="card fade-in stagger-2">
                <div class="card-icon <?= $gananciaDiaTotal >= 0 ? 'success' : 'danger' ?>">
                    <?= $gananciaDiaTotal >= 0 ? '📈' : '📉' ?>
                </div>
                <p class="card-title">Sesión Hoy</p>
                <h2 class="card-value <?= $gananciaDiaTotal >= 0 ? 'text-success' : 'text-danger' ?>" id="kpi-dia">
                    <?= $gananciaDiaTotal >= 0 ? '+' : '' ?><?= number_format($gananciaDiaTotal, 0, ',', '.') ?> €
                </h2>
                <p class="card-sub">
                    <span class="badge <?= $pctTotalDia >= 0 ? 'bg-success' : 'bg-danger' ?>" id="kpi-dia-pct">
                        <?= $pctTotalDia >= 0 ? '▲' : '▼' ?> <?= number_format(abs($pctTotalDia), 2) ?>%
                    </span>
                    <span class="badge <?= $alphaDiaUp ? 'bg-success' : 'bg-danger' ?>" id="kpi-vs-ibex" title="Alpha vs IBEX hoy"
                        style="margin-left:0.25rem;font-size:0.65rem;">
                        vs IBEX <?= $alphaDiaUp ? '+' : '' ?><?= number_format($alphaDia, 2) ?>%
                    </span>
                </p>
            </div>
            <div class="card fade-in stagger-3">
                <div class="card-icon <?= $gananciaYTDTotal >= 0 ? 'success' : 'danger' ?>">📅</div>
                <p class="card-title">YTD <?= date('Y') ?></p>
                <h2 class="card-value <?= $gananciaYTDTotal >= 0 ? 'text-success' : 'text-danger' ?>" id="kpi-ytd">
                    <?= $gananciaYTDTotal >= 0 ? '+' : '' ?><?= number_format($gananciaYTDTotal, 0, ',', '.') ?> €
                </h2>
                <p class="card-sub">
                    <span class="badge <?= $porcentajeYTDTotal >= 0 ? 'bg-success' : 'bg-danger' ?>" id="kpi-ytd-pct">
                        <?= $porcentajeYTDTotal >= 0 ? '▲' : '▼' ?> <?= number_format(abs($porcentajeYTDTotal), 2) ?>%
                    </span>
                </p>
            </div>
            <!-- v3: P&L REAL -->
            <div class="card fade-in stagger-4">
                <div class="card-icon <?= ($pnlTotal !== null && $pnlTotal < 0) ? 'danger' : 'success' ?>">🧾</div>
                <p class="card-title">P&L Real <span class="badge-v3">NUEVO</span></p>
                <?php if ($pnlTotal !== null): ?>
                    <h2 class="card-value <?= $pnlTotal >= 0 ? 'text-success' : 'text-danger' ?>" id="kpi-pnl">
                        <?= $pnlTotal >= 0 ? '+' : '' ?><?= number_format($pnlTotal, 0, ',', '.') ?> €
                    </h2>
                    <p class="card-sub" id="kpi-pnl-sub">
                        <?= $pnlTotalPct >= 0 ? '+' : '' ?><?= number_format($pnlTotalPct, 2, ',', '.') ?>% sobre
                        <?= number_format($costeTotalInvertido, 0, ',', '.') ?> € invertidos
                        <?= $posicionesConCoste < count($portfolioData) ? "($posicionesConCoste/" . count($portfolioData) . " con coste)" : '' ?>
                    </p>
                <?php else: ?>
                    <h2 class="card-value" style="font-size:1rem;" id="kpi-pnl">Sin configurar</h2>
                    <p class="card-sub" id="kpi-pnl-sub">Introduce tu coste medio en <a href="gestionar_cartera.php" style="color:var(--accent-light)">Gestionar cartera</a></p>
                <?php endif; ?>
            </div>
            <!-- v3: DIVIDENDOS -->
            <div class="card fade-in stagger-5">
                <div class="card-icon success">💸</div>
                <p class="card-title">Dividendos/año <span class="badge-v3">NUEVO</span></p>
                <h2 class="card-value text-success"><?= number_format($dividendosAnuales, 0, ',', '.') ?> €</h2>
                <p class="card-sub">Yield <?= number_format($divYieldCartera, 2, ',', '.') ?>% · ~<?= number_format($dividendosAnuales / 12, 0, ',', '.') ?> €/mes</p>
            </div>
            <!-- v3: RIESGO -->
            <div class="card fade-in stagger-6">
                <div class="card-icon accent">🛡️</div>
                <p class="card-title">Riesgo <span class="badge-v3">NUEVO</span></p>
                <h2 class="card-value"><?= $betaPondFinal !== null ? 'β ' . number_format($betaPondFinal, 2, ',', '.') : '—' ?></h2>
                <p class="card-sub">
                    <?php if ($volCartera !== null): ?>
                        Vol. <?= number_format($volCartera, 1, ',', '.') ?>% anual ·
                        <?= $betaPondFinal !== null ? ($betaPondFinal < 0.8 ? 'Perfil defensivo' : ($betaPondFinal <= 1.2 ? 'Perfil neutral' : 'Perfil agresivo')) : '' ?>
                    <?php else: ?>Calculando...<?php endif; ?>
                </p>
            </div>
        </div>

        <!-- IBEX35 BANNER + PORTFOLIO COMPARISON -->
        <div class="ibex-banner card fade-in" id="ibex-banner">
            <div class="ibex-left">
                <span class="ibex-flag">🇪🇸</span>
                <div class="ibex-info">
                    <span class="ibex-label">IBEX 35</span>
                    <span class="ibex-sublabel">Índice de referencia</span>
                </div>
            </div>
            <div class="ibex-center">
                <span class="ibex-price"
                    id="ibex-price"><?= $ibexPrice > 0 ? number_format($ibexPrice, 2, ',', '.') : '---' ?></span>
                <span class="ibex-unit">pts</span>
            </div>
            <div class="ibex-right">
                <?php if ($ibexPrice > 0): ?>
                    <span class="ibex-change <?= $ibexUp ? 'up' : 'down' ?>" id="ibex-change">
                        <span class="ibex-change-icon"><?= $ibexUp ? '▲' : '▼' ?></span>
                        <span class="ibex-change-text"><?= ($ibexUp ? '+' : '') . number_format($ibexChg, 2) ?>%</span>
                    </span>
                    <span class="ibex-variation"
                        id="ibex-variation"><?= ($ibexUp ? '+' : '') . number_format($ibexDiff, 2, ',', '.') ?> pts</span>
                <?php else: ?>
                    <span class="ibex-change" id="ibex-change">
                        <span class="ibex-change-icon">⏳</span>
                        <span class="ibex-change-text">Cargando...</span>
                    </span>
                    <span class="ibex-variation" id="ibex-variation"></span>
                <?php endif; ?>
            </div>
            <div class="ibex-comparison">
                <div class="comparison-divider"></div>
                <div class="comparison-grid">
                    <div class="comparison-item">
                        <span class="comparison-label">YTD IBEX</span>
                        <span class="comparison-value <?= $ibexYTDUp ? 'up' : 'down' ?>">
                            <?= $ibexYTDUp ? '▲' : '▼' ?> <?= number_format(abs($ibexYTD), 2) ?>%
                        </span>
                    </div>
                    <div class="comparison-item">
                        <span class="comparison-label">YTD Portfolio</span>
                        <span class="comparison-value <?= $porcentajeYTDTotal >= 0 ? 'up' : 'down' ?>">
                            <?= $porcentajeYTDTotal >= 0 ? '▲' : '▼' ?>
                            <?= number_format(abs($porcentajeYTDTotal), 2) ?>%
                        </span>
                    </div>
                    <div class="comparison-item alpha">
                        <span class="comparison-label">Alpha (α)</span>
                        <span class="comparison-value badge-alpha <?= $alphaYTDUp ? 'up' : 'down' ?>">
                            <?= $alphaYTDUp ? '+' : '' ?><?= number_format($alphaYTD, 2) ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- v3: CENTRO DE DECISIONES -->
        <?php if (!empty($alertas)): ?>
        <div class="card fade-in decision-center">
            <div class="chart-header">
                <h3>🎯 Centro de Decisiones</h3>
                <span class="text-muted" style="font-size:0.72rem;">Alertas automáticas con tus datos de hoy</span>
            </div>
            <div class="alerts-grid">
                <?php foreach ($alertas as $al): ?>
                    <div class="alert-item alert-<?= $al['nivel'] ?>">
                        <span class="alert-icon"><?= $al['icono'] ?></span>
                        <div>
                            <strong><?= htmlspecialchars($al['titulo']) ?></strong>
                            <p><?= htmlspecialchars($al['texto']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- CHARTS ROW -->
        <div class="charts-section">
            <div class="card fade-in">
                <div class="chart-header">
                    <h3>📈 Evolución del Portfolio</h3>
                    <div style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap;">
                        <?php if (!empty($ibexHistory)): ?>
                        <div class="period-buttons" id="portfolio-mode">
                            <button class="period-btn active" data-mode="eur">€ Valor</button>
                            <button class="period-btn" data-mode="base100">Base 100 vs IBEX</button>
                        </div>
                        <?php endif; ?>
                        <div class="period-buttons" id="portfolio-periods">
                            <button class="period-btn" data-days="30">1M</button>
                            <button class="period-btn" data-days="90">3M</button>
                            <button class="period-btn active" data-days="180">6M</button>
                            <button class="period-btn" data-days="365">1A</button>
                            <button class="period-btn" data-days="0">Todo</button>
                        </div>
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
                <div class="chart-container chart-container-donut">
                    <canvas id="sectorChart"></canvas>
                    <div class="donut-center-label">
                        <span class="donut-center-value <?= $hhi > 2500 ? 'text-danger' : ($hhi > 1500 ? 'text-warning' : 'text-success') ?>"><?= number_format($hhi, 0, ',', '.') ?></span>
                        <span class="donut-center-text">HHI</span>
                    </div>
                </div>
                <p class="hhi-note">
                    <?php if ($hhi > 2500): ?>
                        ⚠️ <strong>Concentración alta</strong> (HHI &gt; 2.500).
                    <?php elseif ($hhi > 1500): ?>
                        Concentración moderada (HHI 1.500-2.500).
                    <?php else: ?>
                        ✅ Cartera bien diversificada (HHI &lt; 1.500).
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- v3: PANEL DE RIESGO -->
        <?php if ($volCartera !== null): ?>
        <div class="card fade-in risk-panel">
            <div class="chart-header">
                <h3>🛡️ Panel de Riesgo</h3>
                <span class="text-muted" style="font-size:0.72rem;">Calculado con <?= count($portfolioHistory) ?> sesiones reales de tu cartera</span>
            </div>
            <div class="risk-grid">
                <div class="risk-item">
                    <span class="risk-label">Beta ponderada</span>
                    <span class="risk-value"><?= $betaPondFinal !== null ? 'β ' . number_format($betaPondFinal, 2, ',', '.') : '—' ?></span>
                    <span class="risk-note"><?= $betaPondFinal !== null ? ($betaPondFinal < 0.8 ? 'Te mueves menos que el mercado' : ($betaPondFinal <= 1.2 ? 'Te mueves como el mercado' : 'Te mueves más que el mercado')) : 'Sin datos de beta' ?></span>
                </div>
                <div class="risk-item">
                    <span class="risk-label">Volatilidad anual</span>
                    <span class="risk-value"><?= number_format($volCartera, 1, ',', '.') ?>%</span>
                    <span class="risk-note">Oscilación típica de tu cartera</span>
                </div>
                <div class="risk-item">
                    <span class="risk-label">Max. drawdown</span>
                    <span class="risk-value text-danger"><?= number_format($maxDrawdown, 1, ',', '.') ?>%</span>
                    <span class="risk-note">Peor caída desde máximos del periodo</span>
                </div>
                <div class="risk-item">
                    <span class="risk-label">Concentración (HHI)</span>
                    <span class="risk-value <?= $hhi > 2500 ? 'text-danger' : '' ?>"><?= number_format($hhi, 0, ',', '.') ?></span>
                    <span class="risk-note">&gt;2.500 = concentrada</span>
                </div>
                <?php if ($rent1A !== null): 
                    $phTs = is_numeric($portfolioHistory[0]['x']) ? (int)($portfolioHistory[0]['x'] > 1e11 ? $portfolioHistory[0]['x'] / 1000 : $portfolioHistory[0]['x']) : strtotime($portfolioHistory[0]['x']);
                ?>
                <div class="risk-item">
                    <span class="risk-label">Rentab. 1 Año (12M)</span>
                    <span class="risk-value <?= $rent1A >= 0 ? 'text-success' : 'text-danger' ?>"><?= $rent1A >= 0 ? '+' : '' ?><?= number_format($rent1A, 1, ',', '.') ?>%</span>
                    <span class="risk-note"><?= $rentTotal !== null ? '(' . ($rentTotal >= 0 ? '+' : '') . number_format($rentTotal, 1, ',', '.') . '% desde ' . ($phTs ? date('M Y', $phTs) : 'inicio') . ')' : 'Interanual (últimos 365 días)' ?></span>
                </div>
                <?php elseif ($rentTotal !== null && !empty($portfolioHistory[0]['x'])): 
                    $phTs = is_numeric($portfolioHistory[0]['x']) ? (int)($portfolioHistory[0]['x'] > 1e11 ? $portfolioHistory[0]['x'] / 1000 : $portfolioHistory[0]['x']) : strtotime($portfolioHistory[0]['x']);
                ?>
                <div class="risk-item">
                    <span class="risk-label">Rentab. Total</span>
                    <span class="risk-value <?= $rentTotal >= 0 ? 'text-success' : 'text-danger' ?>"><?= $rentTotal >= 0 ? '+' : '' ?><?= number_format($rentTotal, 1, ',', '.') ?>%</span>
                    <span class="risk-note"><?= $phTs ? date('M Y', $phTs) : '' ?> → hoy</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- TABLE -->
        <div class="table-container fade-in">
            <div class="table-header">
                <h3>📋 Posiciones del Portfolio</h3>
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <span class="text-muted" style="font-size: 0.8rem;"><?= count($portfolioData) ?> valores</span>
                    <a href="gestionar_cartera.php" class="btn btn-secondary" style="padding:0.35rem 0.75rem; font-size:0.75rem; text-decoration:none;" title="Editar acciones, coste medio y sector">⚙️ Gestionar</a>
                    <button class="btn btn-secondary" onclick="exportarCSV()" style="padding:0.35rem 0.75rem; font-size:0.75rem;" aria-label="Exportar cartera a CSV">📥 CSV</button>
                </div>
            </div>
            <div class="table-scroll-wrapper">
                <table id="stock-table">
                    <thead>
                        <tr>
                            <th data-sort="name">Valor <span class="sort-icon">↕</span></th>
                            <th class="text-right" data-sort="shares">Acciones</th>
                            <th class="text-right" data-sort="price">Precio</th>
                            <th class="text-right" data-sort="value">Valor</th>
                            <th class="text-right" data-sort="weight">Peso</th>
                            <th class="text-right" data-sort="day">Día</th>
                            <th class="text-right" data-sort="ytd">YTD</th>
                            <th class="text-right" data-sort="pnl">P&L Real</th>
                            <th class="text-center" data-sort="signal">Señal</th>
                            <th class="text-center">Análisis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($portfolioData as $i => $row):
                            $d = $row['data'];
                            $clsYTD = $row['p_ytd'] >= 0 ? 'success' : 'danger';
                            $clsDia = $row['p_dia'] >= 0 ? 'success' : 'danger';
                            $shortTicker = preg_replace('/\.(MC|MI)$/i', '', $d['ticker']);
                            if ($shortTicker === 'MFE-A')
                                $shortTicker = 'MFE';
                            $techSignal = $d['tech_signal'] ?? 'MANTENER';
                            $techScore = (int)($d['tech_score'] ?? 0);
                            $sigCls = $techScore >= 10 ? 'buy' : ($techScore >= -10 ? 'hold' : 'sell');
                            ?>
                            <tr>
                                <td>
                                    <div class="ticker-cell">
                                        <div class="ticker-logo"
                                            style="background: <?= $row['sector_color'] ?>22; color: <?= $row['sector_color'] ?>;">
                                            <?= strtoupper($shortTicker) ?>
                                        </div>
                                        <div class="ticker-info">
                                            <div class="name"><?= htmlspecialchars($d['nombre']) ?></div>
                                            <div class="symbol"><?= strtoupper($shortTicker) ?> · <?= $row['sector'] ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-right text-muted"><?= number_format($d['num_acciones'], 0, ',', '.') ?></td>
                                <td class="text-right fw-600 nowrap mono">
                                    <?= number_format($row['precio_actual'], 3, ',', '.') ?> €
                                </td>
                                <td class="text-right fw-600 nowrap mono">
                                    <?= number_format($row['valor_total'], 0, ',', '.') ?> €
                                </td>
                                <td class="text-right nowrap">
                                    <span class="mono" style="font-size:0.78rem;"><?= number_format($row['peso'], 1, ',', '.') ?>%</span>
                                    <div class="weight-bar"><i style="width:<?= round(min(100, $row['peso']), 2) ?>%; background:<?= $row['sector_color'] ?>;"></i></div>
                                </td>
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
                                <td class="text-right nowrap">
                                    <?php if ($row['pnl_real'] !== null): ?>
                                        <span class="badge bg-<?= $row['pnl_real'] >= 0 ? 'success' : 'danger' ?>">
                                            <?= $row['pnl_real'] >= 0 ? '▲' : '▼' ?> <?= number_format(abs($row['pnl_real_pct']), 2) ?>%
                                        </span>
                                        <br><small class="mono text-muted"><?= $row['pnl_real'] >= 0 ? '+' : '' ?><?= number_format($row['pnl_real'], 0, ',', '.') ?> €</small>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.75rem;" title="Configura el coste medio en Gestionar cartera">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="signal-pill signal-<?= $sigCls ?>"><?= htmlspecialchars($techSignal) ?></span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-secondary btn-sm" data-ticker="<?= $d['ticker'] ?>"
                                        data-ticker-yahoo="<?= $d['ticker_yahoo'] ?? $d['ticker'] ?>"
                                        data-name="<?= htmlspecialchars($d['nombre']) ?>"
                                        data-price="<?= $row['precio_actual'] ?>"
                                        data-prev="<?= $row['precio_prev'] ?>"
                                        data-ref="<?= $row['precio_cierre_2025'] ?>"
                                        data-shares="<?= $d['num_acciones'] ?>"
                                        data-cost="<?= $row['coste_medio'] ?>"
                                        data-peso="<?= round($row['peso'], 2) ?>"
                                        data-div-anual="<?= round($row['div_anual'], 2) ?>"
                                        data-pe="<?= $d['trailing_pe'] ?? ($d['pe_ratio'] ?? '') ?>"
                                        data-target="<?= $d['target_mean_price'] ?? ($d['target_price'] ?? '') ?>" data-cap="<?= $d['market_cap'] ?? '' ?>"
                                        data-div="<?= $d['dividend_yield'] ?? '' ?>" data-beta="<?= $d['beta'] ?? '' ?>"
                                        data-high52="<?= $d['fifty_two_week_high'] ?? '' ?>"
                                        data-low52="<?= $d['fifty_two_week_low'] ?? '' ?>" data-eps="<?= $d['eps'] ?? '' ?>"
                                        data-pb="<?= $d['price_to_book'] ?? '' ?>" data-vol="<?= $d['avg_volume'] ?? '' ?>"
                                        data-history='<?= htmlspecialchars(cleanDbJson($d['history_json'] ?? '[]'), ENT_QUOTES, 'UTF-8') ?>'
                                        data-sector="<?= $row['sector'] ?>" data-sector-color="<?= $row['sector_color'] ?>"
                                        data-rsi="<?= $d['rsi_14'] ?? '' ?>"
                                        data-sma20="<?= $d['sma_20'] ?? '' ?>"
                                        data-sma50="<?= $d['sma_50'] ?? '' ?>"
                                        data-techscore="<?= $techScore ?>"
                                        data-techsignal="<?= htmlspecialchars($techSignal) ?>"
                                        data-volatilidad="<?= $d['volatilidad'] ?? '' ?>"
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
    </div>

    <!-- MODAL DE ANÁLISIS DE ACTIVO -->
    <div id="analysisModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-name">
        <div class="modal-content">
            <button class="close-btn" onclick="closeModal()" aria-label="Cerrar ventana de análisis">✕</button>

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

            <!-- v3: TU POSICIÓN (esta sección faltaba y rompía el modal en la v2) -->
            <div class="card position-summary">
                <h3 style="font-size: 0.9rem; margin-bottom: 0.75rem;">🧾 Tu Posición</h3>
                <div class="position-grid">
                    <div class="position-cell"><span class="pv" id="m-shares">-</span><span class="pl">Acciones</span></div>
                    <div class="position-cell"><span class="pv" id="m-current">-</span><span class="pl">Valor actual</span></div>
                    <div class="position-cell"><span class="pv" id="m-weight">-</span><span class="pl">Peso cartera</span></div>
                    <div class="position-cell"><span class="pv" id="m-cost">-</span><span class="pl">Coste medio</span></div>
                    <div class="position-cell"><span class="pv" id="m-pnl">-</span><span class="pl">P&L real</span></div>
                    <div class="position-cell"><span class="pv" id="m-divy">-</span><span class="pl">Div. anual</span></div>
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
                        <div class="chart-container"><canvas id="historyChart" aria-label="Gráfico de historial de precios"></canvas></div>
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
                        <h3 style="font-size: 0.9rem; margin-bottom: 0.5rem;">📈 Indicadores Técnicos</h3>
                        <ul class="fundamental-list" id="tech-list">
                            <li><span>RSI (14)</span><span id="m-rsi">-</span></li>
                            <li><span>SMA 20</span><span id="m-sma20">-</span></li>
                            <li><span>SMA 50</span><span id="m-sma50">-</span></li>
                            <li><span>MACD (12,26)</span><span id="m-macd">-</span></li>
                            <li><span>Bandas Bollinger</span><span id="m-bollinger">-</span></li>
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

    <!-- AI REPORT MODAL -->
    <div id="aiReportModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="ai-report-title">
        <div class="modal-content ai-report-modal">
            <button class="close-btn" onclick="closeAIReport()" aria-label="Cerrar informe técnico">✕</button>
            <div class="ai-report-header">
                <span class="ai-badge">📊 Análisis Técnico</span>
                <h2 id="ai-report-title">Análisis Técnico de Cartera</h2>
                <div style="display:flex; align-items:center; gap:0.6rem;">
                    <span class="ai-date" id="ai-report-date"><?= date('l j \d\e F \d\e Y') ?></span>
                    <button class="btn btn-secondary" onclick="window.print()" style="padding:0.25rem 0.6rem; font-size:0.75rem;" aria-label="Imprimir informe en PDF">🖨️ PDF</button>
                </div>
            </div>
            <div id="ai-report-content" class="ai-report-body">
                <div class="ai-loading">
                    <div class="ai-loading-dots">
                        <span></span><span></span><span></span>
                    </div>
                    <p>Analizando movimientos del mercado...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTOR DATA FOR JS -->
    <script>
        var SECTOR_DATA = <?= json_encode(array_map(function ($k, $v) {
            return ['name' => $k, 'value' => round($v['valor'], 2), 'color' => $v['color']];
        }, array_keys($sectorDistrib), array_values($sectorDistrib))) ?>;

        var PORTFOLIO_HISTORY = <?= json_encode(roundFloats($portfolioHistory)) ?>;
        var IBEX_HISTORY = <?= json_encode(roundFloats($ibexHistory)) ?>;

        var ALL_STOCKS_HISTORY = {};
        <?php foreach ($valores as $val): ?>
            ALL_STOCKS_HISTORY['<?= $val['ticker'] ?>'] = <?= cleanDbJson($val['history_json'] ?? '[]') ?>;
        <?php endforeach; ?>

        // Portfolio data for AI report
        var PORTFOLIO_SUMMARY = {
            totalValue: <?= round($valorTotalCartera) ?>,
            dayChange: <?= round($gananciaDiaTotal) ?>,
            dayChangePct: <?= round($pctTotalDia, 2) ?>,
            ytdChange: <?= round($gananciaYTDTotal) ?>,
            ytdChangePct: <?= round($porcentajeYTDTotal, 2) ?>,
            ibexDayPct: <?= round($ibexChg, 2) ?>,
            ibexYTDPct: <?= round($ibexYTD, 2) ?>,
            alphaYTD: <?= round($alphaYTD, 2) ?>,
            costeInvertido: <?= round($costeTotalInvertido) ?>,
            pnlReal: <?= $pnlTotal !== null ? round($pnlTotal) : 'null' ?>,
            dividendosAnuales: <?= round($dividendosAnuales) ?>
        };
    </script>
    <script src="script.js?v=4.0"></script>
    <script>
        // Registrar y forzar actualización de Service Worker para PWA y móviles
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('service-worker.js?v=15')
                    .then(function(reg) {
                        reg.update();
                    })
                    .catch(function(err) {
                        console.warn('PWA: no se pudo registrar el Service Worker', err);
                    });
            });
        }
    </script>
</body>

</html>
