<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Yhs\WebmanConfigCenter\ConfigItem;
use Yhs\WebmanConfigCenter\ConfigSynchronizer;
use Yhs\WebmanConfigCenter\ContentValidator;

$validator = new ContentValidator();
$content = "<?php return ['enable' => true];";
$validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'app.php', 'php', $content, 1, md5($content)), 'php');

try {
    $content = "<?php return getenv('SECRET');";
    $validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'bad.php', 'php', $content, 1, md5($content)), 'php');
    throw new RuntimeException('动态 PHP 配置未被拒绝');
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
        'apply_secret' => 'test-secret',
    ]);
    $mapping = [
        'group' => 'DEFAULT_GROUP',
        'data_id' => 'app.php',
        'path' => 'app.php',
        'format' => 'php',
    ];
    $path = $configRoot . '/app.php';

    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'updated', '首次同步应返回 updated');
    assertTrue(file_get_contents($path) === $servedContent, '首次同步未写入配置文件');

    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'unchanged', '同版本且本地一致时应返回 unchanged');

    unlink($path);
    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'repaired', '同版本但本地文件缺失时应返回 repaired');
    assertTrue(file_get_contents($path) === $servedContent, '本地文件缺失后未自动修复');

    file_put_contents($path, "<?php return ['from' => 'local'];");
    $result = $synchronizer->sync($mapping);
    assertTrue($result['status'] === 'repaired', '同版本但本地内容不一致时应返回 repaired');
    assertTrue(file_get_contents($path) === $servedContent, '本地文件被改坏后未自动修复');
} finally {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
    rmrf($tmp);
}

echo "ok\n";
