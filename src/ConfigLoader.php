<?php

namespace Kylin987\WebmanConfigCenter;

use RuntimeException;

final class ConfigLoader
{
    private static bool $envLoaded = false;

    public static function load(?string $basePath = null): array
    {
        $basePath = rtrim($basePath ?: getcwd(), '/');
        self::loadEnv($basePath);
        $paths = [
            $basePath . '/config/plugin/kylin987/config-center/config.php',
            $basePath . '/config/config-center.php',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $config = require $path;
                if (!is_array($config)) {
                    throw new RuntimeException('配置文件必须返回数组：' . $path);
                }
                return self::withListeners(self::normalizeStateDirectory($config), dirname($path));
            }
        }

        throw new RuntimeException(
            "缺少配置文件，请确认已安装插件配置：config/plugin/kylin987/config-center/config.php"
        );
    }

    private static function withListeners(array $config, string $configDirectory): array
    {
        $listenersPath = rtrim($configDirectory, '/') . '/listeners.php';
        if (!is_file($listenersPath)) {
            throw new RuntimeException('缺少监听配置文件：' . $listenersPath);
        }

        $listeners = require $listenersPath;
        if (!is_array($listeners)) {
            throw new RuntimeException('监听配置文件必须返回数组：' . $listenersPath);
        }

        $config['items'] = $listeners;
        return $config;
    }

    private static function normalizeStateDirectory(array $config): array
    {
        if (empty($config['state_dir']) || ($config['state_dir_host_isolation'] ?? true) === false) {
            return $config;
        }

        $hostname = self::hostname();
        $stateDir = rtrim((string) $config['state_dir'], '/');
        if ($hostname !== '' && basename($stateDir) !== $hostname) {
            $config['state_dir'] = $stateDir . '/' . $hostname;
        }

        return $config;
    }

    private static function hostname(): string
    {
        $hostname = gethostname();
        if (!is_string($hostname) || $hostname === '') {
            $hostname = php_uname('n');
        }

        return preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $hostname) ?: '';
    }

    private static function loadEnv(string $basePath): void
    {
        if (self::$envLoaded || !is_file($basePath . '/.env') || !class_exists('\\Dotenv\\Dotenv')) {
            return;
        }

        if (method_exists('\\Dotenv\\Dotenv', 'createUnsafeMutable')) {
            \Dotenv\Dotenv::createUnsafeMutable($basePath)->load();
        } else {
            \Dotenv\Dotenv::createMutable($basePath)->load();
        }

        self::$envLoaded = true;
    }
}
