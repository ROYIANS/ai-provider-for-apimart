<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Provider;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\ApimartAiProvider\Metadata\ApimartModelMetadataDirectory;
use WordPress\ApimartAiProvider\Models\ApimartImageGenerationModel;
use WordPress\ApimartAiProvider\Models\ApimartTextGenerationModel;
use WordPress\ApimartAiProvider\Models\ApimartTextToSpeechConversionModel;

/**
 * APIMart provider for the PHP AI Client.
 *
 * @since 0.1.0
 */
class ApimartProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     */
    protected static function baseUrl(): string
    {
        return 'https://api.apimart.ai/v1';
    }

    /**
     * {@inheritDoc}
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return new ApimartTextGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isImageGeneration()) {
                return new ApimartImageGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isTextToSpeechConversion()) {
                return new ApimartTextToSpeechConversionModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $modelMetadata->getSupportedCapabilities())
        );
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $description = 'Text, image and speech generation through APIMart.';
        if (function_exists('__')) {
            $translated = __('Text, image and speech generation through APIMart.', 'ai-provider-for-apimart');
            if (is_string($translated)) {
                $description = $translated;
            }
        }

        $pluginDir = dirname(__DIR__, 2);
        if (defined('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR')) {
            $definedPluginDir = constant('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR');
            if (is_string($definedPluginDir)) {
                $pluginDir = $definedPluginDir;
            }
        }

        return new ProviderMetadata(
            'apimart',
            'APIMart',
            ProviderTypeEnum::cloud(),
            'https://apimart.ai/keys',
            RequestAuthenticationMethod::apiKey(),
            $description,
            $pluginDir . '/assets/images/apimart.svg'
        );
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ListModelsApiBasedProviderAvailability(static::modelMetadataDirectory());
    }

    /**
     * {@inheritDoc}
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ApimartModelMetadataDirectory();
    }
}
