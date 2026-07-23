<?php

namespace Yhs\WebmanConfigCenter;

use RuntimeException;

final class ApplyAdapter
{
    public function __construct(private readonly array $config)
    {
    }

    public function consume(): void
    {
        $directory = rtrim((string) ($this->config['state_dir'] ?? ''), '/');
        $secret = (string) ($this->config['apply_secret'] ?? '');
        if ($directory === '' || $secret === '') return;
        foreach (glob($directory . '/apply/*.json') ?: [] as $path) {
            $request = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!hash_equals(hash_hmac('sha256', $request['key'] . ':' . $request['revision'], $secret), (string) ($request['signature'] ?? ''))) throw new RuntimeException('配置 apply 签名无效');
            $mapping = $this->mapping((string) $request['key']);
            if (($command = (string) ($mapping['reload_command'] ?? '')) !== '') {
                exec($command . ' >/dev/null 2>&1', $output, $code);
                if ($code !== 0) throw new RuntimeException('配置 reload 命令执行失败');
            }
            (new AtomicFileWriter())->write($directory . '/applied/' . hash('sha256', $request['key']) . '.json', json_encode(['revision' => $request['revision']], JSON_THROW_ON_ERROR));
            unlink($path);
        }
    }

    private function mapping(string $key): array
    {
        foreach ($this->config['items'] ?? [] as $item) {
            $namespace = $item['namespace'] ?? $this->config['namespace'] ?? 'public';
            if ($namespace . '/' . $item['group'] . '/' . $item['data_id'] === $key) return $item;
        }
        throw new RuntimeException('未找到本地 apply 映射');
    }
}

