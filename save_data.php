<?php
// save_data.php
require_once 'config.php';
date_default_timezone_set('Europe/Madrid');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !isset($data['ticker'])) die('Error');

$clean = function ($v) {
    return is_numeric($v) ? $v : 0;
};

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
        ultima_actualizacion = :ts
        WHERE ticker = :ticker OR ticker_yahoo = :ticker2";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':p' => $data['price'],
    ':pc' => $data['previous_close'],
    ':h_last' => $clean($data['high_last']),
    ':l_last' => $clean($data['low_last']),
    ':pe' => $clean($data['pe']),
    ':pe2' => $clean($data['pe']),
    ':cap' => $clean($data['cap']),
    ':cap2' => $clean($data['cap']),
    ':t'   => $clean($data['target']),
    ':t2'   => $clean($data['target']),
    ':d'   => $clean($data['div']),
    ':d2'   => $clean($data['div']),
    ':b'   => $clean($data['beta']),
    ':b2'   => $clean($data['beta']),
    ':h52' => $clean($data['high52']),
    ':h522' => $clean($data['high52']),
    ':l52' => $clean($data['low52']),
    ':l522' => $clean($data['low52']),
    ':vol' => $clean($data['vol']),
    ':vol2' => $clean($data['vol']),
    ':pb'  => $clean($data['pb']),
    ':pb2'  => $clean($data['pb']),
    ':eps' => $clean($data['eps']),
    ':eps2' => $clean($data['eps']),
    ':rev' => $clean($data['rev']),
    ':rev2' => $clean($data['rev']),
    ':hist' => $data['history'] ?? '[]',
    ':ts' => date('Y-m-d H:i:s'),
    ':ticker' => $data['ticker'],
    ':ticker2' => $data['ticker']
]);
echo json_encode(['status' => 'ok']);
