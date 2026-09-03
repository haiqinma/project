---
id: file.preview.concept
title: 文件预览类型
type: concept
feature: file
scope: end-user
locale: zh
aliases:
  - 文件能不能预览
  - 在线看文件
  - 文档预览
  - 支持哪些文件预览
  - excel 能直接看吗
related_tools: [get_file_detail, fetch_file_content]
related_pages: [file]
prerequisites: []
negative:
  - office 类（doc/xls/ppt 等）预览需要管理员在应用市场安装 office 插件（OnlyOffice）
  - OnlyOffice 文档服务不可达、JWT 密钥不一致或回调地址不可达时，Office 文件无法在线打开或保存
  - 其他格式（PDF / CAD / OFD 等）预览需要 fileview 插件
  - 未安装对应插件时打开会提示「未安装」，但仍可下载
  - 视频 / 音频不在文件预览里直接播放，会触发下载
last_verified: v0.0.2
---

# 文件预览类型

## 定义
文件预览指在浏览器内不下载即可查看的能力。YeYing 不同文件类型走不同预览管线，依赖不同插件。

## 浏览器直接预览（无需插件）
- **document**：内置在线文档（Markdown 渲染器），支持 Mermaid 代码块预览；配置内网 PlantUML Server 后支持 PlantUML 代码块预览
- **txt / code**：纯文本与代码高亮
- **picture**：jpg / png / webp / gif / bmp 直接渲染
- **mind**：思维导图（需 minder 插件，但产品默认随包提供）
- **drawio**：流程图（需 drawio 插件）

## Markdown 图表
- Mermaid：使用前端内置 Mermaid 渲染。
- PlantUML：浏览器端不内置完整 PlantUML 引擎；管理员配置 `PLANTUML_SERVER_URL` 为同源内网反代路径（如 `/plantuml`）后，`plantuml` / `puml` 代码块渲染为 SVG。未配置时按普通代码块显示，系统不会自动请求公网 PlantUML 服务。

## 需安装插件预览
- **word / excel / ppt**：内置类型，但实际渲染由 OnlyOffice 完成，需 office 插件和可访问的 OnlyOffice Docs 服务
- **csv / tsv / xls / xlsx / ods**：按电子表格处理。系统会优先把真实扩展名传给 OnlyOffice，避免 CSV 被当成 xlsx 打开
- **pdf / cad / ofd / tif** 等：由 fileview 插件提供
- **wps**：fileview 插件

## OnlyOffice 配置要求
- 私有化或本地部署可通过 `OFFICE_ENABLED=true` 显式启用外部 OnlyOffice Docs 服务；如果通过应用市场安装 `office` 插件，也会自动视为可用
- 浏览器需要能访问 OnlyOffice Docs API 脚本；生产通常通过同源 `/office/web-apps/apps/api/documents/api.js` 反向代理，特殊部署可配置 `OFFICE_PUBLIC_API_URL`
- OnlyOffice 服务需要能访问 Project 文件下载和保存回调 API；容器部署默认使用 `http://nginx/api`，本地直跑可配置 `OFFICE_INTERNAL_API_BASE`
- Project 后端保存编辑结果时需要能下载 OnlyOffice 回调里的文件地址；特殊网络可配置 `OFFICE_INTERNAL_DOCUMENT_BASE`
- OnlyOffice JWT 密钥必须与 Project 签发 token 使用的密钥一致，否则编辑器初始化或保存会失败

## 不支持预览的类型
- **archive**（zip / rar / 7z 等）：只能下载，无在线解压
- **media**（mp3 / mp4 / mov 等）：从文件入口直接下载，预览能力依赖浏览器原生
- **axure（rp）**：仅记录类型，需用 Axure 客户端查看

## 在线编辑 vs 仅预览
- 在线编辑：document、word/excel/ppt（office 插件）、mind、drawio
- 仅预览：pdf、其他 fileview 支持的类型
- 编辑权限受 FileUser 权限位（读写 / 只读）控制
