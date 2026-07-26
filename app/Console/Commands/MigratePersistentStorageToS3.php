<?php

namespace App\Console\Commands;

use App\Services\PersistentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MigratePersistentStorageToS3 extends Command
{
    protected $signature = 'persistent-storage:migrate-s3
        {--execute : Upload and verify objects. Without it, only report planned changes}
        {--namespace=* : Limit migration to one or more registered namespace prefixes}
        {--limit=0 : Maximum local files to inspect}
        {--manifest= : Manifest path. Defaults to storage/app/persistent-storage-migration/manifest-YYYYmmdd-His.jsonl}';

    protected $description = 'Copy all local persistent upload namespaces to S3 and write a verification manifest';

    /** @var array<string, int> */
    private array $counts = [
        'inspected' => 0,
        'planned' => 0,
        'uploaded' => 0,
        'verified' => 0,
        'skipped' => 0,
        'failed' => 0,
    ];

    public function handle(): int
    {
        try {
            PersistentStorage::validateS3Configuration();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        try {
            $execute = (bool) $this->option('execute');
            $limit = max(0, (int) $this->option('limit'));
            $manifestPath = $this->manifestPath();
            $namespaces = $this->selectedNamespaces();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if (!$execute) {
            $this->warn('Dry run: no object will be uploaded and no manifest will be written. Use --execute to migrate.');
        } else {
            $this->makeDirectory(dirname($manifestPath));
            file_put_contents($manifestPath, '');
            $this->info("Manifest: {$manifestPath}");
        }

        foreach ($namespaces as $namespace) {
            if ($limit > 0 && $this->counts['inspected'] >= $limit) {
                break;
            }
            $this->scanNamespace($namespace, $execute, $limit, $manifestPath);
        }

        $this->newLine();
        $this->table(['Result', 'Count'], collect($this->counts)
            ->map(fn (int $count, string $name) => [$name, $count])
            ->values()
            ->all());

        if ($this->counts['failed'] > 0) {
            $this->error('Persistent storage migration completed with failures. Fix the failed objects and rerun.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function selectedNamespaces(): array
    {
        $requested = array_values(array_filter(array_map(
            static fn (string $namespace): string => rtrim(ltrim(str_replace('\\', '/', trim($namespace)), '/'), '/') . '/',
            (array) $this->option('namespace')
        )));
        if ($requested === []) {
            return PersistentStorage::persistentNamespaces();
        }

        return array_values(array_map(static fn (string $namespace): string => PersistentStorage::normalizePrefix($namespace), $requested));
    }

    private function scanNamespace(string $namespace, bool $execute, int $limit, string $manifestPath): void
    {
        $root = public_path($namespace);
        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if ($limit > 0 && $this->counts['inspected'] >= $limit) {
                return;
            }
            $this->counts['inspected']++;
            $this->migrateFile($file->getPathname(), $execute, $manifestPath);
        }
    }

    private function migrateFile(string $localPath, bool $execute, string $manifestPath): void
    {
        $key = $this->relativeKey($localPath);
        if (!PersistentStorage::isPersistentKey($key)) {
            $this->counts['skipped']++;
            return;
        }

        $this->counts['planned']++;
        if (!$execute) {
            $this->line($key);
            return;
        }

        try {
            $expectedSize = filesize($localPath);
            $expectedHash = hash_file('sha256', $localPath);
            if ($expectedSize === false || $expectedHash === false) {
                throw new RuntimeException("Unable to calculate checksum: {$key}");
            }

            $stream = fopen($localPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException("Unable to open local file: {$key}");
            }
            try {
                if (!Storage::disk('s3')->put($key, $stream)) {
                    throw new RuntimeException("Unable to upload object: {$key}");
                }
            } finally {
                fclose($stream);
            }
            $this->counts['uploaded']++;

            $actual = $this->remoteChecksum($key);
            if ($actual['size'] !== $expectedSize || !hash_equals($expectedHash, $actual['hash'])) {
                throw new RuntimeException("S3 verification failed: {$key}");
            }
            $this->counts['verified']++;

            $this->appendManifest($manifestPath, [
                'key' => $key,
                'size' => (int) $expectedSize,
                'sha256' => $expectedHash,
                'mtime' => filemtime($localPath) ?: 0,
                'migrated_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $exception) {
            $this->counts['failed']++;
            $this->error("{$key}: {$exception->getMessage()}");
        }
    }

    private function manifestPath(): string
    {
        $path = trim((string) $this->option('manifest'));
        if ($path === '') {
            return storage_path('app/persistent-storage-migration/manifest-' . date('Ymd-His') . '.jsonl');
        }
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function relativeKey(string $localPath): string
    {
        $publicRoot = rtrim(public_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($localPath, $publicRoot)) {
            throw new RuntimeException("Local file is outside public path: {$localPath}");
        }
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($localPath, strlen($publicRoot)));
    }

    /** @param array<string, mixed> $row */
    private function appendManifest(string $manifestPath, array $row): void
    {
        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false || file_put_contents($manifestPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to write manifest: {$manifestPath}");
        }
    }

    /** @return array{size: int, hash: string} */
    private function remoteChecksum(string $key): array
    {
        $stream = Storage::disk('s3')->readStream($key);
        if (!is_resource($stream)) {
            throw new RuntimeException("Unable to read S3 object: {$key}");
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("Unable to read S3 object: {$key}");
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return ['size' => $size, 'hash' => hash_final($hash)];
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }
    }
}
