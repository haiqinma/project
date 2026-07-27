# YeYing Storage Protocol V1

## Goal

YeYing uses exactly one persistent file backend per deployment. `FILE_STORAGE_DISK=local` stores every persistent object on the local filesystem. `FILE_STORAGE_DISK=s3` stores every persistent object in the configured S3-compatible service. Business modules must not choose their own persistent disk and must not retain a persistent local compatibility copy in S3 mode.

## Object Identity

Existing relative paths remain the canonical object keys, for example:

```text
uploads/file/document/202607/4/2e2a09a0847be472a0e3c89ece541bd1
uploads/chat/202607/12/example.png
uploads/task/content/202607/24/example
uploads/user/picture/5/202607/example.jpg
```

Database fields and HTML content continue to store relative `uploads/...` paths. Per-record `storage.disk` metadata is not authoritative; the deployment-wide `FILE_STORAGE_DISK` selects the backend.

## Persistent Namespaces

The following namespaces contain persistent business data and must use the selected backend:

- `uploads/file/`: FileCenter content and embedded attachments.
- `uploads/chat/`: chat images, audio and files.
- `uploads/emosearch/`: persisted chat image search results.
- `uploads/task/`: task descriptions and embedded images.
- `uploads/user/`: user avatars and personal uploaded files.
- `uploads/report/`: report attachments.
- `uploads/assistant/`: AI assistant generated or uploaded files.
- `uploads/pic/`: other persisted editor images.
- `uploads/desktop/`: published desktop application artifacts.

New persistent namespaces must be registered in the storage service before use. Business code must not write directly to `public/uploads`.

## Temporary Namespaces

`uploads/tmp/` and `uploads/desktop-draft/` are local working directories. They are never migrated to S3 and must not be referenced as durable business data. Upload chunks, image transformations, archive assembly, OnlyOffice conversion and similar processing may use local temporary files, but the files must be removed after the final persistent object is committed or the operation fails.

Generated avatar placeholders and image transformation caches are disposable. They may remain in a dedicated local cache directory, but the application must be able to regenerate them and backup or migration must ignore them.

## Read And Delivery

- Local mode serves existing `uploads/...` paths from `public/uploads`.
- S3 mode resolves the same paths through the storage service. Original objects are delivered with a short-lived signed S3 URL or a storage-aware response; they are not downloaded into `public/uploads`.
- Operations requiring a local pathname use a unique file under `storage/app/tmp/`, then remove it after use.
- Range requests and large downloads must be handled by S3 or a streaming response and must not buffer the complete object in PHP memory.

## Write Contract

1. Finish validation and transformation in a local temporary file when required.
2. Write the final object to the configured persistent backend.
3. Verify that the backend accepted the object before committing its database reference.
4. Remove temporary files in both success and failure paths.
5. Never write the final persistent object to both backends during normal operation.

Delete and replace operations must target the configured backend. Shared object references must be checked before physical deletion.

## Atomic Migration

Migration from local to S3 is an operational switch, not an ongoing mixed mode:

1. Keep `FILE_STORAGE_DISK=local` while copying every registered persistent namespace to S3.
2. Record key, byte size and SHA-256 in a migration manifest.
3. Verify every S3 object by reading it back. Any missing or mismatched object blocks the switch.
4. Enter maintenance mode or otherwise stop persistent file writes.
5. Copy and verify the final delta.
6. Change `FILE_STORAGE_DISK=s3`, clear configuration and restart LaravelS.
7. Verify representative image, download, chat, task and Office workflows.
8. Remove local persistent objects only with a separate explicit cleanup command that rechecks the manifest and active backend.

The migration command is `./cmd artisan persistent-storage:migrate-s3`; the local cleanup command is `./cmd artisan persistent-storage:cleanup-local <manifest>`.

Rollback before local cleanup changes the setting back to `local`. After cleanup, rollback requires restoring the local objects from S3 or backup.

## Backup

Local mode backups include all registered persistent namespaces. S3 mode backups treat the S3 bucket and prefix as the file source of truth; `public/uploads` is not a file backup. Database backups and object backups must be taken from a consistent maintenance window when strict point-in-time recovery is required.
