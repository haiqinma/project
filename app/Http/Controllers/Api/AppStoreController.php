<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Module\Base;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Request;

/**
 * YeYing Agent Runtime 接入代理。
 *
 * Project 只负责认证、管理员校验和转发 Agent internal API。
 * appstore 路由名是历史兼容入口，实际安装、升级、卸载由 Agent Runtime 编排。
 */
class AppStoreController extends AbstractController
{
    /**
     * @api {get} api/appstore/installed 获取已安装应用
     *
     * @apiDescription 代理 Agent Runtime `GET /api/v1/internal/installed`。
     * @apiVersion 1.0.0
     * @apiGroup appstore
     * @apiName installed
     *
     * @apiSuccess {Number} ret     返回状态码（1正确、0错误）
     * @apiSuccess {String} msg     返回信息
     * @apiSuccess {Array} data     已安装应用列表
     */
    public function installed()
    {
        User::auth();
        return $this->forward('GET', '/api/v1/internal/installed');
    }

    /**
     * @api {post} api/appstore/install 安装应用
     *
     * @apiDescription 管理员代理 Agent Runtime `POST /api/v1/internal/install`，返回 Runtime Task。
     * @apiVersion 1.0.0
     * @apiGroup appstore
     * @apiName install
     *
     * @apiParam {String} app_id 应用 ID
     * @apiParam {String} [version] 指定版本；为空时由 AppStore 选择已发布版本
     */
    public function install()
    {
        User::auth('admin');
        return $this->forwardLifecycle('/api/v1/internal/install');
    }

    /**
     * @api {post} api/appstore/upgrade 升级应用
     *
     * @apiDescription 管理员代理 Agent Runtime `POST /api/v1/internal/upgrade`，返回 Runtime Task。
     * @apiVersion 1.0.0
     * @apiGroup appstore
     * @apiName upgrade
     *
     * @apiParam {String} app_id 应用 ID
     * @apiParam {String} [version] 指定版本；为空时升级到已发布版本
     */
    public function upgrade()
    {
        User::auth('admin');
        return $this->forwardLifecycle('/api/v1/internal/upgrade');
    }

    /**
     * @api {post} api/appstore/uninstall 卸载应用
     *
     * @apiDescription 管理员代理 Agent Runtime `POST /api/v1/internal/uninstall`，返回 Runtime Task。
     * @apiVersion 1.0.0
     * @apiGroup appstore
     * @apiName uninstall
     *
     * @apiParam {String} app_id 应用 ID
     */
    public function uninstall()
    {
        User::auth('admin');
        $appId = trim((string) Request::input('app_id', ''));
        if ($appId === '') {
            return Base::retError('应用ID不能为空');
        }
        return $this->forward('POST', '/api/v1/internal/uninstall', ['app_id' => $appId]);
    }

    private function forwardLifecycle(string $path): array
    {
        $appId = trim((string) Request::input('app_id', ''));
        if ($appId === '') {
            return Base::retError('应用ID不能为空');
        }

        $payload = ['app_id' => $appId];
        $version = trim((string) Request::input('version', ''));
        if ($version !== '') {
            $payload['version'] = $version;
        }

        return $this->forward('POST', $path, $payload);
    }

    private function forward(string $method, string $path, array $payload = []): array
    {
        $baseUrl = rtrim((string) config('dootask.agent_internal_url', config('dootask.appstore_internal_url', '')), '/');
        if ($baseUrl === '') {
            return Base::retError('Agent Runtime 未配置');
        }

        $headers = [
            'Accept' => 'application/json',
            'Token' => (string) Base::token(),
            'Language' => (string) Base::headerOrInput('language'),
            'X-YeYing-Instance' => (string) config('dootask.agent_instance_id', 'project'),
        ];
        $internalToken = trim((string) config('dootask.agent_internal_token', ''));
        if ($internalToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $internalToken;
        }

        $options = ['headers' => $headers];
        if ($payload !== []) {
            $options['json'] = $payload;
        }

        try {
            $response = (new Client([
                'base_uri' => $baseUrl,
                'timeout' => 20,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]))->request($method, $path, $options);
        } catch (GuzzleException $e) {
            return Base::retError('无法连接 Agent Runtime', ['error' => $e->getMessage()]);
        }

        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) {
            return Base::retError('Agent Runtime 返回异常', [
                'status' => $response->getStatusCode(),
            ]);
        }

        $code = intval($body['code'] ?? $response->getStatusCode());
        $message = trim((string) ($body['message'] ?? $body['msg'] ?? 'success'));
        $data = $body['data'] ?? [];

        if ($code >= 200 && $code < 300) {
            return Base::retSuccess($message ?: 'success', $data);
        }

        return Base::retError($message ?: 'Agent Runtime 返回错误', [
            'code' => $code,
            'data' => $data,
        ]);
    }
}
