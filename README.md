# 初一中转

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)

初一中转是一个 WordPress 插件，用于把自定义 OpenAI 兼容中转接口接入 WordPress AI Client / Connectors。插件支持文本生成、视觉输入和生图能力声明，并提供后台模型同步、能力勾选和模型测试页面。

仓库地址：[https://github.com/DearLicy/chuyi-ai-relay](https://github.com/DearLicy/chuyi-ai-relay)

## 功能特性

- 注册 `初一中转` 为 WordPress AI Client Provider。
- 自动出现在 WordPress Connectors 页面，使用 API Key 认证。
- 支持自定义 OpenAI 兼容接口地址。
- 支持从 `/v1/models` 一键获取模型列表。
- 支持为每个模型手动声明能力：
  - 文本生成
  - 视觉输入
  - 生图
- 生图模型会以 `image_generation` capability 暴露给 WordPress AI 功能。
- 管理页内置文本测试和图像测试。
- 设置页支持 `Ctrl + S` / `Command + S` 快速保存。
- 模型测试结果会过滤 reasoning / deep thinking 内容，只展示最终回答。

## 环境要求

- WordPress `6.9+`
- PHP `7.4+`
- WordPress AI Client / Connectors 可用
- 一个兼容 OpenAI 协议的中转接口

接口至少建议支持：

- `GET /v1/models`
- `POST /v1/chat/completions`

如果要使用生图能力，中转站还需要支持通过 `chat/completions` 返回图片 URL 或 base64 图片数据。

## 安装

进入 WordPress 插件目录：

```bash
cd wp-content/plugins
```

克隆仓库：

```bash
git clone https://github.com/DearLicy/chuyi-ai-relay.git
```

然后在 WordPress 后台进入：

```text
插件 → 已安装插件 → 启用「初一中转」
```

也可以直接上传插件目录到：

```text
wp-content/plugins/chuyi-ai-relay
```

## 配置流程

### 1. 设置接口地址

进入：

```text
设置 → 初一中转
```

填写 OpenAI 兼容接口地址，例如：

```text
https://api.example.com
```

或：

```text
https://api.example.com/v1
```

插件会自动规范化接口地址：

- 没有协议时自动补 `https://`
- 没有 `/v1` 时自动补 `/v1`
- 如果误填了 `/models`、`/chat/completions`、`/responses`、`/images/generations`，会自动裁剪为基础地址

### 2. 设置 API Key

进入 WordPress Connectors 页面，找到 `初一中转`，填写你的中转接口 API Key。

API Key 会由 Connectors 保存到：

```text
connectors_ai_chuyi_relay_api_key
```

插件也支持通过环境变量或常量提供 API Key：

```php
define('CHUYI_RELAY_API_KEY', 'your-api-key');
```

或环境变量：

```text
CHUYI_RELAY_API_KEY=your-api-key
```

优先级为：

```text
环境变量 CHUYI_RELAY_API_KEY → PHP 常量 CHUYI_RELAY_API_KEY → Connectors 保存的 API Key
```

### 3. 获取模型

回到：

```text
设置 → 初一中转
```

点击：

```text
一键获取模型
```

插件会请求：

```text
GET {base_url}/models
```

并保存模型列表。

### 4. 设置模型能力

模型获取后，可以在右侧模型能力面板中勾选：

- `文本生成`
- `视觉输入`
- `生图`

注意：如果一个模型勾选了 `生图`，插件会把它作为独立生图模型处理，不再同时声明为文本模型。

### 5. 测试模型

设置页下方提供：

- 文本测试
- 图像测试

图像测试下拉框只显示已标记为 `生图` 的模型。

## 模型能力自动推断

点击 `一键获取模型` 后，插件会根据模型 ID 做默认能力推断。

默认识别为生图模型的关键词：

```text
dall-e, gpt-image, imagen, flux, stable-diffusion, sdxl, midjourney
```

默认识别为视觉模型的关键词：

```text
gpt-4o, gpt-4.1, gpt-5, o1, o3, o4, vision, qwen-vl, glm-4v, llava
```

默认排除的非生成模型关键词：

```text
embedding, embed, rerank, moderation, tts, whisper, transcribe, realtime
```

其他模型默认声明为文本生成模型。

由于 `/models` 标准响应通常不包含能力字段，自动推断无法覆盖所有中转站命名方式。建议获取模型后手动确认一次能力勾选。

## 请求方式

### 文本生成

文本模型通过 OpenAI 兼容的 chat completions 接口请求：

```text
POST {base_url}/chat/completions
```

### 生图

生图模型也通过 chat completions 接口请求：

```text
POST {base_url}/chat/completions
```

请求中会带上：

```json
{
  "modalities": ["image"]
}
```

插件会从响应中提取图片 URL、`data:image/...;base64,...` 或纯 base64 图片数据，并转换为 WordPress AI Client 期望的图片结果。

## 目录结构

```text
chuyi-ai-relay/
├── plugin.php
├── assets/
│   ├── images/
│   │   └── chuyi-relay.svg
│   └── js/
│       └── admin-settings.js
└── src/
    ├── autoload.php
    ├── Settings.php
    ├── Admin/
    │   └── SettingsPage.php
    ├── Availability/
    │   └── ChuyiRelayProviderAvailability.php
    ├── Metadata/
    │   └── ChuyiRelayModelMetadataDirectory.php
    ├── Models/
    │   ├── ChuyiRelayImageGenerationModel.php
    │   └── ChuyiRelayTextGenerationModel.php
    └── Provider/
        └── ChuyiRelayProvider.php
```

## 开发说明

插件没有引入 Composer 依赖，使用 `src/autoload.php` 做简单 PSR-4 风格自动加载。

Provider ID：

```text
chuyi-relay
```

主要 option：

```text
chuyi_ai_relay_base_url
chuyi_ai_relay_models
chuyi_ai_relay_model_capabilities
connectors_ai_chuyi_relay_api_key
```

语法检查：

```bash
php -l plugin.php
php -l src/Settings.php
php -l src/Admin/SettingsPage.php
php -l src/Provider/ChuyiRelayProvider.php
php -l src/Metadata/ChuyiRelayModelMetadataDirectory.php
php -l src/Models/ChuyiRelayTextGenerationModel.php
php -l src/Models/ChuyiRelayImageGenerationModel.php
php -l src/Availability/ChuyiRelayProviderAvailability.php
node --check assets/js/admin-settings.js
```

## 常见问题

### 为什么 Connectors 页面只填写 API Key？

WordPress Connectors 的 Provider 认证结构主要负责 API Key 管理。接口地址、模型列表和模型能力属于初一中转插件自己的配置，所以放在：

```text
设置 → 初一中转
```

### 为什么要先保存接口地址，再填写 API Key？

`初一中转` Provider 的 base URL 来自插件设置。先保存接口地址，Connectors 页面才能展示和使用正确的中转站信息。

### 为什么获取模型后还要手动勾选能力？

OpenAI 标准 `/models` 响应一般只返回模型 ID，不返回模型是否支持视觉、生图等能力。插件会做默认推断，但最终以后台手动勾选为准。

### 生图模型已经勾选了，为什么调用仍然失败？

勾选 `生图` 只表示插件把该模型声明为 WordPress AI 的生图模型。实际能否生成图片取决于中转站是否支持该模型、是否支持通过 `chat/completions` 返回图片，以及响应格式是否包含可提取的图片 URL 或 base64 数据。

### 支持 `/images/generations` 吗？

当前版本的生图调用走 `/chat/completions`，这是为了兼容部分中转站对图像模型的统一聊天接口封装。如果你的中转站只支持 `/images/generations`，需要改造 `ChuyiRelayImageGenerationModel`。

## 维护者

- 作者：李初一
- GitHub：[@DearLicy](https://github.com/DearLicy)
- 仓库：[DearLicy/chuyi-ai-relay](https://github.com/DearLicy/chuyi-ai-relay)