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

访问令牌用于 Codex、Claude CLI 和本地脚本通过 AK/SK 签名访问 Project。每个令牌绑定当前用户、具体项目和有效期，是当前用户在所选项目内的另一种认证方式。

## 入口

- 桌面端 Web / Electron：右上角头像 →「设置」→「访问令牌」
- 路由：`/manage/setting/automation-token`

## 创建步骤

1. 点击「创建令牌」，填写便于识别的名称。
2. 选择至少一个当前账号参与的项目。
3. 选择 7、30 或 90 天有效期并创建。
4. 立即保存 Access Key 和 Secret Key。Secret Key 只展示一次，关闭弹窗后无法再次查看。

## 管理与限制

- 「禁用」会让令牌立即失效；「删除」不可恢复。
- 「轮换密钥」会生成新的 Secret Key，旧 Secret Key 立即失效，新密钥仍只展示一次。
- 标准业务 API 同时支持网页登录态和 AK/SK；令牌请求复用当前用户已有的项目角色、任务可见性和操作权限。
- 令牌仅能访问所选项目。用户、组织、系统、License、应用市场和令牌管理等非项目级高风险接口不接受 AK/SK。
