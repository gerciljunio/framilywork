<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
        public readonly array $headers = []
    ) {}

    public function send(): void
    {
        json_response($this->data, $this->status, $this->headers);
    }
}