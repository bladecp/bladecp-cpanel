<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Support;

/**
 * Removes credentials, signed URLs and personal data before anything is
 * logged or stored as error detail. Every log line and every error detail
 * shown in the interface goes through this class.
 */
final class Redactor
{
    public const MASK = '[redacted]';
    private const MAX_STRING = 2000;
    private const MAX_DEPTH = 6;

    /** Context keys whose values are never written, whatever their content. */
    private const SENSITIVE_KEY = '/(api[_-]?key|authorization|bearer|token|secret|password|passwd|signature|credential|cookie|session|url|uri|email|owner)/i';

    public static function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::MASK;
        }
        if ($value instanceof \Throwable) {
            return ['type' => (new \ReflectionClass($value))->getShortName(), 'message' => self::string($value->getMessage())];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                    $out[$key] = self::MASK;
                    continue;
                }
                $out[$key] = self::value($item, $depth + 1);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if (is_object($value)) {
            return '[' . (new \ReflectionClass($value))->getShortName() . ']';
        }
        return $value;
    }

    public static function string(string $value): string
    {
        if (strlen($value) > self::MAX_STRING) {
            $value = substr($value, 0, self::MAX_STRING) . '...';
        }
        $patterns = [
            // BladeCP API keys of any environment.
            '/\bbcp_[a-z]{2,10}_[A-Za-z0-9]{8,}\b/' => self::MASK,
            // Authorization headers.
            '/(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i' => '$1' . self::MASK,
            // Any URL query string may carry a signature (pre-signed upload URLs).
            '#\b(https?://[^\s?"\'<>]+)\?[^\s"\'<>]*#i' => '$1?' . self::MASK,
            // Bare signed query fragments.
            '/(X-Amz-[A-Za-z-]+=)[^&\s"\']+/i' => '$1' . self::MASK,
            // key=value or key: value pairs that look like secrets.
            '/\b(api[_-]?key|token|secret|password|passwd)\b(\s*[:=]\s*)("?)[^\s,;"]+/i' => '$1$2$3' . self::MASK,
            // E-mail addresses (personal data).
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => self::MASK,
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? self::MASK;
        }
        // Control characters would break one-line log records.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }
}
