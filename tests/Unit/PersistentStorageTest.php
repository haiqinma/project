<?php

namespace Tests\Unit;

use App\Module\Base;
use App\Services\PersistentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PersistentStorageTest extends TestCase
{
    public function test_s3_mode_writes_only_to_s3(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');

        $key = 'uploads/chat/test/' . uniqid('', true) . '/message.txt';
        $source = storage_path('app/tmp/' . uniqid('', true) . '.txt');
        @mkdir(dirname($source), 0775, true);
        file_put_contents($source, 's3-only-content');

        try {
            PersistentStorage::putFile($key, $source);

            Storage::disk('s3')->assertExists($key);
            $this->assertFileDoesNotExist(public_path($key));
            $this->assertTrue(PersistentStorage::exists($key));

            $stream = PersistentStorage::readStream($key);
            try {
                $this->assertSame('s3-only-content', stream_get_contents($stream));
            } finally {
                fclose($stream);
            }

            PersistentStorage::delete($key);
            Storage::disk('s3')->assertMissing($key);
        } finally {
            @unlink($source);
        }
    }

    public function test_local_mode_writes_only_to_public_uploads(): void
    {
        config()->set('dootask.file_storage_disk', 'local');

        $key = 'uploads/task/test/' . uniqid('', true) . '/content.html';
        $target = public_path($key);

        try {
            PersistentStorage::putContent($key, '<p>task</p>');

            $this->assertSame('<p>task</p>', file_get_contents($target));
            $this->assertTrue(PersistentStorage::exists($key));
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
            @rmdir(dirname($target));
            @rmdir(dirname(dirname($target)));
        }
    }

    public function test_temporary_namespace_cannot_be_persisted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unregistered persistent object namespace');

        PersistentStorage::putContent('uploads/tmp/chunks/example', 'temporary');
    }

    public function test_s3_object_can_be_copied_to_a_disposable_local_file(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');
        $key = 'uploads/report/test/' . uniqid('', true) . '/attachment.txt';
        Storage::disk('s3')->put($key, 'temporary-processing');

        $temporary = PersistentStorage::copyToTemporary($key);
        try {
            $this->assertStringStartsWith(storage_path('app/tmp/persistent-storage/'), $temporary);
            $this->assertSame('temporary-processing', file_get_contents($temporary));
            $this->assertFileDoesNotExist(public_path($key));
        } finally {
            @unlink($temporary);
        }
    }

    public function test_upload_helper_commits_persistent_files_only_to_selected_backend(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');

        $source = storage_path('app/tmp/' . uniqid('', true) . '.txt');
        @mkdir(dirname($source), 0775, true);
        file_put_contents($source, 'uploaded-content');
        $key = 'uploads/chat/test/' . uniqid('', true) . '/example.txt';

        try {
            $result = Base::upload([
                'type' => 'file',
                'file' => new UploadedFile($source, 'example.txt', null, null, true),
                'path' => dirname($key) . '/',
                'saveName' => basename($key),
                'size' => 10,
            ]);

            $this->assertSame(1, $result['ret']);
            Storage::disk('s3')->assertExists($key);
            $this->assertFileDoesNotExist(public_path($key));
        } finally {
            @unlink($source);
        }
    }

    public function test_s3_prefix_listing_metadata_and_delete_directory(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');

        PersistentStorage::putContent('uploads/desktop/1.2.3/app.dmg', 'desktop-app');
        PersistentStorage::putContent('uploads/desktop/1.2.3/latest.yml', 'metadata');
        PersistentStorage::putContent('uploads/desktop/2.0.0/app.dmg', 'next-app');

        $this->assertSame([
            'uploads/desktop/1.2.3/app.dmg',
            'uploads/desktop/1.2.3/latest.yml',
        ], PersistentStorage::listFiles('uploads/desktop/1.2.3/', false));
        $this->assertSame([
            'uploads/desktop/1.2.3',
            'uploads/desktop/2.0.0',
        ], PersistentStorage::listDirectories('uploads/desktop/'));
        $this->assertSame(11, PersistentStorage::size('uploads/desktop/1.2.3/app.dmg'));
        $this->assertGreaterThan(0, PersistentStorage::lastModified('uploads/desktop/1.2.3/app.dmg'));

        PersistentStorage::deleteDirectory('uploads/desktop/1.2.3/');

        Storage::disk('s3')->assertMissing('uploads/desktop/1.2.3/app.dmg');
        Storage::disk('s3')->assertExists('uploads/desktop/2.0.0/app.dmg');
    }
}
