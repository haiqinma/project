<?php

namespace Tests\Unit;

use App\Services\FileStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileStorageTest extends TestCase
{
    public function test_s3_storage_requires_complete_configuration(): void
    {
        config()->set('dootask.file_storage_disk', 's3');
        config()->set('filesystems.disks.s3.endpoint', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_ENDPOINT');

        FileStorage::validateConfiguration();
    }

    public function test_s3_storage_keeps_metadata_and_restores_local_cache(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');

        $relativePath = 'uploads/file/test/' . uniqid('', true) . '/example.txt';
        $localPath = public_path($relativePath);
        mkdir(dirname($localPath), 0775, true);
        file_put_contents($localPath, 'warehouse-content');

        try {
            $storage = FileStorage::store($relativePath);

            $this->assertSame(['disk' => 's3', 'key' => $relativePath], $storage);
            Storage::disk('s3')->assertExists($relativePath);

            unlink($localPath);
            $restoredPath = FileStorage::ensureLocal([
                'url' => $relativePath,
                'storage' => $storage,
            ]);

            $this->assertSame($localPath, $restoredPath);
            $this->assertSame('warehouse-content', file_get_contents($restoredPath));

            FileStorage::delete(['url' => $relativePath, 'storage' => $storage]);
            Storage::disk('s3')->assertMissing($relativePath);
        } finally {
            if (is_file($localPath)) {
                unlink($localPath);
            }
            @rmdir(dirname($localPath));
            @rmdir(dirname(dirname($localPath)));
        }
    }

    public function test_local_storage_only_records_the_existing_path(): void
    {
        config()->set('dootask.file_storage_disk', 'local');

        $relativePath = 'uploads/file/test/example.txt';

        $this->assertSame(
            ['disk' => 'local', 'key' => $relativePath],
            FileStorage::store($relativePath)
        );
    }
}
