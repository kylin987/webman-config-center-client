<?php

namespace Yhs\WebmanConfigCenter;

final class ConfigItem
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $group,
        public readonly string $dataId,
        public readonly string $format,
        public readonly string $content,
        public readonly int $revision,
        public readonly string $md5,
    ) {
    }

    public function key(): string
    {
        return $this->namespace . '/' . $this->group . '/' . $this->dataId;
    }
}

