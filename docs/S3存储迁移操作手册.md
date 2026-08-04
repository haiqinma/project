# S3 Migration Runbook

This runbook migrates Project persistent uploads from local `public/uploads` to an S3-compatible backend such as Warehouse. It is intentionally operational: follow the phases in order and do not skip the verification steps.

## Preconditions

- Production code includes `persistent-storage:migrate-s3` and `persistent-storage:cleanup-local`.
- `.env` still has `FILE_STORAGE_DISK=local` before migration starts.
- `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`, `S3_BUCKET`, `S3_PREFIX`, `S3_ENDPOINT`, and `S3_PATH_STYLE` are configured.
- The S3 credential can read, create, update, and delete objects under the configured bucket/prefix.
- MySQL and `public/uploads` have a fresh backup.
- Operators can stop writes or enter a short maintenance window for the final delta.

## Phase 1: Dry Run

Run a dry run while the application is still using local storage:

```bash
cd /opt/deploy/project
./cmd artisan persistent-storage:migrate-s3
```

Review the counts and listed keys. The command scans only registered persistent namespaces:

```text
uploads/assistant/
uploads/chat/
uploads/desktop/
uploads/emosearch/
uploads/file/
uploads/pic/
uploads/report/
uploads/task/
uploads/user/
```

It does not migrate local working directories such as `uploads/tmp/` or `uploads/desktop-draft/`.

For a smaller verification batch, limit by namespace or count:

```bash
./cmd artisan persistent-storage:migrate-s3 --namespace=uploads/file/ --limit=100
./cmd artisan persistent-storage:migrate-s3 --namespace=uploads/chat/202607/
```

## Phase 2: First Copy

Run the first copy with an explicit manifest path:

```bash
MANIFEST=storage/app/persistent-storage-migration/manifest-$(date +%Y%m%d-%H%M%S).jsonl
./cmd artisan persistent-storage:migrate-s3 --execute --manifest="$MANIFEST"
```

The command uploads every planned object to S3, reads it back, compares byte size and SHA-256, then appends one JSON line per verified object:

```json
{"key":"uploads/chat/202607/1/example.png","size":12345,"sha256":"...","mtime":1785139200,"migrated_at":"2026-07-27T10:00:00+08:00"}
```

If any object fails, do not switch storage. Fix the failed object or credential problem, then rerun the command. Existing S3 objects are overwritten by the local source of truth during this phase.

## Phase 3: Final Delta

Enter a maintenance window or otherwise stop persistent writes before the final delta. Do not let users upload, edit files, change task descriptions, send attachments, publish desktop packages, or update avatars during this phase.

Run the same command again using the same manifest path:

```bash
./cmd artisan persistent-storage:migrate-s3 --execute --manifest="$MANIFEST"
```

This refreshes S3 with any objects created or changed after the first copy and rewrites the manifest for the verified final state.

## Phase 4: Switch Backend

Edit `.env`:

```dotenv
FILE_STORAGE_DISK=s3
```

Keep `FILESYSTEM_DRIVER=local`; it is still used for local temporary processing.

Clear config and restart LaravelS:

```bash
./cmd artisan config:clear
./scripts/starter.sh restart
```

If the deployment uses external process supervision, restart the application process through that supervisor instead of the bundled starter script.

## Phase 5: Verification

Verify representative workflows before reopening writes:

- Open an existing FileCenter file, preview it, and download it.
- Open a task with rich text content and embedded images.
- Send a chat image and a regular file attachment.
- Open an old chat image and file attachment.
- Upload or view a user avatar.
- Open a report with embedded images.
- Download a desktop client artifact from `desktop/publish/latest`.
- Save an Office or text document if the related app is installed.

Also check application logs:

```bash
tail -n 200 storage/logs/laravel.log
```

If verification fails before cleanup, roll back by setting `FILE_STORAGE_DISK=local`, clearing config, and restarting LaravelS. Local persistent files still exist at this point.

## Phase 6: Local Cleanup

Only run cleanup after S3 mode has been verified in production.

First dry-run cleanup:

```bash
./cmd artisan persistent-storage:cleanup-local "$MANIFEST"
```

Then execute:

```bash
./cmd artisan persistent-storage:cleanup-local "$MANIFEST" --execute
```

The cleanup command requires `FILE_STORAGE_DISK=s3`. For each manifest row, it verifies:

- the key is in a registered persistent namespace
- the local file still matches manifest size and SHA-256
- the S3 object also matches the same size and SHA-256

Only then does it delete the local file and remove empty directories under `public/uploads`. It never deletes files not listed in the manifest.

## Rollback Notes

- Before cleanup: set `FILE_STORAGE_DISK=local`, clear config, restart LaravelS.
- After cleanup: restore local objects from S3 or backup before switching back to local.
- Do not run `rm -rf public/uploads/*` manually. Temporary directories may be cleaned separately, but persistent namespaces must follow the manifest-based cleanup.

## Command Reference

```bash
# Dry run full migration
./cmd artisan persistent-storage:migrate-s3

# Execute full migration and write manifest
./cmd artisan persistent-storage:migrate-s3 --execute --manifest=storage/app/persistent-storage-migration/manifest.jsonl

# Dry run a namespace
./cmd artisan persistent-storage:migrate-s3 --namespace=uploads/file/

# Cleanup dry run
./cmd artisan persistent-storage:cleanup-local storage/app/persistent-storage-migration/manifest.jsonl

# Cleanup execute
./cmd artisan persistent-storage:cleanup-local storage/app/persistent-storage-migration/manifest.jsonl --execute
```
