<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class NodePusherClient
{
    public function publish(array $body, ?string $timestamp = null, ?int $timeout = null): array
    {
        $baseUrl = $this->requiredConfig('base_url', 'PASSPORT_NODE_URL');
        $appId = $this->requiredConfig('app_id', 'PUSHER_APP_ID');
        $key = $this->requiredConfig('key', 'PUSHER_APP_KEY');
        $secret = $this->requiredConfig('secret', 'PUSHER_APP_SECRET');
        $timestamp = $timestamp ?: now()->toIso8601String();

        $response = Http::withHeaders([
            'x-pusher-key' => $key,
            'x-pusher-timestamp' => $timestamp,
            'x-pusher-signature' => self::buildPublishSignature($timestamp, $body, $secret),
        ])->timeout($timeout ?? $this->timeout())->post($this->publishUrl($baseUrl, $appId), $body);

        return [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'json' => $response->json(),
            'body' => $response->body(),
        ];
    }

    public function configurationSummary(): array
    {
        $key = (string) config('services.node_pusher.key', '');

        return [
            'baseUrl' => rtrim((string) config('services.node_pusher.base_url', ''), '/'),
            'appId' => (string) config('services.node_pusher.app_id', ''),
            'key' => $this->mask($key),
        ];
    }

    public static function buildPublishSignature(string $timestamp, array $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', trim($timestamp) . '.' . self::canonicalJson($body), $secret);
    }

    public static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(static fn (mixed $item): string => self::canonicalJson($item), $value)) . ']';
            }

            ksort($value, SORT_STRING);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = self::jsonEncode((string) $key) . ':' . self::canonicalJson($item);
            }
            return '{' . implode(',', $items) . '}';
        }

        return self::jsonEncode($value);
    }

    private function publishUrl(string $baseUrl, string $appId): string
    {
        return rtrim($baseUrl, '/') . '/api/v1/public/pusher/apps/' . rawurlencode($appId) . '/events';
    }

    private function requiredConfig(string $key, string $envName): string
    {
        $value = trim((string) config("services.node_pusher.{$key}", ''));
        if ($value === '') {
            throw new RuntimeException("{$envName} is required for pusher publish.");
        }
        return $value;
    }

    private function timeout(): int
    {
        $timeout = filter_var(config('services.node_pusher.timeout', 10), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 300],
        ]);

        return $timeout === false ? 10 : $timeout;
    }

    private static function jsonEncode(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode pusher payload.');
        }
        return $encoded;
    }

    private function mask(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 10) {
            return substr($value, 0, 2) . '***';
        }
        return substr($value, 0, 6) . '...' . substr($value, -4);
    }
}
