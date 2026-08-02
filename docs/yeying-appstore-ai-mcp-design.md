# YeYing 社区应用中心、AI Assistant 与 MCP 详细设计

> 历史文档：本文记录早期把 Node 作为 AppStore 运行入口的设计，不再作为当前架构依据。当前权威边界见 `docs/agent-runtime-design.md`：Project 只消费 Agent Runtime，Agent 负责安装、升级、失败回滚和卸载，Node 只做社区发布目录与授权入口。

## 1. 目标

YeYing 不再依赖 `dootask/appstore:0.5.4` 作为应用市场服务，改用社区已有的 `node` 项目作为应用注册、审核和发布中心，并增加 YeYing 兼容层，使主程序能够安装和管理插件。

最终目标：

```text
YeYing 主程序
  -> YeYing AppStore 兼容层
  -> 社区 node 应用中心
  -> AI Assistant / MCP / 其他插件
```

本地运行模式保持轻量：

```text
YeYing LaravelS       127.0.0.1:2222
YeYing AppStore/node   127.0.0.1:8100
MySQL                  Docker 23306
Redis                  Docker 26379
Manticore              Docker 9306
```

不依赖 Nginx 作为 AppStore 到 YeYing 的中间代理。

## 2. 现状判断

### 2.1 主项目已有的内容

YeYing 主项目已经包含 AI 相关的适配代码：

- `app/Module/AI.php`
- `app/Tasks/AiTaskAnalyzeTask.php`
- `app/Tasks/AiTaskLoopTask.php`
- `app/Tasks/UpdateSessionTitleViaAiTask.php`
- `app/Models/AiAssistantSession.php`
- `app/Models/AiAssistantSearchLog.php`
- `resources/assets/js/components/AIAssistant/`
- `resources/ai-kb/`
- `resources/ai-kb/_meta/tool-binding.yaml`

这些内容表示主程序具备 AI Assistant 的调用、会话、RAG 和 UI 适配能力，但不等于 AI Assistant 服务已经安装。

### 2.2 当前未完成的部分

当前本地尚未完成：

- AI Assistant 插件安装
- AI Assistant Docker 服务启动
- AI Gateway 启动
- LLM Provider 配置
- MCP Server 安装
- MCP 工具鉴权和权限验证
- AppStore 应用列表接口
- AppStore 插件安装/卸载/升级编排

因此当前主程序中的 AI UI 可能存在，但会因为 `AI Assistant` 应用未安装而不可用。

## 3. 组件边界

### 3.1 YeYing 主程序

职责：

- 用户、组织、项目、任务和文件数据
- AI 助手前端入口
- AI 会话和业务上下文
- RAG 查询编排
- MCP 工具权限边界
- 插件菜单和应用状态消费
- 向 AppStore 发起用户生命周期回调

不负责：

- 直接运行大模型
- 管理应用市场目录
- 构建和发布 AI Docker 镜像

### 3.2 `node` 社区应用中心

职责：

- 应用注册
- 应用详情和版本管理
- 应用审核
- 应用发布/下架
- 钱包身份和 UCAN 权限
- 社区应用门户

现有项目位置：

```text
/Users/liuxin2/Workspace/opensource/node
```

现有技术栈：

- Node.js
- TypeScript
- Express
- TypeORM
- PostgreSQL/MySQL
- Redis
- Vue 3 应用门户

### 3.3 YeYing AppStore 兼容层

兼容层可以作为 `node` 的一组路由和服务，也可以作为独立 Adapter 服务。第一阶段建议直接放到 `node`，减少部署组件数量。

职责：

- 将社区应用转换为 YeYing 插件格式
- 返回 YeYing 已安装应用列表
- 提供 YeYing 应用商店入口
- 校验 YeYing 用户身份
- 管理当前 YeYing 实例的安装状态
- 调度插件安装、卸载和升级
- 写入 YeYing 的 `docker/appstore/config`

### 3.4 AI Assistant

AI Assistant 来源：

```text
https://github.com/dootask/system-plugins/tree/main/ai
```

当前确认许可证：

```text
MIT License
```

职责：

- 模型供应商配置
- 对话和流式响应
- 多模型支持
- 视觉模型
- Redis 会话
- RAG 检索调用
- MCP 工具调用
- AI 任务分析和报告能力

改造后发布名称：

```text
YeYing AI Assistant
```

建议镜像名：

```text
yeying-community/ai
```

保留原 MIT 版权声明，同时增加夜莺社区贡献声明。

### 3.5 YeYing MCP Server

原 `dootask/mcp` 仓库源码公开，但当前未发现明确的根目录许可证，且 README 已声明项目弃用。因此不直接复制发布。

推荐重新实现：

```text
yeying-community/mcp
```

建议许可证：

```text
MIT License
```

工具实现参考现有工具清单和 `resources/ai-kb/_meta/tool-binding.yaml`，但重新使用 YeYing API、YeYing Token/UCAN 和权限模型。

## 4. AppStore 兼容 API

第一阶段需要兼容以下接口：

```text
GET  /internal
GET  /api/v1/internal/installed
POST /api/v1/internal/hooks/user_onboard
POST /api/v1/internal/hooks/user_update
POST /api/v1/internal/hooks/user_offboard
```

### 4.1 已安装应用列表

响应兼容当前 YeYing 前端：

```json
{
  "code": 200,
  "data": [
    {
      "id": "ai",
      "version": "0.1.0",
      "install_at": "2026-07-12T00:00:00.000Z",
      "menu_items": [
        {
          "location": "application",
          "label": "YeYing AI Assistant",
          "url": "apps/ai/",
          "visible_to": "all"
        }
      ]
    }
  ]
}
```

### 4.2 应用安装状态

当前 YeYing 主程序兼容以下本地状态文件：

```text
docker/appstore/config/{appId}/config.yml
```

示例：

```yaml
status: installed
install_version: 0.1.0
install_at: 2026-07-12T00:00:00.000Z
app_secret: generated-secret
```

长期方案应将实例安装状态存储在 `node` 数据库，并在本地文件中保留运行时配置镜像，避免手工修改文件造成状态不一致。

## 5. AppStore 鉴权

当前旧 AppStore 使用：

```http
Token: <YeYing token>
```

`node` 原生使用：

```http
Authorization: Bearer <JWT/UCAN>
```

第一阶段兼容策略：

1. AppStore Adapter 接收 `Token`。
2. 调用 YeYing：

   ```text
   GET http://127.0.0.1:2222/api/users/info
   ```

3. 验证用户身份和管理员权限。
4. Adapter 内部再使用 `node` 的服务身份访问应用中心。

生产环境不直接信任浏览器传来的管理员字段，所有安装、卸载和发布操作必须在服务端重新验证。

## 6. 插件包格式

插件需要包含：

```text
<appid>/
├── config.yml
├── logo.svg
├── README.md
├── README_zh.md
└── <version>/
    ├── config.yml
    ├── docker-compose.yml
    ├── nginx.conf
    └── CHANGELOG.md
```

构建型插件还应包含源码和 Dockerfile，并由 CI 构建镜像。

### 6.1 AI Assistant 插件

建议：

```text
appid: ai
镜像：yeying-community/ai:<version>
许可证：MIT
```

需要替换：

- DooTask 品牌
- 主程序 API 地址
- Token 验证
- MCP 地址
- 默认提示词
- 知识库品牌内容
- Docker 镜像名
- 插件菜单文案

## 7. MCP 设计

MCP Server 第一阶段工具范围：

- 查询用户
- 查询项目
- 查询任务
- 创建任务
- 更新任务
- 查询文件
- 获取文件文本
- 查询消息
- 查询报告

高风险写操作必须：

- 显式声明工具权限
- 校验当前用户对资源的权限
- 写入审计日志
- 支持幂等键
- 限制批量操作规模

不直接暴露：

- 任意数据库查询
- 任意 Shell 命令
- 任意页面 DOM 操作
- 任意文件系统路径读写
- 未经确认的批量删除

## 8. AI 模型配置

模型配置由 AI Assistant 应用提供，不在 YeYing 主程序 `.env` 中直接保存模型 API Key。

管理员流程：

```text
应用
  -> 应用商店
  -> 安装 AI Assistant
  -> 打开 AI Assistant
  -> 设置
  -> 模型供应商
```

配置项：

- API Key
- Base URL
- 模型列表
- 默认模型
- Temperature
- 默认提示词
- 视觉模型
- MCP 工具开关

## 9. 本地部署顺序

```text
1. 启动 MySQL、Redis、Manticore
2. 启动 YeYing LaravelS
3. 启动 node AppStore
4. 安装 YeYing AI Assistant 插件
5. 启动 AI Assistant 服务
6. 启动 YeYing MCP Server
7. 在 AI Assistant 中配置 LLM
8. 执行 Manticore 全量同步
9. 验证 AI 对话、RAG、任务分析和 MCP 工具
```

## 10. 验收标准

### AppStore

- 管理员能看到应用商店
- 能浏览应用列表
- 能查看应用详情
- 能安装和卸载应用
- 已安装应用能出现在 YeYing 应用中心
- 应用状态和版本可追踪

### AI Assistant

- 能打开 AI 助手
- 能配置至少一个模型
- 能完成流式对话
- 能读取任务上下文
- 能执行 RAG 检索
- 没有 API Key 时给出明确错误

### MCP

- MCP 客户端能连接
- Token/UCAN 鉴权正常
- 工具列表可发现
- 读操作经过权限过滤
- 写操作有审计和幂等控制
- 未授权工具调用被拒绝

## 11. 许可证和发布要求

### AI Assistant

AI Assistant 源码为 MIT，可以改造和商业化发布，但必须：

- 保留原 MIT LICENSE
- 保留 DooTask 原版权声明
- 增加 YeYing 社区版权和修改说明
- 检查依赖许可证
- 不继续使用 DooTask 商标、Logo 和域名

### MCP

原仓库没有确认到明确许可证，不能直接按 MIT 发布。YeYing MCP 应重新实现并单独采用社区确定的许可证。

### AppStore

`node` 当前根项目声明为 ISC，Web 子项目为 MIT。发布 YeYing Community AppStore 前，需要统一根项目许可证策略，并增加第三方依赖许可证清单。

## 12. 后续待办

以下工作不阻塞当前核心 Web 生产包，但需要在后续版本完成：

### AppStore

- [ ] 在 `node` 中增加 YeYing AppStore 兼容 API。
- [ ] 支持 `GET /api/v1/internal/installed`。
- [ ] 支持 `/internal` 应用市场页面。
- [ ] 支持 YeYing Token/JWT 管理员鉴权。
- [ ] 支持应用安装、卸载、升级和状态同步。
- [ ] 替换 `dootask/appstore:0.5.4`。

### AI Assistant

- [ ] Fork MIT 版本 AI Assistant。
- [ ] 替换 DooTask 品牌、域名和镜像名。
- [ ] 适配 YeYing API 和开源 Doo 运行时。
- [ ] 构建 `yeying-community/ai` 镜像。
- [ ] 发布 AI Assistant 应用包到社区应用中心。

### MCP

- [ ] 不直接复制许可证不明确且已弃用的 `dootask/mcp`。
- [ ] 重新实现 YeYing MCP Server。
- [ ] 使用 YeYing Token/UCAN 和工具级权限。
- [ ] 增加审计、幂等和高风险操作确认。
- [ ] 构建并发布 `yeying-community/mcp`。

### 生产部署

- [ ] 修复生产 Composer 依赖分层，确保发布包包含 LaravelS/Swoole 运行时。
- [ ] 完成干净 Ubuntu 主机安装验证。
- [ ] 增加数据库迁移和升级脚本。
- [ ] 增加 systemd/Supervisor 服务模板。
- [ ] 增加 Nginx/HTTPS/WebSocket 配置模板。
- [ ] 增加备份、回滚和健康检查流程。
