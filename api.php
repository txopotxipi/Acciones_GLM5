<?php
// api.php
require_once 'config.php';

class StockAPI
{

    // 1. DATOS RÁPIDOS (Precio y Cierre Anterior)
    public static function getQuickQuote($ticker)
    {
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/$ticker?interval=1d&range=2d";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($data, true);
        if (!isset($json['chart']['result'][0]['meta'])) return false;

        $meta = $json['chart']['result'][0]['meta'];
        return [
            'price' => $meta['regularMarketPrice'],
            'previous_close' => $meta['chartPreviousClose']
        ];
    }

    // 2. DATOS FUNDAMENTALES (PER, Objetivo, etc.)
    public static function getFundamentals($ticker)
    {
        $modules = "price,summaryDetail,financialData,defaultKeyStatistics";
        $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}?modules={$modules}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        if (!$data || isset($data['error']) || empty($data['quoteSummary']['result'])) return false;

        $res = $data['quoteSummary']['result'][0];

        return [
            'market_cap' => $res['price']['marketCap']['raw'] ?? 0,
            'pe_ratio' => $res['defaultKeyStatistics']['trailingPE']['raw'] ?? 0,
            'target_price' => $res['financialData']['targetMeanPrice']['raw'] ?? 0,
            'fifty_two_week_high' => $res['summaryDetail']['fiftyTwoWeekHigh']['raw'] ?? 0,
            'fifty_two_week_low' => $res['summaryDetail']['fiftyTwoWeekLow']['raw'] ?? 0
        ];
    }

    // 3. HISTÓRICO PARA GRÁFICOS
    public static function getHistory($ticker)
    {
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}?range=60d&interval=1d";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($data, true);
        if (!isset($json['chart']['result'][0])) return [];

        $timestamps = $json['chart']['result'][0]['timestamp'];
        $closes = $json['chart']['result'][0]['indicators']['quote'][0]['close'];

        $history = [];
        foreach ($timestamps as $index => $ts) {
            if (isset($closes[$index])) {
                $history[] = ['date' => date('d M', $ts), 'close' => $closes[$index]];
            }
        }
        return $history;
    }
}
