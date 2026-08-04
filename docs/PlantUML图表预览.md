# PlantUML 图表预览

Project 的 Markdown 在线文档可以把 `plantuml` / `puml` 代码块渲染为图形，但渲染必须依赖自建 PlantUML Server。Project 不默认连接公网 PlantUML 服务。

## 推荐架构

```text
Browser -> Project Nginx /plantuml/* -> Internal PlantUML Server
```

浏览器必须访问一个自己可达的地址。不要把 `.env` 配成 `http://plantuml:8080` 这类容器内网地址，因为浏览器无法解析 Docker 内网服务名。

## 部署 PlantUML Server

可以把 PlantUML Server 作为共享中间件部署，不由 Project 启停。示例：

```bash
docker run -d \
  --name plantuml \
  --restart unless-stopped \
  -p 127.0.0.1:18080:8080 \
  plantuml/plantuml-server:jetty
```

该端口只监听本机回环地址，避免直接暴露到公网。

## Nginx 反向代理

在 Project 所在域名下暴露同源路径，例如：

```nginx
location /plantuml/ {
    proxy_pass http://127.0.0.1:18080/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

这样浏览器访问的是 `https://project.example.com/plantuml/svg/...`，实际渲染在内网 PlantUML Server 完成。

## Project 配置

`.env` 中配置浏览器可访问的 PlantUML 根路径：

```env
PLANTUML_SERVER_URL=/plantuml
```

如果本地直接访问 PlantUML Server 映射端口，不经过 Nginx 反代，则不要带 `/plantuml` 前缀：

```env
PLANTUML_SERVER_URL=http://127.0.0.1:18080
```

修改 `.env` 后需要清理配置缓存并重启本地 LaravelS/Swoole：

```bash
./cmd artisan config:clear
./cmd local-stop
./cmd local-start
```

`./cmd local-start` 只负责启动后端 LaravelS/Swoole，不会重新生成浏览器加载的前端 JS。修改前端源码后，需要让浏览器加载到新的前端资源；开发调试可由用户自行启动 Vite dev server，发版场景则通过正式构建流程生成静态资源。

## Markdown 写法

支持 fenced 代码块：

````markdown
```plantuml
Alice -> Bob: hello
```
````

也支持完整 PlantUML 块：

```text
@startuml
Alice -> Bob: hello
@enduml
```

如果 `PLANTUML_SERVER_URL` 为空，PlantUML 内容会按普通代码块显示，不会自动请求公网服务。

## 与 OnlyOffice 的关系

PlantUML 和 OnlyOffice 属于同一类问题：主程序只负责文件、权限和页面接入，具体渲染能力由外部服务或应用提供。

- PlantUML：由自建 PlantUML Server 把文本图表渲染成 SVG。
- OnlyOffice：由 office 插件 / OnlyOffice 服务提供 Word、Excel、PPT 在线预览和编辑。

因此，提示「OnlyOffice 未安装」不是 Markdown 渲染问题，而是 office 应用能力尚未部署或接入。
