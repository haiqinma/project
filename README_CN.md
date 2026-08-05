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

该命令会执行前端生产构建并更新 `public/js/build/`。`./cmd local-start` 只服务 `public/js/build/` 中已有的静态资源，不会自动编译 Vue 源码。修改 `resources/assets/js/` 后，需要执行 `npm run build` 并刷新浏览器，才能在本地页面看到更新。`./cmd build` 和 `./cmd prod` 是同类前端构建封装命令。

## 生产部署和运维

生产部署、运维和升级细节统一维护在中文文档：[docs/部署手册.md](./docs/部署手册.md)。

## License

MIT License。第三方组件和原始版权声明以各自许可证为准。
