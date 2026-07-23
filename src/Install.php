<?php

namespace Yhs\WebmanConfigCenter;

class Install
{
    public const WEBMAN_PLUGIN = true;

    protected static array $pathRelation = [
        'config/plugin/kylin987/config-center' => 'config/plugin/kylin987/config-center',
    ];

    public static function install(): void
    {
        static::installByRelation();
    }

    public static function uninstall(): void
    {
        static::uninstallByRelation();
    }

    public static function installByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            if ($pos = strrpos($dest, '/')) {
                $parentDir = base_path() . '/' . substr($dest, 0, $pos);
                if (!is_dir($parentDir)) {
                    mkdir($parentDir, 0777, true);
                }
            }
            copy_dir(__DIR__ . '/' . $source, base_path() . '/' . $dest);
        }
    }

    public static function uninstallByRelation(): void
    {
        foreach (static::$pathRelation as $source => $dest) {
            $path = base_path() . '/' . $dest;
            if (!is_dir($path) && !is_file($path)) {
                continue;
            }
            remove_dir($path);
        }
    }
}
