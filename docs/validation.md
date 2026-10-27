# 本地验证记录

日期：2026-10-27（Asia/Shanghai）。适配分支：`codex/apimart-provider`。当前版本：0.1.2。自动化测试不需要 WordPress、数据库或真实 API Key；另行运行了本地完整 WordPress 后台回归。

本机没有原生 PHP/Composer，使用 Docker 隔离执行；未安装全局 PHP，也未使用 pnpm。主工作区 Composer lock 中 SDK 为 1.3.1，与 WordPress 7.0.3 内置版本一致。最低依赖与最新 SDK 的更新均在 `/tmp` 独立副本执行，没有用最新依赖覆盖主工作区。

| 环境 | SDK | PHPUnit | 结果 |
| --- | --- | --- | --- |
| PHP 7.4.33，主 lock | 1.3.1 | 9.6.35 | 271 tests / 1159 assertions，通过 |
| PHP 8.5.12，主 lock | 1.3.1 | 9.6.35 | 271 tests / 1159 assertions，通过 |
| PHP 8.5.12，最新可解析 SDK | 1.5.0 | 9.6.35 | 271 tests / 1159 assertions，通过 |
| PHP 7.4.33，`--prefer-lowest --prefer-stable` | 1.3.1 | 9.6.33 | 271 tests / 1159 assertions，通过 |

主 lock、SDK 1.5.0 和最低依赖环境均通过 PHPCS 和 PHPStan level max。PHPCS 检查 21 个源码、插件及测试文件；0.1.0 的 4 个独立示例文件额外通过 PHPCS。`composer validate --strict` 通过，`git diff --check` 通过。

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
- SDK 1.3.1 注册下界、管理员依赖提示、权限与 HTML 转义；无效显式 API 地址跳过 APIMart 注册并显示提示，WordPress 继续启动。
- 四个 API 域名的完整文本、图片任务和语音链路；环境变量/常量/WordPress 设置优先级、HTTPS 地址校验、尾斜线规范化、凭据页面与目录缓存隔离。
- 后台 Settings API 注册、预设允许列表、非法输入、核心 nonce 表单、权限、输出转义，以及环境配置覆盖时保留已有选择。
- 使用真实 SDK Registry、模型目录和 PromptBuilder 的文本/图像/语音三条完整链路；仅 HTTP 边界使用 fake transporter。

## WordPress 后台回归与域名检查

0.1.0 把 SDK 最低版本误设为 1.4.0；WordPress 7.0–7.1 内置 SDK 为 1.3.1，插件启用后会静默跳过注册。0.1.1 将 Composer 约束和运行时门槛统一为 `^1.3.1`，并为缺少或不兼容 SDK 增加管理员提示。

使用官方 WordPress 7.0.3 发行包、内置 PHP AI Client 1.3.1、官方 SQLite Database Integration 1.8 和独立本地数据库搭建回归站点。将实际构建的 0.1.1 ZIP 解压安装并激活，验证：

- `wp_get_connector('apimart')` 存在，Provider 活跃，图标 URL 正确，核心自动注册 `connectors_ai_apimart_api_key` 设置。
- 浏览器中的“设置 → 连接”实际显示 APIMart 卡片和 Set up 按钮。
- “设置 → APIMart”提供四个完整 API 地址。浏览器选择 `apib.ai` 并保存后显示 Settings saved，数据库值为 `https://api.apib.ai/v1`，Provider 的文本 URL 为 `https://api.apib.ai/v1/chat/completions`。
- 显式配置无效 HTTP 地址后，完整 WordPress 仍成功启动，APIMart 未注册，管理员可看到 `APIMART_BASE_URL is invalid` 提示。

本地测试站不使用真实 Key。对 `api.apimart.ai`、`api.apib.ai`、`api.aiuxu.com`、`api.aishuch.com` 的 `/v1/models` 进行无凭据 HTTPS 探测，均返回 HTTP 401、JSON `apimart_error`。备用地址来自站点所有者提供的信息及确认；该检查只证明地址连通和认证错误响应，未证明真实生成成功。

## 模型目录与 WordPress AI 1.4 回归（0.1.2）

- 核对官方 Models List Metadata 文档：`unknown` 分类仍可调用，认证目录由 Key 权限和分组决定。增加未知分类下的明确 Text/Vision 标签识别；`gpt-6-luna` 按官方公开目录的 text 分类兜底，仍要求该 ID 实际出现在认证目录中，不补造 Vision 或追加账户不可见模型。
- 在原有两个图片模型外增加 `gpt-image-2`、`gpt-image-2-ext`、`gpt-image-2-official`、`gpt-image-2.5-flare`、`gpt-image-2.5-sunburst`、`gpt-image-2.5-ext`。目录能力和请求校验共用配置；测试覆盖各模型的比例、分辨率、格式、数量、参考图与 mask 限制，以及 HTTP 202 的新版任务响应。
- 升级模型缓存 schema 命名，验证不会命中 0.1.1 的已过滤目录；后台新增权限与 nonce 保护的刷新按钮，验证只清当前缓存、不触发生成和网络请求。
- 官方 AI 1.4 的图片 Ability 固定要求 inline 输出。新增按需下载结果 URL 转 base64 的兼容：独立 GET 不带 APIMart 认证，禁重定向、共享截止时间，校验 MIME、空响应和 32 MiB 上限。默认 remote 输出保留原有行为。大小上限在 SDK 缓冲后检查，不能当作流式内存上限。
- 将实际 0.1.2 ZIP 安装至上述本地 WordPress，另装官方 AI 1.4.0。测试专用 MU 插件在 WordPress HTTP 边界提供认证目录、图片任务和图片文件的固定响应，不使用真实 Key、不请求实际生成。
- 实际 `Models_Controller` 返回 `gpt-6-luna` 和全部 8 个图片 ID；浏览器成功选择并保存 `gpt-image-2.5-flare` 与 Title Generation 的 `gpt-6-luna`；刷新缓存按钮显示成功。
- 执行真实注册的 `wp_get_ability('ai/image-generation')->execute(...)`，确认选中 Flare、通过能力筛选、完成提交与轮询、无认证下载，并得到 68 字节的 inline PNG。外部计费生成请求为零。此项证明 WordPress 集成路径，不代表真实账户模型列表或线上生成质量。
- WordPress 内置编辑发送 base64 参考图，而当前适配器输入仍要求公开 URL，因此内置编辑功能仍不兼容。通用 SDK 的公开 URL 编辑路径保持支持。

## 发行包

0.1.0 初次检查使用独立临时 Git 副本构造工作区 tree，再以 `git archive` 检查 Composer 包；该检查没有修改真实 index，当时 Composer 包有 18 个文件，WordPress 包有 12 个文件。0.1.1 新增后台设置类，WordPress ZIP 有 13 个文件；0.1.2 新增共享图片能力配置，ZIP 为 14 个文件。Composer 包保留 Provider、模型、图标、许可证、兼容性文档和示例，排除 WordPress bootstrap、手动 autoloader、测试、IDE 与 CI 文件。

WordPress 包通过 `rsync --exclude-from=.distignore` 暂存验证，包含 `plugin.php`、`src/autoload.php`、Provider/模型、后台设置类和 APIMart 图标，排除 vendor、tests、IDE 设置、PHPUnit 缓存、开发文档、静态分析 stubs 和示例。可通过 `bash scripts/build-plugin.sh` 重建带有 `ai-provider-for-apimart/` 顶层目录的可安装 ZIP，产物位于 `dist/`，不进入 Git 或后续打包内容。构建脚本会检查 ZIP 完整性并打印 SHA-256。

## 尚未验证

未执行带真实 Key 的 APIMart 请求，未访问用户的生产 WordPress 后台，也未在本地逐一运行 CI 定义的 PHP 8.0–8.4 全矩阵。测试确认适配、SDK 协议转换以及本地 WordPress 注册、设置、模型选择和模拟图片生成 Ability 行为；无法据此确认账户权限、线上生成返回、音频编码实际质量、图像编辑效果或计费。

图片轮询期限能否限制单次网络等待，取决于 transporter 执行 RequestOptions 的能力。真实服务验收前应使用 README 中的 Guzzle 配置或等价的选项感知 transporter。工具、JSON Schema、Embedding 和其他未声明能力的边界见 [compatibility.md](compatibility.md)。
