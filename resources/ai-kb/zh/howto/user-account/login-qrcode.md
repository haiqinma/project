---
id: user-account.login-qrcode.howto
title: 扫码登录
type: howto
feature: user-account
scope: end-user
locale: zh
aliases:
  - 扫码登录
  - 二维码登录
  - 通行证扫码登录
  - 扫码登陆
  - 怎么扫码登录
  - 网页扫码
  - App 扫码登 PC
related_tools: []
related_pages: []
prerequisites:
  - 被登录端处于登录页
  - 已配置通行证登录时，可使用手机相机或夜莺钱包扫码确认
  - 未配置通行证登录时，需要一端已登录，作为旧版扫码端
negative:
  - 通行证二维码只包含一次性登录会话，不包含 Project 登录 token
  - 旧版本地二维码 code 30 秒内有效；过期需刷新登录页重新生成
  - 旧版本地二维码 code 必须 ≥ 32 字符，被篡改/截断会报「参数错误」
  - 不支持「同一二维码多端复用」，扫码成功并被消费后立即失效
last_verified: v0.0.2
---

# 扫码登录

## 是什么
YeYing 登录页支持扫码登录。配置 Node Passport 后，登录页优先生成「通行证扫码登录」二维码，用户可以用手机相机或夜莺钱包扫码，在手机上确认后让当前 Project 页面登录。

如果部署未配置通行证登录，系统会回退到旧版 Project 本地二维码：已登录的另一端扫码后，让登录页直接登入对应账号。旧版底层接口为 `api/users/login/qrcode`。

## 操作步骤
1. **被登录端**：打开登录页，切到「通行证扫码登录」，自动生成二维码
2. **手机端**：用手机相机、夜莺钱包或已登录客户端扫描二维码
3. **确认登录**：通行证模式下在手机确认页核对应用和域名；旧版模式下在已登录客户端确认登录
4. **完成登录**：确认后被登录端自动跳转到首页

## 通行证接口流程（了解原理用）
- 被登录端：调用 `api/passport/login/session` 创建 Node Passport 登录会话并展示二维码
- 手机端：打开 Node Passport 授权页 `/passport/authorize?requestId=...`，用 Passkey/指纹完成确认
- 被登录端：周期性调用 `api/passport/login/status`，确认通过后 Project 用一次性 code 换取本地用户 token；同一浏览器使用本机通行证登录时，回调页会通知登录页立即完成状态检查
- 未配置 `PASSPORT_NODE_URL` 或通行证服务不可用时，前端回退旧版本地扫码登录

## 部署注意
- `PASSPORT_NODE_URL` 必须是手机浏览器可访问的 Node 地址，不能填只有服务器本机可访问的 `localhost`
- `PASSPORT_CLIENT_ID` 必须填写 Node 应用中心里 Project 应用发布后生成的应用 ID（`applications.uid` UUID）
- `APP_URL` 必须是 Node 回调 Project 时可访问的 Project 地址，否则手机确认后电脑端无法完成登录

## 旧版本地接口流程（兼容）
- 被登录端：周期性调用 `users/login/qrcode?type=status&code=xxx`，返回 `success` + user 即代表扫码端确认
- 扫码端：扫码后调用 `users/login/qrcode?type=login&code=xxx`，把当前用户与该 code 绑定
- code 在 Redis 缓存中，TTL 30 秒；超时未确认需刷新二维码

## 不支持
- 二维码不能截图给别人扫；扫码确认即视为本人授权登录
- 旧版本地二维码不能用「未登录设备」反向扫「已登录设备」生成的码，方向必须是已登录端扫被登录端
- 通行证确认成功但钱包未绑定 Project 账号时，不能绕过 Project 账号绑定和业务权限

## 相关
- 普通密码登录：[[user-account.login.howto]]
- 图形验证码：[[user-account.login-codeimg.howto]]
