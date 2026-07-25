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

V1 covers FileCenter attachment content only. It does not migrate existing files, chat attachments, avatars, document embedded images, temporary uploads, or `public/uploads` as a whole.

## Operations

Keep the Warehouse credential scoped to `/services/project` with read, create, update, and delete permissions. Do not delete `public/uploads/file/` directly: it is still required as the compatibility cache and for historical local attachments.
