<?php

namespace Tests\Unit;

use App\Models\ProjectTaskContent;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectTaskContentStorageTest extends TestCase
{
    public function test_task_content_uses_s3_without_a_local_persistent_copy(): void
    {
        Storage::fake('s3');
        config()->set('dootask.file_storage_disk', 's3');

        $content = '<p>task-storage-' . uniqid('', true) . '</p>';
        $key = ProjectTaskContent::saveContent(123, $content);

        Storage::disk('s3')->assertExists($key);
        $this->assertSame($content, Storage::disk('s3')->get($key));
        $this->assertFileDoesNotExist(public_path($key));

        $model = ProjectTaskContent::fillInstance([
            'content' => ['url' => $key],
        ]);
        $this->assertSame($content, $model->getContentInfo()['content']);
    }
}
