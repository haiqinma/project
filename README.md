# YeYing - Open Source Task Management System

**[中文文档](./README_CN.md)** | English

YeYing is an open source task and project management system based on Laravel, LaravelS/Swoole and Vue 2. It is released under the MIT License.

## Local Development

In local development, PHP/LaravelS runs directly on the host. MySQL, Redis, Manticore, AppStore and other shared middleware are managed outside this project; project commands do not start or stop those containers. Node.js/npm is only needed for frontend development and builds.

### Requirements

- macOS or Linux
- PHP 8.4 with Swoole
- Composer
- Node.js 20+ and npm
- MySQL 8.4 and Redis reachable from the host
- Manticore and AppStore deployed separately when needed

### Initialize and start

Create `.env` and point the database and Redis settings at your existing shared middleware:

```bash
cp .env.template .env
```

At minimum review:

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

Then run from the project root:

```bash
./cmd local-install
./cmd local-start
```

Open:

```text
http://127.0.0.1:2222
```

`./cmd local-install` initializes `.env`, PHP dependencies and runtime directories, then runs migrations. It only checks that required settings exist; it does not rewrite `.env` and does not start MySQL, Redis or other middleware. If a value is wrong or a service is unavailable, the command fails when the application connects to that service.

Stop the host service:

```bash
./cmd local-stop
```

Run the frontend development server separately when needed:

```bash
./cmd dev
```

Build frontend assets without starting the PHP service:

```bash
npm run build
```

## Production Deployment

Production uses a generated `project-vVERSION-COMMIT.tar.gz` package. The application runs directly on Ubuntu with PHP 8.4 and LaravelS/Swoole. Node.js is not required on the production server.

### 1. Build the package

Run on the build machine:

```bash
./scripts/package.sh
```

Without arguments, the script fetches remote tags from `origin`, creates the next patch tag on `origin/main` (for example `v0.0.1` -> `v0.0.2`), pushes the tag, then packages that exact commit. If the latest tag already points to `origin/main`, packaging is skipped. To package an existing tag without creating a new one, pass it explicitly:

```bash
./scripts/package.sh v0.0.2
```

Set `PACKAGE_REMOTE=upstream` to package from another remote. The script builds in a temporary Git worktree, installs frontend dependencies, builds static assets, installs production Composer dependencies and writes the package to `output/`.

The package excludes `.env`, `node_modules`, `package-lock.json`, Git history and runtime logs.

### 2. Install Ubuntu dependencies

The repository only includes `.env.template` as the configuration template. Copy it to `.env` and fill in deployment-specific values. `.env` is the active configuration, contains secrets, is ignored by Git, and must never be committed. SMTP and other business settings are configured by an administrator in the web console.

After extracting the package on Ubuntu, run:

```bash
sudo ./scripts/ubuntu-deps.sh --install
./scripts/ubuntu-deps.sh --check
```

This installs PHP 8.4, Swoole, Composer, LDAP/GMP and the other required PHP extensions, MySQL/Redis client tools, and build/runtime tools. `scripts/starter.sh` does not install PHP by itself.

### 3. Configure and initialize

```bash
cp .env.template .env
# Edit .env with APP_URL, MySQL, Redis, Manticore and LaravelS settings.
./scripts/install.sh
```

At minimum configure `APP_ENV=production`, `APP_DEBUG=false`, the MySQL and Redis endpoints, and:

```dotenv
RUNTIME_DRIVER=yeying
LARAVELS_LISTEN_IP=127.0.0.1
LARAVELS_LISTEN_PORT=2222
```

When LaravelS runs on the host, configure `DB_HOST`, `DB_PORT`, `REDIS_HOST` and `REDIS_PORT` with addresses reachable from the host. The project does not start MySQL, Redis or other shared middleware for you.

### 4. Start and manage

```bash
./scripts/starter.sh start
./scripts/starter.sh status
./scripts/starter.sh restart
./scripts/starter.sh stop
```

Logs are written to `storage/logs/starter.log` and `storage/logs/laravel.log`.

### 5. Health checks

The default command checks readiness. Use `all` to include required dependencies or `json` for automation:

```bash
./scripts/health-check.sh
./scripts/health-check.sh --level all
./scripts/health-check.sh --level all --format json
```

- `liveness` checks the LaravelS PID when available and calls `/health`.
- `readiness` verifies that LaravelS can serve application requests.
- `dependency` runs a read-only `SELECT 1` against MySQL and `PING` against Redis.
- `all` runs liveness, readiness and dependency checks in order.

MySQL and Redis are required dependencies. The script never starts, stops or repairs shared middleware. `HEALTH_BASE_URL`, `HEALTH_TIMEOUT` and `HEALTH_RETRIES` can override the defaults.

Use Caddy or Nginx in front of LaravelS for HTTPS, WebSocket forwarding, upload limits and static asset caching. Keep the application listener on `127.0.0.1` in production.

## Operations

```bash
./cmd repassword                 # Reset the first admin user's password
./cmd repassword 1               # Reset by user ID
./cmd repassword user@example.com # Reset by email
./cmd ensure-admin               # Create or repair the default admin if none exists
./cmd help
```

`./cmd repassword` without arguments only targets users whose `identity` contains the `admin` marker. If it prints `错误：未找到管理员用户！`, do not reset an arbitrary first user. Grant administrator identity to the intended account first.

`./cmd repassword` uses the MySQL connection in `.env` and requires a local `mysql` client. On Ubuntu, `scripts/ubuntu-deps.sh --install` installs it. The command never guesses old project container names; container usage requires an explicit `MYSQL_CONTAINER=mysql`.

`./scripts/install.sh` automatically runs the admin bootstrap after migrations. If you need to repair an existing deployment, run:

```bash
./cmd ensure-admin
```

If an administrator already exists, the command prints it and leaves passwords unchanged. If no administrator exists, it creates or repairs `admin@yeying.com`, prints a one-time initial password, and requires password change on first login.

Back up MySQL, persistent uploads and production configuration before upgrades. Follow `docs/生产升级操作手册.md` for the production upgrade sequence.

For versioned releases behind a `project` symlink, keep runtime data outside the release directory. Run installation with the same shared directory for every version:

```bash
YEYING_SHARED_DIR=/opt/deploy/shared/project ./scripts/install.sh
```

This preserves and links `.env`, `storage/` and `public/uploads/`. In local storage mode, `public/uploads/` is the persistent file source of truth and must be shared across releases. In S3 mode, the S3 bucket/prefix is the persistent source of truth; `public/uploads/tmp/` and other local working files remain disposable. Do not create each release with an independent runtime data directory.

For S3 migration, follow `docs/S3存储迁移操作手册.md`; do not switch `FILE_STORAGE_DISK=s3` until the manifest-backed migration and final delta are verified.

## License

MIT License. Third-party components remain under their respective licenses.
