<?php

namespace Kylin987\WebmanConfigCenter;

final class ConfigCenterLogger
{
    private static array $lastLoggedAt = [];

    public function __construct(private readonly array $config)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function warningThrottled(string $key, string $message, array $context = []): void
    {
        $seconds = max(0, (int) ($this->config['log_throttle_seconds'] ?? 300));
        $now = time();
        $lastLoggedAt = self::$lastLoggedAt[$key] ?? 0;
        if ($seconds > 0 && $lastLoggedAt > 0 && ($now - $lastLoggedAt) < $seconds) {
            return;
        }
        self::$lastLoggedAt[$key] = $now;
        $this->warning($message, $context + ['throttle_seconds' => $seconds]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $context = $this->normalizeContext($context);
        $channel = (string) ($this->config['log_channel'] ?? 'default');
        $channel = $channel !== '' ? $channel : 'default';

        try {
            if (class_exists('\\support\\Log')) {
                \support\Log::channel($channel)->{$level}($message, $context);
                return;
            }
        } catch (\Throwable) {
        }

        error_log('[webman-config-center-client] ' . $level . ' ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = [
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                ];
            }
        }
        return $context;
    }
}
