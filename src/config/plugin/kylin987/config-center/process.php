<?php

if (!config('plugin.kylin987.config-center.app.enable', true)) {
    return [];
}

return [
    'config-center' => [
        'handler' => \Kylin987\WebmanConfigCenter\Process\ConfigCenterProcess::class,
        'count' => 1,
        'reloadable' => false,
    ],
];
