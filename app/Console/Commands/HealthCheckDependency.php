<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PDO;
use Throwable;

class HealthCheckDependency extends Command
{
    protected $signature = 'health:dependency {component : database or redis} {--timeout=10 : Connection timeout in seconds}';

    protected $description = 'Run a read-only health check against a required dependency';

    public function handle(): int
    {
        $component = (string) $this->argument('component');
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 300],
        ]);

        if ($timeout === false) {
            $this->error('Timeout must be an integer between 1 and 300 seconds.');
            return self::INVALID;
        }

        try {
            return match ($component) {
                'database' => $this->checkDatabase($timeout),
                'redis' => $this->checkRedis($timeout),
                default => $this->invalidComponent(),
            };
        } catch (Throwable) {
            $this->error("{$component} dependency check failed.");
            return self::FAILURE;
        }
    }

    private function checkDatabase(int $timeout): int
    {
        $connection = (string) config('database.default');
        $optionsKey = "database.connections.{$connection}.options";
        $options = (array) config($optionsKey, []);
        $options[PDO::ATTR_TIMEOUT] = $timeout;
        config([$optionsKey => $options]);

        DB::purge($connection);
        DB::connection($connection)->select('SELECT 1');
        DB::disconnect($connection);

        return self::SUCCESS;
    }

    private function checkRedis(int $timeout): int
    {
        config([
            'database.redis.default.timeout' => $timeout,
            'database.redis.default.read_timeout' => $timeout,
        ]);

        Redis::purge('default');
        $response = Redis::connection('default')->ping();
        Redis::connection('default')->disconnect();

        if (!in_array(strtoupper((string) $response), ['1', 'PONG'], true)) {
            throw new \RuntimeException('Unexpected Redis PING response.');
        }

        return self::SUCCESS;
    }

    private function invalidComponent(): int
    {
        $this->error('Component must be database or redis.');
        return self::INVALID;
    }
}
