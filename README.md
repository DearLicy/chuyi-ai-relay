# 初一 AI 中转

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-1.0.1-3858E9)](https://github.com/DearLicy/chuyi-ai-relay/releases)

初一 AI 中转是一个 WordPress 插件，用来把自定义 AI 中转站接入 WordPress AI Client / Connectors。

插件负责中转站地址、协议类型、模型池、模型能力、连通状态和模型测试；API Key 仍由 WordPress Connectors 管理。这样官方 AI 插件、主题或其它插件可以继续沿用 Connectors 的授权流程，同时把多个中转站集中在一个后台里维护。

- GitHub 仓库：[DearLicy/chuyi-ai-relay](https://github.com/DearLicy/chuyi-ai-relay)
- 当前版本：`1.0.1`

## 适合谁使用

适合已经在 WordPress 中使用官方 AI 插件、AI Client 或 Connectors，并且希望接入自建或第三方 AI 中转站的站点。

典型场景：

- 有多个 OpenAI-compatible 中转站，需要统一管理。
- 同时使用 OpenAI-compatible 与 Anthropic Messages 风格接口。
- 希望把不同中转暴露成独立 Connector provider。
- 希望在后台直接获取模型、标记模型能力、测试延迟和测试模型。
- 希望官方 AI 插件继续使用 Connectors 体系，但实际请求走自己的中转。

## 核心功能

### 多中转管理

- 支持添加多个中转站。
- 支持单独启用或停用中转。
- 支持调整中转顺序。
- 每个中转拥有稳定标识。
- 每个启用中转会注册为独立 Connector provider。
- 每个中转独立维护模型池、模型能力和运行状态。
- 速度测试结果会写回对应中转卡片，刷新后仍可查看。

### 多协议接入

当前支持两种协议模式：

```text
OpenAI Compatible
Anthropic Messages
```

OpenAI-compatible 中转建议支持：

```text
GET  /v1/models
POST /v1/chat/completions
POST /v1/images/generations
```

Anthropic Messages 中转建议支持：

```text
GET  /v1/models
POST /v1/messages
```

### 模型池和能力声明

每个中转都可以维护自己的模型列表。每个模型可以声明以下能力：

- 文本
- 视觉
- 生图

能力声明会影响模型暴露方式：

- `文本`：暴露给文本生成能力。
- `视觉`：作为支持图片输入的文本模型使用。
- `生图`：暴露给图片生成能力。
- Anthropic Messages 模式不暴露生图能力。

多数中转站的 `/models` 响应只返回模型 ID，不返回完整能力。插件会按模型名称做默认推断，但正式使用前建议手动确认。

### 模型获取、延迟和测试

后台支持：

- 一键获取模型。
- 中转连通性测试。
- 最近延迟记录。
- 最近测试时间记录。
- 文本模型测试。
- 图片模型测试。
- 图片测试结果展示 Markdown 图片、URL、`data:image/...` 和 base64 图片。
- 测试结果被限制在结果卡片内，避免撑破后台布局。

### 图片生成策略

`1.0.1` 的图片生成顺序是：

```text
/v1/images/generations → /v1/chat/completions
```

也就是：

- 优先使用标准图片生成接口。
- 标准图片接口不可用或失败时，再回退聊天接口兼容模式。
- 实际能否生成成功，取决于中转站、模型和响应格式。

### 官方 AI 插件兼容

插件会配合官方 AI 插件的 Connector Approval 机制：

- 自动注册中转 provider。
- 自动允许官方 `ai/ai.php` 调用本插件注册的中转 connector。
- 官方 AI 插件生成封面图时，提示词生成和实际生图都可以继续走本插件中转。

如果其它插件或主题也要调用 AI 能力，请到：

```text
设置 → Connectors → Connector Approvals
```

确认对应调用方已获准使用相关 connector。

### 后台体验和中文化

- 插件后台为中文界面。
- 插件后台首页为 `使用说明`。
- 顶部页面切换不刷新整页。
- 每次切换页面都会重新读取配置并清空临时状态。
- 后台样式尽量贴近官方 `ai-wp-admin` 风格。
- 运行时补充官方 AI 插件后台中文翻译。

## 版本差异

### v1.0.0 实现了什么

`v1.0.0` 是首个公开版本，重点是把一个 OpenAI-compatible 中转站接入 WordPress AI Client / Connectors。

远程 `v1.0.0` Release 说明为：

```text
支持单中转站接入，存在少部分已知Bug，但插件轻量
```

`v1.0.0` 的主要能力：

- 插件名为 `初一中转`。
- 注册单个 `初一中转` Provider。
- 支持一个 OpenAI-compatible 中转站。
- 支持自定义接口地址。
- API Key 由 Connectors 保存。
- 支持从 `/v1/models` 获取模型。
- 支持手动标记文本、视觉、生图能力。
- 支持后台文本测试和图像测试。
- 生图通过 `/chat/completions` 兼容模式处理。
- 后台设置页较轻量。

### v1.0.1 更新了什么

`v1.0.1` 是功能扩展和体验修复版本。

主要变化：

- 插件名称调整为 `初一 AI 中转`。
- 英文显示名调整为 `Chuyi AI Relay`。
- 从单中转升级为多中转管理。
- 每个启用中转独立注册 Connector provider。
- 新增 OpenAI-compatible / Anthropic Messages 协议选择。
- 新增中转运行状态、最近延迟和最近测试时间。
- 速度测试结果写回中转卡片并持久保存。
- 优化模型测试页。
- 图片测试支持 Markdown 图片语法、尖括号 URL、标题参数、`data:image/...` 和 base64 图片。
- 图片生成改为优先走 `/v1/images/generations`，失败后再回退 `/v1/chat/completions`。
- 增加官方 AI 插件后台中文化补充。
- 自动兼容官方 `ai/ai.php` 调用本插件 connector 的审批需求。
- 管理页切换改为不刷新整页，但每次切换都会重新读取数据。
- 新增 `使用说明` 首页。
- 新增打赏展示区块。

## 安装

进入 WordPress 插件目录：

```bash
cd wp-content/plugins
```

克隆仓库：

```bash
git clone https://github.com/DearLicy/chuyi-ai-relay.git
```

然后在 WordPress 后台启用：

```text
插件 → 已安装插件 → 启用「初一 AI 中转」
```

也可以直接上传插件目录到：

```text
wp-content/plugins/chuyi-ai-relay
```

## 使用说明

### 1. 添加中转站

进入：

```text
初一 AI 中转 → 中转管理
```

添加一条中转配置：

```text
启用此中转：开启
中转名称：自定义名称
中转站地址：https://api.example.com
协议模式：OpenAI Compatible 或 Anthropic Messages
```

中转地址建议填写站点根地址，例如：

```text
https://api.example.com
```

不要填写完整接口路径，例如：

```text
https://api.example.com/v1/chat/completions
https://api.example.com/v1/images/generations
https://api.example.com/v1/messages
```

插件会在运行时统一拼接接口路径。

### 2. 设置 API Key

进入：

```text
设置 → Connectors
```

找到对应的 `初一 AI 中转` connector，填写 API Key。

默认中转 provider ID：

```text
chuyi-relay
```

其它中转 provider ID 类似：

```text
chuyi-relay-{标识}
```

API Key 也支持通过 PHP 常量或环境变量提供：

```php
define('CHUYI_RELAY_API_KEY', 'your-default-api-key');
define('CHUYI_RELAY_{标识}_API_KEY', 'your-relay-api-key');
```

```text
CHUYI_RELAY_API_KEY=your-default-api-key
CHUYI_RELAY_{标识}_API_KEY=your-relay-api-key
```

优先级：

```text
环境变量 → PHP 常量 → Connectors 保存的 API Key
```

### 3. 获取模型

回到：

```text
初一 AI 中转 → 中转管理
```

点击对应中转里的：

```text
一键获取模型
```

插件会请求该中转的模型列表，并写入该中转自己的模型池。

### 4. 设置模型能力

模型获取后，检查每个模型的能力：

```text
文本 / 视觉 / 生图
```

建议：

- 普通聊天模型勾选 `文本`。
- 支持图片输入的模型勾选 `文本` 和 `视觉`。
- 图片生成模型勾选 `生图`。
- Anthropic Messages 中转不要配置生图模型。

### 5. 测试中转和模型

先进入：

```text
初一 AI 中转 → 中转管理
```

执行：

```text
速度测试
```

确认中转可用后，再进入：

```text
初一 AI 中转 → 模型测试
```

选择中转、测试类型和模型后执行测试。

### 6. 授权插件或主题调用 Connector

官方 AI 插件和 WordPress Connectors 会限制调用方。

如果其它插件或主题调用 AI 能力时报未授权，请进入：

```text
设置 → Connectors → Connector Approvals
```

手动允许对应插件或主题使用本插件注册的 connector。

## 注意事项

### 从 v1.0.0 升级到 v1.0.1

`v1.0.0` 是单中转结构，`v1.0.1` 已经变成多中转结构。升级后建议检查：

- 中转是否仍处于启用状态。
- 中转地址是否是根地址，而不是完整接口路径。
- 对应 Connector 的 API Key 是否还在。
- 模型列表是否完整。
- 模型能力是否符合真实支持情况。
- 其它插件或主题是否已在 Connector Approvals 中获准调用。

### 中转地址填写规则

建议只填写根地址：

```text
https://api.example.com
```

不要填写接口路径。插件会按协议自动拼接：

```text
/v1/models
/v1/chat/completions
/v1/images/generations
/v1/messages
```

### 模型能力不是强校验

模型能力声明决定 WordPress AI Client 如何看待这个模型，但不代表中转站一定支持。

正式使用前建议确认：

- 哪些模型用于文本。
- 哪些模型支持视觉输入。
- 哪些模型是真正的生图模型。

### 图片生成兼容性

`1.0.1` 优先使用标准图片接口：

```text
/v1/images/generations
```

只有标准图片接口不可用或失败时，才回退：

```text
/v1/chat/completions
```

如果中转站只支持其中一种方式，需要确保对应模型和响应格式可用。

### 后台页面切换

插件后台顶部页面切换不会刷新整个 WordPress 后台页面。

但每次切换都会重新读取配置，并清空临时状态，例如：

- 测试结果。
- 当前测试选择。
- 保存提示。
- 请求中的状态。

这是为了避免旧页面状态误导。

## 目录结构

```text
chuyi-ai-relay/
├── plugin.php
├── ads/
│   └── connectors.json
├── assets/
│   ├── images/
│   │   ├── chuyi-relay.svg
│   │   ├── reward-alipay.jpg
│   │   └── reward-wechat.jpg
│   └── js/
│       ├── admin-settings.js
│       └── connectors-ads.js
└── src/
    ├── autoload.php
    ├── Settings.php
    ├── Admin/
    │   ├── ConnectorAds.php
    │   └── SettingsPage.php
    ├── Availability/
    │   └── ChuyiRelayProviderAvailability.php
    ├── Language/
    │   └── I18n.php
    ├── Metadata/
    │   └── ChuyiRelayModelMetadataDirectory.php
    ├── Models/
    │   ├── ChuyiRelayImageGenerationModel.php
    │   └── ChuyiRelayTextGenerationModel.php
    ├── Provider/
    │   ├── ChuyiRelayAnthropicApiKeyRequestAuthentication.php
    │   └── ChuyiRelayProvider.php
```

## 常见问题

### 为什么 Connectors 页面只填写 API Key？

Connectors 负责认证和授权。中转地址、协议、模型池和能力声明属于本插件自己的配置，所以放在：

```text
初一 AI 中转 → 中转管理
```

### 为什么官方 AI 插件生成图片时会先请求 chat/completions？

官方 AI 插件可能先用文本模型生成图片提示词，然后再调用生图能力。前一次 `chat/completions` 是提示词生成，不代表实际生图也走聊天接口。

### 为什么页面切换后测试结果消失？

这是设计行为。后台顶部页面切换不刷新整页，但每次切换都会重新读取配置并清空临时测试结果，避免旧状态误导。

## 打赏支持

如果这个插件帮你节省了时间，可以请作者喝杯咖啡。

### 微信 / 支付宝

二维码文件位于插件目录：

```text
assets/images/reward-wechat.jpg
assets/images/reward-alipay.jpg
```

也可以在插件后台 `使用说明` 页底部查看。

### TRC20 / USDT

```text
TKu7SNWrmi3n1n6e8FJDgPAwe8oGrxXHvP
```

## 维护者

- 作者：李初一
- GitHub：[@DearLicy](https://github.com/DearLicy)
- 仓库：[DearLicy/chuyi-ai-relay](https://github.com/DearLicy/chuyi-ai-relay)