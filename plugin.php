<?php

/**
 * Plugin Name:       AI Provider for APIMart
 * Plugin URI:        https://github.com/ROYIANS/ai-provider-for-apimart
 * Description:       APIMart text, image and speech integration for the WordPress AI Client.
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Version:           0.1.0
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
        version_compare(AiClient::VERSION, '1.4.0', '<') ||
        version_compare(AiClient::VERSION, '2.0.0', '>=')
    ) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(ApimartProvider::class)) {
        return;
    }

    $registry->registerProvider(ApimartProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
