<?php

namespace Kylin987\WebmanConfigCenter;

use RuntimeException;

final class ReloadCommandRunner
{
    private ConfigCenterLogger $logger;

    public function __construct(private readonly array $config)
    {
        $this->logger = new ConfigCenterLogger($config);
    }

    public function run(array $mapping, string $key, int $revision): ?array
    {
        $command = trim((string) ($mapping['reload_command'] ?? ''));
        if ($command === '') {
            return null;
        }

        $output = [];
        exec($command . ' 2>&1', $output, $code);

        $result = [
            'command' => $command,
            'exit_code' => $code,
            'output' => $this->tailOutput($output),
        ];

        $context = [
            'key' => $key,
            'revision' => $revision,
            'command' => $command,
            'exit_code' => $code,
            'output' => $result['output'],
        ];

        if ($code !== 0) {
            $this->logger->warning('config-center reload command failed', $context);
            throw new RuntimeException('配置 reload 命令执行失败');
        }

        $this->logger->info('config-center reload command executed', $context);
        return $result;
    }

    private function tailOutput(array $output): array
    {
        $lines = array_slice(array_map(static fn ($line) => (string) $line, $output), -20);

        return array_map(static function (string $line): string {
            return substr($line, 0, 1000);
        }, $lines);
    }
}
