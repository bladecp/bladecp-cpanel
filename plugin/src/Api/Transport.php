<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Api;

/**
 * HTTP boundary. The production implementation uses cURL; tests use a fake.
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse;

    /**
     * Uploads bytes [offset, offset + length) of a file with a single PUT,
     * streaming from disk. $tick receives the bytes sent so far and returns
     * false to abort the transfer.
     *
     * @param callable(int):bool $tick
     */
    public function putFile(string $url, string $path, int $offset, int $length, callable $tick): HttpResponse;
}
