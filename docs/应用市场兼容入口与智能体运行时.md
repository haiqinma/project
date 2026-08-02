# Agent Runtime 与 AppStore 兼容入口

Project 中的 `api/appstore/*` 仍作为历史兼容入口保留，但不再代表 Node AppStore 直接接管运行时。社区架构调整后，Project 只把安装、升级、卸载和用户生命周期 hook 转发给 Agent Runtime。

## 配置

Project `.env` 需要配置：

```dotenv
AGENT_INTERNAL_URL=http://127.0.0.1:3900
AGENT_INSTANCE_ID=project
AGENT_INTERNAL_TOKEN=
```

`AGENT_INTERNAL_TOKEN` 必须与 Agent 服务的 `HUB_INTERNAL_TOKEN` 一致。旧的 `APPSTORE_INTERNAL_URL`、`APPSTORE_INSTANCE_ID`、`APPSTORE_AGENT_TOKEN` 仅作为兼容名保留；如果继续使用，`APPSTORE_INTERNAL_URL` 也应指向 Agent，而不是 Node。

## 调用边界

Project 对前端暴露：

- `api/appstore/installed`
- `api/appstore/install`
- `api/appstore/upgrade`
- `api/appstore/uninstall`

这些接口只做登录态和管理员校验，然后代理 Agent Runtime 的 `/api/v1/internal/*` 接口。Agent 再按协议访问 Node Registry 获取 release artifact、签名和版本信息，并在受控运行目录内执行安装、升级、失败回滚和卸载。

`docker/appstore/` 目录当前仍是历史插件状态目录，短期不改路径，避免破坏已有安装状态。新增协议和文档统一使用 Agent Runtime 表达运行控制面。
