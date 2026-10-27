# 本地验证记录

日期：2026-10-27（Asia/Shanghai）。适配分支：`codex/apimart-provider`。验证不需要 WordPress、数据库或真实 API Key。

本机没有原生 PHP/Composer，使用 Docker 隔离执行；未安装全局 PHP，也未使用 pnpm。主工作区 Composer lock 中 SDK 保持 1.4.0。最低依赖与最新 SDK 的更新均在 `/tmp` 独立副本执行，没有用最新依赖覆盖主工作区。

| 环境 | SDK | PHPUnit | 结果 |
| --- | --- | --- | --- |
| PHP 7.4.33，主 lock | 1.4.0 | 9.6.35 | 152 tests / 566 assertions，通过 |
| PHP 8.5.12，主 lock | 1.4.0 | 9.6.35 | 152 tests / 566 assertions，通过 |
| PHP 8.5.12，最新可解析 SDK | 1.5.0 | 9.6.35 | 152 tests / 566 assertions，通过 |
| PHP 7.4.33，`--prefer-lowest --prefer-stable` | 1.4.0 | 9.6.33 | 152 tests / 566 assertions，通过 |

主 lock、SDK 1.5.0 和最低依赖环境均通过 PHPCS 和 PHPStan level max。PHPCS 检查 17 个源码、插件及测试文件；4 个独立示例文件额外通过 PHPCS。`composer validate --strict` 通过，`git diff --check` 通过。

最低依赖解析中的主要版本为 PHPStan 2.1.0、PHP_CodeSniffer 4.0.2、Slevomat 8.23.0、PHPCompatibility dev-develop (`4b1ac83`)、Nyholm PSR-7 1.8.0、PHP HTTP Discovery 1.0.0。最新 SDK 为 1.5.0 (`77a59957763d550a9c92025a15bf565048b977b5`)。

上游锁定的 PHPCS 4.0.1 与 PHPCSUtils 1.2.2 含已公布安全公告。本次锁更新为 PHPCS 4.0.4、PHPCSUtils 1.2.3，并更新它们的安装器与兼容性规则。PHPCS 允许范围收紧到 `^3.13.6 || ^4.0.2`。更新后 `composer audit --locked` 未发现漏洞公告。

关键复现命令（在仓库根目录，将 `PWD` 替换为实际 checkout 路径亦可）：

```sh
docker run --rm -e COMPOSER_ROOT_VERSION=dev-codex/apimart-provider \
  -v "$PWD:/app" -w /app composer:2 test

docker run --rm -e COMPOSER_ROOT_VERSION=dev-codex/apimart-provider \
  -v "$PWD:/app" -w /app composer:2 lint

docker run --rm --entrypoint php \
  -v "$PWD:/app" -w /app wordpress:cli-php7.4 vendor/bin/phpunit

docker run --rm -e COMPOSER_ROOT_VERSION=dev-codex/apimart-provider \
  -v "$PWD:/app" -w /app composer:2 validate --strict
```

## 覆盖内容

- Provider 身份、基址、图标路径、模型工厂和环境变量 Bearer 认证。
- 模型分类、Vision 输入、已核实 image/TTS 名单、未知与不支持模型、严格显式 ID 查询、权限相关缓存隔离。
- Chat 请求转换、非流式参数、标准与包裹响应、工具续轮、legacy 调用 ID、参数对象校验、推理内容、token usage、finish reason 与错误。
- 图像生成和编辑参数、任务提交、多任务、有界轮询、HTTP timeout 参数、失败/取消/超时/未知状态、畸形返回、远程结果和 expires_at。
- TTS 格式/声音、UTF-8 与 4096 字符边界、speed、二进制返回与 JSON/HTML/HTTP 错误。
- WordPress 启动的无 ABSPATH、缺少 SDK、不兼容 SDK、重复注册、init 优先级、symlink 路径和目录常量。
- 使用真实 SDK Registry、模型目录和 PromptBuilder 的文本/图像/语音三条完整链路；仅 HTTP 边界使用 fake transporter。

## 发行包

初次检查时改动尚未提交，使用独立临时 Git 副本构造工作区 tree，再以 `git archive` 检查 Composer 包；该检查没有修改真实 index。Composer 包有 18 个文件，WordPress 包有 12 个文件。Composer 包包含 Provider、模型、图标、许可证、兼容性文档和示例，排除 WordPress bootstrap、手动 autoloader、测试、IDE 与 CI 文件。

WordPress 包通过 `rsync --exclude-from=.distignore` 暂存验证，包含 `plugin.php`、`src/autoload.php`、Provider/模型和 APIMart 图标，排除 vendor、tests、IDE 设置、PHPUnit 缓存、开发文档和示例。可通过 `bash scripts/build-plugin.sh` 重建带有 `ai-provider-for-apimart/` 顶层目录的可安装 ZIP，产物位于 `dist/`，不进入 Git 或后续打包内容。构建脚本会检查 ZIP 完整性并打印 SHA-256。

## 尚未验证

未执行真实 APIMart 请求，未运行完整 WordPress 后台，也未在本地逐一运行 CI 定义的 PHP 8.0–8.4 全矩阵。测试确认适配和 SDK 协议转换的本地行为；无法据此确认账户权限、线上服务返回、音频编码实际质量、图像编辑效果或计费。

图片轮询期限能否限制单次网络等待，取决于 transporter 执行 RequestOptions 的能力。真实服务验收前应使用 README 中的 Guzzle 配置或等价的选项感知 transporter。工具、JSON Schema、Embedding 和其他未声明能力的边界见 [compatibility.md](compatibility.md)。
