<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Queue;

/** Outcome of an administrator action, rendered as a localized notice. */
final class ActionResult
{
    /** @param array<string,scalar|null> $params */
    public function __construct(
        public readonly bool $ok,
        public readonly string $messageKey,
        public readonly array $params = []
    ) {
    }

    /** @param array<string,scalar|null> $params */
    public static function ok(string $messageKey, array $params = []): self
    {
        return new self(true, $messageKey, $params);
    }

    /** @param array<string,scalar|null> $params */
    public static function fail(string $messageKey, array $params = []): self
    {
        return new self(false, $messageKey, $params);
    }
}
