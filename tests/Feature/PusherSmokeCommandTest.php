<?php

namespace Tests\Feature;

use App\Services\NodePusherClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PusherSmokeCommandTest extends TestCase
{
    public function test_client_signs_canonical_payload_and_posts_to_node(): void
    {
        config()->set('services.node_pusher.base_url', 'http://node.test');
        config()->set('services.node_pusher.app_id', 'app.test');
        config()->set('services.node_pusher.key', 'pk_test');
        config()->set('services.node_pusher.secret', 'ps_test');

        Http::fake([
            'node.test/*' => Http::response(['code' => 0, 'data' => ['accepted' => true]], 200),
        ]);

        $body = [
            'eventId' => 'project-smoke-test',
            'type' => 'project.smoke',
            'channels' => ['public-project-smoke'],
            'data' => ['b' => 2, 'a' => 1],
            'persist' => true,
        ];
        $timestamp = '2026-09-02T00:00:00.000Z';

        $result = app(NodePusherClient::class)->publish($body, $timestamp);

        $this->assertTrue($result['successful']);
        Http::assertSent(function (Request $request) use ($body, $timestamp): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://node.test/api/v1/public/pusher/apps/app.test/events'
                && $request->header('x-pusher-key')[0] === 'pk_test'
                && $request->header('x-pusher-timestamp')[0] === $timestamp
                && $request->header('x-pusher-signature')[0] === NodePusherClient::buildPublishSignature($timestamp, $body, 'ps_test')
                && $request->data() === $body;
        });
    }

    public function test_smoke_command_publishes_test_event(): void
    {
        config()->set('services.node_pusher.base_url', 'http://node.test');
        config()->set('services.node_pusher.app_id', 'app.test');
        config()->set('services.node_pusher.key', 'pk_test');
        config()->set('services.node_pusher.secret', 'ps_test');

        Http::fake([
            'node.test/*' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'eventId' => 'project-smoke-fixed',
                    'accepted' => true,
                    'idempotent' => false,
                    'channels' => ['public-project-smoke'],
                    'persisted' => true,
                ],
            ], 200),
        ]);

        $this->artisan('pusher:smoke', [
            '--event-id' => 'project-smoke-fixed',
            '--channel' => 'public-project-smoke',
            '--type' => 'project.smoke',
        ])->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://node.test/api/v1/public/pusher/apps/app.test/events'
                && $request->data()['eventId'] === 'project-smoke-fixed'
                && $request->data()['channels'] === ['public-project-smoke'];
        });
    }
}
