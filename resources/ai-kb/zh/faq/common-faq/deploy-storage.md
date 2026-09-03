---
id: common-faq.deploy-storage.faq
title: 磁盘占满 / YeYing 数据目录变大怎么办
type: faq
feature: common-faq
scope: admin
locale: zh
aliases:
  - 磁盘满了
  - 数据目录太大
  - public/uploads 太大
  - mysql 备份占空间
  - 日志文件过大
  - YeYing 越用越大
  - 清理空间
  - Warehouse S3 文件存储
  - FILE_STORAGE_DISK
  - persistent-storage:migrate-s3
  - persistent-storage:cleanup-local
related_tools: []
related_pages: []
prerequisites:
  - 部署机能 root 操作主机文件系统
negative:
  - 不要手动 rm public/uploads/ 下的文件，会导致已上传附件 404
  - 不要清空数据库表，应通过任务回收站、消息删除等业务操作清理
  - mysql 日志（binlog）不在自动备份里，需另行处理
last_verified: v0.0.2
---

# 磁盘占满 / YeYing 数据目录变大怎么办

## 问题
服务器磁盘越来越满，YeYing 部署目录占了大头，想清理释放空间。

## 主要占用点
按通常大小排序：

| 目录 | 内容 | 能否清理 |
|---|---|---|
| `public/uploads/` | 附件、头像 | 不能直接删，走业务回收站 |
| `docker/mysql/data/` | 数据库 | 不能直接删 |
| `docker/mysql/backup/` | 数据库自动备份 | 可以，保留近几份 |
| `storage/logs/`、`docker/log/` | Laravel/nginx/php 日志 | 可以，按日轮转 |
| `public/uploads/tmp/` | 上传临时文件 | 可以，定时清理 |

## Warehouse S3 文件存储
Project 可设置 `FILE_STORAGE_DISK=s3` 将持久化上传写入 Warehouse S3 兼容服务。`S3_BUCKET=services`、`S3_PREFIX=project` 对应 Warehouse 凭证范围 `/services/project`；`S3_PATH_STYLE=true` 必须保留。

- `FILESYSTEM_DRIVER` 保持 `local`，不要全局切换为 `s3`。
- `FILE_STORAGE_DISK=local` 时，持久化文件只以 `public/uploads` 为准；`FILE_STORAGE_DISK=s3` 时，持久化文件只以 S3 bucket/prefix 为准。
- `uploads/tmp/` 和 `uploads/desktop-draft/` 是本地临时目录，不迁移到 S3，也不能作为业务数据引用。
- S3 模式下，预览、下载、图片处理、压缩打包需要本地路径时，只使用 `storage/app/tmp` 下的一次性临时文件，不恢复到 `public/uploads`。
- `.env` 的 `LOCAL_STORAGE_DIR` 可指定 Laravel `storage/` 的实际目录。留空时使用项目内的 `storage/`；生产可配置固定绝对路径，并在每个新 release 执行 `scripts/install.sh` 迁移内容和创建整体目录链接。该配置不控制 `run/` 和 `public/uploads/`。
- 不要在同一实例中长期保留“一部分本地、一部分 S3”的混合状态。
- 外部 Nginx 若用正则规则直接处理图片、JS、CSS 等静态文件，必须为 `/uploads/` 设置更高优先级的 location，并在本地文件不存在时回退 LaravelS；否则 S3 中存在的聊天图片和附件会被 Nginx 直接返回 404。

## 迁移历史持久化文件
从本地切换到 S3 必须先迁移历史持久化文件，不能只改 `.env`。管理员应先执行检查命令：

```bash
./cmd artisan persistent-storage:migrate-s3
```

该命令默认仅检查，不上传、不写 manifest。确认输出后，执行：

```bash
./cmd artisan persistent-storage:migrate-s3 --execute --manifest=storage/app/persistent-storage-migration/manifest.jsonl
```

命令只扫描注册的持久化命名空间，跳过 `uploads/tmp/`、`uploads/desktop-draft/` 等本地临时目录；每个对象上传到 S3 后会读回校验大小和 SHA-256，并把 key、size、sha256 写入 JSONL manifest。

迁移切换顺序是：保持 `FILE_STORAGE_DISK=local` 执行首轮迁移；停写或进入维护窗口；再次执行迁移同步最终增量；修改 `.env` 为 `FILE_STORAGE_DISK=s3`；清配置并重启 LaravelS；验证图片、下载、聊天、任务、桌面包和 Office 流程。

确认 S3 模式稳定后，再用 manifest 清理本地持久化文件：

```bash
./cmd artisan persistent-storage:cleanup-local storage/app/persistent-storage-migration/manifest.jsonl
./cmd artisan persistent-storage:cleanup-local storage/app/persistent-storage-migration/manifest.jsonl --execute
```

清理命令要求当前已经是 `FILE_STORAGE_DISK=s3`，会同时校验本地文件和 S3 对象都与 manifest 的 size/SHA-256 一致，才删除本地文件。`file-storage:migrate-s3` 只是历史 FileCenter 兼容命令，新迁移应使用 `persistent-storage:migrate-s3`。

## 解决

**清理 mysql 自动备份（保留 7 天）**
```bash
find ./docker/mysql/backup -name "*.sql.gz" -mtime +7 -delete
```

**清理临时上传、日志**
```bash
find ./public/uploads/tmp -mtime +1 -delete
find ./storage/logs ./docker/log -name "*.log" -mtime +30 -delete
```

**业务侧清理**
- 任务 / 文件回收站：超 30 天自动清理（不可恢复）
- 大附件：找到大文件先归档外存

**数据库瘦身（慎用）**
- 老旧消息归档（先备份）
- 删除离职用户的设备记录、session

## 不要做
- 不要 `rm -rf public/uploads/*`，所有附件会 404
- 不要直接清空 `users` 等核心表，依赖关系会断

## 相关
- 备份：[[common-faq.deploy-backup.faq]]
