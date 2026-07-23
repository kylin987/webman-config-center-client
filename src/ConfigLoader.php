<?php

namespace Yhs\WebmanConfigCenter;

use RuntimeException;

final class ConfigLoader
{
    public static function load(?string $basePath = null): array
    {
        $basePath = rtrim($basePath ?: getcwd(), '/');
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
                return $config;
            }
        }

        throw new RuntimeException(
            "缺少配置文件，请确认已安装插件配置：config/plugin/kylin987/config-center/config.php"
        );
    }
}
