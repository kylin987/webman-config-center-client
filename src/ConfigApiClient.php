<?php

namespace Yhs\WebmanConfigCenter;

use GuzzleHttp\Client;
use RuntimeException;

final class ConfigApiClient
{
    private Client $client;

    public function __construct(private readonly array $config)
    {
        $this->client = new Client([
            'base_uri' => rtrim((string) ($config['endpoint'] ?? ''), '/') . '/',
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 3),
            'timeout' => (float) ($config['timeout'] ?? 8),
            'http_errors' => false,
        ]);
    }

    public function fetch(string $namespace, string $group, string $dataId): ConfigItem
    {
        $response = $this->client->get('api/client/v1/config', [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'auth' => [
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
            ],
            'query' => compact('namespace', 'group', 'dataId'),
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('配置服务读取失败，HTTP ' . $response->getStatusCode());
        }
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $data = $body['data'] ?? null;
        if (($body['code'] ?? -1) !== 0 || !is_array($data)) {
            throw new RuntimeException('配置服务返回无效响应');
        }
        return new ConfigItem(
            (string) ($data['namespace'] ?? ''),
            (string) ($data['group'] ?? ''),
            (string) ($data['dataId'] ?? ''),
            (string) ($data['format'] ?? ''),
            (string) ($data['content'] ?? ''),
            (int) ($data['revision'] ?? 0),
            (string) ($data['md5'] ?? ''),
        );
    }
}
