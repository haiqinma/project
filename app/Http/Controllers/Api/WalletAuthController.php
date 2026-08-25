<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\UserWallet;
use App\Models\UserEmailVerification;
use App\Module\Base;
use App\Module\Doo;
use App\Services\Wallet\WalletSignatureService;
use App\Services\Wallet\IdentityCredentialVerifier;
use App\Services\Wallet\IdentityPresentationVerifier;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Request;
use Throwable;

class WalletAuthController extends AbstractController
{
    private const CHALLENGE_TTL = 300;
    private const IDENTITY_LOGIN_TTL = 300;

    public function challenge()
    {
        $address = $this->normalizeAddress(Request::input('address'));
        $chainId = trim((string)Request::input('chain_id', config('dootask.wallet_chain_id', '1')));
        $this->validateChain($chainId);
        $nonce = Str::random(32);
        $issuedAt = Carbon::now()->toIso8601String();
        $message = Request::getHost() . " wants you to sign in to YeYing.\n\nAddress: {$address}\nChain ID: {$chainId}\nNonce: {$nonce}\nIssued At: {$issuedAt}";
        Cache::put($this->challengeKey($address, $chainId), [
            'message' => $message,
            'address' => $address,
            'chain_id' => $chainId,
        ], self::CHALLENGE_TTL);
        $identity = null;
        $identityScopes = $this->identityScopes(Request::input('identity_scopes', []));
        if ($identityScopes) {
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $identity = $this->nodeIdentityRequest('/api/v1/public/identity/authorize/request', [
                'appId' => config('dootask.passport_client_id'),
                'redirectUri' => $this->identityCallbackUrl(),
                'codeChallenge' => $challenge,
                'codeChallengeMethod' => 'S256',
                'scopes' => $identityScopes,
            ]);
            Cache::put($this->challengeKey($address, $chainId), array_merge(Cache::get($this->challengeKey($address, $chainId)), [
                'identity' => ['request' => $identity, 'verifier' => $verifier, 'scopes' => $identityScopes],
            ]), self::CHALLENGE_TTL);
        }
        return Base::retSuccess('success', [
            'challenge' => $message,
            'nonce' => $nonce,
            'expires_at' => Carbon::now()->addSeconds(self::CHALLENGE_TTL)->toIso8601String(),
            ...($identity ? ['identity_authorization' => $identity] : []),
        ]);
    }

    public function verify()
    {
        $address = $this->normalizeAddress(Request::input('address'));
        $chainId = trim((string)Request::input('chain_id', config('dootask.wallet_chain_id', '1')));
        $this->validateChain($chainId);
        $signature = trim((string)Request::input('signature'));
        $challenge = Cache::pull($this->challengeKey($address, $chainId));
        if (!is_array($challenge)) {
            return Base::retError('钱包登录挑战已过期或无效', ['code' => 'wallet_challenge_invalid']);
        }
        $recovered = app(WalletSignatureService::class)->recoverPersonalSignAddress($challenge['message'], $signature);
        if ($recovered !== $address) {
            return Base::retError('钱包签名地址不匹配', ['code' => 'wallet_signature_mismatch']);
        }
        $identityResult = null;
        if (!empty($challenge['identity'])) {
            $presentation = Request::input('identity_presentation');
            if (!is_array($presentation)) return Base::retError('缺少钱包身份授权证明', ['code' => 'identity_presentation_required']);
            $approved = $this->nodeIdentityRequest('/api/v1/public/identity/authorize/approve', [
                'requestId' => $challenge['identity']['request']['requestId'], 'presentation' => $presentation,
            ]);
            $identityResult = $this->nodeIdentityRequest('/api/v1/public/identity/authorize/exchange', [
                'code' => $approved['authorizationCode'],
                'appId' => config('dootask.passport_client_id'),
                'redirectUri' => $this->identityCallbackUrl(),
                'codeVerifier' => $challenge['identity']['verifier'],
            ]);
            try {
                $identityResult['did'] = $this->normalizeDid($identityResult['did'] ?? '');
                foreach (($identityResult['credentials'] ?? []) as $credential) {
                    $type = $credential['type'] ?? '';
                    if ($type === 'EmailCredential') app(IdentityCredentialVerifier::class)->verify($credential['credential'] ?? '', $identityResult['did'], 'EmailCredential');
                    if ($type === 'UsernameCredential') app(IdentityCredentialVerifier::class)->verify($credential['credential'] ?? '', $identityResult['did'], 'UsernameCredential');
                }
            } catch (Throwable) {
                return Base::retError('请先在钱包身份中完成邮箱验证', ['code' => 'wallet_email_required']);
            }
        }
        $wallet = UserWallet::where('chain', 'eip155')->where('chain_id', $chainId)->where('address_normalized', $address)->first();
        if ($identityResult) {
            $wallet = UserWallet::where('wallet_identity_did', $identityResult['did'])->first() ?: $wallet;
        }
        if (!$wallet) {
            $placeholder = 'wallet-' . substr(hash('sha256', $address . ':' . $chainId), 0, 24) . '@wallet.yeying.local';
            $user = User::whereEmail($placeholder)->first();
            if (!$user) {
                $user = User::reg($placeholder, Str::random(32), ['nickname' => '夜莺用户']);
                $user->email_verity = 0;
                $user->save();
            }
            $wallet = UserWallet::createInstance([
                'userid' => $user->userid,
                'chain' => 'eip155',
                'chain_id' => $chainId,
                'address' => $address,
                'address_normalized' => $address,
                'last_login_at' => Carbon::now(),
            ]);
            $wallet->save();
            $setupToken = $this->issueEmailSetupToken($user->userid);
            return Base::retError('首次使用钱包登录，请先设置并验证邮箱', [
                'code' => 'wallet_email_required',
                'address' => $address,
                'chain_id' => $chainId,
                'setup_token' => $setupToken,
            ]);
        }
        if ($identityResult && $wallet->wallet_identity_did !== $identityResult['did']) {
            $wallet->update(['wallet_identity_did' => $identityResult['did']]);
        }
        $user = User::where('userid', $wallet->userid)->first();
        if (!$user || $user->disable_at) {
            return Base::retError('钱包绑定的账号不可用');
        }
        if (!$user->email || str_ends_with($user->email, '@wallet.yeying.local') || intval($user->email_verity) !== 1) {
            $setupToken = $this->issueEmailSetupToken($user->userid);
            return Base::retError('请先设置并验证邮箱', [
                'code' => 'wallet_email_required',
                'address' => $address,
                'chain_id' => $chainId,
                'setup_token' => $setupToken,
            ]);
        }
        $wallet->update(['last_login_at' => Carbon::now()]);
        return Base::retSuccess('success', [
            'token' => User::generateTokenNoDevice($user, max(1, intval(Base::settingFind('system', 'token_valid_days', 30))) * 86400),
            'userid' => $user->userid,
            'address' => $address,
            'is_new' => false,
        ]);
    }

    public function email()
    {
        $setupToken = trim((string)Request::input('setup_token', Request::input('token')));
        $setupKey = 'wallet_email_setup:' . hash('sha256', $setupToken);
        $userid = $setupToken ? Cache::get($setupKey) : null;
        $user = $userid ? User::whereUserid($userid)->first() : null;
        if (!$user) {
            return Base::retError('邮箱补全凭证已失效，请重新进行钱包登录', ['code' => 'wallet_email_setup_expired']);
        }
        $email = strtolower(trim((string)Request::input('email')));
        if (!Base::isEmail($email) || str_ends_with($email, '@wallet.yeying.local')) {
            return Base::retError('请输入有效邮箱地址');
        }
        if (User::where('userid', '<>', $user->userid)->whereEmail($email)->exists()) {
            return Base::retError('邮箱地址已存在');
        }
        $user->email = $email;
        $user->email_verity = 0;
        $username = trim((string)Request::input('username'));
        $currentNickname = trim((string)$user->getRawOriginal('nickname'));
        if (in_array($currentNickname, ['', '夜莺用户'], true)
            && mb_strlen($username) >= 2
            && mb_strlen($username) <= 20) {
            $user->nickname = $username;
            $user->az = Base::getFirstCharter($username);
            $user->pinyin = Base::cn2pinyin($username);
        }
        $user->save();
        UserEmailVerification::userEmailSend($user, 1, $email);
        Cache::forget($setupKey);
        return Base::retSuccess('验证邮件已发送，请登录邮箱完成验证', [
            'email' => $email,
        ]);
    }

    public function info()
    {
        $user = User::auth();
        $wallet = UserWallet::whereUserid($user->userid)
            ->orderByDesc('id')
            ->first(['address', 'chain', 'chain_id']);
        return Base::retSuccess('success', $wallet ?: json_decode('{}'));
    }

    private function issueEmailSetupToken(int $userid): string
    {
        $token = Str::random(64);
        Cache::put('wallet_email_setup:' . hash('sha256', $token), $userid, 86400);
        return $token;
    }

    public function bind()
    {
        $userid = Doo::userId();
        if (!$userid) {
            return Base::retError('请先登录夜莺账号', ['code' => 'login_required']);
        }
        $address = $this->normalizeAddress(Request::input('address'));
        $chainId = trim((string)Request::input('chain_id', config('dootask.wallet_chain_id', '1')));
        $this->validateChain($chainId);
        $challenge = Cache::pull($this->challengeKey($address, $chainId));
        if (!is_array($challenge)) {
            return Base::retError('钱包绑定挑战已过期或无效', ['code' => 'wallet_challenge_invalid']);
        }
        $signature = trim((string)Request::input('signature'));
        $recovered = app(WalletSignatureService::class)->recoverPersonalSignAddress($challenge['message'], $signature);
        if ($recovered !== $address) {
            return Base::retError('钱包签名地址不匹配', ['code' => 'wallet_signature_mismatch']);
        }
        $existing = UserWallet::where('chain', 'eip155')->where('chain_id', $chainId)->where('address_normalized', $address)->first();
        if ($existing && intval($existing->userid) !== $userid) {
            return Base::retError('该钱包已绑定其他夜莺账号', ['code' => 'wallet_already_bound']);
        }
        if (!$existing) {
            $wallet = UserWallet::createInstance([
                'userid' => $userid,
                'chain' => 'eip155',
                'chain_id' => $chainId,
                'address' => $address,
                'address_normalized' => $address,
                'last_login_at' => Carbon::now(),
            ]);
            $wallet->save();
        }
        return Base::retSuccess('钱包绑定成功', ['address' => $address, 'chain_id' => $chainId]);
    }

    public function login__session()
    {
        $address = $this->normalizeAddress(Request::input('address'));
        $chainId = trim((string)config('dootask.wallet_chain_id', '1'));
        $this->validateChain($chainId);
        $sessionId = (string)Str::uuid();
        $scopes = $this->identityScopes(Request::input('identity_scopes', [
            'identity.basic',
            'identity.wallet',
            'identity.email',
        ]));
        $nonce = $this->base64UrlEncode(random_bytes(32));
        $audience = $this->projectOrigin();
        Cache::put($this->identityLoginKey($sessionId), [
            'address' => $address,
            'chain_id' => $chainId,
            'audience' => $audience,
            'nonce' => $nonce,
            'scopes' => $scopes,
        ], self::IDENTITY_LOGIN_TTL);
        return Base::retSuccess('success', [
            'session_id' => $sessionId,
            'request_id' => $sessionId,
            'audience' => $audience,
            'nonce' => $nonce,
            'scopes' => $scopes,
            'issuerEndpoint' => $this->nodeBaseUrl(),
            'expires_at' => Carbon::now()->addSeconds(self::IDENTITY_LOGIN_TTL)->toIso8601String(),
        ]);
    }

    public function login__verify()
    {
        $sessionId = trim((string)Request::input('session_id'));
        $address = $this->normalizeAddress(Request::input('address'));
        $session = $sessionId ? Cache::pull($this->identityLoginKey($sessionId)) : null;
        if (!is_array($session) || ($session['address'] ?? '') !== $address) {
            return $this->sdkError('钱包身份登录会话已过期或无效', 'wallet_identity_session_invalid');
        }
        $presentation = Request::input('presentation', Request::input('identity_presentation'));
        if (!is_array($presentation)) {
            return $this->sdkError('缺少钱包身份授权证明', 'identity_presentation_required');
        }
        $verifier = app(IdentityPresentationVerifier::class);
        try {
            $presentation = $verifier->verify($presentation, [
                'audience' => $session['audience'],
                'nonce' => $session['nonce'],
                'scopes' => $session['scopes'],
            ]);
        } catch (Throwable) {
            return $this->sdkError('缺少钱包身份授权证明', 'identity_presentation_required');
        }
        $proofAddress = $this->normalizeAddress($verifier->walletAddress($presentation));
        if ($proofAddress !== $address) {
            return $this->sdkError('钱包身份授权地址不匹配', 'identity_wallet_mismatch');
        }
        $did = $this->normalizeDid($presentation['holder'] ?? '');
        if ($did === '') {
            return $this->sdkError('钱包身份服务返回异常', 'wallet_identity_missing');
        }

        $wallet = UserWallet::where('wallet_identity_did', $did)->first();
        if (!$wallet) {
            return $this->sdkError('请在夜莺钱包中继续完成登录确认', 'wallet_identity_unbound', [
                'reason' => 'wallet_confirmation_required',
            ]);
        }

        $user = User::whereUserid($wallet->userid)->first();
        if (!$user || $user->disable_at) {
            return $this->sdkError('钱包绑定的账号不可用', 'wallet_user_disabled');
        }
        $this->applyIdentityCredentials($user, $presentation);
        if (!$user->email || str_ends_with($user->email, '@wallet.yeying.local') || intval($user->email_verity) !== 1) {
            return $this->sdkError('请先在钱包身份中完成邮箱验证', 'wallet_email_required');
        }

        $wallet->update(['last_login_at' => Carbon::now()]);
        return Base::retSuccess('success', [
            'token' => User::generateTokenNoDevice($user, max(1, intval(Base::settingFind('system', 'token_valid_days', 30))) * 86400),
            'userid' => $user->userid,
            'did' => $did,
            'walletAddress' => $address,
        ]);
    }

    public function account__challenge()
    {
        $address = $this->normalizeAddress(Request::input('address'));
        $chainKey = $this->normalizeChainKey(Request::input('chainKey'));
        $identity = $this->normalizeDid(Request::input('identity'));
        $result = $this->nodeIdentityRequest('/api/v1/public/identity/account-links/challenge', [
            'identity' => $identity,
            'account' => ['chainKey' => $chainKey, 'address' => $address],
        ]);
        return Base::retSuccess('success', $result);
    }

    public function account__verify()
    {
        $address = $this->normalizeAddress(Request::input('address'));
        $chainKey = $this->normalizeChainKey(Request::input('chainKey'));
        $identity = $this->normalizeDid(Request::input('identity'));
        $payload = [
            'identityDocument' => Request::input('identityDocument'),
            'identity' => $identity,
            'account' => ['chainKey' => $chainKey, 'address' => $address],
            'nonce' => trim((string)Request::input('nonce')),
            'issuedAt' => trim((string)Request::input('issuedAt')),
            'expiresAt' => trim((string)Request::input('expiresAt')),
            'accountSignature' => trim((string)Request::input('accountSignature')),
        ];
        $result = $this->nodeIdentityRequest('/api/v1/public/identity/account-links/verify', $payload);
        $this->upsertWalletIdentity($identity, $address, $this->chainIdFromChainKey($chainKey));
        return Base::retSuccess('success', $result);
    }

    private function applyIdentityCredentials(User $user, array $presentation): void
    {
        foreach (app(IdentityPresentationVerifier::class)->credentialTokens($presentation) as $credential) {
            try {
                $claims = app(IdentityCredentialVerifier::class)->verify($credential, $presentation['holder'], 'EmailCredential');
            } catch (Throwable) {
                continue;
            }
            $email = strtolower(trim((string)data_get($claims, 'vc.credentialSubject.email', '')));
            if (!Base::isEmail($email)) {
                continue;
            }
            $current = strtolower(trim((string)$user->email));
            if ($current === '' || str_ends_with($current, '@wallet.yeying.local') || $current === $email) {
                $user->email = $email;
                $user->email_verity = 1;
                $user->save();
            }
        }
    }

    private function upsertWalletIdentity(string $did, string $address, string $chainId): UserWallet
    {
        $wallet = UserWallet::where('wallet_identity_did', $did)->first()
            ?: UserWallet::where('chain', 'eip155')->where('chain_id', $chainId)->where('address_normalized', $address)->first();
        if ($wallet) {
            if ($wallet->wallet_identity_did !== $did) {
                $wallet->update(['wallet_identity_did' => $did]);
            }
            return $wallet;
        }
        $placeholder = 'wallet-' . substr(hash('sha256', $address . ':' . $chainId), 0, 24) . '@wallet.yeying.local';
        $user = User::whereEmail($placeholder)->first();
        if (!$user) {
            $user = User::reg($placeholder, Str::random(32), ['nickname' => '夜莺用户']);
            $user->email_verity = 0;
            $user->save();
        }
        $wallet = UserWallet::createInstance([
            'userid' => $user->userid,
            'chain' => 'eip155',
            'chain_id' => $chainId,
            'address' => $address,
            'address_normalized' => $address,
            'wallet_identity_did' => $did,
            'last_login_at' => Carbon::now(),
        ]);
        $wallet->save();
        return $wallet;
    }

    private function sdkError(string $message, string $code, array $extra = []): array
    {
        return array_merge(Base::retError($message, ['code' => $code]), [
            'code' => $code,
            'message' => $message,
        ], $extra);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function identityLoginKey(string $sessionId): string
    {
        return 'wallet_identity_login:' . hash('sha256', $sessionId);
    }

    private function normalizeAddress($address): string
    {
        $address = trim((string)$address);
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            abort(422, '钱包地址格式无效');
        }
        return strtolower($address);
    }

    private function normalizeChainKey($chainKey): string
    {
        $chainKey = trim((string)$chainKey);
        if ($chainKey === '') {
            $chainKey = 'eip155:' . config('dootask.wallet_chain_id', '1');
        }
        if (!preg_match('/^eip155:[0-9]+$/', $chainKey)) {
            abort(422, '暂不支持该链网络');
        }
        $this->validateChain($this->chainIdFromChainKey($chainKey));
        return $chainKey;
    }

    private function chainIdFromChainKey(string $chainKey): string
    {
        return substr($chainKey, strlen('eip155:'));
    }

    private function normalizeDid($did): string
    {
        $did = trim((string)$did);
        if (!preg_match('/^did:yeying:wid_[A-Za-z0-9_-]{22,}$/', $did)) {
            abort(422, '钱包身份格式无效');
        }
        return $did;
    }

    private function validateChain(string $chainId): void
    {
        if ($chainId !== (string)config('dootask.wallet_chain_id', '1')) {
            abort(422, '暂不支持该链网络');
        }
    }

    private function challengeKey(string $address, string $chainId): string
    {
        return 'wallet_login_challenge:' . $chainId . ':' . $address;
    }

    private function identityScopes($input): array
    {
        $scopes = is_array($input) ? $input : preg_split('/\s+/', trim((string)$input));
        $allowed = ['identity.basic', 'identity.wallet', 'identity.username', 'identity.email'];
        $scopes = array_values(array_unique(array_filter(array_map('trim', $scopes))));
        if (array_diff($scopes, $allowed)) abort(422, '不支持的钱包身份 scope');
        return $scopes;
    }

    private function identityCallbackUrl(): string
    {
        // Wallet login completes in the browser and does not follow a redirect.
        // Reuse the application's registered callback URI for Node's audience binding.
        return rtrim((string)config('app.url'), '/') . '/api/passport/callback';
    }

    private function projectOrigin(): string
    {
        $appUrl = rtrim((string)config('app.url'), '/');
        if ($appUrl !== '') {
            $parts = parse_url($appUrl);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        return Request::getSchemeAndHttpHost();
    }

    private function nodeBaseUrl(): string
    {
        return rtrim(trim((string)config('dootask.passport_node_url', '')), '/');
    }

    private function nodeIdentityRequest(string $path, array $payload): array
    {
        $base = rtrim(trim((string)config('dootask.passport_node_url', '')), '/');
        if ($base === '' || !config('dootask.passport_client_id')) abort(503, '钱包身份服务未配置');
        $response = (new Client(['base_uri' => $base, 'timeout' => 15, 'connect_timeout' => 5]))->post($path, ['json' => $payload]);
        $body = json_decode((string)$response->getBody(), true);
        if (!is_array($body) || intval($body['code'] ?? -1) !== 0 || !is_array($body['data'] ?? null)) abort(502, '钱包身份服务返回异常');
        return $body['data'];
    }
}
