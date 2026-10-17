<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Api;

/**
 * cURL transport. Request and response bodies are never logged here, and the
 * cURL error text is not propagated because it can include environment data.
 */
final class CurlTransport implements Transport
{
    private const CONNECT_TIMEOUT = 10;
    /** A transfer below 1 KiB/s for 60 seconds is considered stalled. */
    private const LOW_SPEED_LIMIT = 1024;
    private const LOW_SPEED_TIME = 60;

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            return new HttpResponse(0, '', [], 'curl_init_failed');
        }
        $responseHeaders = [];
        curl_setopt_array($handle, $this->baseOptions($url, $responseHeaders) + [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, max(1, $timeoutSeconds)),
            CURLOPT_HTTPHEADER => self::headerLines($headers),
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);
        if ($raw === false || $errno !== 0) {
            return new HttpResponse(0, '', [], 'curl_error_' . $errno);
        }
        return new HttpResponse($status, (string) $raw, $responseHeaders);
    }

    public function putFile(string $url, string $path, int $offset, int $length, callable $tick): HttpResponse
    {
        try {
            $stream = new PartStream($path, $offset, $length);
        } catch (\Throwable) {
            return new HttpResponse(0, '', [], 'file_read_error');
        }
        $handle = curl_init();
        if ($handle === false) {
            $stream->close();
            return new HttpResponse(0, '', [], 'curl_init_failed');
        }
        $responseHeaders = [];
        $readError = false;
        $aborted = false;
        curl_setopt_array($handle, $this->baseOptions($url, $responseHeaders) + [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILESIZE => $length,
            CURLOPT_READFUNCTION => static function ($curl, $unused, int $max) use ($stream, &$readError): string {
                try {
                    return $stream->read($max);
                } catch (\Throwable) {
                    $readError = true;
                    return '';
                }
            },
            CURLOPT_NOPROGRESS => false,
            // PHP 8.2 added XFERINFOFUNCTION; PROGRESSFUNCTION has the same shape on 8.1.
            (defined('CURLOPT_XFERINFOFUNCTION') ? constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION) => static function ($curl, $downTotal, $down, $upTotal, $up) use ($tick, &$aborted): int {
                if ($tick((int) $up) === false) {
                    $aborted = true;
                    return 1;
                }
                return 0;
            },
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
            // Disables "Expect: 100-continue"; the signed URL accepts the body directly.
            CURLOPT_HTTPHEADER => ['Expect:'],
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($handle);
        curl_close($handle);
        $stream->close();
        if ($aborted) {
            return new HttpResponse(0, '', [], 'aborted');
        }
        if ($readError) {
            return new HttpResponse(0, '', [], 'file_read_error');
        }
        if ($raw === false || $errno !== 0) {
            return new HttpResponse(0, '', [], 'curl_error_' . $errno);
        }
        return new HttpResponse($status, (string) $raw, $responseHeaders);
    }

    /**
     * @param array<string,string> $responseHeaders
     * @return array<int,mixed>
     */
    private function baseOptions(string $url, array &$responseHeaders): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $trimmed) === 1) {
                    // A new status line (after 100 Continue) starts a new header set.
                    $responseHeaders = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        return $options;
    }

    /**
     * @param array<string,string> $headers
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
        }
        return $lines;
    }
}
