# AI Provider for APIMart

APIMart integration for the [WordPress PHP AI Client](https://github.com/WordPress/php-ai-client), usable as a Composer package or WordPress plugin. This independent fork is based on [WordPress/ai-provider-for-openai](https://github.com/WordPress/ai-provider-for-openai). It registers a separate `apimart` provider and defaults to `https://api.apimart.ai/v1`.

The implementation follows APIMart's documented contracts and has automated tests with fake HTTP responses. Live requests with an APIMart API key have not been verified. See [兼容性与实现说明](docs/compatibility.md) for the exact scope and protocol decisions.

| Capability | Available through this adapter |
| --- | --- |
| Text and chat history | Models categorized as `chat`, plus uncategorized entries with explicit text/vision metadata and the verified `gpt-6-luna` fallback; embedding-only entries are excluded |
| Image input for chat | Only when the catalog includes the `Vision` tag |
| Image generation and public-URL editing | Verified GPT Image 1, 1.5, 2 and 2.5 IDs listed below, when categorized as `image` or `unknown`; remote or inline output |
| Text to speech | `gpt-4o-mini-tts`, when categorized as `audio` |
| Embeddings, video, other image/audio models | Not exposed by this version |

The model list comes from `GET /v1/models?expand=category` and depends on your API key's permissions and group. APIMart documents that an `unknown` category can still be callable. This adapter recognizes explicit `Text` or `Vision` tags on uncategorized entries, and provides a text-only fallback for `gpt-6-luna` based on APIMart's verified public model metadata. Supported image IDs also have verified contracts when their category is `unknown`. These fallbacks apply only to IDs returned by your authenticated catalog; they never add models that your key cannot see. Other uncategorized or unsupported entries are omitted. Explicit model IDs still resolve through that catalog, including on SDK versions with an explicit-ID shortcut; arbitrary IDs do not acquire guessed capabilities.

Catalog caches are separated by API endpoint, authentication credentials and the adapter's metadata schema. The SDK caches parsed models for up to 24 hours. After changing model permissions, use **Settings → APIMart → Refresh model list**, then reopen the application's model selector. Upgrading to 0.1.2 uses a new cache namespace so the older two-image-model list is not reused.

## Requirements and installation

- PHP **7.4+**.
- `wordpress/php-ai-client` **1.3.1+ within major version 1**.
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

For WordPress, copy the checkout into `wp-content/plugins/ai-provider-for-apimart/`, ensure a compatible PHP AI Client is loaded by your WordPress environment, then activate the plugin. It registers at `init` priority 5 and safely returns when the SDK is missing or older than 1.3.1. WordPress 6.9+ is the plugin's declared minimum. WordPress 7.0–7.1 bundle SDK 1.3.1, which is supported. The WordPress AI plugin version is separate from the PHP AI Client SDK version. If the SDK is missing or incompatible, the plugin displays an administrator notice explaining the problem. The plugin's manual autoloader loads this provider only; it does not load a second SDK into WordPress.

On WordPress 7.0+, enter the API key under **Settings → Connectors → APIMart → Set up**. Alternatively, set `APIMART_API_KEY` as an environment variable, or define the constant in WordPress before the provider is registered:

```php
define('APIMART_API_KEY', 'your-api-key');
```

The SDK reads the environment variable first, then the constant. Requests use `Authorization: Bearer ...`. Existing OpenAI credentials are independent of this provider.

With the WordPress **AI 1.4** plugin, enable **Settings → AI → top-right tools icon → Developer Tools → Model selection** to show the provider and model controls for each enabled feature. Select APIMart and a model for the feature, then save. The model selector belongs to the consuming application; this connector supplies its available models and capabilities.

## API domain and network access

Use **Settings → APIMart** to choose the API domain your WordPress server can reach. The selector includes the default address and the alternate domains supplied by the site owner:

| Domain | API base URL |
| --- | --- |
| `apimart.ai` (default) | `https://api.apimart.ai/v1` |
| `apib.ai` | `https://api.apib.ai/v1` |
| `aiuxu.com` | `https://api.aiuxu.com/v1` |
| `aishuch.com` | `https://api.aishuch.com/v1` |

Model discovery, chat, image submission and task polling, and speech all use the chosen endpoint. Switching the endpoint also separates its model catalog cache. Endpoint selection is manual; the adapter does not automatically replay generation requests against another domain after a timeout.

For server configuration or standalone Composer usage, set the `APIMART_BASE_URL` environment variable, or add this constant in `wp-config.php` before WordPress loads plugins:

```php
define('APIMART_BASE_URL', 'https://api.apib.ai/v1');
```

Precedence: nonempty environment variable → nonempty constant → WordPress setting → default. Configuration overrides are shown on the settings page. Environment/constant configuration also accepts a trusted HTTPS proxy base URL; credentials, query strings, and fragments inside the URL are rejected. An invalid explicit override raises an error for direct Composer usage; the WordPress bootstrap skips APIMart and displays an administrator notice while keeping WordPress available. Trailing slashes are normalized. The preset addresses returned APIMart authentication errors in checks without a key; successful authenticated generation has not been verified on these domains.

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

Image generation submits JSON to `/v1/images/generations`, then polls `/v1/tasks/{id}`. The SDK call blocks until completion or its bounded polling deadline. Defaults: 120 seconds total and a 2-second polling interval. Configure `apimart_poll_timeout` (0.1–600 seconds) and `apimart_poll_interval` (0.1–60 seconds) through custom options; these local settings are removed before the API request. Each HTTP request receives a timeout bounded by the remaining deadline and the caller's request timeout. This requires a transporter/client that honors SDK request options: SDK 1.3.1 supports Guzzle and clients implementing `ClientWithOptionsInterface`. A plain PSR-18 client can ignore these options and block beyond the polling deadline; configure its own timeout or use the Guzzle setup above. WordPress integrations must likewise provide an option-aware transporter.

Image capabilities follow each model's documented contract:

| Exact catalog IDs | Images per request | Output | Reference limit | Mask |
| --- | --- | --- | --- | --- |
| `gpt-image-1-official`, `gpt-image-1.5-official` | 1–4 | PNG, JPEG | 15 | Yes |
| `gpt-image-2`, `gpt-image-2-ext` | 1 | PNG | 15 | No |
| `gpt-image-2-official` | 1–4 | PNG, JPEG, WebP | 16 | Yes |
| `gpt-image-2.5-flare`, `gpt-image-2.5-sunburst` | 1–4 | PNG, JPEG, WebP | 16 | Yes |
| `gpt-image-2.5-ext` | 1–4 | PNG | 16 | No |

APIMart exposes the 2.5 family as Flare, Sunburst and Ext IDs; this adapter does not invent a separate `gpt-image-2.5` alias. The original 1/1.5 models support `1:1`, `2:3`, `3:2`. The 2/2-official/2.5 Flare and Sunburst models support 15 documented ratios; 2.5 Ext supports 10. Newer families also accept `size=auto` and 1K/2K/4K resolution through custom options, with the casing required by their API. The model picker and request validation share the same per-model configuration.

Image editing uses the same endpoint and passes remote input files as `image_urls`; it requires the catalog's `Image to Image` tag. Reference images and optional `mask_url` must be publicly reachable HTTP(S) URLs. Local/inline file uploads are not implemented. Supply documented model-specific parameters such as `resolution`, `quality`, `background`, `mask_url` or the 2.5 Ext `version` through custom options; unsupported settings are rejected before submission.

The default output is a remote SDK file, as in the example above. For `FileTypeEnum::inline()`, the adapter downloads each completed result URL without APIMart authentication headers and returns a base64-backed SDK file. Downloads share the generation deadline, do not follow redirects, and require a nonempty image of at most 32 MiB with a matching PNG/JPEG/WebP Content-Type. The SDK transporter buffers the body before the size check.

Inline output supports WordPress AI 1.4 image generation, which requests inline files and reads base64 data. Its built-in image editing sends inline reference images and remains incompatible with this adapter's public-URL input requirement. Applications that supply public reference URLs can use the adapter's image editing support.

Save remote outputs before the provider-supplied `expires_at` value in `additionalData.tasks[*].result.images[*]`; no universal expiration period is assumed. Full task metadata and `task_ids` are retained for both output types. Failed and cancelled tasks raise errors. A client timeout does not cancel the remote task; the error includes its ID so the application can query its status before deciding whether to resubmit.

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

Build an installable WordPress ZIP with `bash scripts/build-plugin.sh` (requires `rsync`, `zip`, `unzip` and `shasum`). The output is `dist/ai-provider-for-apimart-0.1.2.zip`, with one `ai-provider-for-apimart/` top-level directory. Upload that ZIP via **Plugins → Add New → Upload Plugin**. It contains the provider and manual autoloader; the compatible PHP AI Client must already be loaded by your WordPress environment. Generated ZIPs are ignored by Git and excluded from subsequent builds.

```sh
composer install
composer test
composer lint
composer validate --strict
```

PHPUnit uses fake transporters and does not require WordPress, a database or an API key. CI runs on this fork for `trunk`, `codex/**`, pull requests and manual dispatch, with lowest/latest dependency matrices on PHP 7.4–8.5. WordPress.org deployment uses the APIMart slug and stays disabled unless `WPORG_DEPLOY_ENABLED=true` is configured for this repository after its own listing and secrets are ready.

See [local verification results](docs/validation.md) and [CONTRIBUTING.md](CONTRIBUTING.md) for distribution checks. Upstream code remains attributed to the WordPress AI Team; this fork and the user-supplied APIMart icon do not imply endorsement by WordPress, OpenAI or APIMart. Licensed under [GPL-2.0-or-later](LICENSE).
