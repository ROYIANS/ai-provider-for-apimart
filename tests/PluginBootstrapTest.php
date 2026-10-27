<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the WordPress bootstrap in clean PHP subprocesses, without WordPress or the SDK.
 */
class PluginBootstrapTest extends TestCase
{
    public function testBootstrapReturnsOutsideWordPress(): void
    {
        $result = $this->runBootstrap(null, false, 1, false);

        $this->assertSame([], $result['hooks']);
        $this->assertNull($result['pluginDir']);
        $this->assertNull($result['pluginBasenameArgument']);
        $this->assertSame(0, $result['registryAccess']);
    }

    public function testRegistersInitAtPriorityFiveAndUsesTheWordPressPluginPath(): void
    {
        $result = $this->runBootstrap(null);

        $this->assertSame([
            [
                'hook' => 'init',
                'callback' => 'WordPress\\ApimartAiProvider\\register_provider',
                'priority' => 5,
            ],
        ], $result['hooks']);
        $this->assertSame(
            '/wordpress/wp-content/plugins/custom-folder/ai-provider-for-apimart',
            $result['pluginDir']
        );
        $this->assertSame(dirname(__DIR__) . '/plugin.php', $result['pluginBasenameArgument']);
    }

    public function testMissingSdkReturnsBeforeAccessingTheRegistry(): void
    {
        $result = $this->runBootstrap(null);

        $this->assertFalse($result['sdkLoaded']);
        $this->assertSame(0, $result['registryAccess']);
        $this->assertSame([], $result['checkedProviders']);
        $this->assertSame([], $result['registeredProviders']);
    }

    /**
     * @dataProvider incompatibleSdkVersionProvider
     */
    public function testIncompatibleSdkReturnsBeforeAccessingTheRegistry(string $version): void
    {
        $result = $this->runBootstrap($version);

        $this->assertTrue($result['sdkLoaded']);
        $this->assertSame(0, $result['registryAccess']);
        $this->assertSame([], $result['checkedProviders']);
        $this->assertSame([], $result['registeredProviders']);
    }

    /**
     * @return array<string, array{string}> Versions below the supported SDK minimum.
     */
    public function incompatibleSdkVersionProvider(): array
    {
        return [
            'previous minor' => ['1.3.1'],
            'latest unsupported patch' => ['1.3.99'],
            'unsupported next major' => ['2.0.0'],
        ];
    }

    /**
     * @dataProvider compatibleSdkVersionProvider
     */
    public function testCompatibleSdkRegistersTheProviderOnceAcrossRepeatedInitCalls(string $version): void
    {
        $result = $this->runBootstrap($version, false, 2);
        $providerClass = 'WordPress\\ApimartAiProvider\\Provider\\ApimartProvider';

        $this->assertSame(2, $result['registryAccess']);
        $this->assertSame([$providerClass, $providerClass], $result['checkedProviders']);
        $this->assertSame([$providerClass], $result['registeredProviders']);
    }

    /**
     * @return array<string, array{string}> Supported SDK versions.
     */
    public function compatibleSdkVersionProvider(): array
    {
        return [
            'minimum version' => ['1.4.0'],
            'later version' => ['1.5.0'],
        ];
    }

    public function testAnAlreadyRegisteredProviderIsNotRegisteredAgain(): void
    {
        $result = $this->runBootstrap('1.4.0', true);

        $this->assertSame(1, $result['registryAccess']);
        $this->assertSame(
            ['WordPress\\ApimartAiProvider\\Provider\\ApimartProvider'],
            $result['checkedProviders']
        );
        $this->assertSame([], $result['registeredProviders']);
    }

    public function testAnExistingPluginDirectoryConstantIsPreserved(): void
    {
        $result = $this->runBootstrap(null, false, 1, true, '/custom/plugin/path');

        $this->assertSame('/custom/plugin/path', $result['pluginDir']);
        $this->assertNull($result['pluginBasenameArgument']);
        $this->assertCount(1, $result['hooks']);
    }

    /**
     * Runs only the plugin and small WordPress/SDK stubs in a fresh PHP process.
     *
     * Composer's autoloader is deliberately absent so class_exists() can exercise
     * the missing-SDK path even when PHPUnit itself uses the real SDK.
     *
     * @param string|null $sdkVersion The fake SDK version, or null to omit the SDK.
     * @param bool $registered Whether the registry already contains the provider.
     * @param int $initCount The number of times to execute the registered init callback.
     * @param bool $wordpress Whether to define WordPress's bootstrap constants.
     * @param string|null $pluginDir A pre-existing plugin directory constant.
     * @return array<string, mixed> The subprocess's observable state.
     */
    private function runBootstrap(
        ?string $sdkVersion,
        bool $registered = false,
        int $initCount = 1,
        bool $wordpress = true,
        ?string $pluginDir = null
    ): array {
        $sdkDeclaration = '';
        if ($sdkVersion !== null) {
            $sdkDeclaration = str_replace('__VERSION__', var_export($sdkVersion, true), <<<'PHP_CODE'
namespace WordPress\AiClient {
    class AiClient
    {
        public const VERSION = __VERSION__;

        public static function defaultRegistry()
        {
            $GLOBALS['registryAccess']++;
            return $GLOBALS['registry'];
        }
    }
}
PHP_CODE
            );
        }

        $script = strtr(<<<'PHP_CODE'
__SDK_DECLARATION__
namespace {
    $GLOBALS['hooks'] = [];
    $GLOBALS['registryAccess'] = 0;
    $GLOBALS['registry'] = new class(__REGISTERED__) {
        public array $checkedProviders = [];
        public array $registeredProviders = [];
        private bool $registered;

        public function __construct(bool $registered)
        {
            $this->registered = $registered;
        }

        public function hasProvider(string $class): bool
        {
            $this->checkedProviders[] = $class;
            return $this->registered;
        }

        public function registerProvider(string $class): void
        {
            $this->registeredProviders[] = $class;
            $this->registered = true;
        }
    };

    function plugin_basename(string $path): string
    {
        $GLOBALS['pluginBasenameArgument'] = $path;
        return 'custom-folder/ai-provider-for-apimart/plugin.php';
    }

    function add_action(string $hook, $callback, int $priority = 10): void
    {
        $GLOBALS['hooks'][] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
        ];
    }

    if (__WORDPRESS__) {
        define('ABSPATH', '/wordpress/');
        define('WP_PLUGIN_DIR', '/wordpress/wp-content/plugins');
    }
    if (__PLUGIN_DIR__ !== null) {
        define('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR', __PLUGIN_DIR__);
    }

    require __PLUGIN_PATH__;

    for ($i = 0; $i < __INIT_COUNT__; $i++) {
        if (isset($GLOBALS['hooks'][0])) {
            call_user_func($GLOBALS['hooks'][0]['callback']);
        }
    }

    echo json_encode([
        'hooks' => $GLOBALS['hooks'],
        'pluginDir' => defined('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR') ? AI_PROVIDER_FOR_APIMART_PLUGIN_DIR : null,
        'pluginBasenameArgument' => $GLOBALS['pluginBasenameArgument'] ?? null,
        'sdkLoaded' => class_exists('WordPress\\AiClient\\AiClient', false),
        'registryAccess' => $GLOBALS['registryAccess'],
        'checkedProviders' => $GLOBALS['registry']->checkedProviders,
        'registeredProviders' => $GLOBALS['registry']->registeredProviders,
    ], JSON_THROW_ON_ERROR);
}
PHP_CODE
            , [
                '__SDK_DECLARATION__' => $sdkDeclaration,
                '__REGISTERED__' => $registered ? 'true' : 'false',
                '__WORDPRESS__' => $wordpress ? 'true' : 'false',
                '__PLUGIN_DIR__' => var_export($pluginDir, true),
                '__PLUGIN_PATH__' => var_export(dirname(__DIR__) . '/plugin.php', true),
                '__INIT_COUNT__' => (string) $initCount,
            ]);

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, (string) $errors);
        $this->assertSame('', $errors);
        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, (string) $output);
        return $result;
    }
}
