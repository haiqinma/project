---
id: user-settings.automation-audit.howto
title: 查看访问令牌审计
type: howto
feature: user-settings
scope: admin
locale: zh
aliases:
  - 怎么查谁用了访问令牌
  - AK SK 调用记录在哪里
  - 查看令牌认证失败记录
related_tools: []
related_pages: [setting]
prerequisites:
  - 当前账号是系统管理员
negative:
  - 审计页不展示 Secret Key
  - 普通用户不能访问全局审计页
last_verified: v0.0.2
---

# 查看访问令牌审计

## 入口

- 桌面端 Web / Electron：右上角头像 →「设置」→「访问令牌审计」
- 路由：`/manage/setting/automation-audit`
- 仅系统管理员可访问。

## 查询方法

1. 按 action 过滤操作类型，例如 `task.update`、`task.status`、`file.download` 或 `auth.verify`。
2. 按 result 过滤成功、签名失败、nonce 重放、限流或越权记录。
3. 可按用户 ID 精确查询，结果包含令牌、资源、IP、客户端和发生时间。

## 安全限制

- 审计页只显示 Access Key 和令牌名称，不保存、不显示 Secret Key。
- 令牌被删除后，历史审计仍保留用户、操作、资源和请求来源信息。
