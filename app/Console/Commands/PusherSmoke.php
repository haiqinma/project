<?php

namespace App\Console\Commands;

use App\Services\NodePusherClient;
use Illuminate\Console\Command;
use Throwable;

class PusherSmoke extends Command
{
    protected $signature = 'pusher:smoke
        {--channel=public-project-smoke : Channel to publish the smoke event to}
        {--type=project.smoke : Event type}
        {--event-id= : Fixed event id. Defaults to a generated project-smoke id}
        {--persist=1 : Whether Node should persist the event, 1 or 0}
        {--timeout=10 : HTTP timeout in seconds}';

    protected $description = 'Publish a smoke event to the Node Pusher gateway using Project configuration';

    public function handle(NodePusherClient $client): int
    {
        try {
            $timeout = $this->timeout();
            $channel = trim((string) $this->option('channel'));
            $type = trim((string) $this->option('type'));
            if ($channel === '' || $type === '') {
                $this->error('Channel and type are required.');
                return self::INVALID;
            }

            $eventId = trim((string) $this->option('event-id'));
            if ($eventId === '') {
                $eventId = 'project-smoke-' . now()->format('YmdHis') . '-' . bin2hex(random_bytes(4));
            }

            $body = [
                'eventId' => $eventId,
                'type' => $type,
                'channels' => [$channel],
                'data' => [
                    'source' => 'project',
                    'ok' => true,
                    'timestamp' => now()->toIso8601String(),
                ],
                'persist' => $this->boolOption('persist'),
            ];

            $summary = $client->configurationSummary();
            $this->line("Node Pusher: {$summary['baseUrl']} app={$summary['appId']} key={$summary['key']}");
            $this->line("Publishing {$type} to {$channel} eventId={$eventId}");

            $result = $client->publish($body, timeout: $timeout);
            $payload = is_array($result['json']) ? $result['json'] : null;
            $accepted = $result['successful']
                && ($payload['code'] ?? null) === 0
                && (bool) data_get($payload, 'data.accepted');

            if (!$accepted) {
                $this->error('Pusher smoke failed.');
                $this->line($this->responseSummary($result));
                return self::FAILURE;
            }

            $this->info('Pusher smoke accepted.');
            $this->line($this->responseSummary($result));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function timeout(): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 300],
        ]);
        if ($timeout === false) {
            throw new \RuntimeException('Timeout must be an integer between 1 and 300 seconds.');
        }
        return $timeout;
    }

    private function boolOption(string $name): bool
    {
        $value = strtolower(trim((string) $this->option($name)));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function responseSummary(array $result): string
    {
        return json_encode([
            'status' => $result['status'],
            'response' => $result['json'] ?: $result['body'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }
}
