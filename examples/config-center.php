<?php

return [
    'endpoint' => getenv('CONFIG_CENTER_ENDPOINT') ?: 'https://config.example.com/',
    'username' => getenv('CONFIG_CENTER_USERNAME') ?: '',
    'password' => getenv('CONFIG_CENTER_PASSWORD') ?: '',
    'namespace' => 'public',
    'config_root' => getenv('CONFIG_CENTER_CONFIG_ROOT') ?: base_path() . '/config/nacos',
    'state_dir' => getenv('CONFIG_CENTER_STATE_DIR') ?: runtime_path() . '/config-center',
    'connect_timeout' => 3,
    'timeout' => 8,
    'redis_url' => getenv('CONFIG_CENTER_REDIS_URL') ?: 'tls://redis.example.com:6379',
    'event_channel' => 'config-center:changed',
    'poll_interval' => 60,
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
