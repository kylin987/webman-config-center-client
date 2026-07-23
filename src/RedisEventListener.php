<?php

namespace Yhs\WebmanConfigCenter;

use Predis\Client;
use RuntimeException;

final class RedisEventListener
{
    private ConfigCenterLogger $logger;

    public function __construct(private readonly array $config, private readonly ConfigSynchronizer $synchronizer)
    {
        $this->logger = new ConfigCenterLogger($config);
    }

    public function run(): never
    {
        $delay = 1;
        $wasDisconnected = false;
        while (true) {
            try {
                $url = (string) ($this->config['redis_url'] ?? '');
                if ($url === '') throw new RuntimeException('未配置 redis_url；如不需要实时监听，请使用 config-center-poll 轮询');
                $client = new Client($url, ['read_write_timeout' => 0]);
                $loop = $client->pubSubLoop();
                $loop->subscribe((string) ($this->config['event_channel'] ?? 'config-center:changed'));
                if ($wasDisconnected) {
                    $this->logger->info('config-center redis listener recovered');
                }
                $wasDisconnected = false;
                $delay = 1;
                foreach ($loop as $message) {
                    if ($message->kind !== 'message') continue;
                    $this->handle((string) $message->payload);
                }
            } catch (\Throwable $exception) {
                $wasDisconnected = true;
                $this->logger->warningThrottled('redis.listener.failed', 'config-center redis listener reconnecting', [
                    'exception' => $exception,
                    'delay_seconds' => $delay,
                ]);
                sleep($delay);
                $delay = min($delay * 2, 60);
            }
        }
    }

    private function handle(string $payload): void
    {
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $data = $event['data'] ?? [];
        foreach ($this->config['items'] ?? [] as $mapping) {
            $namespace = (string) ($mapping['namespace'] ?? $this->config['namespace'] ?? 'public');
            if ($namespace !== ($data['namespace'] ?? '') || ($mapping['group'] ?? '') !== ($data['group'] ?? '') || ($mapping['data_id'] ?? '') !== ($data['dataId'] ?? '')) continue;
            try {
                $this->synchronizer->sync($mapping);
            } catch (\Throwable $exception) {
                $this->logger->warningThrottled('redis.sync.failed.' . md5($namespace . '/' . ($mapping['group'] ?? '') . '/' . ($mapping['data_id'] ?? '')), 'config-center event sync failed; keep using local config file', [
                    'exception' => $exception,
                    'group' => $mapping['group'] ?? '',
                    'data_id' => $mapping['data_id'] ?? '',
                    'namespace' => $namespace,
                ]);
            }
        }
    }
}
