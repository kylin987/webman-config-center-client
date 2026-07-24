# Webman 项目从 Nacos 迁移到 Config Center 步骤

本文用于记录业务 Webman 项目从 `workbunny/webman-nacos` 迁移到 `kylin987/webman-config-center-client` 的标准流程。

核心原则：不要一开始就关闭 Nacos。先让 Config Center 客户端把配置文件拉到本地，确认文件到位后，再把业务配置切到 CC，最后清理 Nacos。

## 1. 部署 Config Center 客户端

安装客户端包：

```bash
composer require kylin987/webman-config-center-client:^1.1
```

PHP 8.3 项目使用：

```bash
composer83 require kylin987/webman-config-center-client:^1.1
```

配置客户端基础信息：

```env
CONFIG_CENTER_ENDPOINT=https://config-center.example.com/
CONFIG_CENTER_USERNAME=your-client-username
CONFIG_CENTER_PASSWORD=your-client-password
```

编辑：

```text
config/plugin/kylin987/config-center/listeners.php
```

把需要迁移的配置文件先加入监听，例如：

```php
return [
    [
        'group' => 'sw',
        'data_id' => 'sw-mysql.php',
        'format' => 'php',
        'path' => config_path() . '/cc/sw-mysql.php',
        'reload_command' => '',
    ],
    [
        'group' => 'jtk',
        'data_id' => 'jtk-redis.php',
        'format' => 'php',
        'path' => config_path() . '/cc/jtk-redis.php',
        'reload_command' => '',
    ],
];
```

第一步只部署客户端和监听配置，不改业务读取逻辑，不关闭 Nacos。

完成后提交代码。

## 2. 重启项目，确认监听文件已经落地

重启项目后观察 `config/cc/` 是否生成了监听文件：

```text
config/cc/sw-mysql.php
config/cc/jtk-redis.php
```

也可以在项目目录手动执行一次同步：

```bash
php vendor/bin/config-center-sync
```

PHP 8.3 项目使用：

```bash
php83 vendor/bin/config-center-sync
```

如果同步失败，先看业务项目日志。客户端会保留本地旧配置，不应该因为 Config Center 短暂不可用导致业务直接退出。

## 3. Redis 订阅第二阶段再开启

Config Center 客户端自身的 Redis 订阅配置，也依赖 `jtk-redis.php` 这类远端配置内容。

所以第一阶段不要开启 Redis 订阅，先只使用轮询：

```php
'redis' => [
    'enable' => false,
    'host' => '',
    'port' => 6379,
    'password' => '',
    'database' => 0,
],
```

等第 2 步确认 `config/cc/jtk-redis.php` 已经拉到本地后，再参考项目实际配置开启 Redis 订阅，例如：

```php
$redis = config('cc.jtk-redis', []);

return [
    // ...
    'redis' => [
        'enable' => !empty($redis['host']) && (getenv('RUN_ENV') ?? 'docker') !== 'local',
        'host' => $redis['host'] ?? '',
        'port' => $redis['port'] ?? 6379,
        'password' => $redis['password'] ?? '',
        'database' => 0,
    ],
];
```

这样可以避免第一次启动时 Redis 配置还没落地，客户端订阅进程就提前连接 Redis。

## 4. 业务配置直接切到 CC

确认 `config/cc/*.php` 文件已经存在后，再把业务配置直接改成读取 CC。

例如 Redis：

```php
$redis = config('cc.jtk-redis', []);

return [
    'default' => [
        'host' => $redis['host'] ?? getenv('REDIS_HOST'),
        'password' => $redis['password'] ?? getenv('REDIS_PASSWORD'),
        'port' => $redis['port'] ?? getenv('REDIS_PORT'),
        'database' => $redis['db_shanwu'] ?? getenv('REDIS_DATABASE'),
    ],
];
```

例如 Redis Queue：

```php
$redis = config('cc.jtk-redis', []);

return [
    'sw_web' => [
        'host' => 'redis://' . ($redis['host'] ?? getenv('REDIS_HOST')) . ':' . ($redis['port'] ?? getenv('REDIS_PORT')),
        'options' => [
            'auth' => $redis['password'] ?? getenv('REDIS_PASSWORD'),
            'db' => $redis['db_queue'] ?? getenv('REDIS_QUEUE_DATABASE'),
            'prefix' => 'sw_',
        ],
    ],
];
```

例如 MySQL：

```php
$mysql = (array)config('cc.sw-mysql', []);
$sw = $mysql['sw'] ?? [];
```

这一步不兼容 Nacos，业务配置直接切到 CC。

完成后提交代码，重启项目让配置生效。

## 5. 确认无问题后清理 Nacos

确认以下内容都正常后，再清理 Nacos：

- Pod 重启、重建后业务正常。
- `config/cc/*.php` 文件存在。
- 新增或编辑 Config Center 配置后，业务项目能同步。
- MySQL、Redis、Redis Queue 等业务配置都已经读取 `config('cc.xxx')`。
- 日志中没有 Nacos 连接或监听相关错误。

确认稳定后执行：

```bash
composer remove workbunny/webman-nacos
```

PHP 8.3 项目使用：

```bash
composer83 remove workbunny/webman-nacos
```

然后清理项目里的 Nacos 痕迹，包括但不限于：

```text
config/plugin/workbunny/webman-nacos/
config/nacos/
config/app.php 中的 nacos_enable
业务代码里的 config('nacos.xxx')
.env 里的 NACOS_* 配置
```

最后重启项目并观察日志。

## 常见注意事项

### Config Center 服务端公网域名可能走 IPv6

如果服务端开启了客户端 IP 白名单，而公网域名会解析到 IPv6，可以在客户端配置里强制走 IPv4：

```php
'ip_resolve' => 'v4',
```

ACK 内部服务访问一般保持默认即可：

```php
'ip_resolve' => 'auto',
```

### 第一次部署不要依赖 Redis 订阅

第一次部署时，本地 `config/cc/jtk-redis.php` 可能还不存在，所以 Config Center 客户端自己的 Redis 订阅也拿不到 Redis 配置。

正确顺序是：

1. 先用轮询拉配置。
2. 确认 Redis 配置文件落地。
3. 再开启 Redis 订阅。

### 如果业务配置启动时为空

优先检查：

```bash
php vendor/bin/config-center-sync
ls -la config/cc/
```

PHP 8.3 项目：

```bash
php83 vendor/bin/config-center-sync
ls -la config/cc/
```

如果 `config/cc/*.php` 不存在，不要急着关闭 Nacos 或删除旧配置。
