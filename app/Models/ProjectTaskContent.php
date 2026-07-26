<?php

namespace App\Models;

use App\Module\Base;
use App\Exceptions\ApiException;
use App\Services\PersistentStorage;

/**
 * App\Models\ProjectTaskContent
 *
 * @property int $id
 * @property int|null $project_id 项目ID
 * @property int|null $task_id 任务ID
 * @property int|null $userid 用户ID
 * @property string|null $desc 内容描述
 * @property string|null $content 内容
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel cancelAppend()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel cancelHidden()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel change($array)
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel getKeyValue()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent query()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel remove()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel saveOrIgnore()
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereDesc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ProjectTaskContent whereUserid($value)
 * @mixin \Eloquent
 */
class ProjectTaskContent extends AbstractModel
{
    protected $hidden = [
        'updated_at',
    ];

    /**
     * 获取内容详情
     * @return array
     */
    public function getContentInfo()
    {
        $content = Base::json2array($this->content);
        if (isset($content['url'])) {
            $array = $this->toArray();
            $array['content'] = PersistentStorage::getContent($content['url']);
            if ($array['content']) {
                $replace = Base::fillUrl('uploads');
                $array['content'] = str_replace('{{RemoteURL}}uploads', $replace, $array['content']);
            }
            return $array;
        }
        return $this->toArray();
    }

    /**
     * 保存任务详情至文件并返回文件路径
     * @param $task_id
     * @param $content
     * @return string
     */
    public static function saveContent($task_id, $content)
    {
        @ini_set("pcre.backtrack_limit", 999999999);
        //
        $path = 'uploads/task/content/' . date("Ym") . '/' . $task_id . '/';
        //
        preg_match_all('/<img[^>]*?src=\\\\?["\']data:image\/(png|jpg|jpeg|webp);base64,(.*?)\\\\?["\']/s', $content, $matchs);
        foreach ($matchs[2] as $key => $text) {
            $objectKey = $path . 'attached/' . md5($text) . "." . $matchs[1][$key];
            $temporary = storage_path('app/tmp/task-content/' . bin2hex(random_bytes(16)));
            Base::makeDir(dirname($temporary));
            try {
                if (Base::saveContentImage($temporary, base64_decode($text))) {
                    $paramet = getimagesize($temporary);
                    if ($paramet !== false) {
                        PersistentStorage::putFile($objectKey, $temporary);
                        $content = str_replace($matchs[0][$key], '<img src="{{RemoteURL}}' . $objectKey . '" original-width="' . $paramet[0] . '" original-height="' . $paramet[1] . '"', $content);
                    }
                }
            } finally {
                @unlink($temporary);
            }
        }
        preg_match_all('/(<img[^>]*?src=\\\\?["\'])(https?:\/\/[^\/]+\/)(uploads\/[^\s"\'>]+)(\\\\?["\'][^>]*?>)/i', $content, $matches);
        foreach ($matches[0] as $key => $fullMatch) {
            if (PersistentStorage::exists($matches[3][$key])) {
                $replacement = $matches[1][$key] . '{{RemoteURL}}' . $matches[3][$key] . $matches[4][$key];
                $content = str_replace($fullMatch, $replacement, $content);
            }
        }
        //
        $filePath = $path . md5($content);
        try {
            PersistentStorage::putContent($filePath, $content);
        } catch (\Throwable $exception) {
            throw new ApiException("保存任务详情至文件失败,请重试");
        }
        //
        return $filePath;
    }
}
