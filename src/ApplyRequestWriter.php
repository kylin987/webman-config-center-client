<?php

namespace Yhs\WebmanConfigCenter;

final class ApplyRequestWriter
{
    public function __construct(private readonly string $directory, private readonly string $secret)
    {
    }

    public function write(string $key, int $revision): void
    {
        if ($this->secret === '') return;
        $data = ['key' => $key, 'revision' => $revision];
        $data['signature'] = hash_hmac('sha256', $key . ':' . $revision, $this->secret);
        (new AtomicFileWriter())->write($this->directory . '/apply/' . hash('sha256', $key) . '.json', json_encode($data, JSON_THROW_ON_ERROR));
    }
}

