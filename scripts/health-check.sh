#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

LEVEL="${HEALTH_LEVEL:-readiness}"
TIMEOUT="${HEALTH_TIMEOUT:-10}"
RETRIES="${HEALTH_RETRIES:-0}"
INTERVAL="${HEALTH_INTERVAL:-2}"
FORMAT="${HEALTH_FORMAT:-text}"
BASE_URL="${HEALTH_BASE_URL:-}"
CONFIG="${HEALTH_CONFIG:-$PROJECT_DIR/.env}"
PID_FILE="${PROJECT_HEALTH_PID_FILE:-${YEYING_RUN_DIR:-$PROJECT_DIR/run}/yeying.pid}"
QUIET=false
VERBOSE=false

CHECK_NAMES=()
CHECK_STATUSES=()
CHECK_DURATIONS=()
CHECK_MESSAGES=()
PASSED=0
WARNED=0
FAILED=0
SKIPPED=0
HAD_TIMEOUT=false
HAD_FRAMEWORK_ERROR=false

usage() {
  cat <<'EOF'
Usage: ./scripts/health-check.sh [options]

Options:
  --level <level>       liveness, readiness, dependency, or all (default: readiness)
  --timeout <seconds>   Maximum time for one check attempt (default: 10)
  --retries <count>     Retries after the first failed attempt (default: 0)
  --interval <seconds>  Delay between retries (default: 2)
  --format <format>     text or json (default: text)
  --base-url <url>      Override service URL (default: http://127.0.0.1:<LaravelS port>)
  --config <path>       Environment file used to resolve the LaravelS port (default: .env)
  --quiet               Hide per-check text output; keep the final result
  --verbose             Write retry diagnostics to stderr
  --help                Show this help

Environment variables:
  HEALTH_LEVEL, HEALTH_TIMEOUT, HEALTH_RETRIES, HEALTH_INTERVAL, HEALTH_FORMAT,
  HEALTH_BASE_URL, HEALTH_CONFIG, PROJECT_HEALTH_PID_FILE
EOF
}

usage_error() {
  printf 'health-check: %s\nTry --help for usage.\n' "$1" >&2
  exit 2
}

framework_error() {
  printf 'health-check: %s\n' "$1" >&2
  exit 3
}

require_value() {
  [[ $# -ge 2 && -n "${2:-}" && "$2" != --* ]] || usage_error "$1 requires a value"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --level) require_value "$@"; LEVEL="$2"; shift 2 ;;
    --timeout) require_value "$@"; TIMEOUT="$2"; shift 2 ;;
    --retries) require_value "$@"; RETRIES="$2"; shift 2 ;;
    --interval) require_value "$@"; INTERVAL="$2"; shift 2 ;;
    --format) require_value "$@"; FORMAT="$2"; shift 2 ;;
    --base-url) require_value "$@"; BASE_URL="$2"; shift 2 ;;
    --config) require_value "$@"; CONFIG="$2"; shift 2 ;;
    --quiet) QUIET=true; shift ;;
    --verbose) VERBOSE=true; shift ;;
    --help|-h) usage; exit 0 ;;
    *) usage_error "unknown argument: $1" ;;
  esac
done

case "$LEVEL" in liveness|readiness|dependency|all) ;; *) usage_error "invalid level: $LEVEL" ;; esac
case "$FORMAT" in text|json) ;; *) usage_error "invalid format: $FORMAT" ;; esac
[[ "$TIMEOUT" =~ ^[1-9][0-9]*$ ]] || usage_error "timeout must be a positive integer"
[[ "$RETRIES" =~ ^[0-9]+$ ]] || usage_error "retries must be a non-negative integer"
[[ "$INTERVAL" =~ ^[0-9]+$ ]] || usage_error "interval must be a non-negative integer"
[[ -z "$BASE_URL" || "$BASE_URL" =~ ^https?://[^[:space:]]+$ ]] || usage_error "base-url must be an http(s) URL"

command -v php >/dev/null 2>&1 || framework_error "required command not found: php"
command -v curl >/dev/null 2>&1 || framework_error "required command not found: curl"
[[ -x "$PROJECT_DIR/cmd" ]] || framework_error "required project command not found: $PROJECT_DIR/cmd"

if [[ "$CONFIG" != /* ]]; then CONFIG="$PROJECT_DIR/$CONFIG"; fi

env_value() {
  local key="$1"
  [[ -f "$CONFIG" ]] || return 0
  sed -n "s/^${key}=//p" "$CONFIG" | head -n 1 | sed -e 's/^['\''"]//' -e 's/['\''"]$//'
}

if [[ -z "$BASE_URL" ]]; then
  port="${LARAVELS_LISTEN_PORT:-$(env_value LARAVELS_LISTEN_PORT)}"
  port="${port:-2222}"
  [[ "$port" =~ ^[1-9][0-9]*$ ]] || usage_error "configured LaravelS port is invalid"
  BASE_URL="http://127.0.0.1:$port"
fi
BASE_URL="${BASE_URL%/}"

now_ms() { php -r 'echo (int) floor(microtime(true) * 1000);'; }

STARTED_AT="$(php -r 'echo gmdate("Y-m-d\\TH:i:s\\Z");')"
START_MS="$(now_ms)"
VERSION="$(php -r '$p=json_decode(@file_get_contents($argv[1]), true); echo $p["version"] ?? "unknown";' "$PROJECT_DIR/package.json")"
ENVIRONMENT="$(env_value APP_ENV)"
ENVIRONMENT="${ENVIRONMENT:-production}"

sanitize_message() {
  local value="$1"
  value="${value//$'\n'/ }"; value="${value//$'\r'/ }"; value="${value//$'\t'/ }"
  printf '%s' "${value:0:500}"
}

add_result() {
  local name="$1" status="$2" duration="$3" message
  message="$(sanitize_message "$4")"
  CHECK_NAMES+=("$name"); CHECK_STATUSES+=("$status"); CHECK_DURATIONS+=("$duration"); CHECK_MESSAGES+=("$message")
  case "$status" in pass) PASSED=$((PASSED + 1));; warn) WARNED=$((WARNED + 1));; fail) FAILED=$((FAILED + 1));; skip) SKIPPED=$((SKIPPED + 1));; esac
  if [[ "$FORMAT" == text && "$QUIET" == false ]]; then
    printf '[%s] %s: %s (%s ms)\n' "$(printf '%s' "$status" | tr '[:lower:]' '[:upper:]')" "$name" "$message" "$duration"
  fi
}

retry_check() {
  local name="$1" success_message="$2" function_name="$3"
  local attempt=0 max_attempts=$((RETRIES + 1)) started ended output='' rc=1
  started="$(now_ms)"
  while (( attempt < max_attempts )); do
    attempt=$((attempt + 1)); set +e; output="$($function_name 2>&1)"; rc=$?; set -e
    if (( rc == 0 )); then ended="$(now_ms)"; add_result "$name" pass "$((ended - started))" "$success_message"; return 0; fi
    [[ "$rc" -eq 124 || "$rc" -eq 28 ]] && HAD_TIMEOUT=true
    [[ "$rc" -eq 3 ]] && HAD_FRAMEWORK_ERROR=true
    if (( attempt < max_attempts )); then
      [[ "$VERBOSE" == true ]] && printf 'Retrying %s (%d/%d): %s\n' "$name" "$attempt" "$max_attempts" "$(sanitize_message "$output")" >&2
      sleep "$INTERVAL"
    fi
  done
  ended="$(now_ms)"; add_result "$name" fail "$((ended - started))" "${output:-check failed}"; return 1
}

check_process() {
  [[ -f "$PID_FILE" ]] || return 10
  local pid state
  pid="$(tr -d '[:space:]' < "$PID_FILE")"
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || { printf 'PID file is invalid'; return 1; }
  kill -0 "$pid" >/dev/null 2>&1 || { printf 'LaravelS process is not running'; return 1; }
  state="$(ps -o state= -p "$pid" 2>/dev/null | tr -d '[:space:]')"
  [[ "$state" != Z* ]] || { printf 'LaravelS process is a zombie'; return 1; }
  if [[ -r "/proc/$pid/cmdline" ]]; then
    tr '\0' ' ' < "/proc/$pid/cmdline" | grep -q 'laravels' || { printf 'PID belongs to another process'; return 1; }
  fi
}

check_http() {
  local body
  body="$(curl --silent --show-error --fail --max-time "$TIMEOUT" --connect-timeout "$TIMEOUT" "$BASE_URL/health")" || return $?
  [[ "$(printf '%s' "$body" | tr -d '[:space:]')" == ok ]] || { printf 'health endpoint returned an unexpected response'; return 1; }
}

check_database() { (cd "$PROJECT_DIR" && "$PROJECT_DIR/cmd" artisan health:dependency database --timeout="$TIMEOUT" --no-interaction --quiet); }
check_redis() { (cd "$PROJECT_DIR" && "$PROJECT_DIR/cmd" artisan health:dependency redis --timeout="$TIMEOUT" --no-interaction --quiet); }

run_liveness() {
  set +e; check_process; local process_rc=$?; set -e
  if [[ "$process_rc" -eq 10 ]]; then
    add_result process skip 0 "PID file is not used by the current launch mode"
  elif [[ "$process_rc" -eq 0 ]]; then
    add_result process pass 0 "LaravelS process is running"
  else
    retry_check process "LaravelS process is running" check_process || true
  fi
  retry_check http_liveness "GET /health returned ok" check_http || true
}

run_readiness() { run_liveness; }
run_dependency() {
  retry_check database "database read-only query succeeded" check_database || true
  retry_check redis "Redis PING succeeded" check_redis || true
}

case "$LEVEL" in liveness) run_liveness;; readiness) run_readiness;; dependency) run_dependency;; all) run_readiness; run_dependency;; esac

END_MS="$(now_ms)"; DURATION_MS=$((END_MS - START_MS))
if (( FAILED > 0 )); then STATUS=fail; elif (( WARNED > 0 )); then STATUS=warn; else STATUS=pass; fi

if [[ "$FORMAT" == text ]]; then
  printf 'RESULT status=%s passed=%d warned=%d failed=%d skipped=%d duration_ms=%d\n' "$STATUS" "$PASSED" "$WARNED" "$FAILED" "$SKIPPED" "$DURATION_MS"
else
  export HC_PROJECT=project HC_VERSION="$VERSION" HC_ENVIRONMENT="$ENVIRONMENT" HC_LEVEL="$LEVEL" HC_STATUS="$STATUS"
  export HC_STARTED_AT="$STARTED_AT" HC_DURATION_MS="$DURATION_MS" HC_PASSED="$PASSED" HC_WARNED="$WARNED" HC_FAILED="$FAILED" HC_SKIPPED="$SKIPPED"
  args=(); for ((i = 0; i < ${#CHECK_NAMES[@]}; i++)); do args+=("${CHECK_NAMES[$i]}" "${CHECK_STATUSES[$i]}" "${CHECK_DURATIONS[$i]}" "${CHECK_MESSAGES[$i]}"); done
  php -r '
    $checks=[]; for ($i=1; $i<$argc; $i+=4) $checks[]=["name"=>$argv[$i],"status"=>$argv[$i+1],"duration_ms"=>(int)$argv[$i+2],"message"=>$argv[$i+3]];
    echo json_encode(["schema_version"=>"1.0","type"=>"health_check","project"=>getenv("HC_PROJECT"),"version"=>getenv("HC_VERSION"),"environment"=>getenv("HC_ENVIRONMENT"),"level"=>getenv("HC_LEVEL"),"status"=>getenv("HC_STATUS"),"started_at"=>getenv("HC_STARTED_AT"),"duration_ms"=>(int)getenv("HC_DURATION_MS"),"summary"=>["passed"=>(int)getenv("HC_PASSED"),"warned"=>(int)getenv("HC_WARNED"),"failed"=>(int)getenv("HC_FAILED"),"skipped"=>(int)getenv("HC_SKIPPED")],"checks"=>$checks], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
  ' "${args[@]}"
fi

if (( FAILED == 0 )); then exit 0; fi
if [[ "$HAD_FRAMEWORK_ERROR" == true ]]; then exit 3; fi
if [[ "$HAD_TIMEOUT" == true ]]; then exit 4; fi
exit 1
