<?php

namespace App\Services;

use App\Contracts\AutomationTokenCreationAuthorizer;
use App\Exceptions\ApiException;
use App\Models\AutomationToken;
use App\Models\AutomationTokenAudit;
use App\Models\AutomationTokenNonce;
use App\Models\AbstractModel;
use App\Models\ProjectUser;
use App\Models\ProjectTask;
use App\Models\ProjectPermission;
use App\Models\Project;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\QueryException;

class AutomationTokenService
{
    public const SCOPES = [
        'project:read',
        'task:read',
        'task:comment',
        'task:update',
        'task:status',
        'file:read',
    ];

    public static function issue(int $userid, string $name, array $scopes, array $projectIds, Carbon $expiresAt): array
    {
        $secret = 'yysk_' . bin2hex(random_bytes(24));
        $token = AutomationToken::createInstance([
            'userid' => $userid,
            'access_key' => 'yyak_' . bin2hex(random_bytes(12)),
            'secret_hash' => hash('sha256', $secret),
            'name' => $name,
            'scopes' => array_values($scopes),
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
            throw new \LogicException('自动化令牌创建授权器配置无效');
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
            throw new ApiException('自动化访问认证失败');
        }
        if ($token->expires_at->isPast()) {
            $token->updateInstance(['status' => AutomationToken::STATUS_EXPIRED]);
            $token->save();
            self::audit($token, 'auth.verify', 'expired', $request);
            throw new ApiException('自动化访问认证失败');
        }
        try {
            $requestTime = Carbon::parse($timestamp);
        } catch (\Throwable) {
            self::audit($token, 'auth.verify', 'invalid_timestamp', $request);
            throw new ApiException('自动化访问认证失败');
        }
        $window = (int) config('dootask.automation_token_time_window', 300);
        if (abs($requestTime->diffInSeconds(now(), false)) > $window) {
            self::audit($token, 'auth.verify', 'invalid_timestamp', $request);
            throw new ApiException('自动化访问认证失败');
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
            throw new ApiException('自动化访问认证失败');
        }

        $rateKey = "automation_token_rate:{$token->id}";
        $rateLimit = max(1, (int) config('dootask.automation_token_rate_per_minute', 60));
        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            self::audit($token, 'auth.verify', 'rate_limited', $request);
            throw new ApiException('自动化访问请求过于频繁');
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
            throw new ApiException('自动化访问认证失败');
        }
        $user = User::whereUserid($token->userid)->whereNull('disable_at')->first();
        if (!$user) {
            self::audit($token, 'auth.verify', 'invalid_user', $request);
            throw new ApiException('自动化访问认证失败');
        }

        $token->updateInstance(['last_used_at' => now(), 'last_used_ip' => $request->ip()]);
        $token->save();
        $request->attributes->set('automation_token', $token);
        RequestContext::save('auth', $user);
        return $token;
    }

    public static function authorizeProject(AutomationToken $token, int $projectId, string $scope): void
    {
        if (!$token->hasScope($scope) || !$token->allowsProject($projectId) ||
            !ProjectUser::whereProjectId($projectId)->whereUserid($token->userid)->exists()) {
            self::audit($token, 'authorization.verify', 'forbidden', request(), 'project', $projectId);
            throw new ApiException('自动化访问权限不足');
        }
    }

    public static function appendTaskComment(AutomationToken $token, ProjectTask $task, string $content): array
    {
        if ($task->parent_id > 0) {
            throw new ApiException('当前任务暂不支持追加评论');
        }
        if (!$task->dialog_id) {
            AbstractModel::transaction(function () use ($task) {
                $task->lockForUpdate();
                if (!$task->dialog_id) {
                    $dialog = WebSocketDialog::createGroup($task->name, $task->relationUserids(), 'task');
                    if (!$dialog) {
                        throw new ApiException('创建任务会话失败');
                    }
                    $task->dialog_id = $dialog->id;
                    $task->save();
                }
            });
        }
        $result = WebSocketDialogMsg::sendMsg(null, $task->dialog_id, 'text', [
            'type' => 'text',
            'text' => e($content),
        ], $token->userid);
        self::audit($token, 'task.comment', 'success', request(), 'task', $task->id);
        $data = $result['data'] ?? [];
        return $data instanceof WebSocketDialogMsg ? $data->toArray() : (array) $data;
    }

    public static function updateTask(AutomationToken $token, ProjectTask $task, array $input): array
    {
        $allowed = ['name', 'content', 'color', 'task_tag', 'p_level', 'p_name', 'p_color', 'times'];
        $params = array_intersect_key($input, array_flip($allowed));
        if (!$params) {
            throw new ApiException('没有可更新的任务字段');
        }
        $params['task_id'] = $task->id;
        $permission = array_key_exists('times', $params) ? ProjectPermission::TASK_TIME : ProjectPermission::TASK_UPDATE;
        ProjectPermission::userTaskPermission(Project::userProject($task->project_id), $permission, $task);
        $params = ProjectTask::normalizeTimes($params, $task);
        $updateMarking = [];
        $task->updateTask($params, $updateMarking);
        $data = ProjectTask::oneTask($task->id)->toArray();
        $data['update_marking'] = $updateMarking ?: json_decode('{}');
        $task->pushMsg('update', $data);
        self::audit($token, 'task.update', 'success', request(), 'task', $task->id);
        return $data;
    }

    public static function updateTaskStatus(AutomationToken $token, ProjectTask $task, array $input): array
    {
        $params = ['task_id' => $task->id];
        if (array_key_exists('flow_item_id', $input)) {
            $params['flow_item_id'] = intval($input['flow_item_id']);
        }
        if (array_key_exists('completed', $input)) {
            $params['complete_at'] = filter_var($input['completed'], FILTER_VALIDATE_BOOLEAN) ? now()->toDateTimeString() : false;
        }
        if (count($params) === 1) {
            throw new ApiException('请选择任务状态或完成状态');
        }
        ProjectPermission::userTaskPermission(Project::userProject($task->project_id), ProjectPermission::TASK_STATUS, $task);
        $updateMarking = [];
        $task->updateTask($params, $updateMarking);
        $data = ProjectTask::oneTask($task->id)->toArray();
        $data['update_marking'] = $updateMarking ?: json_decode('{}');
        $task->pushMsg('update', $data);
        self::audit($token, 'task.status', 'success', request(), 'task', $task->id);
        return $data;
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
