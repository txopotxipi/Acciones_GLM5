<?php
// ajax_analisis.php
require_once 'api.php';

if (isset($_GET['ticker'])) {
    $ticker = $_GET['ticker'];

    // Obtenemos datos frescos para el modal
    $fundamentals = StockAPI::getFundamentals($ticker);
    $history = StockAPI::getHistory($ticker);

    echo json_encode([
        'fundamentals' => $fundamentals,
        'history' => $history
    ]);
}
