# Contributing

This repository maintains the APIMart adaptation at [ROYIANS/ai-provider-for-apimart](https://github.com/ROYIANS/ai-provider-for-apimart). The original implementation comes from the WordPress AI Team's [OpenAI provider](https://github.com/WordPress/ai-provider-for-openai); retain its license and attribution.

Use PHP 7.4-compatible syntax and PHP AI Client 1.4.0 APIs. Run `composer install`, `composer test`, `composer lint`, and `composer validate --strict`. API tests must use fake HTTP transporters rather than a real key. Add protocol tests for changes to requests, response normalization, model discovery, task state handling or error behavior. When extending support, record the relevant official APIMart contract and its model-specific limits in `docs/compatibility.md`.

An APIMart catalog tag is not enough to establish an endpoint's request/response contract. Do not infer model capabilities solely from OpenAI naming patterns. Changes to the advertised catalog affect SDK model selection and need corresponding tests. Keep local polling settings out of outbound API bodies.

Create a reviewable branch and pull request against this repository. Never commit API keys, generated vendor files, IDE settings, or generated result URLs containing secrets. The `.idea/` directory is ignored locally and excluded from distributions.

For a WordPress package, run `bash scripts/build-plugin.sh`. It stages a copy using the exclusions in `.distignore`, keeps `plugin.php`, `src/`, the license and provider icon, and excludes tests, vendor and development files. The resulting ZIP is in the Git-ignored `dist/` directory and can be uploaded through the WordPress plugin installer. For a Composer archive, `.gitattributes` excludes WordPress bootstrap and development-only files. CI checks both layouts. Example commands and compatibility documentation are retained in Composer source distributions.

WordPress.org deployment is disabled until the repository variable `WPORG_DEPLOY_ENABLED` is set to `true`, this fork has its own `ai-provider-for-apimart` listing, and SVN credentials have been configured. No publication, tag or release is required for local development. The inherited OpenAI marketing images and remote-install Playground blueprint were removed from this fork because they describe a different plugin.
