=== AI Provider for APIMart ===
Contributors:      royians
Tags:              ai, apimart, artificial-intelligence, connector
Requires at least: 6.9
Stable tag:        0.1.2
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

APIMart text, image and speech integration for the PHP AI Client SDK.

== Description ==

This independent fork of WordPress/ai-provider-for-openai registers APIMart as a separate provider for the WordPress PHP AI Client. It requires PHP AI Client 1.3.1 or later within major version 1.

* Non-streaming Chat Completions for APIMart chat models, including uncategorized entries with explicit Text/Vision tags and a verified gpt-6-luna text fallback.
* Image input for chat models explicitly tagged Vision.
* Asynchronous image generation with verified GPT Image 1, 1.5, 2 and 2.5 model IDs, remote or inline output, and public-URL editing when advertised by the catalog.
* Binary text-to-speech with gpt-4o-mini-tts, WAV by default.
* Model discovery scoped to your API key, with separate endpoint- and authentication-aware catalog caches.
* APIMart provider logo and automatic registration at init priority 5.
* Select apimart.ai, apib.ai, aiuxu.com or aishuch.com under Settings > APIMart, or configure APIMART_BASE_URL on the server.
* Refresh the cached model list under Settings > APIMart after your model permissions change.

Only models returned by your API key and supported by this adapter appear in the catalog. Verified fallbacks help classify incomplete catalog entries; they do not append models absent from the key's list or infer Vision from a name. Video, embeddings, unrecognized models, other image/audio models, streaming, and provider-native web search are not exposed. Tools and JSON Schema mappings exist in the lower-level adapter, but are not advertised as universal catalog capabilities.

Supported image IDs are gpt-image-1-official, gpt-image-1.5-official, gpt-image-2, gpt-image-2-ext, gpt-image-2-official, gpt-image-2.5-flare, gpt-image-2.5-sunburst and gpt-image-2.5-ext. Formats, image counts, aspect ratios and editing options follow each model's documented limits. APIMart's 2.5 family uses Flare, Sunburst and Ext names; a separate gpt-image-2.5 alias is not added.

The image call polls synchronously for up to 120 seconds by default. Remote output is the default; when inline output is requested, completed image URLs are downloaded without APIMart authentication headers and returned as base64-backed SDK files. Downloads share the generation deadline, do not follow redirects, and require a matching image Content-Type and a nonempty body of at most 32 MiB. Save remote images before the individual expires_at timestamps returned in task metadata. Reference images and masks require public URLs; inline image uploads are not implemented. A local timeout does not cancel the remote task.

WordPress AI 1.4 image generation requires inline output and is supported by this conversion. Its built-in image editing sends inline reference images and is not compatible with this adapter's public-URL input requirement. Public-URL editing remains available to applications that provide remote reference files.

This fork has automated mock-response coverage. Real API requests have not been verified with an APIMart key. Consult README.md and docs/compatibility.md in the source repository for the exact scope and setup examples.

== Installation ==

1. Ensure your WordPress environment loads PHP AI Client 1.3.1 or later within major version 1. The WordPress version alone does not establish SDK compatibility.
2. Copy the plugin files to /wp-content/plugins/ai-provider-for-apimart/.
3. On WordPress 7.0+, configure the key under Settings > Connectors > APIMart after activation. APIMART_API_KEY as an environment variable or constant is also supported.
4. Activate the plugin. WordPress 7.0–7.1 bundle supported SDK 1.3.1. If a compatible SDK is unavailable, an administrator notice explains why APIMart could not be registered.
5. If needed, choose an alternate API domain under Settings > APIMart. APIMART_BASE_URL environment/constant overrides the selected domain.
6. Select APIMart in an application or plugin that uses the PHP AI Client.

== Frequently Asked Questions ==

= Where do I get a key? =

Use the [APIMart API Key Management Page](https://apimart.ai/keys).

= How do I use an alternate API domain? =

Open Settings > APIMart and choose apib.ai, aiuxu.com or aishuch.com. The API address keeps the api. prefix and /v1 path, for example https://api.apib.ai/v1. Alternatively define APIMART_BASE_URL in wp-config.php or the environment. The selected endpoint applies to model discovery, text, image tasks and speech. Switching is manual and generation requests are not automatically replayed on other domains.

= Where can I choose a model for each feature? =

In WordPress AI 1.4, open Settings > AI, use the top-right tools icon, then Developer Tools > Model selection. Enable it to reveal the provider and model controls for each enabled feature. Choose APIMart and a model, then save. Other applications expose their own model controls.

= Why is a model missing? =

The list is limited by the APIMart API key's permissions and group, the adapter's supported contracts, and the feature's required capabilities. The SDK may cache parsed models for 24 hours. Use Settings > APIMart > Refresh model list, then reopen model selection. Version 0.1.2 also changes the cache namespace to discard older filtered lists after upgrading.

= Is this an official WordPress, OpenAI or APIMart plugin? =

This is an independent fork maintained at https://github.com/ROYIANS/ai-provider-for-apimart. Upstream work remains credited to the WordPress AI Team under GPL-2.0-or-later.

= Does this work without PHP AI Client? =

No. This plugin provides an adapter for PHP AI Client and does not install or load a duplicate SDK in WordPress.

== Changelog ==

= 0.1.2 =

* Support GPT Image 2, 2 Ext, 2 Official, 2.5 Flare, 2.5 Sunburst and 2.5 Ext with their individual parameter contracts and asynchronous task responses.
* Convert completed image URLs to inline SDK files when requested, matching WordPress AI 1.4 image generation. Built-in WordPress AI editing still requires unsupported inline reference-image input.
* Recognize explicit Text/Vision metadata on uncategorized models, and provide a verified text fallback for gpt-6-luna when returned by the key's catalog.
* Allow verified image IDs with incomplete category metadata without adding models absent from the key's catalog.
* Invalidate older parsed model caches after upgrading, and add a nonce- and permission-protected Refresh model list button under Settings > APIMart.

= 0.1.1 =

* Fix APIMart missing from Settings > Connectors on WordPress installations using bundled PHP AI Client 1.3.1.
* Support SDK 1.3.1 and later within major version 1; the previous 1.4.0 minimum was unnecessarily restrictive.
* Display an administrator notice when the SDK is missing or incompatible instead of silently skipping provider registration.
* Add Settings > APIMart with four API domain presets, plus APIMART_BASE_URL environment/constant configuration.
* Use the selected endpoint for all API calls and separate model caches by endpoint and credentials.

= 0.1.0 =

* Initial APIMart adaptation based on WordPress/ai-provider-for-openai.
* Separate provider, package, plugin identity and user-supplied APIMart logo.
* Chat Completions adapter, category-based model discovery, bounded asynchronous image polling, and documented TTS formats.
* Mock protocol tests and fork CI. WordPress.org deployment requires explicit repository configuration.

== Upgrade Notice ==

= 0.1.2 =

Adds verified GPT Image 2/2.5 families, improves incomplete model metadata handling and reloads the cached model list. Upload this ZIP to replace the previous plugin. Model availability still follows your API key's catalog.

= 0.1.1 =

Fixes the missing APIMart connector on WordPress 7.0–7.1 with bundled PHP AI Client 1.3.1 and adds alternate API domain settings. Upload this ZIP to replace version 0.1.0.

= 0.1.0 =

Independent APIMart fork. Uses APIMART_API_KEY and originally required PHP AI Client 1.4.0+. No prior OpenAI provider configuration is migrated automatically.
