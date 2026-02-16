<?php
// cron_update.php — Yahoo Finance v8 data fetcher
// Run manually via browser: https://tudominio.com/cron_update.php
// Or set as cron job on InfinityFree
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

// All tickers from portfolio  
$stmt = $pdo->query("SELECT ticker, ticker_yahoo FROM valores");
$tickers = $stmt->fetchAll();

$results = [];
$errorCount = 0;

foreach ($tickers as $row) {
    $symbol = $row['ticker_yahoo'] ?: $row['ticker'];
    $data = fetchYahooData($symbol);

    if ($data) {
        try {
            // Only overwrite fundamental fields if API returned real values (not 0)
            $sql = "UPDATE valores SET
                precio_actual = :p,
                precio_cierre_anterior = :pc,
                high_last = :h_last,
                low_last = :l_last,
                pe_ratio = IF(:pe > 0, :pe2, pe_ratio),
                market_cap = IF(:cap > 0, :cap2, market_cap),
                target_price = IF(:t > 0, :t2, target_price),
                dividend_yield = IF(:d > 0, :d2, dividend_yield),
                beta = IF(:b > 0, :b2, beta),
                fifty_two_week_high = IF(:h52 > 0, :h522, fifty_two_week_high),
                fifty_two_week_low = IF(:l52 > 0, :l522, fifty_two_week_low),
                avg_volume = IF(:vol > 0, :vol2, avg_volume),
                price_to_book = IF(:pb > 0, :pb2, price_to_book),
                eps = IF(:eps > 0, :eps2, eps),
                revenue = IF(:rev > 0, :rev2, revenue),
                history_json = :hist,
                ultima_actualizacion = NOW()
                WHERE ticker = :ticker OR ticker_yahoo = :ticker2";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':p' => $data['price'],
                ':pc' => $data['previous_close'],
                ':h_last' => $data['high'],
                ':l_last' => $data['low'],
                ':pe' => $data['pe'],
                ':pe2' => $data['pe'],
                ':cap' => $data['market_cap'],
                ':cap2' => $data['market_cap'],
                ':t' => $data['target_price'],
                ':t2' => $data['target_price'],
                ':d' => $data['dividend_yield'],
                ':d2' => $data['dividend_yield'],
                ':b' => $data['beta'],
                ':b2' => $data['beta'],
                ':h52' => $data['fifty_two_week_high'],
                ':h522' => $data['fifty_two_week_high'],
                ':l52' => $data['fifty_two_week_low'],
                ':l522' => $data['fifty_two_week_low'],
                ':vol' => $data['avg_volume'],
                ':vol2' => $data['avg_volume'],
                ':pb' => $data['price_to_book'],
                ':pb2' => $data['price_to_book'],
                ':eps' => $data['eps'],
                ':eps2' => $data['eps'],
                ':rev' => $data['revenue'],
                ':rev2' => $data['revenue'],
                ':hist' => $data['history_json'],
                ':ticker' => $row['ticker'],
                ':ticker2' => $symbol,
            ]);

            $results[] = ['ticker' => $symbol, 'status' => 'OK', 'price' => $data['price']];
        } catch (Exception $e) {
            $results[] = ['ticker' => $symbol, 'status' => 'DB_ERROR', 'error' => $e->getMessage()];
            $errorCount++;
        }
    } else {
        $results[] = ['ticker' => $symbol, 'status' => 'FETCH_ERROR'];
        $errorCount++;
    }

    usleep(500000); // 0.5s delay to avoid rate limiting
}

echo json_encode([
    'success' => $errorCount === 0,
    'updated' => count($results) - $errorCount,
    'errors' => $errorCount,
    'results' => $results,
    'timestamp' => date('Y-m-d H:i:s')
], JSON_PRETTY_PRINT);

// ==========================================
// Yahoo Finance v8 API fetcher
// ==========================================
function fetchYahooData($ticker)
{
    $cookieJar = sys_get_temp_dir() . '/yahoo_cookie_' . md5($ticker) . '.txt';

    // Step 1: Get cookies
    $ch = curl_init("https://finance.yahoo.com/quote/{$ticker}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: text/html',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    curl_close($ch);

    // Step 2: Get chart data (1 year)
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?range=1y&interval=1d";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
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

    $price = $meta['regularMarketPrice'] ?? 0;
    // IMPORTANT: previousClose = yesterday's close. chartPreviousClose = price at START of chart range (1yr ago!)
    $previousClose = $meta['previousClose'] ?? $meta['regularMarketPreviousClose'] ?? 0;
    // Fallback: use the second-to-last close from history if available
    if ($previousClose <= 0 && count($history) >= 2) {
        $previousClose = $history[count($history) - 2]['y'];
    }

    // Step 3: Try to get fundamental data from quoteSummary
    $fundamentals = fetchFundamentals($ticker, $cookieJar);

    return [
        'price' => $price,
        'previous_close' => $previousClose,
        'high' => (!empty($indicators['high']) ? $indicators['high'][array_key_last($indicators['high'])] : 0) ?: 0,
        'low' => (!empty($indicators['low']) ? $indicators['low'][array_key_last($indicators['low'])] : 0) ?: 0,
        'pe' => $fundamentals['trailingPE'] ?? 0,
        'market_cap' => $fundamentals['marketCap'] ?? 0,
        'target_price' => $fundamentals['targetMeanPrice'] ?? 0,
        'dividend_yield' => $fundamentals['dividendYield'] ?? 0,
        'beta' => $fundamentals['beta'] ?? 0,
        'fifty_two_week_high' => $meta['fiftyTwoWeekHigh'] ?? $fundamentals['fiftyTwoWeekHigh'] ?? 0,
        'fifty_two_week_low' => $meta['fiftyTwoWeekLow'] ?? $fundamentals['fiftyTwoWeekLow'] ?? 0,
        'avg_volume' => $fundamentals['averageVolume'] ?? 0,
        'price_to_book' => $fundamentals['priceToBook'] ?? 0,
        'eps' => $fundamentals['trailingEps'] ?? 0,
        'revenue' => $fundamentals['totalRevenue'] ?? 0,
        'history_json' => json_encode($history),
    ];
}

function fetchFundamentals($ticker, $cookieJar)
{
    $modules = 'defaultKeyStatistics,financialData,summaryDetail';
    $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}?modules={$modules}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    if (!isset($json['quoteSummary']['result'][0])) return [];

    $data = $json['quoteSummary']['result'][0];
    $summary = $data['summaryDetail'] ?? [];
    $keyStats = $data['defaultKeyStatistics'] ?? [];
    $financial = $data['financialData'] ?? [];

    return [
        'trailingPE' => extractValue($summary, 'trailingPE'),
        'marketCap' => extractValue($summary, 'marketCap'),
        'dividendYield' => extractValue($summary, 'dividendYield') * 100,
        'beta' => extractValue($keyStats, 'beta'),
        'priceToBook' => extractValue($keyStats, 'priceToBook'),
        'trailingEps' => extractValue($keyStats, 'trailingEps'),
        'fiftyTwoWeekHigh' => extractValue($summary, 'fiftyTwoWeekHigh'),
        'fiftyTwoWeekLow' => extractValue($summary, 'fiftyTwoWeekLow'),
        'averageVolume' => extractValue($summary, 'averageVolume'),
        'targetMeanPrice' => extractValue($financial, 'targetMeanPrice'),
        'totalRevenue' => extractValue($financial, 'totalRevenue'),
    ];
}

function extractValue($data, $key)
{
    if (!isset($data[$key])) return 0;
    $val = $data[$key];
    if (is_array($val)) {
        return $val['raw'] ?? 0;
    }
    return $val ?: 0;
}
