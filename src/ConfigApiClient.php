<?php

namespace Kylin987\WebmanConfigCenter;

use GuzzleHttp\Client;
use RuntimeException;

final class ConfigApiClient
{
    private Client $client;

    public function __construct(private readonly array $config)
    {
        $this->client = new Client($this->clientOptions([
            'base_uri' => rtrim((string) ($config['endpoint'] ?? ''), '/') . '/',
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 3),
            'timeout' => (float) ($config['timeout'] ?? 8),
            'http_errors' => false,
        ]));
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
            throw new RuntimeException($this->errorMessage(
                '配置服务读取失败',
                $response->getStatusCode(),
                (string) $response->getBody(),
                $namespace,
                $group,
                $dataId
            ));
        }

        $rawBody = (string) $response->getBody();
        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException($this->errorMessage(
                '配置服务返回无效 JSON',
                $response->getStatusCode(),
                $rawBody,
                $namespace,
                $group,
                $dataId
            ), 0, $exception);
        }

        $data = $body['data'] ?? null;
        if (($body['code'] ?? -1) !== 0 || !is_array($data)) {
            throw new RuntimeException($this->errorMessage(
                '配置服务返回错误',
                $response->getStatusCode(),
                $rawBody,
                $namespace,
                $group,
                $dataId
            ));
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

    public function publish(
        string $namespace,
        string $group,
        string $dataId,
        string $format,
        string $content,
        ?int $expectedRevision = null,
        string $note = ''
    ): ConfigPublishResult {
        $payload = [
            'namespace' => $namespace,
            'group' => $group,
            'dataId' => $dataId,
            'format' => $format,
            'content' => $content,
            'note' => $note,
        ];
        if ($expectedRevision !== null) {
            $payload['expectedRevision'] = $expectedRevision;
        }

        $response = $this->client->post('api/client/v1/config/publish', [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'auth' => [
                (string) ($this->config['username'] ?? ''),
                (string) ($this->config['password'] ?? ''),
            ],
            'json' => $payload,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException($this->errorMessage(
                '配置服务发布失败',
                $response->getStatusCode(),
                (string) $response->getBody(),
                $namespace,
                $group,
                $dataId
            ));
        }

        $rawBody = (string) $response->getBody();
        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new RuntimeException($this->errorMessage(
                '配置服务返回无效 JSON',
                $response->getStatusCode(),
                $rawBody,
                $namespace,
                $group,
                $dataId
            ), 0, $exception);
        }

        $data = $body['data'] ?? null;
        if (($body['code'] ?? -1) !== 0 || !is_array($data)) {
            throw new RuntimeException($this->errorMessage(
                '配置服务返回错误',
                $response->getStatusCode(),
                $rawBody,
                $namespace,
                $group,
                $dataId
            ));
        }

        return new ConfigPublishResult(
            (string) ($data['namespace'] ?? ''),
            (string) ($data['group'] ?? ''),
            (string) ($data['dataId'] ?? ''),
            (string) ($data['format'] ?? $format),
            (int) ($data['revision'] ?? 0),
            (string) ($data['md5'] ?? ''),
        );
    }

    private function errorMessage(string $prefix, int $statusCode, string $body, string $namespace, string $group, string $dataId): string
    {
        $message = $prefix . ' [' . $namespace . '/' . $group . '/' . $dataId . ']，HTTP ' . $statusCode;
        $detail = $this->responseDetail($body);

        return $detail === '' ? $message : $message . '：' . $detail;
    }

    private function responseDetail(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($json)) {
                foreach (['message', 'msg', 'error'] as $field) {
                    if (isset($json[$field]) && is_scalar($json[$field]) && trim((string) $json[$field]) !== '') {
                        return $this->shorten((string) $json[$field]);
                    }
                }
            }
        } catch (\Throwable) {
        }

        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($body)));
        return $this->shorten($plain);
    }

    private function shorten(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        $limit = 300;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($message, 'UTF-8') > $limit ? mb_substr($message, 0, $limit, 'UTF-8') . '...' : $message;
        }

        return strlen($message) > $limit ? substr($message, 0, $limit) . '...' : $message;
    }

    private function clientOptions(array $options): array
    {
        $ipResolve = strtolower(trim((string) ($this->config['ip_resolve'] ?? 'auto')));
        if ($ipResolve === '' || $ipResolve === 'auto') {
            return $options;
        }

        $curlValue = match ($ipResolve) {
            'v4', 'ipv4', '4' => 'CURL_IPRESOLVE_V4',
            'v6', 'ipv6', '6' => 'CURL_IPRESOLVE_V6',
            default => throw new RuntimeException('ip_resolve 配置不正确，仅支持 auto、v4、v6'),
        };

        if (!defined('CURLOPT_IPRESOLVE') || !defined($curlValue)) {
            throw new RuntimeException('当前 PHP cURL 环境不支持 ip_resolve 配置');
        }

        $options['curl'][constant('CURLOPT_IPRESOLVE')] = constant($curlValue);

        return $options;
    }
}
