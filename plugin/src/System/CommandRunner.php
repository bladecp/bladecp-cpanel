<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

/** Runs a command without a shell. */
interface CommandRunner
{
    /**
     * @param list<string> $argv
     * @param (callable():bool)|null $tick called about every few seconds; false aborts
     * @param string|null $stdin written to the standard input (null: /dev/null)
     */
    public function run(array $argv, int $timeoutSeconds, ?callable $tick = null, int $maxOutputBytes = 262144, ?string $stdin = null): CommandResult;
}
