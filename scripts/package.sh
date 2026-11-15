#!/usr/bin/env bash
set -euo pipefail
export COMPOSER_ALLOW_SUPERUSER=1
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/package-common.sh"
package_common_init project "$@"
for command_name in npm composer; do command -v "$command_name" >/dev/null 2>&1 || package_fail "$command_name is required"; done
[[ -f "$PACKAGE_ROOT_DIR/composer.lock" ]] || package_fail 'composer.lock is required for deterministic PHP dependency installation'
(cd "$PACKAGE_ROOT_DIR" && HUSKY=0 npm install --no-audit --no-fund && npx vite build -- fromcmd)
(cd "$PACKAGE_ROOT_DIR" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)
[[ -f "$PACKAGE_ROOT_DIR/public/manifest.json" && -d "$PACKAGE_ROOT_DIR/public/js/build" ]] || package_fail 'frontend build manifest or assets are missing'
package_make_root project-service
package_archive_source "$PACKAGE_DIR"
rm -rf "$PACKAGE_DIR/node_modules" "$PACKAGE_DIR/vendor" "$PACKAGE_DIR/output" "$PACKAGE_DIR/public/js/build"
cp -R "$PACKAGE_ROOT_DIR/vendor" "$PACKAGE_DIR/vendor"
cp "$PACKAGE_ROOT_DIR/public/manifest.json" "$PACKAGE_DIR/public/manifest.json"
package_copy_scripts "$PACKAGE_DIR/scripts"
package_strip_build_files "$PACKAGE_DIR"
package_finish_artifact service project

package_make_root project-web
PACKAGE_HEALTH_PATH="/$(jq -r 'to_entries[] | select(.key | endswith("/app.js")) | .value.file' "$PACKAGE_ROOT_DIR/public/manifest.json" | head -n 1)"
[[ -n "$PACKAGE_HEALTH_PATH" && "$PACKAGE_HEALTH_PATH" != "/null" ]] || package_fail 'frontend entry is missing from public/manifest.json'
mkdir -p "$PACKAGE_DIR/js"
cp -R "$PACKAGE_ROOT_DIR/public/js/build" "$PACKAGE_DIR/js/build"
package_finish_artifact static
package_finish
