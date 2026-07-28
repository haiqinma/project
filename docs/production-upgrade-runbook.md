# Production Upgrade Runbook

This runbook is for versioned production releases where `/opt/deploy/project` points to a release directory such as `/opt/deploy/project-v0.0.4-xxxxxxx`.

## 1. Preflight

Run these checks on the server:

```bash
cd /opt/deploy/project
readlink -f /opt/deploy/project
grep -E '^(APP_URL|FILE_STORAGE_DISK|S3_|DB_HOST|DB_PORT|REDIS_HOST|REDIS_PORT)' .env
scripts/starter.sh status
scripts/health-check.sh --level readiness --timeout 5
```

Back up the database and the shared runtime data before replacing the release package. The package release must preserve the shared `.env`, `storage/` and `public/uploads` paths.

## 2. Upgrade Code

Deploy the new package and switch the `/opt/deploy/project` symlink to the new release. Then run the package update steps from the new release directory:

```bash
cd /opt/deploy/project
./cmd composer install --no-dev --optimize-autoloader
./cmd artisan migrate --force
./cmd artisan config:clear
scripts/starter.sh restart
scripts/health-check.sh --level readiness --timeout 5 --retries 12 --interval 5
scripts/health-check.sh --level all --timeout 5
```

Do not use `./cmd php restart` for the host-run LaravelS deployment. The production application process is managed by `scripts/starter.sh`.

## 3. Storage Migration

If the deployment uses local persistent storage, keep `FILE_STORAGE_DISK=local`.

If the deployment is moving from local storage to S3, use the S3 runbook:

```bash
./cmd artisan persistent-storage:migrate-s3
./cmd artisan persistent-storage:migrate-s3 --execute
./cmd artisan persistent-storage:migrate-s3
```

Only run cleanup when the execute step generated a readable manifest with migrated objects:

```bash
./cmd artisan persistent-storage:cleanup-local storage/app/persistent-storage-migration/<manifest>.jsonl
./cmd artisan persistent-storage:cleanup-local storage/app/persistent-storage-migration/<manifest>.jsonl --execute
```

If the dry run reports `planned = 0` and there is no readable manifest, there is nothing to clean. Local files under `public/uploads/tmp/` are temporary and are not persistent migration input.

## 4. Verification

After the restart, verify:

1. Existing documents and attachments open correctly.
2. A new file can be created, saved, refreshed and reopened.
3. A new attachment can be uploaded and reopened.
4. `tail -n 100 storage/logs/laravel.log` shows no new storage or AppStore errors.
5. `scripts/starter.sh status` reports the application running.
6. `scripts/health-check.sh --level all` reports HTTP, database and Redis checks as passing.

## 5. Rollback

Before local cleanup, rollback can switch `/opt/deploy/project` back to the previous release and restart:

```bash
ln -sfn /opt/deploy/project-v<previous> /opt/deploy/project
cd /opt/deploy/project
scripts/starter.sh restart
```

After S3 cleanup, rollback requires restoring local persistent objects from S3 or backup before switching `FILE_STORAGE_DISK` back to `local`.
