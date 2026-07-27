<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PersistentStorage
{
    private const PERSISTENT_NAMESPACES = [
        'uploads/assistant/',
        'uploads/chat/',
        'uploads/desktop/',
        'uploads/emosearch/',
        'uploads/file/',
        'uploads/pic/',
        'uploads/report/',
        'uploads/task/',
        'uploads/user/',
    ];

    /** @return list<string> */
    public static function persistentNamespaces(): array
    {
        return self::PERSISTENT_NAMESPACES;
    }

    public static function validateConfiguration(): void
    {
        if (!self::usesS3()) {
            return;
        }

        self::validateS3Configuration();
    }

    public static function validateS3Configuration(): void
    {
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

    public static function putFile(string $key, string $source): void
    {
        $key = self::normalizeKey($key);
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException("Persistent storage source is not readable: {$source}");
        }

        if (self::usesS3()) {
            $stream = fopen($source, 'rb');
            if ($stream === false) {
                throw new RuntimeException("Unable to open persistent storage source: {$source}");
            }
            try {
                if (!Storage::disk('s3')->put($key, $stream)) {
                    throw new RuntimeException("Unable to store persistent object: {$key}");
                }
            } finally {
                fclose($stream);
            }
            return;
        }

        $target = public_path($key);
        if (realpath($source) === realpath($target)) {
            return;
        }
        self::copyAtomically($source, $target);
    }

    public static function putContent(string $key, string $content): void
    {
        $key = self::normalizeKey($key);
        if (self::usesS3()) {
            if (!Storage::disk('s3')->put($key, $content)) {
                throw new RuntimeException("Unable to store persistent object: {$key}");
            }
            return;
        }

        $target = public_path($key);
        self::makeDirectory(dirname($target));
        $temporary = $target . '.write-' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $content) === false || !rename($temporary, $target)) {
                throw new RuntimeException("Unable to store persistent object: {$key}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return resource */
    public static function readStream(string $key)
    {
        $key = self::normalizeKey($key);
        $stream = self::usesS3()
            ? Storage::disk('s3')->readStream($key)
            : fopen(public_path($key), 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException("Unable to read persistent object: {$key}");
        }
        return $stream;
    }

    public static function exists(string $key): bool
    {
        $key = self::normalizeKey($key);
        return self::usesS3()
            ? Storage::disk('s3')->exists($key)
            : is_file(public_path($key));
    }

    public static function getContent(string $key): string
    {
        $stream = self::readStream($key);
        try {
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new RuntimeException("Unable to read persistent object content: {$key}");
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    public static function delete(string $key): void
    {
        $key = self::normalizeKey($key);
        if (self::usesS3()) {
            Storage::disk('s3')->delete($key);
            return;
        }

        $path = public_path($key);
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException("Unable to delete persistent object: {$key}");
        }
    }

    public static function deleteDirectory(string $prefix): void
    {
        $prefix = self::normalizePrefix($prefix);
        if (self::usesS3()) {
            $files = Storage::disk('s3')->allFiles($prefix);
            if ($files !== []) {
                Storage::disk('s3')->delete($files);
            }
            return;
        }

        $directory = public_path($prefix);
        if (is_dir($directory)) {
            \App\Module\Base::deleteDirAndFile($directory);
        }
    }

    /** @return list<string> */
    public static function listFiles(string $prefix, bool $recursive = true): array
    {
        $prefix = self::normalizePrefix($prefix);
        if (self::usesS3()) {
            $files = $recursive
                ? Storage::disk('s3')->allFiles($prefix)
                : Storage::disk('s3')->files($prefix);
            sort($files);
            return array_values($files);
        }

        $directory = public_path($prefix);
        if (!is_dir($directory)) {
            return [];
        }
        $files = \App\Module\Base::recursiveFiles($directory, $recursive);
        sort($files);
        $publicRoot = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return array_values(array_map(static function (string $file) use ($publicRoot): string {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($publicRoot)));
        }, $files));
    }

    /** @return list<string> */
    public static function listDirectories(string $prefix): array
    {
        $prefix = self::normalizePrefix($prefix);
        if (self::usesS3()) {
            $directories = [];
            foreach (Storage::disk('s3')->allFiles($prefix) as $file) {
                $relative = substr($file, strlen($prefix));
                $segment = strtok($relative, '/');
                if (is_string($segment) && $segment !== '') {
                    $directories[$prefix . $segment] = true;
                }
            }
            $directories = array_keys($directories);
            sort($directories);
            return array_values($directories);
        }

        $directory = public_path($prefix);
        if (!is_dir($directory)) {
            return [];
        }
        $directories = \App\Module\Base::recursiveDirs($directory, false);
        sort($directories);
        return array_values(array_map(static fn (string $dir) => $prefix . basename($dir), $directories));
    }

    public static function size(string $key): int
    {
        $key = self::normalizeKey($key);
        $size = self::usesS3()
            ? Storage::disk('s3')->size($key)
            : filesize(public_path($key));
        if ($size === false) {
            throw new RuntimeException("Unable to get persistent object size: {$key}");
        }
        return (int) $size;
    }

    public static function lastModified(string $key): int
    {
        $key = self::normalizeKey($key);
        $timestamp = self::usesS3()
            ? Storage::disk('s3')->lastModified($key)
            : filemtime(public_path($key));
        if ($timestamp === false) {
            throw new RuntimeException("Unable to get persistent object mtime: {$key}");
        }
        return (int) $timestamp;
    }

    public static function temporaryUrl(string $key, DateTimeInterface $expiresAt): string
    {
        $key = self::normalizeKey($key);
        if (!self::usesS3()) {
            throw new RuntimeException('Temporary URLs are only available in S3 mode.');
        }
        return Storage::disk('s3')->temporaryUrl($key, $expiresAt);
    }

    public static function copyToTemporary(string $key): string
    {
        $key = self::normalizeKey($key);
        $directory = storage_path('app/tmp/persistent-storage');
        self::makeDirectory($directory);
        $temporary = $directory . '/' . bin2hex(random_bytes(16));
        $source = self::readStream($key);
        $target = fopen($temporary, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException("Unable to create temporary object file: {$key}");
        }
        try {
            if (stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException("Unable to copy persistent object to temporary file: {$key}");
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        } finally {
            fclose($source);
            fclose($target);
        }
        return $temporary;
    }

    public static function readableLocalPath(string $key): array
    {
        $key = self::normalizeKey($key);
        if (!self::usesS3()) {
            return [public_path($key), static function (): void {}];
        }

        $temporary = self::copyToTemporary($key);
        return [$temporary, static function () use ($temporary): void {
            @unlink($temporary);
        }];
    }

    public static function normalizeKey(string $key): string
    {
        $key = ltrim(str_replace('\\', '/', trim($key)), '/');
        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            throw new RuntimeException('Invalid persistent object key.');
        }
        foreach (self::PERSISTENT_NAMESPACES as $namespace) {
            if (str_starts_with($key, $namespace) && strlen($key) > strlen($namespace)) {
                return $key;
            }
        }
        throw new RuntimeException("Unregistered persistent object namespace: {$key}");
    }

    public static function normalizePrefix(string $prefix): string
    {
        $prefix = rtrim(ltrim(str_replace('\\', '/', trim($prefix)), '/'), '/') . '/';
        if ($prefix === '/' || str_contains($prefix, '..') || str_contains($prefix, "\0")) {
            throw new RuntimeException('Invalid persistent object prefix.');
        }
        foreach (self::PERSISTENT_NAMESPACES as $namespace) {
            if (str_starts_with($prefix, $namespace)) {
                return $prefix;
            }
        }
        throw new RuntimeException("Unregistered persistent object namespace: {$prefix}");
    }

    public static function isPersistentKey(string $key): bool
    {
        try {
            self::normalizeKey($key);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function usesS3(): bool
    {
        return config('dootask.file_storage_disk', 'local') === 's3';
    }

    private static function copyAtomically(string $source, string $target): void
    {
        self::makeDirectory(dirname($target));
        $temporary = $target . '.copy-' . bin2hex(random_bytes(8));
        try {
            if (!copy($source, $temporary) || !rename($temporary, $target)) {
                throw new RuntimeException("Unable to store local persistent object: {$target}");
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create persistent storage directory: {$directory}");
        }
    }
}
