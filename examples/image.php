<?php

declare(strict_types=1);

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

require __DIR__ . '/bootstrap.php';

$config = ModelConfig::fromArray([
    'outputFileType' => FileTypeEnum::remote()->value,
    'outputMimeType' => 'image/png',
    'outputMediaAspectRatio' => '3:2',
    'customOptions' => [
        'apimart_poll_timeout' => 120.0,
        'apimart_poll_interval' => 2.0,
    ],
]);

$result = AiClient::prompt('A small lighthouse in a watercolor landscape.')
    ->usingModel($registry->getProviderModel('apimart', 'gpt-image-1-official'))
    ->usingModelConfig($config)
    ->generateImageResult();

fwrite(STDOUT, $result->toImageFile()->getUrl() . PHP_EOL);
fwrite(STDOUT, json_encode($result->getAdditionalData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

// To edit instead, append ->withFile('https://your-public-host/reference.png', 'image/png')
// before generateImageResult(). The model catalog must advertise Image to Image.
