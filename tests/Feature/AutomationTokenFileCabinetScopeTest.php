<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\AutomationToken;
use App\Models\User;
use App\Services\AutomationTokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class AutomationTokenFileCabinetScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $email): User
    {
        $user = User::createInstance([
            'email' => $email,
            'userimg' => '',
            'nickname' => 'TestUser_' . substr(md5($email), 0, 6),
            'profession' => '',
            'password' => md5('123456'),
        ]);
        $user->save();
        return $user;
    }

    private function makeToken(User $user, array $scopes = []): AutomationToken
    {
        $token = AutomationToken::createInstance([
            'userid' => $user->userid,
            'access_key' => 'yyak_test_' . bin2hex(random_bytes(6)),
            'secret_hash' => hash('sha256', 'test-secret'),
            'name' => 'scope-test',
            'scopes' => $scopes,
            'project_ids' => [1],
            'expires_at' => Carbon::now()->addDay(),
            'status' => AutomationToken::STATUS_ACTIVE,
        ]);
        $token->save();
        return $token->fresh();
    }

    public function test_file_cabinet_endpoint_requires_scope(): void
    {
        $token = $this->makeToken($this->makeUser('token-file-deny@test.local'));
        $request = Request::create('/api/file/lists', 'GET', ['pid' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('访问令牌无权调用此接口');

        AutomationTokenService::authorizeStandardRequest($token, $request);
    }

    public function test_file_cabinet_scope_allows_request_to_continue_to_file_permissions(): void
    {
        $token = $this->makeToken($this->makeUser('token-file-allow@test.local'), [
            AutomationToken::SCOPE_FILE_CABINET,
        ]);
        $request = Request::create('/api/file/lists', 'GET', ['pid' => 0]);

        AutomationTokenService::authorizeStandardRequest($token, $request);

        $this->assertTrue(true);
    }

    public function test_file_cabinet_scope_only_allows_file_cabinet_upload_scene(): void
    {
        $token = $this->makeToken($this->makeUser('token-upload-deny@test.local'), [
            AutomationToken::SCOPE_FILE_CABINET,
        ]);
        $request = Request::create('/api/upload/init', 'POST', ['scene' => 'generic_file']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('访问令牌无权调用此接口');

        AutomationTokenService::authorizeStandardRequest($token, $request);
    }

    public function test_file_cabinet_scope_allows_file_cabinet_upload_init(): void
    {
        $token = $this->makeToken($this->makeUser('token-upload-allow@test.local'), [
            AutomationToken::SCOPE_FILE_CABINET,
        ]);
        $request = Request::create('/api/upload/init', 'POST', ['scene' => 'file_cabinet']);

        AutomationTokenService::authorizeStandardRequest($token, $request);

        $this->assertTrue(true);
    }
}
