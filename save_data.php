<?php
// save_data.php — Protegido con API Key interna
require_once 'config.php';
date_default_timezone_set('Europe/Madrid');
header('Content-Type: application/json; charset=utf-8');

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

if ($apiKey !== INTERNAL_API_KEY && !$isSameOrigin) {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['ticker'])) {
    die(json_encode(['error' => 'Datos inválidos']));
}

$ticker = trim($data['ticker']);
if (!preg_match('/^[A-Z0-9.\-^]{1,20}$/', $ticker)) {
    die(json_encode(['error' => 'Ticker inválido']));
}

$clean = function ($v) {
    return is_numeric($v) ? $v : 0;
};

try {
    $sql = "UPDATE valores SET 
            precio_actual = :p,
            precio_cierre_anterior = :pc,
            trailing_pe = IF(:pe > 0, :pe2, trailing_pe),
            target_mean_price = IF(:t > 0, :t2, target_mean_price),
            dividend_yield = IF(:d > 0, :d2, dividend_yield),
            fifty_two_week_high = IF(:h52 > 0, :h522, fifty_two_week_high),
            fifty_two_week_low = IF(:l52 > 0, :l522, fifty_two_week_low),
            rsi_14 = IF(:rsi > 0, :rsi2, rsi_14),
            sma_20 = IF(:sma20 > 0, :sma202, sma_20),
            sma_50 = IF(:sma50 > 0, :sma502, sma_50),
            volatilidad = IF(:vol > 0, :vol2, volatilidad),
            tech_score = IF(:score != 0, :score2, tech_score),
            tech_signal = IF(:signal != '', :signal2, tech_signal),
            history_json = IF(:hist != '[]', :hist2, history_json),
            ultima_actualizacion = :ts
            WHERE ticker = :ticker OR ticker_yahoo = :ticker2";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':p' => $data['price'],
        ':pc' => $data['previous_close'],
        ':pe' => $clean($data['pe'] ?? null),
        ':pe2' => $clean($data['pe'] ?? null),
        ':t'   => $clean($data['target'] ?? null),
        ':t2'   => $clean($data['target'] ?? null),
        ':d'   => $clean($data['div'] ?? null),
        ':d2'   => $clean($data['div'] ?? null),
        ':h52' => $clean($data['high52'] ?? null),
        ':h522' => $clean($data['high52'] ?? null),
        ':l52' => $clean($data['low52'] ?? null),
        ':l522' => $clean($data['low52'] ?? null),
        ':rsi' => $clean($data['rsi'] ?? null),
        ':rsi2' => $clean($data['rsi'] ?? null),
        ':sma20' => $clean($data['sma20'] ?? null),
        ':sma202' => $clean($data['sma20'] ?? null),
        ':sma50' => $clean($data['sma50'] ?? null),
        ':sma502' => $clean($data['sma50'] ?? null),
        ':vol' => $clean($data['vol'] ?? null),
        ':vol2' => $clean($data['vol'] ?? null),
        ':score' => (int)($data['tech_score'] ?? 0),
        ':score2' => (int)($data['tech_score'] ?? 0),
        ':signal' => $data['tech_signal'] ?? '',
        ':signal2' => $data['tech_signal'] ?? '',
        ':hist' => is_string($data['history'] ?? null) ? $data['history'] : json_encode($data['history'] ?? []),
        ':hist2' => is_string($data['history'] ?? null) ? $data['history'] : json_encode($data['history'] ?? []),
        ':ts' => date('Y-m-d H:i:s'),
        ':ticker' => $data['ticker'],
        ':ticker2' => $data['ticker']
    ]);
    echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
