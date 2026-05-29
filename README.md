# 初一 AI 中转

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-1.0.3-3858E9)](https://github.com/DearLicy/chuyi-ai-relay/releases)

初一 AI 中转是一个 WordPress 插件，用于把自建或第三方 AI 中转站接入 WordPress AI Client / Connectors。

插件负责中转站地址、协议类型、模型池、模型能力和模型测试；API Key 仍由 WordPress Connectors 统一管理。

交流群：`201307007`

## 主要功能

- 支持多个 AI 中转站集中管理。
- 支持 OpenAI Compatible 和 Anthropic Messages 协议。
- 支持一键获取模型列表。
- 支持为模型声明文本、视觉、生图能力。
- 支持中转连通测试、延迟记录和模型测试。
- 支持官方 AI 插件和 WordPress Connectors 调用。
- 支持中文后台、使用说明、审批提醒和插件更新提醒。
- 支持完全接管 WordPress AI 插件的图片生成功能。
- 支持图片接口生成图片。
- 支持通过对话接口请求图片生成。
- 支持图片生成结果解析和统一转换，包括 base64、URL、Markdown 包裹的 base64、Markdown 包裹的 URL。
- 支持远程图片 URL 下载后临时转换为 base64，再提供给 AI 插件处理；不会把远程图片保存到本地媒体库。
- 支持从 Markdown 内容中提取图片内容，并获取可交给 AI 插件处理的 base64 图片数据。

## 1.0.3 更新说明

服务器当前版本为 `1.0.3`，相比 GitHub 当前 `1.0.2` 版本，主要更新如下：

### 图片生成能力优化

- 插件完全接管 WordPress AI 插件的图片生成功能。
- 同时支持图片生成接口和对话接口触发图片生成。
- 兼容接口返回的多种图片格式：
  - 原始 base64。
  - 图片 URL。
  - Markdown 包裹的 base64 图片。
  - Markdown 包裹的图片 URL。
- 当接口返回 URL 时，插件会请求远程图片并获取图片 base64，交给 AI 插件继续处理。
- URL 图片只做临时读取和转换，不保存到本地。
- Markdown 内容会先进行图片提取，再获取可用 base64 数据。
- 该流程用于兼容不同中转站、不同模型和不同返回格式，减少 AI 插件侧的适配成本。

## 图片生成接管流程视图

```mermaid
flowchart LR
    A[官方 AI 图片能力] --> B[WP AI Client]
    B --> C[初一 ChuyiRelayImageGenerationModel]
    C --> D{选择生成通道}

    D -->|图片接口| E[/v1/images/generations]
    D -->|对话接口| F[/v1/chat/completions 或 /v1/messages]

    E --> G{接口返回内容}
    F --> G

    G -->|base64| H[直接提取 base64]
    G -->|data:image/...;base64,...| I[提取图片 base64]
    G -->|图片 URL| J[临时下载远程图片]
    G -->|Markdown 包裹 URL| K[从 Markdown 提取 URL]
    G -->|Markdown 包裹 base64| L[从 Markdown 提取 base64]

    K --> J
    J --> M[转换为 base64]
    H --> N[统一图片数据]
    I --> N
    L --> N
    M --> N

    N --> O[OpenAI compatible image data]
    O --> P[返回给官方 AI 插件处理]
```

简化理解：

```text
官方 AI 图片能力
  → WP AI Client
  → 初一 AI 中转接管图片生成模型
  → 自动选择图片接口或对话接口
  → 解析 base64 / URL / Markdown 图片内容
  → URL 图片只临时读取并转换，不保存本地
  → 统一为 AI 插件可处理的 base64 图片数据
  → 返回给官方 AI 插件继续处理
```

## 图片生成返回格式兼容

插件会把不同接口返回统一转换为 AI 插件可处理的图片数据。

| 返回形式 | 处理方式 |
| --- | --- |
| base64 | 直接识别并交给 AI 插件处理 |
| `data:image/...;base64,...` | 提取 base64 内容 |
| 图片 URL | 下载远程图片，临时转换为 base64，不保存本地 |
| Markdown 图片 URL | 从 Markdown 中提取 URL，再临时转换为 base64 |
| Markdown base64 图片 | 从 Markdown 中提取 base64，再交给 AI 插件处理 |

## 使用方法

### 1. 添加中转站

进入：

```text
初一 AI 中转 → 中转管理
```

添加中转配置：

```text
启用此中转：开启
中转名称：自定义名称
中转站地址：https://api.example.com
协议模式：OpenAI Compatible 或 Anthropic Messages
```

中转地址建议填写站点根地址，不要填写完整接口路径。

推荐：

```text
https://api.example.com
```

不推荐：

```text
https://api.example.com/v1/chat/completions
https://api.example.com/v1/images/generations
https://api.example.com/v1/messages
```

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

API Key 也可以通过 PHP 常量或环境变量提供：

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

插件会读取中转的模型列表，并写入该中转自己的模型池。

### 4. 设置模型能力

模型获取后，检查并勾选模型能力：

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

### 6. 审批调用权限

官方 AI 插件和 WordPress Connectors 会限制调用方。

如果官方 AI 插件、主题或其它插件调用 AI 能力时报未授权，请进入：

```text
设置 → Connectors → Connector Approvals
```

手动允许对应调用方使用本插件注册的 connector。

## 后台页面

插件启用后，后台会出现：

```text
初一 AI 中转
```

主要页面：

- `使用说明`：查看配置流程、审批提醒和打赏入口。
- `接入设置`：管理基础接入配置。
- `中转管理`：添加中转、获取模型、设置能力、测试延迟。
- `模型测试`：测试文本模型和图片模型。

## 打赏作者

如果这个插件帮你节省了时间，可以请作者喝杯咖啡。

### 微信 / 支付宝

<table>
  <tr>
    <td align="center">
      <strong>微信</strong><br>
      <img src="assets/images/reward-wechat.jpg" alt="微信打赏" width="220">
    </td>
    <td align="center">
      <strong>支付宝</strong><br>
      <img src="assets/images/reward-alipay.jpg" alt="支付宝打赏" width="220">
    </td>
  </tr>
</table>

### TRC20 / USDT

```text
TKu7SNWrmi3n1n6e8FJDgPAwe8oGrxXHvP
```