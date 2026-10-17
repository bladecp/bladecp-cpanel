<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Api;

/** Transport level result. A status of 0 means the request never got an HTTP answer. */
final class HttpResponse
{
    /** @param array<string,string> $headers lower-case header names */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly ?string $transportError = null
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
