#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  cat <<'EOF'
使用方法:
  ./cmd repassword [账号标识符] [自定义密码]

参数说明:
  [账号标识符]: 可选，可以是用户ID(纯数字)或邮箱地址。不提供时默认为第一个 identity 包含 admin 的管理员用户
  [自定义密码]: 可选，指定要设置的新密码。不提供时会自动生成随机密码

使用示例:
  ./cmd repassword
  ./cmd repassword 123
  ./cmd repassword user@example.com
  ./cmd repassword 123 newpass
  ./cmd repassword user@example.com newpass

说明:
  命令使用 .env 中的 MySQL 配置。
  若本机没有 mysql 客户端，会自动使用当前 docker-compose 的 mysql 服务或运行中的 mysql 容器。
  如需指定容器，可设置 MYSQL_CONTAINER=mysql。
EOF
  exit 0
fi

[[ -f "$root_dir/.env" ]] || { echo "缺少 $root_dir/.env，请先配置数据库连接。" >&2; exit 1; }
env_value() {
  local key="$1"
  sed -n "s/^${key}=//p" "$root_dir/.env" | head -n 1 | sed -e 's/^['\''"]//' -e 's/['\''"]$//'
}

export MYSQL_HOST="$(env_value DB_HOST)"
export MYSQL_PORT="$(env_value DB_PORT)"
export MYSQL_USER="$(env_value DB_USERNAME)"
export MYSQL_PASSWORD="$(env_value DB_PASSWORD)"
export MYSQL_DATABASE="$(env_value DB_DATABASE)"
export MYSQL_PREFIX="$(env_value DB_PREFIX)"

: "${MYSQL_HOST:?DB_HOST 未配置}"
: "${MYSQL_PORT:?DB_PORT 未配置}"
: "${MYSQL_USER:?DB_USERNAME 未配置}"
: "${MYSQL_DATABASE:?DB_DATABASE 未配置}"
: "${MYSQL_PREFIX:=}"

docker_container_running() {
  local container="$1"
  [[ -n "$container" ]] || return 1
  [[ "$(docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null || true)" == "true" ]]
}

detect_mysql_container() {
  local container=""

  if docker compose version >/dev/null 2>&1; then
    container="$(docker compose -f "$root_dir/docker-compose.yml" --project-directory "$root_dir" ps -q mysql 2>/dev/null | head -n 1 || true)"
    if docker_container_running "$container"; then
      printf '%s\n' "$container"
      return 0
    fi
  fi

  if command -v docker-compose >/dev/null 2>&1; then
    container="$(docker-compose -f "$root_dir/docker-compose.yml" --project-directory "$root_dir" ps -q mysql 2>/dev/null | head -n 1 || true)"
    if docker_container_running "$container"; then
      printf '%s\n' "$container"
      return 0
    fi
  fi

  if docker_container_running mysql; then
    printf '%s\n' mysql
    return 0
  fi

  container="$(docker ps --filter label=com.docker.compose.service=mysql --format '{{.ID}}' 2>/dev/null | head -n 1 || true)"
  if docker_container_running "$container"; then
    printf '%s\n' "$container"
    return 0
  fi

  return 1
}

if ! command -v mysql >/dev/null 2>&1; then
  command -v docker >/dev/null 2>&1 || {
    echo "未找到 mysql 客户端，也未找到 Docker 命令，无法使用容器内 MySQL 客户端。" >&2
    exit 1
  }

  mysql_container="${MYSQL_CONTAINER:-}"
  if [[ -z "$mysql_container" ]]; then
    mysql_container="$(detect_mysql_container || true)"
  fi

  [[ -n "$mysql_container" ]] || {
    echo "未找到 mysql 客户端，也未找到运行中的 MySQL 容器。请启动 MySQL 容器，或显式设置 MYSQL_CONTAINER=mysql 后重试。" >&2
    exit 1
  }

  if [[ "$(docker inspect -f '{{.State.Running}}' "$mysql_container" 2>/dev/null || true)" != "true" ]]; then
    echo "MYSQL_CONTAINER 指定的容器不存在或未运行: $mysql_container" >&2
    exit 1
  fi

  export MYSQL_CONTAINER="$mysql_container"
  export MYSQL_HOST=127.0.0.1
  export MYSQL_PORT=3306
  echo "未找到本机 mysql 客户端，已使用 MySQL 容器: $MYSQL_CONTAINER" >&2
fi

exec sh "$root_dir/docker/mysql/repassword.sh" "$@"
