<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Metadata;

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * Discovers models using APIMart's server-provided category and capability tags.
 *
 * Unknown categories and unsupported endpoints are omitted. Explicit IDs still
 * resolve through this catalog, so no model capabilities are inferred from names.
 *
 * @since 0.1.0
 */
class ApimartModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Image models whose asynchronous generation contract has been verified in the documentation.
     *
     * @var list<string>
     */
    private const IMAGE_MODEL_IDS = ['gpt-image-1-official', 'gpt-image-1.5-official'];

    /**
     * {@inheritDoc}
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        if ($path === 'models') {
            $path .= '?expand=category';
        }

        return new Request($method, ApimartProvider::url($path), $headers, $data);
    }

    /**
     * Keeps endpoint- and key-scoped APIMart catalogs separate in local and persistent caches.
     *
     * Only a hash of the endpoint and authentication headers is used in the cache key.
     *
     * @return string The provider, endpoint and credentials cache namespace.
     */
    protected function getBaseCacheKey(): string
    {
        $request = $this->createRequest(HttpMethodEnum::GET(), 'models');
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        return parent::getBaseCacheKey() . '_' . hash('sha256', serialize([
            $request->getUri(),
            $request->getHeaders(),
        ]));
    }

    /**
     * {@inheritDoc}
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        $data = $response->getData();
        if ($data === null || !isset($data['data'])) {
            throw ResponseException::fromMissingData('APIMart', 'data');
        }
        if (
            (isset($data['success']) && $data['success'] !== true) ||
            (isset($data['code']) && $data['code'] !== 200) ||
            isset($data['error'])
        ) {
            throw ResponseException::fromInvalidData('APIMart', 'data', 'Model listing reported an API error.');
        }
        if (!is_array($data['data']) || !array_is_list($data['data'])) {
            throw ResponseException::fromInvalidData('APIMart', 'data', 'Expected a list of models.');
        }

        $models = [];
        foreach ($data['data'] as $index => $model) {
            if (!is_array($model) || !isset($model['id']) || !is_string($model['id']) || $model['id'] === '') {
                throw ResponseException::fromInvalidData('APIMart', 'data', 'Expected a nonempty model ID.');
            }
            $category = $model['category'] ?? 'unknown';
            $tags = $model['capability_tags'] ?? [];
            if (!is_string($category) || !is_array($tags) || !array_is_list($tags)) {
                throw ResponseException::fromInvalidData('APIMart', 'data.' . $index, 'Invalid model metadata.');
            }
            $validatedTags = [];
            foreach ($tags as $tag) {
                if (!is_string($tag)) {
                    throw ResponseException::fromInvalidData('APIMart', 'capability_tags', 'Expected string tags.');
                }
                $validatedTags[] = $tag;
            }

            $metadata = $this->createModelMetadata($model['id'], $category, $validatedTags);
            if ($metadata !== null) {
                $models[] = $metadata;
            }
        }

        // Keep discovery deterministic without preferring one upstream vendor's naming scheme.
        usort($models, static function (ModelMetadata $left, ModelMetadata $right): int {
            return strcmp($left->getId(), $right->getId());
        });

        return $models;
    }

    /**
     * Maps a catalog entry to an implemented API contract.
     *
     * @param string $id The catalog model ID.
     * @param string $category The APIMart category.
     * @param list<string> $tags The APIMart capability tags.
     * @return ModelMetadata|null Metadata, or null for an unsupported entry.
     */
    private function createModelMetadata(string $id, string $category, array $tags): ?ModelMetadata
    {
        if ($category === 'chat') {
            // Embedding-only entries share the chat category, but use a different API.
            if (in_array('Embedding', $tags, true) && !in_array('Text', $tags, true)) {
                return null;
            }
            return new ModelMetadata(
                $id,
                $id,
                [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
                $this->textOptions(in_array('Vision', $tags, true))
            );
        }
        if ($category === 'image' && in_array($id, self::IMAGE_MODEL_IDS, true)) {
            $inputModalities = [[ModalityEnum::text()]];
            if (in_array('Image to Image', $tags, true)) {
                $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
            }
            return new ModelMetadata($id, $id, [CapabilityEnum::imageGeneration()], [
                new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
                new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
                new SupportedOption(OptionEnum::candidateCount(), [1, 2, 3, 4]),
                new SupportedOption(OptionEnum::outputMimeType(), ['image/png', 'image/jpeg']),
                new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::remote()]),
                new SupportedOption(OptionEnum::outputMediaOrientation(), [
                    MediaOrientationEnum::square(),
                    MediaOrientationEnum::landscape(),
                    MediaOrientationEnum::portrait(),
                ]),
                new SupportedOption(OptionEnum::outputMediaAspectRatio(), ['1:1', '3:2', '2:3']),
                new SupportedOption(OptionEnum::customOptions()),
            ]);
        }
        if ($category === 'audio' && $id === 'gpt-4o-mini-tts') {
            return new ModelMetadata($id, $id, [CapabilityEnum::textToSpeechConversion()], [
                new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
                new SupportedOption(OptionEnum::outputMimeType(), [
                    'audio/wav', 'audio/ogg', 'audio/aac', 'audio/flac', 'audio/pcm',
                ]),
                new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
                new SupportedOption(OptionEnum::outputSpeechVoice(), [
                    'alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer',
                ]),
                new SupportedOption(OptionEnum::customOptions()),
            ]);
        }
        return null;
    }

    /**
     * Returns Chat Completions options supported by this adapter.
     *
     * Per-model parameter contracts are not supplied for chat models; APIMart
     * remains authoritative for model-specific restrictions on optional settings.
     *
     * @param bool $vision Whether APIMart advertises image input.
     * @return list<SupportedOption> The supported options.
     */
    private function textOptions(bool $vision): array
    {
        $inputModalities = [[ModalityEnum::text()]];
        if ($vision) {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }
        return [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain']),
            new SupportedOption(OptionEnum::customOptions()),
        ];
    }
}
