# Plugin Check 报告处理与复验（0.1.3）

2026-10-27，官方 Plugin Check 2.1.0 对实际 0.1.2 安装包完整复现了用户提供的报告：33 个 ERROR、4 个 WARNING，共 37 项。0.1.3 同时修复实际 WordPress 输出和打包问题，并为共享 SDK 中不属于 HTML 输出的代码注明精确的静态检查例外。

## 37 项的处理

| 报告类别 | 数量 | 0.1.3 处理 |
| --- | ---: | --- |
| `WordPress.WP.I18n.NonSingularStringLiteralText` | 4 ERROR | `plugin.php` 的三个管理员提示与 `Settings.php` 的模型缓存说明改为完整字符串字面量，使翻译工具能提取整个消息 |
| `WordPress.Security.EscapeOutput.OutputNotEscaped` | 1 ERROR | 刷新模型列表的权限错误在传给 `wp_die()` 的 HTML 输出边界使用 `esc_html()` |
| `missing_direct_file_access_protection` | 1 ERROR | WordPress 专用 `src/autoload.php` 检查 `ABSPATH`；未定义时返回，不注册 autoloader |
| `missing_readme_header_tested` | 1 ERROR | 在实际验证 WordPress 7.1.3 后补充 `Tested up to: 7.1` |
| `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | 24 ERROR | SDK 异常是文本数据，在受影响语句添加带理由的精确行级注释；HTML 消费方在渲染时转义 |
| `WordPress.WP.AlternativeFunctions.parse_url_parse_url` | 2 ERROR | 独立 Composer 应用也使用这些 URL 校验方法，保留原生函数并在两处注明精确规则例外 |
| `WordPress.Security.NonceVerification.Recommended` | 4 WARNING | 两个查询标志只选择固定提示，没有状态变更；在对应两行注明精确例外，实际刷新操作继续校验权限与 nonce |

数量按报告中的 token 记录计算。同一异常表达式或查询标志可能产生多条记录，并不等于 37 个独立漏洞。

## 输出边界与例外范围

Provider、Metadata 和 Models 类由 WordPress 插件与独立 Composer 应用共用。它们抛出的异常用于 SDK、JSON 错误或日志，没有直接输出 HTML。提前对错误消息调用 `esc_html()` 会把 `<`、`&` 等文本编码，影响 API 错误的原始内容，也会给独立应用增加 WordPress 函数依赖。因此只对报告中的相关语句添加 `WordPress.Security.EscapeOutput.ExceptionNotEscaped` 行级注释及理由。消费方将错误渲染到 HTML 时仍需转义。

0.1.3 新增两个对应测试：管理员权限错误中的翻译文本 `<script>denied</script>` 会被转义，而 SDK 错误 `Quota <limit> & balance "low"` 保持文本内容。其余 WordPress 页面仍在 HTML、属性和 URL 输出位置使用适合的转义函数。

两处 `parse_url()` 都用于严格的绝对 URL 校验，并结合现有的 URL、协议及组成部分验证。这些方法在没有 WordPress 的 Composer 环境中也需要工作，因此没有改成依赖 WordPress 的 `wp_parse_url()`。例外仅覆盖这两个调用；URL 允许范围没有放宽。

设置页读取的 `catalog-refreshed=1` 和 `catalog-refresh-error=1` 只控制固定的成功或失败提示，不把查询值输出到页面，也不执行缓存清理。实际 `refreshModels()` 仍要求 `manage_options` 权限、有效 nonce，并由受保护的后台操作处理。这里的两条行级注释只对应显示提示时的查询读取。

WordPress 手动 autoloader 的访问保护不影响 Composer PSR-4 加载。独立测试使用自己的测试 autoloader，避免靠定义 `ABSPATH` 让 SDK 测试依赖 WordPress 环境。无 `ABSPATH` 时直接包含 `src/autoload.php` 的烟雾检查也确认没有注册加载器。

没有全局关闭 WPCS、Plugin Check 检查类别或错误等级。插件继续运行全部默认检查，具体例外保留在可审阅的源码行旁。

## 官方工具复验

将同一实际构建的 0.1.3 ZIP 解压安装到两个独立本地站点。使用官方 Plugin Check 2.1.0、WP-CLI 2.7.1 和 PHP 7.4.33，结果如下：

| WordPress | 检查 | 结果 |
| --- | --- | --- |
| 7.0.3 | 默认静态检查 | 0 ERROR、0 WARNING |
| 7.0.3 | 提前加载 CLI bootstrap 的静态及 runtime 检查 | 0 ERROR、0 WARNING |
| 7.1.3 | 默认静态检查 | 0 ERROR、0 WARNING |
| 7.1.3 | 提前加载 CLI bootstrap 的静态及 runtime 检查 | 0 ERROR、0 WARNING |

四次输出均为 `Success: Checks complete. No errors found.`，stderr 为空。runtime 检查产生的临时 `object-cache.php` 已自动清理。没有启用需要外部 AI 的额外检查。

本地安装了 AI 1.4.0。Plugin Check 的 CLI 命令使用 `--skip-plugins=ai`，避开其无关的 WP-CLI 激活 hook 类型错误；这只用于检查命令。后续完整集成回归正常加载 AI 插件。

以下命令中的 `WP_TEST_ROOT` 是包含 `wordpress/` 安装目录的本地测试目录。先安装并激活 Plugin Check 2.1.0 和实际插件包，再执行：

```sh
docker run --rm --user 0 -v "$WP_TEST_ROOT:/work" -w /work/wordpress \
  --entrypoint wp wordpress:cli-php7.4 --allow-root --skip-plugins=ai \
  plugin check ai-provider-for-apimart --format=strict-json

docker run --rm --user 0 -v "$WP_TEST_ROOT:/work" -w /work/wordpress \
  --entrypoint wp wordpress:cli-php7.4 --allow-root --skip-plugins=ai \
  --require=./wp-content/plugins/plugin-check/cli.php \
  plugin check ai-provider-for-apimart --format=strict-json
```

无发现时，即使选择 `--format=strict-json`，该版本工具也会输出上述成功消息，而不是空 JSON 数组。

## 集成回归与 CI

受测安装包在 WordPress 7.0.3 / 7.1.3 × PHP 7.4.33 / 8.5.12 四组环境中完成 Connector 与实际 `ai/image-generation` Ability 回归，使用 AI 1.4.0 和 PHP AI Client 1.3.1。Provider 已注册且活跃，REST 模型列表含 `gpt-6-luna` 和 8 个图片模型 ID。选中 `gpt-image-2.5-flare` 后，实际 Ability 完成任务流程并返回 68 字节 inline PNG。

模型目录、异步提交、任务轮询和 CDN 下载由本地 `pre_http_request` fixture 提供固定响应，外部生成请求为零。该回归验证 WordPress 集成和请求响应流程，没有验证真实账户模型列表或真实服务的生成质量。

`.github/workflows/plugin-check.yml` 在 `main`、`codex/**`、pull request 和手动触发时执行。它先用 `.distignore` 暂存发行内容并断言测试、vendor、Git 和开发配置没有进入包，再使用固定提交的官方 `WordPress/plugin-check-action`。环境固定为 WordPress 7.1.3、PHP 7.4 和 Plugin Check 2.1.0。action 提前加载 Plugin Check CLI bootstrap、激活目标插件，运行全部默认静态及 runtime 检查；`strict: true` 使警告同样阻断 CI。

本次修复使用新的 0.1.3 版本，不移动既有 `v0.1.2` 标签。工具检查通过不代表 WordPress.org 人工审核通过。自动化测试、安装包范围及仍未验证的线上行为见 [validation.md](validation.md)。
