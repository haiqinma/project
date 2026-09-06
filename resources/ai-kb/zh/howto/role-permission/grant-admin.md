---
id: role-permission.grant-admin.howto
title: 授予 / 取消系统管理员身份
type: howto
feature: role-permission
scope: super-admin
locale: zh
aliases:
  - 设为管理员
  - 给管理员权限
  - 添加管理员
  - 创建默认管理员
  - ensure-admin
  - repassword
  - 重置管理员密码
  - setadmin
  - clearadmin
  - 取消管理员
  - 怎么让别人当管理员
related_tools: []
related_pages: []
prerequisites:
  - 操作账号必须已经是系统管理员（admin identity）
  - 目标账号必须存在且未被禁用
negative:
  - 不能给临时账号（temp identity）授予管理员身份前，请先取消其 temp 标记
  - 不能取消超级管理员（id=1）的管理员身份，超管始终自带 admin
  - 普通用户无法自助申请，必须由现任管理员授予
last_verified: v0.0.2
---

# 授予 / 取消系统管理员身份

## 入口
- 桌面端：右上角头像 → 「团队管理」 → 选中用户 → 「设为管理员」/「取消管理员」
- 移动端：移动端无团队管理入口，需在桌面端操作
- 部署运维：服务器项目目录执行 `./cmd ensure-admin`，仅用于没有任何管理员时创建/修复默认管理员

## 操作步骤
1. 用系统管理员账号登录
2. 进入团队管理（左侧搜索 / 筛选用户）
3. 点击目标用户行的更多操作
4. 选择「设为管理员」或「取消管理员」
5. 后端调用 `POST api/users/operation`：
   - `setadmin`：把 `'admin'` 字符串添加到目标用户 `identity` 数组
   - `clearadmin`：从 `identity` 数组移除 `'admin'`

## 第一个管理员
首次部署执行 `./scripts/install.sh` 后，系统会自动运行管理员初始化。若库里没有任何 `identity` 含 `admin` 的用户，会创建或修复 `admin@yeying.com`，输出一次性初始密码，并设置首次登录必须改密。已有管理员时不会改密码。

如果旧部署已经没有管理员，服务器项目目录执行 `./cmd ensure-admin`。不要把 `./cmd repassword` 改成回退第一个用户；`repassword` 只负责重置已有管理员或显式指定的用户。

如果只是忘记管理员密码，服务器项目目录执行 `./cmd repassword`、`./cmd repassword 用户ID` 或 `./cmd repassword 邮箱`。该命令优先使用本机 `mysql` 客户端；本机没有 `mysql` 时，会自动使用当前 `docker-compose.yml` 的 `mysql` 服务或运行中的 `mysql` 容器。非默认容器可用 `MYSQL_CONTAINER=容器名 ./cmd repassword ...` 指定。

## 生效时间
- 后端立即生效（数据库直接更新 `users.identity`）
- 前端要等目标用户下次刷新或重新登录，`store.state.userIsAdmin` 才会更新
- WebSocket 在线用户会收到资料更新通知

## 谁能操作
- **超级管理员**：可操作任意用户（除自己）
- **系统管理员**：可操作其他用户，但不能操作超管（被 `checkSystem(1)` 拦截）
- **普通用户**：不可操作

## 与其它身份的关系
- `identity` 是字符串数组，可同时含 `admin`、`temp`、`disable` 等。授予 admin 不会清除其它标记
- 改 admin 身份不影响项目 / 任务级权限。详见 [[role-permission.admin.concept]]
- 想知道遇到「权限不足」该联系谁，见 [[role-permission.permission-denied.faq]]
