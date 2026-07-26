<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersistentStorageMigrationCommandTest extends TestCase
{
    public function test_migration_uploads_registered_local_namespaces_and_writes_manifest(): void
    {
        Storage::fake('s3');
        $this->configureS3();
        config()->set('dootask.file_storage_disk', 'local');

        $key = 'uploads/chat/migration-test/message.txt';
        $tmpKey = 'uploads/tmp/migration-test/chunk.txt';
        $manifest = storage_path('app/persistent-storage-migration/test-manifest.jsonl');
        $this->writePublicFile($key, 'chat-message');
        $this->writePublicFile($tmpKey, 'temporary');
        @unlink($manifest);

        try {
            $this->artisan('persistent-storage:migrate-s3', [
                '--execute' => true,
                '--namespace' => ['uploads/chat/migration-test/'],
                '--manifest' => $manifest,
            ])->assertSuccessful();

            Storage::disk('s3')->assertExists($key);
            Storage::disk('s3')->assertMissing($tmpKey);
            $this->assertFileExists($manifest);
            $rows = array_values(array_filter(explode(PHP_EOL, trim((string) file_get_contents($manifest)))));
            $this->assertCount(1, $rows);
            $row = json_decode($rows[0], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($key, $row['key']);
            $this->assertSame(strlen('chat-message'), $row['size']);
            $this->assertSame(hash('sha256', 'chat-message'), $row['sha256']);
        } finally {
            @unlink(public_path($key));
            @unlink(public_path($tmpKey));
            @unlink($manifest);
            @rmdir(dirname(public_path($key)));
            @rmdir(dirname(public_path($tmpKey)));
        }
    }

    public function test_migration_dry_run_does_not_upload_or_write_manifest(): void
    {
        Storage::fake('s3');
        $this->configureS3();
        config()->set('dootask.file_storage_disk', 'local');

        $key = 'uploads/report/migration-test/report.txt';
        $manifest = storage_path('app/persistent-storage-migration/test-dry-run.jsonl');
        $this->writePublicFile($key, 'report');
        @unlink($manifest);

        try {
            $this->artisan('persistent-storage:migrate-s3', [
                '--namespace' => ['uploads/report/migration-test/'],
                '--manifest' => $manifest,
            ])->assertSuccessful();

            Storage::disk('s3')->assertMissing($key);
            $this->assertFileDoesNotExist($manifest);
        } finally {
            @unlink(public_path($key));
            @rmdir(dirname(public_path($key)));
        }
    }

    public function test_cleanup_deletes_only_manifest_matching_local_files_after_s3_switch(): void
    {
        Storage::fake('s3');
        $this->configureS3();
        config()->set('dootask.file_storage_disk', 's3');

        $key = 'uploads/user/migration-test/avatar.txt';
        $manifest = storage_path('app/persistent-storage-migration/test-cleanup.jsonl');
        $content = 'avatar';
        $this->writePublicFile($key, $content);
        Storage::disk('s3')->put($key, $content);
        @mkdir(dirname($manifest), 0775, true);
        file_put_contents($manifest, json_encode([
            'key' => $key,
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);

        try {
            $this->artisan('persistent-storage:cleanup-local', [
                'manifest' => $manifest,
                '--execute' => true,
            ])->assertSuccessful();

            $this->assertFileDoesNotExist(public_path($key));
        } finally {
            @unlink(public_path($key));
            @unlink($manifest);
            @rmdir(dirname(public_path($key)));
        }
    }

    private function writePublicFile(string $key, string $content): void
    {
        $path = public_path($key);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, $content);
    }

    private function configureS3(): void
    {
        config()->set('filesystems.disks.s3.key', 'test-key');
        config()->set('filesystems.disks.s3.secret', 'test-secret');
        config()->set('filesystems.disks.s3.bucket', 'services');
        config()->set('filesystems.disks.s3.root', 'project');
        config()->set('filesystems.disks.s3.endpoint', 'http://127.0.0.1:6066');
    }
}
