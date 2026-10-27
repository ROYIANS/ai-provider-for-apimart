<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;
use RuntimeException;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\ApimartAiProvider\Admin\Settings;
use WordPress\ApimartAiProvider\Metadata\ApimartModelMetadataDirectory;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * Namespaced WordPress doubles exercise forms and sanitization without real API traffic.
 */
class SettingsTest extends TestCase
{
    public static bool $allowed = true;
    public static bool $validNonce = true;

    /** @var mixed */
    public static $saved;

    /** @var list<array<mixed>> */
    public static array $calls = [];

    /** @var list<callable> */
    public static array $fields = [];

    /** @var array<string, string> */
    public static array $translations = [];

    /** @var string|false */
    private $originalBaseUrl;

    /** @var array<string, mixed> */
    private array $originalQuery;

    private ?CacheInterface $originalCache;

    /** @var array<mixed> */
    private array $originalDirectories;

    private ReflectionProperty $directoryCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBaseUrl = getenv('APIMART_BASE_URL');
        putenv('APIMART_BASE_URL');
        $this->originalQuery = $_GET;
        $_GET = [];
        $this->originalCache = AiClient::getCache();
        $this->directoryCache = new ReflectionProperty(AbstractProvider::class, 'modelMetadataDirectoryCache');
        if (PHP_VERSION_ID < 80100) {
            $this->directoryCache->setAccessible(true);
        }
        $this->originalDirectories = $this->directoryCache->getValue();
        SettingsTest::$allowed = true;
        SettingsTest::$validNonce = true;
        SettingsTest::$saved = ApimartProvider::DEFAULT_BASE_URL;
        SettingsTest::$calls = [];
        SettingsTest::$fields = [];
        SettingsTest::$translations = [];
    }

    protected function tearDown(): void
    {
        putenv($this->originalBaseUrl === false ? 'APIMART_BASE_URL' : 'APIMART_BASE_URL=' . $this->originalBaseUrl);
        $_GET = $this->originalQuery;
        AiClient::setCache($this->originalCache);
        $this->directoryCache->setValue(null, $this->originalDirectories);
        parent::tearDown();
    }

    public function testRegistersAdminHooksOnlyOnce(): void
    {
        Settings::registerHooks();
        Settings::registerHooks();

        $this->assertSame([
            ['add_action', 'admin_menu', [Settings::class, 'addPage']],
            ['add_action', 'admin_init', [Settings::class, 'registerSettings']],
            ['add_action', 'admin_post_apimart_refresh_models', [Settings::class, 'refreshModels']],
        ], SettingsTest::$calls);
    }

    public function testPageAndOptionUseCoreSettingsPermissionsAndSanitizer(): void
    {
        Settings::addPage();
        Settings::registerSettings();

        $this->assertSame([
            'add_options_page', 'APIMart', 'APIMart', 'manage_options',
            'ai-provider-for-apimart', [Settings::class, 'renderPage'],
        ], SettingsTest::$calls[0]);
        $this->assertSame([
            'register_setting', 'ai_provider_for_apimart', ApimartProvider::BASE_URL_OPTION,
            [
                'type' => 'string',
                'default' => ApimartProvider::DEFAULT_BASE_URL,
                'sanitize_callback' => [Settings::class, 'sanitizeBaseUrl'],
            ],
        ], SettingsTest::$calls[1]);
    }

    public function testAcceptsEachListedApiDomain(): void
    {
        $this->assertCount(4, ApimartProvider::baseUrlPresets());
        foreach (array_keys(ApimartProvider::baseUrlPresets()) as $url) {
            $this->assertSame($url, Settings::sanitizeBaseUrl($url));
        }
        $this->assertSame([], SettingsTest::$calls);
    }

    /**
     * @dataProvider invalidBaseUrlProvider
     * @param mixed $value The untrusted submitted value.
     */
    public function testRejectsUnlistedOrMalformedValuesAndRetainsSavedChoice($value): void
    {
        SettingsTest::$saved = 'https://api.apib.ai/v1';

        $this->assertSame(SettingsTest::$saved, Settings::sanitizeBaseUrl($value));
        $this->assertSame('add_settings_error', SettingsTest::$calls[0][0]);
        $this->assertSame(ApimartProvider::BASE_URL_OPTION, SettingsTest::$calls[0][1]);
    }

    /**
     * @return array<string, array{mixed}> Untrusted option submissions.
     */
    public function invalidBaseUrlProvider(): array
    {
        return [
            'arbitrary endpoint' => ['https://example.test/v1'],
            'insecure scheme' => ['http://api.apib.ai/v1'],
            'HTML injection' => ['"><script>alert(1)</script>'],
            'array' => [['https://api.apib.ai/v1']],
            'missing' => [null],
        ];
    }

    public function testFallsBackToDefaultWhenStoredChoiceIsInvalid(): void
    {
        SettingsTest::$saved = '<script>bad-option</script>';

        $this->assertSame(ApimartProvider::DEFAULT_BASE_URL, Settings::sanitizeBaseUrl(null));
        ob_start();
        Settings::renderDomainField();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(
            '<option value="https://api.apimart.ai/v1" selected="selected">',
            $html
        );
        $this->assertStringNotContainsString('bad-option', $html);
    }

    public function testUnprivilegedUserCannotRenderSettingsForm(): void
    {
        SettingsTest::$allowed = false;

        ob_start();
        Settings::renderPage();
        $this->assertSame('', ob_get_clean());
        $this->assertSame([], SettingsTest::$calls);
    }

    public function testFormUsesCoreNonceAndSelectedPresetAndEscapesTranslatedCopy(): void
    {
        SettingsTest::$saved = 'https://api.aiuxu.com/v1';
        SettingsTest::$translations['Choose the API access domain for this server. '
            . 'Model discovery, text, images and speech use this address.'] = '<script>translated</script>';
        Settings::registerSettings();

        ob_start();
        Settings::renderPage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<form action="options.php" method="post">', $html);
        $this->assertStringContainsString('name="_wpnonce"', $html);
        $this->assertContains(['settings_fields', 'ai_provider_for_apimart'], SettingsTest::$calls);
        $this->assertStringContainsString(
            '<option value="https://api.aiuxu.com/v1" selected="selected">',
            $html
        );
        $this->assertStringContainsString('https://api.aishuch.com/v1', $html);
        $this->assertStringContainsString('options-connectors.php', $html);
        $this->assertStringContainsString('&lt;script&gt;translated&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString(
            '<form action="https://example.test/wp-admin/admin-post.php" method="post">',
            $html
        );
        $this->assertStringContainsString('name="action" value="apimart_refresh_models"', $html);
        $this->assertStringContainsString('test-refresh-nonce', $html);
        $this->assertContains(['wp_nonce_field', 'apimart_refresh_models'], SettingsTest::$calls);
        $this->assertStringContainsString('Refresh model list', $html);
        $this->assertStringContainsString('this does not generate content', $html);
    }

    public function testExternalOverrideDisablesSelectorAndPreservesSavedChoice(): void
    {
        putenv('APIMART_BASE_URL=https://api.aishuch.com/v1');
        SettingsTest::$saved = 'https://api.apib.ai/v1';
        Settings::registerSettings();

        ob_start();
        Settings::renderPage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('APIMART_BASE_URL', $html);
        $this->assertStringContainsString('disabled="disabled"', $html);
        $this->assertStringContainsString(
            '<input type="hidden" name="' . ApimartProvider::BASE_URL_OPTION
            . '" value="https://api.apib.ai/v1">',
            $html
        );
        $this->assertStringNotContainsString('Save Changes', $html);
        $this->assertStringContainsString('Refresh model list', $html);
        $this->assertSame('https://api.apib.ai/v1', SettingsTest::$saved);
    }

    public function testRefreshClearsTheCurrentCatalogWithoutNetworkOrGenerationRequests(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('delete')->with($this->callback(static function (string $key): bool {
            return $key !== '' && strpos($key, 'test-refresh-secret') === false;
        }))->willReturn(true);
        $cache->expects($this->never())->method('get');
        $cache->expects($this->never())->method('set');
        $this->configureRefreshDirectory($cache);

        $this->runRefreshUntilRedirect();

        $this->assertSame([
            ['check_admin_referer', 'apimart_refresh_models'],
            ['wp_safe_redirect', 'https://example.test/wp-admin/options-general.php'
                . '?page=ai-provider-for-apimart&catalog-refreshed=1'],
        ], SettingsTest::$calls);
    }

    public function testRefreshRejectsUnprivilegedUsersBeforeCheckingNonceOrClearingCache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('delete');
        $this->configureRefreshDirectory($cache);
        SettingsTest::$allowed = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You are not allowed to refresh the APIMart model list.');
        Settings::refreshModels();
    }

    public function testRefreshRejectsInvalidNonceBeforeClearingCache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('delete');
        $this->configureRefreshDirectory($cache);
        SettingsTest::$validNonce = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid nonce.');
        Settings::refreshModels();
    }

    public function testRefreshFailureRedirectsToAGenericNoticeWithoutExceptionDetails(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('delete')->willThrowException(
            new RuntimeException('Backend failed for test-refresh-secret')
        );
        $this->configureRefreshDirectory($cache);

        $this->runRefreshUntilRedirect();

        $this->assertSame([
            ['check_admin_referer', 'apimart_refresh_models'],
            ['wp_safe_redirect', 'https://example.test/wp-admin/options-general.php'
                . '?page=ai-provider-for-apimart&catalog-refresh-error=1'],
        ], SettingsTest::$calls);
    }

    public function testRefreshRejectsDirectoriesWithoutCacheInvalidationSupport(): void
    {
        $directory = $this->createMock(ModelMetadataDirectoryInterface::class);
        $directory->expects($this->never())->method('listModelMetadata');
        $directory->expects($this->never())->method('getModelMetadata');
        $directories = $this->originalDirectories;
        $directories[ApimartProvider::class] = $directory;
        $this->directoryCache->setValue(null, $directories);

        $this->runRefreshUntilRedirect();

        $this->assertSame([
            ['check_admin_referer', 'apimart_refresh_models'],
            ['wp_safe_redirect', 'https://example.test/wp-admin/options-general.php'
                . '?page=ai-provider-for-apimart&catalog-refresh-error=1'],
        ], SettingsTest::$calls);
    }

    public function testRefreshNoticesUseFixedEscapedCopyAndExactQueryValues(): void
    {
        $_GET['catalog-refreshed'] = '1';
        SettingsTest::$translations['The cached APIMart model list was cleared. '
            . 'Reopen model selection to load the current list.'] = '<script>notice</script>';

        ob_start();
        Settings::renderPage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('&lt;script&gt;notice&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $_GET = ['catalog-refreshed' => '<script>query</script>', 'catalog-refresh-error' => ['1']];
        ob_start();
        Settings::renderPage();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('notice-success', $html);
        $this->assertStringNotContainsString('notice-error', $html);
        $this->assertStringNotContainsString('query', $html);

        $_GET = ['catalog-refresh-error' => '1'];
        ob_start();
        Settings::renderPage();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('The APIMart model cache could not be cleared.', $html);
    }

    private function configureRefreshDirectory(CacheInterface $cache): void
    {
        $directory = new ApimartModelMetadataDirectory();
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication('test-refresh-secret'));
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->never())->method('send');
        $directory->setHttpTransporter($transporter);
        $directories = $this->originalDirectories;
        $directories[ApimartProvider::class] = $directory;
        $this->directoryCache->setValue(null, $directories);
        AiClient::setCache($cache);
    }

    private function runRefreshUntilRedirect(): void
    {
        try {
            Settings::refreshModels();
            $this->fail('The admin-post handler must redirect and stop execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Redirected.', $exception->getMessage());
        }
    }
}

namespace WordPress\ApimartAiProvider\Admin;

use RuntimeException;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;
use WordPress\ApimartAiProvider\Tests\Admin\SettingsTest;

function add_action(string $hook, callable $callback): void
{
    SettingsTest::$calls[] = ['add_action', $hook, $callback];
}

function add_options_page(string $title, string $menu, string $capability, string $slug, callable $callback): void
{
    SettingsTest::$calls[] = ['add_options_page', $title, $menu, $capability, $slug, $callback];
}

function register_setting(string $group, string $name, array $args): void
{
    SettingsTest::$calls[] = ['register_setting', $group, $name, $args];
}

function add_settings_section(string $id, string $title, string $callback, string $page): void
{
    SettingsTest::$calls[] = ['add_settings_section', $id, $title, $callback, $page];
}

function add_settings_field(
    string $id,
    string $title,
    callable $callback,
    string $page,
    string $section,
    array $args
): void {
    SettingsTest::$calls[] = ['add_settings_field', $id, $title, $callback, $page, $section, $args];
    SettingsTest::$fields[] = $callback;
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && SettingsTest::$allowed;
}

function get_option(string $name, $default = false)
{
    return $name === ApimartProvider::BASE_URL_OPTION ? SettingsTest::$saved : $default;
}

function add_settings_error(string $setting, string $code, string $message): void
{
    SettingsTest::$calls[] = ['add_settings_error', $setting, $code, $message];
}

function __(string $message, string $domain): string
{
    return SettingsTest::$translations[$message] ?? $message;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function admin_url(string $path): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function settings_errors(string $setting): void
{
    SettingsTest::$calls[] = ['settings_errors', $setting];
}

function settings_fields(string $group): void
{
    SettingsTest::$calls[] = ['settings_fields', $group];
    echo '<input type="hidden" name="_wpnonce" value="test-settings-nonce">';
}

function do_settings_sections(string $page): void
{
    foreach (SettingsTest::$fields as $callback) {
        $callback();
    }
}

function wp_nonce_field(string $action): string
{
    SettingsTest::$calls[] = ['wp_nonce_field', $action];
    $html = '<input type="hidden" name="_wpnonce" value="test-refresh-nonce">';
    echo $html;
    return $html;
}

function check_admin_referer(string $action): int
{
    SettingsTest::$calls[] = ['check_admin_referer', $action];
    if (!SettingsTest::$validNonce) {
        throw new RuntimeException('Invalid nonce.');
    }
    return 1;
}

function wp_die(string $message): void
{
    SettingsTest::$calls[] = ['wp_die', $message];
    throw new RuntimeException($message);
}

function wp_safe_redirect(string $location): bool
{
    SettingsTest::$calls[] = ['wp_safe_redirect', $location];
    throw new RuntimeException('Redirected.');
}

function submit_button(string $text = 'Save Changes', string $type = 'primary'): void
{
    echo '<button type="submit" class="' . esc_attr($type) . '">' . esc_html($text) . '</button>';
}
