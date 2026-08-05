<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\UserWallet;
use App\Module\Base;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Request;

/**
 * YeYing Passport 登录接入。
 *
 * Project 只负责创建登录请求、展示二维码、查询 Node 登录状态并换取本地会话。
 * 钱包签名、Passkey、手机确认和应用授权由 Node / Wallet 负责。
 */
class PassportController extends AbstractController
{
    private const SESSION_TTL_SECONDS = 300;

    /**
     * @api {post} api/passport/login/session 创建通行证登录会话
     *
     * @apiDescription 调用 Node Passport 创建二维码登录会话。未配置 PASSPORT_NODE_URL 时前端应回退旧二维码。
     * @apiVersion 1.0.0
     * @apiGroup passport
     * @apiName login__session
     */
    public function login__session(): array
    {
        $baseUrl = $this->nodeBaseUrl();
        if ($baseUrl === '') {
            return Base::retError('通行证登录未配置', ['code' => 'passport_not_configured']);
        }

        $appId = $this->clientId();
        if ($appId === '') {
            return Base::retError('通行证应用ID未配置', ['code' => 'passport_client_not_configured']);
        }
        $sessionId = (string)Str::uuid();
        $codeVerifier = $this->base64UrlEncode(random_bytes(64));
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
        $redirectUri = $this->callbackUrl();
        $payload = [
            'appId' => $appId,
            'redirectUri' => $redirectUri,
            'state' => $sessionId,
            'codeChallenge' => $codeChallenge,
            'codeChallengeMethod' => 'S256',
            'requestTtlMs' => self::SESSION_TTL_SECONDS * 1000,
        ];

        $result = $this->nodeRequest('POST', '/api/v1/public/auth/passport/authorize/request', $payload);
        if (!Base::isSuccess($result)) {
            return $result;
        }

        $data = $this->normalizeNodeData($result['data']);
        $requestId = trim((string)($data['requestId'] ?? $data['request_id'] ?? ''));
        if ($requestId === '') {
            return Base::retError('通行证服务返回异常', ['code' => 'passport_session_missing']);
        }

        $qrcodeUrl = trim((string)($data['verifyUrl'] ?? $data['verify_url'] ?? ''));

        Cache::put($this->sessionCacheKey($sessionId), [
            'request_id' => $requestId,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
            'status' => 'pending',
            'app_id' => $appId,
        ], self::SESSION_TTL_SECONDS);

        Log::info('passport login session created', [
            'event' => 'passport_login_session_created',
            'session_id' => substr($sessionId, 0, 12),
            'request_id' => substr($requestId, 0, 18),
            'app_id' => $appId,
        ]);

        return Base::retSuccess('success', [
            'session_id' => $sessionId,
            'qrcode_url' => $qrcodeUrl,
            'status' => $data['status'] ?? 'pending',
            'expires_at' => $data['expires_at'] ?? Carbon::now()->addSeconds(self::SESSION_TTL_SECONDS)->toIso8601String(),
            'poll_interval' => intval($data['poll_interval'] ?? 2) ?: 2,
        ]);
    }

    /**
     * @api {get} api/passport/login/status 查询通行证登录状态
     *
     * @apiParam {String} session_id 通行证登录会话 ID
     * @apiVersion 1.0.0
     * @apiGroup passport
     * @apiName login__status
     */
    public function login__status(): array
    {
        $sessionId = trim((string)Request::input('session_id'));
        if ($sessionId === '') {
            return Base::retError('session_id 不能为空');
        }
        $cache = Cache::get($this->sessionCacheKey($sessionId));
        if (!is_array($cache)) {
            Log::info('passport login status', [
                'event' => 'passport_login_status',
                'session_id' => substr($sessionId, 0, 12),
                'status' => 'expired',
                'reason' => 'cache_missing',
            ]);
            return Base::retError('通行证登录二维码已过期', ['code' => 'expired', 'status' => 'expired']);
        }

        if (!empty($cache['authorization_code'])) {
            return $this->completeLoginByAuthorizationCode($sessionId, $cache);
        }

        $requestId = trim((string)($cache['request_id'] ?? ''));
        if ($requestId === '') {
            Cache::forget($this->sessionCacheKey($sessionId));
            Log::info('passport login status', [
                'event' => 'passport_login_status',
                'session_id' => substr($sessionId, 0, 12),
                'status' => 'expired',
                'reason' => 'request_id_missing',
            ]);
            return Base::retError('通行证登录二维码已过期', ['code' => 'expired', 'status' => 'expired']);
        }

        $result = $this->nodeRequest('GET', '/api/v1/public/auth/passport/authorize/request/' . rawurlencode($requestId));
        if (!Base::isSuccess($result)) {
            return $result;
        }

        $data = $this->normalizeNodeData($result['data']);
        $status = strtolower(trim((string)($data['status'] ?? 'pending')));
        Log::info('passport login status', [
            'event' => 'passport_login_status',
            'session_id' => substr($sessionId, 0, 12),
            'request_id' => substr($requestId, 0, 18),
            'status' => $status ?: 'pending',
        ]);
        if (!in_array($status, ['approved', 'success', 'confirmed'], true)) {
            return Base::retSuccess('success', [
                'status' => $status ?: 'pending',
                'message' => $data['message'] ?? '',
            ]);
        }

        return Base::retSuccess('success', [
            'status' => 'scanned',
            'message' => '请在手机上确认登录',
        ]);
    }

    /**
     * @api {get} api/passport/callback 接收 Node Passport 登录回调
     *
     * @apiParam {String} code Node 一次性授权码
     * @apiParam {String} state Project 本地登录会话 ID
     * @apiVersion 1.0.0
     * @apiGroup passport
     * @apiName callback
     */
    public function callback()
    {
        $code = trim((string)Request::input('code'));
        $sessionId = trim((string)Request::input('state'));
        if ($code === '' || $sessionId === '') {
            return response('通行证登录回调参数不完整，请关闭页面后重新扫码。', 400);
        }

        $cacheKey = $this->sessionCacheKey($sessionId);
        $cache = Cache::get($cacheKey);
        if (!is_array($cache)) {
            return response('通行证登录二维码已过期，请回到电脑端刷新二维码。', 410);
        }

        $cache['authorization_code'] = $code;
        $cache['status'] = 'approved';
        Cache::put($cacheKey, $cache, self::SESSION_TTL_SECONDS);

        Log::info('passport login callback received', [
            'event' => 'passport_login_callback_received',
            'session_id' => substr($sessionId, 0, 12),
            'request_id' => substr((string)($cache['request_id'] ?? ''), 0, 18),
        ]);

        return response($this->callbackHtml($sessionId), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function completeLoginByAuthorizationCode(string $sessionId, array $cache): array
    {
        $code = trim((string)($cache['authorization_code'] ?? ''));
        if ($code === '') {
            return Base::retSuccess('success', [
                'status' => 'scanned',
                'message' => '请在手机上确认登录',
            ]);
        }

        $identity = $this->exchangeCode($code, $cache);
        if (!Base::isSuccess($identity)) {
            Log::info('passport login exchange failed', [
                'event' => 'passport_login_exchange_failed',
                'session_id' => substr($sessionId, 0, 12),
                'request_id' => substr((string)($cache['request_id'] ?? ''), 0, 18),
                'code' => $identity['data']['code'] ?? 'unknown',
            ]);
            return $identity;
        }

        $login = $this->loginByPassportIdentity($identity['data']);
        if (!Base::isSuccess($login)) {
            Cache::forget($this->sessionCacheKey($sessionId));
            Log::info('passport login bind failed', [
                'event' => 'passport_login_bind_failed',
                'session_id' => substr($sessionId, 0, 12),
                'request_id' => substr((string)($cache['request_id'] ?? ''), 0, 18),
                'code' => $login['data']['code'] ?? 'unknown',
            ]);
            return $login;
        }

        Cache::forget($this->sessionCacheKey($sessionId));
        Log::info('passport login completed', [
            'event' => 'passport_login_completed',
            'session_id' => substr($sessionId, 0, 12),
            'request_id' => substr((string)($cache['request_id'] ?? ''), 0, 18),
            'userid' => $login['data']->userid ?? null,
        ]);
        return Base::retSuccess('success', array_merge($login['data']->toArray(), [
            'token' => $login['data']->token,
            'status' => 'approved',
        ]));
    }

    private function exchangeCode(string $code, array $cache): array
    {
        $payload = [
            'code' => $code,
            'appId' => trim((string)($cache['app_id'] ?? $this->clientId())),
            'redirectUri' => trim((string)($cache['redirect_uri'] ?? $this->callbackUrl())),
            'codeVerifier' => trim((string)($cache['code_verifier'] ?? '')),
        ];

        return $this->nodeRequest('POST', '/api/v1/public/auth/passport/authorize/exchange', $payload);
    }

    private function loginByPassportIdentity(array $identity): array
    {
        $address = strtolower(trim((string)($identity['walletAddress'] ?? '')));
        $chain = 'eip155';
        $chainId = trim((string)config('dootask.wallet_chain_id', '1'));

        if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            return Base::retError('通行证未返回有效钱包地址', ['code' => 'passport_wallet_missing']);
        }

        $userWallet = UserWallet::where('chain', $chain)
            ->where('chain_id', $chainId)
            ->where('address_normalized', $address)
            ->first();
        if (!$userWallet) {
            return Base::retError('该通行证尚未绑定 Project 账号，请先使用邮箱账号登录后绑定钱包', [
                'code' => 'passport_wallet_unbound',
                'address' => $address,
                'chain_id' => $chainId,
            ]);
        }

        $user = User::whereUserid($userWallet->userid)->first();
        if (!$user || $user->disable_at) {
            return Base::retError('通行证绑定的账号不可用', ['code' => 'passport_user_disabled']);
        }
        if (!$user->email || str_ends_with($user->email, '@wallet.yeying.local') || intval($user->email_verity) !== 1) {
            return Base::retError('请先设置并验证邮箱', ['code' => 'wallet_email_required']);
        }

        $userWallet->update(['last_login_at' => Carbon::now()]);
        $user->updateInstance([
            'login_num' => $user->login_num + 1,
            'last_ip' => Base::getIp(),
            'last_at' => Carbon::now(),
            'line_ip' => Base::getIp(),
            'line_at' => Carbon::now(),
        ]);
        $user->save();
        User::generateToken($user, true);

        return Base::retSuccess('success', $user);
    }

    private function nodeRequest(string $method, string $path, array $payload = []): array
    {
        $baseUrl = $this->nodeBaseUrl();
        if ($baseUrl === '') {
            return Base::retError('通行证登录未配置', ['code' => 'passport_not_configured']);
        }

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Language' => (string)Base::headerOrInput('language'),
                'X-YeYing-Client' => $this->clientId(),
            ],
        ];
        if ($payload !== []) {
            $options['json'] = $payload;
        }

        try {
            $response = (new Client([
                'base_uri' => $baseUrl,
                'timeout' => 15,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]))->request($method, $path, $options);
        } catch (GuzzleException $e) {
            return Base::retError('无法连接通行证服务', ['code' => 'passport_unreachable', 'error' => $e->getMessage()]);
        }

        $body = json_decode((string)$response->getBody(), true);
        if (!is_array($body)) {
            return Base::retError('通行证服务返回异常', [
                'code' => 'passport_invalid_response',
                'status' => $response->getStatusCode(),
            ]);
        }

        $ret = $body['ret'] ?? null;
        $code = intval($body['code'] ?? $response->getStatusCode());
        $message = trim((string)($body['msg'] ?? $body['message'] ?? 'success'));
        $data = $body['data'] ?? $body;

        if ($ret === 1 || ($ret === null && ($code === 0 || ($code >= 200 && $code < 300)))) {
            return Base::retSuccess($message ?: 'success', $data);
        }

        return Base::retError($message ?: '通行证服务返回错误', [
            'code' => $body['code'] ?? $code,
            'data' => $data,
        ]);
    }

    private function normalizeNodeData($data): array
    {
        return is_array($data) ? $data : [];
    }

    private function nodeBaseUrl(): string
    {
        return rtrim(trim((string)config('dootask.passport_node_url', '')), '/');
    }

    private function clientId(): string
    {
        return trim((string)config('dootask.passport_client_id', ''));
    }

    private function scope(): array
    {
        $scope = trim((string)config('dootask.passport_scope', 'openid profile wallet'));
        return array_values(array_filter(preg_split('/\s+/', $scope) ?: []));
    }

    private function callbackUrl(): string
    {
        return $this->projectOrigin() . '/api/passport/callback';
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

    private function sessionCacheKey(string $sessionId): string
    {
        return 'passport_login_session:' . hash('sha256', $sessionId);
    }

    private function callbackHtml(string $sessionId): string
    {
        $payload = json_encode([
            'sessionId' => $sessionId,
            'status' => 'approved',
            'time' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}';

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>YeYing Passport</title>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;padding:32px;line-height:1.6;color:#1f2937">
    <h3>通行证登录已确认</h3>
    <p>正在通知 Project 登录页，请回到电脑端继续使用。</p>
    <script>
        (function () {
            var payload = {$payload};
            try {
                window.localStorage.setItem('__project_passport_callback__', JSON.stringify(payload));
            } catch (e) {}
            try {
                var channel = new BroadcastChannel('project-passport-login');
                channel.postMessage(payload);
                channel.close();
            } catch (e) {}
            setTimeout(function () {
                window.close();
            }, 1200);
        })();
    </script>
</body>
</html>
HTML;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
