<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

class ApimartProviderTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testProviderLogoUsesWordPressPluginDirectory(): void
    {
        $pluginDir = '/wordpress/wp-content/plugins/ai-provider-for-apimart';
        define('AI_PROVIDER_FOR_APIMART_PLUGIN_DIR', $pluginDir);

        $metadata = ApimartProvider::metadata();

        $this->assertSame(
            $pluginDir . '/assets/images/apimart.svg',
            $metadata->getLogoPath()
        );
    }
}
