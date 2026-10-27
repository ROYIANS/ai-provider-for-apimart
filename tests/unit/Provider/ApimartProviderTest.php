<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\unit\Provider;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\ApimartAiProvider\Metadata\ApimartModelMetadataDirectory;
use WordPress\ApimartAiProvider\Models\ApimartImageGenerationModel;
use WordPress\ApimartAiProvider\Models\ApimartTextGenerationModel;
use WordPress\ApimartAiProvider\Models\ApimartTextToSpeechConversionModel;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * @covers \WordPress\ApimartAiProvider\Provider\ApimartProvider
 */
class ApimartProviderTest extends TestCase
{
    /**
     * Tests URL resolution and endpoint concatenation with base URL.
     *
     * @return void
     */
    public function testBaseUrlAndUrlConstruction(): void
    {
        $this->assertSame('https://api.apimart.ai/v1', ApimartProvider::url());
        $this->assertSame('https://api.apimart.ai/v1/models', ApimartProvider::url('models'));
        $this->assertSame('https://api.apimart.ai/v1/chat/completions', ApimartProvider::url('/chat/completions'));
    }

    /**
     * Tests that provider metadata is constructed with correct properties.
     *
     * @return void
     */
    public function testProviderMetadata(): void
    {
        $metadata = ApimartProvider::metadata();

        $this->assertInstanceOf(ProviderMetadata::class, $metadata);
        $this->assertSame('apimart', $metadata->getId());
        $this->assertSame('APIMart', $metadata->getName());
        $this->assertTrue($metadata->getType()->isCloud());
        $this->assertSame('https://apimart.ai/keys', $metadata->getCredentialsUrl());
        $this->assertTrue($metadata->getAuthenticationMethod()->isApiKey());

        $this->assertSame('Text, image and speech generation through APIMart.', $metadata->getDescription());
        $logoPath = $metadata->getLogoPath();
        $this->assertNotNull($logoPath);
        $this->assertStringEndsWith('assets/images/apimart.svg', $logoPath);
        $this->assertFileExists($logoPath);
    }

    /**
     * Tests that modelMetadataDirectory returns the directory instance and caches it.
     *
     * @return void
     */
    public function testModelMetadataDirectory(): void
    {
        $directory1 = ApimartProvider::modelMetadataDirectory();
        $directory2 = ApimartProvider::modelMetadataDirectory();

        $this->assertInstanceOf(ApimartModelMetadataDirectory::class, $directory1);
        $this->assertSame($directory1, $directory2);
    }

    /**
     * Tests that availability returns the availability checker instance and caches it.
     *
     * @return void
     */
    public function testProviderAvailability(): void
    {
        $availability1 = ApimartProvider::availability();
        $availability2 = ApimartProvider::availability();

        $this->assertInstanceOf(ListModelsApiBasedProviderAvailability::class, $availability1);
        $this->assertSame($availability1, $availability2);
    }

    /**
     * Tests that createModel creates an ApimartTextGenerationModel for text-generation capability.
     *
     * @return void
     */
    public function testCreateModelWithTextGenerationCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'gpt-4o',
            'gpt-4o',
            [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(ApimartTextGenerationModel::class, $model);
    }

    /**
     * Tests that createModel creates an ApimartImageGenerationModel for image-generation capability.
     *
     * @return void
     */
    public function testCreateModelWithImageGenerationCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'gpt-image-1-official',
            'gpt-image-1-official',
            [CapabilityEnum::imageGeneration()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(ApimartImageGenerationModel::class, $model);
    }

    /**
     * Embedding endpoints are not advertised until their APIMart contract is verified.
     */
    public function testCreateModelRejectsUnimplementedEmbeddingCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'text-embedding-3-small',
            'text-embedding-3-small',
            [CapabilityEnum::embeddingGeneration()],
            []
        );

        $this->expectException(RuntimeException::class);
        $this->createModelViaProvider($modelMetadata);
    }

    /**
     * Tests that createModel creates an ApimartTextToSpeechConversionModel for text-to-speech capability.
     *
     * @return void
     */
    public function testCreateModelWithTextToSpeechConversionCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'gpt-4o-mini-tts',
            'gpt-4o-mini-tts',
            [CapabilityEnum::textToSpeechConversion()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(ApimartTextToSpeechConversionModel::class, $model);
    }

    /**
     * Tests that createModel skips unhandled capabilities before reaching a supported one.
     *
     * @return void
     */
    public function testCreateModelSkipsUnhandledCapabilities(): void
    {
        $modelMetadata = new ModelMetadata(
            'gpt-4o',
            'gpt-4o',
            [CapabilityEnum::chatHistory(), CapabilityEnum::textGeneration()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(ApimartTextGenerationModel::class, $model);
    }

    /**
     * Tests that createModel instantiates the first matching capability when multiple supported capabilities exist.
     *
     * @return void
     */
    public function testCreateModelSelectsFirstMatchingCapability(): void
    {
        $modelMetadata = new ModelMetadata(
            'hybrid-model',
            'hybrid-model',
            [CapabilityEnum::imageGeneration(), CapabilityEnum::textGeneration()],
            []
        );

        $model = $this->createModelViaProvider($modelMetadata);

        $this->assertInstanceOf(ApimartImageGenerationModel::class, $model);
    }

    /**
     * Tests that createModel throws RuntimeException when unsupported capabilities are provided.
     *
     * @return void
     */
    public function testCreateModelThrowsRuntimeExceptionForUnsupportedCapabilities(): void
    {
        $modelMetadata = new ModelMetadata(
            'unsupported-model',
            'unsupported-model',
            [CapabilityEnum::speechGeneration()],
            []
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported model capabilities: speech_generation');

        $this->createModelViaProvider($modelMetadata);
    }

    /**
     * Tests that createModel throws RuntimeException when capabilities list is empty.
     *
     * @return void
     */
    public function testCreateModelThrowsRuntimeExceptionForEmptyCapabilities(): void
    {
        $modelMetadata = new ModelMetadata(
            'empty-model',
            'empty-model',
            [],
            []
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported model capabilities: ');

        $this->createModelViaProvider($modelMetadata);
    }

    /**
     * Helper to invoke protected static createModel on ApimartProvider.
     *
     * @param ModelMetadata $modelMetadata The model metadata.
     * @return ModelInterface The instantiated model.
     */
    private function createModelViaProvider(ModelMetadata $modelMetadata): ModelInterface
    {
        $provider = new class extends ApimartProvider {
            public static function exposeCreateModel(
                ModelMetadata $modelMetadata,
                ProviderMetadata $providerMetadata
            ): ModelInterface {
                return static::createModel($modelMetadata, $providerMetadata);
            }
        };

        return $provider::exposeCreateModel($modelMetadata, ApimartProvider::metadata());
    }
}
