# 夜莺 - 开源任务管理系统

**[English](./README.md)** | 中文文档

夜莺 是基于 Laravel、LaravelS/Swoole 和 Vue 2 的开源任务与项目管理系统，采用 MIT License 发布。

- 社区：夜莺社区
- 邮箱：`yeying.community@gmail.com`
- 数据库：MySQL 8.4
- 搜索：Manticore（可选但建议生产启用）

## 本地开发

本地开发模式下，PHP/LaravelS 在宿主机运行。MySQL、Redis、Manticore、AppStore 等通用中间件由你在项目外统一管理，项目命令不会启动或停止这些容器。Node.js/npm 只用于前端开发和构建，不参与 PHP 服务运行。

### 环境要求

- macOS 或 Linux
- PHP 8.4
- PHP Swoole 扩展
- Composer
- Node.js 20+ 和 npm
- 可从宿主机访问的 MySQL 8.4 和 Redis
- Manticore、AppStore 按需单独部署

### 初始化本地环境

先准备 `.env` 并确认数据库、Redis 等连接配置指向你已经启动的通用中间件：

```bash
cp .env.template .env
```

至少检查这些配置：

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=project
DB_USERNAME=project
DB_PASSWORD=123456

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_CLIENT=predis
```

然后在项目根目录执行：

```bash
./cmd local-install
```

该命令用于初始化本地 `.env`、PHP 依赖、运行目录并执行迁移。它只检查必要配置项是否存在，不会自动修正 `.env`，也不会启动 MySQL、Redis 或其他中间件；如果配置错误或服务未启动，命令会在连接数据库时直接报错。

### 启动本地 PHP 服务

```bash
./cmd local-start
```

访问：

```text
http://127.0.0.1:2222
```

查看、停止和重启：

```bash
./cmd local-stop
./cmd local-start
```

### 前端开发模式

需要实时编译前端时，另开终端执行：

```bash
./cmd dev
```

修改前端后，开发服务器会自动重新编译。不要同时运行多个前端开发服务器。

### 本地生产构建

只构建前端静态资源，不启动生产 PHP 服务：

```bash
npm run build
```

构建产物位于：

```text
public/js/build/
public/manifest.json
```

## 生产部署

生产部署使用构建好的 `project-v版本号-提交号.tar.gz`，应用在 Ubuntu 宿主机以 PHP 8.4 + LaravelS/Swoole 进程运行，不使用应用容器。MySQL、Redis、Manticore 可以部署在独立服务器或容器中。

配置文件约定：仓库只提交 `.env.template` 作为配置模板；复制为 `.env` 后填写实际值。`.env` 是生效配置，包含密码和密钥，已被 Git 忽略，禁止提交到远端。SMTP 邮箱和其他业务设置由管理员在后台配置，不放入 `.env`。

### 1. 构建生产包

在构建机执行：

```bash
./scripts/package.sh
```

不带参数时，脚本会从 `origin` 拉取远端 tag，在 `origin/main` 上创建下一个 patch 版本 tag（例如 `v0.0.1` -> `v0.0.2`），推送 tag，然后基于这个精确提交打包。如果最新 tag 已经指向 `origin/main`，则跳过打包。要按已有 tag 打包且不创建新 tag，可显式传入：

```bash
./scripts/package.sh v0.0.2
```

如需从其他远端打包，可设置 `PACKAGE_REMOTE=upstream`。脚本会在临时 Git worktree 中构建，避免污染当前工作区。

脚本会依次执行：

- `npm install`
- 前端生产构建
- `composer install --no-dev`
- 生成包含 `vendor` 和前端静态资源的生产包

输出目录：

```text
output/project-v版本号-提交号.tar.gz
```

生产包不包含：

- `.env`
- `node_modules`
- `package-lock.json`
- Git 历史
- 运行日志

### 2. 上传并解压

在 Ubuntu 服务器执行：

```bash
sudo mkdir -p /opt/yeying
sudo tar -xzf project-v版本号-提交号.tar.gz -C /opt/yeying
cd /opt/yeying
```

### 3. 安装 Ubuntu 运行依赖

第一次部署或 PHP/Swoole 未安装时执行：

```bash
sudo ./scripts/ubuntu-deps.sh --install
```

该脚本负责安装：

- PHP 8.4、PHP CLI 和 PHP 开发包
- Swoole
- MySQL、Redis、LDAP、GMP、cURL、XML、DOM、mbstring、GD、Imagick、ZIP、FFI 等 PHP 扩展
- Composer
- Swoole 编译依赖和基础运维工具

安装后检查：

```bash
./scripts/ubuntu-deps.sh --check
```

应至少看到：

```text
OK command: php
OK php extension: swoole
OK command: composer
```

### 4. 配置生产环境

```bash
cp .env.template .env
```

编辑 `.env`，至少配置：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://你的域名

DB_CONNECTION=mysql
DB_HOST=MySQL地址
DB_PORT=3306
DB_DATABASE=yeying
DB_USERNAME=yeying
DB_PASSWORD=数据库密码

REDIS_HOST=Redis地址
REDIS_PORT=6379

SEARCH_HOST=Manticore地址
SEARCH_PORT=9306

LARAVELS_LISTEN_IP=127.0.0.1
LARAVELS_LISTEN_PORT=2222
RUNTIME_DRIVER=yeying
```

宿主机运行时请将 `DB_HOST`、`DB_PORT`、`REDIS_HOST` 和 `REDIS_PORT` 配置为宿主机可访问的实际地址。项目不会代为启动 MySQL、Redis 或其他通用中间件。

不要把生产 `.env` 提交到 Git。生产 SMTP 邮箱在管理员后台的系统邮箱设置中配置，不写入 README 或源码。

### 5. 初始化应用

```bash
./scripts/install.sh
```

该命令会：

- 生成 `APP_KEY`
- 创建 Laravel 可写目录
- 安装生产 Composer 依赖
- 执行数据库迁移
- 确保存在默认管理员账号（无管理员时创建/修复 `admin@yeying.com` 并输出初始密码）
- 清理配置、路由和视图缓存
- 修复 `storage` 和 `bootstrap/cache` 权限

### 6. 启动和管理服务

```bash
./scripts/starter.sh start
./scripts/starter.sh status
./scripts/starter.sh restart
./scripts/starter.sh stop
```

日志：

```text
storage/logs/starter.log
storage/logs/laravel.log
```

启动脚本只负责启动 PHP/LaravelS，不负责安装 PHP。若出现 `PHP 8.4 is not installed`，先执行：

```bash
sudo ./scripts/ubuntu-deps.sh --install
./scripts/install.sh
```

### 7. 健康检查

默认执行就绪检查，也可以执行完整检查或输出 JSON：

```bash
./scripts/health-check.sh
./scripts/health-check.sh --level all
./scripts/health-check.sh --level all --format json
```

- `liveness`：检查 LaravelS PID（外部进程管理或本地模式没有 PID 文件时跳过）和 `/health`。
- `readiness`：确认 LaravelS 已经可以响应应用请求。
- `dependency`：对 MySQL 执行只读 `SELECT 1`，并对 Redis 执行 `PING`。
- `all`：依次执行存活、就绪和依赖检查。

MySQL 和 Redis 都是必需依赖，检查失败会返回 `FAIL`。脚本只读取当前配置并执行只读探针，不会启动、停止或修复任何中间件。可通过 `HEALTH_BASE_URL`、`HEALTH_TIMEOUT`、`HEALTH_RETRIES` 等环境变量覆盖默认值。

### 8. 生产入口

建议让 LaravelS 只监听 `127.0.0.1:2222`，前面使用 Caddy 或 Nginx 提供：

- HTTPS
- 域名访问
- WebSocket 转发
- 上传大小和超时配置
- 静态资源缓存

不要把生产 `.env`、MySQL 密码和 SMTP 授权码写入仓库。

## 常用运维命令

```bash
./cmd repassword                  # 重置第一个管理员密码
./cmd repassword 1                # 按用户 ID 重置密码
./cmd repassword user@example.com # 按邮箱重置密码
./cmd ensure-admin                # 无管理员时创建/修复默认管理员
./cmd help                        # 查看命令帮助
```

无参数执行 `./cmd repassword` 时，只会查找 `identity` 包含 `admin` 标记的用户。若提示 `错误：未找到管理员用户！`，不要回退重置任意第一个用户，应先把明确的目标账号授予管理员身份。

`./cmd repassword` 使用 `.env` 中的 MySQL 连接配置，并要求本机安装 `mysql` 客户端。Ubuntu 可通过 `scripts/ubuntu-deps.sh --install` 安装。命令不会猜测旧项目容器名；容器场景需要显式设置 `MYSQL_CONTAINER=mysql`。

部署后需要获得第一个管理账号时，执行下面命令即可；若系统已存在管理员，命令只会输出已有管理员，不修改密码。若没有管理员，命令会创建/修复 `admin@yeying.com`，输出初始密码，并强制首次登录后修改密码。

```bash
./cmd ensure-admin
```

生产升级前请先备份数据库和持久文件。生产升级顺序按 `docs/生产升级操作手册.md` 执行；S3 迁移按 `docs/S3存储迁移操作手册.md` 执行。

## License

MIT License。第三方组件和原始版权声明以各自许可证为准。
