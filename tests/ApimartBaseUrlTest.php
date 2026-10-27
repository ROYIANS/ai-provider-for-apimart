<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

/**
 * Constants and WordPress function stubs must not leak between configuration cases.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ApimartBaseUrlTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APIMART_BASE_URL');
    }

    public function testDefaultUrlDoesNotRequireWordPress(): void
    {
        $this->assertSame(ApimartProvider::DEFAULT_BASE_URL, ApimartProvider::url());
        $this->assertFalse(ApimartProvider::hasBaseUrlOverride());
    }

    /**
     * @dataProvider presetProvider
     */
    public function testEachDomainPreservesTheApiPrefixAndVersionPath(string $url, string $website): void
    {
        putenv('APIMART_BASE_URL=' . $url);

        $this->assertSame($url . '/models', ApimartProvider::url('models'));
        $this->assertSame($url . '/chat/completions', ApimartProvider::url('/chat/completions'));
        $this->assertSame('https://' . $website . '/keys', ApimartProvider::metadata()->getCredentialsUrl());
        $this->assertTrue(ApimartProvider::hasBaseUrlOverride());
    }

    /**
     * @return array<string, array{string, string}> The supported API and website domain pairs.
     */
    public function presetProvider(): array
    {
        $cases = [];
        foreach (ApimartProvider::baseUrlPresets() as $url => $website) {
            $cases[$website] = [$url, $website];
        }
        return $cases;
    }

    public function testEnvironmentOverridesTheConstantAndWordPressOption(): void
    {
        putenv('APIMART_BASE_URL=https://api.apib.ai/v1');
        define('APIMART_BASE_URL', 'https://api.aiuxu.com/v1');
        $this->stubWordPressOption('https://api.aishuch.com/v1');

        $this->assertSame('https://api.apib.ai/v1', ApimartProvider::url());
    }

    public function testWhitespaceEnvironmentFallsThroughToTheConstant(): void
    {
        putenv('APIMART_BASE_URL=   ');
        define('APIMART_BASE_URL', '  https://api.aiuxu.com/v1///  ');
        $this->stubWordPressOption('https://api.aishuch.com/v1');

        $this->assertSame('https://api.aiuxu.com/v1', ApimartProvider::url());
        $this->assertTrue(ApimartProvider::hasBaseUrlOverride());
    }

    public function testEmptyServerConfigurationAllowsTheWordPressSelection(): void
    {
        putenv('APIMART_BASE_URL=');
        define('APIMART_BASE_URL', '  ');
        $this->stubWordPressOption(' https://api.aishuch.com/v1/ ');

        $this->assertSame('https://api.aishuch.com/v1', ApimartProvider::url());
        $this->assertSame('https://aishuch.com/keys', ApimartProvider::metadata()->getCredentialsUrl());
        $this->assertFalse(ApimartProvider::hasBaseUrlOverride());
    }

    /**
     * @dataProvider staleOptionProvider
     *
     * @param mixed $option The stale WordPress option value.
     */
    public function testStaleOrUnrecognizedWordPressOptionsUseTheDefault($option): void
    {
        $this->stubWordPressOption($option);

        $this->assertSame(ApimartProvider::DEFAULT_BASE_URL, ApimartProvider::url());
    }

    /**
     * @return array<string, array{mixed}> Invalid or unavailable WordPress selections.
     */
    public function staleOptionProvider(): array
    {
        return [
            'unset option' => [false],
            'empty option' => [''],
            'unrecognized host' => ['https://other.example/v1'],
            'insecure option' => ['http://api.apib.ai/v1'],
            'wrong type' => [['https://api.apib.ai/v1']],
        ];
    }

    public function testCustomHttpsProxyRetainsItsPortAndPath(): void
    {
        putenv('APIMART_BASE_URL=  https://proxy.example:8443/apimart/v1///  ');

        $this->assertSame('https://proxy.example:8443/apimart/v1/audio/speech', ApimartProvider::url('audio/speech'));
        $this->assertSame('https://apimart.ai/keys', ApimartProvider::metadata()->getCredentialsUrl());
    }

    /**
     * @dataProvider invalidExplicitUrlProvider
     */
    public function testInvalidExplicitDestinationsAreRejected(string $url): void
    {
        putenv('APIMART_BASE_URL=' . $url);
        $this->stubWordPressOption('https://api.apib.ai/v1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('APIMART_BASE_URL must be an HTTPS URL');
        ApimartProvider::url('models');
    }

    /**
     * @return array<string, array{string}> Invalid explicit API URLs.
     */
    public function invalidExplicitUrlProvider(): array
    {
        return [
            'HTTP scheme' => ['http://api.apib.ai/v1'],
            'unsupported scheme' => ['ftp://api.apib.ai/v1'],
            'missing scheme' => ['api.apib.ai/v1'],
            'missing host' => ['https:///v1'],
            'embedded credentials' => ['https://user:password@api.apib.ai/v1'],
            'embedded username' => ['https://user@api.apib.ai/v1'],
            'query string' => ['https://api.apib.ai/v1?token=example'],
            'empty query' => ['https://api.apib.ai/v1?'],
            'fragment' => ['https://api.apib.ai/v1#example'],
            'empty fragment' => ['https://api.apib.ai/v1#'],
            'malformed URL' => ['not a URL'],
        ];
    }

    public function testAnInvalidConstantIsNotSilentlyReplacedByTheWordPressSelection(): void
    {
        define('APIMART_BASE_URL', 'http://api.apib.ai/v1');
        $this->stubWordPressOption('https://api.aiuxu.com/v1');

        $this->expectException(InvalidArgumentException::class);
        ApimartProvider::url();
    }

    public function testANonStringConstantIsRejected(): void
    {
        define('APIMART_BASE_URL', false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('APIMART_BASE_URL must be an HTTPS URL string.');
        ApimartProvider::url();
    }

    /**
     * @param mixed $value The value returned by WordPress get_option().
     */
    private function stubWordPressOption($value): void
    {
        $GLOBALS['apimart_test_base_url'] = $value;
        eval('namespace { function get_option($name, $default = false) {
            if ($name !== "ai_provider_for_apimart_base_url") {
                throw new \\RuntimeException("Unexpected WordPress option name.");
            }
            return $GLOBALS["apimart_test_base_url"];
        } }');
    }
}
