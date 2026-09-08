#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
PROJECT_DIR=$(cd "${SCRIPT_DIR}/.." && pwd)
SOURCE_ENV="${PROJECT_DIR}/.env"

fail() {
    echo "$1" >&2
    exit 1
}

if [[ $# -ne 1 ]]; then
    fail "Usage: $0 <target_directory_absolute_path>"
fi

TARGET_DIR=$1

if [[ "$TARGET_DIR" != /* ]]; then
    fail "Target directory must be an absolute path: ${TARGET_DIR}"
fi

if [[ ! -d "$TARGET_DIR" ]]; then
    fail "Target directory not found: ${TARGET_DIR}"
fi

if [[ ! -f "$SOURCE_ENV" ]]; then
    fail "Source config file not found: ${SOURCE_ENV}"
fi

cp -f "$SOURCE_ENV" "${TARGET_DIR}/.env" || fail "Failed to copy .env to ${TARGET_DIR}"

exit 0
