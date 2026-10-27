<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\RedirectException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Tests the asynchronous image submission and task protocol without API credentials or real delays.
 */
class ApimartImageGenerationModelTest extends TestCase
{
    /**
     * @dataProvider inlineImageFormats
     * @param string $modelId The image model identifier.
     * @param string $mimeType The requested supported image format.
     */
    public function testInlineOutputDownloadsWithoutCredentialsWithinTheRemainingDeadline(
        string $modelId,
        string $mimeType
    ): void {
        $model = $this->model($modelId);
        $model->setConfig(ModelConfig::fromArray([
            'outputFileType' => 'inline', 'outputMimeType' => $mimeType,
            'customOptions' => ['apimart_poll_timeout' => 3, 'apimart_poll_interval' => 2],
        ]));
        $options = RequestOptions::fromArray(['timeout' => 20, 'connectTimeout' => 5, 'maxRedirects' => 4]);
        $model->setRequestOptions($options);
        $body = "image binary\x00\xff";
        $url = 'https://cdn.example.com/generated-image?signature=example';
        $requests = [];
        $this->responses($model, [
            $this->submission('task-inline'),
            ['code' => 200, 'data' => ['id' => 'task-inline', 'status' => 'processing']],
            $this->completed('task-inline', [$url]),
            new Response(200, ['Content-Type' => strtoupper($mimeType) . '; charset=binary'], $body),
        ], $requests);

        $result = $model->generateImageResult($this->prompt());

        $file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        $this->assertNotNull($file);
        $this->assertTrue($file->isInline());
        $this->assertSame(base64_encode($body), $file->getBase64Data());
        $this->assertSame($mimeType, $file->getMimeType());
        $this->assertNull($file->getUrl());
        $this->assertSame($url, $result->getAdditionalData()['tasks'][0]['result']['images'][0]['url'][0]);
        $this->assertCount(4, $requests);
        foreach (array_slice($requests, 0, 3) as $request) {
            $this->assertSame('Bearer fake-key', $request->getHeaderAsString('Authorization'));
        }
        $download = $requests[3];
        $this->assertSame($url, $download->getUri());
        $this->assertTrue($download->getMethod()->isGet());
        $this->assertSame(['Accept' => [$mimeType]], $download->getHeaders());
        $this->assertNull($download->getData());
        $this->assertSame(1.0, $download->getOptions()->getTimeout());
        $this->assertSame(1.0, $download->getOptions()->getConnectTimeout());
        $this->assertSame(0, $download->getOptions()->getMaxRedirects());
        $this->assertSame(20.0, $options->getTimeout());
        $this->assertSame(5.0, $options->getConnectTimeout());
        $this->assertSame(4, $options->getMaxRedirects());
        $this->assertArrayNotHasKey('response_format', $requests[0]->getData());
    }

    /** @return array<string, array{string, string}> */
    public static function inlineImageFormats(): array
    {
        return [
            'legacy png' => ['gpt-image-1-official', 'image/png'],
            'ext png' => ['gpt-image-2.5-ext', 'image/png'],
            'official jpeg' => ['gpt-image-2-official', 'image/jpeg'],
            'flare webp' => ['gpt-image-2.5-flare', 'image/webp'],
        ];
    }

    /**
     * @dataProvider invalidDownloadedImages
     * @param Response $download The malformed download response.
     */
    public function testInlineOutputRejectsInvalidDownloads(Response $download): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['outputFileType' => 'inline']));
        $requests = [];
        $this->responses($model, [
            $this->submission('task-inline'), $this->completed('task-inline'), $download,
        ], $requests);
        $this->expectException(ResponseException::class);

        $model->generateImageResult($this->prompt());
    }

    /** @return array<string, array{Response}> */
    public static function invalidDownloadedImages(): array
    {
        return [
            'missing content type' => [new Response(200, [], 'image bytes')],
            'html instead of image' => [new Response(200, ['Content-Type' => 'text/html'], '<html>Error</html>')],
            'wrong image format' => [new Response(200, ['Content-Type' => 'image/jpeg'], 'image bytes')],
            'unsupported image format' => [new Response(200, ['Content-Type' => 'image/svg+xml'], '<svg/>')],
            'null body' => [new Response(200, ['Content-Type' => 'image/png'])],
            'empty body' => [new Response(200, ['Content-Type' => 'image/png'], '')],
            'oversized content length' => [new Response(200, [
                'Content-Type' => 'image/png', 'Content-Length' => '33554433',
            ], 'image bytes')],
        ];
    }

    public function testInlineOutputRejectsBodyLargerThanThirtyTwoMebibytes(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['outputFileType' => 'inline']));
        $requests = [];
        $this->responses($model, [
            $this->submission('task-inline'), $this->completed('task-inline'),
            new Response(200, ['Content-Type' => 'image/png'], str_repeat('x', 32 * 1024 * 1024 + 1)),
        ], $requests);
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('32 MiB');

        $model->generateImageResult($this->prompt());
    }

    public function testInlineOutputPropagatesDownloadHttpErrors(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['outputFileType' => 'inline']));
        $requests = [];
        $this->responses($model, [
            $this->submission('task-inline'), $this->completed('task-inline'), new Response(404, [], 'Not found'),
        ], $requests);
        $this->expectException(ClientException::class);

        $model->generateImageResult($this->prompt());
    }

    public function testInlineOutputRejectsRedirectsWithoutFollowingTheLocation(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['outputFileType' => 'inline']));
        $requests = [];
        $this->responses($model, [
            $this->submission('task-inline'), $this->completed('task-inline'),
            new Response(302, ['Location' => 'http://127.0.0.1/private.png']),
        ], $requests);
        $this->expectException(RedirectException::class);

        $model->generateImageResult($this->prompt());
    }

    public function testInlineOutputRejectsPrivateUrlsBeforeDownloading(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['outputFileType' => 'inline']));
        $requests = [];
        $this->responses($model, [
            $this->submission('task-inline'), $this->completed('task-inline', ['http://127.0.0.1/image.png']),
        ], $requests);
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Invalid image URL');

        $model->generateImageResult($this->prompt());
    }

    /**
     * @dataProvider secondGenerationAliases
     * @param string $modelId The documented model identifier or alias.
     */
    public function testSecondGenerationAliasesUseTheirOwnJsonContract(string $modelId): void
    {
        $model = $this->model($modelId);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaAspectRatio' => '16:9',
            'customOptions' => ['resolution' => '2K', 'official_fallback' => true],
        ]));
        $requests = [];
        $this->responses($model, [$this->submission('task-two'), $this->completed('task-two')], $requests);

        $result = $model->generateImageResult($this->prompt());

        $this->assertSame([
            'model' => $modelId, 'prompt' => 'Draw a castle', 'n' => 1, 'size' => '16:9',
            'resolution' => '2k', 'official_fallback' => true,
        ], $requests[0]->getData());
        $this->assertSame('image/png', $result->getCandidates()[0]->getMessage()->getParts()[0]
            ->getFile()->getMimeType());
        $this->assertSame('https://api.apimart.ai/v1/tasks/task-two', $requests[1]->getUri());
    }

    /** @return array<string, array{string}> */
    public static function secondGenerationAliases(): array
    {
        return ['base' => ['gpt-image-2'], 'compatible ext alias' => ['gpt-image-2-ext']];
    }

    /**
     * @dataProvider advancedImageModels
     * @param string $modelId The model with explicit output format support.
     * @param string $quality The supported generation quality.
     */
    public function testAdvancedModelsSupportWebpSixteenReferencesAndMasks(string $modelId, string $quality): void
    {
        $model = $this->model($modelId);
        $references = array_fill(0, 16, 'https://cdn.example.com/reference.png');
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 4, 'outputMediaAspectRatio' => '21:9', 'outputMimeType' => 'image/webp',
            'customOptions' => [
                'image_urls' => $references, 'mask_url' => 'https://cdn.example.com/mask.png',
                'quality' => $quality, 'resolution' => '4K', 'background' => 'transparent',
            ],
        ]));
        $requests = [];
        $this->responses($model, [
            $this->submission('task-advanced'),
            ['code' => 200, 'data' => ['id' => 'task-advanced', 'status' => 'in_progress']],
            $this->completed('task-advanced', ['https://cdn.example.com/output.webp']),
        ], $requests);

        $result = $model->generateImageResult($this->prompt());

        $this->assertSame('webp', $requests[0]->getData()['output_format']);
        $this->assertSame('4k', $requests[0]->getData()['resolution']);
        $this->assertSame($quality, $requests[0]->getData()['quality']);
        $this->assertSame($references, $requests[0]->getData()['image_urls']);
        $this->assertSame(4, $requests[0]->getData()['n']);
        $this->assertSame('image/webp', $result->getCandidates()[0]->getMessage()->getParts()[0]
            ->getFile()->getMimeType());
        $this->assertSame([2.0], $model->delays);
    }

    /** @return array<string, array{string, string}> */
    public static function advancedImageModels(): array
    {
        return [
            'second generation official' => ['gpt-image-2-official', 'high'],
            'flare' => ['gpt-image-2.5-flare', 'xhigh'],
            'sunburst' => ['gpt-image-2.5-sunburst', 'max'],
        ];
    }

    /**
     * @dataProvider submissionEnvelopes
     * @param bool $versioned Whether the provider uses the versioned 202 submission response.
     */
    public function testExtModelAcceptsBothTaskEnvelopesAndPreservesItsParameters(bool $versioned): void
    {
        $model = $this->model('gpt-image-2.5-ext');
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 4,
            'customOptions' => [
                'size' => 'auto', 'version' => 'sunburst', 'resolution' => '2k',
                'image_urls' => array_fill(0, 16, 'https://cdn.example.com/reference.png'),
            ],
        ]));
        $requests = [];
        $responses = [
            $versioned ? ['code' => 202, 'data' => [
                'id' => 'task-ext', 'object' => 'generation.task', 'type' => 'image',
                'status' => 'pending', 'poll_url' => '/v1/tasks/task-ext',
            ]] : $this->submission('task-ext'),
            $this->completed('task-ext'),
        ];
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->exactly(2))->method('send')->willReturnCallback(
            static function (Request $request) use (&$requests, &$responses, $versioned): Response {
                $status = $requests === [] && $versioned ? 202 : 200;
                $requests[] = $request;
                return new Response($status, [], json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
            }
        );
        $model->setHttpTransporter($transporter);

        $result = $model->generateImageResult($this->prompt());

        $this->assertSame('task-ext', $result->getId());
        $this->assertSame('2K', $requests[0]->getData()['resolution']);
        $this->assertSame('sunburst', $requests[0]->getData()['version']);
        $this->assertSame('auto', $requests[0]->getData()['size']);
        $this->assertSame(4, $requests[0]->getData()['n']);
        $this->assertArrayNotHasKey('output_format', $requests[0]->getData());
        $this->assertSame('https://api.apimart.ai/v1/tasks/task-ext', $requests[1]->getUri());
    }

    /** @return array<string, array{bool}> */
    public static function submissionEnvelopes(): array
    {
        return ['legacy task list' => [false], 'versioned 202 task object' => [true]];
    }

    public function testFlareSupportsValidatedExactPixelDimensions(): void
    {
        $model = $this->model('gpt-image-2.5-flare');
        $model->setConfig(ModelConfig::fromArray(['customOptions' => ['size' => '1600x1200']]));
        $requests = [];
        $this->responses($model, [$this->submission('task-pixels'), $this->completed('task-pixels')], $requests);

        $model->generateImageResult($this->prompt());

        $this->assertSame('1600x1200', $requests[0]->getData()['size']);
    }

    /**
     * @dataProvider invalidNewImageConfig
     * @param string $modelId The model identifier.
     * @param array<string, mixed> $config The invalid model configuration.
     */
    public function testNewModelLimitsFailBeforeSubmission(string $modelId, array $config): void
    {
        $model = $this->model($modelId);
        $model->setConfig(ModelConfig::fromArray($config));
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->never())->method('send');
        $model->setHttpTransporter($transporter);
        $this->expectException(InvalidArgumentException::class);

        $model->generateImageResult($this->prompt());
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function invalidNewImageConfig(): array
    {
        return [
            'two count limit' => ['gpt-image-2', ['candidateCount' => 2]],
            'two ext jpeg' => ['gpt-image-2-ext', ['outputMimeType' => 'image/jpeg']],
            'two quality' => ['gpt-image-2', ['customOptions' => ['quality' => 'high']]],
            'two references limit' => ['gpt-image-2', ['customOptions' => [
                'image_urls' => array_fill(0, 16, 'https://cdn.example.com/reference.png'),
            ]]],
            'official unsupported quality' => ['gpt-image-2-official', ['customOptions' => ['quality' => 'max']]],
            'ext unsupported ratio' => ['gpt-image-2.5-ext', ['outputMediaAspectRatio' => '9:21']],
            'ext output format' => ['gpt-image-2.5-ext', ['customOptions' => ['output_format' => 'jpeg']]],
            'ext unsupported format' => ['gpt-image-2.5-ext', ['outputMimeType' => 'image/webp']],
            'ext unsupported resolution' => ['gpt-image-2.5-ext', ['customOptions' => ['resolution' => '8K']]],
            'ext invalid version' => ['gpt-image-2.5-ext', ['customOptions' => ['version' => 'other']]],
            'ext invalid count' => ['gpt-image-2.5-ext', ['candidateCount' => 5]],
            'ext mask' => ['gpt-image-2.5-ext', ['customOptions' => [
                'image_urls' => ['https://cdn.example.com/reference.png'],
                'mask_url' => 'https://cdn.example.com/mask.png',
            ]]],
            'flare references limit' => ['gpt-image-2.5-flare', ['customOptions' => [
                'image_urls' => array_fill(0, 17, 'https://cdn.example.com/reference.png'),
            ]]],
            'flare non-multiple dimensions' => ['gpt-image-2.5-flare', ['customOptions' => ['size' => '1601x1200']]],
            'flare low pixel count' => ['gpt-image-2.5-flare', ['customOptions' => ['size' => '640x480']]],
            'flare extreme ratio' => ['gpt-image-2.5-flare', ['customOptions' => ['size' => '3840x512']]],
            'flare excessive dimensions' => ['gpt-image-2.5-flare', ['customOptions' => ['size' => '4096x2048']]],
        ];
    }

    public function testGenerationAuthenticatesEveryRequestAndPreservesTaskMetadata(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 2,
            'outputFileType' => 'remote',
            'outputMediaAspectRatio' => '2:3',
            'outputMimeType' => 'image/jpeg',
        ]));
        $requests = [];
        $this->responses($model, [
            $this->submission('task-one'),
            ['code' => 200, 'data' => ['id' => 'task-one', 'status' => 'pending']],
            ['code' => 200, 'data' => ['id' => 'task-one', 'status' => 'processing']],
            $this->completed('task-one', ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg']),
        ], $requests);

        $result = $model->generateImageResult($this->prompt());

        $this->assertSame('task-one', $result->getId());
        $this->assertCount(2, $result->getCandidates());
        $this->assertSame('https://cdn.example.com/a.jpg', $result->getCandidates()[0]->getMessage()->getParts()[0]
            ->getFile()->getUrl());
        $this->assertSame(
            'image/jpeg',
            $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile()->getMimeType()
        );
        $this->assertSame(1763174708, $result->getAdditionalData()['tasks'][0]['result']['images'][0]['expires_at']);
        $this->assertSame(0.15, $result->getAdditionalData()['tasks'][0]['cost']);
        $this->assertSame(['task-one'], $result->getAdditionalData()['task_ids']);
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
        $this->assertCount(4, $requests);
        $this->assertSame('https://api.apimart.ai/v1/images/generations', $requests[0]->getUri());
        $this->assertSame([
            'model' => 'gpt-image-1-official', 'prompt' => 'Draw a castle', 'n' => 2,
            'size' => '2:3', 'output_format' => 'jpeg',
        ], $requests[0]->getData());
        $this->assertSame('https://api.apimart.ai/v1/tasks/task-one', $requests[1]->getUri());
        foreach ($requests as $request) {
            $this->assertSame('Bearer fake-key', $request->getHeaderAsString('Authorization'));
        }
        $this->assertSame([2.0, 2.0], $model->delays);
    }

    public function testEditingUsesJsonReferenceAndMaskUrlsOnTheGenerationEndpoint(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => ['mask_url' => 'https://cdn.example.com/mask.png', 'quality' => 'high'],
        ]));
        $requests = [];
        $this->responses($model, [$this->submission('task-edit'), $this->completed('task-edit')], $requests);
        $model->generateImageResult($this->prompt([
            new MessagePart(new File('https://cdn.example.com/reference.png', 'image/png')),
            new MessagePart(new File('https://cdn.example.com/reference2.png', 'image/png')),
        ]));
        $this->assertSame([
            'https://cdn.example.com/reference.png', 'https://cdn.example.com/reference2.png',
        ], $requests[0]->getData()['image_urls']);
        $this->assertSame('https://cdn.example.com/mask.png', $requests[0]->getData()['mask_url']);
        $this->assertSame('application/json', $requests[0]->getHeaderAsString('Content-Type'));
    }

    public function testMultipleTasksAreCollectedWithoutResubmittingThePrompt(): void
    {
        $model = $this->model();
        $requests = [];
        $this->responses($model, [
            ['code' => 200, 'data' => [['task_id' => 'task-a'], ['task_id' => 'task-b']]],
            $this->completed('task-a'), $this->completed('task-b'),
        ], $requests);
        $result = $model->generateImageResult($this->prompt());
        $this->assertCount(2, $result->getCandidates());
        $this->assertSame(['task-a', 'task-b'], $result->getAdditionalData()['task_ids']);
        $this->assertCount(3, $requests);
    }

    public function testTimeoutStopsPollingAndRetainsCallerRequestOptions(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => ['apimart_poll_timeout' => 3, 'apimart_poll_interval' => 2],
        ]));
        $options = RequestOptions::fromArray(['timeout' => 20.0, 'connectTimeout' => 1.0]);
        $model->setRequestOptions($options);
        $requests = [];
        $this->responses($model, [
            $this->submission('task-timeout'),
            ['code' => 200, 'data' => ['status' => 'submitted']],
            ['code' => 200, 'data' => ['status' => 'pending']],
        ], $requests);
        try {
            $model->generateImageResult($this->prompt());
            $this->fail('Expected the polling deadline to expire.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Timed out', $error->getMessage());
            $this->assertStringContainsString('task-timeout', $error->getMessage());
        }
        $this->assertCount(3, $requests);
        $this->assertSame([2.0, 1.0], $model->delays);
        $this->assertSame(3.0, $requests[0]->getOptions()->getTimeout());
        $this->assertSame(1.0, $requests[2]->getOptions()->getTimeout());
        $this->assertSame(20.0, $options->getTimeout());
        $this->assertSame(1.0, $requests[2]->getOptions()->getConnectTimeout());
        $this->assertArrayNotHasKey('apimart_poll_timeout', $requests[0]->getData());
        $this->assertArrayNotHasKey('apimart_poll_interval', $requests[0]->getData());
    }

    /**
     * @dataProvider terminalTaskErrors
     * @param string $status The failed terminal state.
     */
    public function testFailedAndCancelledTasksStopImmediately(string $status): void
    {
        $model = $this->model();
        $requests = [];
        $this->responses($model, [
            $this->submission('task-failed'),
            ['code' => 200, 'data' => ['status' => $status, 'error' => ['message' => 'Provider explanation']]],
        ], $requests);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task-failed" ' . $status . ': Provider explanation');
        $model->generateImageResult($this->prompt());
    }

    /** @return array<string, array{string}> */
    public static function terminalTaskErrors(): array
    {
        return ['failed' => ['failed'], 'cancelled' => ['cancelled']];
    }

    /**
     * @dataProvider malformedTasks
     * @param array<string, mixed> $taskResponse The invalid task response.
     */
    public function testMalformedTaskResultsThrowResponseErrors(array $taskResponse): void
    {
        $model = $this->model();
        $requests = [];
        $this->responses($model, [$this->submission('task-one'), $taskResponse], $requests);
        $this->expectException(ResponseException::class);
        $model->generateImageResult($this->prompt());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedTasks(): array
    {
        return [
            'missing status' => [['code' => 200, 'data' => ['id' => 'task-one']]],
            'unknown status' => [['code' => 200, 'data' => ['status' => 'paused']]],
            'wrong task id' => [['code' => 200, 'data' => ['id' => 'other-task', 'status' => 'completed']]],
            'missing images' => [['code' => 200, 'data' => ['status' => 'completed', 'result' => []]]],
            'empty images' => [['code' => 200, 'data' => ['status' => 'completed', 'result' => ['images' => []]]]],
            'invalid urls' => [['code' => 200, 'data' => [
                'status' => 'completed', 'result' => ['images' => [['url' => ['file:///tmp/image.png']]]],
            ]]],
            'business error' => [['code' => 500, 'error' => ['message' => 'Internal provider failure']]],
        ];
    }

    /**
     * @dataProvider malformedSubmissions
     * @param array<string, mixed> $submission The invalid submission envelope.
     */
    public function testMalformedSubmissionsDoNotPoll(array $submission): void
    {
        $model = $this->model();
        $requests = [];
        $this->responses($model, [$submission], $requests);
        $this->expectException(ResponseException::class);
        $model->generateImageResult($this->prompt());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedSubmissions(): array
    {
        return [
            'empty data' => [['code' => 200, 'data' => []]],
            'invalid id' => [['code' => 200, 'data' => [['task_id' => 123]]]],
            'duplicate id' => [['code' => 200, 'data' => [['task_id' => 'a'], ['task_id' => 'a']]]],
            'business failure' => [['code' => 402, 'error' => ['message' => 'Insufficient balance']]],
        ];
    }

    /**
     * @dataProvider invalidImageConfig
     * @param array<string, mixed> $config The invalid model configuration.
     */
    public function testInvalidConfigFailsBeforeSubmission(array $config): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray($config));
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->never())->method('send');
        $model->setHttpTransporter($transporter);
        $this->expectException(InvalidArgumentException::class);
        $model->generateImageResult($this->prompt());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidImageConfig(): array
    {
        return [
            'webp output' => [['outputMimeType' => 'image/webp']],
            'invalid ratio' => [['outputMediaAspectRatio' => '16:9']],
            'too many images' => [['candidateCount' => 5]],
            'mask without image' => [['customOptions' => ['mask_url' => 'https://cdn.example.com/mask.png']]],
            'local url' => [['customOptions' => ['image_urls' => ['http://127.0.0.1/image.png']]]],
            'too many references' => [['customOptions' => [
                'image_urls' => array_fill(0, 16, 'https://cdn.example.com/a.png'),
            ]]],
            'transparent jpeg' => [[
                'outputMimeType' => 'image/jpeg', 'customOptions' => ['background' => 'transparent'],
            ]],
            'conflicting model' => [['customOptions' => ['model' => 'other-model']]],
            'invalid timeout' => [['customOptions' => ['apimart_poll_timeout' => 0]]],
            'excessive timeout' => [['customOptions' => ['apimart_poll_timeout' => 601]]],
            'invalid interval' => [['customOptions' => ['apimart_poll_interval' => '2']]],
        ];
    }

    public function testInlineImageEditingFailsBeforeSubmission(): void
    {
        $model = $this->model();
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->never())->method('send');
        $model->setHttpTransporter($transporter);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publicly accessible image URLs');
        $model->generateImageResult($this->prompt([
            new MessagePart(new File(base64_encode('fake image'), 'image/png')),
        ]));
    }

    public function testHttpSubmissionErrorsPropagateWithoutPolling(): void
    {
        $model = $this->model();
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->once())->method('send')->willReturn(
            new Response(401, [], '{"error":{"message":"Invalid key"}}')
        );
        $model->setHttpTransporter($transporter);
        $this->expectException(ClientException::class);
        $model->generateImageResult($this->prompt());
    }

    /**
     * @param string $modelId The model identifier under test.
     * @return ImageModelWithVirtualClock The authenticated model.
     */
    private function model(string $modelId = 'gpt-image-1-official'): ImageModelWithVirtualClock
    {
        $model = new ImageModelWithVirtualClock(
            new ModelMetadata($modelId, 'GPT Image', [CapabilityEnum::imageGeneration()], []),
            new ProviderMetadata('apimart', 'APIMart', ProviderTypeEnum::cloud())
        );
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('fake-key'));
        return $model;
    }

    /**
     * @param list<MessagePart> $extraParts Additional image input parts.
     * @return list<Message> The image prompt.
     */
    private function prompt(array $extraParts = []): array
    {
        return [new Message(MessageRoleEnum::user(), array_merge([new MessagePart('Draw a castle')], $extraParts))];
    }

    /**
     * @param ImageModelWithVirtualClock $model The model under test.
     * @param list<array<string, mixed>|Response> $responses The mocked response data or raw downloads.
     * @param list<Request> $requests Captured authenticated requests.
     */
    private function responses(ImageModelWithVirtualClock $model, array $responses, array &$requests): void
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->exactly(count($responses)))->method('send')->willReturnCallback(
            static function (Request $request) use (&$responses, &$requests): Response {
                $requests[] = $request;
                $response = array_shift($responses);
                return $response instanceof Response ? $response :
                    new Response(200, [], json_encode($response, JSON_THROW_ON_ERROR));
            }
        );
        $model->setHttpTransporter($transporter);
    }

    /**
     * @param string $taskId The task identifier.
     * @return array<string, mixed> A task submission response.
     */
    private function submission(string $taskId): array
    {
        return ['code' => 200, 'data' => [['status' => 'submitted', 'task_id' => $taskId]]];
    }

    /**
     * @param string $taskId The task identifier.
     * @param list<string> $urls The generated image URLs.
     * @return array<string, mixed> A completed task response.
     */
    private function completed(string $taskId, array $urls = ['https://cdn.example.com/image.png']): array
    {
        return ['code' => 200, 'data' => [
            'id' => $taskId, 'status' => 'completed', 'cost' => 0.15,
            'result' => ['images' => [['url' => $urls, 'expires_at' => 1763174708]]],
        ]];
    }
}
