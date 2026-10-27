<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Admin;

use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * Provides a standard WordPress settings page for the API access domain.
 *
 * @since 0.1.1
 */
final class Settings
{
    private const PAGE_SLUG = 'ai-provider-for-apimart';
    private const OPTION_GROUP = 'ai_provider_for_apimart';

    private static bool $hooksRegistered = false;

    /**
     * Registers admin callbacks once, even if provider registration runs again.
     *
     * @return void
     */
    public static function registerHooks(): void
    {
        if (self::$hooksRegistered) {
            return;
        }

        self::$hooksRegistered = true;
        add_action('admin_menu', [self::class, 'addPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    /**
     * Adds the page beneath Settings, using WordPress's settings capability.
     *
     * @return void
     */
    public static function addPage(): void
    {
        add_options_page(
            'APIMart',
            'APIMart',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'renderPage']
        );
    }

    /**
     * Registers the preset-only setting and its form field.
     *
     * @return void
     */
    public static function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, ApimartProvider::BASE_URL_OPTION, [
            'type' => 'string',
            'default' => ApimartProvider::DEFAULT_BASE_URL,
            'sanitize_callback' => [self::class, 'sanitizeBaseUrl'],
        ]);
        add_settings_section('apimart_connection', '', '__return_false', self::PAGE_SLUG);
        add_settings_field(
            ApimartProvider::BASE_URL_OPTION,
            __('API access domain', 'ai-provider-for-apimart'),
            [self::class, 'renderDomainField'],
            self::PAGE_SLUG,
            'apimart_connection',
            ['label_for' => ApimartProvider::BASE_URL_OPTION]
        );
    }

    /**
     * Accepts only the supplied access domains, retaining a valid saved choice on error.
     *
     * @param mixed $value The submitted setting value.
     * @return string The allowed API base URL.
     */
    public static function sanitizeBaseUrl($value): string
    {
        if (is_string($value) && isset(ApimartProvider::baseUrlPresets()[$value])) {
            return $value;
        }

        add_settings_error(
            ApimartProvider::BASE_URL_OPTION,
            'apimart_invalid_base_url',
            __('Choose one of the listed APIMart API access domains.', 'ai-provider-for-apimart')
        );

        return self::savedBaseUrl();
    }

    /**
     * Renders the capability-checked page with the core Settings API nonce.
     *
     * @return void
     */
    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>APIMart</h1>';
        echo '<p>' . esc_html(__(
            'Choose the API access domain for this server. Model discovery, text, images and speech use this address.',
            'ai-provider-for-apimart'
        )) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('options-connectors.php')) . '">' . esc_html(__(
            'Configure the APIMart API key in Settings → Connectors.',
            'ai-provider-for-apimart'
        )) . '</a></p>';

        settings_errors(ApimartProvider::BASE_URL_OPTION);
        if (ApimartProvider::hasBaseUrlOverride()) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(__(
                'APIMART_BASE_URL is configured in the environment or wp-config.php and overrides this saved setting.',
                'ai-provider-for-apimart'
            )) . '</p></div>';
        }

        echo '<form action="options.php" method="post">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        if (!ApimartProvider::hasBaseUrlOverride()) {
            submit_button();
        }
        echo '</form></div>';
    }

    /**
     * Lists the supplied domain substitutions without sending test requests.
     *
     * @return void
     */
    public static function renderDomainField(): void
    {
        $current = self::savedBaseUrl();
        $overridden = ApimartProvider::hasBaseUrlOverride();
        $name = ApimartProvider::BASE_URL_OPTION;

        echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '"';
        echo $overridden ? ' disabled="disabled">' : '>';
        foreach (ApimartProvider::baseUrlPresets() as $url => $domain) {
            echo '<option value="' . esc_attr($url) . '"';
            echo $current === $url ? ' selected="selected">' : '>';
            echo esc_html($domain . ' — ' . $url) . '</option>';
        }
        echo '</select>';

        if ($overridden) {
            // A disabled select is not submitted; retain the saved choice if the form is submitted.
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($current) . '">';
        }
    }

    /**
     * Reads a valid saved preset without exposing unexpected stored values in the form.
     *
     * @return string The saved preset, or the default URL.
     */
    private static function savedBaseUrl(): string
    {
        $saved = get_option(ApimartProvider::BASE_URL_OPTION, ApimartProvider::DEFAULT_BASE_URL);

        return is_string($saved) && isset(ApimartProvider::baseUrlPresets()[$saved])
            ? $saved
            : ApimartProvider::DEFAULT_BASE_URL;
    }
}
