<?php

namespace App\Services;

use App\Contracts\AutomationTokenCreationAuthorizer;
use App\Exceptions\ApiException;
use App\Models\AutomationToken;
use App\Models\AutomationTokenAudit;
use App\Models\AutomationTokenNonce;
use App\Models\ProjectUser;
use App\Models\ProjectTask;
use App\Models\ProjectTaskFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\QueryException;

class AutomationTokenService
{
    public static function issue(int $userid, string $name, array $projectIds, Carbon $expiresAt): array
    {
        $secret = 'yysk_' . bin2hex(random_bytes(24));
        $token = AutomationToken::createInstance([
            'userid' => $userid,
            'access_key' => 'yyak_' . bin2hex(random_bytes(12)),
            'secret_hash' => hash('sha256', $secret),
            'name' => $name,
            // Kept for database compatibility. Authorization is now defined by project
            // scope plus the token owner's existing Project permissions.
            'scopes' => [],
            'project_ids' => array_values(array_map('intval', $projectIds)),
            'expires_at' => $expiresAt,
            'status' => AutomationToken::STATUS_ACTIVE,
        ]);
        $token->save();
        self::audit($token, 'token.create', 'success');

        return ['token' => $token, 'secret_key' => $secret];
    }

    public static function authorizeCreation(User $user, Request $request): void
    {
        $authorizer = config('dootask.automation_token_creation_authorizer');
        if (!$authorizer) {
            return;
        }
        $instance = app($authorizer);
        if (!$instance instanceof AutomationTokenCreationAuthorizer) {
            throw new \LogicException('访问令牌创建授权器配置无效');
        }
        $instance->authorize($user, $request);
    }

    public static function rotate(AutomationToken $token): string
    {
        if ($token->status !== AutomationToken::STATUS_ACTIVE || $token->expires_at->isPast()) {
            throw new ApiException('仅可轮换生效中的令牌');
        }
        $secret = 'yysk_' . bin2hex(random_bytes(24));
        $token->updateInstance([
            'secret_hash' => hash('sha256', $secret),
        ]);
        $token->save();
        AutomationTokenNonce::whereTokenId($token->id)->delete();
        self::audit($token, 'token.rotate', 'success');
        return $secret;
    }

    public static function authenticate(Request $request): AutomationToken
    {
        $accessKey = trim((string) $request->header('X-YY-AK'));
        $timestamp = trim((string) $request->header('X-YY-Timestamp'));
        $nonce = trim((string) $request->header('X-YY-Nonce'));
        $signature = strtolower(trim((string) $request->header('X-YY-Signature')));
        $token = $accessKey === '' ? null : AutomationToken::whereAccessKey($accessKey)->first();

        if (!$token || $token->status !== AutomationToken::STATUS_ACTIVE || !$timestamp || !$nonce || !$signature) {
            self::audit($token, 'auth.verify', 'invalid_credentials', $request);
            throw new ApiException('访问令牌认证失败');
        }
        if ($token->expires_at->isPast()) {
            $token->updateInstance(['status' => AutomationToken::STATUS_EXPIRED]);
            $token->save();
            self::audit($token, 'auth.verify', 'expired', $request);
            throw new ApiException('访问令牌认证失败');
        }
        try {
            $requestTime = Carbon::parse($timestamp);
        } catch (\Throwable) {
            self::audit($token, 'auth.verify', 'invalid_timestamp', $request);
            throw new ApiException('访问令牌认证失败');
        }
        $window = (int) config('dootask.automation_token_time_window', 300);
        if (abs($requestTime->diffInSeconds(now(), false)) > $window) {
            self::audit($token, 'auth.verify', 'invalid_timestamp', $request);
            throw new ApiException('访问令牌认证失败');
        }
        $canonical = implode("\n", [
            strtoupper($request->method()),
            '/' . ltrim($request->path(), '/'),
            self::canonicalQuery($request->query()),
            hash('sha256', $request->getContent()),
            $timestamp,
            $nonce,
        ]);
        $expected = hash_hmac('sha256', $canonical, $token->secret_hash);
        if (!hash_equals($expected, $signature)) {
            self::audit($token, 'auth.verify', 'invalid_signature', $request);
            throw new ApiException('访问令牌认证失败');
        }

        $rateKey = "automation_token_rate:{$token->id}";
        $rateLimit = max(1, (int) config('dootask.automation_token_rate_per_minute', 60));
        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            self::audit($token, 'auth.verify', 'rate_limited', $request);
            throw new ApiException('访问令牌请求过于频繁');
        }
        RateLimiter::hit($rateKey, 60);

        if (random_int(1, 100) === 1) {
            AutomationTokenNonce::where('expires_at', '<', now())->delete();
        }
        try {
            $nonceRow = AutomationTokenNonce::createInstance([
                'token_id' => $token->id,
                'nonce_hash' => hash('sha256', $nonce),
                'expires_at' => now()->addSeconds($window),
                'created_at' => now(),
            ]);
            $nonceRow->save();
        } catch (QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($sqlState !== '23505' && $driverCode !== 1062) {
                throw $e;
            }
            self::audit($token, 'auth.verify', 'replayed_nonce', $request);
            throw new ApiException('访问令牌认证失败');
        }
        $user = User::whereUserid($token->userid)->whereNull('disable_at')->first();
        if (!$user) {
            self::audit($token, 'auth.verify', 'invalid_user', $request);
            throw new ApiException('访问令牌认证失败');
        }

        $token->updateInstance(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        $token->save();
        $request->attributes->set('automation_token', $token);
        $request->attributes->set('access_token_user', $user);
        RequestContext::save('auth', $user);
        return $token;
    }

    /**
     * 标准业务 API 的令牌边界：复用所属用户原有权限，并限制在令牌项目范围内。
     */
    public static function authorizeStandardRequest(AutomationToken $token, Request $request): void
    {
        $path = trim($request->path(), '/');
        if (!str_starts_with($path, 'api/')) {
            self::forbidStandardRequest($token, $request);
        }
        $resource = substr($path, 4);
        if ($resource === 'project/lists') {
            RequestContext::save('access_token_project_ids', $token->project_ids);
            return;
        }
        if (str_starts_with($resource, 'project/')) {
            $projectId = self::standardRequestProjectId($request);
            if ($projectId > 0) {
                self::authorizeProjectMembership($token, $projectId, $request);
                return;
            }
        }
        if (in_array($resource, ['dialog/msg/list', 'dialog/msg/sendtext'], true)) {
            $dialogId = intval($request->input('dialog_id'));
            $projectId = $dialogId > 0 ? intval(ProjectTask::whereDialogId($dialogId)->value('project_id')) : 0;
            if ($projectId > 0) {
                self::authorizeProjectMembership($token, $projectId, $request);
                return;
            }
        }
        self::forbidStandardRequest($token, $request);
    }

    private static function standardRequestProjectId(Request $request): int
    {
        $projectId = intval($request->input('project_id'));
        if ($projectId > 0) {
            return $projectId;
        }
        $taskId = intval($request->input('task_id'));
        if ($taskId > 0) {
            return intval(ProjectTask::whereId($taskId)->value('project_id'));
        }
        $fileId = intval($request->input('file_id'));
        if ($fileId > 0) {
            $file = ProjectTaskFile::whereId($fileId)->first(['project_id', 'task_id']);
            if ($file) {
                $projectId = intval($file->project_id);
                return $projectId > 0
                    ? $projectId
                    : intval(ProjectTask::whereId($file->task_id)->value('project_id'));
            }
        }
        return 0;
    }

    private static function authorizeProjectMembership(AutomationToken $token, int $projectId, Request $request): void
    {
        if ($token->allowsProject($projectId) && ProjectUser::whereProjectId($projectId)->whereUserid($token->userid)->exists()) {
            return;
        }
        self::forbidStandardRequest($token, $request, 'project', $projectId);
    }

    private static function forbidStandardRequest(AutomationToken $token, Request $request, ?string $resourceType = null, ?int $resourceId = null): never
    {
        self::audit($token, 'authorization.verify', 'forbidden', $request, $resourceType, $resourceId);
        throw new ApiException('访问令牌无权调用此接口');
    }

    public static function audit(?AutomationToken $token, string $action, string $result, ?Request $request = null, ?string $resourceType = null, ?int $resourceId = null): void
    {
        $request ??= request();
        $audit = AutomationTokenAudit::createInstance([
            'token_id' => $token?->id,
            'userid' => $token?->userid,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'request_id' => (string) ($request->header('X-Request-ID') ?: $request->header('X-Correlation-ID')),
            'result' => $result,
            'created_at' => now(),
        ]);
        $audit->save();
    }

    private static function canonicalQuery(array $query): string
    {
        ksort($query);
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
