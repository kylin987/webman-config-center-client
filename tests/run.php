<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Kylin987\WebmanConfigCenter\ConfigItem;
use Kylin987\WebmanConfigCenter\ConfigCenterLogger;
use Kylin987\WebmanConfigCenter\ConfigLoader;
use Kylin987\WebmanConfigCenter\ConfigSynchronizer;
use Kylin987\WebmanConfigCenter\ContentValidator;

$validator = new ContentValidator();
$content = "<?php return ['enable' => true];";
$validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'app.php', 'php', $content, 1, md5($content)), 'php');

try {
    $content = "<?php return getenv('SECRET');";
    $validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'bad.php', 'php', $content, 1, md5($content)), 'php');
    throw new RuntimeException('动态 PHP 配置未被拒绝');
} catch (\InvalidArgumentException) {
}

$content = "<?php\nclass Rule {}\n";
$validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'Rule.php', 'php_code', $content, 1, md5($content)), 'php_code');

try {
    $validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'Rule.php', 'php', $content, 1, md5($content)), 'php');
    throw new RuntimeException('PHP 类文件不应作为普通 php 配置通过校验');
} catch (\InvalidArgumentException) {
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rmrf(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        rmrf($path . '/' . $item);
    }
    rmdir($path);
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$socket) {
        throw new RuntimeException('无法获取测试端口：' . $errstr);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr(strrchr((string) $name, ':'), 1);
}

$tmp = sys_get_temp_dir() . '/config-center-client-test-' . bin2hex(random_bytes(6));
$router = $tmp . '/router.php';
$configRoot = $tmp . '/config';
$stateDir = $tmp . '/state';
$serverProcess = null;
$originalErrorLog = ini_get('error_log');

try {
    mkdir($tmp, 0750, true);
    $servedContent = "<?php return ['from' => 'remote'];";
    file_put_contents($router, <<<'PHP'
<?php
$content = "<?php return ['from' => 'remote'];";
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/client/v1/config') {
    http_response_code(404);
    echo 'not found';
    return;
}
header('Content-Type: application/json');
echo json_encode([
    'code' => 0,
    'data' => [
        'namespace' => $_GET['namespace'] ?? 'public',
        'group' => $_GET['group'] ?? 'DEFAULT_GROUP',
        'dataId' => $_GET['dataId'] ?? 'app.php',
        'format' => 'php',
        'content' => $content,
        'revision' => 5,
        'md5' => md5($content),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP);

    $port = freePort();
    $command = PHP_BINARY . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
    $serverProcess = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $tmp . '/server.log', 'a'],
        2 => ['file', $tmp . '/server.log', 'a'],
    ], $pipes, $tmp);
    if (!is_resource($serverProcess)) {
        throw new RuntimeException('无法启动测试配置服务');
    }
    fclose($pipes[0]);

    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    assertTrue($ready, '测试配置服务启动超时');

    $synchronizer = new ConfigSynchronizer([
        'endpoint' => 'http://127.0.0.1:' . $port,
        'username' => 'client',
        'password' => 'secret',
        'namespace' => 'public',
        'config_root' => $configRoot,
        'state_dir' => $stateDir,
        'log_channel' => 'default',
    ]);
    $reloadLog = $tmp . '/reload.log';
    $reloadCommand = PHP_BINARY . ' -r ' . escapeshellarg('file_put_contents(' . var_export($reloadLog, true) . ', "reload\n", FILE_APPEND);');
    $mapping = [
        'group' => 'DEFAULT_GROUP',
        'data_id' => 'app.php',
        'path' => 'app.php',
        'format' => 'php',
        'reload_command' => $reloadCommand,
    ];
    $path = $configRoot . '/app.php';

    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'updated', '首次同步应返回 updated');
    assertTrue(file_get_contents($path) === $servedContent, '首次同步未写入配置文件');
    assertTrue((string) file_get_contents($reloadLog) === "reload\n", '首次同步未执行 reload_command');

    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'unchanged', '同版本且本地一致时应返回 unchanged');
    assertTrue((string) file_get_contents($reloadLog) === "reload\n", '配置未变化时不应执行 reload_command');

    unlink($path);
    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'repaired', '同版本但本地文件缺失时应返回 repaired');
    assertTrue(file_get_contents($path) === $servedContent, '本地文件缺失后未自动修复');
    assertTrue((string) file_get_contents($reloadLog) === "reload\nreload\n", '配置修复时未执行 reload_command');

    file_put_contents($path, "<?php return ['from' => 'local'];");
    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'repaired', '同版本但本地内容不一致时应返回 repaired');
    assertTrue(file_get_contents($path) === $servedContent, '本地文件被改坏后未自动修复');

    $absolutePath = $tmp . '/outside/sw-mysql.php';
    $result = $synchronizer->sync([
        'group' => 'DEFAULT_GROUP',
        'data_id' => 'sw-mysql.php',
        'path' => $absolutePath,
        'format' => 'php',
    ]);
    assertTrue($result['status'] === 'updated', '绝对路径首次同步应返回 updated');
    assertTrue(file_get_contents($absolutePath) === $servedContent, '绝对路径未正确写入配置文件');

    $logPath = $tmp . '/client.log';
    ini_set('error_log', $logPath);
    $logger = new ConfigCenterLogger(['log_channel' => 'default', 'log_throttle_seconds' => 300]);
    $logger->warningThrottled('test.throttle', 'config-center test throttled warning', ['x' => 1]);
    $logger->warningThrottled('test.throttle', 'config-center test throttled warning', ['x' => 2]);
    $logContent = (string) file_get_contents($logPath);
    assertTrue(substr_count($logContent, 'config-center test throttled warning') === 1, '日志限频未生效');

    $commandRoot = $tmp . '/command-root';
    mkdir($commandRoot . '/config/plugin/kylin987/config-center', 0750, true);
    file_put_contents($commandRoot . '/config/plugin/kylin987/config-center/config.php', <<<'PHP'
<?php
return [
    'endpoint' => 'http://127.0.0.1:1',
    'username' => 'client',
    'password' => 'secret',
    'namespace' => 'public',
    'config_root' => __DIR__,
    'state_dir' => sys_get_temp_dir() . '/config-center-client-test-state',
    'connect_timeout' => 0.1,
    'timeout' => 0.1,
    'log_channel' => 'default',
    'log_throttle_seconds' => 300,
    'fail_on_error' => false,
];
PHP);
    file_put_contents($commandRoot . '/config/plugin/kylin987/config-center/listeners.php', <<<'PHP'
<?php
return [
    ['group' => 'DEFAULT_GROUP', 'data_id' => 'app.php', 'format' => 'php', 'path' => 'app.php'],
];
PHP);
    $loaded = ConfigLoader::load($commandRoot);
    assertTrue(count($loaded['items']) === 1 && $loaded['items'][0]['data_id'] === 'app.php', 'listeners.php 未正确加载为监听项');
    $safeHostname = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) gethostname());
    assertTrue(basename((string) $loaded['state_dir']) === $safeHostname, 'state_dir 默认应按主机名隔离');

    $sharedStateConfig = str_replace(
        "'state_dir' => sys_get_temp_dir() . '/config-center-client-test-state',",
        "'state_dir' => sys_get_temp_dir() . '/config-center-client-test-state',\n    'state_dir_host_isolation' => false,",
        (string) file_get_contents($commandRoot . '/config/plugin/kylin987/config-center/config.php')
    );
    file_put_contents($commandRoot . '/config/plugin/kylin987/config-center/config.php', $sharedStateConfig);
    $loaded = ConfigLoader::load($commandRoot);
    assertTrue(basename((string) $loaded['state_dir']) === 'config-center-client-test-state', 'state_dir_host_isolation=false 时不应追加主机名');

    $isolatedStateConfig = str_replace(
        "\n    'state_dir_host_isolation' => false,",
        '',
        $sharedStateConfig
    );
    file_put_contents($commandRoot . '/config/plugin/kylin987/config-center/config.php', $isolatedStateConfig);

    $syncCommand = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/config-center-sync');
    exec('cd ' . escapeshellarg($commandRoot) . ' && ' . $syncCommand . ' 2>/dev/null', $output, $exitCode);
    assertTrue($exitCode === 0, '默认配置下 config-center-sync 失败不应阻断启动');

    $strictConfig = str_replace("'fail_on_error' => false", "'fail_on_error' => true", (string) file_get_contents($commandRoot . '/config/plugin/kylin987/config-center/config.php'));
    file_put_contents($commandRoot . '/config/plugin/kylin987/config-center/config.php', $strictConfig);
    exec('cd ' . escapeshellarg($commandRoot) . ' && ' . $syncCommand . ' 2>/dev/null', $output, $exitCode);
    assertTrue($exitCode === 1, 'fail_on_error=true 时 config-center-sync 失败应返回非 0');

    $eventLoopCompatScript = $tmp . '/event-loop-compat.php';
    file_put_contents($eventLoopCompatScript, <<<'PHP'
<?php
namespace Workerman\Events {
    interface EventInterface
    {
        public const EV_READ = 1;
    }
}

namespace Workerman {
    class Worker
    {
        public static $globalEvent = null;
    }
}

namespace {
    require __DIR__ . '/vendor/autoload.php';

    use Kylin987\WebmanConfigCenter\Process\ConfigCenterProcess;
    use Workerman\Events\EventInterface;
    use Workerman\Worker;

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    class LegacyEventLoop
    {
        public array $calls = [];
        public $callback = null;

        public function add($fd, $flag, $func, $args = []): bool
        {
            $this->calls[] = ['add', $flag];
            $this->callback = $func;
            return true;
        }

        public function del($fd, $flag): bool
        {
            $this->calls[] = ['del', $flag];
            return true;
        }
    }

    class ModernEventLoop
    {
        public array $calls = [];
        public $callback = null;

        public function onReadable($fd, $func): bool
        {
            $this->calls[] = ['onReadable'];
            $this->callback = $func;
            return true;
        }

        public function offReadable($fd): bool
        {
            $this->calls[] = ['offReadable'];
            return true;
        }
    }

    $process = new ConfigCenterProcess();
    $register = new ReflectionMethod($process, 'registerRedisReadable');
    $register->setAccessible(true);
    $unregister = new ReflectionMethod($process, 'unregisterRedisReadable');
    $unregister->setAccessible(true);
    $socket = fopen('php://temp', 'r+');

    Worker::$globalEvent = new LegacyEventLoop();
    $register->invoke($process, $socket);
    check(Worker::$globalEvent->calls === [['add', EventInterface::EV_READ]], 'Workerman v4 应使用 add(EV_READ)');
    check(is_callable(Worker::$globalEvent->callback), 'Workerman v4 readable callback 未注册');
    $unregister->invoke($process, $socket);
    check(Worker::$globalEvent->calls === [['add', EventInterface::EV_READ], ['del', EventInterface::EV_READ]], 'Workerman v4 应使用 del(EV_READ)');

    Worker::$globalEvent = new ModernEventLoop();
    $register->invoke($process, $socket);
    check(Worker::$globalEvent->calls === [['onReadable']], 'Workerman v5 应使用 onReadable');
    check(is_callable(Worker::$globalEvent->callback), 'Workerman v5 readable callback 未注册');
    $unregister->invoke($process, $socket);
    check(Worker::$globalEvent->calls === [['onReadable'], ['offReadable']], 'Workerman v5 应使用 offReadable');

    fclose($socket);
    echo "event-loop-ok\n";
}
PHP);
    $autoloadLink = $tmp . '/vendor';
    symlink(dirname(__DIR__) . '/vendor', $autoloadLink);
    exec(PHP_BINARY . ' ' . escapeshellarg($eventLoopCompatScript), $output, $exitCode);
    assertTrue($exitCode === 0 && in_array('event-loop-ok', $output, true), 'Workerman 事件循环兼容测试失败：' . implode("\n", $output));

    $missingListenersRoot = $tmp . '/missing-listeners-root';
    mkdir($missingListenersRoot . '/config/plugin/kylin987/config-center', 0750, true);
    file_put_contents($missingListenersRoot . '/config/plugin/kylin987/config-center/config.php', "<?php\nreturn [];\n");
    try {
        ConfigLoader::load($missingListenersRoot);
        throw new RuntimeException('缺少 listeners.php 时应抛出异常');
    } catch (RuntimeException $exception) {
        assertTrue(str_contains($exception->getMessage(), '缺少监听配置文件'), '缺少 listeners.php 的异常提示不正确');
    }
} finally {
    ini_set('error_log', $originalErrorLog === false ? '' : $originalErrorLog);
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
    rmrf($tmp);
}

echo "ok\n";
