---
id: user-settings.automation-token.howto
title: 创建和管理访问令牌
type: howto
feature: user-settings
scope: end-user
locale: zh
aliases:
  - 怎么给 Codex 创建访问令牌
  - 自动化脚本怎么访问项目任务
  - 在哪里创建 AK SK
  - 如何禁用机器访问密钥
related_tools: []
related_pages: [setting]
prerequisites:
  - 已登录 Project
  - 至少参与一个项目
negative:
  - Secret Key 只在创建成功时展示一次，之后不能查看
  - 自动化令牌不能访问未授权项目或绕过原有任务可见性
  - 自动化令牌不支持管理员级、用户管理和系统设置权限
last_verified: v0.0.2
---

# 创建和管理访问令牌

访问令牌用于 Codex、Claude CLI 和本地脚本通过 AK/SK 签名访问 Project。每个令牌绑定当前用户、具体项目、权限范围和有效期，不能替代网页登录态。

## 入口

- 桌面端 Web / Electron：右上角头像 →「设置」→「访问令牌」
- 路由：`/manage/setting/automation-token`

## 创建步骤

1. 点击「创建令牌」，填写便于识别的名称。
2. 选择至少一个当前账号参与的项目。
3. 选择权限范围；默认只选择项目读取和任务读取，评论等写权限需主动勾选。
4. 选择 7、30 或 90 天有效期并创建。
5. 立即保存 Access Key 和 Secret Key。Secret Key 只展示一次，关闭弹窗后无法再次查看。

## 管理与限制

- 「禁用」会让令牌立即失效；「删除」不可恢复。
- 「轮换密钥」会生成新的 Secret Key，旧 Secret Key 立即失效，新密钥仍只展示一次。
- 自动化 API 每次同时检查令牌 scope、项目授权、项目成员关系和任务可见性。
- 按 scope 可读取项目、任务和文件，追加评论，更新标题、描述、标签、优先级、时间等普通字段，以及任务流程状态和完成状态。
- 不支持通过自动化令牌管理用户、系统设置、任务负责人、任务可见性、归档或跨项目移动。
