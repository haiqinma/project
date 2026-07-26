# File Storage V1

File Storage V1 adds Warehouse S3-compatible storage for FileCenter attachments. It does not replace the Project-wide local filesystem.

## Configuration

```dotenv
FILE_STORAGE_DISK=s3
S3_BUCKET=services
S3_PREFIX=project
S3_ENDPOINT=http://127.0.0.1:6066
S3_PATH_STYLE=true
```

`FILESYSTEM_DRIVER` must remain `local`. `FILE_STORAGE_DISK` controls only FileCenter attachment persistence.

## Behavior

1. FileCenter uploads, editor saves, and OnlyOffice callbacks finish local processing first.
2. The final attachment is copied to the configured S3 disk and records `storage.disk` and `storage.key` in `file_contents.content`.
3. The local copy remains as a compatibility cache for image URLs, OnlyOffice, previews, and archive downloads.
4. If an S3-backed attachment cache file is missing, Project restores it from S3 before reading it.
5. Permanently deleting a FileCenter content record also deletes its S3 object.

## Scope

V1 covers FileCenter attachment content only. It does not migrate chat attachments, avatars, document embedded images, temporary uploads, or `public/uploads` as a whole.

## Operations

Keep the Warehouse credential scoped to `/services/project` with read, create, update, and delete permissions. Do not delete `public/uploads/file/` directly: it is still required as the compatibility cache and for historical local attachments.

Versioned production releases must share `public/uploads` across versions. Set `YEYING_SHARED_DIR=/opt/deploy/shared/project` when running `scripts/install.sh`; the installer links `.env`, `storage` and `public/uploads` into that directory.

## Historical FileCenter Migration

Changing `FILE_STORAGE_DISK` to `s3` does not automatically migrate records previously stored with `storage.disk=local`. After S3 has been verified, inspect the eligible records first:

```bash
./cmd artisan file-storage:migrate-s3
```

The default is a dry run. It only reports records whose `file_contents.content.url` is under `uploads/file/`, has no S3 marker, and has a readable local cache file. It skips deleted records, non-FileCenter uploads, already migrated records, and missing local files.

Run the actual migration only after reviewing that output:

```bash
./cmd artisan file-storage:migrate-s3 --execute
```

Each record is uploaded, read back from S3, and verified by byte size and SHA-256 before its `storage` metadata is updated. The local file is never deleted. For a staged rollout, use `--limit=100`, resume with `--after-id=12345`, or retry individual records with `--id=12345`.
