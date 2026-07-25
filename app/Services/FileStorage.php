<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Storage for FileCenter attachments. Local files remain a compatibility cache
 * while S3 is enabled, so existing preview and processing code can keep using paths.
 */
class FileStorage
{
    public static function validateConfiguration(): void
    {
        if (!self::usesS3()) {
            return;
        }

        $required = [
            'S3_ACCESS_KEY_ID' => config('filesystems.disks.s3.key'),
            'S3_SECRET_ACCESS_KEY' => config('filesystems.disks.s3.secret'),
            'S3_BUCKET' => config('filesystems.disks.s3.bucket'),
            'S3_PREFIX' => config('filesystems.disks.s3.root'),
            'S3_ENDPOINT' => config('filesystems.disks.s3.endpoint'),
        ];
        $missing = array_keys(array_filter($required, static fn ($value) => trim((string) $value) === ''));
        if ($missing !== []) {
            throw new RuntimeException('FILE_STORAGE_DISK=s3 requires: ' . implode(', ', $missing));
        }
    }

    public static function store(string $relativePath): array
    {
        $relativePath = self::normalizePath($relativePath);
        if (!self::usesS3()) {
            return ['disk' => 'local', 'key' => $relativePath];
        }

        $localPath = public_path($relativePath);
        if (!is_file($localPath)) {
            throw new RuntimeException("Attachment cache file does not exist: {$relativePath}");
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Unable to read attachment cache file: {$relativePath}");
        }
        try {
            if (!Storage::disk('s3')->put($relativePath, $stream)) {
                throw new RuntimeException("Unable to store attachment: {$relativePath}");
            }
        } finally {
            fclose($stream);
        }

        return ['disk' => 's3', 'key' => $relativePath];
    }

    public static function ensureLocal(array $content): string
    {
        $relativePath = self::normalizePath((string) ($content['url'] ?? ''));
        $storage = $content['storage'] ?? [];
        if (($storage['disk'] ?? 'local') !== 's3') {
            return public_path($relativePath);
        }

        $key = self::normalizePath((string) ($storage['key'] ?? $relativePath));
        $localPath = public_path($relativePath);
        if (is_file($localPath)) {
            return $localPath;
        }

        $stream = Storage::disk('s3')->readStream($key);
        if (!is_resource($stream)) {
            throw new RuntimeException("Unable to read attachment: {$key}");
        }

        $directory = dirname($localPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            fclose($stream);
            throw new RuntimeException("Unable to create attachment cache directory: {$directory}");
        }

        $temporaryPath = $localPath . '.download-' . bin2hex(random_bytes(8));
        $target = fopen($temporaryPath, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException("Unable to write attachment cache file: {$relativePath}");
        }
        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }
        if (!rename($temporaryPath, $localPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to finalize attachment cache file: {$relativePath}");
        }

        return $localPath;
    }

    public static function delete(array $content): void
    {
        $storage = $content['storage'] ?? [];
        if (($storage['disk'] ?? 'local') !== 's3') {
            return;
        }
        Storage::disk('s3')->delete(self::normalizePath((string) ($storage['key'] ?? $content['url'] ?? '')));
    }

    private static function usesS3(): bool
    {
        return config('dootask.file_storage_disk', 'local') === 's3';
    }

    private static function normalizePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');
        if ($path === '' || !str_starts_with($path, 'uploads/file/') || str_contains($path, '..')) {
            throw new RuntimeException('Invalid FileCenter attachment path');
        }
        return $path;
    }
}
