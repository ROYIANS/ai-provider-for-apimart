<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\ApimartAiProvider\Models\ApimartTextToSpeechConversionModel;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * @covers \WordPress\ApimartAiProvider\Models\ApimartTextToSpeechConversionModel
 */
class ApimartTextToSpeechConversionModelTest extends TestCase
{
    public function testSendsAuthenticatedWavRequestAndReturnsInlineAudio(): void
    {
        $model = $this->model();
        $transport = $this->createMock(HttpTransporterInterface::class);
        $transport->expects($this->once())->method('send')->willReturnCallback(function (Request $request): Response {
            $this->assertSame('https://api.apimart.ai/v1/audio/speech', $request->getUri());
            $this->assertSame('POST', (string) $request->getMethod());
            $this->assertSame([
                'model' => 'gpt-4o-mini-tts',
                'input' => "Hello\n世界",
                'voice' => 'alloy',
                'response_format' => 'wav',
            ], $request->getData());
            return new Response(200, ['Content-Type' => 'audio/wav'], 'RIFF-audio-bytes');
        });
        $model->setHttpTransporter($transport);
        $result = $model->convertTextToSpeechResult($this->prompt(['Hello', '世界']));
        $this->assertSame('audio/wav', $result->toAudioFile()->getMimeType());
        $this->assertSame(base64_encode('RIFF-audio-bytes'), $result->toAudioFile()->getBase64Data());
        $this->assertTrue($result->toAudioFile()->isInline());
        $this->assertSame('apimart', $result->getProviderMetadata()->getId());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * @dataProvider formats
     */
    public function testMapsDocumentedAudioFormats(string $mime, string $format): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray([
            'outputMimeType' => $mime,
            'outputSpeechVoice' => 'nova',
            'customOptions' => ['speed' => 1.5],
        ]));
        $transport = $this->createMock(HttpTransporterInterface::class);
        $transport->expects($this->once())->method('send')->willReturnCallback(
            function (Request $request) use ($mime, $format): Response {
                $this->assertSame($format, $request->getData()['response_format']);
                $this->assertSame('nova', $request->getData()['voice']);
                $this->assertSame(1.5, $request->getData()['speed']);
                return new Response(200, ['Content-Type' => $mime], 'audio-bytes');
            }
        );
        $model->setHttpTransporter($transport);
        $this->assertSame($mime, $model->convertTextToSpeechResult($this->prompt())->toAudioFile()->getMimeType());
    }

    /** @return array<string, array{string, string}> */
    public function formats(): array
    {
        return [
            'wav' => ['audio/wav', 'wav'],
            'opus' => ['audio/ogg', 'opus'],
            'aac' => ['audio/aac', 'aac'],
            'flac' => ['audio/flac', 'flac'],
            'pcm' => ['audio/pcm', 'pcm'],
        ];
    }

    /**
     * @dataProvider invalidConfigs
     * @param array<string, mixed> $config The invalid configuration.
     */
    public function testRejectsInvalidConfigurationBeforeHttp(array $config): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray($config));
        $transport = $this->createMock(HttpTransporterInterface::class);
        $transport->expects($this->never())->method('send');
        $model->setHttpTransporter($transport);
        $this->expectException(InvalidArgumentException::class);
        $model->convertTextToSpeechResult($this->prompt());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public function invalidConfigs(): array
    {
        return [
            'mp3 is undocumented' => [['outputMimeType' => 'audio/mpeg']],
            'unknown voice' => [['outputSpeechVoice' => 'unknown']],
            'remote file' => [['outputFileType' => 'remote']],
            'overridden model' => [['customOptions' => ['model' => 'tts-1']]],
            'too fast' => [['customOptions' => ['speed' => 4.1]]],
            'speed string' => [['customOptions' => ['speed' => '1.0']]],
        ];
    }

    public function testRejectsOverlongUnicodeText(): void
    {
        $model = $this->model();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('4096');
        $model->convertTextToSpeechResult($this->prompt([str_repeat('字', 4097)]));
    }

    public function testAccepts4096UnicodeCharacters(): void
    {
        $model = $this->modelWithResponse(new Response(200, [], 'binary'));
        $this->assertSame(base64_encode('binary'), $model->convertTextToSpeechResult(
            $this->prompt([str_repeat('字', 4096)])
        )->toAudioFile()->getBase64Data());
    }

    public function testRejectsNonTextInput(): void
    {
        $model = $this->model();
        $this->expectException(InvalidArgumentException::class);
        $model->convertTextToSpeechResult([new Message(MessageRoleEnum::user(), [
            new MessagePart('Hello'),
            new MessagePart(new File('https://example.com/a.png', 'image/png')),
        ])]);
    }

    /** @dataProvider invalidResponses */
    public function testRejectsEmptyOrJsonErrorResponses(Response $response): void
    {
        $model = $this->modelWithResponse($response);
        $this->expectException(ResponseException::class);
        $model->convertTextToSpeechResult($this->prompt());
    }

    /** @return array<string, array{Response}> */
    public function invalidResponses(): array
    {
        return [
            'empty' => [new Response(200, [], '')],
            'json error' => [new Response(200, [], '{"error":{"message":"balance"}}')],
            'json content type' => [new Response(200, ['Content-Type' => 'application/json'], '{}')],
            'html' => [new Response(200, ['Content-Type' => 'text/html'], '<html>failure</html>')],
        ];
    }

    public function testPropagatesAuthenticationErrors(): void
    {
        $model = $this->modelWithResponse(new Response(401, [], '{"error":{"message":"Invalid key"}}'));
        $this->expectException(ClientException::class);
        $model->convertTextToSpeechResult($this->prompt());
    }

    private function model(): ApimartTextToSpeechConversionModel
    {
        $model = new ApimartTextToSpeechConversionModel(
            new ModelMetadata('gpt-4o-mini-tts', 'TTS', [CapabilityEnum::textToSpeechConversion()], []),
            ApimartProvider::metadata()
        );
        $authentication = $this->createMock(RequestAuthenticationInterface::class);
        $authentication->method('authenticateRequest')->willReturnArgument(0);
        $model->setRequestAuthentication($authentication);
        // Required even for validation paths; validation must happen before send().
        $model->setHttpTransporter($this->createMock(HttpTransporterInterface::class));
        return $model;
    }

    private function modelWithResponse(Response $response): ApimartTextToSpeechConversionModel
    {
        $model = $this->model();
        $transport = $this->createMock(HttpTransporterInterface::class);
        $transport->expects($this->once())->method('send')->willReturn($response);
        $model->setHttpTransporter($transport);
        return $model;
    }

    /** @param list<string> $texts @return list<Message> */
    private function prompt(array $texts = ['Hello']): array
    {
        return [new Message(MessageRoleEnum::user(), array_map(static function (string $text): MessagePart {
            return new MessagePart($text);
        }, $texts))];
    }
}
