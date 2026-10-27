<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\Admin;

use PHPUnit\Framework\TestCase;
use WordPress\ApimartAiProvider\Admin\Settings;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * Namespaced WordPress doubles exercise forms and sanitization without real API traffic.
 */
class SettingsTest extends TestCase
{
    public static bool $allowed = true;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBaseUrl = getenv('APIMART_BASE_URL');
        putenv('APIMART_BASE_URL');
        SettingsTest::$allowed = true;
        SettingsTest::$saved = ApimartProvider::DEFAULT_BASE_URL;
        SettingsTest::$calls = [];
        SettingsTest::$fields = [];
        SettingsTest::$translations = [];
    }

    protected function tearDown(): void
    {
        putenv($this->originalBaseUrl === false ? 'APIMART_BASE_URL' : 'APIMART_BASE_URL=' . $this->originalBaseUrl);
        parent::tearDown();
    }

    public function testRegistersAdminHooksOnlyOnce(): void
    {
        Settings::registerHooks();
        Settings::registerHooks();

        $this->assertSame([
            ['add_action', 'admin_menu', [Settings::class, 'addPage']],
            ['add_action', 'admin_init', [Settings::class, 'registerSettings']],
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
        $this->assertStringNotContainsString('type="submit"', $html);
        $this->assertSame('https://api.apib.ai/v1', SettingsTest::$saved);
    }
}

namespace WordPress\ApimartAiProvider\Admin;

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

function submit_button(): void
{
    echo '<button type="submit">Save Changes</button>';
}
