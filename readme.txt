=== AI Provider for APIMart ===
Contributors:      royians
Tags:              ai, apimart, artificial-intelligence, connector
Requires at least: 6.9
Stable tag:        0.1.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

APIMart text, image and speech integration for the PHP AI Client SDK.

== Description ==

This independent fork of WordPress/ai-provider-for-openai registers APIMart as a separate provider for the WordPress PHP AI Client. It requires PHP AI Client 1.4.0 or later within major version 1.

* Non-streaming Chat Completions for models categorized as chat by APIMart.
* Image input for chat models explicitly tagged Vision.
* Asynchronous image generation and public-URL image editing with gpt-image-1-official and gpt-image-1.5-official.
* Binary text-to-speech with gpt-4o-mini-tts, WAV by default.
* Model discovery scoped to your API key, with separate authentication-aware catalog caches.
* APIMart provider logo and automatic registration at init priority 5.

Only models returned by your API key and supported by this adapter appear in the catalog. Video, embeddings, unknown models, other image/audio models, streaming, and provider-native web search are not exposed. Tools and JSON Schema mappings exist in the lower-level adapter, but are not advertised as universal catalog capabilities.

The image call polls synchronously for up to 120 seconds by default. Download generated images before the individual expires_at timestamps returned in task metadata. Reference images and masks require public URLs; inline image uploads are not implemented. A local timeout does not cancel the remote task.

This fork has automated mock-response coverage. Real API requests have not been verified with an APIMart key. Consult README.md and docs/compatibility.md in the source repository for the exact scope and setup examples.

== Installation ==

1. Ensure your WordPress environment loads PHP AI Client 1.4.0 or later within major version 1. The WordPress version alone does not establish SDK compatibility.
2. Copy the plugin files to /wp-content/plugins/ai-provider-for-apimart/.
3. Configure APIMART_API_KEY as an environment variable or constant.
4. Activate the plugin. Registration safely returns if the compatible SDK is unavailable.
5. Select APIMart in an application or plugin that uses the PHP AI Client.

== Frequently Asked Questions ==

= Where do I get a key? =

Use the [APIMart API Key Management Page](https://apimart.ai/keys).

= Is this an official WordPress, OpenAI or APIMart plugin? =

This is an independent fork maintained at https://github.com/ROYIANS/ai-provider-for-apimart. Upstream work remains credited to the WordPress AI Team under GPL-2.0-or-later.

= Does this work without PHP AI Client? =

No. This plugin provides an adapter for PHP AI Client and does not install or load a duplicate SDK in WordPress.

== Changelog ==

= 0.1.0 =

* Initial APIMart adaptation based on WordPress/ai-provider-for-openai.
* Separate provider, package, plugin identity and user-supplied APIMart logo.
* Chat Completions adapter, category-based model discovery, bounded asynchronous image polling, and documented TTS formats.
* Mock protocol tests and fork CI. WordPress.org deployment requires explicit repository configuration.

== Upgrade Notice ==

= 0.1.0 =

Independent APIMart fork. Uses APIMART_API_KEY and requires PHP AI Client 1.4.0+. No prior OpenAI provider configuration is migrated automatically.
