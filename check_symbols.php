<?php

require_once "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();

$manager = app(\App\Bot\Exchange\ExchangeManager::class);
$exchange = $manager->create();

echo count($exchange->markets) . " markets loaded\n";

foreach (['BTCUSDT', 'BTC/USDT', 'btcusdt', 'BTC-USDT', 'BTC/USDTT'] as $s) {
    echo $s . ': ' . (isset($exchange->markets[$s]) ? 'YES' : 'NO') . "\n";
}

// Print first 10 markets
echo "\nFirst 10 markets:\n";
$count = 0;
foreach ($exchange->markets as $k => $v) {
    if ($count++ >= 10) break;
    echo "  $k\n";
}