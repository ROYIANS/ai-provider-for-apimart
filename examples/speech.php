<?php

declare(strict_types=1);

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

require __DIR__ . '/bootstrap.php';

$result = AiClient::prompt('Welcome to our website.')
    ->usingModel($registry->getProviderModel('apimart', 'gpt-4o-mini-tts'))
    ->usingModelConfig(ModelConfig::fromArray([
        'outputMimeType' => 'audio/wav',
        'outputSpeechVoice' => 'alloy',
        'customOptions' => ['speed' => 1.0],
    ]))
    ->convertTextToSpeechResult();

$destination = $argv[1] ?? sys_get_temp_dir() . '/apimart-speech.wav';
file_put_contents($destination, base64_decode($result->toAudioFile()->getBase64Data(), true));
fwrite(STDOUT, $destination . PHP_EOL);
