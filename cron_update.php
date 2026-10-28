<?php
// cron_update.php — Yahoo Finance v8 data fetcher (optimized)
// Run manually via browser: https://tudominio.com/cron_update.php
// Or set as cron job on InfinityFree
require_once 'config.php';
date_default_timezone_set('Europe/Madrid');

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

// All tickers from portfolio  
$stmt = $pdo->query("SELECT ticker, ticker_yahoo, history_json FROM valores");
$tickers = $stmt->fetchAll();

function mergeHistoriesCron($existingJson, $newHistoryJson) {
    if (empty($existingJson)) return $newHistoryJson;
    $existing = json_decode($existingJson, true);
    if (!is_array($existing) || empty($existing)) return $newHistoryJson;
    $newHistory = json_decode($newHistoryJson, true);
    if (!is_array($newHistory) || empty($newHistory)) return $existingJson;

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
            $map[$day] = $p;
        }
    }
    ksort($map);
    return json_encode(array_values($map));
}

// ==========================================
// FUNCIONES DE ANÁLISIS TÉCNICO (OFFLOADING)
// ==========================================
function calcSMA_cron($prices, $period) {
    $count = count($prices);
    if ($count < $period) return null;
    $sum = array_sum(array_slice($prices, -$period));
    return $sum / $period;
}

function calcRSI_cron($prices, $period = 14) {
    $count = count($prices);
    if ($count < $period + 1) return null;
    $gains = 0; $losses = 0;
    for ($i = 1; $i <= $period; $i++) {
        $diff = $prices[$i] - $prices[$i - 1];
        if ($diff > 0) $gains += $diff; else $losses -= $diff;
    }
    $avgGain = $gains / $period;
    $avgLoss = $losses / $period;
    for ($i = $period + 1; $i < $count; $i++) {
        $diff = $prices[$i] - $prices[$i - 1];
        $avgGain = ($avgGain * ($period - 1) + ($diff > 0 ? $diff : 0)) / $period;
        $avgLoss = ($avgLoss * ($period - 1) + ($diff < 0 ? -$diff : 0)) / $period;
    }
    if ($avgLoss == 0) return 100;
    return 100 - (100 / (1 + ($avgGain / $avgLoss)));
}

function calcVolatility_cron($prices) {
    if (count($prices) < 20) return null;
    $recent = array_slice($prices, -20);
    $returns = [];
    for ($i = 1; $i < count($recent); $i++) {
        if ($recent[$i-1] > 0) $returns[] = ($recent[$i] - $recent[$i-1]) / $recent[$i-1];
    }
    if (empty($returns)) return null;
    $mean = array_sum($returns) / count($returns);
    $varianceSum = 0;
    foreach ($returns as $r) $varianceSum += pow($r - $mean, 2);
    return sqrt($varianceSum / count($returns)) * sqrt(252) * 100;
}

function calcTechScore_cron($val, $prices, $price) {
    $score = 0;
    $rsi = calcRSI_cron($prices, 14);
    if ($rsi !== null) {
        if ($rsi < 30) $score += 20;
        elseif ($rsi < 40) $score += 10;
        elseif ($rsi > 70) $score -= 20;
        elseif ($rsi > 60) $score -= 5;
    }
    $sma20 = calcSMA_cron($prices, 20);
    $sma50 = calcSMA_cron($prices, 50);
    if ($sma20 !== null && $sma50 !== null && $price > 0) {
        if ($price > $sma20 && $sma20 > $sma50) $score += 20;
        elseif ($price > $sma20 || $sma20 > $sma50) $score += 10;
        elseif ($price < $sma20 && $sma20 < $sma50) $score -= 20;
        elseif ($price < $sma20 || $sma20 < $sma50) $score -= 10;
    }
    $target = (float)($val['target_price'] ?? 0);
    if ($target > 0 && $price > 0) {
        $potential = (($target - $price) / $price) * 100;
        if ($potential > 15) $score += 20;
        elseif ($potential > 5) $score += 8;
        elseif ($potential < -5) $score -= 15;
    }
    $pe = (float)($val['pe_ratio'] ?? 0);
    if ($pe > 0) {
        if ($pe < 10) $score += 15;
        elseif ($pe < 15) $score += 8;
        elseif ($pe > 22) $score -= 10;
    }
    $div = (float)($val['dividend_yield'] ?? 0);
    if ($div > 5) $score += 10;
    elseif ($div > 3) $score += 5;
    $beta = (float)($val['beta'] ?? 0);
    if ($beta > 0 && $beta < 0.7) $score += 5;
    $pb = (float)($val['price_to_book'] ?? 0);
    if ($pb > 0 && $pb < 1) $score += 5;
    elseif ($pb > 3) $score -= 5;
    $high52 = (float)($val['fifty_two_week_high'] ?? 0);
    $low52 = (float)($val['fifty_two_week_low'] ?? 0);
    if ($high52 > 0 && $low52 > 0 && ($high52 - $low52) > 0 && $price > 0) {
        $rangePos = (($price - $low52) / ($high52 - $low52)) * 100;
        if ($rangePos < 20) $score += 10;
        elseif ($rangePos > 90) $score -= 10;
    }
    return max(-100, min(100, $score));
}

function getTechSignal_cron($score) {
    if ($score >= 30) return 'COMPRAR';
    if ($score >= 10) return 'COMPRA MODERADA';
    if ($score >= -10) return 'MANTENER';
    if ($score >= -30) return 'PRECAUCIÓN';
    return 'VENDER';
}

$results = [];
$errorCount = 0;

// Single shared cookie jar for all tickers (instead of per-ticker, in private_cache)
$cookieDir = __DIR__ . '/private_cache';
if (!is_dir($cookieDir)) @mkdir($cookieDir, 0755, true);
$sharedCookieJar = $cookieDir . '/yahoo_cron_cookie_' . date('Ymd') . '.tmp';
if (!file_exists($sharedCookieJar) || filesize($sharedCookieJar) === 0) {
    $ch = curl_init('https://finance.yahoo.com/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $sharedCookieJar,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: text/html',
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ==========================================
// AUTO-ROLLOVER: Update precio_cierre_2025 (YTD reference) on New Year
// Runs once per year, during the first 5 days of January
// ==========================================
$currentMonth = (int)date('n');
$currentDay = (int)date('j');
$currentYear = (int)date('Y');
$rolloverFlag = sys_get_temp_dir() . '/ytd_rollover_' . $currentYear . '.flag';

if ($currentMonth === 1 && $currentDay <= 5 && !file_exists($rolloverFlag)) {
    // Copy last year's final precio_actual → precio_cierre_2025 for all stocks
    $pdo->exec("UPDATE valores SET precio_cierre_2025 = precio_actual WHERE precio_actual > 0");
    file_put_contents($rolloverFlag, date('Y-m-d H:i:s') . ' — YTD reference updated for ' . $currentYear);
    $results[] = ['rollover' => 'YTD reference updated for year ' . $currentYear];
}

foreach ($tickers as $row) {
    $symbol = $row['ticker_yahoo'] ?: $row['ticker'];
    $data = fetchYahooData($symbol, $sharedCookieJar);

    if ($data) {
        try {
            $mergedHistJson = mergeHistoriesCron($row['history_json'] ?? '[]', $data['history_json'] ?? '[]');

            // Cálculo técnico precalculado (Offloading)
            $rsiVal = null; $sma20Val = null; $sma50Val = null; $volVal = null; $scoreVal = 0; $signalVal = 'MANTENER';
            $histArr = json_decode($mergedHistJson, true);
            if (!empty($histArr)) {
                $pricesArr = array_column($histArr, 'y');
                if (count($pricesArr) >= 14) {
                    $rsiVal = calcRSI_cron($pricesArr, 14);
                    $sma20Val = calcSMA_cron($pricesArr, 20);
                    $sma50Val = calcSMA_cron($pricesArr, 50);
                    $volVal = calcVolatility_cron($pricesArr);
                    $scoreVal = calcTechScore_cron($data, $pricesArr, $data['price']);
                    $signalVal = getTechSignal_cron($scoreVal);
                }
            }

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
                rsi_14 = IF(:has_rsi = 1, :rsi, rsi_14),
                sma_20 = IF(:has_sma20 = 1, :sma20, sma_20),
                sma_50 = IF(:has_sma50 = 1, :sma50, sma_50),
                tech_score = :ts_score,
                tech_signal = :ts_signal,
                volatilidad = IF(:has_vol = 1, :volatilidad, volatilidad),
                ultima_actualizacion = :ts
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
                ':hist' => $mergedHistJson,
                ':has_rsi' => $rsiVal !== null ? 1 : 0,
                ':rsi' => $rsiVal !== null ? round($rsiVal, 2) : null,
                ':has_sma20' => $sma20Val !== null ? 1 : 0,
                ':sma20' => $sma20Val !== null ? round($sma20Val, 4) : null,
                ':has_sma50' => $sma50Val !== null ? 1 : 0,
                ':sma50' => $sma50Val !== null ? round($sma50Val, 4) : null,
                ':ts_score' => $scoreVal,
                ':ts_signal' => $signalVal,
                ':has_vol' => $volVal !== null ? 1 : 0,
                ':volatilidad' => $volVal !== null ? round($volVal, 2) : null,
                ':ts' => date('Y-m-d H:i:s'),
                ':ticker' => $row['ticker'],
                ':ticker2' => $symbol,
            ]);

            $results[] = ['ticker' => $symbol, 'status' => 'OK', 'price' => $data['price'], 'score' => $scoreVal, 'signal' => $signalVal];
        } catch (Exception $e) {
            $results[] = ['ticker' => $symbol, 'status' => 'DB_ERROR', 'error' => $e->getMessage()];
            $errorCount++;
        }
    } else {
        $results[] = ['ticker' => $symbol, 'status' => 'FETCH_ERROR'];
        $errorCount++;
    }

    usleep(100000); // 0.1s delay (only 8 tickers, safe with shared cookies)
}

// Write cache file for quick page loads
$cacheData = ['timestamp' => date('Y-m-d H:i:s'), '_cached_at' => date('Y-m-d H:i:s'), 'source' => 'cron', 'stocks' => []];
$stmt2 = $pdo->query("SELECT * FROM valores WHERE num_acciones > 0");
foreach ($stmt2->fetchAll() as $r) {
    $t = $r['ticker_yahoo'] ?: $r['ticker'];
    $cacheData['stocks'][$t] = [
        'price' => (float)$r['precio_actual'],
        'previous_close' => (float)$r['precio_cierre_anterior'],
        'high' => (float)($r['high_last'] ?? 0),
        'low' => (float)($r['low_last'] ?? 0),
        'history' => json_decode($r['history_json'] ?: '[]', true),
    ];
}
// IBEX data
$ibexRow = $pdo->query("SELECT precio_actual, precio_cierre_anterior FROM valores WHERE ticker = '^IBEX' LIMIT 1")->fetch();
if ($ibexRow) {
    $ip = (float)$ibexRow['precio_actual'];
    $iprev = (float)$ibexRow['precio_cierre_anterior'];
    $cacheData['ibex'] = [
        'price' => $ip,
        'previous_close' => $iprev,
        'change' => $ip - $iprev,
        'change_pct' => $iprev > 0 ? (($ip - $iprev) / $iprev) * 100 : 0
    ];
}

// Preservar y actualizar snapshot de hoy en portfolio_history
$prevCacheFile = __DIR__ . '/cache_data.json';
$portfolioHistory = [];
if (file_exists($prevCacheFile)) {
    $pJson = json_decode(file_get_contents($prevCacheFile), true);
    if (!empty($pJson['portfolio_history'])) {
        $portfolioHistory = $pJson['portfolio_history'];
    }
}

$totalValHoy = 0;
foreach ($stmt2->fetchAll() as $r) {
    $totalValHoy += ((float)$r['num_acciones'] * (float)$r['precio_actual']);
}

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
$cacheData['portfolio_history'] = $portfolioHistory;

file_put_contents(__DIR__ . '/cache_data.json', json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ==========================================
// SNAPSHOT DIARIO EN BASE DE DATOS (portfolio_snapshots)
// ==========================================
try {
    if ($totalValHoy > 0) {
        $numPos = 0;
        $sharesStmtCount = $pdo->query("SELECT COUNT(*) as c FROM valores WHERE num_acciones > 0 AND ticker != '^IBEX'");
        $numPos = (int)($sharesStmtCount->fetch()['c'] ?? 0);

        $snapStmt = $pdo->prepare("INSERT INTO portfolio_snapshots (fecha, valor_total, num_posiciones) VALUES (:f, :v, :n) ON DUPLICATE KEY UPDATE valor_total = :v2, num_posiciones = :n2");
        $snapStmt->execute([
            ':f' => date('Y-m-d'),
            ':v' => round($totalValHoy, 2),
            ':n' => $numPos,
            ':v2' => round($totalValHoy, 2),
            ':n2' => $numPos
        ]);
    }
} catch (Exception $e) {
    // Si la tabla aún no se ha creado en phpMyAdmin, se continúa sin error
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
function fetchYahooData($ticker, $cookieJar)
{
    // Cookie warmup is now done ONCE before the loop (shared cookie jar)

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

    // Step 3: Try to get fundamental data from quoteSummary
    $fundamentals = fetchFundamentals($ticker, $cookieJar);

    $price = $meta['regularMarketPrice'] ?? 0;
    // IMPORTANT: previousClose = yesterday's close. chartPreviousClose = price at START of chart range (1yr ago!)
    $previousClose = $meta['previousClose'] ?? $meta['regularMarketPreviousClose'] ?? 0;

    // Prioritize previousClose from fundamentals (summaryDetail) as it is much more reliable
    if (isset($fundamentals['previousClose']) && $fundamentals['previousClose'] > 0) {
        $previousClose = $fundamentals['previousClose'];
    }

    // Fallback: Intentar obtener el previousClose real y ultra-preciso de la metadata del chart de 1 día
    if ($previousClose <= 0) {
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

        if ($res1d) {
            $json1d = json_decode($res1d, true);
            $meta1d = $json1d['chart']['result'][0]['meta'] ?? [];
            $previousClose = $meta1d['previousClose'] ?? $meta1d['chartPreviousClose'] ?? 0;
        }
    }

    // Fallback secundario: use the second-to-last close from history if available
    if ($previousClose <= 0 && count($history) >= 2) {
        $previousClose = $history[count($history) - 2]['y'];
    }

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
        'previousClose' => extractValue($summary, 'previousClose'),
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
