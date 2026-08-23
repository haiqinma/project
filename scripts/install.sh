#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

env_value() {
  local key="$1"
  [[ -f "$root_dir/.env" ]] || return 0
  sed -n "s/^${key}=//p" "$root_dir/.env" | head -n 1 | sed -e 's/^['\''"]//' -e 's/['\''"]$//'
}

link_shared_path() {
  local relative="$1" shared="$YEYING_SHARED_DIR/$relative" current="$root_dir/$relative"
  mkdir -p "$(dirname "$shared")" "$(dirname "$current")"
  if [[ -d "$current" && ! -L "$current" ]]; then
    mkdir -p "$shared"
    rsync -a "$current/" "$shared/"
    rm -rf "$current"
  fi
  [[ -e "$current" || -L "$current" ]] || ln -s "$shared" "$current"
}

prepare_shared_release() {
  [[ -n "${YEYING_SHARED_DIR:-}" ]] || return 0
  YEYING_SHARED_DIR="${YEYING_SHARED_DIR%/}"
  mkdir -p "$YEYING_SHARED_DIR"
  chmod 755 "$YEYING_SHARED_DIR"
  link_shared_path storage
  link_shared_path public/uploads
  if [[ -f "$root_dir/.env" && ! -e "$YEYING_SHARED_DIR/.env" ]]; then
    cp -p "$root_dir/.env" "$YEYING_SHARED_DIR/.env"
  fi
  if [[ ! -e "$YEYING_SHARED_DIR/.env" ]]; then
    cp "$root_dir/.env.template" "$YEYING_SHARED_DIR/.env"
  fi
  if [[ -e "$YEYING_SHARED_DIR/.env" && ! -L "$root_dir/.env" ]]; then
    rm -f "$root_dir/.env"
    ln -s "$YEYING_SHARED_DIR/.env" "$root_dir/.env"
  fi
}

link_runtime_path() {
  local relative current runtime
  relative="$1"
  current="$root_dir/$relative"
  runtime="$runtime_dir/$relative"
  [[ "$current" == "$runtime" ]] && return 0
  mkdir -p "$(dirname "$runtime")" "$(dirname "$current")"
  if [[ "$relative" != "storage/laravels.pid" ]]; then
    mkdir -p "$runtime"
  fi
  if [[ -d "$current" && ! -L "$current" ]]; then
    rsync -a "$current/" "$runtime/"
    rm -rf "$current"
  elif [[ -e "$current" && ! -L "$current" ]]; then
    if [[ ! -e "$runtime" ]]; then
      mv "$current" "$runtime"
    else
      rm -f "$current"
    fi
  fi
  [[ -e "$current" || -L "$current" ]] || ln -s "$runtime" "$current"
}

prepare_runtime_paths() {
  local configured
  configured="$(env_value YEYING_RUNTIME_DIR)"
  configured="${configured:-.}"
  if [[ "$configured" == /* ]]; then
    runtime_dir="${configured%/}"
  else
    runtime_dir="$root_dir/${configured%/}"
  fi

  # Only relative paths rooted at the release are normalized here. Production
  # paths should be absolute so release cleanup cannot remove runtime files.
  if [[ "$runtime_dir" == "$root_dir/." ]]; then
    runtime_dir="$root_dir"
  fi

  link_runtime_path storage/app/tmp
  link_runtime_path storage/framework/cache
  link_runtime_path storage/framework/sessions
  link_runtime_path storage/framework/views
  link_runtime_path storage/logs
  link_runtime_path storage/temp
  link_runtime_path storage/laravels.pid
  link_runtime_path public/uploads/tmp
  link_runtime_path run
}

if [[ "${SKIP_DEPENDENCY_CHECK:-0}" != "1" ]]; then
  "$root_dir/scripts/ubuntu-deps.sh" --check
fi

if ! command -v php >/dev/null 2>&1; then
  echo "PHP 8.4 is not installed. Run: sudo $root_dir/scripts/ubuntu-deps.sh --install" >&2
  exit 1
fi
command -v composer >/dev/null 2>&1 || { echo "Composer is required. Run ubuntu-deps.sh --install first." >&2; exit 1; }
php -m | grep -qi '^swoole$' || { echo "PHP Swoole extension is required. Run ubuntu-deps.sh --install first." >&2; exit 1; }

cd "$root_dir"
# Nginx serves public/ through the versioned release directory. Some deployment
# tools create that directory with mode 0700 even when package contents are valid.
chmod 755 "$root_dir"
prepare_shared_release
[[ -f .env ]] || cp .env.template .env
prepare_runtime_paths
mkdir -p bootstrap/cache storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan dootask:ensure-admin
php artisan config:clear
php artisan route:clear
php artisan view:clear

chmod -R ug+rwX bootstrap/cache storage
echo "YeYing installation complete. Configure .env, then run scripts/starter.sh start."
