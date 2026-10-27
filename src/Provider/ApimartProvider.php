<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Provider;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
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
    public const DEFAULT_BASE_URL = 'https://api.apimart.ai/v1';
    public const BASE_URL_OPTION = 'ai_provider_for_apimart_base_url';

    private const URL_WHITESPACE = " \t\n\r\0\x0B";

    /**
     * Lists the APIMart API domains available in WordPress settings.
     *
     * @since 0.1.1
     *
     * @return array<string, string> API base URLs mapped to website domains.
     */
    public static function baseUrlPresets(): array
    {
        return [
            self::DEFAULT_BASE_URL => 'apimart.ai',
            'https://api.apib.ai/v1' => 'apib.ai',
            'https://api.aiuxu.com/v1' => 'aiuxu.com',
            'https://api.aishuch.com/v1' => 'aishuch.com',
        ];
    }

    /**
     * Indicates whether server configuration overrides the WordPress setting.
     *
     * @since 0.1.1
     *
     * @return bool Whether an environment variable or constant supplies the base URL.
     */
    public static function hasBaseUrlOverride(): bool
    {
        return self::baseUrlOverride() !== null;
    }

    /**
     * {@inheritDoc}
     */
    protected static function baseUrl(): string
    {
        $override = self::baseUrlOverride();
        if ($override !== null) {
            return self::validateBaseUrl($override);
        }

        if (function_exists('get_option')) {
            $configured = get_option(self::BASE_URL_OPTION, self::DEFAULT_BASE_URL);
            if (is_string($configured)) {
                $configured = rtrim(trim($configured, self::URL_WHITESPACE), '/');
                if (isset(static::baseUrlPresets()[$configured])) {
                    return $configured;
                }
            }
        }

        return self::DEFAULT_BASE_URL;
    }

    /**
     * Reads explicit server configuration before consulting WordPress options.
     *
     * @return string|null The configured value, or null when no override exists.
     */
    private static function baseUrlOverride(): ?string
    {
        $environment = getenv('APIMART_BASE_URL');
        if (is_string($environment) && trim($environment, self::URL_WHITESPACE) !== '') {
            return trim($environment, self::URL_WHITESPACE);
        }

        if (defined('APIMART_BASE_URL')) {
            $constant = constant('APIMART_BASE_URL');
            if (!is_string($constant)) {
                throw new InvalidArgumentException('APIMART_BASE_URL must be an HTTPS URL string.');
            }
            if (trim($constant, self::URL_WHITESPACE) !== '') {
                return trim($constant, self::URL_WHITESPACE);
            }
        }

        return null;
    }

    /**
     * Validates an explicit destination before requests can include API credentials.
     *
     * @param string $url The configured API base URL.
     * @return string The normalized base URL without a trailing slash.
     */
    private static function validateBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false ||
            !is_array($parts) ||
            !isset($parts['scheme'], $parts['host']) ||
            strtolower($parts['scheme']) !== 'https' ||
            $parts['host'] === '' ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            strpos($url, '?') !== false ||
            strpos($url, '#') !== false
        ) {
            throw new InvalidArgumentException(
                'APIMART_BASE_URL must be an HTTPS URL without credentials, a query string, or a fragment.'
            );
        }

        return $url;
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
            $description = __('Text, image and speech generation through APIMart.', 'ai-provider-for-apimart');
        }

        $pluginDir = dirname(__DIR__, 2);
        if (defined('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR')) {
            $definedPluginDir = constant('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR');
            if (is_string($definedPluginDir)) {
                $pluginDir = $definedPluginDir;
            }
        }

        $baseUrl = static::baseUrl();
        $websiteDomain = static::baseUrlPresets()[$baseUrl] ?? 'apimart.ai';

        return new ProviderMetadata(
            'apimart',
            'APIMart',
            ProviderTypeEnum::cloud(),
            'https://' . $websiteDomain . '/keys',
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
