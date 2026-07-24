<?php

if (!config('plugin.kylin987.config-center.app.enable', true)) {
    return [];
}

return [
    'config-center-poll' => [
        'handler' => \Kylin987\WebmanConfigCenter\Process\ConfigCenterPollProcess::class,
        'count' => 1,
        'reloadable' => false,
    ],
];
