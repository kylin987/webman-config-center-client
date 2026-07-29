<?php

return [
    // 配置中心服务端地址。建议保留最后的 /。
    'endpoint' => getenv('CONFIG_CENTER_ENDPOINT') ?: 'https://config.example.com/',

    // 客户端账号用户名。在服务端管理后台的“客户端账号”中创建，不是管理员账号。
    'username' => getenv('CONFIG_CENTER_USERNAME') ?: '',

    // 客户端账号密码。建议通过 .env 或 K8s Secret 注入，不要硬编码到项目仓库。
    'password' => getenv('CONFIG_CENTER_PASSWORD') ?: '',

    // 配置中心 namespace，默认 public。一般不用改。
    'namespace' => 'public',

    // 远端配置同步到本地的根目录。默认写入业务项目 config/cc，可通过 config('cc.xxx') 读取。
    'config_root' => base_path() . '/config/cc',

    // 客户端运行状态根目录，用于记录已同步版本和文件 md5。
    'state_dir' => runtime_path() . '/config-center',

    // 是否按主机名隔离 state_dir。多 Pod 共享同一工作目录时必须开启，避免只有一个 Pod 执行 reload_command。
    'state_dir_host_isolation' => true,

    // 连接配置中心服务端的超时时间，单位秒。
    'connect_timeout' => 3,

    // 读取配置中心响应的总超时时间，单位秒。
    'timeout' => 8,

    // 访问服务端时使用的 IP 协议。auto=系统默认，v4=强制 IPv4，v6=强制 IPv6。
    // 外网域名配合 IP 白名单时，如果不想走 IPv6，可以改成 v4。
    'ip_resolve' => 'auto',

    // Redis Pub/Sub 实时通知配置。enable=false 时只使用轮询。
    'redis' => [
        // 是否启用 Redis 订阅。启用后仍会保留轮询作为兜底。
        'enable' => false,

        // Redis 地址。
        'host' => '127.0.0.1',

        // Redis 端口。
        'port' => 6379,

        // Redis 密码。建议通过 .env 或 K8s Secret 注入。
        'password' => getenv('CONFIG_CENTER_REDIS_PASSWORD') ?: '',

        // Redis DB。
        'database' => 0,
    ],

    // Redis Pub/Sub 频道，需要和服务端 CONFIG_CENTER_EVENT_CHANNEL 保持一致。
    'event_channel' => 'config-center:changed',

    // 轮询间隔，单位秒。自动进程和手动 config-center-poll 命令都会使用。
    'poll_interval' => 60,

    // 轮询随机抖动秒数，避免多个客户端在同一秒集中请求；默认取 poll_interval 的一半，最多 30 秒。
    'poll_jitter_seconds' => 30,

    // Webman 日志 channel。默认 default。
    'log_channel' => 'default',

    // 同类错误日志限频时间，单位秒，避免配置中心异常时刷屏。
    'log_throttle_seconds' => 300,

    // 启动前同步失败时是否返回非 0。默认 false，表示保留本地旧配置并继续启动。
    'fail_on_error' => false,
];
