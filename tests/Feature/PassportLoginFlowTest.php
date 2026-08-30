<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PassportController;
use App\Http\Controllers\Api\WalletAuthController;
use App\Models\User;
use App\Module\Base;
use App\Services\Wallet\IdentityCredentialVerifier;
use App\Services\Wallet\IdentityPresentationVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Request;
use RuntimeException;
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

class ExpiredTestIdentityCredentialVerifier extends IdentityCredentialVerifier
{
    public function verify(string $token, string $expectedDid, string $expectedType): array
    {
        throw new RuntimeException('identity_credential_expired');
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
        config()->set('dootask.passport_scope', 'identity.basic identity.username identity.email identity.wallet identity.avatar');
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
        $this->assertSame(['identity.basic', 'identity.username', 'identity.email', 'identity.wallet', 'identity.avatar'], $request['payload']['scopes']);
        $this->assertSame($result['data']['session_id'], $request['payload']['state']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $request['payload']['codeChallenge']);

        $cache = Cache::get($this->cacheKey($result['data']['session_id']));
        $this->assertSame('passport-request-1', $cache['request_id']);
        $this->assertNotEmpty($cache['code_verifier']);
    }

    public function test_passport_scope_always_includes_project_identity_username(): void
    {
        config()->set('dootask.passport_scope', 'identity.basic identity.email identity.wallet identity.avatar');
        $controller = new TestPassportController();
        $controller->responses[] = Base::retSuccess('success', [
            'requestId' => 'passport-request-username',
            'verifyUrl' => '/identity/authorize?requestId=passport-request-username',
            'status' => 'pending',
        ]);

        $controller->login__session();

        $this->assertContains('identity.username', $controller->requests[0]['payload']['scopes']);
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

    public function test_wallet_identity_expired_email_credential_does_not_raise_server_error(): void
    {
        app()->instance(IdentityCredentialVerifier::class, new ExpiredTestIdentityCredentialVerifier());

        $controller = new WalletAuthController();
        $method = new \ReflectionMethod(WalletAuthController::class, 'applyIdentityCredentials');
        $method->setAccessible(true);
        $user = new User();
        $user->email = 'wallet-user@wallet.yeying.local';
        $user->email_verity = 0;

        $method->invoke($controller, $user, [
            'holder' => 'did:yeying:wid_1234567890123456789012',
            'credentials' => ['expired-jwt-vc'],
        ]);

        $this->assertSame('wallet-user@wallet.yeying.local', $user->email);
        $this->assertSame(0, $user->email_verity);
    }

    public function test_wallet_identity_required_scope_rejects_missing_credential(): void
    {
        app()->instance(IdentityCredentialVerifier::class, new ExpiredTestIdentityCredentialVerifier());
        $controller = new WalletAuthController();
        $method = new \ReflectionMethod(WalletAuthController::class, 'applyIdentityCredentials');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identity_credential_required:EmailCredential');
        $method->invoke($controller, new User(), [
            'holder' => 'did:yeying:wid_1234567890123456789012',
            'credentials' => ['expired-jwt-vc'],
        ], ['identity.email']);
    }

    public function test_identity_document_must_belong_to_presentation_holder(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('identity_document_holder_mismatch');
        (new IdentityPresentationVerifier())->verify([
            'version' => 1,
            'holder' => 'did:yeying:wid_1234567890123456789012',
            'audience' => 'http://project.test',
            'nonce' => 'nonce',
            'issuedAt' => now()->subSecond()->toIso8601String(),
            'expiresAt' => now()->addMinute()->toIso8601String(),
            'scopes' => ['identity.basic'],
            'identityDocument' => [
                'id' => 'did:yeying:wid_abcdefghijklmnopqrstuvwxyz',
                'controllers' => [],
            ],
            'proof' => [
                'type' => 'YeyingIdentityPresentationProofV1',
                'purpose' => 'authentication',
                'verificationMethod' => 'did:yeying:wid_1234567890123456789012#controller',
                'proofValue' => 'invalid',
            ],
        ], [
            'audience' => 'http://project.test',
            'nonce' => 'nonce',
            'scopes' => ['identity.basic'],
        ]);
    }

    public function test_identity_trust_bundle_is_loaded_from_configured_directory(): void
    {
        $directory = storage_path('framework/testing/identity-trust-' . Str::uuid());
        mkdir($directory, 0700, true);
        $metadata = json_encode(['issuer' => 'did:web:node.test', 'jwks_uri' => 'https://node.test/.well-known/jwks.json'], JSON_UNESCAPED_SLASHES);
        $jwks = json_encode(['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'kid' => 'test', 'x' => str_repeat('a', 43)]]], JSON_UNESCAPED_SLASHES);
        file_put_contents("{$directory}/issuer-metadata.json", $metadata);
        file_put_contents("{$directory}/jwks.json", $jwks);
        file_put_contents("{$directory}/manifest.json", json_encode([
            'issuer' => 'did:web:node.test',
            'metadataSha256' => hash('sha256', $metadata),
            'jwksSha256' => hash('sha256', $jwks),
        ]));
        config()->set('dootask.passport_identity_trust_dir', $directory);
        $method = new \ReflectionMethod(IdentityCredentialVerifier::class, 'trustBundle');
        $method->setAccessible(true);

        try {
            $bundle = $method->invoke(new IdentityCredentialVerifier());
            $this->assertSame('did:web:node.test', $bundle['issuer']);
            $this->assertSame('test', $bundle['jwks']['keys'][0]['kid']);
        } finally {
            foreach (glob("{$directory}/*") ?: [] as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function test_wallet_identity_login_session_returns_issuer_endpoint(): void
    {
        Request::replace(['address' => '0x5c7bf91C493126314bb821C123Dee889FFCa3932']);
        $controller = new WalletAuthController();

        $result = $controller->login__session();

        $this->assertSame(1, $result['ret']);
        $this->assertSame('http://node.test', $result['data']['issuerEndpoint']);
        $this->assertSame(['identity.basic', 'identity.username', 'identity.wallet', 'identity.email', 'identity.avatar'], $result['data']['scopes']);
    }

    public function test_identity_avatar_uri_is_normalized_for_project_storage(): void
    {
        $walletController = new WalletAuthController();
        $walletMethod = new \ReflectionMethod(WalletAuthController::class, 'normalizeAvatarUri');
        $walletMethod->setAccessible(true);

        $passportController = new TestPassportController();
        $passportMethod = new \ReflectionMethod(PassportController::class, 'normalizeAvatarUri');
        $passportMethod->setAccessible(true);

        $this->assertSame('ipfs://bafyavatarcid', $walletMethod->invoke($walletController, ' ipfs://bafyavatarcid '));
        $this->assertSame('', $walletMethod->invoke($walletController, 'javascript:alert(1)'));
        $this->assertSame('', $walletMethod->invoke($walletController, str_repeat('a', 2049)));
        $this->assertSame(Base::unFillUrl('https://avatar.example/person.png'), $passportMethod->invoke($passportController, 'https://avatar.example/person.png'));
    }

    private function cacheKey(string $sessionId): string
    {
        return 'passport_login_session:' . hash('sha256', $sessionId);
    }
}
