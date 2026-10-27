<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * Adapts APIMart's asynchronous GPT Image API to the SDK's synchronous image contract.
 *
 * Image editing accepts public reference URLs. Results retain the remote URLs and
 * their provider-supplied expiration timestamps in additionalData.tasks.
 */
class ApimartImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    /**
     * {@inheritDoc}
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $params = $this->prepareGenerateImageParams($prompt);
        $customOptions = $this->getConfig()->getCustomOptions();
        $timeout = $this->pollingOption($customOptions, 'apimart_poll_timeout', 120.0, 600.0);
        $interval = $this->pollingOption($customOptions, 'apimart_poll_interval', 2.0, 60.0);
        $deadline = $this->currentTime() + $timeout;
        $submission = $this->sendTaskRequest(HttpMethodEnum::POST(), 'images/generations', $params, $timeout);
        $submittedTasks = $submission['data'] ?? null;
        if (!is_array($submittedTasks) || !array_is_list($submittedTasks) || $submittedTasks === []) {
            throw ResponseException::fromInvalidData('APIMart', 'data', 'Expected a non-empty list of image tasks.');
        }

        $taskIds = [];
        foreach ($submittedTasks as $task) {
            if (
                !is_array($task) || !isset($task['task_id']) ||
                !is_string($task['task_id']) || $task['task_id'] === ''
            ) {
                throw ResponseException::fromInvalidData('APIMart', 'data[].task_id', 'Expected a non-empty task ID.');
            }
            if (in_array($task['task_id'], $taskIds, true)) {
                throw ResponseException::fromInvalidData('APIMart', 'data[].task_id', 'Duplicate task ID.');
            }
            $taskIds[] = $task['task_id'];
        }

        $candidates = [];
        $tasks = [];
        $expectedMimeType = $params['output_format'] === 'jpeg' ? 'image/jpeg' : 'image/png';
        foreach ($taskIds as $taskId) {
            $task = $this->waitForTask($taskId, $deadline, $interval);
            $tasks[] = $task;
            foreach ($this->imageCandidates($task, $expectedMimeType) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return new GenerativeAiResult(
            $taskIds[0],
            $candidates,
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            ['task_ids' => $taskIds, 'tasks' => $tasks]
        );
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
     * Builds JSON parameters shared by generation and editing.
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array{model: string, prompt: string, n: int, size: string, output_format: string, ...}
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        if (count($prompt) !== 1 || !$prompt[0]->getRole()->isUser()) {
            throw new InvalidArgumentException('APIMart image generation requires a single user message.');
        }

        $texts = [];
        $imageUrls = [];
        foreach ($prompt[0]->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null) {
                $texts[] = $text;
                continue;
            }
            $file = $part->getFile();
            if ($file === null || !$file->isImage() || !$file->isRemote()) {
                throw new InvalidArgumentException('APIMart image editing requires publicly accessible image URLs.');
            }
            $url = $file->getUrl();
            if ($url === null || !$this->isPublicHttpUrl($url)) {
                throw new InvalidArgumentException('APIMart image editing requires publicly accessible HTTP(S) URLs.');
            }
            $imageUrls[] = $url;
        }
        $text = implode("\n", $texts);
        if (trim($text, " \f\n\r\t\v\x00") === '') {
            throw new InvalidArgumentException('APIMart image generation requires non-empty text instructions.');
        }

        $config = $this->getConfig();
        if ($config->getOutputFileType() !== null && !$config->getOutputFileType()->isRemote()) {
            throw new InvalidArgumentException('APIMart image generation returns remote files only.');
        }
        $mimeType = $config->getOutputMimeType() ?? 'image/png';
        if (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
            throw new InvalidArgumentException('APIMart GPT Image supports image/png and image/jpeg output only.');
        }
        $count = $config->getCandidateCount() ?? 1;
        if ($count < 1 || $count > 4) {
            throw new InvalidArgumentException('APIMart GPT Image candidateCount must be between 1 and 4.');
        }
        $params = [
            'model' => $this->metadata()->getId(),
            'prompt' => $text,
            'n' => $count,
            'size' => $this->prepareSizeParam(
                $config->getOutputMediaOrientation(),
                $config->getOutputMediaAspectRatio()
            ),
            'output_format' => $mimeType === 'image/jpeg' ? 'jpeg' : 'png',
        ];
        if ($imageUrls !== []) {
            $params['image_urls'] = $imageUrls;
        }

        foreach ($config->getCustomOptions() as $key => $value) {
            if ($key === 'apimart_poll_timeout' || $key === 'apimart_poll_interval') {
                continue;
            }
            if (array_key_exists($key, $params) || $key === 'response_format') {
                throw new InvalidArgumentException(
                    'The custom option "' . $key . '" conflicts with an image parameter.'
                );
            }
            $params[$key] = $value;
        }
        if (isset($params['image_urls'])) {
            $urls = $params['image_urls'];
            if (!is_array($urls) || !array_is_list($urls) || count($urls) < 1 || count($urls) > 15) {
                throw new InvalidArgumentException(
                    'APIMart image_urls must contain between 1 and 15 public image URLs.'
                );
            }
            foreach ($urls as $url) {
                if (!is_string($url) || !$this->isPublicHttpUrl($url)) {
                    throw new InvalidArgumentException('APIMart image_urls must contain public HTTP(S) URLs.');
                }
            }
        }
        if (isset($params['mask_url'])) {
            if (
                !isset($params['image_urls']) ||
                !is_string($params['mask_url']) ||
                !$this->isPublicHttpUrl($params['mask_url'])
            ) {
                throw new InvalidArgumentException(
                    'APIMart mask_url requires image_urls and a public HTTP(S) mask URL.'
                );
            }
        }
        if (($params['background'] ?? null) === 'transparent' && $params['output_format'] === 'jpeg') {
            throw new InvalidArgumentException('APIMart transparent backgrounds require PNG output.');
        }

        /** @var array{model: string, prompt: string, n: int, size: string, output_format: string, ...} $params */
        return $params;
    }

    /**
     * {@inheritDoc}
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        if ($aspectRatio !== null) {
            if (!in_array($aspectRatio, ['1:1', '2:3', '3:2'], true)) {
                throw new InvalidArgumentException('APIMart GPT Image supports aspect ratios 1:1, 2:3 and 3:2.');
            }
            return $aspectRatio;
        }
        if ($orientation !== null) {
            if ($orientation->isLandscape()) {
                return '3:2';
            }
            if ($orientation->isPortrait()) {
                return '2:3';
            }
        }
        return '1:1';
    }

    /**
     * Polls a task without exceeding the shared deadline or a finite request budget.
     *
     * @param string $taskId The submitted task ID.
     * @param float $deadline The absolute deadline in seconds.
     * @param float $interval The delay between requests, in seconds.
     * @return array<string, mixed> The completed task, including expiration and cost metadata.
     */
    protected function waitForTask(string $taskId, float $deadline, float $interval): array
    {
        $attempts = (int) ceil(max(0.0, $deadline - $this->currentTime()) / $interval) + 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $remaining = $deadline - $this->currentTime();
            if ($remaining <= 0) {
                break;
            }
            $response = $this->sendTaskRequest(
                HttpMethodEnum::GET(),
                'tasks/' . rawurlencode($taskId),
                null,
                $remaining
            );
            $task = $response['data'] ?? null;
            if (!is_array($task) || array_is_list($task) || !isset($task['status']) || !is_string($task['status'])) {
                throw ResponseException::fromInvalidData('APIMart', 'data.status', 'Expected a task status string.');
            }
            if (isset($task['id']) && $task['id'] !== $taskId) {
                throw ResponseException::fromInvalidData(
                    'APIMart',
                    'data.id',
                    'Task ID does not match the submitted task.'
                );
            }
            if ($task['status'] === 'completed') {
                /** @var array<string, mixed> $task */
                return $task;
            }
            if ($task['status'] === 'failed' || $task['status'] === 'cancelled') {
                $error = $task['error'] ?? null;
                $message = is_array($error) && isset($error['message']) && is_string($error['message'])
                    ? ': ' . $error['message'] : '';
                throw new RuntimeException('APIMart image task "' . $taskId . '" ' . $task['status'] . $message);
            }
            if (!in_array($task['status'], ['submitted', 'pending', 'processing'], true)) {
                throw ResponseException::fromInvalidData('APIMart', 'data.status', 'Unrecognized task status.');
            }
            $remaining = $deadline - $this->currentTime();
            if ($remaining > 0 && $attempt + 1 < $attempts) {
                $this->pause(min($interval, $remaining));
            }
        }
        throw new RuntimeException(
            'Timed out waiting for APIMart image task "' . $taskId . '"; the task may still run.'
        );
    }

    /**
     * Sends an authenticated request with its HTTP timeout bounded by the remaining task deadline.
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The relative API path.
     * @param array<string, mixed>|null $data JSON request data.
     * @param float $remaining Remaining time, in seconds.
     * @return array<string, mixed> The decoded response envelope.
     */
    private function sendTaskRequest(HttpMethodEnum $method, string $path, ?array $data, float $remaining): array
    {
        $options = $this->getRequestOptions();
        $options = $options === null ? new RequestOptions() : clone $options;
        $configuredTimeout = $options->getTimeout();
        $options->setTimeout(
            $configuredTimeout !== null && $configuredTimeout > 0 ? min($configuredTimeout, $remaining) : $remaining
        );
        $request = new Request(
            $method,
            ApimartProvider::url($path),
            ['Content-Type' => 'application/json'],
            $data,
            $options
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        $this->throwIfNotSuccessful($response);
        return $this->parseTaskEnvelope($response);
    }

    /**
     * @param Response $response The response to validate.
     * @return array<string, mixed> The decoded envelope.
     */
    private function parseTaskEnvelope(Response $response): array
    {
        $data = $response->getData();
        if ($data === null) {
            throw ResponseException::fromInvalidData('APIMart', 'response', 'Expected a JSON object.');
        }
        if (isset($data['error']) || (isset($data['code']) && $data['code'] !== 200)) {
            $error = $data['error'] ?? null;
            $message = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message'] : 'Unsuccessful API response.';
            throw new ResponseException('APIMart image API error: ' . $message);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $task A completed image task.
     * @param string $mimeType The requested output MIME type.
     * @return list<Candidate> The generated image candidates.
     */
    private function imageCandidates(array $task, string $mimeType): array
    {
        $result = $task['result'] ?? null;
        $images = is_array($result) ? ($result['images'] ?? null) : null;
        if (!is_array($images) || !array_is_list($images) || $images === []) {
            throw ResponseException::fromInvalidData('APIMart', 'data.result.images', 'Expected generated images.');
        }
        $candidates = [];
        foreach ($images as $image) {
            $urls = is_array($image) ? ($image['url'] ?? null) : null;
            if (!is_array($urls) || !array_is_list($urls) || $urls === []) {
                throw ResponseException::fromInvalidData('APIMart', 'data.result.images[].url', 'Expected image URLs.');
            }
            foreach ($urls as $url) {
                if (!is_string($url) || !$this->isPublicHttpUrl($url)) {
                    throw ResponseException::fromInvalidData(
                        'APIMart',
                        'data.result.images[].url',
                        'Invalid image URL.'
                    );
                }
                $candidates[] = $this->parseResponseChoiceToCandidate(['url' => $url], count($candidates), $mimeType);
            }
        }
        return $candidates;
    }

    /**
     * Validates URL syntax and rejects local hosts/IPs; remote reachability remains the caller's responsibility.
     *
     * @param string $url The reference or generated URL.
     * @return bool Whether the URL can identify a public HTTP resource.
     */
    private function isPublicHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (
            !is_array($parts) || !isset($parts['scheme'], $parts['host']) ||
            isset($parts['user']) || isset($parts['pass'])
        ) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }
        return strpos($host, '.') !== false && !preg_match('/(?:^|\.)(?:localhost|local)$/', $host);
    }

    /**
     * @param array<string, mixed> $options Custom model options.
     * @param string $name The local polling option name.
     * @param float $default The default number of seconds.
     * @param float $maximum The maximum number of seconds.
     * @return float The validated option.
     */
    private function pollingOption(array $options, string $name, float $default, float $maximum): float
    {
        $value = $options[$name] ?? $default;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0.1 || $value > $maximum) {
            throw new InvalidArgumentException($name . ' must be a number between 0.1 and ' . $maximum . ' seconds.');
        }
        return (float) $value;
    }

    /**
     * Clock seam for deterministic polling tests.
     *
     * @return float The current time in seconds.
     */
    protected function currentTime(): float
    {
        return microtime(true);
    }

    /**
     * @param float $seconds The polling delay in seconds.
     */
    protected function pause(float $seconds): void
    {
        usleep((int) round($seconds * 1000000));
    }
}
