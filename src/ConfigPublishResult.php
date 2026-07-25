<?php

namespace Kylin987\WebmanConfigCenter;

final class ConfigPublishResult
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $group,
        public readonly string $dataId,
        public readonly string $format,
        public readonly int $revision,
        public readonly string $md5,
    ) {
    }

    public function key(): string
    {
        return $this->namespace . '/' . $this->group . '/' . $this->dataId;
    }

    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'group' => $this->group,
            'dataId' => $this->dataId,
            'format' => $this->format,
            'revision' => $this->revision,
            'md5' => $this->md5,
        ];
    }
}
