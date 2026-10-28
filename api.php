<?php
// api.php — Endpoint de consulta individual con seguridad y validación
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

// VALIDACIÓN DE SEGURIDAD
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
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

class StockAPI
{
    public static function getQuote($ticker)
    {
        $ticker = trim($ticker);
        if (!preg_match('/^[A-Z0-9.\-^]{1,20}$/', $ticker)) {
            return false;
        }

        $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?interval=1d&range=1y";

        $cookieDir = __DIR__ . '/private_cache';
        if (!is_dir($cookieDir)) @mkdir($cookieDir, 0755, true);
        $cookieFile = $cookieDir . "/yahoo_cookie_" . md5($ticker) . ".tmp";

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Language: en-US,en;q=0.9,es;q=0.8',
            'Connection: keep-alive',
            'Referer: https://finance.yahoo.com/',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => 'gzip, deflate'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            return false;
        }

        $json = json_decode($response, true);

        if (isset($json['chart']['result'][0]['meta'])) {
            $meta = $json['chart']['result'][0]['meta'];
            $indicators = $json['chart']['result'][0]['indicators']['quote'][0] ?? [];

            $history = [];
            if (isset($json['chart']['result'][0]['timestamp']) && !empty($indicators['close'])) {
                $timestamps = $json['chart']['result'][0]['timestamp'];
                $closes = $indicators['close'];
                foreach ($timestamps as $k => $ts) {
                    if (isset($closes[$k]) && $closes[$k] !== null) {
                        $history[] = ['x' => $ts, 'y' => round($closes[$k], 4)];
                    }
                }
            }

            return [
                'price' => $meta['regularMarketPrice'] ?? 0,
                'previous_close' => $meta['chartPreviousClose'] ?? ($meta['regularMarketPreviousClose'] ?? 0),
                'history' => json_encode($history),
                'pe' => 0,
                'cap' => 0,
                'target' => 0,
                'div' => 0,
                'beta' => 0
            ];
        }

        return false;
    }
}

// LÓGICA DE SALIDA
$action = $_GET['action'] ?? '';
$ticker = $_GET['ticker'] ?? '';

if ($action === 'quote' && $ticker) {
    if (!preg_match('/^[A-Z0-9.\-^]{1,20}$/', $ticker)) {
        die(json_encode(['error' => 'Invalid ticker format']));
    }
    $data = StockAPI::getQuote($ticker);
    if ($data) echo json_encode($data);
    else echo json_encode(['error' => 'Yahoo bloqueado o sin datos']);
} else {
    echo json_encode(['error' => 'Params missing']);
}
