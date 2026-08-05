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

This command runs the production frontend build and updates `public/js/build/`. `./cmd local-start` serves the existing static assets from `public/js/build/`; it does not compile Vue source files. After changing files under `resources/assets/js/`, run `npm run build` and refresh the browser to see the updated local page. `./cmd build` and `./cmd prod` are equivalent wrappers for the same frontend asset build.

## Deployment and Operations

Production deployment, operations and upgrade details are maintained in Chinese at [docs/部署手册.md](./docs/部署手册.md).

## License

MIT License. Third-party components remain under their respective licenses.
