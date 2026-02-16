<?php
// api.php
require_once 'config.php';

class StockAPI
{

    // --- FUNCIÓN MAESTRA: Cookie Jar Strategy ---
    public static function getQuote($ticker)
    {
        // Endpoint de la API v8 (Chart) - El más fiable
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?interval=1d&range=1y";

        // 1. Archivo temporal para cookies (CRITICAL)
        $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "yahoo_cookie_" . md5($ticker) . ".txt";
        if (!file_exists($cookieFile)) touch($cookieFile);

        $ch = curl_init();

        // 2. Headers exactos de un navegador real
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Language: en-US,en;q=0.9,es;q=0.8',
            'Connection: keep-alive',
            'Referer: https://finance.yahoo.com/',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $cookieFile,   // Guardar cookies
            CURLOPT_COOKIEFILE => $cookieFile, // Enviar cookies
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_ENCODING => 'gzip, deflate' // Importante
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            // Intento fallback: Web Scraping
            return self::scrapeFallback($ticker);
        }

        $json = json_decode($response, true);

        if (isset($json['chart']['result'][0]['meta'])) {
            $meta = $json['chart']['result'][0]['meta'];
            $indicators = $json['chart']['result'][0]['indicators']['quote'][0];

            // Historial para guardar
            $history = [];
            if (isset($json['chart']['result'][0]['timestamp'])) {
                $timestamps = $json['chart']['result'][0]['timestamp'];
                $closes = $indicators['close'];
                foreach ($timestamps as $k => $ts) {
                    if (isset($closes[$k])) {
                        $history[] = ['x' => $ts, 'y' => $closes[$k]];
                    }
                }
            }

            return [
                'price' => $meta['regularMarketPrice'],
                'previous_close' => $meta['chartPreviousClose'],
                'history' => json_encode($history), // Guardamos histórico real
                'pe' => 0,
                'cap' => 0,
                'target' => 0,
                'div' => 0,
                'beta' => 0
            ];
        }

        return false;
    }

    // Fallback: Scrapear la web si la API falla
    private static function scrapeFallback($ticker)
    {
        $url = "https://finance.yahoo.com/quote/$ticker";
        $cookie = sys_get_temp_dir() . "/ycookie.txt";
        if (!file_exists($cookie)) touch($cookie);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookie,
            CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        // Regex simple para precios
        $price = 0;
        $prev = 0;
        if (preg_match('/data-field="regularMarketPrice"[^>]*>([0-9.]+)/', $html, $m)) $price = floatval($m[1]);
        if (preg_match('/data-field="regularMarketPreviousClose"[^>]*>([0-9.]+)/', $html, $m)) $prev = floatval($m[1]);

        if ($price > 0) {
            return ['price' => $price, 'previous_close' => $prev, 'history' => '[]', 'pe' => 0, 'cap' => 0, 'target' => 0, 'div' => 0, 'beta' => 0];
        }
        return false;
    }
}

// --- LOGICA DE SALIDA ---
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';
$ticker = $_GET['ticker'] ?? '';

if ($action === 'quote' && $ticker) {
    $data = StockAPI::getQuote($ticker);
    if ($data) echo json_encode($data);
    else echo json_encode(['error' => 'Yahoo bloqueado o sin datos']);
} else {
    echo json_encode(['error' => 'Params missing']);
}
