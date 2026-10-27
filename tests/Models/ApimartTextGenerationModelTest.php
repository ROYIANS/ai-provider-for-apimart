<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WordPress\ApimartAiProvider\Models\ApimartTextGenerationModel;

/**
 * Tests APIMart protocol conversion without a real API key or network request.
 *
 * @covers \WordPress\ApimartAiProvider\Models\ApimartTextGenerationModel
 */
class ApimartTextGenerationModelTest extends TestCase
{
    public function testSendsAuthenticatedNonStreamingChatRequestAndParsesEnvelope(): void
    {
        $model = $this->model();
        $options = new RequestOptions();
        $options->setTimeout(30.0);
        $model->setRequestOptions($options);
        $model->setConfig(ModelConfig::fromArray([
            'systemInstruction' => 'Be concise.',
            'maxTokens' => 120,
            'temperature' => 0.3,
            'topP' => 0.8,
            'candidateCount' => 1,
            'stopSequences' => ['END'],
            'customOptions' => ['seed' => 42],
        ]));
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->once())->method('send')->willReturnCallback(
            function (Request $request) use ($options): Response {
                $this->assertSame('https://api.apimart.ai/v1/chat/completions', $request->getUri());
                $this->assertSame(['Bearer test-key'], $request->getHeader('Authorization'));
                $this->assertEquals($options, $request->getOptions());
                $params = $request->getData();
                $this->assertSame('claude-sonnet-4-6', $params['model']);
                $this->assertFalse($params['stream']);
                $this->assertSame('system', $params['messages'][0]['role']);
                $this->assertSame('Be concise.', $params['messages'][0]['content']);
                $this->assertSame('Hello', $params['messages'][1]['content']);
                $this->assertSame(120, $params['max_tokens']);
                $this->assertSame(0.3, $params['temperature']);
                $this->assertSame(0.8, $params['top_p']);
                $this->assertSame(1, $params['n']);
                $this->assertSame(['END'], $params['stop']);
                $this->assertSame(42, $params['seed']);
                $this->assertArrayNotHasKey('input', $params);
                $this->assertArrayNotHasKey('instructions', $params);
                return $this->response(['code' => 200, 'data' => $this->completion()]);
            }
        );
        $model->setHttpTransporter($transporter);
        $result = $model->generateTextResult($this->prompt());

        $this->assertSame('Hello back', $result->toText());
        $this->assertSame('chatcmpl-123', $result->getId());
        $this->assertSame(7, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(4, $result->getTokenUsage()->getCompletionTokens());
        $this->assertSame(11, $result->getTokenUsage()->getTotalTokens());
        $this->assertSame('claude-sonnet-4-6', $result->getAdditionalData()['model']);
    }

    public function testParsesPlainResponseAndPreservesThoughtUsage(): void
    {
        $data = $this->completion();
        $data['choices'][0]['message']['reasoning_content'] = 'A brief reasoning summary';
        $data['usage']['completion_tokens_details'] = ['reasoning_tokens' => 2];
        $model = $this->modelWithResponse($this->response($data));
        $result = $model->generateTextResult($this->prompt());

        $this->assertSame('Hello back', $result->toText());
        $this->assertSame(2, $result->getTokenUsage()->getThoughtTokens());
        $this->assertTrue($result->toMessage()->getParts()[0]->getChannel()->isThought());
        $this->assertSame('A brief reasoning summary', $result->toMessage()->getParts()[0]->getText());
    }

    public function testMapsVisionInputAndStructuredOutput(): void
    {
        $model = $this->model();
        $schema = ['type' => 'object', 'properties' => ['caption' => ['type' => 'string']]];
        $model->setConfig(ModelConfig::fromArray([
            'outputMimeType' => 'application/json',
            'outputSchema' => $schema,
        ]));
        $params = $this->params($model, [new Message(MessageRoleEnum::user(), [
            new MessagePart('Describe this image'),
            new MessagePart(new File('https://example.com/photo.png', 'image/png')),
        ])]);

        $this->assertSame([
            'type' => 'image_url',
            'image_url' => ['url' => 'https://example.com/photo.png'],
        ], $params['messages'][0]['content'][1]);
        $this->assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'response_schema', 'schema' => $schema, 'strict' => true],
        ], $params['response_format']);
    }

    public function testMapsJsonObjectOutputWithoutSchema(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['outputMimeType' => 'application/json']));
        $this->assertSame(['type' => 'json_object'], $this->params($model)['response_format']);
    }

    public function testFunctionDeclarationsAndReturnedCallsRestoreClientNames(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray([
            'functionDeclarations' => [(new FunctionDeclaration('weather/get', 'Get weather'))->toArray()],
        ]));
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->once())->method('send')->willReturnCallback(
            function (Request $request): Response {
                $params = $request->getData();
                $name = $params['tools'][0]['function']['name'];
                $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $name);
                $data = $this->completion();
                $data['choices'][0] = [
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call-weather',
                            'type' => 'function',
                            'function' => ['name' => $name, 'arguments' => '{"city":"Paris"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ];
                return $this->response(['code' => 200, 'data' => $data]);
            }
        );
        $model->setHttpTransporter($transporter);
        $result = $model->generateTextResult($this->prompt());
        $call = $result->toMessage()->getParts()[0]->getFunctionCall();
        $this->assertNotNull($call);
        $this->assertSame('call-weather', $call->getId());
        $this->assertSame('weather/get', $call->getName());
        $this->assertSame(['city' => 'Paris'], $call->getArgs());
        $this->assertTrue($result->getCandidates()[0]->getFinishReason()->isToolCalls());
    }

    public function testMultiTurnToolHistoryUsesChatCompletionsShape(): void
    {
        $model = $this->model();
        $params = $this->params($model, [
            new Message(MessageRoleEnum::model(), [new MessagePart(new FunctionCall('call-1', 'weather/get', []))]),
            new Message(MessageRoleEnum::user(), [
                new MessagePart(new FunctionResponse('call-1', 'weather/get', ['temperature' => 18])),
            ]),
        ]);
        $this->assertSame('assistant', $params['messages'][0]['role']);
        $call = $params['messages'][0]['tool_calls'][0];
        $this->assertSame('call-1', $call['id']);
        $this->assertSame('{}', $call['function']['arguments']);
        $this->assertSame('tool', $params['messages'][1]['role']);
        $this->assertSame('call-1', $params['messages'][1]['tool_call_id']);
        $this->assertSame('{"temperature":18}', $params['messages'][1]['content']);
    }

    public function testLegacyFunctionCallFinishReasonIsNormalized(): void
    {
        $data = $this->completion();
        $data['choices'][0] = [
            'message' => [
                'role' => 'assistant',
                'function_call' => ['name' => 'weather', 'arguments' => '{}'],
            ],
            'finish_reason' => 'function_call',
        ];
        $model = $this->modelWithResponse($this->response($data));
        $result = $model->generateTextResult($this->prompt());
        $this->assertTrue($result->getCandidates()[0]->getFinishReason()->isToolCalls());
        $call = $result->toMessage()->getParts()[0]->getFunctionCall();
        $this->assertNotNull($call);
        $this->assertSame('weather', $call->getName());
        $this->assertNotEmpty($call->getId());

        $history = array_merge($this->prompt(), [
            $result->toMessage(),
            new Message(MessageRoleEnum::user(), [
                new MessagePart(new FunctionResponse($call->getId(), $call->getName(), ['temperature' => 18])),
            ]),
        ]);
        $params = $this->params($model, $history);
        $this->assertSame($call->getId(), $params['messages'][1]['tool_calls'][0]['id']);
        $this->assertSame($call->getId(), $params['messages'][2]['tool_call_id']);
        $this->assertSame('tool', $params['messages'][2]['role']);
        $this->assertSame('{"temperature":18}', $params['messages'][2]['content']);
        // Preparing the same conversation again must preserve the synthetic ID.
        $this->assertSame($params, $this->params($model, $history));
    }

    public function testLegacyFunctionCallIdsAreStablePerCompletionAndUniquePerChoice(): void
    {
        $data = $this->completion();
        $choice = [
            'message' => [
                'role' => 'assistant',
                'function_call' => ['name' => 'weather', 'arguments' => '{}'],
            ],
            'finish_reason' => 'function_call',
        ];
        $data['choices'] = [$choice, $choice];
        $first = $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
        $repeated = $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
        $firstId = $first->getCandidates()[0]->getMessage()->getParts()[0]->getFunctionCall()->getId();
        $secondId = $first->getCandidates()[1]->getMessage()->getParts()[0]->getFunctionCall()->getId();
        $this->assertNotEmpty($firstId);
        $this->assertNotSame($firstId, $secondId);
        $this->assertSame($firstId, $repeated->toMessage()->getParts()[0]->getFunctionCall()->getId());

        // Servers may omit a completion ID; independent responses still need distinct call IDs.
        unset($data['id']);
        $withoutId = $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
        $another = $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
        $this->assertNotSame(
            $withoutId->toMessage()->getParts()[0]->getFunctionCall()->getId(),
            $another->toMessage()->getParts()[0]->getFunctionCall()->getId()
        );
    }

    /**
     * @dataProvider finishReasons
     */
    public function testMapsFinishReasons(string $apiReason, string $sdkReason): void
    {
        $data = $this->completion();
        $data['choices'][0]['finish_reason'] = $apiReason;
        $result = $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
        $this->assertSame($sdkReason, $result->getCandidates()[0]->getFinishReason()->value);
    }

    /** @return array<string, array{string, string}> */
    public function finishReasons(): array
    {
        return [
            'stopped' => ['stop', 'stop'],
            'limit' => ['length', 'length'],
            'filter' => ['content_filter', 'content_filter'],
        ];
    }

    /**
     * @dataProvider malformedResponses
     * @param array<string, mixed> $data The malformed API data.
     */
    public function testRejectsMalformedOrUnsuccessfulResponses(array $data, string $message): void
    {
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage($message);
        $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function malformedResponses(): array
    {
        $badUsage = $this->completion();
        $badUsage['usage']['prompt_tokens'] = '7';
        $badTool = $this->completion();
        $badTool['choices'][0]['message']['tool_calls'] = [[
            'id' => 'call-invalid-json',
            'function' => ['name' => 'weather', 'arguments' => '{broken'],
        ]];
        $badReason = $this->completion();
        $badReason['choices'][0]['finish_reason'] = 'unknown';
        return [
            'API error in HTTP 200' => [['error' => ['message' => 'Insufficient balance']], 'Insufficient balance'],
            'SDK errors remain text data' => [
                ['error' => ['message' => 'Quota <limit> & balance "low"']],
                'Quota <limit> & balance "low"',
            ],
            'unsuccessful envelope code' => [['code' => 429, 'message' => 'Too many requests'], 'Too many requests'],
            'missing envelope data' => [['code' => 200], 'data'],
            'empty choices' => [['choices' => []], 'choices'],
            'wrong choices type' => [['choices' => 'invalid'], 'choices'],
            'wrong usage type' => [$badUsage, 'usage.prompt_tokens'],
            'invalid tool JSON' => [$badTool, 'tool_calls.function.arguments'],
            'unknown finish reason' => [$badReason, 'finish_reason'],
        ];
    }

    /**
     * @dataProvider invalidToolCalls
     * @param array<string, mixed> $call The malformed modern tool call.
     */
    public function testRejectsInvalidToolCallFieldsWithResponseException(array $call, string $field): void
    {
        $data = $this->completion();
        $data['choices'][0]['message']['tool_calls'] = [$call];
        $data['choices'][0]['finish_reason'] = 'tool_calls';
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage($field);
        $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public function invalidToolCalls(): array
    {
        $valid = ['id' => 'call-valid', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{}']];
        $cases = [];
        $missingId = $valid;
        unset($missingId['id']);
        $cases['missing ID'] = [$missingId, 'tool_calls.id'];
        foreach ([null, '', '   ', 3, true] as $index => $id) {
            $call = $valid;
            $call['id'] = $id;
            $cases['invalid ID ' . $index] = [$call, 'tool_calls.id'];
        }
        $missingName = $valid;
        unset($missingName['function']['name']);
        $cases['missing name'] = [$missingName, 'tool_calls.function.name'];
        foreach ([null, '', '   ', 3, true] as $index => $name) {
            $call = $valid;
            $call['function']['name'] = $name;
            $cases['invalid name ' . $index] = [$call, 'tool_calls.function.name'];
        }
        $missingArguments = $valid;
        unset($missingArguments['function']['arguments']);
        $cases['missing arguments'] = [$missingArguments, 'tool_calls.function.arguments'];
        foreach (['3', 'true', 'null', '"text"', '[]', '[1]', 3, true, null, [], [1]] as $index => $arguments) {
            $call = $valid;
            $call['function']['arguments'] = $arguments;
            $cases['non-object arguments ' . $index] = [$call, 'tool_calls.function.arguments'];
        }
        return $cases;
    }

    /**
     * @dataProvider validToolArguments
     * @param string|array<string, mixed> $arguments The API tool arguments.
     * @param array<string, mixed> $expected The decoded arguments.
     */
    public function testAcceptsObjectToolArguments($arguments, array $expected): void
    {
        $data = $this->completion();
        $data['choices'][0]['message']['content'] = null;
        $data['choices'][0]['message']['tool_calls'] = [[
            'id' => 'call-object',
            'type' => 'function',
            'function' => ['name' => 'weather', 'arguments' => $arguments],
        ]];
        $data['choices'][0]['finish_reason'] = 'tool_calls';
        $result = $this->modelWithResponse($this->response($data))->generateTextResult($this->prompt());
        $call = $result->toMessage()->getParts()[0]->getFunctionCall();
        $this->assertNotNull($call);
        $this->assertSame('call-object', $call->getId());
        $this->assertSame($expected, $call->getArgs());
    }

    /** @return array<string, array{string|array<string, mixed>, array<string, mixed>}> */
    public function validToolArguments(): array
    {
        return [
            'empty object JSON' => ['{}', []],
            'object JSON' => ['{"city":"Paris","days":[1,2]}', ['city' => 'Paris', 'days' => [1, 2]]],
            'decoded object' => [['city' => 'Paris'], ['city' => 'Paris']],
        ];
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Expected a JSON object');
        $this->modelWithResponse(new Response(200, [], 'not json'))->generateTextResult($this->prompt());
    }

    public function testHttpErrorsRetainSdkExceptionType(): void
    {
        $this->expectException(ClientException::class);
        $this->modelWithResponse(new Response(401, [], '{"error":{"message":"Invalid key"}}'))
            ->generateTextResult($this->prompt());
    }

    public function testRejectsStreamingCustomOptionBeforeSendingRequest(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['customOptions' => ['stream' => true]]));
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->never())->method('send');
        $model->setHttpTransporter($transporter);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stream=false');
        $model->generateTextResult($this->prompt());
    }

    public function testRejectsCustomOptionConflictingWithModel(): void
    {
        $model = $this->model();
        $model->setConfig(ModelConfig::fromArray(['customOptions' => ['model' => 'other-model']]));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflicts');
        $this->params($model);
    }

    private function model(): ApimartTextGenerationModel
    {
        $model = new ApimartTextGenerationModel(
            new ModelMetadata('claude-sonnet-4-6', 'Claude Sonnet', [CapabilityEnum::textGeneration()], []),
            new ProviderMetadata('apimart', 'APIMart', ProviderTypeEnum::cloud())
        );
        $authentication = $this->createMock(RequestAuthenticationInterface::class);
        $authentication->method('authenticateRequest')->willReturnCallback(static function (Request $request): Request {
            return $request->withHeader('Authorization', 'Bearer test-key');
        });
        $model->setRequestAuthentication($authentication);
        return $model;
    }

    private function modelWithResponse(Response $response): ApimartTextGenerationModel
    {
        $model = $this->model();
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->once())->method('send')->willReturn($response);
        $model->setHttpTransporter($transporter);
        return $model;
    }

    /** @return list<Message> */
    private function prompt(): array
    {
        return [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
    }

    /**
     * @param list<Message>|null $prompt The input messages.
     * @return array<string, mixed> The outgoing Chat Completions parameters.
     */
    private function params(ApimartTextGenerationModel $model, ?array $prompt = null): array
    {
        $method = new ReflectionMethod($model, 'prepareGenerateTextParams');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        return $method->invoke($model, $prompt ?? $this->prompt());
    }

    /** @param array<string, mixed> $data The response body. */
    private function response(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function completion(): array
    {
        return [
            'id' => 'chatcmpl-123',
            'model' => 'claude-sonnet-4-6',
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Hello back'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 4, 'total_tokens' => 11],
        ];
    }
}
