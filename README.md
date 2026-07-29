# kylin987/webman-config-center-client

面向 Webman 项目的轻量配置中心客户端。

它负责从 Config Center 服务端拉取配置，校验内容格式，然后写入业务项目本地配置目录。远端异常时会保留本地旧配置，避免因为配置中心短暂不可用导致业务 worker 退出。

配套服务端仓库：[kylin987/webman-config-center](https://github.com/kylin987/webman-config-center)

## 安装

```bash
composer require kylin987/webman-config-center-client
```

安装后，Webman 会自动复制插件配置到：

```text
config/plugin/kylin987/config-center/
```

同时会创建配置落地目录：

```text
config/cc/
├── .gitignore
└── app.php
```

其中 `config/cc/app.php` 是占位配置，保证 `config('cc.*')` 这类读取方式在远端配置同步前也可用；其他同步生成的配置文件默认不会提交到 git。

主要配置文件是：

```text
config/plugin/kylin987/config-center/app.php
config/plugin/kylin987/config-center/config.php
config/plugin/kylin987/config-center/listeners.php
config/plugin/kylin987/config-center/process.php
```

## 配置

打开 `config/plugin/kylin987/config-center/config.php`，根据项目实际情况修改服务端地址、客户端账号密码、轮询和日志参数。

插件默认开启。Webman 启动时会自动启动一个 `config-center` 进程：

```bash
php start.php start
```

如果需要关闭自动同步进程，修改：

```php
// config/plugin/kylin987/config-center/app.php
return [
    'enable' => false,
];
```

推荐只把服务端地址、客户端账号密码等环境相关或敏感信息放到 `.env`：

```bash
CONFIG_CENTER_ENDPOINT=http://config-center.example.com/
CONFIG_CENTER_USERNAME=your-client-username
CONFIG_CENTER_PASSWORD=your-client-password
CONFIG_CENTER_REDIS_PASSWORD=your-redis-password
```

其他普通配置建议直接写在 `config.php` 中，例如 namespace、Redis 地址、DB、频道、轮询间隔、日志 channel 等。

### 多 Pod 共享目录和状态目录

客户端会把远端配置写入 `config/cc`，这个目录可以被多个 Pod 共享。但同步状态目录 `state_dir` 默认会按主机名再隔离一层：

```php
'state_dir' => runtime_path() . '/config-center',
'state_dir_host_isolation' => true,
```

实际使用时会变成类似：

```text
runtime/config-center/<hostname>/
```

这样做是为了兼容多 Pod 共享同一套项目目录的部署方式。如果所有 Pod 共用同一个 `state_dir`，可能出现一个 Pod 已经写入最新 state，其他 Pod 收到 Redis 通知后判断为 `unchanged`，从而不执行自己的 `reload_command`。默认按主机名隔离后，每个 Pod 都会独立记录同步状态，并在配置更新时执行自己的 reload。

如果你明确只有单实例，或者确实希望多个进程共享同一份同步状态，可以关闭：

```php
'state_dir_host_isolation' => false,
```

如果服务端开启了客户端 IP 白名单，并且公网域名可能解析到 IPv6，可以在 `config.php` 中强制客户端走 IPv4：

```php
'ip_resolve' => 'v4',
```

可选值：

- `auto`：默认值，交给系统和 DNS 决定。
- `v4`：强制 IPv4，适合公网域名 + IPv4 白名单。
- `v6`：强制 IPv6，一般不需要。

轮询默认带随机抖动，避免几十个客户端集中在同一秒请求服务端：

- `poll_interval=60` 表示基础轮询间隔是 60 秒。
- `poll_jitter_seconds=30` 表示每次实际轮询会随机落在 30~90 秒之间。
- 如果把 `poll_jitter_seconds` 配成 `null`，默认取 `poll_interval` 的一半，最多 30 秒。
- 自动进程启动后会立即同步一次，方便启动后马上生成本地配置文件；后续轮询会带随机抖动。

如果需要 Redis Pub/Sub 实时通知，修改 `config.php`：

```php
'redis' => [
    'enable' => true,
    'host' => 'redis.default.svc',
    'port' => 6379,
    'password' => getenv('CONFIG_CENTER_REDIS_PASSWORD') ?: '',
    'database' => 0,
],
```

启用 Redis 后，自动进程会同时做两件事：

- 按 `poll_interval` 定时轮询，作为兜底。
- 订阅 Redis Pub/Sub，服务端发布配置后立即收到通知并同步。

旧版或高级用法仍然兼容 `redis_url`：

```php
'redis_url' => 'tcp://:redis-password@redis.example.com:6379/0',
```

如果配置文件里存在 `redis` 数组，并且明确设置了 `enable`，会优先按 `redis` 数组判断是否启用。

打开 `config/plugin/kylin987/config-center/listeners.php`，维护需要监听/同步的配置文件。

`listeners.php` 是白名单，只有声明过的配置才会被写入本地文件：

```php
<?php

return [
    [
        'group' => 'DEFAULT_GROUP',
        'data_id' => 'app.php',
        'format' => 'php',
        'path' => config_path() . '/cc/app.php',
        'reload_command' => '',
    ],
];
```

`path` 支持绝对路径和相对路径：

- 推荐写绝对路径，例如 `config_path() . '/cc/sw-mysql.php'`，清楚知道文件会写到哪里。
- 如果写相对路径，例如 `'sw-mysql.php'`，客户端会把它拼到 `config_root` 下面，默认也就是 `config/cc/sw-mysql.php`。

`listeners.php` 必须存在并返回数组。监听项不要写到 `config.php` 里，`config.php` 只负责服务端地址、账号密码、轮询、Redis、日志等运行参数。

`reload_command` 是可选项，默认为空。只有远端配置实际更新或本地文件被修复时，客户端才会执行对应监听项里的 `reload_command`；配置未变化时不会执行。命令执行成功或失败都会写入当前 `log_channel`。

注意：客户端同步配置文件后，Webman 运行中已经加载到内存的 `config('cc.xxx')` 不会自动变化。如果业务希望配置发布后立即生效，需要给对应监听项配置 `reload_command`，或者在发布后手动 reload 项目。

如果确实希望某个配置更新后自动 reload，可以这样写：

```php
[
    'group' => 'DEFAULT_GROUP',
    'data_id' => 'app.php',
    'format' => 'php',
    'path' => config_path() . '/cc/app.php',
    'reload_command' => 'php ' . base_path() . '/start.php reload',
]
```

修改 `listeners.php` 后执行 `php start.php reload`，插件进程会重新读取监听列表；如果你从早期版本升级过来，建议升级后先执行一次 `php start.php restart`。

### PHP 配置和 PHP 代码文件

`format => 'php'` 专门用于普通配置文件，内容必须是单个静态 `return` 表达式，例如：

```php
<?php

return [
    'debug' => false,
];
```

如果需要同步 PHP 类文件、函数文件等代码文件，使用 `php_code`。客户端只做 PHP 语法校验，不要求 `return`：

```php
[
    'group' => 'juhe',
    'data_id' => 'HallRuleEvaluator.php',
    'format' => 'php_code',
    'path' => app_path() . '/common/library/HallRuleEvaluator.php',
    'reload_command' => '',
],
```

## 业务代码读取配置

客户端默认把远端配置文件写入业务项目的：

```text
config/cc/
```

所以在 Webman 里读取时，统一使用 `cc` 作为一级配置名。

例如 `listeners.php` 中这样配置：

```php
[
    'group' => 'DEFAULT_GROUP',
    'data_id' => 'redis.php',
    'format' => 'php',
    'path' => config_path() . '/cc/redis.php',
]
```

同步后会生成：

```text
config/cc/redis.php
```

业务代码里这样读取：

```php
$redis = config('cc.redis', []);
```

如果 `path` 带目录：

```php
[
    'group' => 'DEFAULT_GROUP',
    'data_id' => 'mysql.php',
    'format' => 'php',
    'path' => config_path() . '/cc/database/mysql.php',
]
```

同步后会生成：

```text
config/cc/database/mysql.php
```

业务代码里这样读取：

```php
$mysql = config('cc.database.mysql', []);
```

也就是说，`config()` 的 key 由本地 `path` 在 `config/` 目录下的相对位置决定，不是由远端 `data_id` 决定。

如果你把配置写到 `config/cc` 之外，也可以，但 Webman 的 `config()` 读取 key 会跟着路径变化。例如：

```php
[
    'group' => 'DEFAULT_GROUP',
    'data_id' => 'custom.php',
    'format' => 'php',
    'path' => config_path() . '/custom/config.php',
]
```

同步后业务代码读取：

```php
$custom = config('custom.config', []);
```

## 客户端发布配置

如果业务项目需要主动新增或修改配置，可以使用客户端账号调用发布接口。发布成功后，服务端会生成历史版本，并触发配置变更通知。

```php
use Kylin987\WebmanConfigCenter\ConfigApiClient;
use Kylin987\WebmanConfigCenter\ConfigLoader;

$client = new ConfigApiClient(ConfigLoader::load());

$result = $client->publish(
    namespace: 'public',
    group: 'DEFAULT_GROUP',
    dataId: 'app.php',
    format: 'php',
    content: "<?php\nreturn ['debug' => false];\n",
    expectedRevision: null,
    note: 'publish from business project'
);

echo $result->revision;
```

`expectedRevision` 是可选的乐观锁：

- 不传：直接新增或覆盖发布。
- 传当前版本号：如果服务端版本已经变化，会发布失败，避免覆盖别人刚提交的内容。

## 命令

一般情况下不需要手动启动监听进程，插件会跟随 Webman 自动启动 `config-center` 进程。

如果需要手动调试，可以使用下面的命令。

启动前同步一次：

```bash
php vendor/bin/config-center-sync
```

手动启动定时轮询：

```bash
php vendor/bin/config-center-poll
```

Redis 是配置可选项。自动进程已经支持 Redis 订阅；下面这个命令只建议用于手动调试 Redis 事件监听：

```bash
php vendor/bin/config-center-listen
```

## 运行建议

- 默认使用插件自动注册的 `config-center` 进程，不需要额外 sidecar。
- 默认轮询会带随机抖动，多个 Pod 不会固定在同一秒请求服务端。
- `config-center-sync` 可以用于手动调试或启动前同步一次。
- 如果需要更实时的发布通知，开启 `redis.enable` 即可，自动进程会同时订阅 Redis。
- 配置中心不可用时，客户端保留本地旧文件，下一次同步成功后再更新。
- 配置中心不可用时，自动轮询进程、`config-center-poll` 和 `config-center-listen` 都不会连续刷屏；错误会写入 Webman 日志，默认 channel 为 `default`，同类错误默认 300 秒最多写一次。
- 如果希望启动前同步失败时阻断启动，可以配置 `fail_on_error=true`；默认不阻断，适合配置文件已经随项目发布或已经落地到本地的场景。
- 如果服务端版本号未变化，但本地配置文件被误删或内容被手动改坏，客户端会按服务端内容自动修复本地文件，并返回 `repaired` 状态。
