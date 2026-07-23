<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Yhs\WebmanConfigCenter\ConfigItem;
use Yhs\WebmanConfigCenter\ContentValidator;

$validator = new ContentValidator();
$content = "<?php return ['enable' => true];";
$validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'app.php', 'php', $content, 1, md5($content)), 'php');

try {
    $content = "<?php return getenv('SECRET');";
    $validator->validate(new ConfigItem('public', 'DEFAULT_GROUP', 'bad.php', 'php', $content, 1, md5($content)), 'php');
    throw new RuntimeException('动态 PHP 配置未被拒绝');
} catch (\InvalidArgumentException) {
}

echo "ok\n";
