<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageObjectControllerTest extends TestCase
{
    public function test_s3_object_path_redirects_to_a_temporary_url(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');
        $key = 'uploads/chat/test/message_thumb.png';
        Storage::disk('s3')->put($key, 'message');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path): string => 'https://storage.example.test/signed/' . $path
        );

        $this->get('/' . $key)
            ->assertRedirect('https://storage.example.test/signed/' . $key);
    }

    public function test_local_mode_does_not_fall_back_to_s3(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 'local');
        $key = 'uploads/chat/test/missing.txt';
        Storage::disk('s3')->put($key, 'message');

        $this->get('/' . $key)->assertNotFound();
    }

    public function test_s3_crop_failure_falls_back_to_original_object(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');
        $key = 'uploads/chat/test/broken.png';
        Storage::disk('s3')->put($key, 'not an image');
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path): string => 'https://storage.example.test/signed/' . $path
        );

        $this->get('/' . $key . '/crop/ratio:5,percentage:320x0')
            ->assertRedirect('https://storage.example.test/signed/' . $key);
    }
}
