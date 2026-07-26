# AppStore Runtime Agent

运行 `scripts/appstore-agent.sh --dry-run` 可验证社区 AppStore Runtime Agent 协议。它不会拉镜像、启动或停止容器、修改插件配置，也不会启动 MySQL、Redis、Manticore 等共享中间件。

在 `.env` 设置 `APPSTORE_INTERNAL_URL`、`APPSTORE_INSTANCE_ID`、`APPSTORE_AGENT_ID` 和随机的 `APPSTORE_AGENT_TOKEN`。社区 Node 的 `appStoreAgent.instances` 必须为同一实例登记该 Token 的 SHA-256，不保存明文。

dry-run 会领取一个任务，下载并校验 release digest、包内校验和与固定镜像 digest，再检查 release 声明的 Project API、MySQL、Redis、Manticore TCP 连通性。无论成功或失败都会归还任务为 `pending`，不会改变安装状态。

`--once` 执行安装、升级或卸载任务。安装和升级只接受固定镜像 digest、回环地址端口、命名卷及受控环境变量；健康检查失败时会恢复上一份本地 release。卸载执行 `docker compose down --remove-orphans`，不删除命名卷，因此应用数据默认保留。

运行时必须声明与应用一致的 `service.route_prefix`，例如 AI 为 `/apps/ai/`。Agent 只根据受控字段生成 `docker/appstore/config/{appId}/nginx.conf`，不接受发布包提供任意 Nginx 配置。生产机由宿主机 Nginx 加载该目录，并允许 Agent 执行配置检查和 reload。
