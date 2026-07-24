<?php

namespace Kylin987\WebmanConfigCenter;

use RuntimeException;

final class RedisConnectionConfig
{
    /**
     * @return array{enabled:bool,scheme:string,host:string,port:int,password:string,database:int|null}
     */
    public static function fromConfig(array $config): array
    {
        $redis = $config['redis'] ?? null;
        if (is_array($redis) && array_key_exists('enable', $redis)) {
            if (!filter_var($redis['enable'], FILTER_VALIDATE_BOOL)) {
                return self::disabled();
            }

            return self::fromArray($redis);
        }

        $url = trim((string) ($config['redis_url'] ?? ''));
        if ($url === '') {
            return self::disabled();
        }

        return self::fromUrl($url);
    }

    public static function isEnabled(array $config): bool
    {
        return self::fromConfig($config)['enabled'];
    }

    /**
     * @return array<string,mixed>
     */
    public static function toPredisParameters(array $redis): array
    {
        $parameters = [
            'scheme' => $redis['scheme'],
            'host' => $redis['host'],
            'port' => $redis['port'],
        ];

        if ($redis['password'] !== '') {
            $parameters['password'] = $redis['password'];
        }

        if ($redis['database'] !== null) {
            $parameters['database'] = $redis['database'];
        }

        return $parameters;
    }

    /**
     * @return array{enabled:bool,scheme:string,host:string,port:int,password:string,database:int|null}
     */
    private static function fromArray(array $redis): array
    {
        $host = trim((string) ($redis['host'] ?? ''));
        if ($host === '') {
            throw new RuntimeException('redis.host 不能为空');
        }

        $database = $redis['database'] ?? null;

        return [
            'enabled' => true,
            'scheme' => trim((string) ($redis['scheme'] ?? 'tcp')) ?: 'tcp',
            'host' => $host,
            'port' => (int) ($redis['port'] ?? 6379),
            'password' => (string) ($redis['password'] ?? ''),
            'database' => $database === null || $database === '' ? null : (int) $database,
        ];
    }

    /**
     * @return array{enabled:bool,scheme:string,host:string,port:int,password:string,database:int|null}
     */
    private static function fromUrl(string $url): array
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
            'enabled' => true,
            'scheme' => (string) ($parts['scheme'] ?? 'tcp'),
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? 6379),
            'password' => isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
            'database' => $database,
        ];
    }

    /**
     * @return array{enabled:bool,scheme:string,host:string,port:int,password:string,database:int|null}
     */
    private static function disabled(): array
    {
        return [
            'enabled' => false,
            'scheme' => 'tcp',
            'host' => '',
            'port' => 6379,
            'password' => '',
            'database' => null,
        ];
    }
}
