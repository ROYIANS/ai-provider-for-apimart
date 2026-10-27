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
        $result = $this->runBootstrap('1.3.1');

        $this->assertSame([
            [
                'hook' => 'init',
                'callback' => 'WordPress\\ApimartAiProvider\\register_provider',
                'priority' => 5,
            ],
            [
                'hook' => 'admin_menu',
                'callback' => ['WordPress\\ApimartAiProvider\\Admin\\Settings', 'addPage'],
                'priority' => 10,
            ],
            [
                'hook' => 'admin_init',
                'callback' => ['WordPress\\ApimartAiProvider\\Admin\\Settings', 'registerSettings'],
                'priority' => 10,
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
            'previous patch' => ['1.3.0'],
            'previous minor' => ['1.2.99'],
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
        $this->assertSame('', $result['notice']);
        $this->assertCount(3, $result['hooks']);
    }

    /**
     * @return array<string, array{string}> Supported SDK versions.
     */
    public function compatibleSdkVersionProvider(): array
    {
        return [
            'WordPress 7.0.3 bundled SDK' => ['1.3.1'],
            'later patch' => ['1.3.99'],
            'next minor' => ['1.4.0'],
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
        $result = $this->runBootstrap('1.3.1', false, 1, true, '/custom/plugin/path');

        $this->assertSame('/custom/plugin/path', $result['pluginDir']);
        $this->assertNull($result['pluginBasenameArgument']);
        $this->assertCount(3, $result['hooks']);
    }

    public function testMissingSdkSchedulesAnActionableAdministratorNotice(): void
    {
        $result = $this->runBootstrap(null);

        $this->assertSame([
            'hook' => 'admin_notices',
            'callback' => 'WordPress\\ApimartAiProvider\\show_sdk_requirement_notice',
            'priority' => 10,
        ], $result['hooks'][1]);
        $this->assertStringContainsString('notice notice-error', $result['notice']);
        $this->assertStringContainsString('PHP AI Client 1.3.1', $result['notice']);
        $this->assertStringContainsString('The SDK is not loaded', $result['notice']);
    }

    public function testIncompatibleSdkNoticeIncludesTheLoadedSdkVersion(): void
    {
        $result = $this->runBootstrap('1.3.0');

        $this->assertStringContainsString('The loaded SDK version is 1.3.0', $result['notice']);
        $this->assertStringContainsString('APIMart connector could not be registered', $result['notice']);
    }

    public function testSdkVersionInAdministratorNoticeIsHtmlEscaped(): void
    {
        $result = $this->runBootstrap('0.0.0<script>alert("x")</script>');

        $this->assertStringContainsString(
            '0.0.0&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
            $result['notice']
        );
        $this->assertStringNotContainsString('<script>', $result['notice']);
    }

    public function testSdkRequirementNoticeIsHiddenWithoutPluginActivationPermission(): void
    {
        $result = $this->runBootstrap(null, false, 1, true, null, false);

        $this->assertCount(2, $result['hooks']);
        $this->assertSame('', $result['notice']);
    }

    public function testInvalidApiBaseUrlSkipsRegistrationAndDisplaysAnAdministratorNotice(): void
    {
        $result = $this->runBootstrap('1.3.1', false, 1, true, null, true, true);

        $this->assertSame([], $result['registeredProviders']);
        $this->assertCount(2, $result['hooks']);
        $this->assertSame('admin_notices', $result['hooks'][1]['hook']);
        $this->assertStringContainsString('APIMART_BASE_URL is invalid', $result['notice']);
        $this->assertStringContainsString('HTTPS API base URL', $result['notice']);
    }

    public function testInvalidApiBaseUrlNoticeIsHiddenWithoutPluginActivationPermission(): void
    {
        $result = $this->runBootstrap('1.3.1', false, 1, true, null, false, true);

        $this->assertSame([], $result['registeredProviders']);
        $this->assertSame('', $result['notice']);
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
     * @param bool $canActivatePlugins Whether the current user can activate plugins.
     * @param bool $invalidBaseUrl Whether provider registration rejects server configuration.
     * @return array<string, mixed> The subprocess's observable state.
     */
    private function runBootstrap(
        ?string $sdkVersion,
        bool $registered = false,
        int $initCount = 1,
        bool $wordpress = true,
        ?string $pluginDir = null,
        bool $canActivatePlugins = true,
        bool $invalidBaseUrl = false
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
namespace WordPress\AiClient\Common\Exception {
    class InvalidArgumentException extends \InvalidArgumentException {}
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
            if (__INVALID_BASE_URL__) {
                throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException('Invalid base URL');
            }
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

    function current_user_can(string $capability): bool
    {
        if ($capability !== 'activate_plugins') {
            throw new \RuntimeException('Unexpected permission check: ' . $capability);
        }
        return __CAN_ACTIVATE_PLUGINS__;
    }

    function __(string $message, string $domain): string
    {
        if ($domain !== 'ai-provider-for-apimart') {
            throw new \RuntimeException('Unexpected translation domain: ' . $domain);
        }
        return $message;
    }

    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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

    ob_start();
    foreach ($GLOBALS['hooks'] as $hook) {
        if ($hook['hook'] === 'admin_notices') {
            call_user_func($hook['callback']);
        }
    }
    $notice = ob_get_clean();

    echo json_encode([
        'hooks' => $GLOBALS['hooks'],
        'notice' => $notice,
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
                '__CAN_ACTIVATE_PLUGINS__' => $canActivatePlugins ? 'true' : 'false',
                '__INVALID_BASE_URL__' => $invalidBaseUrl ? 'true' : 'false',
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
