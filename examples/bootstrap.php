<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

// Set this to your application's vendor/autoload.php when running from a separate checkout.
$autoload = getenv('APIMART_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
require $autoload;

if (!getenv('APIMART_API_KEY')) {
    throw new RuntimeException('Set APIMART_API_KEY before running this example.');
}
if (!class_exists(Client::class)) {
    throw new RuntimeException('Install guzzlehttp/guzzle:^7.0 in your application first.');
}

$registry = AiClient::defaultRegistry();
// Guzzle supports the SDK request options used to bound image task polling.
$registry->setHttpTransporter(new HttpTransporter(new Client(['timeout' => 30.0, 'connect_timeout' => 10.0])));
$registry->registerProvider(ApimartProvider::class);
