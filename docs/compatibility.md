# APIMart 兼容性与实现说明

本次适配基于上游 `WordPress/ai-provider-for-openai` 提交 `24ba3bee14674fcf6f48e615481fb35e480880e9`。协议按 2026-10-27 查阅的 APIMart 官方文档实现；测试使用模拟 HTTP 响应，没有真实 API Key 验证。没有把文档示例视为线上实测。

## 身份和运行条件

Composer 包名为 `royians/ai-provider-for-apimart`，命名空间为 `WordPress\ApimartAiProvider`，Provider ID 为 `apimart`。默认基址为 `https://api.apimart.ai/v1`。WordPress 插件和 Composer 两种用法均保留；Composer 正式依赖 `wordpress/php-ai-client:^1.3.1`，PHP 最低 7.4。插件在缺少 SDK 或 SDK 低于 1.3.1 或达到 2.0 时跳过注册，并向有插件管理权限的管理员显示原因。

0.1.1 提供“设置 → APIMart”域名选择，预设 `api.apimart.ai`、`api.apib.ai`、`api.aiuxu.com`、`api.aishuch.com`，路径均为 `/v1`。备用域名依据站点所有者提供的信息及确认加入；未携带 Key 的 HTTPS 探测返回同类 APIMart 401 认证错误，未验证真实生成。`APIMART_BASE_URL` 环境变量优先于同名常量，再优先于 WordPress 保存值；显式配置仅接受合法 HTTPS 基址，拒绝 URL 内凭据、查询和 fragment。模型目录、文本、图片提交与任务轮询、TTS 共享基址。目录缓存同时按接口地址和认证摘要隔离；域名切换为手动操作，生成请求不会因错误被自动跨域重放。

密钥变量和常量名均为 `APIMART_API_KEY`，SDK 优先读取环境变量。所有模型列表、生成和任务查询请求经过 Bearer 鉴权。图标使用用户提供的 `assets/images/apimart.svg`；WordPress 通过插件目录常量保留符号链接路径。

## WordPress 连接页面兼容性修复（0.1.1）

0.1.0 将最低 SDK 误设为 1.4.0，导致内置 SDK 1.3.1 的 WordPress 7.0–7.1 在插件已启用时跳过 Provider 注册，APIMart 不出现在“设置 → 连接”。WordPress AI 插件的版本号与 PHP AI Client SDK 版本不同，不能用前者判断 SDK 是否兼容。

已核对 SDK 1.3.1 的文本、图像、TTS 和 TokenUsage 合同，当前实现没有依赖 1.4 新增的 Embedding API。0.1.1 支持 SDK 1.3.1，继续在 init 优先级 5 注册；WordPress 在 init 优先级 15 自动从同一默认 Registry 发现 Provider，因此不需要额外手工注册 Connector，也不需要先填写 API Key 才显示卡片。

## 模型目录

[模型列表文档](https://docs.apimart.ai/en/api-reference/texts/models/list.md) 提供 `category` 和 `capability_tags`；实现请求 `expand=category`，不再按 OpenAI ID 前缀推断多厂商模型。

- `chat` 映射文本和对话历史；仅 Embedding 的条目排除。0.1.2 也接受 `unknown` 分类但明确带有 `Text` / `Vision` 标签的文本条目。
- `Vision` 标签允许文本加图片输入；其他 Audio/Omni 标签不会自动声明音频输入。
- 图片仅接受下文列出的已核实 GPT Image ID，分类可为 `image` 或 `unknown`。图片编辑输入还需要 `Image to Image` 标签。
- `audio` 仅接受 `gpt-4o-mini-tts`。
- 未能识别的 `unknown`、视频、其他图像/音频、Embedding 不进入本版本目录。

官方文档说明 `unknown` 表示平台尚未补齐目录分类，模型仍可调用。`gpt-6-luna` 已由官方 [GPT 页面](https://apimart.ai/zh/model/llm/gpt)及[公开定价接口](https://apib.ai/api/pricing/model?model=gpt-6-luna)核实为文本模型；0.1.2 对这个准确 ID 提供文本兜底。兜底仍要求它出现在当前 Key 的认证目录中，不会向目录追加 ID，也不会凭名称补造 Vision 能力。公开目录不能证明用户 Key 的实际权限或返回的分类。

严格的 `$registry->getProviderModel()` 查询要求 ID 存在于当前 Key 返回的可支持目录；SDK 的 `usingModelPreference()` 只是偏好，可能自动回退到其他模型。SDK 1.5 的“跳过列表查询”快捷路径未启用。目录缓存键包含认证信息的 SHA-256 哈希，避免权限不同的 Key 共享目录；缓存键不写入原始密钥。

SDK 将解析后的模型元数据缓存 86400 秒。0.1.2 的缓存键加入元数据 schema 版本，升级后不会继续使用旧版仅保留两个图片模型的解析结果。“设置 → APIMart → Refresh model list”通过权限与 nonce 检查后清除当前接口、当前认证上下文的目录缓存；再次打开模型选择时重新读取，不发起内容生成。平台本身的目录更新仍可能有数分钟延迟。

WordPress AI 1.4 的逐功能选模入口是“设置 → AI → 右上角工具图标 → Developer Tools → Model selection”。启用后，各个已开启的功能显示 Provider 与 Model，选择后需要保存。Provider 负责提供模型和能力；具体功能配置由使用 PHP AI Client 的应用负责。

文本目录仅声明通用文档核实的基本参数。工具和 JSON Schema 的底层转换保留并有 mock 测试，但列表没有聊天模型逐项参数合同，所以普通 PromptBuilder 的 `usingFunctionDeclarations()` 和 `asJson()` / `asOutputSchema()` 无法通过本目录的能力匹配。已自行核实的应用可直接配置模型与自有元数据，或通过 customOptions 传递协议参数。不能因此宣称所有模型均支持这些能力。

## 文本

采用 [通用 Chat Completions](https://docs.apimart.ai/en/api-reference/texts/general/chat-completions.md) 与[非流式接口](https://docs.apimart.ai/en/api-reference/texts/general/chat-completions-nostream.md)，显式发送 `stream=false`，路径为 `/v1/chat/completions`。非流式页面个别示例包含额外 `/api`，与通用接口不一致；实现选择通用文档的 `/v1`。

复用 SDK 的 OpenAI-compatible 基类，规范化标准响应和 `{code:200,data:{choices:...,usage:...}}` 包裹响应。纯文本消息使用字符串；Vision 保留标准 image_url 内容数组。映射工具声明、工具调用与工具响应、多轮历史、JSON Schema 包装、finish reason、usage 和 reasoning_content。`length` 保留为 SDK 的 token-limit finish reason；不将截断结果当成完整成功停止。HTTP 错误、业务错误和畸形响应抛出异常。

不采用 [Responses 页面](https://docs.apimart.ai/en/api-reference/texts/openai/responses.md)：它列出的模型范围有限，示例的 choices/usage 结构与标准 Responses output 不一致。没有把不确定的 `/responses` 行为推广到全部模型。流式、原生 web search 和音频输入不在本次对外支持范围。

## 图像

依据 [GPT Image 1](https://docs.apimart.ai/en/api-reference/images/gpt-image-1/generation.md)、[GPT Image 2 / 2 Ext](https://docs.apimart.ai/en/api-reference/images/gpt-image-2/generation.md)、[GPT Image 2 Official](https://docs.apimart.ai/en/api-reference/images/gpt-image-2/official.md)、[GPT Image 2.5](https://docs.apimart.ai/en/api-reference/images/gpt-image-2.5/generation.md)、[GPT Image 2.5 Ext](https://docs.apimart.ai/en/api-reference/images/gpt-image-2.5-ext/generation.md)及[任务状态文档](https://docs.apimart.ai/en/api-reference/tasks/status.md)。生成与编辑均以 JSON 提交 `/v1/images/generations`，解析 `data[].task_id` 或 2.5 Ext 的 HTTP 202 单任务对象，再查询 `/v1/tasks/{id}`。编辑传 `image_urls` / `mask_url`，不使用 OpenAI multipart `/images/edits`。

| 目录中的准确 ID | 数量 | 输出格式 | 参考图上限 | mask_url |
| --- | --- | --- | --- | --- |
| `gpt-image-1-official`、`gpt-image-1.5-official` | 1–4 | PNG、JPEG | 15 | 支持 |
| `gpt-image-2`、`gpt-image-2-ext` | 1 | PNG | 15 | 不支持 |
| `gpt-image-2-official` | 1–4 | PNG、JPEG、WebP | 16 | 支持 |
| `gpt-image-2.5-flare`、`gpt-image-2.5-sunburst` | 1–4 | PNG、JPEG、WebP | 16 | 支持 |
| `gpt-image-2.5-ext` | 1–4 | PNG | 16 | 不支持 |

2.5 系列在官方目录中使用 Flare / Sunburst / Ext ID，不额外创建未经核实的 `gpt-image-2.5` 别名。2 / 2 Ext 参数文档的 `n` 上限为 1，而示例中出现 2；实现按参数说明限制为 1。

1 / 1.5 支持 `1:1`、`2:3`、`3:2`。2 / 2 Ext / 2 Official / 2.5 Flare 与 Sunburst 支持 15 种文档比例；2.5 Ext 支持 10 种。新系列还支持 `size=auto` 与 1K / 2K / 4K 分辨率；配置会按各接口要求规范化分辨率大小写。2.5 Ext 的 `version` 可为 `flare` / `sunburst`。目录展示与请求校验共用逐模型配置，避免列表宣称可选但请求无法实现。

参考图与掩码均要求公开 HTTP(S) URL，不支持本地或 inline 上传。掩码要求同时提供参考图；透明背景与 JPEG 冲突会在发送前拒绝。

默认输出为 SDK remote 文件；显式请求 `FileTypeEnum::inline()` 时，0.1.2 会下载已完成任务的图片 URL 并构造含 base64 数据的 SDK File，下载请求不携带 APIMart 认证头。下载共用生成的截止时间，不跟随重定向；要求 Content-Type 与请求的 PNG / JPEG / WebP 格式一致，内容非空且不超过 32 MiB。SDK transporter 先缓冲响应体再检查大小，该限制不是流式内存上限。普通 remote 输出不会触发下载。两种输出均保留完整 `additionalData.tasks` 与 `additionalData.task_ids`；remote 文件应在任务返回的 `result.images[*].expires_at` 前保存，不假设固定 URL 有效期。

WordPress AI 1.4 的 `Generate_Image` 固定请求 inline 输出并读取 base64，而 SDK 不会自动把 APIMart 任务的远程 URL 转为 inline File。上述转换补齐了内置图片生成的输出合同。内置图片编辑则会发送 base64 参考图，仍不满足本适配器的公开 URL 输入要求；因此不能把目录中的 `Image to Image` 能力解读为 WordPress AI 1.4 内置编辑已经兼容。通过应用传入公开参考图 URL 的通用编辑路径继续支持。

同步 SDK 调用会阻塞轮询。customOptions 的 `apimart_poll_timeout` 默认为 120 秒（范围 0.1–600），`apimart_poll_interval` 默认为 2 秒（范围 0.1–60）；这两个参数不会发送给 APIMart。提交和所有任务共享一个截止时间，单次 HTTP timeout 取剩余时间与调用方配置的较小值。有限请求预算避免异常时钟下无限轮询。SDK 1.3.1 仅在 Guzzle 或实现 ClientWithOptionsInterface 的客户端上应用 RequestOptions；普通 PSR-18 客户端会忽略这些选项，单次请求可能超出轮询期限。使用 README 中的 Guzzle 配置，或由集成环境提供能执行请求超时的 transporter。

处理 submitted/pending/processing/completed/failed/cancelled。未知状态、无任务 ID、错误业务码、无结果、非法 URL 均报错。轮询超时包含任务 ID，并说明远程任务可能继续执行；没有远程取消或自动重新提交，以免重复付费。

## 语音

依据 [TTS 文档](https://docs.apimart.ai/en/api-reference/audios/tts.md)。`POST /v1/audio/speech` 返回二进制音频，转换为 SDK inline File。默认 WAV；MIME 对应为 `audio/wav → wav`、`audio/ogg → opus`、`audio/aac → aac`、`audio/flac → flac`、`audio/pcm → pcm`。没有继续使用上游默认 MP3，因为 APIMart 页面未列出 MP3。

仅接入 `gpt-4o-mini-tts`，六种声音为 alloy/echo/fable/onyx/nova/shimmer。输入只接受有效 UTF-8 文本，最多 4096 个字符；speed 范围 0.25–4.0。空响应、JSON 错误和 text/* 响应不会被伪装为音频。图像和语音文档未给出 token usage，因此返回零值，不用于计算实际费用。

## 后续核实范围

本版本未接入 Embedding：官方目录有能力标签，但索引未提供独立 Embedding 接口合同。这不等于断言 APIMart 不支持该能力。新增其他图片、音频或视频模型前，需核实相应请求参数、异步结果和 SDK 能力表示方式。

没有真实 Key 时已完成全部本地实现和 mock 验证。上线前需用测试 Key 验证至少一个可访问聊天模型、一个图像任务（含编辑）和 TTS，确认真实返回与上述文档一致，并记录账户权限、费用和任务耗时。具体已执行检查记录在交付说明中。
