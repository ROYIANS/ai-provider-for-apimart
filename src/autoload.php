<?php

/**
 * WordPress plugin autoloader. Standalone applications use Composer's autoloader.
 *
 * @since 0.1.0
 *
 * @package WordPress\ApimartAiProvider
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    return;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'WordPress\\ApimartAiProvider\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);

    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
