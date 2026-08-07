#!/usr/bin/env bash
set -euo pipefail

command="${1:-start}"
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
pid_dir="${YEYING_RUN_DIR:-$root_dir/run}"
log_dir="${YEYING_LOG_DIR:-$root_dir/storage/logs}"
pid_file="$pid_dir/yeying.pid"

env_value() {
  local key="$1"
  [[ -f "$root_dir/.env" ]] || return 0
  sed -n "s/^${key}=//p" "$root_dir/.env" | head -n 1 | sed -e 's/^['\''"]//' -e 's/['\''"]$//'
}

port="${LARAVELS_LISTEN_PORT:-$(env_value LARAVELS_LISTEN_PORT)}"
port="${port:-2222}"
health_url="${YEYING_HEALTH_URL:-http://127.0.0.1:${port}/}"

ensure_dirs() {
  chmod 755 "$root_dir"
  mkdir -p "$pid_dir" "$log_dir" "$root_dir/bootstrap/cache" "$root_dir/storage/framework/cache"
  touch "$log_dir/starter.log"
}

check_runtime() {
  command -v curl >/dev/null 2>&1 || { echo "curl is required for health checks." >&2; exit 1; }
  if ! command -v php >/dev/null 2>&1; then
    cat >&2 <<EOF
PHP 8.4 is not installed. Run these commands first:
  sudo "$root_dir/scripts/ubuntu-deps.sh" --install
  "$root_dir/scripts/install.sh"
EOF
    exit 1
  fi
  if ! php -m | grep -qi '^swoole$'; then
    cat >&2 <<EOF
PHP Swoole extension is not installed. Run:
  sudo "$root_dir/scripts/ubuntu-deps.sh" --install
EOF
    exit 1
  fi
  [[ -f "$root_dir/vendor/autoload.php" ]] || { echo "Missing vendor/autoload.php. Run scripts/install.sh first." >&2; exit 1; }
  php -r 'exit((int) !extension_loaded("swoole"));' || { echo "PHP Swoole extension is required." >&2; exit 1; }
}

run_pending_migrations() {
  echo "Checking database migrations..."
  if ! php "$root_dir/artisan" migrate --force --no-interaction; then
    echo "Database migration failed. YeYing was not started." >&2
    exit 1
  fi
}

is_running() {
  [[ -f "$pid_file" ]] || return 1
  local pid
  pid="$(cat "$pid_file")"
  [[ "$pid" =~ ^[0-9]+$ ]] || return 1
  kill -0 "$pid" 2>/dev/null || return 1
  # A stale PID can be reused by an unrelated system process (for example
  # watchdogd). Verify that the recorded process is this LaravelS instance.
  [[ -r "/proc/$pid/cmdline" ]] || return 1
  tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | grep -q 'laravels'
}

start() {
  ensure_dirs
  check_runtime
  if is_running; then
    echo "YeYing already running (pid $(cat "$pid_file"), port $port)."
    return 0
  fi
  if [[ -f "$pid_file" ]]; then
    echo "Removing stale PID file: $pid_file (pid $(cat "$pid_file" 2>/dev/null || echo unknown))." >&2
    rm -f "$pid_file"
  fi
  if [[ ! -f "$root_dir/.env" ]]; then
    echo "Missing $root_dir/.env. Copy .env.template and configure the database first." >&2
    exit 1
  fi
  cd "$root_dir"
  run_pending_migrations
  nohup php "$root_dir/bin/laravels" start >>"$log_dir/starter.log" 2>&1 &
  echo $! > "$pid_file"
  for _ in {1..30}; do
    if curl -fsS "$health_url" >/dev/null 2>&1; then
      echo "YeYing started (pid $(cat "$pid_file"), port $port)."
      return 0
    fi
    if ! is_running; then
      echo "YeYing failed to start. See $log_dir/starter.log." >&2
      rm -f "$pid_file"
      exit 1
    fi
    sleep 1
  done
  echo "YeYing did not become healthy at $health_url. See $log_dir/starter.log." >&2
  echo "Listening sockets:" >&2
  ss -lntp 2>/dev/null | grep -E ":${port}\b" >&2 || true
  echo "Recent starter log:" >&2
  tail -30 "$log_dir/starter.log" >&2 || true
  exit 1
}

stop() {
  if ! is_running; then
    rm -f "$pid_file"
    echo "YeYing is not running."
    return 0
  fi
  local pid
  pid="$(cat "$pid_file")"
  kill "$pid"
  for _ in {1..50}; do
    kill -0 "$pid" 2>/dev/null || break
    sleep 0.2
  done
  kill -9 "$pid" 2>/dev/null || true
  rm -f "$pid_file"
  echo "YeYing stopped."
}

case "$command" in
  start) start ;;
  stop) stop ;;
  restart) stop; start ;;
  status) if is_running; then echo "YeYing is running (pid $(cat "$pid_file"), port $port)."; else echo "YeYing is stopped."; fi ;;
  *) echo "Usage: $(basename "$0") {start|stop|restart|status}" >&2; exit 1 ;;
esac
