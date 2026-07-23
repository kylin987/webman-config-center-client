# kylin987/webman-config-center-client

面向 Webman 项目的轻量配置中心客户端。

它负责从 Config Center 服务端拉取配置，校验内容格式，然后写入业务项目本地配置目录。远端异常时会保留本地旧配置，避免因为配置中心短暂不可用导致业务 worker 退出。

## 安装

```bash
composer require kylin987/webman-config-center-client
```

安装后，Webman 会自动复制插件配置到：

```text
config/plugin/kylin987/config-center/
```

主要配置文件是：

```text
config/plugin/kylin987/config-center/config.php
```

## 配置

打开 `config/plugin/kylin987/config-center/config.php`，根据项目实际情况修改服务端地址、客户端账号密码、Redis 地址和监听项。

常用环境变量：

```bash
CONFIG_CENTER_ENDPOINT=http://config-center.example.com/
CONFIG_CENTER_USERNAME=your-client-username
CONFIG_CENTER_PASSWORD=your-client-password
CONFIG_CENTER_REDIS_URL=tcp://redis.example.com:6379
CONFIG_CENTER_CONFIG_ROOT=/app/config/nacos
CONFIG_CENTER_STATE_DIR=/app/runtime/config-center
CONFIG_CENTER_APPLY_SECRET=replace-with-random-secret
```

`items` 是白名单，只有声明过的配置才会被写入本地文件：

```php
[
    'group' => 'DEFAULT_GROUP',
    'data_id' => 'app.php',
    'format' => 'php',
    'path' => 'app.php',
    'reload_command' => 'php start.php reload',
]
```

## 命令

启动前同步一次：

```bash
php vendor/bin/config-center-sync
```

监听 Redis 发布事件，适合放在 sidecar 或独立进程中：

```bash
php vendor/bin/config-center-listen
```

定时轮询补偿，避免 Redis Pub/Sub 短暂断线后漏更新：

```bash
php vendor/bin/config-center-poll
```

## Webman 内应用更新

如果项目需要配置更新后执行 reload，可以在业务项目独立 Webman process 的定时器里调用：

```php
Yhs\WebmanConfigCenter\ApplyAdapter::consume(config('plugin.kylin987.config-center.config'));
```

`ApplyAdapter` 只接受共享状态目录中带 HMAC 的请求，并且只会执行当前业务项目白名单里声明的 `reload_command`。

## 运行建议

- `config-center-sync` 用于 initContainer 或应用启动前同步。
- `config-center-listen` 和 `config-center-poll` 建议运行在 sidecar 或独立进程中，不要放进业务 worker 阻塞执行。
- 配置中心不可用时，客户端保留本地旧文件，下一次同步成功后再更新。
