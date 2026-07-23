<?php

namespace Yhs\WebmanConfigCenter;

use Predis\Client;
use RuntimeException;

final class RedisEventListener
{
    public function __construct(private readonly array $config, private readonly ConfigSynchronizer $synchronizer)
    {
    }

    public function run(): never
    {
        if (!class_exists(Client::class)) {
            throw new RuntimeException('使用 config-center-listen 需要先安装可选依赖：composer require predis/predis');
        }
        $delay = 1;
        while (true) {
            try {
                $url = (string) ($this->config['redis_url'] ?? '');
                if ($url === '') throw new RuntimeException('未配置 redis_url；如不需要实时监听，请使用 config-center-poll 轮询');
                $client = new Client($url, ['read_write_timeout' => 0]);
                $loop = $client->pubSubLoop();
                $loop->subscribe((string) ($this->config['event_channel'] ?? 'config-center:changed'));
                $delay = 1;
                foreach ($loop as $message) {
                    if ($message->kind !== 'message') continue;
                    $this->handle((string) $message->payload);
                }
            } catch (\Throwable $exception) {
                fwrite(STDERR, sprintf("config-center redis listener reconnecting in %ds: %s\n", $delay, $exception->getMessage()));
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
            $this->synchronizer->sync($mapping);
        }
    }
}
