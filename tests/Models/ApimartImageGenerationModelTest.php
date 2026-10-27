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
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Tests the asynchronous image submission and task protocol without API credentials or real delays.
 */
class ApimartImageGenerationModelTest extends TestCase
{
    public function testGenerationAuthenticatesEveryRequestAndPreservesTaskMetadata(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 2,
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
            'inline output' => [['outputFileType' => 'inline']],
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

    /** @return ImageModelWithVirtualClock */
    private function model(): ImageModelWithVirtualClock
    {
        $model = new ImageModelWithVirtualClock(
            new ModelMetadata('gpt-image-1-official', 'GPT Image', [CapabilityEnum::imageGeneration()], []),
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
     * @param list<array<string, mixed>> $responses The mocked response data.
     * @param list<Request> $requests Captured authenticated requests.
     */
    private function responses(ImageModelWithVirtualClock $model, array $responses, array &$requests): void
    {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->exactly(count($responses)))->method('send')->willReturnCallback(
            static function (Request $request) use (&$responses, &$requests): Response {
                $requests[] = $request;
                return new Response(200, [], json_encode(array_shift($responses), JSON_THROW_ON_ERROR));
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
