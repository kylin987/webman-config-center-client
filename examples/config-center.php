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
    'config_root' => getenv('CONFIG_CENTER_CONFIG_ROOT') ?: base_path() . '/config/cc',

    // 客户端运行状态根目录，用于记录已同步版本和文件 md5。
    'state_dir' => getenv('CONFIG_CENTER_STATE_DIR') ?: runtime_path() . '/config-center',

    // 是否按主机名隔离 state_dir。多 Pod 共享同一工作目录时必须开启，避免只有一个 Pod 执行 reload_command。
    'state_dir_host_isolation' => filter_var(getenv('CONFIG_CENTER_STATE_DIR_HOST_ISOLATION') ?: true, FILTER_VALIDATE_BOOL),

    // 连接配置中心服务端的超时时间，单位秒。
    'connect_timeout' => 3,

    // 读取配置中心响应的总超时时间，单位秒。
    'timeout' => 8,

    // 可选：配置后，自动进程会同时订阅 Redis Pub/Sub，实现更实时的配置更新。
    // 不配置时，自动进程只使用轮询。
    'redis_url' => getenv('CONFIG_CENTER_REDIS_URL') ?: '',

    // Redis Pub/Sub 频道，需要和服务端 CONFIG_CENTER_EVENT_CHANNEL 保持一致。
    'event_channel' => 'config-center:changed',

    // 轮询间隔，单位秒。自动进程和手动 config-center-poll 命令都会使用。
    'poll_interval' => 60,

    // 轮询随机抖动秒数，避免多个客户端在同一秒集中请求；默认取 poll_interval 的一半，最多 30 秒。
    'poll_jitter_seconds' => getenv('CONFIG_CENTER_POLL_JITTER_SECONDS') === false ? null : (int) getenv('CONFIG_CENTER_POLL_JITTER_SECONDS'),

    // Webman 日志 channel。默认 default。
    'log_channel' => getenv('CONFIG_CENTER_LOG_CHANNEL') ?: 'default',

    // 同类错误日志限频时间，单位秒，避免配置中心异常时刷屏。
    'log_throttle_seconds' => (int) (getenv('CONFIG_CENTER_LOG_THROTTLE_SECONDS') ?: 300),

    // 启动前同步失败时是否返回非 0。默认 false，表示保留本地旧配置并继续启动。
    'fail_on_error' => filter_var(getenv('CONFIG_CENTER_FAIL_ON_ERROR') ?: false, FILTER_VALIDATE_BOOL),
];
