# scripts 脚本说明

本文档说明 `scripts/` 目录下各脚本的用途、典型调用方式和维护注意事项。脚本默认从仓库根目录执行；涉及 PHP 项目命令时，日常开发仍优先通过 `./cmd` 封装调用。

## 运维与部署脚本

| 脚本 | 用途 | 典型调用 | 说明 |
| --- | --- | --- | --- |
| `install.sh` | 初始化生产运行环境内的项目依赖、目录、密钥、数据库迁移和管理员账号。 | `./scripts/install.sh` | 会检查 Ubuntu 依赖、生成 `.env`、执行 Composer 安装和数据库迁移。 |
| `starter.sh` | 管理 LaravelS/Swoole 服务进程。 | `./scripts/starter.sh start` / `stop` / `restart` / `status` | 启动前会检查 PHP、Swoole、`vendor/autoload.php`，并执行待处理迁移。 |
| `health-check.sh` | 检查服务存活、就绪状态和依赖连通性。 | `./scripts/health-check.sh --level readiness` | 支持 `liveness`、`readiness`、`dependency`、`all`，输出格式支持 `text` / `json`。 |
| `ubuntu-deps.sh` | 检查或安装 Ubuntu 环境依赖。 | `./scripts/ubuntu-deps.sh --check` / `sudo ./scripts/ubuntu-deps.sh --install` | 安装模式仅支持 Ubuntu，会安装 PHP 8.4、扩展、Composer、MySQL/Redis 客户端等。 |

## 发布与升级脚本

| 脚本 | 用途 | 典型调用 | 说明 |
| --- | --- | --- | --- |
| `package.sh` | 基于远端 `main` 或指定 tag 打生产发布包。 | `./scripts/package.sh` / `./scripts/package.sh v1.2.3` | 会拉取远端、构建前端、安装生产 PHP 依赖，并输出 `output/<project>-<version>-<hash>.tar.gz`。 |
| `config_backup.sh` | 生产升级前备份配置文件。 | `./scripts/config_backup.sh` | 读取 `scripts/backup.conf` 和 `.passphrase-file`，备份 `.env`；如存在 `/etc/nginx/conf.d/project.conf`，也会放在备份包根目录下。 |
| `copy-for-upgrade.sh` | 升级过程中将当前版本配置复制到目标版本目录。 | `./scripts/copy-for-upgrade.sh /absolute/path/to/target` | 接收目标目录绝对路径，直接覆盖目标目录下 `.env`，成功返回 `0`。 |
| `backup.conf.template` | `config_backup.sh` 的配置模板。 | 复制为 `scripts/backup.conf` 后编辑 | 用于控制配置备份开关、文件名前缀和后缀。 |

## 应用市场与运行时脚本

| 脚本 | 用途 | 典型调用 | 说明 |
| --- | --- | --- | --- |
| `appstore-agent.sh` | 应用市场 Runtime Agent 本地执行器。 | `./scripts/appstore-agent.sh --once` / `--dry-run` | 从内部 AppStore API 领取安装、升级、卸载任务，校验 release、生成 `runtime.env`、执行 Docker Compose、健康检查、写入本地状态并更新 nginx 代理配置。 |

## 开发协作脚本

| 脚本 | 用途 | 典型调用 | 说明 |
| --- | --- | --- | --- |
| `sync.sh` | 将当前分支同步到上游同名分支。 | `./scripts/sync.sh` | 要求工作区干净；默认从 `upstream/<当前分支>` rebase 后推送到 `origin`，可用 `AUTO_PUSH=false` 禁止自动推送。 |
| `pr.sh` | 创建当前分支到上游仓库的 GitHub Pull Request。 | `./scripts/pr.sh` | 会检查/安装 GitHub CLI、处理 `origin` / `upstream`、可自动 push 当前分支并创建 PR。 |
| `check-language.mjs` | 校验前端 `$L()` / `$A.L()` 字面量是否登记。 | `node scripts/check-language.mjs` 或 `npm run check:lang` | 扫描 `resources/assets/js`，发现缺失文案时返回非 0。 |
| `gen-events-map.mjs` | 生成前端 mitt 事件总线映射文档。 | `node scripts/gen-events-map.mjs` 或 `npm run events:map` | 扫描 `emitter.emit/on/off` 调用并更新 `docs/前端事件总线映射.md`。 |
| `repassword.sh` | 重置用户密码的辅助入口。 | `./cmd repassword [账号标识符] [自定义密码]` | 读取 `.env` 数据库配置，优先使用本机 `mysql`，否则尝试使用运行中的 MySQL 容器，最终调用 `docker/mysql/repassword.sh`。 |

## 维护约定

- 新增脚本应放在 `scripts/` 目录下，脚本内使用仓库根目录或脚本目录推导路径，避免依赖调用者当前目录。
- Shell 脚本建议使用 `#!/usr/bin/env bash` 和 `set -euo pipefail`；需要被直接执行的脚本应设置可执行权限。
- 新增用户可见错误提示或命令输出时，保持中文描述清晰；如涉及前后端业务文案，再按国际化规则同步语言文件。
- 修改发布、升级、部署相关脚本时，同步检查 `docs/生产升级操作手册.md` 是否需要更新。
- `package.sh`、`install.sh`、`starter.sh`、`ubuntu-deps.sh` 等脚本可能修改依赖、数据库或服务进程，日常开发不要作为普通验证命令随意执行。
