<?php

declare(strict_types=1);

use WordPress\AiClient\AiClient;

require __DIR__ . '/bootstrap.php';

$modelId = $argv[1] ?? '';
if ($modelId === '') {
    throw new InvalidArgumentException('Usage: php examples/text.php <catalog-chat-model-id>');
}

$result = AiClient::prompt('Explain WordPress hooks in two sentences.')
    ->usingModel($registry->getProviderModel('apimart', $modelId))
    ->generateTextResult();

fwrite(STDOUT, $result->toText() . PHP_EOL);
fwrite(STDOUT, json_encode($result->getTokenUsage()->toArray(), JSON_PRETTY_PRINT) . PHP_EOL);
