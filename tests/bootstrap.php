<?php

/**
 * PHPUnit bootstrap file for the AI Provider for APIMart package.
 *
 * @since 0.1.0
 *
 * @package WordPress\ApimartAiProvider
 */

declare(strict_types=1);

/*
 * Load the PHP AI Client SDK. Prefer the Composer autoloader; alternatively, a standalone
 * autoloader for the SDK may be provided via the PHP_AI_CLIENT_AUTOLOAD environment variable
 * (useful in environments where Composer dependencies cannot be installed).
 */
$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$sdkAutoload = getenv('PHP_AI_CLIENT_AUTOLOAD');
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
} elseif (is_string($sdkAutoload) && $sdkAutoload !== '' && file_exists($sdkAutoload)) {
    require_once $sdkAutoload;
}

// Keep standalone tests independent of the WordPress-only plugin autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WordPress\\ApimartAiProvider\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});
