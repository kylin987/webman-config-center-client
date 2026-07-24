<?php

// 配置中心缓存目录占位：默认必须开启，否则 config('cc.*') 读不到远端拉取的配置。
// 其他配置文件由 config-center 客户端同步生成，不建议提交到 git。
return [
    'enable' => true,
];
