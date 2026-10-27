<?php

/**
 * Plugin Name:       AI Provider for APIMart
 * Plugin URI:        https://github.com/ROYIANS/ai-provider-for-apimart
 * Description:       APIMart text, image and speech integration for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           0.1.2
 * Author:            ROYIANS
 * Author URI:        https://github.com/ROYIANS
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       ai-provider-for-apimart
 *
 * @package WordPress\ApimartAiProvider
 */

declare(strict_types=1);

namespace WordPress\ApimartAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\ApimartAiProvider\Admin\Settings;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

if (!defined('ABSPATH')) {
    return;
}

// Preserve the path beneath WP_PLUGIN_DIR when WordPress loads the plugin through a symlink.
if (!defined('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR')) {
    define(
        'AI_PROVIDER_FOR_APIMART_PLUGIN_DIR',
        WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__))
    );
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers APIMart when a compatible PHP AI Client is available.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void
{
    if (
        !class_exists(AiClient::class) ||
        version_compare(AiClient::VERSION, '1.3.1', '<') ||
        version_compare(AiClient::VERSION, '2.0.0', '>=')
    ) {
        add_action('admin_notices', __NAMESPACE__ . '\\show_sdk_requirement_notice');
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(ApimartProvider::class)) {
        Settings::registerHooks();
        return;
    }

    try {
        $registry->registerProvider(ApimartProvider::class);
    } catch (InvalidArgumentException $exception) {
        add_action('admin_notices', __NAMESPACE__ . '\\show_base_url_requirement_notice');
        return;
    }

    Settings::registerHooks();
}

/**
 * Keeps malformed server endpoint configuration from interrupting WordPress.
 *
 * @since 0.1.1
 *
 * @return void
 */
function show_base_url_requirement_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-error"><p>' . esc_html(__(
        'APIMart could not be registered because APIMART_BASE_URL is invalid. '
        . 'Set an HTTPS API base URL without credentials, a query string, or a fragment '
        . 'in your environment or wp-config.php.',
        'ai-provider-for-apimart'
    )) . '</p></div>';
}

/**
 * Explains why an active plugin cannot register its provider.
 *
 * @since 0.1.1
 *
 * @return void
 */
function show_sdk_requirement_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    if (!class_exists(AiClient::class)) {
        $message = __(
            'AI Provider for APIMart requires PHP AI Client 1.3.1 or later within major version 1. '
            . 'The SDK is not loaded, so the APIMart connector could not be registered.',
            'ai-provider-for-apimart'
        );
    } else {
        $message = sprintf(
            /* translators: %s: Installed PHP AI Client SDK version. */
            __(
                'AI Provider for APIMart requires PHP AI Client 1.3.1 or later within major version 1. '
                . 'The loaded SDK version is %s, so the APIMart connector could not be registered.',
                'ai-provider-for-apimart'
            ),
            AiClient::VERSION
        );
    }

    echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
