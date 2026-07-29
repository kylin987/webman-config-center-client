<?php

namespace Kylin987\WebmanConfigCenter;

use RuntimeException;

final class ConfigSynchronizer
{
    private ConfigApiClient $api;

    private ContentValidator $validator;

    private AtomicFileWriter $writer;

    private ReloadCommandRunner $reloadCommandRunner;

    public function __construct(private readonly array $config)
    {
        $this->api = new ConfigApiClient($config);
        $this->validator = new ContentValidator();
        $this->writer = new AtomicFileWriter();
        $this->reloadCommandRunner = new ReloadCommandRunner($config);
    }

    public function syncAll(): array
    {
        $results = [];
        foreach ($this->config['items'] ?? [] as $item) {
            $results[] = $this->sync($item);
        }
        return $results;
    }

    public function sync(array $mapping): array
    {
        $namespace = (string) ($mapping['namespace'] ?? $this->config['namespace'] ?? 'public');
        $lockPath = $this->statePath($namespace . '/' . ($mapping['group'] ?? '') . '/' . ($mapping['data_id'] ?? '')) . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('无法获取配置同步锁');
        }
        try {
            return $this->syncLocked($mapping);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function syncLocked(array $mapping): array
    {
        foreach (['group', 'data_id', 'path', 'format'] as $field) {
            if (empty($mapping[$field])) throw new RuntimeException('监听项缺少 ' . $field);
        }
        $namespace = (string) ($mapping['namespace'] ?? $this->config['namespace'] ?? 'public');
        $item = $this->api->fetch($namespace, (string) $mapping['group'], (string) $mapping['data_id']);
        $state = $this->state($item->key());
        $downloadedRevision = (int) ($state['downloaded_revision'] ?? 0);
        $path = $this->resolvePath((string) $mapping['path']);
        if ($this->localFileMatches($path, $item->md5)) {
            if ($item->revision > $downloadedRevision || (string) ($state['md5'] ?? '') !== $item->md5) {
                $this->writeState($item->key(), ['downloaded_revision' => $item->revision, 'md5' => $item->md5]);
            }
            return ['key' => $item->key(), 'status' => 'unchanged', 'revision' => $item->revision];
        }
        $this->validator->validate($item, (string) $mapping['format']);
        $this->writer->write($path, $item->content);
        $status = $item->revision <= $downloadedRevision ? 'repaired' : 'updated';
        $reload = $this->reloadCommandRunner->run($mapping, $item->key(), $item->revision);
        $this->writeState($item->key(), ['downloaded_revision' => $item->revision, 'md5' => $item->md5]);
        $result = ['key' => $item->key(), 'status' => $status, 'revision' => $item->revision, 'path' => $path];
        if ($reload !== null) {
            $result['reload'] = [
                'exit_code' => $reload['exit_code'],
            ];
        }
        return $result;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || preg_match('#(^|[\\\\/])\\.\\.([\\\\/]|$)#', $path)) {
            throw new RuntimeException('配置文件路径不在允许范围内');
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $root = rtrim((string) ($this->config['config_root'] ?? ''), '/');
        if ($root === '') {
            throw new RuntimeException('配置文件路径不在允许范围内');
        }

        return $root . '/' . ltrim($path, '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private function localFileMatches(string $path, string $md5): bool
    {
        if ($md5 === '' || !is_file($path)) {
            return false;
        }
        $hash = hash_file('md5', $path);
        return is_string($hash) && hash_equals(strtolower($md5), strtolower($hash));
    }

    private function state(string $key): array
    {
        $path = $this->statePath($key);
        if (!is_file($path)) return [];
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeState(string $key, array $state): void
    {
        $path = $this->statePath($key);
        (new AtomicFileWriter())->write($path, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function statePath(string $key): string
    {
        $directory = rtrim((string) ($this->config['state_dir'] ?? ''), '/');
        if ($directory === '') throw new RuntimeException('未配置 state_dir');
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建配置状态目录');
        }
        return $directory . '/' . hash('sha256', $key) . '.json';
    }
}
