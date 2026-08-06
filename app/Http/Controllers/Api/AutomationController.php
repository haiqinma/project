<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\AutomationToken;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\ProjectFlowItem;
use App\Models\ProjectTaskFile;
use App\Models\WebSocketDialogMsg;
use App\Module\Base;
use App\Services\AutomationTokenService;
use App\Services\PersistentStorage;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Request;

class AutomationController extends AbstractController
{
    /** @api {get} api/automation/project/lists 获取授权项目列表 */
    public function project__lists()
    {
        $this->requireMethod('GET');
        $token = $this->token();
        if (!$token->hasScope('project:read')) {
            throw new ApiException('自动化访问权限不足');
        }
        $list = Project::authData($token->userid)
            ->whereIn('projects.id', $token->project_ids)
            ->whereNull('projects.archived_at')
            ->get(['projects.id', 'projects.name', 'projects.desc', 'projects.updated_at']);
        return Base::retSuccess('success', ['list' => $list]);
    }

    /** @api {get} api/automation/task/lists 获取授权任务列表 */
    public function task__lists()
    {
        $this->requireMethod('GET');
        $token = $this->token();
        $projectId = intval(Request::input('project_id'));
        AutomationTokenService::authorizeProject($token, $projectId, 'task:read');
        $query = $this->visibleTasks($token, $projectId);
        $keyword = trim((string) Request::input('keyword'));
        if ($keyword !== '') {
            $query->where('project_tasks.name', 'like', "%{$keyword}%");
        }
        $list = $query->orderByDesc('project_tasks.id')->paginate(Base::getPaginate(100, 50));
        return Base::retSuccess('success', $list->toArray());
    }

    /** @api {get} api/automation/task/detail 获取授权任务详情 */
    public function task__detail()
    {
        $this->requireMethod('GET');
        $token = $this->token();
        $task = $this->task($token, intval(Request::input('task_id')), 'task:read');
        $task->load(['content', 'taskUser', 'taskTag']);
        $messages = $task->dialog_id ? WebSocketDialogMsg::whereDialogId($task->dialog_id)
            ->whereIn('type', ['text', 'notice'])
            ->orderByDesc('id')->limit(50)->get()->reverse()->values() : collect();
        $task->setAppends(['today', 'overdue']);
        $flows = ProjectFlowItem::whereProjectId($task->project_id)->orderBy('sort')->get([
            'id', 'name', 'status', 'color', 'turns', 'columnid',
        ]);
        return Base::retSuccess('success', ['task' => $task, 'messages' => $messages, 'flows' => $flows]);
    }

    /** @api {post} api/automation/task/comment 追加任务评论 */
    public function task__comment()
    {
        $this->requireMethod('POST');
        $token = $this->token();
        $task = $this->task($token, intval(Request::input('task_id')), 'task:comment');
        $content = trim((string) Request::input('content'));
        if ($content === '' || mb_strlen($content) > 10000) {
            throw new ApiException('评论内容不能为空且不能超过10000个字符');
        }
        return Base::retSuccess('评论成功', AutomationTokenService::appendTaskComment($token, $task, $content));
    }

    /** @api {post} api/automation/task/update 更新任务普通字段 */
    public function task__update()
    {
        $this->requireMethod('POST');
        $token = $this->token();
        $task = $this->task($token, intval(Request::input('task_id')), 'task:update');
        return Base::retSuccess('修改成功', AutomationTokenService::updateTask($token, $task, Request::input()));
    }

    /** @api {post} api/automation/task/status 更新任务状态 */
    public function task__status()
    {
        $this->requireMethod('POST');
        $token = $this->token();
        $task = $this->task($token, intval(Request::input('task_id')), 'task:status');
        return Base::retSuccess('修改成功', AutomationTokenService::updateTaskStatus($token, $task, Request::input()));
    }

    /** @api {get} api/automation/file/download 获取授权文件下载信息 */
    public function file__download()
    {
        $this->requireMethod('GET');
        $token = $this->token();
        $file = $this->file($token, intval(Request::input('file_id')));
        return Base::retSuccess('success', [
            'id' => $file->id,
            'task_id' => $file->task_id,
            'project_id' => $file->project_id,
            'name' => $file->name,
            'size' => $file->size,
            'ext' => $file->ext,
            'content_path' => '/api/automation/file/content',
            'content_params' => ['file_id' => $file->id],
        ]);
    }

    /** @api {get} api/automation/file/content 下载授权文件内容 */
    public function file__content(): StreamedResponse
    {
        $this->requireMethod('GET');
        $token = $this->token();
        $file = $this->file($token, intval(Request::input('file_id')));
        $path = $file->getRawOriginal('path');
        if (!PersistentStorage::exists($path)) {
            throw new ApiException('文件不存在或无权访问');
        }
        $file->increment('download');
        AutomationTokenService::audit($token, 'file.download', 'success', request(), 'file', $file->id);
        return response()->streamDownload(function () use ($path) {
            $stream = PersistentStorage::readStream($path);
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $file->name, ['Content-Type' => 'application/octet-stream']);
    }

    private function token(): AutomationToken
    {
        return request()->attributes->get('automation_token');
    }

    private function requireMethod(string $method): void
    {
        if (!request()->isMethod($method)) {
            throw new ApiException('请求方法不允许');
        }
    }

    private function task(AutomationToken $token, int $taskId, string $scope): ProjectTask
    {
        $task = ProjectTask::allData($token->userid)->where('project_tasks.id', $taskId)->first();
        if (!$task) {
            throw new ApiException('任务不存在或无权访问');
        }
        AutomationTokenService::authorizeProject($token, (int) $task->project_id, $scope);
        $visible = $this->visibleTasks($token, (int) $task->project_id)->where('project_tasks.id', $taskId)->exists();
        if (!$visible) {
            AutomationTokenService::audit($token, 'authorization.verify', 'forbidden', request(), 'task', $taskId);
            throw new ApiException('任务不存在或无权访问');
        }
        return $task;
    }

    private function file(AutomationToken $token, int $fileId): ProjectTaskFile
    {
        $file = ProjectTaskFile::find($fileId);
        if (!$file) {
            throw new ApiException('文件不存在或无权访问');
        }
        $task = $this->task($token, (int) $file->task_id, 'file:read');
        $file->project_id = $task->project_id;
        return $file;
    }

    private function visibleTasks(AutomationToken $token, int $projectId): Builder
    {
        $userid = $token->userid;
        return ProjectTask::query()
            ->select('project_tasks.*')
            ->where('project_tasks.project_id', $projectId)
            ->where(function (Builder $query) use ($userid) {
                $query->where('project_tasks.visibility', 1)
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('project_users')
                        ->whereColumn('project_users.project_id', 'project_tasks.project_id')
                        ->where('project_users.userid', $userid)
                        ->whereIn('project_users.owner', [ProjectUser::OWNER_PRIMARY, ProjectUser::OWNER_DEPUTY]))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('project_task_users')
                        ->whereColumn('project_task_users.task_id', 'project_tasks.id')->where('project_task_users.userid', $userid))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('project_task_visibility_users')
                        ->whereColumn('project_task_visibility_users.task_id', 'project_tasks.id')->where('project_task_visibility_users.userid', $userid));
            });
    }
}
