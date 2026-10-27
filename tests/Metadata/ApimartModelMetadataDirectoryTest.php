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
            ['id' => 'gpt-image-2', 'category' => 'image', 'capability_tags' => ['Text to Image']],
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
        $this->assertEquals([FileTypeEnum::remote()], $this->optionValues($model, OptionEnum::outputFileType()));
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
        string $apiKey = 'test-api-key'
    ): ApimartModelMetadataDirectory {
        $transporter = $this->createMock(HttpTransporterInterface::class);
        $transporter->expects($this->once())->method('send')->with($this->callback(
            static function (Request $request) use ($apiKey): bool {
                return $request->getMethod()->isGet() &&
                    $request->getUri() === 'https://api.apimart.ai/v1/models?expand=category' &&
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
