<?php

namespace Kylin987\WebmanConfigCenter;

class Install
{
    public const WEBMAN_PLUGIN = true;

    protected static array $pathRelation = [
        'config/plugin/kylin987/config-center' => 'config/plugin/kylin987/config-center',
    ];

    public static function install(): void
    {
        static::installByRelation();
        static::installConfigCacheDirectory();
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

    public static function installConfigCacheDirectory(): void
    {
        $sourceDirectory = __DIR__ . '/config/cc';
        $destDirectory = base_path() . '/config/cc';
        if (!is_dir($destDirectory)) {
            mkdir($destDirectory, 0777, true);
        }

        foreach (['.gitignore', 'app.php'] as $filename) {
            $source = $sourceDirectory . '/' . $filename;
            $dest = $destDirectory . '/' . $filename;
            if (is_file($source) && !file_exists($dest)) {
                copy($source, $dest);
            }
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
