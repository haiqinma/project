---
id: appstore.concept
title: 应用市场是什么
type: concept
feature: appstore
scope: admin
locale: zh
aliases:
  - 应用商店
  - 应用市场
  - AppStore
  - 插件市场
  - 装插件在哪
  - 插件管理
related_tools: []
related_pages: [application]
prerequisites:
  - 需要系统管理员权限
negative:
  - 不是 iOS / Android 那种第三方应用商店，仅装 YeYing 内部插件
  - 普通成员看不到「应用商店」入口
  - 不支持单用户安装，所有插件对全员生效
last_verified: v0.0.1
---

# 应用市场是什么

## 定义
应用市场（AppStore）是 YeYing 的插件管理后台，让系统管理员一键安装 / 卸载 / 更新各种功能插件，例如 AI 助手、审批、签到、OnlyOffice 等。`appstore` 名称现在是兼容入口；真正的安装、升级、失败回滚和卸载由 Agent Runtime 执行，Node 只作为社区发布目录和授权入口。

## 关键属性
- **微应用形态**：通过 `MicroApps` 加载 iframe，URL 为 `appstore/internal`
- **后端校验**：`App\Module\Apps::isInstalled($appId)` 读取 `docker/appstore/config/{appId}/config.yml` 中 `status: installed` 判断
- **未安装会抛 ApiException**：`Apps::isInstalledThrow()` 提示「应用「X」未安装」
- **社区发布链路**：Project 通过 `api/appstore/*` 兼容入口转发到 Agent；Agent 从 Node 查询 release artifact、签名和版本信息
- **运行控制面**：Agent 负责安装任务、升级、失败回滚、卸载、健康检查和运行状态；Project 不在 Web 请求里拉镜像或启动容器
- **生命周期 Hook**：用户创建 / 离职会调 `dispatchUserHook` 通知 Agent，再由 Agent 分发给相关插件（user_onboard / offboard / update）

## 运维 dry-run
新部署应配置 `AGENT_INTERNAL_URL`、`AGENT_INSTANCE_ID` 和 `AGENT_INTERNAL_TOKEN`。旧的 `APPSTORE_INTERNAL_URL` 仅作为兼容名保留；如果继续使用，也应指向 Agent Runtime，而不是直接指向 Node。

## 插件类型
- 官方内置：ai、approve、checkin/face、office、drawio、minder、okr、search（manticore）、fileview
- 社区插件：以 `community_` 前缀命名，如 `community_kuaifan_memos`、`community_kuaifan_kpi`、`community_Learntotolearn_roomly`

## 与「微应用菜单」的区别
- **应用市场**：管理插件「装/卸/更新」的容器
- **微应用菜单**：插件装好后注册到「应用」页的菜单项，普通成员可见的入口

## 不支持
- 不支持卸载 `appstore` 自身（`isInstalled('appstore')` 强制返回 true）
- 不支持普通成员浏览未装插件列表
- 不支持安装非 YeYing 兼容的任意 Docker 镜像

## 相关
- 安装：[[appstore.install.howto]]
- 卸载：[[appstore.uninstall.howto]]
- 入口：[[appstore.entry.menu-map]]
