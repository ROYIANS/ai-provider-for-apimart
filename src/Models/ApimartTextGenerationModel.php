<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Models;

use stdClass;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * APIMart's synchronous, OpenAI-compatible Chat Completions adapter.
 *
 * @phpstan-import-type ToolCallData from AbstractOpenAiCompatibleTextGenerationModel
 */
class ApimartTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /** @var array<string, string> API function names mapped back to client function names. */
    private array $functionNames = [];

    /**
     * {@inheritDoc}
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $this->functionNames = [];
        $params = parent::prepareGenerateTextParams($prompt);

        // The endpoint may default to SSE. This SDK method returns a complete result.
        if (array_key_exists('stream', $params) && $params['stream'] !== false) {
            throw new InvalidArgumentException('APIMart text generation requires stream=false.');
        }
        $params['stream'] = false;

        return $params;
    }

    /**
     * Keeps plain-text messages in the string format documented by APIMart.
     *
     * @param list<Message> $messages The conversation messages.
     * @param string|null $systemInstruction The optional system instruction.
     * @return list<array<string, mixed>> The Chat Completions messages.
     */
    protected function prepareMessagesParam(array $messages, ?string $systemInstruction = null): array
    {
        $prepared = parent::prepareMessagesParam($messages, $systemInstruction);
        foreach ($prepared as &$message) {
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            $text = '';
            foreach ($content as $part) {
                if (!is_array($part) || ($part['type'] ?? null) !== 'text' || !is_string($part['text'] ?? null)) {
                    continue 2;
                }
                $text .= $part['text'];
            }
            $message['content'] = $content === [] && isset($message['tool_calls']) ? null : $text;
        }
        unset($message);
        return $prepared;
    }

    /**
     * {@inheritDoc}
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request($method, ApimartProvider::url($path), $headers, $data, $this->getRequestOptions());
    }

    /**
     * {@inheritDoc}
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        if ($outputSchema === null) {
            return ['type' => 'json_object'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'response_schema',
                'schema' => $outputSchema,
                'strict' => true,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = parent::prepareToolsParam($functionDeclarations);
        foreach ($tools as &$tool) {
            if (
                isset($tool['function']) && is_array($tool['function']) &&
                isset($tool['function']['name']) && is_string($tool['function']['name'])
            ) {
                $tool['function']['name'] = $this->apiFunctionName($tool['function']['name']);
            }
        }
        unset($tool);
        return $tools;
    }

    /**
     * {@inheritDoc}
     */
    protected function getMessagePartToolCallData(MessagePart $part): ?array
    {
        $toolCall = parent::getMessagePartToolCallData($part);
        if (
            $toolCall !== null && isset($toolCall['function']) && is_array($toolCall['function']) &&
            isset($toolCall['function']['name']) && is_string($toolCall['function']['name'])
        ) {
            $toolCall['function']['name'] = $this->apiFunctionName($toolCall['function']['name']);
        }
        return $toolCall;
    }

    /**
     * Retains valid names and deterministically maps other PHP tool names to API-safe names.
     */
    private function apiFunctionName(string $name): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name) === 1) {
            $this->functionNames[$name] = $name;
            return $name;
        }
        $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
        $apiName = substr($prefix ?? '', 0, 47) . '_' . substr(hash('sha256', $name), 0, 16);
        $this->functionNames[$apiName] = $name;
        return $apiName;
    }

    /**
     * {@inheritDoc}
     */
    protected function throwIfNotSuccessful(Response $response): void
    {
        parent::throwIfNotSuccessful($response);
        $this->responseData($response);
    }

    /**
     * Accepts both the documented {code, data} envelope and a plain Chat Completions response.
     *
     * @return array<string, mixed> The unwrapped Chat Completions response.
     */
    private function responseData(Response $response): array
    {
        $data = $response->getData();
        if ($data === null) {
            throw ResponseException::fromInvalidData('APIMart', 'body', 'Expected a JSON object.');
        }
        if (isset($data['error'])) {
            $error = $data['error'];
            $message = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message']
                : 'The API returned an error.';
            // SDK exception data is escaped by consumers at the rendering boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new ResponseException('APIMart API error: ' . $message);
        }
        if (array_key_exists('code', $data)) {
            if (!is_int($data['code']) || $data['code'] < 200 || $data['code'] >= 300) {
                $message = isset($data['message']) && is_string($data['message'])
                    ? $data['message']
                    : 'The response contains an unsuccessful or invalid code.';
                // SDK exception data is escaped by consumers at the rendering boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new ResponseException('APIMart API error: ' . $message);
            }
            if (!isset($data['data']) || !is_array($data['data']) || array_is_list($data['data'])) {
                throw ResponseException::fromInvalidData('APIMart', 'data', 'Expected a Chat Completions object.');
            }
            /** @var array<string, mixed> $data */
            $data = $data['data'];
        }
        return $data;
    }

    /**
     * {@inheritDoc}
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $data = $this->responseData($response);
        if (isset($data['choices']) && is_array($data['choices'])) {
            if (!array_is_list($data['choices'])) {
                throw ResponseException::fromInvalidData('APIMart', 'choices', 'Expected a list of choices.');
            }
            // Legacy function calls have no ID; synthesize one for modern tool history.
            // The response ID and choice position keep it stable for a given completion.
            $callNamespace = isset($data['id']) && is_string($data['id']) && $data['id'] !== ''
                ? $data['id']
                : bin2hex(random_bytes(16));
            foreach ($data['choices'] as $choiceIndex => &$choice) {
                if (!is_array($choice) || array_is_list($choice)) {
                    throw ResponseException::fromInvalidData('APIMart', 'choices', 'Expected choice objects.');
                }
                if (!isset($choice['message']) || !is_array($choice['message'])) {
                    throw ResponseException::fromMissingData('APIMart', 'choices.message');
                }
                foreach (['content', 'reasoning_content'] as $field) {
                    if (isset($choice['message'][$field]) && !is_string($choice['message'][$field])) {
                        throw ResponseException::fromInvalidData(
                            'APIMart',
                            // SDK exception data is escaped by consumers at the rendering boundary.
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                            'choices.message.' . $field,
                            'Expected text.'
                        );
                    }
                }
                if (isset($choice['message']['tool_calls'])) {
                    $calls = $choice['message']['tool_calls'];
                    if (!is_array($calls) || !array_is_list($calls)) {
                        throw ResponseException::fromInvalidData(
                            'APIMart',
                            'tool_calls',
                            'Expected a list of tool calls.'
                        );
                    }
                    foreach ($calls as $call) {
                        if (!is_array($call) || array_is_list($call)) {
                            throw ResponseException::fromInvalidData(
                                'APIMart',
                                'tool_calls',
                                'Expected tool call objects.'
                            );
                        }
                    }
                }
                // Older compatible backends use the singular function_call response form.
                if (($choice['finish_reason'] ?? null) === 'function_call') {
                    $choice['finish_reason'] = 'tool_calls';
                    if (isset($choice['message']['function_call']) && is_array($choice['message']['function_call'])) {
                        $choice['message']['tool_calls'] = [[
                            'id' => 'call_apimart_' . substr(
                                hash('sha256', $callNamespace . ':' . $choiceIndex),
                                0,
                                32
                            ),
                            'type' => 'function',
                            'function' => $choice['message']['function_call'],
                        ]];
                        unset($choice['message']['function_call']);
                    }
                }
            }
            unset($choice);
        }

        $usage = $data['usage'] ?? [];
        if (!is_array($usage)) {
            throw ResponseException::fromInvalidData('APIMart', 'usage', 'Expected an object.');
        }
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
            if (isset($usage[$field]) && (!is_int($usage[$field]) || $usage[$field] < 0)) {
                throw ResponseException::fromInvalidData(
                    'APIMart',
                    // SDK exception data is escaped by consumers at the rendering boundary.
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    'usage.' . $field,
                    'Expected a non-negative integer.'
                );
            }
        }
        $thoughtTokens = null;
        if (isset($usage['completion_tokens_details'])) {
            $details = $usage['completion_tokens_details'];
            if (!is_array($details)) {
                throw ResponseException::fromInvalidData(
                    'APIMart',
                    'usage.completion_tokens_details',
                    'Expected an object.'
                );
            }
            if (isset($details['reasoning_tokens'])) {
                if (!is_int($details['reasoning_tokens']) || $details['reasoning_tokens'] < 0) {
                    throw ResponseException::fromInvalidData(
                        'APIMart',
                        'usage.completion_tokens_details.reasoning_tokens',
                        'Expected a non-negative integer.'
                    );
                }
                $thoughtTokens = $details['reasoning_tokens'];
            }
        }

        $normalized = new Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            json_encode($data, JSON_THROW_ON_ERROR)
        );
        $result = parent::parseResponseToGenerativeAiResult($normalized);
        $tokenUsage = $result->getTokenUsage();

        return new GenerativeAiResult(
            $result->getId(),
            $result->getCandidates(),
            new TokenUsage(
                $tokenUsage->getPromptTokens(),
                $tokenUsage->getCompletionTokens(),
                $tokenUsage->getTotalTokens(),
                $thoughtTokens
            ),
            $result->getProviderMetadata(),
            $result->getModelMetadata(),
            $result->getAdditionalData()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param ToolCallData $toolCallData The tool call data from the API response.
     */
    protected function parseResponseChoiceMessageToolCallPart(array $toolCallData): ?MessagePart
    {
        if (
            !isset($toolCallData['id']) || !is_string($toolCallData['id']) ||
            trim($toolCallData['id'], " \n\r\t\v\x00") === ''
        ) {
            throw ResponseException::fromInvalidData('APIMart', 'tool_calls.id', 'Expected a non-empty call ID.');
        }
        $function = $toolCallData['function'] ?? null;
        if (
            !is_array($function) || !isset($function['name']) || !is_string($function['name']) ||
            trim($function['name'], " \n\r\t\v\x00") === ''
        ) {
            throw ResponseException::fromInvalidData(
                'APIMart',
                'tool_calls.function.name',
                'Expected a function name.'
            );
        }
        if (!array_key_exists('arguments', $function)) {
            throw ResponseException::fromMissingData('APIMart', 'tool_calls.function.arguments');
        }
        $arguments = $function['arguments'];
        if (is_string($arguments)) {
            // Decode without associative mode to distinguish an empty object from a JSON list.
            $decoded = json_decode($arguments);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ResponseException::fromInvalidData(
                    'APIMart',
                    'tool_calls.function.arguments',
                    'Expected valid JSON.'
                );
            }
            $objectArguments = $decoded instanceof stdClass;
        } else {
            $objectArguments = is_array($arguments) && !array_is_list($arguments);
        }
        if (!$objectArguments) {
            throw ResponseException::fromInvalidData(
                'APIMart',
                'tool_calls.function.arguments',
                'Expected a JSON object of function arguments.'
            );
        }
        $part = parent::parseResponseChoiceMessageToolCallPart($toolCallData);
        if ($part === null) {
            return null;
        }
        $call = $part->getFunctionCall();
        if ($call === null) {
            return $part;
        }
        $name = $call->getName();
        return new MessagePart(new FunctionCall(
            $call->getId(),
            $name !== null ? ($this->functionNames[$name] ?? $name) : null,
            $call->getArgs()
        ));
    }
}
