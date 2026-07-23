<?php

return [
    'endpoint' => getenv('CONFIG_CENTER_ENDPOINT') ?: 'https://config.example.com/',
    'username' => getenv('CONFIG_CENTER_USERNAME') ?: '',
    'password' => getenv('CONFIG_CENTER_PASSWORD') ?: '',
    'namespace' => getenv('CONFIG_CENTER_NAMESPACE') ?: 'public',
    'config_root' => getenv('CONFIG_CENTER_CONFIG_ROOT') ?: base_path() . '/config/nacos',
    'state_dir' => getenv('CONFIG_CENTER_STATE_DIR') ?: runtime_path() . '/config-center',
    'connect_timeout' => 3,
    'timeout' => 8,
    // 可选：配置后可运行 config-center-listen 监听 Redis Pub/Sub，实现更实时的配置更新。
    // 不配置时，使用 config-center-poll 轮询即可。
    'redis_url' => getenv('CONFIG_CENTER_REDIS_URL') ?: '',
    'event_channel' => getenv('CONFIG_CENTER_EVENT_CHANNEL') ?: 'config-center:changed',
    'poll_interval' => (int) (getenv('CONFIG_CENTER_POLL_INTERVAL') ?: 60),
    'apply_secret' => getenv('CONFIG_CENTER_APPLY_SECRET') ?: '',
    'items' => [
        [
            'group' => 'DEFAULT_GROUP',
            'data_id' => 'app.php',
            'format' => 'php',
            'path' => 'app.php',
            'reload_command' => 'php start.php reload',
        ],
    ],
];
