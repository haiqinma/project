#!/usr/bin/env bash

set -uo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
BACKUP_CONF_FILE="${SCRIPT_DIR}/backup.conf"
PASSPHRASE_FILE="${SCRIPT_DIR}/.passphrase-file"
BACKUP_DIR="/opt/backup"
LOGFILE=""

DEPLOY_REAL_PATH=$(realpath "$(pwd)")
DEPLOY_DIR_NAME=$(basename "$DEPLOY_REAL_PATH")
MODULE_NAME="$DEPLOY_DIR_NAME"
if [[ "$DEPLOY_DIR_NAME" =~ ^(.+)-v[^-]+-[[:alnum:]]{7}$ ]]; then
    MODULE_NAME="${BASH_REMATCH[1]}"
fi

init_log_file() {
    local logfile_name=$1
    local logfile_dir="/opt/logs"

    LOGFILE="${logfile_dir}/${logfile_name}"
    mkdir -p "$logfile_dir"
    touch "$LOGFILE"

    local filesize=0
    filesize=$(stat -c "%s" "$LOGFILE" 2>/dev/null || echo 0)
    if [[ "$filesize" -ge 1048576 ]]; then
        printf 'clear old logs at %s to avoid log file too big\n' "$(date)" > "$LOGFILE"
    fi
}

log() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOGFILE"
}

log_err() {
    echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOGFILE" >&2
}

# shellcheck disable=SC2317 # Invoked by trap on script exit.
cleanup() {
    if [[ -n "${TMP_DIR:-}" && -d "$TMP_DIR" ]]; then
        rm -rf "$TMP_DIR"
    fi
    if [[ -n "${TMP_OUTPUT:-}" && -f "$TMP_OUTPUT" ]]; then
        rm -f "$TMP_OUTPUT"
    fi
}

fail() {
    log_err "$1"
    exit 1
}

init_log_file "config-backup-${MODULE_NAME}.log"
trap cleanup EXIT

BACKUP_CONF_FLAG="True"
BACKUP_CONF_PREFIX=""
BACKUP_CONF_SUFFIX=".conf.tar.gz.gpg"

if [[ ! -f "$BACKUP_CONF_FILE" ]]; then
    fail "Backup config not found: ${BACKUP_CONF_FILE}"
fi

# shellcheck source=/dev/null
source "$BACKUP_CONF_FILE"

if [[ "$BACKUP_CONF_FLAG" == "False" ]]; then
    log "Configuration backup disabled by BACKUP_CONF_FLAG=False"
    exit 0
fi

if [[ "$BACKUP_CONF_FLAG" != "True" ]]; then
    fail "Invalid BACKUP_CONF_FLAG: ${BACKUP_CONF_FLAG}. Expected True or False."
fi

BACKUP_FILE_NAME="${BACKUP_CONF_PREFIX}${DEPLOY_DIR_NAME}${BACKUP_CONF_SUFFIX}"
BACKUP_FILE_PATH="${BACKUP_DIR}/${BACKUP_FILE_NAME}"
TMP_DIR="/tmp/${DEPLOY_DIR_NAME}-conf"
TMP_OUTPUT="/tmp/${BACKUP_FILE_NAME}"

log "Start configuration backup for ${DEPLOY_DIR_NAME}"
log "Deploy path: ${DEPLOY_REAL_PATH}"
log "Backup file: ${BACKUP_FILE_PATH}"

if [[ -f "$BACKUP_FILE_PATH" ]]; then
    log "Backup file already exists: ${BACKUP_FILE_PATH}"
    exit 255
fi

if [[ ! -f "${DEPLOY_REAL_PATH}/.env" ]]; then
    fail "Required config file not found: ${DEPLOY_REAL_PATH}/.env"
fi

if [[ ! -f "$PASSPHRASE_FILE" ]]; then
    fail "Passphrase file not found: ${PASSPHRASE_FILE}"
fi

mkdir -p "$BACKUP_DIR" || fail "Failed to create backup directory: ${BACKUP_DIR}"
rm -rf "$TMP_DIR" || fail "Failed to remove old temporary directory: ${TMP_DIR}"
mkdir -p "$TMP_DIR" || fail "Failed to create temporary directory: ${TMP_DIR}"
cp "${DEPLOY_REAL_PATH}/.env" "$TMP_DIR/.env" || fail "Failed to copy .env into temporary directory"

if tar czf - -C "$(dirname "$TMP_DIR")" "$(basename "$TMP_DIR")" | gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file "$PASSPHRASE_FILE" -o "$TMP_OUTPUT"; then
    mv "$TMP_OUTPUT" "$BACKUP_FILE_PATH" || fail "Failed to move backup file to ${BACKUP_FILE_PATH}"
    log "Configuration backup completed: ${BACKUP_FILE_PATH}"
    exit 0
fi

fail "Configuration backup failed"
