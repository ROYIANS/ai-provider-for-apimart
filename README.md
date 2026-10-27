# AI Provider for APIMart

APIMart integration for the [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client), usable as a Composer package or WordPress plugin. This independent fork is based on [WordPress/ai-provider-for-openai](https://github.com/WordPress/ai-provider-for-openai). It registers a separate `apimart` provider and uses `https://api.apimart.ai/v1`.

The implementation follows APIMart's documented contracts and has automated tests with fake HTTP responses. Live requests with an APIMart API key have not been verified. See [兼容性与实现说明](docs/compatibility.md) for the exact scope and protocol decisions.

| Capability | Available through this adapter |
| --- | --- |
| Text and chat history | Models categorized as `chat`; embedding-only entries are excluded |
| Image input for chat | Only when the catalog includes the `Vision` tag |
| Image generation and editing | `gpt-image-1-official`, `gpt-image-1.5-official`, when categorized as `image` |
| Text to speech | `gpt-4o-mini-tts`, when categorized as `audio` |
| Embeddings, video, other image/audio models | Not exposed by this version |

The model list comes from `GET /v1/models?expand=category` and depends on your API key's permissions. Unknown categories and unsupported models are omitted. Explicit model IDs still resolve through that catalog, including on SDK versions with an explicit-ID shortcut; arbitrary IDs do not acquire guessed capabilities. Catalog caches are separated by authentication credentials.

## Requirements and installation

- PHP **7.4+**.
- `wordpress/php-ai-client` **1.4.0+ within major version 1**.
- An [APIMart API key](https://apimart.ai/keys) with access to the selected models.
- For standalone Composer usage, a working PSR-18 HTTP client, for example `guzzlehttp/guzzle`.

This repository is an unreleased fork; no Packagist or WordPress.org publication is assumed. For a local Composer application, add this checkout as a path repository:

```sh
composer config repositories.apimart path /absolute/path/to/ai-provider-for-apimart
composer require 'royians/ai-provider-for-apimart:@dev' 'guzzlehttp/guzzle:^7.0'
```

Composer installs the PHP AI Client dependency. Register the provider in your application before making requests:

```php
use GuzzleHttp\Client;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\HttpTransporter;
use WordPress\ApimartAiProvider\Provider\ApimartProvider;

require __DIR__ . '/vendor/autoload.php';

$registry = AiClient::defaultRegistry();
$registry->setHttpTransporter(new HttpTransporter(new Client(['timeout' => 30.0])));
$registry->registerProvider(ApimartProvider::class);
```

For WordPress, copy the checkout into `wp-content/plugins/ai-provider-for-apimart/`, ensure a compatible PHP AI Client is loaded by your WordPress environment, then activate the plugin. It registers at `init` priority 5 and safely returns when the SDK is missing or older than 1.4.0. WordPress 6.9+ is the plugin's declared minimum; the WordPress version alone does not establish the SDK version. The plugin's manual autoloader loads this provider only; it does not load a second SDK into WordPress.

Set `APIMART_API_KEY` as an environment variable, or define the constant in WordPress before the provider is registered:

```php
define('APIMART_API_KEY', 'your-api-key');
```

The SDK reads the environment variable first, then the constant. Requests use `Authorization: Bearer ...`. Existing OpenAI credentials are independent of this provider.

## Text

Choose a model ID visible to your key. The examples use strict `getProviderModel()` lookup, so an unknown ID raises an error; the SDK's alternative `usingModelPreference()` may fall back to another model. Plain text and chat history use non-streaming Chat Completions; the adapter always sends `stream=false`.

```php
$text = AiClient::prompt('Explain how WordPress hooks work.')
    ->usingModel($registry->getProviderModel('apimart', 'your-chat-model-id'))
    ->generateText();
```

Standard sampling and token-limit options are passed to APIMart; individual models can impose their own restrictions. Vision input uses the SDK's file/message parts and requires the catalog's `Vision` tag. Text responses retain token usage, finish reasons, tool calls, and `reasoning_content` when present.

The lower-level model maps OpenAI-compatible tools and JSON Schema, but the catalog does **not** declare these options for all chat models: APIMart's catalog does not provide their per-model parameter contracts. Consequently, `usingFunctionDeclarations()` and `asJson()` / `asOutputSchema()` are not advertised by normal model selection in this version. Applications that have separately verified a model's contract can use a directly configured model with their own metadata, or pass model-specific protocol parameters through `ModelConfig::setCustomOptions()`. Web search and streaming are not exposed.

## Images

```php
use WordPress\AiClient\Files\Enums\FileTypeEnum;

$result = AiClient::prompt('A small lighthouse in a watercolor landscape.')
    ->usingModel($registry->getProviderModel('apimart', 'gpt-image-1-official'))
    ->asOutputMediaAspectRatio('3:2')
    ->asOutputMimeType('image/png')
    ->asOutputFileType(FileTypeEnum::remote())
    ->generateImageResult();

$imageUrl = $result->toImageFile()->getUrl();
$tasks = $result->getAdditionalData()['tasks'];
```

Image generation submits JSON to `/v1/images/generations`, then polls `/v1/tasks/{id}`. The SDK call blocks until completion or its bounded polling deadline. Defaults: 120 seconds total and a 2-second polling interval. Configure `apimart_poll_timeout` (0.1–600 seconds) and `apimart_poll_interval` (0.1–60 seconds) through custom options; these local settings are removed before the API request. Each HTTP request receives a timeout bounded by the remaining deadline and the caller's request timeout. This requires a transporter/client that honors SDK request options: SDK 1.4 supports Guzzle and clients implementing `ClientWithOptionsInterface`. A plain PSR-18 client can ignore these options and block beyond the polling deadline; configure its own timeout or use the Guzzle setup above. WordPress integrations must likewise provide an option-aware transporter.

Both image models support 1–4 images, PNG/JPEG, and `1:1`, `2:3`, `3:2`. Image editing uses the same endpoint and passes remote input files as `image_urls`; it requires the catalog's `Image to Image` tag. Reference images (maximum 15) and optional `mask_url` must be publicly reachable HTTP(S) URLs. Local/inline file uploads are not implemented. Other documented image parameters, such as `quality`, `background`, and `mask_url`, can be supplied through custom options.

Images are returned as remote SDK files. Save them before the provider-supplied `expires_at` value in `additionalData.tasks[*].result.images[*]`; no universal expiration period is assumed. Full task metadata and `task_ids` are retained. Failed and cancelled tasks raise errors. A client timeout does not cancel the remote task; the error includes its ID so the application can query its status before deciding whether to resubmit.

## Speech

```php
$result = AiClient::prompt('Welcome to our website.')
    ->usingModel($registry->getProviderModel('apimart', 'gpt-4o-mini-tts'))
    ->asOutputSpeechVoice('alloy')
    ->asOutputMimeType('audio/wav')
    ->convertTextToSpeechResult();

$audio = $result->toAudioFile();
file_put_contents('speech.wav', base64_decode($audio->getBase64Data(), true));
```

Speech returns an inline file from the binary `/v1/audio/speech` response. WAV is the default; Opus (`audio/ogg`), AAC, FLAC and raw PCM are also supported. MP3 is not advertised by the APIMart documentation. Available voices: `alloy`, `echo`, `fable`, `onyx`, `nova`, `shimmer`. Input must be UTF-8 text of at most 4096 characters. Set the optional `speed` (0.25–4.0) through custom options. Image and speech APIs do not report token usage in the documented responses; the SDK token counts are zero, not a billing estimate.

Runnable standalone examples are in [examples/](examples/). They perform real, potentially billable requests only when you run them with a configured key. They have not been run against APIMart as part of local verification.

## Development

Build an installable WordPress ZIP with `bash scripts/build-plugin.sh` (requires `rsync`, `zip`, `unzip` and `shasum`). The output is `dist/ai-provider-for-apimart-0.1.0.zip`, with one `ai-provider-for-apimart/` top-level directory. Upload that ZIP via **Plugins → Add New → Upload Plugin**. It contains the provider and manual autoloader; the compatible PHP AI Client must already be loaded by your WordPress environment. Generated ZIPs are ignored by Git and excluded from subsequent builds.

```sh
composer install
composer test
composer lint
composer validate --strict
```

PHPUnit uses fake transporters and does not require WordPress, a database or an API key. CI runs on this fork for `trunk`, `codex/**`, pull requests and manual dispatch, with lowest/latest dependency matrices on PHP 7.4–8.5. WordPress.org deployment uses the APIMart slug and stays disabled unless `WPORG_DEPLOY_ENABLED=true` is configured for this repository after its own listing and secrets are ready.

See [local verification results](docs/validation.md) and [CONTRIBUTING.md](CONTRIBUTING.md) for distribution checks. Upstream code remains attributed to the WordPress AI Team; this fork and the user-supplied APIMart icon do not imply endorsement by WordPress, OpenAI or APIMart. Licensed under [GPL-2.0-or-later](LICENSE).
