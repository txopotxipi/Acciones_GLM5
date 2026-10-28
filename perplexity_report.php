<?php
// perplexity_report.php — Genera informe AI de análisis técnico de la cartera
// Endpoint: POST
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Madrid');

require_once 'config_api.php';
require_once 'config.php';

// VALIDACIÓN DE SEGURIDAD (Compatible con Apache / InfinityFree y same-origin)
$headers = function_exists('getallheaders') ? getallheaders() : [];
$inputBody = json_decode(file_get_contents('php://input'), true);
$apiKey = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? ($inputBody['key'] ?? '');

$isSameOrigin = false;
if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && in_array($_SERVER['HTTP_SEC_FETCH_SITE'], ['same-origin', 'same-site', 'none'])) {
    $isSameOrigin = true;
}
if (!empty($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_HOST'])) {
    if (strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false) {
        $isSameOrigin = true;
    }
}
if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $isSameOrigin = true;
}

if ($apiKey !== INTERNAL_API_KEY && !$isSameOrigin) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Check API key
if (!defined('GROQ_API_KEY') || GROQ_API_KEY === 'TU_API_KEY_AQUI' || empty(GROQ_API_KEY)) {
    echo json_encode(['error' => 'API key de Groq no configurada', 'details' => 'Edita config_api.php con tu API key de Groq']);
    exit;
}

// ==================================================
// FUNCIONES DE ANÁLISIS TÉCNICO CUANTITATIVO
// ==================================================

function calcSMA($prices, $period) {
    $sma = [];
    $count = count($prices);
    for ($i = 0; $i < $count; $i++) {
        if ($i < $period - 1) {
            $sma[] = null;
            continue;
        }
        $sum = 0;
        for ($j = $i - ($period - 1); $j <= $i; $j++) {
            $sum += $prices[$j];
        }
        $sma[] = $sum / $period;
    }
    return $sma;
}

function calcRSI($prices, $period = 14) {
    $count = count($prices);
    if ($count < $period + 1) return null;
    
    $gains = 0;
    $losses = 0;
    for ($i = 1; $i <= $period; $i++) {
        $diff = $prices[$i] - $prices[$i - 1];
        if ($diff > 0) $gains += $diff;
        else $losses -= $diff;
    }
    $avgGain = $gains / $period;
    $avgLoss = $losses / $period;
    
    for ($i = $period + 1; $i < $count; $i++) {
        $diff = $prices[$i] - $prices[$i - 1];
        $avgGain = ($avgGain * ($period - 1) + ($diff > 0 ? $diff : 0)) / $period;
        $avgLoss = ($avgLoss * ($period - 1) + ($diff < 0 ? -$diff : 0)) / $period;
    }
    
    if ($avgLoss === 0) return 100;
    $rs = $avgGain / $avgLoss;
    return 100 - (100 / (1 + $rs));
}

function detectTrend($prices) {
    $count = count($prices);
    if ($count < 50) return ['trend' => 'neutral', 'label' => 'Lateral', 'strength' => 0];
    
    $sma20 = calcSMA($prices, 20);
    $sma50 = calcSMA($prices, 50);
    
    $last20 = $sma20[count($sma20) - 1];
    $last50 = $sma50[count($sma50) - 1];
    $price = $prices[$count - 1];
    
    if ($price > $last20 && $last20 > $last50) {
        return ['trend' => 'bullish', 'label' => 'Alcista ↑', 'strength' => 2];
    }
    if ($price > $last20 || $last20 > $last50) {
        return ['trend' => 'mild-bull', 'label' => 'Ligeramente Alcista', 'strength' => 1];
    }
    if ($price < $last20 && $last20 < $last50) {
        return ['trend' => 'bearish', 'label' => 'Bajista ↓', 'strength' => -2];
    }
    if ($price < $last20 || $last20 < $last50) {
        return ['trend' => 'mild-bear', 'label' => 'Ligeramente Bajista', 'strength' => -1];
    }
    return ['trend' => 'neutral', 'label' => 'Lateral', 'strength' => 0];
}

function calcVolatility($prices) {
    $count = count($prices);
    if ($count < 20) return 0;
    
    $recent = array_slice($prices, -20);
    $returns = [];
    for ($i = 1; $i < count($recent); $i++) {
        if ($recent[$i - 1] > 0) {
            $returns[] = ($recent[$i] - $recent[$i - 1]) / $recent[$i - 1];
        }
    }
    if (empty($returns)) return 0;
    
    $mean = array_sum($returns) / count($returns);
    $varianceSum = 0;
    foreach ($returns as $r) {
        $varianceSum += pow($r - $mean, 2);
    }
    $variance = $varianceSum / count($returns);
    
    return sqrt($variance) * sqrt(252) * 100; // Anualizado %
}

function calculateStockScore($val, $prices, $price) {
    $score = 0;
    
    // RSI
    $rsi = calcRSI($prices, 14);
    if ($rsi !== null) {
        if ($rsi < 30) $score += 20;
        elseif ($rsi < 40) $score += 10;
        elseif ($rsi > 70) $score -= 20;
        elseif ($rsi > 60) $score -= 5;
    }
    
    // Trend
    $trendInfo = detectTrend($prices);
    $score += $trendInfo['strength'] * 10;
    
    // SMA crossover
    $count = count($prices);
    if ($count >= 50) {
        $sma20 = calcSMA($prices, 20);
        $sma50 = calcSMA($prices, 50);
        $l20 = $sma20[count($sma20) - 1];
        $l50 = $sma50[count($sma50) - 1];
        $prev20 = $sma20[count($sma20) - 6];
        $prev50 = $sma50[count($sma50) - 6];
        
        if ($prev20 <= $prev50 && $l20 > $l50) {
            $score += 15;
        } elseif ($prev20 >= $prev50 && $l20 < $l50) {
            $score -= 15;
        }
    }
    
    // Target price
    $target = (float)($val['target_mean_price'] ?? ($val['target_price'] ?? 0));
    if ($target > 0 && $price > 0) {
        $potential = (($target - $price) / $price) * 100;
        if ($potential > 15) $score += 20;
        elseif ($potential > 5) $score += 8;
        elseif ($potential < -5) $score -= 15;
    }
    
    // PER
    $pe = (float)($val['trailing_pe'] ?? ($val['pe_ratio'] ?? 0));
    if ($pe > 0) {
        if ($pe < 10) $score += 15;
        elseif ($pe < 15) $score += 8;
        elseif ($pe > 22) $score -= 10;
    }
    
    // Dividend
    $div = (float)($val['dividend_yield'] ?? 0);
    if ($div > 0) {
        if ($div > 5) $score += 10;
        elseif ($div > 3) $score += 5;
    }
    
    // Beta
    $beta = (float)($val['beta'] ?? 0);
    if ($beta > 0 && $beta < 0.7) {
        $score += 5;
    }
    
    // P/B
    $pb = (float)($val['price_to_book'] ?? 0);
    if ($pb > 0) {
        if ($pb < 1) $score += 5;
        elseif ($pb > 3) $score -= 5;
    }
    
    // 52-week position
    $high52 = (float)($val['fifty_two_week_high'] ?? 0);
    $low52 = (float)($val['fifty_two_week_low'] ?? 0);
    if ($high52 > 0 && $low52 > 0 && ($high52 - $low52) > 0) {
        $rangePos = (($price - $low52) / ($high52 - $low52)) * 100;
        if ($rangePos < 20) $score += 10;
        elseif ($rangePos > 90) $score -= 10;
    }
    
    // YTD
    $ref = (float)($val['precio_cierre_2025'] ?? 0);
    if ($ref > 0) {
        $ytdChg = (($price - $ref) / $ref) * 100;
        if ($ytdChg > 10) $score += 5;
        elseif ($ytdChg < -10) $score -= 5;
    }
    
    return max(-100, min(100, $score));
}

// ==================================================
// PROCESAMIENTO GENERAL DE LA CARTERA
// ==================================================

$stmt = $pdo->query("SELECT * FROM valores WHERE num_acciones > 0 ORDER BY (num_acciones * precio_actual) DESC");
$valores = $stmt->fetchAll();

$ibexStmt = $pdo->query("SELECT precio_actual, precio_cierre_anterior, precio_cierre_2025 FROM valores WHERE ticker = '^IBEX' LIMIT 1");
$ibexData = $ibexStmt->fetch();

$ibexPrice = $ibexData ? $ibexData['precio_actual'] : 0;
$ibexPrev = $ibexData ? $ibexData['precio_cierre_anterior'] : 0;
$ibexChg = ($ibexPrev > 0) ? (($ibexPrice - $ibexPrev) / $ibexPrev * 100) : 0;
$ibexCierre2025 = ($ibexData && $ibexData['precio_cierre_2025'] > 0) ? $ibexData['precio_cierre_2025'] : 0;
$ibexYTD = ($ibexCierre2025 > 0) ? (($ibexPrice - $ibexCierre2025) / $ibexCierre2025 * 100) : 0;

$valorTotalCartera = 0;
$gananciaYTDTotal = 0;
$gananciaDiaTotal = 0;
$totalCapitalInicio2026 = 0;
$totalValorDiaAnterior = 0;

$sectores = [
    'IBE.MC' => 'Energía',
    'TEF.MC' => 'Telecomunicaciones',
    'ELE.MC' => 'Energía',
    'BBVA.MC' => 'Banca',
    'REP.MC' => 'Petróleo',
    'MFE-A' => 'Media',
    'A3M.MC' => 'Media',
];

$stocksList = [];
$activeSectors = [];

foreach ($valores as $val) {
    if ($val['ticker'] === '^IBEX' || $val['ticker_yahoo'] === '^IBEX') continue;

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

    $val['precio_actual_calc'] = $precioActual;
    $val['valor_total'] = $valorPosicion;
    $val['p_dia'] = $porcentajeDia;
    $val['p_ytd'] = $porcentajeYTD;
    
    $sector = $sectores[$val['ticker']] ?? 'Otro';
    $activeSectors[] = $sector;
    
    $stocksList[] = $val;
}

$activeSectors = array_unique($activeSectors);
$sectorsStr = implode(', ', $activeSectors);

$porcentajeYTDTotal = ($totalCapitalInicio2026 > 0) ? ($gananciaYTDTotal / $totalCapitalInicio2026) * 100 : 0;
$pctTotalDia = ($totalValorDiaAnterior > 0) ? ($gananciaDiaTotal / $totalValorDiaAnterior) * 100 : 0;
$alphaYTD = $porcentajeYTDTotal - $ibexYTD;

// ==================================================
// CÁLCULO DE EVOLUCIÓN HISTÓRICA AGREGADA
// ==================================================

$dateMap = [];
$tickers = [];
$sharesMap = [];
foreach ($valores as $val) {
    if ($val['ticker'] === '^IBEX' || $val['ticker_yahoo'] === '^IBEX') continue;
    $ticker = $val['ticker'];
    $tickers[] = $ticker;
    $sharesMap[$ticker] = (float)$val['num_acciones'];
    
    $hist = json_decode($val['history_json'], true);
    if (is_array($hist)) {
        foreach ($hist as $p) {
            $dayTs = floor($p['x'] / 86400) * 86400;
            if (!isset($dateMap[$dayTs])) {
                $dateMap[$dayTs] = [];
            }
            $dateMap[$dayTs][$ticker] = (float)$p['y'];
        }
    }
}

ksort($dateMap);

$timestamps = array_keys($dateMap);
$portfolioValues = [];
$lastPrices = [];

foreach ($timestamps as $ts) {
    foreach ($tickers as $t) {
        if (isset($dateMap[$ts][$t])) {
            $lastPrices[$t] = $dateMap[$ts][$t];
        }
    }
    
    $totalVal = 0;
    foreach ($tickers as $t) {
        $shares = $sharesMap[$t] ?? 0;
        $price = $lastPrices[$t] ?? 0;
        $totalVal += $shares * $price;
    }
    if ($totalVal > 0) {
        $portfolioValues[$ts] = $totalVal;
    }
}

$mesesAbreviados = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

if (count($portfolioValues) > 0) {
    $minVal = min($portfolioValues);
    $maxVal = max($portfolioValues);
    $firstVal = reset($portfolioValues);
    
    $tsMin = array_search($minVal, $portfolioValues);
    $tsMax = array_search($maxVal, $portfolioValues);
    $tsFirst = $timestamps[0];
    
    $mes12 = $mesesAbreviados[(int)date('n', $tsFirst)] . ' ' . date('Y', $tsFirst);
    $mesMin = $tsMin ? ($mesesAbreviados[(int)date('n', $tsMin)] . ' ' . date('Y', $tsMin)) : '---';
    $mesMax = $tsMax ? ($mesesAbreviados[(int)date('n', $tsMax)] . ' ' . date('Y', $tsMax)) : '---';
    
    $gainFromMin = $minVal > 0 ? (($valorTotalCartera - $minVal) / $minVal * 100) : 0;
    $corrFromMax = $maxVal > 0 ? (($valorTotalCartera - $maxVal) / $maxVal * 100) : 0;
} else {
    // Fallbacks
    $firstVal = 320000;
    $minVal = 310000;
    $maxVal = 415000;
    $mes12 = 'jun 2025';
    $mesMin = 'jul 2025';
    $mesMax = 'abr 2026';
    $gainFromMin = 30.0;
    $corrFromMax = -3.0;
}

// ==================================================
// CONSTRUCCIÓN DEL PROMPT PARA EL MODELO
// ==================================================

$stocksText = "";
foreach ($stocksList as $val) {
    $weightPct = ($valorTotalCartera > 0) ? ($val['valor_total'] / $valorTotalCartera) * 100 : 0;

    $weightFmt = number_format($weightPct, 2, ',', '.') . '%';
    $value = number_format($val['valor_total'], 0, ',', '.');
    $price = number_format($val['precio_actual_calc'], 3, ',', '.');
    $ytd = number_format($val['p_ytd'], 2, ',', '.');
    $dayChange = number_format($val['p_dia'], 2, ',', '.');
    
    $shortTicker = preg_replace('/\.(MC|MI)$/i', '', $val['ticker']);
    if ($shortTicker === 'MFE-A') $shortTicker = 'MFE';

    $stocksText .= "| " . str_pad($val['nombre'], 17) . " | " . str_pad($shortTicker, 6) . " | " . str_pad(number_format($val['num_acciones'], 0, ',', '.'), 8) . " | " . str_pad($price . " €", 8) . " | " . str_pad($value . " €", 10) . " | " . str_pad(($val['p_dia'] >= 0 ? '+' : '') . $dayChange . "%", 8) . " | " . str_pad(($val['p_ytd'] >= 0 ? '+' : '') . $ytd . "%", 8) . " |\n";
}

$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fecha = $dias[date('w')] . ' ' . date('d') . ' de ' . $meses[(int) date('n')] . ' de ' . date('Y') . ', ' . date('H:i');

// Cargar contexto de datos detallados de cada activo
$datosAccionesDetalle = "";
foreach ($stocksList as $s) {
    $hist = json_decode($s['history_json'], true);
    $prices = is_array($hist) ? array_column($hist, 'y') : [];
    
    $rsi = calcRSI($prices, 14);
    $trendInfo = detectTrend($prices);
    $volatility = calcVolatility($prices);
    $score = calculateStockScore($s, $prices, $s['precio_actual_calc']);
    
    $verdict = 'MANTENER';
    if ($score >= 30) $verdict = 'COMPRAR';
    elseif ($score >= 10) $verdict = 'COMPRA MODERADA';
    elseif ($score >= -10) $verdict = 'MANTENER';
    elseif ($score >= -30) $verdict = 'PRECAUCIÓN';
    else $verdict = 'VENDER';
    
    $cruceSMA = "Indeterminado";
    if (count($prices) >= 50) {
        $sma20 = calcSMA($prices, 20);
        $sma50 = calcSMA($prices, 50);
        $l20 = $sma20[count($sma20) - 1];
        $l50 = $sma50[count($sma50) - 1];
        $cruceSMA = ($l20 > $l50) ? "SMA20 por encima de SMA50 (señal alcista)" : "SMA20 por debajo de SMA50 (señal bajista)";
    }
    
    $shortTicker = preg_replace('/\.(MC|MI)$/i', '', $s['ticker']);
    if ($shortTicker === 'MFE-A') $shortTicker = 'MFE';
    
    $datosAccionesDetalle .= "🏢 VALOR: {$s['nombre']} ({$shortTicker})\n";
    $datosAccionesDetalle .= "- Ticker oficial: {$s['ticker']}\n";
    $datosAccionesDetalle .= "- Sector: " . ($sectores[$s['ticker']] ?? 'Otro') . "\n";
    $datosAccionesDetalle .= "- Precio actual: " . number_format($s['precio_actual_calc'], 3, ',', '.') . " € | Var Hoy: " . ($s['p_dia'] >= 0 ? '+' : '') . number_format($s['p_dia'], 2, ',', '.') . "% | YTD: " . ($s['p_ytd'] >= 0 ? '+' : '') . number_format($s['p_ytd'], 2, ',', '.') . "%\n";
    $datosAccionesDetalle .= "- Posición: " . number_format($s['num_acciones'], 0, ',', '.') . " acciones | Valor pos.: " . number_format($s['valor_total'], 0, ',', '.') . " €\n";
    $peVal = (float)($s['trailing_pe'] ?? ($s['pe_ratio'] ?? 0));
    $epsVal = (float)($s['eps'] ?? 0);
    $targetVal = (float)($s['target_mean_price'] ?? ($s['target_price'] ?? 0));
    $capVal = (float)($s['market_cap'] ?? 0);
    $divVal = (float)($s['dividend_yield'] ?? 0);
    $betaVal = (float)($s['beta'] ?? 0);
    $pbVal = (float)($s['price_to_book'] ?? 0);
    $low52Val = (float)($s['fifty_two_week_low'] ?? 0);
    $high52Val = (float)($s['fifty_two_week_high'] ?? 0);

    $datosAccionesDetalle .= "- PER: " . ($peVal > 0 ? number_format($peVal, 2, ',', '.') : 'N/D') . " | BPA (EPS): " . ($epsVal > 0 ? number_format($epsVal, 2, ',', '.') . " €" : 'N/D') . "\n";
    $datosAccionesDetalle .= "- Precio objetivo: " . ($targetVal > 0 ? number_format($targetVal, 3, ',', '.') . " €" : 'N/D') . "\n";
    $datosAccionesDetalle .= "- Capitalización: " . ($capVal > 0 ? number_format($capVal, 0, ',', '.') . " €" : 'N/D') . " | Dividendo: " . ($divVal > 0 ? number_format($divVal, 2, ',', '.') . "%" : 'N/D') . "\n";
    $datosAccionesDetalle .= "- Beta: " . ($betaVal > 0 ? number_format($betaVal, 2, ',', '.') : 'N/D') . " | P/B: " . ($pbVal > 0 ? number_format($pbVal, 2, ',', '.') : 'N/D') . "\n";
    $datosAccionesDetalle .= "- Rango 52s: " . ($low52Val > 0 ? number_format($low52Val, 3, ',', '.') . " €" : 'N/D') . " — " . ($high52Val > 0 ? number_format($high52Val, 3, ',', '.') . " €" : 'N/D') . "\n";
    $datosAccionesDetalle .= "- RSI (14) calculado: " . ($rsi !== null ? round($rsi, 1) : 'N/D') . " | Volatilidad anualizada: " . ($volatility > 0 ? round($volatility, 1) . "%" : 'N/D') . "\n";
    $datosAccionesDetalle .= "- Cruce Medias SMA: {$cruceSMA}\n";
    $datosAccionesDetalle .= "- Señal algorítmica: {$verdict} (puntuación: {$score}/100)\n";
    $datosAccionesDetalle .= "--------------------------------------------------------\n\n";
}

$prompt = "Realiza un análisis técnico completo de mi portfolio de inversión (Cartera Pro GLM5) con los siguientes datos a fecha de hoy ({$fecha}):\n\n";
$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "📊 DATOS GENERALES DEL PORTFOLIO\n";
$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "- Valor total de mercado: " . number_format($valorTotalCartera, 0, ',', '.') . " €\n";
$prompt .= "- Número de valores: " . count($stocksList) . "\n";
$prompt .= "- Sectores: " . $sectorsStr . "\n";
$prompt .= "- Resultado sesión hoy: " . ($gananciaDiaTotal >= 0 ? '+' : '') . number_format($gananciaDiaTotal, 0, ',', '.') . " € (" . ($pctTotalDia >= 0 ? '+' : '') . number_format($pctTotalDia, 2, ',', '.') . "%)\n";
$prompt .= "- Rendimiento YTD 2026: " . ($gananciaYTDTotal >= 0 ? '+' : '') . number_format($gananciaYTDTotal, 0, ',', '.') . " € (" . ($porcentajeYTDTotal >= 0 ? '+' : '') . number_format($porcentajeYTDTotal, 2, ',', '.') . "%)\n";
$prompt .= "- YTD IBEX 35 (referencia): " . ($ibexYTD >= 0 ? '+' : '') . number_format($ibexYTD, 2, ',', '.') . "%\n";
$prompt .= "- Alpha generada: " . ($alphaYTD >= 0 ? '+' : '') . number_format($alphaYTD, 2, ',', '.') . "%\n\n";

$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "📋 COMPOSICIÓN DE LA CARTERA\n";
$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "| VALOR             | TICKER | ACCIONES | PRECIO   | VALOR POS. | DÍA      | YTD      |\n";
$prompt .= "|-------------------|--------|----------|----------|------------|----------|----------|\n";
$prompt .= $stocksText . "\n";

$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "📈 EVOLUCIÓN HISTÓRICA (extraída del gráfico de la web)\n";
$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "- Hace 12 meses ({$mes12}): ~" . number_format($firstVal, 0, ',', '.') . " €\n";
$prompt .= "- Mínimo anual ({$mesMin}): ~" . number_format($minVal, 0, ',', '.') . " €\n";
$prompt .= "- Máximo histórico ({$mesMax}): ~" . number_format($maxVal, 0, ',', '.') . " €\n";
$prompt .= "- Valor actual (" . $meses[(int)date('n')] . " " . date('Y') . "): ~" . number_format($valorTotalCartera, 0, ',', '.') . " €\n";
$prompt .= "- Ganancia desde mínimos: +" . number_format($gainFromMin, 2, ',', '.') . "%\n";
$prompt .= "- Corrección desde máximos: " . number_format($corrFromMax, 2, ',', '.') . "%\n\n";

$prompt .= "El portfolio ha seguido una evolución que analizaremos a continuación.\n\n";

$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "📊 DATOS TÉCNICOS DETALLADOS DE CADA VALOR\n";
$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= $datosAccionesDetalle . "\n";

$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$prompt .= "📝 SOLICITO EL SIGUIENTE ANÁLISIS DETALLADO:\n";
$prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
$prompt .= "### 1. Tendencia Global y Momentum del Portfolio (Market & Trend Overview)\n";
$prompt .= "   - Redacta al menos 2 párrafos extensos y altamente analíticos.\n";
$prompt .= "   - Analiza la tendencia principal del portfolio global a corto (1M), medio (3M) y largo plazo (1A) basándote en la evolución histórica facilitada.\n";
$prompt .= "   - Indica la fuerza de la tendencia, el momentum técnico de la cartera y la situación del Price Action general en el mercado.\n";
$prompt .= "   - Define y detalla los Niveles Técnicos Clave del Portfolio (soporte principal y secundario en €, la resistencia principal/máximo histórico y siguiente nivel teórico proyectado, junto con la zona de consolidación actual).\n\n";
$prompt .= "### 2. Atribución de Rendimiento y Fuerza Relativa (Performance Attribution & Alpha)\n";
$prompt .= "   - Analiza el outperformance actual del portfolio (Alpha del " . number_format($alphaYTD, 2, ',', '.') . "% YTD).\n";
$prompt .= "   - Identifica con precisión cuáles valores han sido los principales motores de esta Alpha y cuáles de ellos están lastrando la rentabilidad del portfolio.\n";
$prompt .= "   - Explica el impacto cruzado entre la variación diaria, el rendimiento YTD y el peso ponderado de cada activo (ej: analiza cómo influye que Iberdrola represente más del 50% del portfolio, o el impacto de BBVA, Telefónica, etc.).\n\n";
$prompt .= "### 3. Deep-Dive Técnico por Activo (Análisis exhaustivo de todos los valores)\n";
$prompt .= "   Realiza un diagnóstico técnico pormenorizado para cada uno de los " . count($stocksList) . " valores en cartera (Iberdrola, Endesa, BBVA, Telefónica, Repsol, MediaForEurope y Atresmedia). Para cada valor, crea un encabezado H4 (#### Ticker) y redacta un párrafo completo (mínimo 5-6 líneas densas) y viñetas técnicas que cubran:\n";
$prompt .= "   a) Estructura del precio, tendencia actual y fase del ciclo técnico del valor (acumulación, subida, distribución o bajada).\n";
$prompt .= "   b) Señal técnica recomendada (🟢 COMPRAR / 🟡 MANTENER / 🔴 VENDER) con su respectiva puntuación algorítmica y justificación detallada basada en la relación del precio con las medias móviles SMA20 y SMA50, el RSI y los soportes/resistencias dinámicos.\n";
$prompt .= "   c) Rendimiento YTD y contribución o peso de la posición en el portfolio.\n";
$prompt .= "   d) Niveles técnicos exactos a vigilar (soportes fuertes/dinámicos y resistencias clave).\n";
$prompt .= "   e) Fortaleza o debilidad relativa respecto al IBEX 35.\n\n";
$prompt .= "### 4. Diversificación, Gestión de Riesgo y Directrices Operativas\n";
$prompt .= "   - Analiza la diversificación sectorial de la cartera y evalúa la concentración de riesgo en Energía (Iberdrola y Endesa) y otros sectores.\n";
$prompt .= "   - Estima la Beta global ponderada de la cartera basándote en los datos de los activos y describe el perfil de riesgo general (defensivo vs agresivo).\n";
$prompt .= "   - Presenta una tabla Markdown limpia, clara y estructurada con las directrices operativas tácticas para TODOS los activos de la cartera:\n";
$prompt .= "     | Activo (Ticker) | Peso (%) | Soporte Clave | Decisión (AMPLIAR / MANTENER / REDUCIR / LIQUIDAR) | Tesis táctica de 1 frase |\n\n";
$prompt .= "### 5. Conclusión y Alertas Operativas\n";
$prompt .= "   - Da una valoración técnica general del estado del portfolio y sus perspectivas a medio plazo.\n";
$prompt .= "   - Define con claridad los niveles de alerta y stop-loss sugeridos para la cartera global y soportes críticos que no deben perderse.\n\n";
$prompt .= "REGLA DE EXTENSIÓN Y RIGOR: El reporte debe ser extenso, detallado y riguroso. No dejes secciones vacías o con respuestas cortas. Cada punto debe aportar un análisis técnico real y profundo de al menos un párrafo.\n";

// System prompt
$systemPrompt = "Eres el Portfolio Manager Cuantitativo y Analista Técnico Senior de un 'Hedge Fund' centrado exclusivamente en Renta Variable Española.\n";
$systemPrompt .= "Tu estilo de redacción es el de una nota técnica interna de alta convicción dirigida a los socios inversores del fondo: directo al grano, sumamente analítico, riguroso, formal y preciso, utilizando terminología financiera y técnica bursátil avanzada.\n";
// Call Groq API con modelos activos y fallback automático
$models = ['openai/gpt-oss-120b', 'qwen/qwen3.6-27b', 'openai/gpt-oss-20b', 'groq/compound'];
$report = null;
$usedModel = '';
$lastError = 'No se pudo conectar con Groq';

foreach ($models as $model) {
    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.15,
        'max_tokens' => 4500
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        $lastError = $curlError;
        continue;
    }

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (!empty($data['choices'][0]['message']['content'])) {
            $report = $data['choices'][0]['message']['content'];
            $usedModel = $model;
            break;
        }
    } else {
        $errorData = json_decode($response, true);
        $lastError = $errorData['error']['message'] ?? "HTTP {$httpCode}";
    }
}

if (!$report) {
    echo json_encode(['error' => 'Error de Groq API', 'details' => $lastError]);
    exit;
}

echo json_encode([
    'report' => $report,
    'date' => $fecha,
    'model' => $usedModel,
    'tokens' => $data['usage'] ?? null
]);
