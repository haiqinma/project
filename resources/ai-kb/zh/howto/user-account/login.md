---
id: user-account.login.howto
title: 登录账号
type: howto
feature: user-account
scope: end-user
locale: zh
aliases:
  - 怎么登录
  - 登录 YeYing
  - 登录方式
  - 怎么进系统
  - 登不上
  - 密码登录
related_tools: []
related_pages: []
prerequisites: []
negative:
  - 邮箱、密码各自最长 32 字符，超过提示「帐号或密码错误」
  - 账号被停用（disable_at 非空）会提示「帐号已停用」，需联系管理员
  - 开启「注册需邮箱验证」时，未验证邮箱的账号无法登录，必须先完成验证（[[user-account.email-verify.howto]]）
  - 多次失败后系统会强制要求填验证码（[[user-account.login-codeimg.howto]]）
last_verified: v0.0.2
---

# 登录账号

## 入口
- 登录页：`/login`
- 客户端启动时自动跳转

## 支持的登录方式
YeYing 同时支持以下登录方式（在登录页可切换）：

1. **邮箱 + 密码**：默认方式
2. **钱包登录**：安装夜莺钱包插件后，点击「钱包登录」按钮，通过 Wallet Identity V1 presentation 完成登录。Wallet 使用本地有效凭证生成并签名 presentation，Project 后端本地验证 DID、scope、issuer 和凭证有效期；正常登录不要求实时访问 Node。首次钱包登录需设置并验证邮箱。

钱包登录的解锁、连接、身份资料授权、presentation 出示及账户绑定签名页面顺序，统一遵循 web3-bs《钱包登录统一流程》文档。
3. **通行证扫码登录**：使用夜莺钱包 App 扫描登录页二维码确认登录，无需钱包插件；通过 Wallet Identity authorization code + PKCE 协议完成身份验证。
4. **扫码登录**：客户端/App 已登录后扫码登录另一端，详见 [[user-account.login-qrcode.howto]]
5. **LDAP**：管理员启用 LDAP 时，邮箱+LDAP 密码也可登录，系统自动同步用户
6. **SSO**：管理员配置 OAuth/SAML 后，登录页会出现对应按钮（具体取决于插件）

## 钱包登录与通行证登录的身份模型
- 钱包登录和通行证登录均基于 Wallet Identity V1 协议，跨应用身份主键为 DID（格式为 `did:yeying:wid_*`）。
- 钱包地址是可变的关联凭据，不是永久用户主键；一个钱包身份可关联多个 EVM 或非 EVM 地址。
- 应用通过 `identity.basic`、`identity.wallet`、`identity.username`、`identity.email`、`identity.avatar` scope 按需请求用户身份信息；用户名、邮箱和头像只接受 issuer 签发的已验证凭证（JWT-VC），不信任前端提交值。
- 钱包插件登录需要资料凭证时，Project 后端使用已配置的 issuer 公钥/JWKS 本地验证 presentation 和 JWT-VC。只有钱包发现本地邮箱、用户名或头像凭证过期或临近过期时，才通过 Node issuer endpoint 发起续签；续签失败或 Node 没有可续签的已验证事实时，才需要回钱包重新完成资料验证。Project 是否查询 Node credential status 由自身撤销策略决定。
- 通行证登录（无插件）使用 Wallet Identity authorization code + PKCE 兑换；Project 从 Node 返回的 DID、钱包地址和凭证完成本地登录。

Project 只配置 `PASSPORT_IDENTITY_TRUST_DIR`（生产固定为 `/opt/data/node`）。独立定时任务从 `PASSPORT_NODE_URL` 同步并校验 `issuer-metadata.json`、`jwks.json` 和 `manifest.json`，Project 登录时只读取该目录并校验文件摘要，不实时访问 Node。`PASSPORT_NODE_URL` 同时用于首次账户关联、凭证续签和无插件通行证授权流程。

## 邮箱密码登录步骤
1. 填邮箱、密码
2. 若系统判定需要验证码（API `login/needcode` 返回 need），填图形验证码
3. 提交，成功返回 token 和用户信息

## 登录后行为
- 写入 `last_ip`、`last_at`、`line_ip`、`line_at`
- 生成 token（默认 30 天有效，可在系统设置 token_valid_days 调整）
- 首次登录会自动创建「📝 个人项目」

## 常见错误
- **帐号或密码错误**：邮箱不存在或密码不对；连续错误后会触发验证码
- **请输入验证码 / 请输入正确的验证码**：触发风控，需填图形验证码
- **您还没有验证邮箱**：需先按 [[user-account.email-verify.howto]] 完成验证
- **帐号已停用**：账号被管理员禁用，联系管理员恢复
