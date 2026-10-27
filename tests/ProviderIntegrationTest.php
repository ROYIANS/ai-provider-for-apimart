<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * Exercises the public SDK builder, catalog selection, dependency binding and result conversion together.
 *
 * Fresh processes isolate the SDK's static provider registry/catalog and environment credentials.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ProviderIntegrationTest extends TestCase
{
    /**
     * @var string|false The pre-test API key environment value.
     */
    private $previousApiKey;

    /**
     * @var string|false The pre-test API endpoint environment value.
     */
    private $previousBaseUrl;

    private string $baseUrl = 'https://api.apimart.ai/v1';

    /**
     * @var list<Request> Requests made by the SDK and adapter.
     */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->previousApiKey = getenv('APIMART_API_KEY');
        $this->previousBaseUrl = getenv('APIMART_BASE_URL');
        putenv('APIMART_API_KEY=test-key');
        putenv('APIMART_BASE_URL');
    }

    protected function tearDown(): void
    {
        if ($this->previousApiKey === false) {
            putenv('APIMART_API_KEY');
        } else {
            putenv('APIMART_API_KEY=' . $this->previousApiKey);
        }
        if ($this->previousBaseUrl === false) {
            putenv('APIMART_BASE_URL');
        } else {
            putenv('APIMART_BASE_URL=' . $this->previousBaseUrl);
        }
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testTextExampleUsesTheRequestedCatalogModelAndEnvironmentAuthentication(string $baseUrl): void
    {
        $this->useEndpoint($baseUrl);
        $registry = $this->registerProviderWithFakeTransporter(2);

        $text = AiClient::prompt('Explain how WordPress hooks work.')
            ->usingModel($registry->getProviderModel('apimart', 'catalog-chat-model'))
            ->generateText();

        $this->assertSame('Hooks connect callbacks to WordPress events.', $text);
        $this->assertSame([
            $baseUrl . '/models?expand=category',
            $baseUrl . '/chat/completions',
        ], $this->requestUris());
        $this->assertSame('catalog-chat-model', $this->requests[1]->getData()['model']);
    }

    public function testMatchingModelPreferenceSelectsTheRequestedCatalogModel(): void
    {
        $this->registerProviderWithFakeTransporter(2);

        $text = AiClient::prompt('Explain how WordPress hooks work.')
            ->usingModelPreference(['apimart', 'catalog-chat-model'])
            ->generateText();

        $this->assertSame('Hooks connect callbacks to WordPress events.', $text);
        $this->assertSame([
            'https://api.apimart.ai/v1/models?expand=category',
            'https://api.apimart.ai/v1/chat/completions',
        ], $this->requestUris());
        $this->assertSame('catalog-chat-model', $this->requests[1]->getData()['model']);
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testImageExampleSelectsTheImageContractAndConvertsTheCompletedTask(string $baseUrl): void
    {
        $this->useEndpoint($baseUrl);
        $registry = $this->registerProviderWithFakeTransporter(3);

        $result = AiClient::prompt('A small lighthouse in a watercolor landscape.')
            ->usingModel($registry->getProviderModel('apimart', 'gpt-image-1-official'))
            ->asOutputMediaAspectRatio('3:2')
            ->asOutputMimeType('image/png')
            ->asOutputFileType(FileTypeEnum::remote())
            ->generateImageResult();

        $this->assertSame('https://cdn.example.com/generated.png', $result->toImageFile()->getUrl());
        $this->assertSame('gpt-image-1-official', $result->getModelMetadata()->getId());
        $this->assertSame('apimart', $result->getProviderMetadata()->getId());
        $this->assertSame(['integration-image-task'], $result->getAdditionalData()['task_ids']);
        $this->assertSame([
            $baseUrl . '/models?expand=category',
            $baseUrl . '/images/generations',
            $baseUrl . '/tasks/integration-image-task',
        ], $this->requestUris());
    }

    /**
     * @dataProvider endpointProvider
     */
    public function testSpeechExampleUsesTextToSpeechCapabilityAndReturnsInlineAudio(string $baseUrl): void
    {
        $this->useEndpoint($baseUrl);
        $registry = $this->registerProviderWithFakeTransporter(2);

        $result = AiClient::prompt('Welcome to our website.')
            ->usingModel($registry->getProviderModel('apimart', 'gpt-4o-mini-tts'))
            ->asOutputSpeechVoice('alloy')
            ->asOutputMimeType('audio/wav')
            ->convertTextToSpeechResult();

        $audio = $result->toAudioFile();
        $this->assertTrue($audio->isInline());
        $this->assertSame('audio/wav', $audio->getMimeType());
        $this->assertSame('RIFF-fake-audio', base64_decode($audio->getBase64Data(), true));
        $this->assertSame('gpt-4o-mini-tts', $result->getModelMetadata()->getId());
        $this->assertSame([
            $baseUrl . '/models?expand=category',
            $baseUrl . '/audio/speech',
        ], $this->requestUris());
    }

    public function testStrictExplicitLookupRejectsAnUnknownIdBeforeGeneration(): void
    {
        $registry = $this->registerProviderWithFakeTransporter(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown-model');
        $registry->getProviderModel('apimart', 'unknown-model');
    }

    /**
     * @return array<string, array{string}> Default and user-supplied alternate API domains.
     */
    public function endpointProvider(): array
    {
        return [
            'default' => ['https://api.apimart.ai/v1'],
            'apib' => ['https://api.apib.ai/v1'],
            'aiuxu' => ['https://api.aiuxu.com/v1'],
            'aishuch' => ['https://api.aishuch.com/v1'],
        ];
    }

    private function useEndpoint(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
        putenv('APIMART_BASE_URL=' . $baseUrl);
    }

    /**
     * Registers the real provider with the real default registry and a fake HTTP boundary.
     *
     * @param int $requestCount The expected catalog plus generation request count.
     * @return ProviderRegistry The configured SDK registry.
     */
    private function registerProviderWithFakeTransporter(int $requestCount): ProviderRegistry
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->exactly($requestCount))->method('send')->willReturnCallback(
            function (Request $request): Response {
                $this->requests[] = $request;
                $this->assertSame('Bearer test-key', $request->getHeaderAsString('Authorization'));
                return $this->responseForRequest($request);
            }
        );

        $registry = AiClient::defaultRegistry();
        $registry->setHttpTransporter($transporter);
        $registry->registerProvider(ApimartProvider::class);
        ApimartProvider::modelMetadataDirectory()->invalidateCaches();
        $this->assertTrue($registry->hasProvider('apimart'));
        return $registry;
    }

    /**
     * Supplies every HTTP response; an unexpected endpoint fails rather than reaching the network.
     *
     * @param Request $request The request from the SDK or adapter.
     * @return Response The fixture response.
     */
    private function responseForRequest(Request $request): Response
    {
        switch ($request->getUri()) {
            case $this->baseUrl . '/models?expand=category':
                return $this->jsonResponse(['success' => true, 'data' => [
                    // Sorts first so text preferences must actually choose the requested model.
                    ['id' => 'another-chat-model', 'category' => 'chat', 'capability_tags' => ['Text']],
                    ['id' => 'catalog-chat-model', 'category' => 'chat', 'capability_tags' => ['Text']],
                    ['id' => 'gpt-image-1-official', 'category' => 'image', 'capability_tags' => ['Text to Image']],
                    ['id' => 'gpt-4o-mini-tts', 'category' => 'audio', 'capability_tags' => ['Audio']],
                    ['id' => 'unknown-model', 'category' => 'unknown'],
                ]]);
            case $this->baseUrl . '/chat/completions':
                return $this->jsonResponse([
                    'id' => 'integration-completion',
                    'model' => 'catalog-chat-model',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hooks connect callbacks to WordPress events.',
                        ],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 9, 'total_tokens' => 17],
                ]);
            case $this->baseUrl . '/images/generations':
                return $this->jsonResponse(['code' => 200, 'data' => [['task_id' => 'integration-image-task']]]);
            case $this->baseUrl . '/tasks/integration-image-task':
                return $this->jsonResponse(['code' => 200, 'data' => [
                    'id' => 'integration-image-task',
                    'status' => 'completed',
                    'result' => ['images' => [[
                        'url' => ['https://cdn.example.com/generated.png'],
                        'expires_at' => 1793100000,
                    ]]],
                ]]);
            case $this->baseUrl . '/audio/speech':
                return new Response(200, ['Content-Type' => 'audio/wav'], 'RIFF-fake-audio');
            default:
                $this->fail('Unexpected HTTP request: ' . $request->getUri());
        }
    }

    /**
     * @param array<string, mixed> $data The response data.
     * @return Response The JSON response.
     */
    private function jsonResponse(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    /**
     * @return list<string> The requested URIs in order.
     */
    private function requestUris(): array
    {
        return array_map(static function (Request $request): string {
            return $request->getUri();
        }, $this->requests);
    }
}
