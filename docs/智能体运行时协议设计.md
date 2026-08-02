# Agent Runtime 协议设计

## 1. 定位

社区智能体与插件运行链路统一按下列关系实现：

```text
Project -> Agent Runtime -> Node Registry
```

- Project 是业务系统，拥有项目、任务、文件、权限和用户上下文。
- Agent 是运行控制面，拥有智能体实例、安装任务、升级、失败回滚、卸载、健康检查和运行状态。
- Node 是社区发布中心，拥有应用和智能体的登记、审核、版本、release artifact、签名和授权入口。
- AppStore 是旧名称，短期只作为 Project 前端入口和 API 兼容层存在。

## 2. Project 接入职责

Project 保留 `api/appstore/*` 兼容入口：

- `GET /api/appstore/installed`
- `POST /api/appstore/install`
- `POST /api/appstore/upgrade`
- `POST /api/appstore/uninstall`

这些接口只做：

- 登录态校验。
- 管理员权限校验。
- 转发当前语言、用户 token、Project 实例 ID。
- 使用 `AGENT_INTERNAL_TOKEN` 调用 Agent internal API。

Project 不在 Web 请求内执行下载、解压、容器启动、镜像拉取、健康检查和回滚，也不直接向 Node 创建运行时任务。

## 3. Agent Runtime 协议

Agent 对 Project 暴露 internal API：

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/v1/internal/installed` | 查询当前 Project 已安装能力 |
| POST | `/api/v1/internal/install` | 创建安装任务 |
| POST | `/api/v1/internal/upgrade` | 创建升级任务 |
| POST | `/api/v1/internal/uninstall` | 创建卸载任务 |
| POST | `/api/v1/internal/hooks/{action}` | 接收 Project 用户生命周期 hook |
| GET | `/api/v1/internal/project/agents` | Agent-native 查询入口 |
| POST | `/api/v1/internal/project/agents/install` | Agent-native 安装入口 |
| POST | `/api/v1/internal/project/agents/upgrade` | Agent-native 升级入口 |
| POST | `/api/v1/internal/project/agents/uninstall` | Agent-native 卸载入口 |
| POST | `/api/v1/internal/project/hooks/{action}` | Agent-native hook 入口 |

鉴权：

```http
Authorization: Bearer <AGENT_INTERNAL_TOKEN>
X-YeYing-Instance: project
```

`AGENT_INTERNAL_TOKEN` 必须与 Agent 服务的 `HUB_INTERNAL_TOKEN` 一致。

## 4. Node Registry 职责

Agent 需要安装或升级时，由 Agent 向 Node 查询：

- 应用或智能体清单。
- 目标版本和升级策略。
- release artifact 下载地址。
- digest、签名、公钥或授权证明。
- 配置 schema、端口、路由前缀、依赖声明和健康检查声明。

Node 不负责启动 Project 生产机上的进程，也不写 Project 的 `docker/appstore/` 状态目录。

## 5. 兼容策略

- `APPSTORE_INTERNAL_URL`、`APPSTORE_INSTANCE_ID`、`APPSTORE_AGENT_TOKEN` 仍可被旧部署读取。
- 新部署应使用 `AGENT_INTERNAL_URL`、`AGENT_INSTANCE_ID`、`AGENT_INTERNAL_TOKEN`。
- 如旧配置继续存在，`APPSTORE_INTERNAL_URL` 应指向 Agent Runtime，而不是 Node。
- `docker/appstore/` 当前仍作为历史安装状态目录保留，后续再设计数据迁移，不在本阶段改路径。

## 6. 下一阶段

- Agent 从 Node 拉取真实 release artifact，并校验 digest 与签名。
- Agent 对安装、升级、卸载任务增加状态机和失败回滚。
- Project 增加 Agent 能力调用协议，用于 AI 任务处理、MCP 工具、任务自动化和通行证扫码登录。
- Node 文档只保留发布中心说明，不再承诺运行时安装逻辑。
