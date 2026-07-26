<?php

namespace App\Console\Commands;

use App\Models\FileContent;
use App\Module\Base;
use App\Services\FileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MigrateFileStorageToS3 extends Command
{
    protected $signature = 'file-storage:migrate-s3
        {--execute : Upload and update records. Without it, only report planned changes}
        {--limit=0 : Maximum records to inspect}
        {--after-id=0 : Only process records after this file_contents ID}
        {--id=* : Process specific file_contents IDs}';

    protected $description = 'Migrate historical local FileCenter attachment records to S3';

    /** @var array<string, int> */
    private array $counts = [
        'inspected' => 0,
        'planned' => 0,
        'migrated' => 0,
        'already_s3' => 0,
        'out_of_scope' => 0,
        'missing_local' => 0,
        'changed' => 0,
        'failed' => 0,
    ];

    public function handle(): int
    {
        if (config('dootask.file_storage_disk', 'local') !== 's3') {
            $this->error('FILE_STORAGE_DISK must be set to s3 before migration.');
            return self::FAILURE;
        }

        try {
            FileStorage::validateConfiguration();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));
        $afterId = max(0, (int) $this->option('after-id'));
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id')), static fn (int $id) => $id > 0));

        if (!$execute) {
            $this->warn('Dry run: no object will be uploaded and no database record will change. Use --execute to migrate.');
        }

        $query = FileContent::query()->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('id', '>', $afterId);
        }

        $query->chunkById(100, function (Collection $contents) use ($execute, $limit): bool {
            foreach ($contents as $fileContent) {
                if ($limit > 0 && $this->counts['inspected'] >= $limit) {
                    return false;
                }

                $this->counts['inspected']++;
                $this->migrate($fileContent, $execute);
            }
            return true;
        });

        $this->newLine();
        $this->table(['Result', 'Count'], collect($this->counts)
            ->map(fn (int $count, string $name) => [$name, $count])
            ->values()
            ->all());

        if ($this->counts['failed'] > 0) {
            $this->error('Migration completed with failures. Resolve them and rerun with --id or --after-id.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function migrate(FileContent $fileContent, bool $execute): void
    {
        $originalContent = (string) $fileContent->getRawOriginal('content');
        $content = Base::json2array($originalContent);
        $path = ltrim(str_replace('\\', '/', (string) ($content['url'] ?? '')), '/');

        if (!str_starts_with($path, 'uploads/file/') || str_contains($path, '..')) {
            $this->counts['out_of_scope']++;
            return;
        }

        if (($content['storage']['disk'] ?? 'local') === 's3') {
            $this->counts['already_s3']++;
            return;
        }

        $localPath = public_path($path);
        if (!is_file($localPath)) {
            $this->counts['missing_local']++;
            $this->warn("#{$fileContent->id} local file is missing: {$path}");
            return;
        }

        $this->counts['planned']++;
        if (!$execute) {
            $this->line("#{$fileContent->id} {$path}");
            return;
        }

        try {
            $expectedSize = filesize($localPath);
            $expectedHash = hash_file('sha256', $localPath);
            if ($expectedSize === false || $expectedHash === false) {
                throw new RuntimeException("Unable to calculate checksum: {$path}");
            }

            $storage = FileStorage::store($path);
            $actual = $this->remoteChecksum($storage['key']);
            if ($actual['size'] !== $expectedSize || !hash_equals($expectedHash, $actual['hash'])) {
                throw new RuntimeException("S3 verification failed: {$path}");
            }

            $content['storage'] = $storage;
            $updated = FileContent::query()
                ->whereKey($fileContent->id)
                ->whereNull('deleted_at')
                ->where('content', $originalContent)
                ->update(['content' => Base::array2json($content)]);
            if ($updated !== 1) {
                $this->counts['changed']++;
                $this->warn("#{$fileContent->id} changed while migrating; S3 object was retained and the record was not updated.");
                return;
            }

            $this->counts['migrated']++;
            $this->info("#{$fileContent->id} migrated: {$path}");
        } catch (Throwable $exception) {
            $this->counts['failed']++;
            $this->error("#{$fileContent->id} failed: {$exception->getMessage()}");
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
}
