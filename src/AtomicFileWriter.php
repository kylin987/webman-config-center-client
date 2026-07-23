<?php

namespace Yhs\WebmanConfigCenter;

use RuntimeException;

final class AtomicFileWriter
{
    public function write(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建配置目录');
        }
        $temporary = tempnam($directory, '.config-center-');
        if ($temporary === false) throw new RuntimeException('无法创建临时配置文件');
        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false) throw new RuntimeException('无法写入临时配置文件');
            if (!rename($temporary, $path)) throw new RuntimeException('无法原子替换配置文件');
            $temporary = null;
        } finally {
            if ($temporary && is_file($temporary)) unlink($temporary);
        }
    }
}

