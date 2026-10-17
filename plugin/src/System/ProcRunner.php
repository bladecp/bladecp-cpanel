<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\System;

/**
 * proc_open based runner. The command runs without a shell, with a minimal
 * environment and, when available, in its own session so an abort stops the
 * whole process group (pkgacct starts helper processes).
 */
final class ProcRunner implements CommandRunner
{
    private const TICK_INTERVAL = 5;
    private const ENV = [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'LANG' => 'C',
        'LC_ALL' => 'C',
        'HOME' => '/root',
    ];

    public function run(array $argv, int $timeoutSeconds, ?callable $tick = null, int $maxOutputBytes = 262144, ?string $stdin = null): CommandResult
    {
        if ($argv === [] || !is_executable($argv[0])) {
            return new CommandResult(127, '', 'command not available');
        }
        $setsid = '/usr/bin/setsid';
        $useSession = is_executable($setsid) && function_exists('posix_kill');
        $command = $useSession ? array_merge([$setsid], $argv) : $argv;
        $process = @proc_open($command, [
            0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, self::ENV);
        if (!is_resource($process)) {
            return new CommandResult(126, '', 'command could not be started');
        }
        if ($stdin !== null) {
            // Small JSON requests (whmapi1 --input=json): written at once, then closed.
            @fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $truncated = false;
        $started = time();
        $lastTick = $started;
        $aborted = false;
        $timedOut = false;
        $exitCode = -1;
        while (true) {
            $read = [];
            foreach ([1, 2] as $index) {
                if (is_resource($pipes[$index]) && !feof($pipes[$index])) {
                    $read[] = $pipes[$index];
                }
            }
            if ($read !== []) {
                $write = null;
                $except = null;
                if (@stream_select($read, $write, $except, 1) !== false) {
                    foreach ($read as $stream) {
                        $chunk = (string) fread($stream, 65536);
                        if ($stream === $pipes[1]) {
                            $stdout = self::append($stdout, $chunk, $maxOutputBytes, $truncated);
                        } else {
                            $stderr = self::append($stderr, $chunk, 65536, $truncated);
                        }
                    }
                }
            } else {
                usleep(200000);
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                // Drain whatever is left in the pipes.
                foreach ([1, 2] as $index) {
                    while (is_resource($pipes[$index]) && ($chunk = fread($pipes[$index], 65536)) !== false && $chunk !== '') {
                        if ($index === 1) {
                            $stdout = self::append($stdout, $chunk, $maxOutputBytes, $truncated);
                        } else {
                            $stderr = self::append($stderr, $chunk, 65536, $truncated);
                        }
                    }
                }
                break;
            }
            $now = time();
            if ($timeoutSeconds > 0 && $now - $started > $timeoutSeconds) {
                $timedOut = true;
            } elseif ($tick !== null && $now - $lastTick >= self::TICK_INTERVAL) {
                $lastTick = $now;
                if ($tick() === false) {
                    $aborted = true;
                }
            }
            if ($timedOut || $aborted) {
                $this->stop($process, (int) $status['pid'], $useSession);
                break;
            }
        }
        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
        $closed = proc_close($process);
        if ($exitCode === -1) {
            $exitCode = $closed;
        }
        return new CommandResult($exitCode, $stdout, $stderr, $aborted, $timedOut, $truncated);
    }

    /** @param resource $process */
    private function stop($process, int $pid, bool $group): void
    {
        $signal = static function (int $sig) use ($process, $pid, $group): void {
            if ($group && $pid > 0 && function_exists('posix_kill')) {
                @posix_kill(-$pid, $sig);
            }
            @proc_terminate($process, $sig);
        };
        $signal(15);
        for ($i = 0; $i < 50; $i++) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return;
            }
            usleep(200000);
        }
        $signal(9);
    }

    private static function append(string $buffer, string $chunk, int $max, bool &$truncated): string
    {
        $buffer .= $chunk;
        if (strlen($buffer) > $max) {
            $truncated = true;
            // Keep the tail: the end of a command output carries the error.
            $buffer = substr($buffer, -$max);
        }
        return $buffer;
    }
}
