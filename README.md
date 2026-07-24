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
config/plugin/kylin987/config-center/config.php
config/plugin/kylin987/config-center/listeners.php
```

## 配置

打开 `config/plugin/kylin987/config-center/config.php`，根据项目实际情况修改服务端地址、客户端账号密码、轮询和日志参数。

常用环境变量：

```bash
CONFIG_CENTER_ENDPOINT=http://config-center.example.com/
CONFIG_CENTER_USERNAME=your-client-username
CONFIG_CENTER_PASSWORD=your-client-password
CONFIG_CENTER_CONFIG_ROOT=/app/config/cc
CONFIG_CENTER_STATE_DIR=/app/runtime/config-center
CONFIG_CENTER_APPLY_SECRET=replace-with-random-secret
CONFIG_CENTER_LOG_CHANNEL=default
CONFIG_CENTER_LOG_THROTTLE_SECONDS=300
```

如果需要 Redis Pub/Sub 实时通知，再额外配置：

```bash
CONFIG_CENTER_REDIS_URL=tcp://redis.example.com:6379
```

打开 `config/plugin/kylin987/config-center/listeners.php`，维护需要监听/同步的配置文件。

`listeners.php` 是白名单，只有声明过的配置才会被写入本地文件：

```php
<?php

return [
    [
        'group' => 'DEFAULT_GROUP',
        'data_id' => 'app.php',
        'format' => 'php',
        'path' => 'app.php',
        'reload_command' => 'php start.php reload',
    ],
];
```

旧版本把监听项写在 `config.php` 的 `items` 里仍然兼容；如果同目录存在 `listeners.php`，会优先使用 `listeners.php`。

## 命令

启动前同步一次：

```bash
php vendor/bin/config-center-sync
```

定时轮询配置中心，推荐默认使用：

```bash
php vendor/bin/config-center-poll
```

Redis 是配置可选项。如果希望发布后更快触发同步，配置 `CONFIG_CENTER_REDIS_URL` 后运行 Redis 发布事件监听，适合放在 sidecar 或独立进程中：

```bash
php vendor/bin/config-center-listen
```

## Webman 内应用更新

如果项目需要配置更新后执行 reload，可以在业务项目独立 Webman process 的定时器里调用：

```php
$config = Yhs\WebmanConfigCenter\ConfigLoader::load();
(new Yhs\WebmanConfigCenter\ApplyAdapter($config))->consume();
```

`ApplyAdapter` 只接受共享状态目录中带 HMAC 的请求，并且只会执行当前业务项目白名单里声明的 `reload_command`。

## 运行建议

- `config-center-sync` 用于 initContainer 或应用启动前同步。
- 默认只需要运行 `config-center-poll`，不依赖 Redis。
- 如果需要更实时的发布通知，配置 `CONFIG_CENTER_REDIS_URL` 并运行 `config-center-listen`。
- `config-center-listen` 和 `config-center-poll` 都建议运行在 sidecar 或独立进程中，不要放进业务 worker 阻塞执行。
- 配置中心不可用时，客户端保留本地旧文件，下一次同步成功后再更新。
- 配置中心不可用时，`config-center-poll` 和 `config-center-listen` 不会退出，也不会连续刷 STDERR；错误会写入 Webman 日志，默认 channel 为 `default`，同类错误默认 300 秒最多写一次。
- 如果希望启动前同步失败时阻断启动，可以配置 `CONFIG_CENTER_FAIL_ON_ERROR=1`；默认不阻断，适合配置文件已经随项目发布或已经落地到本地的场景。
- 如果服务端版本号未变化，但本地配置文件被误删或内容被手动改坏，客户端会按服务端内容自动修复本地文件，并返回 `repaired` 状态。
