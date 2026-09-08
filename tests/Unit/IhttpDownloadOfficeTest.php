<?php

namespace Tests\Unit;

use App\Module\Ihttp;
use Tests\TestCase;

class IhttpDownloadOfficeTest extends TestCase
{
    public function test_empty_url_is_rejected_without_creating_file(): void
    {
        $target = storage_path('app/tmp/office-content/' . uniqid('', true));
        $result = Ihttp::downloadOffice('', $target);

        $this->assertFalse($result['ok']);
        $this->assertSame('url error', $result['error']);
        $this->assertFileDoesNotExist($target);
    }

    public function test_http_failure_is_rejected_without_leaving_partial_file(): void
    {
        $target = storage_path('app/tmp/office-content/' . uniqid('', true));
        $result = Ihttp::downloadOffice('http://127.0.0.1:1/unavailable', $target);

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['error']);
        $this->assertFileDoesNotExist($target);
    }
}
