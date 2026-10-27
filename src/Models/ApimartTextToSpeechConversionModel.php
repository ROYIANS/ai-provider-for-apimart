<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * APIMart text-to-speech conversion using the binary Audio Speech API.
 *
 * Uses the `/audio/speech` endpoint to synthesize speech from text with models
 * `gpt-4o-mini-tts`.
 *
 * @since 0.1.0
 */
class ApimartTextToSpeechConversionModel extends AbstractApiBasedModel implements
    TextToSpeechConversionModelInterface
{
    /**
     * Default voice used when none is configured.
     */
    private const DEFAULT_VOICE = 'alloy';

    /**
     * Default output MIME type used when none is configured.
     */
    private const DEFAULT_MIME_TYPE = 'audio/wav';

    /**
     * Maps the documented APIMart output MIME types to `response_format` values.
     *
     * @var array<string, string>
     */
    private const MIME_TYPE_TO_RESPONSE_FORMAT = [
        'audio/ogg'  => 'opus',
        'audio/wav'  => 'wav',
        'audio/flac' => 'flac',
        'audio/aac'  => 'aac',
        'audio/pcm'  => 'pcm',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $mimeType = $this->prepareOutputMimeType();
        $params   = $this->prepareConvertParams($prompt, $mimeType);

        $request = new Request(
            HttpMethodEnum::POST(),
            ApimartProvider::url('audio/speech'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        // Add authentication credentials to the request.
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        // Send and process the request.
        $response = $httpTransporter->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response, $mimeType);
    }

    /**
     * Prepares the request parameters for the Audio Speech API.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt   The prompt messages containing the text.
     * @param string        $mimeType The resolved output MIME type.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareConvertParams(array $prompt, string $mimeType): array
    {
        $config = $this->getConfig();

        if ($config->getOutputFileType() !== null && !$config->getOutputFileType()->isInline()) {
            throw new InvalidArgumentException('APIMart speech returns inline audio files only.');
        }

        $params = [
            'model'           => $this->metadata()->getId(),
            'input'           => $this->preparePromptText($prompt),
            'voice'           => $this->prepareVoice(),
            'response_format' => self::MIME_TYPE_TO_RESPONSE_FORMAT[$mimeType],
        ];

        /*
         * Any custom options are added to the parameters as well.
         * This allows developers to pass other options such as 'speed' or
         * 'instructions' that may not yet be first-class options in the SDK.
         */
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        $key
                    )
                );
            }
            $params[$key] = $value;
        }

        if (isset($params['speed'])) {
            $speed = $params['speed'];
            if ((!is_int($speed) && !is_float($speed)) || !is_finite((float) $speed) || $speed < 0.25 || $speed > 4) {
                throw new InvalidArgumentException('Speech speed must be a number between 0.25 and 4.0.');
            }
        }

        return $params;
    }

    /**
     * Extracts the plain text to synthesize from the prompt.
     *
     * The Audio Speech API accepts a single block of text, so this concatenates
     * the text parts found across the prompt messages.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The text to convert to speech.
     * @throws InvalidArgumentException If no text is found in the prompt.
     */
    protected function preparePromptText(array $prompt): string
    {
        $text = '';
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $partText = $part->getText();
                if ($partText === null) {
                    throw new InvalidArgumentException('APIMart speech input must contain only text.');
                }
                $text .= ('' === $text ? '' : "\n") . $partText;
            }
        }

        if ('' === trim($text, " \f\n\r\t\v\x00")) {
            throw new InvalidArgumentException(
                'The prompt must contain text to convert to speech.'
            );
        }

        // Count Unicode characters without requiring the optional mbstring extension.
        $length = preg_match_all('/./us', $text);
        if ($length === false || $length > 4096) {
            throw new InvalidArgumentException(
                'APIMart speech input must be valid UTF-8 with at most 4096 characters.'
            );
        }

        return $text;
    }

    /**
     * Resolves the voice to use, falling back to the default.
     *
     * @since 0.1.0
     *
     * @return string The voice identifier.
     */
    protected function prepareVoice(): string
    {
        $voice = $this->getConfig()->getOutputSpeechVoice();
        $voice = ($voice === null || '' === $voice) ? self::DEFAULT_VOICE : $voice;
        if (!in_array($voice, ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'], true)) {
            throw new InvalidArgumentException(sprintf('The APIMart speech voice "%s" is not supported.', $voice));
        }
        return $voice;
    }

    /**
     * Resolves the output MIME type, defaulting and validating against supported types.
     *
     * @since 0.1.0
     *
     * @return string A supported audio MIME type.
     * @throws InvalidArgumentException If the configured MIME type is unsupported.
     */
    protected function prepareOutputMimeType(): string
    {
        $mimeType = $this->getConfig()->getOutputMimeType();
        if ($mimeType === null || '' === $mimeType) {
            return self::DEFAULT_MIME_TYPE;
        }

        if (!isset(self::MIME_TYPE_TO_RESPONSE_FORMAT[$mimeType])) {
            throw new InvalidArgumentException(
                sprintf(
                    'The output MIME type "%s" is not supported for text to speech.',
                    $mimeType
                )
            );
        }

        return $mimeType;
    }

    /**
     * Parses the binary audio response into a generative AI result.
     *
     * The Audio Speech API returns raw audio bytes rather than JSON, so the body
     * is base64-encoded into an inline audio file.
     *
     * @since 0.1.0
     *
     * @param Response $response The HTTP response containing raw audio bytes.
     * @param string   $mimeType The MIME type of the returned audio.
     * @return GenerativeAiResult The generative AI result containing the audio file.
     * @throws ResponseException If the response body is empty.
     */
    protected function parseResponseToGenerativeAiResult(Response $response, string $mimeType): GenerativeAiResult
    {
        $body = $response->getBody();
        if ($body === null || '' === $body) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                'body'
            );
        }

        // A gateway can return an application error with HTTP 200; never label JSON as audio.
        $contentType = strtolower($response->getHeaderAsString('Content-Type') ?? '');
        if (strpos($contentType, 'json') !== false || $response->getData() !== null) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'body',
                'Expected binary audio but received JSON.'
            );
        }
        if (strpos($contentType, 'text/') === 0) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'Content-Type',
                'Expected binary audio but received text.'
            );
        }

        $audioFile = new File(base64_encode($body), $mimeType);

        $message   = new Message(MessageRoleEnum::model(), [new MessagePart($audioFile)]);
        $candidate = new Candidate($message, FinishReasonEnum::stop());

        return new GenerativeAiResult(
            $this->generateResultId(),
            [$candidate],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Generates a result identifier.
     *
     * The Audio Speech API does not return an identifier, so one is generated
     * locally for the result.
     *
     * @since 0.1.0
     *
     * @return string The result identifier.
     */
    protected function generateResultId(): string
    {
        return uniqid('apimart-tts-', true);
    }
}
