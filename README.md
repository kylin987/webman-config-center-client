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

常用环境变量：

```bash
CONFIG_CENTER_ENDPOINT=http://config-center.example.com/
CONFIG_CENTER_USERNAME=your-client-username
CONFIG_CENTER_PASSWORD=your-client-password
CONFIG_CENTER_CONFIG_ROOT=/app/config/cc
CONFIG_CENTER_STATE_DIR=/app/runtime/config-center
CONFIG_CENTER_POLL_INTERVAL=60
CONFIG_CENTER_POLL_JITTER_SECONDS=30
CONFIG_CENTER_APPLY_SECRET=replace-with-random-secret
CONFIG_CENTER_LOG_CHANNEL=default
CONFIG_CENTER_LOG_THROTTLE_SECONDS=300
```

轮询默认带随机抖动，避免几十个客户端集中在同一秒请求服务端：

- `CONFIG_CENTER_POLL_INTERVAL=60` 表示基础轮询间隔是 60 秒。
- `CONFIG_CENTER_POLL_JITTER_SECONDS=30` 表示每次实际轮询会随机落在 30~90 秒之间。
- 如果不配置 `CONFIG_CENTER_POLL_JITTER_SECONDS`，默认取 `poll_interval` 的一半，最多 30 秒。
- 自动进程启动后的第一次同步也会随机延迟 0~jitter 秒，避免 Pod 同时启动时一起请求。

如果需要 Redis Pub/Sub 实时通知，再额外配置：

```bash
CONFIG_CENTER_REDIS_URL=tcp://redis.example.com:6379
```

配置 `CONFIG_CENTER_REDIS_URL` 后，自动进程会同时做两件事：

- 按 `poll_interval` 定时轮询，作为兜底。
- 订阅 Redis Pub/Sub，服务端发布配置后立即收到通知并同步。

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
        'reload_command' => 'php start.php reload',
    ],
];
```

`path` 支持绝对路径和相对路径：

- 推荐写绝对路径，例如 `config_path() . '/cc/sw-mysql.php'`，清楚知道文件会写到哪里。
- 如果写相对路径，例如 `'sw-mysql.php'`，客户端会把它拼到 `config_root` 下面，默认也就是 `config/cc/sw-mysql.php`。

`listeners.php` 必须存在并返回数组。监听项不要写到 `config.php` 里，`config.php` 只负责服务端地址、账号密码、轮询、Redis、日志等运行参数。

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

## Webman 内应用更新

如果项目需要配置更新后执行 reload，可以在业务项目独立 Webman process 的定时器里调用：

```php
$config = Kylin987\WebmanConfigCenter\ConfigLoader::load();
(new Kylin987\WebmanConfigCenter\ApplyAdapter($config))->consume();
```

`ApplyAdapter` 只接受共享状态目录中带 HMAC 的请求，并且只会执行当前业务项目白名单里声明的 `reload_command`。

## 运行建议

- 默认使用插件自动注册的 `config-center` 进程，不需要额外 sidecar。
- 默认轮询会带随机抖动，多个 Pod 不会固定在同一秒请求服务端。
- `config-center-sync` 可以用于手动调试或启动前同步一次。
- 如果需要更实时的发布通知，配置 `CONFIG_CENTER_REDIS_URL` 即可，自动进程会同时订阅 Redis。
- 配置中心不可用时，客户端保留本地旧文件，下一次同步成功后再更新。
- 配置中心不可用时，自动轮询进程、`config-center-poll` 和 `config-center-listen` 都不会连续刷屏；错误会写入 Webman 日志，默认 channel 为 `default`，同类错误默认 300 秒最多写一次。
- 如果希望启动前同步失败时阻断启动，可以配置 `CONFIG_CENTER_FAIL_ON_ERROR=1`；默认不阻断，适合配置文件已经随项目发布或已经落地到本地的场景。
- 如果服务端版本号未变化，但本地配置文件被误删或内容被手动改坏，客户端会按服务端内容自动修复本地文件，并返回 `repaired` 状态。
