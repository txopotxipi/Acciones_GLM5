<?php
// quick_update.php — Batch stock update endpoint
// v4: una sola petición batch a Yahoo spark para todos los tickers.
//     Si spark falla o falta algún símbolo, se usa el método antiguo por ticker.
//     ?legacy=1 fuerza el método antiguo (1 petición de 1 año + 1 de 1 día por ticker).
require_once 'config.php';
date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
set_time_limit(60);

// VALIDACIÓN DE SEGURIDAD (Compatible con Apache / InfinityFree y same-origin)
$headers = function_exists('getallheaders') ? getallheaders() : [];
$apiKey = $headers['X-Api-Key'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? $_POST['key'] ?? '';

$isSameOrigin = false;
if (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && in_array($_SERVER['HTTP_SEC_FETCH_SITE'], ['same-origin', 'same-site', 'none'])) {
    $isSameOrigin = true;
}
if (!empty($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_HOST'])) {
    if (strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false) {
        $isSameOrigin = true;
    }
}

if ($apiKey !== INTERNAL_API_KEY && !$isSameOrigin) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($host) || (strpos($host, 'lovestoblog.com') === false && strpos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false && strpos($host, 'x10.network') === false)) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden']));
    }
}

$dbErrors = [];

// ==========================================
// MARKET HOURS CHECK (IBEX 35)
// ==========================================
function isMarketOpen() {
    $now = new DateTime('now', new DateTimeZone('Europe/Madrid'));
    $day = (int)$now->format('N'); // 1=Mon ... 7=Sun
    $hour = (int)$now->format('G');
    $min  = (int)$now->format('i');
    $time = $hour * 60 + $min;

    // Weekend
    if ($day >= 6) return false;

    // 2026 Spanish stock market holidays
    $holidays = [
        '01-01', '01-06', '04-03', '04-06',
        '05-01', '08-15', '10-12', '11-02',
        '12-08', '12-25', '12-26'
    ];
    if (in_array($now->format('m-d'), $holidays)) return false;

    // Market hours: 9:00 - 17:30 CET
    return ($time >= 540 && $time <= 1050);
}

// ==========================================
// CACHE MANAGEMENT
// ==========================================
$CACHE_FILE = __DIR__ . '/cache_data.json';
$CACHE_MAX_AGE = 120; // 2 minutes

function readCache($cacheFile, $maxAge) {
    if (!file_exists($cacheFile)) return null;
    $age = time() - filemtime($cacheFile);
    if ($age > $maxAge) return null;
    $data = json_decode(file_get_contents($cacheFile), true);
    if (!$data) return null;
    $data['_cache_age'] = $age;
    return $data;
}

function writeCache($cacheFile, $data) {
    $data['_cached_at'] = date('Y-m-d H:i:s');
    // JSON compacto: el caché pasa de ~2 MB a ~80 KB y se escribe mucho más rápido.
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    // Escritura atómica: evita que index.php lea un JSON a medio escribir.
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (@rename($tmp, $cacheFile)) return true;
    @unlink($tmp);
    return file_put_contents($cacheFile, $json, LOCK_EX) !== false;
}

// Deja solo los campos que consume el frontend. Evita enviar 3 copias del
// historial de cada acción en cada actualización.
function lightenStock($stock) {
    if (!is_array($stock)) return [];
    $out = [];
    $keys = [
        'price', 'previous_close', 'high52', 'low52',
        'rsi_14', 'sma_20', 'sma_50', 'volatilidad',
        'tech_score', 'tech_signal', 'high', 'low', 'close_2025'
    ];
    foreach ($keys as $key) {
        if (array_key_exists($key, $stock)) {
            $out[$key] = $stock[$key];
        }
    }
    return $out;
}

// ==========================================
// MAIN LOGIC
// ==========================================

// Accept tickers from query param or fetch all from DB
$requestedTickers = isset($_GET['tickers']) ? explode(',', $_GET['tickers']) : [];
$requestedTickers = array_values(array_filter(array_map('trim', $requestedTickers), function ($t) {
    return $t !== '';
}));
$forceRefresh = isset($_GET['force']);
$includeIbex = isset($_GET['ibex']) || empty($requestedTickers);
$useSpark = !isset($_GET['legacy']);

// If no tickers specified, get all from DB
if (empty($requestedTickers)) {
    $stmt = $pdo->query("SELECT ticker, ticker_yahoo FROM valores WHERE num_acciones > 0");
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $requestedTickers[] = $r['ticker_yahoo'] ?: $r['ticker'];
    }
}

// Check cache first (unless forced)
$isMarket = isMarketOpen();
$effectiveMaxAge = $isMarket ? $CACHE_MAX_AGE : 86400 * 7; // Fuera de horario la caché se preserva

// Una sola lectura del caché: sirve para el hit de caché y para preservar
// portfolio_history / ibex_history al escribir el nuevo caché.
$cached = readCache($CACHE_FILE, PHP_INT_MAX);
$cacheAge = isset($cached['_cache_age']) ? (int)$cached['_cache_age'] : PHP_INT_MAX;

if (!$forceRefresh && $cached && $cacheAge <= $effectiveMaxAge && !empty($cached['stocks'])) {
    // Filter cached data to only requested tickers
    $response = [
        'source' => 'cache',
        'cache_age' => $cacheAge,
        'market_open' => $isMarket,
        'timestamp' => $cached['_cached_at'] ?? date('Y-m-d H:i:s'),
        'stocks' => [],
    ];
    foreach ($requestedTickers as $t) {
        if (isset($cached['stocks'][$t])) {
            $response['stocks'][$t] = lightenStock($cached['stocks'][$t]);
        }
    }
    if ($includeIbex && isset($cached['ibex'])) {
        $response['ibex'] = $cached['ibex'];
    }
    echo json_encode($response);
    exit;
}

// If market is closed and we have DB data (only fallback if cache didn't exist)
if (!$isMarket && !$forceRefresh) {
    $response = buildResponseFromDB($pdo, $requestedTickers, $includeIbex);
    $response['source'] = 'database';
    $response['market_open'] = false;
    echo json_encode($response);
    exit;
}

// ==========================================
// FETCH FRESH DATA FROM YAHOO FINANCE
// ==========================================

// Use a single shared cookie jar for all requests in this session (in private_cache)
$cookieDir = __DIR__ . '/private_cache';
if (!is_dir($cookieDir)) @mkdir($cookieDir, 0755, true);
$cookieJar = $cookieDir . '/yahoo_batch_cookie_' . date('Ymd') . '.tmp';

// Warm up cookies with a single request (instead of one per ticker)
if (!file_exists($cookieJar) || filesize($cookieJar) === 0) {
    $ch = curl_init('https://finance.yahoo.com/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: text/html',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$batchTickers = $requestedTickers;
if ($includeIbex && !in_array('^IBEX', $batchTickers, true)) {
    $batchTickers[] = '^IBEX';
}

$stocks = [];
$errors = [];
$sparkData = [];

// 1. Una sola petición batch: precios + historial diario de todos los tickers.
if ($useSpark && !empty($batchTickers)) {
    $sparkData = fetchSparkBatch($batchTickers, $cookieJar);
}

// 2. Fallback por ticker solo para los símbolos que spark no haya devuelto.
foreach ($requestedTickers as $ticker) {
    $ticker = trim($ticker);
    if (empty($ticker) || $ticker === '^IBEX') continue;

    if (isset($sparkData[$ticker])) {
        $stocks[$ticker] = $sparkData[$ticker];
        continue;
    }

    $data = fetchQuick($ticker, $cookieJar, null);
    if ($data) {
        $stocks[$ticker] = $data;
    } else {
        $errors[] = $ticker;
    }
}

// IBEX35
$ibexData = null;
if ($includeIbex) {
    if (isset($sparkData['^IBEX'])) {
        $ibexData = $sparkData['^IBEX'];
    } else {
        $ibexData = fetchQuick('^IBEX', $cookieJar, null);
    }
}

// 3. Transacción PDO atómica ultrarrápida (todos los UPDATEs en milisegundos)
try {
    $pdo->beginTransaction();
    foreach ($stocks as $ticker => $data) {
        updateDB($pdo, $ticker, $data);
    }
    if ($ibexData) {
        updateDB($pdo, '^IBEX', $ibexData);
    }
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $dbErrors[] = "Transacción revertida: " . $e->getMessage();
}

// Build response (solo precios y datos técnicos; sin historiales)
$response = [
    'source' => 'yahoo',
    'market_open' => $isMarket,
    'timestamp' => date('Y-m-d H:i:s'),
    'stocks' => [],
    'errors' => $errors,
    'db_errors' => $dbErrors,
];
foreach ($stocks as $ticker => $data) {
    $response['stocks'][$ticker] = lightenStock($data);
}

if ($ibexData) {
    $response['ibex'] = [
        'price' => $ibexData['price'],
        'previous_close' => $ibexData['previous_close'],
        'change' => $ibexData['previous_close'] > 0
            ? round(($ibexData['price'] - $ibexData['previous_close']) / $ibexData['previous_close'] * 100, 2)
            : 0,
        'diff' => round($ibexData['price'] - $ibexData['previous_close'], 2),
    ];
}

// Preservar y actualizar snapshot de hoy en portfolio_history
$portfolioHistory = $cached['portfolio_history'] ?? [];

$totalValHoy = 0;
try {
    $sharesStmt = $pdo->query("SELECT ticker, ticker_yahoo, num_acciones, precio_actual FROM valores WHERE num_acciones > 0");
    foreach ($sharesStmt->fetchAll() as $sRow) {
        $sym = $sRow['ticker_yahoo'] ?: $sRow['ticker'];
        $p = isset($stocks[$sym]['price']) ? (float)$stocks[$sym]['price'] : (float)$sRow['precio_actual'];
        $totalValHoy += ((float)$sRow['num_acciones'] * $p);
    }
} catch (Exception $e) {}

if ($totalValHoy > 0 && !empty($portfolioHistory)) {
    $todayDate = date('Y-m-d');
    $todayTs = strtotime($todayDate);
    $found = false;
    foreach ($portfolioHistory as &$ph) {
        if ($ph['date'] === $todayDate) {
            $ph['y'] = round($totalValHoy, 2);
            $found = true;
            break;
        }
    }
    unset($ph);
    if (!$found) {
        $portfolioHistory[] = [
            'x' => $todayTs,
            'y' => round($totalValHoy, 2),
            'date' => $todayDate
        ];
    }
}

// Write compact cache (sin historiales duplicados; index.php solo usa precios,
// portfolio_history e ibex_history del caché).
$cacheData = [
    'source' => 'yahoo',
    'market_open' => $isMarket,
    'timestamp' => date('Y-m-d H:i:s'),
    'stocks' => $response['stocks'],
    'ibex' => $response['ibex'] ?? null,
    'portfolio_history' => $portfolioHistory,
    'ibex_history' => $cached['ibex_history'] ?? [],
    'errors' => $errors,
    'db_errors' => $dbErrors,
];
writeCache($CACHE_FILE, $cacheData);

echo json_encode($response);

// ==========================================
// FUNCTIONS
// ==========================================

/**
 * Una sola petición batch a Yahoo spark.
 * Devuelve [symbol => datos] con el mismo formato que fetchQuick().
 * spark no da high/low históricos: se conservan los existentes en mergeHistories().
 */
function fetchSparkBatch($tickers, $cookieJar) {
    $tickers = array_values(array_unique(array_filter(array_map('trim', $tickers), function ($t) {
        return $t !== '';
    })));
    if (empty($tickers)) return [];

    $symbolsStr = implode(',', array_map('rawurlencode', $tickers));
    $url = "https://query1.finance.yahoo.com/v7/finance/spark?symbols={$symbolsStr}&range=1y&interval=1d";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Accept-Language: en-US,en;q=0.9,es;q=0.8',
            'Referer: https://finance.yahoo.com/',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return [];

    $json = json_decode($response, true);
    $results = $json['spark']['result'] ?? [];
    if (!is_array($results)) return [];

    $out = [];
    foreach ($results as $r) {
        $symbol = $r['symbol'] ?? '';
        $resp = $r['response'][0] ?? null;
        if (!$symbol || !is_array($resp)) continue;

        $meta = $resp['meta'] ?? [];
        $timestamps = $resp['timestamp'] ?? [];
        $closes = $resp['indicators']['quote'][0]['close'] ?? [];
        if (!is_array($timestamps) || !is_array($closes)) continue;

        $history = [];
        foreach ($timestamps as $i => $ts) {
            $close = $closes[$i] ?? null;
            if ($close !== null && is_numeric($close) && (float)$close > 0) {
                $history[] = [
                    'x' => (int)$ts,
                    'y' => round((float)$close, 4),
                ];
            }
        }
        if (empty($history)) continue;

        $price = (float)($meta['regularMarketPrice'] ?? 0);
        if ($price <= 0) {
            $price = (float)$history[count($history) - 1]['y'];
        }

        // spark no incluye previousClose en el meta; se deriva de la variación
        // diaria (exacta) y, si no está, del penúltimo cierre.
        $previousClose = 0.0;
        if (isset($meta['previousClose']) && (float)$meta['previousClose'] > 0) {
            $previousClose = (float)$meta['previousClose'];
        }
        if ($previousClose <= 0 && isset($meta['regularMarketChangePercent']) && is_numeric($meta['regularMarketChangePercent'])) {
            $chg = (float)$meta['regularMarketChangePercent'];
            if ($chg > -100) {
                $previousClose = $price / (1 + ($chg / 100));
            }
        }
        if ($previousClose <= 0 && count($history) >= 2) {
            $previousClose = (float)$history[count($history) - 2]['y'];
        }

        // high/low del día para el último punto (el resto se conserva del historial existente)
        $lastIdx = count($history) - 1;
        $dayHigh = (float)($meta['regularMarketDayHigh'] ?? 0);
        $dayLow = (float)($meta['regularMarketDayLow'] ?? 0);
        if ($dayHigh > 0) $history[$lastIdx]['h'] = round($dayHigh, 4);
        if ($dayLow > 0) $history[$lastIdx]['l'] = round($dayLow, 4);

        $high52 = (float)($meta['fiftyTwoWeekHigh'] ?? 0);
        $low52 = (float)($meta['fiftyTwoWeekLow'] ?? 0);

        $out[$symbol] = buildStockData($price, $previousClose, $history, $high52, $low52);
    }

    return $out;
}

/**
 * Cálculos técnicos compartidos por spark y por el fallback por ticker.
 */
function buildStockData($price, $previousClose, array $history, $high52 = 0, $low52 = 0) {
    $price = (float)$price;
    $previousClose = (float)$previousClose;

    $priceList = [];
    foreach ($history as $p) {
        if (isset($p['y']) && is_numeric($p['y']) && (float)$p['y'] > 0) {
            $priceList[] = (float)$p['y'];
        }
    }
    $n = count($priceList);

    $sma20 = null;
    if ($n >= 20) {
        $slice20 = array_slice($priceList, -20);
        $sma20 = array_sum($slice20) / 20;
    }
    $sma50 = null;
    if ($n >= 50) {
        $slice50 = array_slice($priceList, -50);
        $sma50 = array_sum($slice50) / 50;
    }

    $rsi = null;
    if ($n >= 15) {
        $gains = 0; $losses = 0;
        for ($i = $n - 14; $i < $n; $i++) {
            $diff = $priceList[$i] - $priceList[$i - 1];
            if ($diff > 0) $gains += $diff; else $losses -= $diff;
        }
        $avgGain = $gains / 14;
        $avgLoss = $losses / 14;
        $rsi = ($avgLoss == 0) ? 100 : (100 - (100 / (1 + ($avgGain / $avgLoss))));
    }

    $vol = null;
    if ($n >= 30) {
        $slice30 = array_slice($priceList, -30);
        $rets = [];
        for ($i = 1; $i < count($slice30); $i++) {
            if ($slice30[$i - 1] > 0) {
                $rets[] = ($slice30[$i] - $slice30[$i - 1]) / $slice30[$i - 1];
            }
        }
        if (!empty($rets)) {
            $mean = array_sum($rets) / count($rets);
            $variance = 0;
            foreach ($rets as $r) $variance += pow($r - $mean, 2);
            $vol = sqrt($variance / count($rets)) * sqrt(252) * 100;
        }
    }

    $techScore = 0;
    if ($rsi !== null) {
        if ($rsi < 30) $techScore += 20;
        else if ($rsi > 70) $techScore -= 20;
    }
    if ($price > 0 && $sma20 && $sma50) {
        if ($price > $sma20 && $sma20 > $sma50) $techScore += 15;
        else if ($price < $sma20 && $sma20 < $sma50) $techScore -= 15;
    }
    $techSignal = 'MANTENER';
    if ($techScore >= 15) $techSignal = 'COMPRA';
    else if ($techScore >= 5) $techSignal = 'COMPRA MOD.';
    else if ($techScore <= -15) $techSignal = 'VENTA';
    else if ($techScore <= -5) $techSignal = 'PRECAUCIÓN';

    $high52 = (float)$high52;
    $low52 = (float)$low52;
    if ($high52 <= 0 && !empty($priceList)) $high52 = max($priceList);
    if ($low52 <= 0 && !empty($priceList)) $low52 = min($priceList);

    return [
        'price' => round($price, 4),
        'previous_close' => round($previousClose, 4),
        'high52' => round($high52, 4),
        'low52' => round($low52, 4),
        'rsi_14' => $rsi !== null ? round($rsi, 2) : null,
        'sma_20' => $sma20 !== null ? round($sma20, 4) : null,
        'sma_50' => $sma50 !== null ? round($sma50, 4) : null,
        'volatilidad' => $vol !== null ? round($vol, 2) : null,
        'tech_score' => $techScore,
        'tech_signal' => $techSignal,
        'history' => $history,
    ];
}

/**
 * Fallback antiguo: chart de 1 año + chart de 1 día por ticker.
 * Solo se ejecuta si spark falla o con ?legacy=1.
 */
function fetchQuick($ticker, $cookieJar, $realtime = null) {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?range=1y&interval=1d";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Accept-Language: en-US,en;q=0.9,es;q=0.8',
            'Referer: https://finance.yahoo.com/',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $json = json_decode($response, true);
    if (!isset($json['chart']['result'][0])) return null;

    $result = $json['chart']['result'][0];
    $meta = $result['meta'] ?? [];
    $indicators = $result['indicators']['quote'][0] ?? [];
    $timestamps = $result['timestamp'] ?? [];

    // Build history
    $history = [];
    foreach ($timestamps as $i => $ts) {
        $close = $indicators['close'][$i] ?? null;
        if ($close !== null) {
            $history[] = [
                'x' => $ts,
                'y' => round($close, 4),
                'h' => round($indicators['high'][$i] ?? $close, 4),
                'l' => round($indicators['low'][$i] ?? $close, 4),
            ];
        }
    }

    $price = $realtime['price'] ?? $meta['regularMarketPrice'] ?? 0;

    // Intentar obtener el previousClose real y ultra-preciso de la metadata del chart de 1 día
    $url1d = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?range=1d";
    $ch1d = curl_init($url1d);
    curl_setopt_array($ch1d, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res1d = curl_exec($ch1d);
    curl_close($ch1d);

    $prevCloseFromMeta = 0;
    if ($res1d) {
        $json1d = json_decode($res1d, true);
        $meta1d = $json1d['chart']['result'][0]['meta'] ?? [];
        $prevCloseFromMeta = $meta1d['previousClose'] ?? $meta1d['chartPreviousClose'] ?? 0;
    }

    $previousClose = $prevCloseFromMeta ?: ($realtime['previous_close'] ?? $meta['previousClose'] ?? $meta['regularMarketPreviousClose'] ?? 0);
    if ($previousClose <= 0 && count($history) >= 2) {
        $previousClose = $history[count($history) - 2]['y'];
    }

    $high52 = (float)($meta['fiftyTwoWeekHigh'] ?? 0);
    $low52 = (float)($meta['fiftyTwoWeekLow'] ?? 0);

    return buildStockData($price, $previousClose, $history, $high52, $low52);
}

function mergeHistories($existingJson, $newHistory) {
    if (empty($existingJson)) return $newHistory;
    $existing = json_decode($existingJson, true);
    if (!is_array($existing) || empty($existing)) return $newHistory;
    if (empty($newHistory)) return $existing;

    $map = [];
    foreach ($existing as $p) {
        if (isset($p['x']) && isset($p['y'])) {
            $day = floor($p['x'] / 86400) * 86400;
            $map[$day] = $p;
        }
    }
    foreach ($newHistory as $p) {
        if (isset($p['x']) && isset($p['y'])) {
            $day = floor($p['x'] / 86400) * 86400;
            // spark solo trae cierres; conservar high/low del punto existente
            // para no perderlos al fusionar el mismo día.
            if (isset($map[$day])) {
                if (!isset($p['h']) && isset($map[$day]['h'])) $p['h'] = $map[$day]['h'];
                if (!isset($p['l']) && isset($map[$day]['l'])) $p['l'] = $map[$day]['l'];
            }
            $map[$day] = $p;
        }
    }
    ksort($map);
    return array_values($map);
}

function updateDB($pdo, $ticker, $data) {
    global $dbErrors;
    try {
        // Consultar historial existente para fusionar y nunca perder días anteriores
        $prevHistStmt = $pdo->prepare("SELECT history_json FROM valores WHERE ticker = :t1 OR ticker_yahoo = :t2 LIMIT 1");
        $prevHistStmt->execute([':t1' => $ticker, ':t2' => $ticker]);
        $existingRow = $prevHistStmt->fetch();
        $existingHistJson = $existingRow['history_json'] ?? '[]';

        $mergedHistory = mergeHistories($existingHistJson, $data['history'] ?? []);
        $historyJson = json_encode($mergedHistory);
        if ($historyJson === false) $historyJson = '[]';

        // IMPORTANTE: con PDO::ATTR_EMULATE_PREPARES = false (config.php) un mismo
        // placeholder nombrado NO puede repetirse en la consulta: MySQL/PDO lanzan
        // "SQLSTATE[HY093]: Invalid parameter number". Por eso cada condición del IF
        // usa un parámetro distinto (sufijo "c" = condición, "v" = valor).
        $sql = "UPDATE valores SET
            precio_actual = :p,
            precio_cierre_anterior = :pc,
            fifty_two_week_high = IF(:h52c > 0, :h52v, fifty_two_week_high),
            fifty_two_week_low = IF(:l52c > 0, :l52v, fifty_two_week_low),
            rsi_14 = IF(:rsic IS NOT NULL, :rsiv, rsi_14),
            sma_20 = IF(:sma20c IS NOT NULL, :sma20v, sma_20),
            sma_50 = IF(:sma50c IS NOT NULL, :sma50v, sma_50),
            volatilidad = IF(:volc IS NOT NULL, :volv, volatilidad),
            tech_score = :ts_score,
            tech_signal = :ts_signal,
            history_json = :hist,
            ultima_actualizacion = :ts
            WHERE ticker = :ticker OR ticker_yahoo = :ticker2";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':p' => $data['price'],
            ':pc' => $data['previous_close'],
            ':h52c' => $data['high52'] ?? 0,
            ':h52v' => $data['high52'] ?? 0,
            ':l52c' => $data['low52'] ?? 0,
            ':l52v' => $data['low52'] ?? 0,
            ':rsic' => $data['rsi_14'] ?? null,
            ':rsiv' => $data['rsi_14'] ?? null,
            ':sma20c' => $data['sma_20'] ?? null,
            ':sma20v' => $data['sma_20'] ?? null,
            ':sma50c' => $data['sma_50'] ?? null,
            ':sma50v' => $data['sma_50'] ?? null,
            ':volc' => $data['volatilidad'] ?? null,
            ':volv' => $data['volatilidad'] ?? null,
            ':ts_score' => $data['tech_score'] ?? 0,
            ':ts_signal' => $data['tech_signal'] ?? 'MANTENER',
            ':hist' => $historyJson,
            ':ts' => date('Y-m-d H:i:s'),
            ':ticker' => $ticker,
            ':ticker2' => $ticker,
        ]);

        if ($stmt->rowCount() === 0) {
            $dbErrors[] = "No se afectó ninguna fila para el ticker: $ticker. Verifica si existe en la BD.";
        }
    } catch (Exception $e) {
        $dbErrors[] = "Error en DB para $ticker: " . $e->getMessage();
    }
}

function buildResponseFromDB($pdo, $tickers, $includeIbex) {
    $stocks = [];
    $stmt = $pdo->query("SELECT * FROM valores WHERE num_acciones > 0");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $t = $row['ticker_yahoo'] ?: $row['ticker'];
        $history = json_decode($row['history_json'] ?: '[]', true);
        $lastPoint = is_array($history) && !empty($history) ? end($history) : [];
        $stocks[$t] = [
            'price' => (float)$row['precio_actual'],
            'previous_close' => (float)$row['precio_cierre_anterior'],
            'high' => (float)($lastPoint['h'] ?? 0),
            'low' => (float)($lastPoint['l'] ?? 0),
        ];
    }

    $response = [
        'timestamp' => date('Y-m-d H:i:s'),
        'stocks' => $stocks,
    ];

    if ($includeIbex) {
        $ibexStmt = $pdo->query("SELECT precio_actual, precio_cierre_anterior FROM valores WHERE ticker = '^IBEX' LIMIT 1");
        $ibexRow = $ibexStmt->fetch();
        if ($ibexRow) {
            $price = (float)$ibexRow['precio_actual'];
            $prev = (float)$ibexRow['precio_cierre_anterior'];
            $response['ibex'] = [
                'price' => $price,
                'previous_close' => $prev,
                'change' => $prev > 0 ? round(($price - $prev) / $prev * 100, 2) : 0,
                'diff' => round($price - $prev, 2),
            ];
        }
    }

    return $response;
}
