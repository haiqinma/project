<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PassportController;
use App\Models\User;
use App\Module\Base;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Request;
use Tests\TestCase;

class TestPassportController extends PassportController
{
    public array $requests = [];
    public array $responses = [];

    protected function nodeRequest(string $method, string $path, array $payload = []): array
    {
        $this->requests[] = compact('method', 'path', 'payload');
        return array_shift($this->responses) ?? Base::retError('missing test response');
    }
}

class PassportLoginFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        config()->set('dootask.passport_node_url', 'http://node.test');
        config()->set('dootask.passport_client_id', 'project-test');
        config()->set('app.url', 'http://project.test');
        Cache::flush();
    }

    public function test_creates_pkce_authorization_session_and_keeps_verifier_server_side(): void
    {
        $controller = new TestPassportController();
        $controller->responses[] = Base::retSuccess('success', [
            'requestId' => 'passport-request-1',
            'verifyUrl' => '/identity/authorize?requestId=passport-request-1',
            'status' => 'pending',
        ]);

        $result = $controller->login__session();

        $this->assertSame(1, $result['ret']);
        $this->assertNotEmpty($result['data']['session_id']);
        $this->assertSame('http://node.test/identity/authorize?requestId=passport-request-1', $result['data']['qrcode_url']);
        $this->assertArrayNotHasKey('code_verifier', $result['data']);
        $request = $controller->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/api/v1/public/identity/authorize/request', $request['path']);
        $this->assertSame('S256', $request['payload']['codeChallengeMethod']);
        $this->assertSame(['identity.basic', 'identity.email', 'identity.wallet'], $request['payload']['scopes']);
        $this->assertSame($result['data']['session_id'], $request['payload']['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $request['payload']['codeChallenge']);

        $cache = Cache::get($this->cacheKey($result['data']['session_id']));
        $this->assertSame('passport-request-1', $cache['request_id']);
        $this->assertNotEmpty($cache['code_verifier']);
    }

    public function test_reports_scanned_only_after_node_approves_the_request(): void
    {
        $sessionId = (string) Str::uuid();
        Cache::put($this->cacheKey($sessionId), [
            'request_id' => 'passport-request-2',
            'code_verifier' => 'verifier',
            'redirect_uri' => 'http://project.test/api/passport/callback',
            'status' => 'pending',
            'app_id' => 'project-test',
        ], 300);
        Request::replace(['session_id' => $sessionId]);
        $controller = new TestPassportController();
        $controller->responses[] = Base::retSuccess('success', ['status' => 'approved']);

        $result = $controller->login__status();

        $this->assertSame(1, $result['ret']);
        $this->assertSame('scanned', $result['data']['status']);
        $this->assertSame(
            '/api/v1/public/identity/authorize/request/passport-request-2',
            $controller->requests[0]['path']
        );
    }

    public function test_callback_is_bound_to_existing_state_and_rejects_expired_session(): void
    {
        $controller = new TestPassportController();
        Request::replace(['code' => 'authorization-code', 'state' => 'missing-session']);

        $expired = $controller->callback();

        $this->assertSame(410, $expired->getStatusCode());

        $sessionId = (string) Str::uuid();
        Cache::put($this->cacheKey($sessionId), [
            'request_id' => 'passport-request-3',
            'code_verifier' => 'verifier',
            'redirect_uri' => 'http://project.test/api/passport/callback',
            'status' => 'pending',
            'app_id' => 'project-test',
        ], 300);
        Request::replace(['code' => 'authorization-code', 'state' => $sessionId]);

        $accepted = $controller->callback();

        $this->assertSame(200, $accepted->getStatusCode());
        $cached = Cache::get($this->cacheKey($sessionId));
        $this->assertSame('authorization-code', $cached['authorization_code']);
        $this->assertSame('approved', $cached['status']);
    }

    public function test_verified_passport_email_claim_fills_placeholder_project_email(): void
    {
        $controller = new TestPassportController();
        $method = new \ReflectionMethod(PassportController::class, 'applyPassportEmailClaim');
        $method->setAccessible(true);

        $user = new User();
        $user->email = 'wallet-user@wallet.yeying.local';
        $user->email_verity = 0;
        $method->invoke($controller, $user, [
            'email' => 'Person@Example.com',
            'emailVerified' => true,
        ]);
        $this->assertSame('person@example.com', $user->email);
        $this->assertSame(1, $user->email_verity);

        $existing = new User();
        $existing->email = 'local@example.com';
        $existing->email_verity = 1;
        $method->invoke($controller, $existing, [
            'email' => 'other@example.com',
            'emailVerified' => true,
        ]);
        $this->assertSame('local@example.com', $existing->email);
        $this->assertSame(1, $existing->email_verity);
    }

    public function test_latest_passport_did_is_used_as_local_identity_key(): void
    {
        $controller = new TestPassportController();
        $method = new \ReflectionMethod(PassportController::class, 'normalizeDid');
        $method->setAccessible(true);

        $this->assertSame(
            'did:yeying:wid_1234567890123456789012',
            $method->invoke($controller, 'did:yeying:wid_1234567890123456789012')
        );
        $this->assertSame('', $method->invoke($controller, 'wid_1234567890123456789012'));
        $this->assertSame('', $method->invoke($controller, 'did:yeying:sub_1234567890123456789012'));
    }

    private function cacheKey(string $sessionId): string
    {
        return 'passport_login_session:' . hash('sha256', $sessionId);
    }
}
