# 初一 AI 中转

[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-1.0.2-3858E9)](https://github.com/DearLicy/chuyi-ai-relay/releases)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green)](LICENSE)

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
- 支持提示词管理，可按能力覆盖或追加默认提示词。
- 支持自选生图接口：图片接口、对话接口或自动模式。
- 支持对话模式从 Markdown `data:image/...;base64,...` 图片中提取 base64。
- 生图不再下载临时图片文件转换 base64，WordPress 生图链路需要中转站直接返回 base64 图片。
- 支持中文后台、使用说明、审批提醒和插件更新提醒。

## 1.0.2 更新

- 修复 `build_dom_translation_script` 调用时机不正确的问题。
- 修复 `build_dom_translation_script` 内部逻辑导致部分页面无限循环加载、页面卡死的问题。
- 修复图片生成兜底机制导致一次生成请求两次接口、浪费 token 的问题。
- 增加自选生图接口，可按中转站选择图片接口、对话接口或自动模式。
- 增加对话模式从 Markdown 中提取图片 base64 的兼容逻辑。
- 增加提示词管理功能。
- 增加 GPL-3.0-or-later 开源协议。

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
生图接口：图片接口 / 对话接口 / 自动尝试
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

### 4. 设置模型能力与生图接口

模型获取后，检查并勾选模型能力：

```text
文本 / 视觉 / 生图
```

建议：

- 普通聊天模型勾选 `文本`。
- 支持图片输入的模型勾选 `文本` 和 `视觉`。
- 图片生成模型勾选 `生图`。
- Anthropic Messages 中转不要配置生图模型。

生图接口需要按中转站实际返回选择：

```text
图片接口 /v1/images/generations
对话接口 /v1/chat/completions
自动尝试：先图片接口，再对话接口
```

注意：不同中转站返回数据不一致。WordPress 图片生成需要返回 base64 图片才能真正写入媒体流程；如果中转站只返回图片 URL，请换用支持 base64 的生图接口或模型。

### 5. 管理提示词

进入：

```text
初一 AI 中转 → 提示词管理
```

可按能力启用提示词覆盖：

```text
替换默认提示词
追加到默认提示词
```

未启用覆盖时保持插件内置默认提示词。

### 6. 测试中转和模型

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

### 7. 审批调用权限

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
- `接入设置`：管理基础接入配置、生图接口和模型能力。
- `中转管理`：添加中转、获取模型、设置能力、测试延迟。
- `模型测试`：测试文本模型和图片模型。
- `提示词管理`：覆盖或追加各项 AI 能力的默认提示词。

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

## 开源协议

本插件使用 `GPL-3.0-or-later` 协议开源，详见 [LICENSE](LICENSE)。
