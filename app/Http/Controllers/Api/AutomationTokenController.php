<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\AutomationToken;
use App\Models\AutomationTokenAudit;
use App\Models\Project;
use App\Models\User;
use App\Module\Base;
use App\Services\AutomationTokenService;
use Carbon\Carbon;
use Request;

class AutomationTokenController extends AbstractController
{
    /** @api {get} api/automation-token/lists 获取访问令牌 */
    public function lists()
    {
        $this->requireMethod('GET');
        $user = User::auth();
        $tokens = AutomationToken::whereUserid($user->userid)->orderByDesc('id')->get();
        $projectIds = $tokens->pluck('project_ids')->flatten()->map(fn ($id) => (int) $id)->unique();
        $projects = Project::authData($user->userid)->whereIn('projects.id', $projectIds)->pluck('projects.name', 'projects.id');

        return Base::retSuccess('success', [
            'list' => $tokens->map(function (AutomationToken $token) use ($projects) {
                if ($token->status === AutomationToken::STATUS_ACTIVE && $token->expires_at->isPast()) {
                    $token->updateInstance(['status' => AutomationToken::STATUS_EXPIRED]);
                    $token->save();
                }
                $data = $token->toArray();
                $data['projects'] = collect($token->project_ids)->map(fn ($id) => [
                    'id' => (int) $id,
                    'name' => $projects[(int) $id] ?? '项目不可用',
                ])->values();
                return $data;
            }),
            'scopes' => AutomationTokenService::SCOPES,
        ]);
    }

    /** @api {post} api/automation-token/create 创建访问令牌 */
    public function create()
    {
        $this->requireMethod('POST');
        $user = User::auth();
        AutomationTokenService::authorizeCreation($user, request());
        $name = trim((string) Request::input('name'));
        $scopes = array_values(array_unique((array) Request::input('scopes', [])));
        $projectIds = array_values(array_unique(array_map('intval', (array) Request::input('project_ids', []))));
        $expiresAt = Carbon::parse((string) Request::input('expires_at', now()->addDays(30)));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new ApiException('令牌名称不能为空且不能超过100个字符');
        }
        if (!$scopes || array_diff($scopes, AutomationTokenService::SCOPES)) {
            throw new ApiException('请选择有效的权限范围');
        }
        if (!$projectIds || Project::authData($user->userid)->whereIn('projects.id', $projectIds)->count() !== count($projectIds)) {
            throw new ApiException('请选择当前用户参与的有效项目');
        }
        if ($expiresAt->isPast() || $expiresAt->gt(now()->addDays((int) config('dootask.automation_token_max_days', 90)))) {
            throw new ApiException('令牌有效期必须在90天以内');
        }

        $issued = AutomationTokenService::issue($user->userid, $name, $scopes, $projectIds, $expiresAt);
        return Base::retSuccess('创建成功', [
            'id' => $issued['token']->id,
            'access_key' => $issued['token']->access_key,
            'secret_key' => $issued['secret_key'],
        ]);
    }

    /** @api {post} api/automation-token/disable 禁用访问令牌 */
    public function disable()
    {
        $this->requireMethod('POST');
        return $this->revoke(false);
    }

    /** @api {post} api/automation-token/delete 删除访问令牌 */
    public function delete()
    {
        $this->requireMethod('POST');
        return $this->revoke(true);
    }

    /** @api {post} api/automation-token/rotate 轮换访问密钥 */
    public function rotate()
    {
        $this->requireMethod('POST');
        $user = User::auth();
        AutomationTokenService::authorizeCreation($user, request());
        $token = AutomationToken::whereUserid($user->userid)->find(intval(Request::input('id')));
        if (!$token) {
            throw new ApiException('令牌不存在');
        }
        $secret = AutomationTokenService::rotate($token);
        return Base::retSuccess('密钥轮换成功', [
            'id' => $token->id,
            'access_key' => $token->access_key,
            'secret_key' => $secret,
        ]);
    }

    /** @api {get} api/automation-token/admin/audits 获取自动化访问审计 */
    public function admin__audits()
    {
        $this->requireMethod('GET');
        $user = User::auth();
        $user->identity('admin');
        $query = AutomationTokenAudit::with(['token:id,name,access_key'])
            ->orderByDesc('id');
        if ($action = trim((string) Request::input('action'))) {
            $query->where('action', $action);
        }
        if ($result = trim((string) Request::input('result'))) {
            $query->where('result', $result);
        }
        if ($userid = intval(Request::input('userid'))) {
            $query->where('userid', $userid);
        }
        return Base::retSuccess('success', $query->paginate(Base::getPaginate(100, 50))->toArray());
    }

    private function revoke(bool $delete)
    {
        $user = User::auth();
        $token = AutomationToken::whereUserid($user->userid)->find(intval(Request::input('id')));
        if (!$token) {
            throw new ApiException('令牌不存在');
        }
        AutomationTokenService::audit($token, $delete ? 'token.delete' : 'token.disable', 'success');
        if ($delete) {
            $token->delete();
        } else {
            $token->updateInstance(['status' => AutomationToken::STATUS_DISABLED, 'revoked_at' => now()]);
            $token->save();
        }
        return Base::retSuccess($delete ? '删除成功' : '禁用成功');
    }

    private function requireMethod(string $method): void
    {
        if (!request()->isMethod($method)) {
            throw new ApiException('请求方法不允许');
        }
    }
}
