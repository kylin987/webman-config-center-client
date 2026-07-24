<?php

namespace Kylin987\WebmanConfigCenter\Process;

use Kylin987\WebmanConfigCenter\ConfigCenterLogger;
use Kylin987\WebmanConfigCenter\ConfigLoader;
use Kylin987\WebmanConfigCenter\ConfigSynchronizer;
use Workerman\Timer;
use Workerman\Worker;

final class ConfigCenterPollProcess
{
    private bool $wasFailing = false;

    public function onWorkerStart(Worker $worker): void
    {
        $config = ConfigLoader::load();
        $interval = max(10, (int) ($config['poll_interval'] ?? 60));

        $this->sync($config);
        Timer::add($interval, function () use ($config) {
            $this->sync($config);
        });
    }

    private function sync(array $config): void
    {
        $logger = new ConfigCenterLogger($config);

        try {
            (new ConfigSynchronizer($config))->syncAll();
            if ($this->wasFailing) {
                $logger->info('config-center poll process recovered');
            }
            $this->wasFailing = false;
        } catch (\Throwable $exception) {
            $this->wasFailing = true;
            $logger->warningThrottled('poll.process.failed', 'config-center poll process failed; keep using local config files', [
                'exception' => $exception,
            ]);
        }
    }
}
