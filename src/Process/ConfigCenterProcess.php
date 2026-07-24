<?php

namespace Kylin987\WebmanConfigCenter\Process;

use Kylin987\WebmanConfigCenter\ConfigCenterLogger;
use Kylin987\WebmanConfigCenter\ConfigLoader;
use Kylin987\WebmanConfigCenter\ConfigSynchronizer;
use RuntimeException;
use Workerman\Timer;
use Workerman\Worker;

final class ConfigCenterProcess
{
    private array $config = [];

    private ?ConfigCenterLogger $logger = null;

    private ?ConfigSynchronizer $synchronizer = null;

    /** @var resource|null */
    private $redisSocket = null;

    private string $redisBuffer = '';

    private int $redisReconnectDelay = 1;

    private bool $redisWasDisconnected = false;

    private bool $pollWasFailing = false;

    public function onWorkerStart(Worker $worker): void
    {
        $this->config = ConfigLoader::load();
        $this->logger = new ConfigCenterLogger($this->config);
        $this->synchronizer = new ConfigSynchronizer($this->config);

        $interval = max(10, (int) ($this->config['poll_interval'] ?? 60));

        $this->syncAll();
        Timer::add($interval, fn () => $this->syncAll());

        if ((string) ($this->config['redis_url'] ?? '') !== '') {
            Timer::add(0.1, fn () => $this->connectRedis(), [], false);
        }
    }

    public function onWorkerStop(Worker $worker): void
    {
        $this->closeRedis();
    }

    private function syncAll(): void
    {
        try {
            $this->synchronizer?->syncAll();
            if ($this->pollWasFailing) {
                $this->logger?->info('config-center poll recovered');
            }
            $this->pollWasFailing = false;
        } catch (\Throwable $exception) {
            $this->pollWasFailing = true;
            $this->logger?->warningThrottled('poll.process.failed', 'config-center poll failed; keep using local config files', [
                'exception' => $exception,
            ]);
        }
    }

    private function connectRedis(): void
    {
        $this->closeRedis();

        try {
            $parts = $this->parseRedisUrl((string) ($this->config['redis_url'] ?? ''));
            $socket = @stream_socket_client(
                'tcp://' . $parts['host'] . ':' . $parts['port'],
                $errno,
                $error,
                3,
                STREAM_CLIENT_CONNECT
            );
            if (!is_resource($socket)) {
                throw new RuntimeException($error ?: ('Redis 连接失败：' . $errno));
            }

            stream_set_blocking($socket, false);
            $this->redisSocket = $socket;
            $this->redisBuffer = '';

            if ($parts['password'] !== '') {
                $this->writeRedisCommand(['AUTH', $parts['password']]);
            }
            if ($parts['database'] !== null) {
                $this->writeRedisCommand(['SELECT', (string) $parts['database']]);
            }
            $this->writeRedisCommand(['SUBSCRIBE', (string) ($this->config['event_channel'] ?? 'config-center:changed')]);

            Worker::$globalEvent?->onReadable($socket, fn ($stream) => $this->onRedisReadable($stream));

            if ($this->redisWasDisconnected) {
                $this->logger?->info('config-center redis listener recovered');
            }
            $this->redisWasDisconnected = false;
            $this->redisReconnectDelay = 1;
        } catch (\Throwable $exception) {
            $this->redisWasDisconnected = true;
            $this->logger?->warningThrottled('redis.listener.failed', 'config-center redis listener reconnecting', [
                'exception' => $exception,
                'delay_seconds' => $this->redisReconnectDelay,
            ]);
            $this->scheduleRedisReconnect();
        }
    }

    /**
     * @return array{host:string,port:int,password:string,database:int|null}
     */
    private function parseRedisUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException('redis_url 格式不正确');
        }

        $database = null;
        if (!empty($parts['path'])) {
            $database = (int) ltrim($parts['path'], '/');
        }

        return [
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? 6379),
            'password' => isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
            'database' => $database,
        ];
    }

    private function onRedisReadable($stream): void
    {
        $chunk = @fread($stream, 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($stream)) {
                $this->redisWasDisconnected = true;
                $this->logger?->warningThrottled('redis.listener.closed', 'config-center redis listener closed; reconnecting');
                $this->scheduleRedisReconnect();
            }
            return;
        }

        $this->redisBuffer .= $chunk;
        try {
            $this->consumeRedisBuffer();
        } catch (\Throwable $exception) {
            $this->redisWasDisconnected = true;
            $this->logger?->warningThrottled('redis.listener.read.failed', 'config-center redis listener read failed; reconnecting', [
                'exception' => $exception,
            ]);
            $this->scheduleRedisReconnect();
        }
    }

    private function consumeRedisBuffer(): void
    {
        $offset = 0;
        $length = strlen($this->redisBuffer);

        while ($offset < $length) {
            $before = $offset;
            $message = $this->parseResp($this->redisBuffer, $offset);
            if ($message === null && $offset === $before) {
                break;
            }
            if (is_array($message)) {
                if (($message[0] ?? '') === '__redis_error') {
                    throw new RuntimeException((string) ($message[1] ?? 'Redis 返回错误'));
                }
                if (($message[0] ?? '') === 'message') {
                    $this->handleRedisPayload((string) ($message[2] ?? ''));
                }
            }
        }

        if ($offset > 0) {
            $this->redisBuffer = substr($this->redisBuffer, $offset);
        }
    }

    private function parseResp(string $buffer, int &$offset): mixed
    {
        $startOffset = $offset;
        if (!isset($buffer[$offset])) {
            return null;
        }

        $type = $buffer[$offset++];
        $lineEnd = strpos($buffer, "\r\n", $offset);
        if ($lineEnd === false) {
            $offset = $startOffset;
            return null;
        }

        $line = substr($buffer, $offset, $lineEnd - $offset);
        $offset = $lineEnd + 2;

        return match ($type) {
            '+' => $line,
            '-' => ['__redis_error', $line],
            ':' => (int) $line,
            '$' => $this->parseBulkString($buffer, $offset, (int) $line, $startOffset),
            '*' => $this->parseArray($buffer, $offset, (int) $line, $startOffset),
            default => throw new RuntimeException('未知 Redis RESP 类型：' . $type),
        };
    }

    private function parseBulkString(string $buffer, int &$offset, int $bytes, int $startOffset): ?string
    {
        if ($bytes < 0) {
            return null;
        }
        if (strlen($buffer) < $offset + $bytes + 2) {
            $offset = $startOffset;
            return null;
        }

        $value = substr($buffer, $offset, $bytes);
        $offset += $bytes + 2;
        return $value;
    }

    private function parseArray(string $buffer, int &$offset, int $count, int $startOffset): ?array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $before = $offset;
            $value = $this->parseResp($buffer, $offset);
            if ($value === null && $before === $offset) {
                $offset = $startOffset;
                return null;
            }
            $items[] = $value;
        }
        return $items;
    }

    private function handleRedisPayload(string $payload): void
    {
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $data = $event['data'] ?? [];
            foreach ($this->config['items'] ?? [] as $mapping) {
                $namespace = (string) ($mapping['namespace'] ?? $this->config['namespace'] ?? 'public');
                if ($namespace !== ($data['namespace'] ?? '') || ($mapping['group'] ?? '') !== ($data['group'] ?? '') || ($mapping['data_id'] ?? '') !== ($data['dataId'] ?? '')) {
                    continue;
                }
                $this->synchronizer?->sync($mapping);
            }
        } catch (\Throwable $exception) {
            $this->logger?->warningThrottled('redis.event.sync.failed.' . md5($payload), 'config-center redis event sync failed; keep using local config file', [
                'exception' => $exception,
            ]);
        }
    }

    private function writeRedisCommand(array $parts): void
    {
        $command = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $part = (string) $part;
            $command .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }
        if (!is_resource($this->redisSocket) || @fwrite($this->redisSocket, $command) === false) {
            throw new RuntimeException('Redis 命令发送失败');
        }
    }

    private function scheduleRedisReconnect(): void
    {
        $this->closeRedis();
        $delay = $this->redisReconnectDelay;
        $this->redisReconnectDelay = min($this->redisReconnectDelay * 2, 60);
        Timer::add($delay, fn () => $this->connectRedis(), [], false);
    }

    private function closeRedis(): void
    {
        if (is_resource($this->redisSocket)) {
            Worker::$globalEvent?->offReadable($this->redisSocket);
            @fclose($this->redisSocket);
        }
        $this->redisSocket = null;
        $this->redisBuffer = '';
    }
}
