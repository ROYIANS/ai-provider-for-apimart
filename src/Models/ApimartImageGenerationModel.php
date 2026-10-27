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
        // The versioned API returns one task object; the legacy envelope returns a task list.
        if (
            is_array($submittedTasks) && !array_is_list($submittedTasks) &&
            ($submittedTasks['object'] ?? null) === 'generation.task' &&
            ($submittedTasks['type'] ?? null) === 'image' &&
            isset($submittedTasks['id'])
        ) {
            $submittedTasks = [['task_id' => $submittedTasks['id']]];
        }
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
        $expectedMimeType = $this->getConfig()->getOutputMimeType() ?? 'image/png';
        foreach ($taskIds as $taskId) {
            $task = $this->waitForTask($taskId, $deadline, $interval);
            $tasks[] = $task;
            foreach ($this->imageCandidates($task, $expectedMimeType, $deadline) as $candidate) {
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
     * @return array<string, mixed> The validated generation parameters.
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
        $capabilities = ApimartImageModelCapabilities::forModel($this->metadata()->getId());
        if ($capabilities === null) {
            // SDK exception data is escaped by consumers at the rendering boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidArgumentException('Unsupported APIMart image model: ' . $this->metadata()->getId());
        }
        $mimeType = $config->getOutputMimeType() ?? 'image/png';
        if (!in_array($mimeType, $capabilities['mimeTypes'], true)) {
            throw new InvalidArgumentException(
                // SDK exception data is escaped by consumers at the rendering boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                'This APIMart image model does not support output MIME type ' . $mimeType
            );
        }
        $count = $config->getCandidateCount() ?? 1;
        if (!in_array($count, $capabilities['candidateCounts'], true)) {
            throw new InvalidArgumentException('Unsupported candidateCount for this APIMart image model.');
        }
        $params = [
            'model' => $this->metadata()->getId(),
            'prompt' => $text,
            'n' => $count,
            'size' => $this->prepareSizeParam(
                $config->getOutputMediaOrientation(),
                $config->getOutputMediaAspectRatio()
            ),
        ];
        if ($capabilities['outputFormat']) {
            $params['output_format'] = substr($mimeType, strlen('image/'));
        }
        if ($imageUrls !== []) {
            $params['image_urls'] = $imageUrls;
        }

        foreach ($config->getCustomOptions() as $key => $value) {
            if ($key === 'apimart_poll_timeout' || $key === 'apimart_poll_interval') {
                continue;
            }
            if (
                $key === 'size' && $config->getOutputMediaOrientation() === null &&
                $config->getOutputMediaAspectRatio() === null
            ) {
                if ($value === 'auto' && $capabilities['automaticSize']) {
                    $params['size'] = $value;
                    continue;
                }
                if ($capabilities['automaticSize'] && in_array($value, $capabilities['aspectRatios'], true)) {
                    $params['size'] = $value;
                    continue;
                }
                if (is_string($value) && $capabilities['pixelDimensions']) {
                    $params['size'] = $this->validatePixelDimensions($value);
                    continue;
                }
            }
            if (array_key_exists($key, $params) || $key === 'response_format' || $key === 'output_format') {
                throw new InvalidArgumentException(
                    // SDK exception data is escaped by consumers at the rendering boundary.
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    'The custom option "' . $key . '" conflicts with an image parameter.'
                );
            }
            $params[$key] = $value;
        }
        if (isset($params['resolution'])) {
            $resolution = $params['resolution'];
            if (is_string($resolution)) {
                $resolution = $capabilities['versions'] !== [] ? strtoupper($resolution) : strtolower($resolution);
            }
            if (!in_array($resolution, $capabilities['resolutions'], true)) {
                throw new InvalidArgumentException('Unsupported resolution for this APIMart image model.');
            }
            $params['resolution'] = $resolution;
        }
        if (isset($params['version']) && !in_array($params['version'], $capabilities['versions'], true)) {
            throw new InvalidArgumentException('Unsupported version for this APIMart image model.');
        }
        if (isset($params['quality']) && $capabilities['resolutions'] !== []) {
            if (!in_array($params['quality'], $capabilities['qualities'], true)) {
                throw new InvalidArgumentException('Unsupported quality for this APIMart image model.');
            }
        }
        if (!$capabilities['outputFormat']) {
            foreach (['background', 'output_compression', 'moderation'] as $key) {
                if (array_key_exists($key, $params)) {
                    // SDK exception data is escaped by consumers at the rendering boundary.
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    throw new InvalidArgumentException('This APIMart image model does not support ' . $key . '.');
                }
            }
        }
        if (isset($params['image_urls'])) {
            $urls = $params['image_urls'];
            $maximum = $capabilities['maxReferenceImages'];
            if (!is_array($urls) || !array_is_list($urls) || count($urls) < 1 || count($urls) > $maximum) {
                throw new InvalidArgumentException(
                    // SDK exception data is escaped by consumers at the rendering boundary.
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    'APIMart image_urls must contain between 1 and ' . $maximum . ' public image URLs.'
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
                !$capabilities['mask'] ||
                !isset($params['image_urls']) ||
                !is_string($params['mask_url']) ||
                !$this->isPublicHttpUrl($params['mask_url'])
            ) {
                throw new InvalidArgumentException(
                    'APIMart mask_url requires image_urls and a public HTTP(S) mask URL.'
                );
            }
        }
        if (($params['background'] ?? null) === 'transparent' && $mimeType === 'image/jpeg') {
            throw new InvalidArgumentException('APIMart transparent backgrounds require PNG or WebP output.');
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        $capabilities = ApimartImageModelCapabilities::forModel($this->metadata()->getId());
        if ($aspectRatio !== null) {
            if (
                $capabilities === null ||
                (!in_array($aspectRatio, $capabilities['aspectRatios'], true) &&
                    !($aspectRatio === 'auto' && $capabilities['automaticSize']))
            ) {
                throw new InvalidArgumentException('Unsupported aspect ratio for this APIMart image model.');
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
     * @param string $size Exact GPT Image 2.5 pixel dimensions.
     * @return string The validated size.
     */
    private function validatePixelDimensions(string $size): string
    {
        if (preg_match('/^(\d{1,4})x(\d{1,4})$/', $size, $matches)) {
            $width = (int) $matches[1];
            $height = (int) $matches[2];
            $pixels = $width * $height;
            if (
                $width >= 16 && $height >= 16 && $width <= 3840 && $height <= 3840 &&
                $width % 16 === 0 && $height % 16 === 0 && max($width, $height) <= min($width, $height) * 3 &&
                $pixels >= 655360 && $pixels <= 8294400
            ) {
                return $size;
            }
        }
        throw new InvalidArgumentException('Invalid exact pixel dimensions for APIMart GPT Image 2.5.');
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
                // SDK exception data is escaped by consumers at the rendering boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new RuntimeException('APIMart image task "' . $taskId . '" ' . $task['status'] . $message);
            }
            if (!in_array($task['status'], ['submitted', 'pending', 'processing', 'in_progress'], true)) {
                throw ResponseException::fromInvalidData('APIMart', 'data.status', 'Unrecognized task status.');
            }
            $remaining = $deadline - $this->currentTime();
            if ($remaining > 0 && $attempt + 1 < $attempts) {
                $this->pause(min($interval, $remaining));
            }
        }
        throw new RuntimeException(
            // SDK exception data is escaped by consumers at the rendering boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
        if (isset($data['error']) || (isset($data['code']) && !in_array($data['code'], [200, 202], true))) {
            $error = $data['error'] ?? null;
            $message = is_array($error) && isset($error['message']) && is_string($error['message'])
                ? $error['message'] : 'Unsuccessful API response.';
            // SDK exception data is escaped by consumers at the rendering boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new ResponseException('APIMart image API error: ' . $message);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $task A completed image task.
     * @param string $mimeType The requested output MIME type.
     * @param float $deadline The generation and download deadline.
     * @return list<Candidate> The generated image candidates.
     */
    private function imageCandidates(array $task, string $mimeType, float $deadline): array
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
                $fileType = $this->getConfig()->getOutputFileType();
                $candidates[] = $fileType !== null && $fileType->isInline()
                    ? $this->downloadImageCandidate($url, $mimeType, count($candidates), $deadline)
                    : $this->parseResponseChoiceToCandidate(['url' => $url], count($candidates), $mimeType);
            }
        }
        return $candidates;
    }

    /**
     * Downloads an output image for callers such as WordPress that require inline base64 data.
     *
     * This request intentionally never passes through APIMart request authentication. The SDK
     * transporter buffers response bodies, so the size limit is checked before base64 conversion.
     *
     * @param string $url The already validated public output URL.
     * @param string $mimeType The expected image MIME type.
     * @param int $index The candidate index.
     * @param float $deadline The shared generation and download deadline.
     * @return Candidate The inline image candidate.
     */
    private function downloadImageCandidate(string $url, string $mimeType, int $index, float $deadline): Candidate
    {
        $remaining = $deadline - $this->currentTime();
        if ($remaining <= 0) {
            throw new RuntimeException('Timed out before downloading the APIMart generated image.');
        }
        $options = $this->getRequestOptions();
        $options = $options === null ? new RequestOptions() : clone $options;
        $configuredTimeout = $options->getTimeout();
        $options->setTimeout(
            $configuredTimeout !== null && $configuredTimeout > 0 ? min($configuredTimeout, $remaining) : $remaining
        );
        $connectTimeout = $options->getConnectTimeout();
        if ($connectTimeout !== null && $connectTimeout > 0) {
            $options->setConnectTimeout(min($connectTimeout, $remaining));
        }
        // Redirect targets would need their own public-URL validation.
        $options->setMaxRedirects(0);
        $request = new Request(HttpMethodEnum::GET(), $url, ['Accept' => $mimeType], null, $options);
        $response = $this->getHttpTransporter()->send($request);
        $this->throwIfNotSuccessful($response);

        $contentType = strtolower(trim(
            explode(';', $response->getHeaderAsString('Content-Type') ?? '')[0],
            " \f\n\r\t\v\x00"
        ));
        if ($contentType !== $mimeType || !in_array($contentType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw ResponseException::fromInvalidData(
                'APIMart',
                'image.Content-Type',
                'Downloaded image Content-Type must match the requested supported image format.'
            );
        }
        $body = $response->getBody();
        $contentLength = $response->getHeaderAsString('Content-Length');
        $maximum = 32 * 1024 * 1024;
        if (
            $body === null || $body === '' || strlen($body) > $maximum ||
            ($contentLength !== null && ctype_digit($contentLength) && (float) $contentLength > $maximum)
        ) {
            throw ResponseException::fromInvalidData(
                'APIMart',
                'image.body',
                'Downloaded images must be non-empty and no larger than 32 MiB.'
            );
        }
        return $this->parseResponseChoiceToCandidate(['b64_json' => base64_encode($body)], $index, $contentType);
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
        // Absolute URL validation also supports standalone Composer usage.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
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
            // SDK exception data is escaped by consumers at the rendering boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
