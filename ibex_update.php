<?php
// ibex_update.php — Fetch IBEX35 server-side and save to DB
// Called via AJAX from the frontend
require_once 'config.php';
date_default_timezone_set('Europe/Madrid');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$ticker = '^IBEX';

// Step 1: Get cookies from Yahoo
$cookieJar = sys_get_temp_dir() . '/yahoo_cookie_ibex.txt';

$ch = curl_init("https://finance.yahoo.com/quote/%5EIBEX");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept: text/html',
    ],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 15,
]);
curl_exec($ch);
curl_close($ch);

// Step 2: Fetch chart data (5 days for recent price + previous close)
$url = "https://query1.finance.yahoo.com/v8/finance/chart/%5EIBEX?range=5d&interval=1d";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept: application/json',
    ],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode(['error' => 'Yahoo no responde', 'httpCode' => $httpCode]);
    exit;
}

$json = json_decode($response, true);
if (!isset($json['chart']['result'][0])) {
    echo json_encode(['error' => 'Sin datos del IBEX', 'raw' => substr($response, 0, 200)]);
    exit;
}

$result = $json['chart']['result'][0];
$meta   = $result['meta'] ?? [];
$quotes = $result['indicators']['quote'][0] ?? [];
$timestamps = $result['timestamp'] ?? [];

// Get current price from meta
$price = $meta['regularMarketPrice'] ?? 0;

// Build array of valid daily closes
$dailyCloses = [];
for ($i = 0; $i < count($timestamps); $i++) {
    if (isset($quotes['close'][$i]) && $quotes['close'][$i] > 0) {
        $dailyCloses[] = ['ts' => $timestamps[$i], 'close' => $quotes['close'][$i]];
    }
}

// previousClose = cierre del penúltimo día de cotización (ej: viernes si hoy es lunes)
$previousClose = 0;
if (count($dailyCloses) >= 2) {
    $previousClose = $dailyCloses[count($dailyCloses) - 2]['close'];
}
// Fallback to meta if no history
if ($previousClose <= 0) {
    $previousClose = $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0;
}

// Fallback price from close array if meta failed
if ($price <= 0 && count($dailyCloses) > 0) {
    $price = $dailyCloses[count($dailyCloses) - 1]['close'];
}

// Calculate change
$change = $previousClose > 0 ? (($price - $previousClose) / $previousClose * 100) : 0;
$diff   = $price - $previousClose;

// Save to database
try {
    $sql = "UPDATE valores SET 
            precio_actual = :p,
            precio_cierre_anterior = :pc,
            ultima_actualizacion = :ts
            WHERE ticker = :ticker";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':p'      => round($price, 2),
        ':pc'     => round($previousClose, 2),
        ':ts'     => date('Y-m-d H:i:s'),
        ':ticker' => $ticker,
    ]);
} catch (Exception $e) {
    // DB save failed, but still return data to frontend
}

echo json_encode([
    'status'   => 'ok',
    'price'    => round($price, 2),
    'previous' => round($previousClose, 2),
    'change'   => round($change, 2),
    'diff'     => round($diff, 2),
]);
