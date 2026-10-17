<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Support;

/** Time source, injected so retries and polling are testable. */
interface Clock
{
    public function now(): int;
}
