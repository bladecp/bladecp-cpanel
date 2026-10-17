<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Web;

/**
 * Scheme, host and port of the panel the browser is talking to, for
 * absolute redirects. A CGI "Location" with only a path is an internal
 * redirect, so the browser must get an absolute URL. cpsrvd strips the port
 * from HTTP_HOST, so it comes back from SERVER_PORT unless it is the
 * scheme's default. WHM (2087) and cPanel (2083) are HTTPS ports.
 */
final class Origin
{
    private const HTTPS_PORTS = ['2087', '2083'];

    /** Origin such as "https://server.example.com:2087", or '' when the host is not usable. */
    public static function of(Request $request): string
    {
        $host = strtolower($request->host());
        if (preg_match('/^[a-z0-9.-]{1,253}(?::\d{1,5})?$/D', $host) !== 1 && preg_match('/^\[[0-9a-f:.]+\](?::\d{1,5})?$/D', $host) !== 1) {
            return '';
        }
        $port = (string) $request->header('SERVER_PORT');
        $https = strtolower((string) $request->header('HTTPS')) === 'on' || in_array($port, self::HTTPS_PORTS, true);
        foreach (self::HTTPS_PORTS as $known) {
            $https = $https || str_ends_with($host, ':' . $known);
        }
        if (preg_match('/:\d{1,5}$/D', $host) !== 1 && preg_match('/^\d{1,5}$/D', $port) === 1 && $port !== ($https ? '443' : '80')) {
            $host .= ':' . $port;
        }
        return ($https ? 'https://' : 'http://') . $host;
    }
}
