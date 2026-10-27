<?php

declare(strict_types=1);

namespace WordPress\ApimartAiProvider\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\ApimartAiProvider\Metadata\ApimartModelMetadataDirectory;

class ApimartModelMetadataDirectoryTest extends TestCase
{
    public function testDiscoversMultipleVendorsUsingServerCategories(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'qwen-custom', 'category' => 'chat', 'capability_tags' => ['Text']],
            ['id' => 'claude-custom', 'category' => 'chat', 'capability_tags' => ['Text']],
            ['id' => 'deepseek-custom', 'category' => 'chat'],
            ['id' => 'gpt-4o', 'category' => 'unknown'],
            ['id' => 'gpt-5'],
        ]);

        $models = $directory->listModelMetadata();
        $this->assertSame(['claude-custom', 'deepseek-custom', 'qwen-custom'], array_map(
            static function (ModelMetadata $model): string {
                return $model->getId();
            },
            $models
        ));
        foreach ($models as $model) {
            $this->assertContainsEquals(CapabilityEnum::textGeneration(), $model->getSupportedCapabilities());
            $this->assertContainsEquals(CapabilityEnum::chatHistory(), $model->getSupportedCapabilities());
        }
    }

    public function testExcludesUnsupportedEndpointsAndEmbeddingOnlyEntries(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'embedding', 'category' => 'chat', 'capability_tags' => ['Embedding']],
            ['id' => 'video-model', 'category' => 'video', 'capability_tags' => ['Text to Video']],
            ['id' => 'other-image', 'category' => 'image', 'capability_tags' => ['Text to Image']],
            ['id' => 'gpt-image-3-unverified', 'category' => 'image', 'capability_tags' => ['Text to Image']],
            ['id' => 'other-tts', 'category' => 'audio', 'capability_tags' => ['Audio']],
        ]);

        $this->assertSame([], $directory->listModelMetadata());
    }

    public function testAdvertisesVisionOnlyWhenExplicitlyTagged(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'vision-model', 'category' => 'chat', 'capability_tags' => ['Text', 'Vision']],
            ['id' => 'text-model', 'category' => 'chat', 'capability_tags' => ['Text']],
        ]);

        $this->assertEquals(
            [[ModalityEnum::text()], [ModalityEnum::text(), ModalityEnum::image()]],
            $this->optionValues($directory->getModelMetadata('vision-model'), OptionEnum::inputModalities())
        );
        $textModel = $directory->getModelMetadata('text-model');
        $this->assertEquals(
            [[ModalityEnum::text()]],
            $this->optionValues($textModel, OptionEnum::inputModalities())
        );
        $this->assertSame(['text/plain'], $this->optionValues($textModel, OptionEnum::outputMimeType()));
        foreach ($textModel->getSupportedOptions() as $option) {
            $this->assertFalse($option->getName()->isWebSearch());
        }
    }

    public function testIncompleteCategoriesUseExplicitTagsAndVerifiedTextContracts(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'uncatalogued-text', 'category' => 'unknown', 'capability_tags' => ['Text']],
            ['id' => 'uncatalogued-vision', 'capability_tags' => ['Vision']],
            ['id' => 'gpt-6-luna', 'category' => 'unknown'],
            ['id' => 'unknown-embedding', 'capability_tags' => ['Embedding']],
            ['id' => 'unknown-model'],
            ['id' => 'audio-with-text', 'category' => 'audio', 'capability_tags' => ['Text']],
        ]);

        $this->assertSame(['gpt-6-luna', 'uncatalogued-text', 'uncatalogued-vision'], array_map(
            static function (ModelMetadata $model): string {
                return $model->getId();
            },
            $directory->listModelMetadata()
        ));
        $this->assertEquals(
            [[ModalityEnum::text()]],
            $this->optionValues($directory->getModelMetadata('gpt-6-luna'), OptionEnum::inputModalities())
        );
        $this->assertEquals(
            [[ModalityEnum::text()], [ModalityEnum::text(), ModalityEnum::image()]],
            $this->optionValues($directory->getModelMetadata('uncatalogued-vision'), OptionEnum::inputModalities())
        );
    }

    public function testVerifiedFallbackDoesNotAddModelsOutsideTheKeysCatalog(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'one-permitted-model', 'category' => 'chat'],
        ]);

        $this->assertFalse($directory->hasModelMetadata('gpt-6-luna'));
        $this->assertFalse($directory->hasModelMetadata('gpt-image-2'));
        $this->assertCount(1, $directory->listModelMetadata());
    }

    /**
     * @dataProvider newImageContractProvider
     * @param list<int> $counts The documented candidate counts.
     * @param list<string> $mimeTypes The documented output MIME types.
     */
    public function testNewImageModelsExposeTheirOwnContracts(string $id, array $counts, array $mimeTypes): void
    {
        $directory = $this->directoryForModels([
            ['id' => $id, 'category' => 'unknown', 'capability_tags' => ['Image to Image']],
        ]);
        $model = $directory->getModelMetadata($id);

        $this->assertContainsEquals(CapabilityEnum::imageGeneration(), $model->getSupportedCapabilities());
        $this->assertSame($counts, $this->optionValues($model, OptionEnum::candidateCount()));
        $this->assertSame($mimeTypes, $this->optionValues($model, OptionEnum::outputMimeType()));
        $this->assertContains('16:9', $this->optionValues($model, OptionEnum::outputMediaAspectRatio()));
        $this->assertEquals(
            [[ModalityEnum::text()], [ModalityEnum::text(), ModalityEnum::image()]],
            $this->optionValues($model, OptionEnum::inputModalities())
        );
    }

    /** @return array<string, array{string, list<int>, list<string>}> Verified contracts. */
    public function newImageContractProvider(): array
    {
        return [
            '2' => ['gpt-image-2', [1], ['image/png']],
            '2 ext alias' => ['gpt-image-2-ext', [1], ['image/png']],
            '2 official' => ['gpt-image-2-official', [1, 2, 3, 4], ['image/png', 'image/jpeg', 'image/webp']],
            '2.5 flare' => ['gpt-image-2.5-flare', [1, 2, 3, 4], ['image/png', 'image/jpeg', 'image/webp']],
            '2.5 sunburst' => ['gpt-image-2.5-sunburst', [1, 2, 3, 4], ['image/png', 'image/jpeg', 'image/webp']],
            '2.5 ext' => ['gpt-image-2.5-ext', [1, 2, 3, 4], ['image/png']],
        ];
    }

    public function testImageMetadataMatchesVerifiedAsynchronousContract(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'gpt-image-1-official', 'category' => 'image', 'capability_tags' => ['Text to Image']],
            [
                'id' => 'gpt-image-1.5-official',
                'category' => 'image',
                'capability_tags' => ['Text to Image', 'Image to Image'],
            ],
        ]);

        $model = $directory->getModelMetadata('gpt-image-1.5-official');
        $this->assertContainsEquals(CapabilityEnum::imageGeneration(), $model->getSupportedCapabilities());
        $this->assertSame([1, 2, 3, 4], $this->optionValues($model, OptionEnum::candidateCount()));
        $this->assertSame(['image/png', 'image/jpeg'], $this->optionValues($model, OptionEnum::outputMimeType()));
        $this->assertEquals(
            [FileTypeEnum::remote(), FileTypeEnum::inline()],
            $this->optionValues($model, OptionEnum::outputFileType())
        );
        $this->assertSame(['1:1', '3:2', '2:3'], $this->optionValues($model, OptionEnum::outputMediaAspectRatio()));
        $this->assertEquals(
            [[ModalityEnum::text()], [ModalityEnum::text(), ModalityEnum::image()]],
            $this->optionValues($model, OptionEnum::inputModalities())
        );
        $this->assertEquals(
            [[ModalityEnum::text()]],
            $this->optionValues($directory->getModelMetadata('gpt-image-1-official'), OptionEnum::inputModalities())
        );
    }

    public function testSpeechMetadataUsesDocumentedFormatsAndVoices(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'gpt-4o-mini-tts', 'category' => 'audio', 'capability_tags' => ['Audio']],
        ]);
        $model = $directory->getModelMetadata('gpt-4o-mini-tts');

        $this->assertContainsEquals(CapabilityEnum::textToSpeechConversion(), $model->getSupportedCapabilities());
        $this->assertSame(
            ['audio/wav', 'audio/ogg', 'audio/aac', 'audio/flac', 'audio/pcm'],
            $this->optionValues($model, OptionEnum::outputMimeType())
        );
        $this->assertSame(
            ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'],
            $this->optionValues($model, OptionEnum::outputSpeechVoice())
        );
    }

    public function testExplicitModelLookupFetchesMetadataWithAuthenticationAndCachesTheList(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'vendor/arbitrary-name', 'category' => 'chat', 'capability_tags' => ['Text']],
        ]);

        $this->assertSame('vendor/arbitrary-name', $directory->getModelMetadata('vendor/arbitrary-name')->getId());
        $this->assertTrue($directory->hasModelMetadata('vendor/arbitrary-name'));
        $this->assertCount(1, $directory->listModelMetadata());
    }

    public function testUnknownExplicitModelDoesNotAcquireGuessedCapabilities(): void
    {
        $directory = $this->directoryForModels([
            ['id' => 'gpt-5.5', 'category' => 'unknown'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gpt-5.5');
        $directory->getModelMetadata('gpt-5.5');
    }

    public function testAnEmptyCatalogIsAValidResponse(): void
    {
        $this->assertSame([], $this->directoryForModels([])->listModelMetadata());
    }

    public function testPersistentCatalogCacheIsScopedToCredentials(): void
    {
        $storage = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('has')->willReturnCallback(static function (string $key) use (&$storage): bool {
            return array_key_exists($key, $storage);
        });
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$storage) {
            return $storage[$key] ?? null;
        });
        $cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$storage): bool {
            $storage[$key] = $value;
            return true;
        });

        $previousCache = AiClient::getCache();
        AiClient::setCache($cache);
        try {
            $first = $this->directoryForResponse(new Response(200, [], (string) json_encode([
                'data' => [['id' => 'first-model', 'category' => 'chat']],
            ])), 'first-secret');
            $second = $this->directoryForResponse(new Response(200, [], (string) json_encode([
                'data' => [['id' => 'second-model', 'category' => 'chat']],
            ])), 'second-secret');

            $this->assertSame('first-model', $first->listModelMetadata()[0]->getId());
            $this->assertSame('second-model', $second->listModelMetadata()[0]->getId());
            $this->assertCount(2, $storage);
            foreach (array_keys($storage) as $key) {
                $this->assertStringNotContainsString('first-secret', $key);
                $this->assertStringNotContainsString('second-secret', $key);
            }
        } finally {
            AiClient::setCache($previousCache);
        }
    }

    public function testSwitchingApiDomainsDoesNotReuseThePreviousCatalog(): void
    {
        $storage = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('has')->willReturnCallback(static function (string $key) use (&$storage): bool {
            return array_key_exists($key, $storage);
        });
        $cache->method('get')->willReturnCallback(static function (string $key) use (&$storage) {
            return $storage[$key] ?? null;
        });
        $cache->method('set')->willReturnCallback(static function (string $key, $value) use (&$storage): bool {
            $storage[$key] = $value;
            return true;
        });

        $previousCache = AiClient::getCache();
        $previousEnvironment = getenv('APIMART_BASE_URL');
        AiClient::setCache($cache);
        try {
            putenv('APIMART_BASE_URL=https://api.apimart.ai/v1');
            $first = $this->directoryForResponse(new Response(200, [], (string) json_encode([
                'data' => [['id' => 'first-domain-model', 'category' => 'chat']],
            ])), 'same-secret');
            $this->assertSame('first-domain-model', $first->listModelMetadata()[0]->getId());

            putenv('APIMART_BASE_URL=https://api.apib.ai/v1');
            $second = $this->directoryForResponse(new Response(200, [], (string) json_encode([
                'data' => [['id' => 'second-domain-model', 'category' => 'chat']],
            ])), 'same-secret', 'https://api.apib.ai/v1');
            $this->assertSame('second-domain-model', $second->listModelMetadata()[0]->getId());
            $this->assertCount(2, $storage);
            foreach (array_keys($storage) as $key) {
                $this->assertStringNotContainsString('same-secret', $key);
                $this->assertStringNotContainsString('api.apib.ai', $key);
            }
        } finally {
            AiClient::setCache($previousCache);
            putenv($previousEnvironment === false ? 'APIMART_BASE_URL' : 'APIMART_BASE_URL=' . $previousEnvironment);
        }
    }

    public function testAnUpgradeDoesNotReuseThePreviousAdaptersFilteredCatalog(): void
    {
        $request = (new ApiKeyRequestAuthentication('test-api-key'))->authenticateRequest(
            new Request(
                \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum::GET(),
                'https://api.apimart.ai/v1/models?expand=category'
            )
        );
        $oldKey = 'ai_client_' . AiClient::VERSION . '_' . md5(ApimartModelMetadataDirectory::class)
            . '_' . hash('sha256', serialize([$request->getUri(), $request->getHeaders()])) . '_models';
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('has')->willReturnCallback(static function (string $key) use ($oldKey): bool {
            return $key === $oldKey;
        });
        $cache->expects($this->never())->method('get');
        $cache->expects($this->once())->method('set')->with(
            $this->logicalNot($this->equalTo($oldKey)),
            $this->callback(static function (array $models): bool {
                return isset($models['gpt-image-2'], $models['gpt-6-luna']);
            }),
            86400
        )->willReturn(true);

        $previousCache = AiClient::getCache();
        AiClient::setCache($cache);
        try {
            $directory = $this->directoryForModels([
                ['id' => 'gpt-image-2', 'category' => 'image'],
                ['id' => 'gpt-6-luna', 'category' => 'chat'],
            ]);
            $this->assertCount(2, $directory->listModelMetadata());
        } finally {
            AiClient::setCache($previousCache);
        }
    }

    /**
     * @dataProvider malformedResponseProvider
     */
    public function testRejectsMalformedResponses(string $body): void
    {
        $directory = $this->directoryForResponse(new Response(200, [], $body));

        $this->expectException(ResponseException::class);
        $directory->listModelMetadata();
    }

    /**
     * @return array<string, array{string}> The malformed response fixtures.
     */
    public function malformedResponseProvider(): array
    {
        return [
            'invalid JSON' => ['not-json'],
            'missing data' => ['{}'],
            'non-list data' => ['{"data":{"id":"model"}}'],
            'non-array data' => ['{"data":false}'],
            'non-object entry' => ['{"data":["model"]}'],
            'missing ID' => ['{"data":[{"category":"chat"}]}'],
            'empty ID' => ['{"data":[{"id":""}]}'],
            'invalid category' => ['{"data":[{"id":"model","category":3}]}'],
            'invalid tags' => ['{"data":[{"id":"model","capability_tags":"Text"}]}'],
            'non-string tag' => ['{"data":[{"id":"model","capability_tags":[3]}]}'],
            'failure envelope' => ['{"success":false,"data":[]}'],
            'error envelope' => ['{"error":{"message":"Invalid key"},"data":[]}'],
            'failure code' => ['{"code":401,"data":[]}'],
        ];
    }

    public function testRejectsHttpAuthenticationFailure(): void
    {
        $directory = $this->directoryForResponse(new Response(401, [], '{"error":{"message":"Invalid key"}}'));

        $this->expectException(ClientException::class);
        $directory->listModelMetadata();
    }

    /**
     * @param list<array<string, mixed>> $models The catalog entries.
     * @return ApimartModelMetadataDirectory The configured directory.
     */
    private function directoryForModels(array $models): ApimartModelMetadataDirectory
    {
        return $this->directoryForResponse(new Response(200, [], (string) json_encode([
            'success' => true,
            'object' => 'list',
            'data' => $models,
        ])));
    }

    private function directoryForResponse(
        Response $response,
        string $apiKey = 'test-api-key',
        string $baseUrl = 'https://api.apimart.ai/v1'
    ): ApimartModelMetadataDirectory {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->once())->method('send')->with($this->callback(
            static function (Request $request) use ($apiKey, $baseUrl): bool {
                return $request->getMethod()->isGet() &&
                    $request->getUri() === $baseUrl . '/models?expand=category' &&
                    $request->getHeaderAsString('Authorization') === 'Bearer ' . $apiKey;
            }
        ))->willReturn($response);

        $directory = new ApimartModelMetadataDirectory();
        $directory->setHttpTransporter($transporter);
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication($apiKey));
        return $directory;
    }

    /**
     * @param ModelMetadata $model The model metadata.
     * @param OptionEnum $name The option name.
     * @return list<mixed>|null The supported values.
     */
    private function optionValues(ModelMetadata $model, OptionEnum $name): ?array
    {
        foreach ($model->getSupportedOptions() as $option) {
            if ($option->getName()->value === $name->value) {
                return $option->getSupportedValues();
            }
        }
        $this->fail('Missing supported option: ' . $name->value);
    }
}
