<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Api;

use BladeCP\CpanelMigrator\Support\Redactor;

/**
 * Customer API failure mapped to a stable kind, a retry decision and an
 * interface message key. The message never contains credentials or URLs.
 */
final class ApiError extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const NETWORK = 'network';
    public const AUTH = 'auth';
    public const NOT_PARTNER = 'not_partner';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const VALIDATION = 'validation';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER = 'server';
    public const INVALID_RESPONSE = 'invalid_response';

    /** API codes with a dedicated interface message (lower case). */
    public const KNOWN_CODES = [
        'not_a_partner', 'insufficient_scope', 'environment_mismatch', 'commerce_restricted',
        'plan_not_found', 'location_not_available_for_plan', 'customer_identity_resolution_failed',
        'migration_request_conflict', 'migration_request_not_found', 'migration_upload_not_allowed',
        'migration_backup_storage_unavailable', 'migration_service_required', 'migration_service_conflict',
        'migration_payment_required', 'migration_backup_required', 'migration_backup_size_invalid',
        'migration_upload_not_in_progress', 'migration_confirm_upload_not_allowed',
        'migration_approval_not_allowed', 'migration_cancel_not_allowed', 'migration_domain_unavailable',
        'migration_discovery_review_unavailable', 'migration_discovery_choice_conflict',
        'migration_discovery_review_pending', 'migration_commercial_transition_invalid',
        'migration_retry_unavailable', 'migration_retry_dispatch_pending', 'migration_backup_unavailable',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly int $httpStatus = 0,
        public readonly ?string $apiCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        string $message = '',
        public readonly string $path = ''
    ) {
        parent::__construct($message !== '' ? $message : 'BladeCP API error: ' . $kind);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 0, null, false, null, 'The BladeCP API key is not configured.');
    }

    public static function fromResponse(HttpResponse $response, string $path): self
    {
        $path = (string) strtok($path, '?');
        if ($response->status === 0) {
            return new self(self::NETWORK, 0, null, true, null, 'The BladeCP API could not be reached.', $path);
        }
        $decoded = json_decode($response->body, true);
        $rawCode = is_array($decoded) ? ($decoded['code'] ?? ($decoded['error']['code'] ?? null)) : null;
        $rawMessage = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : '';
        $code = null;
        if (is_string($rawCode) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $rawCode) === 1) {
            $code = $rawCode;
        } elseif (is_string($rawCode) && $rawMessage === '') {
            // Some storage errors carry a sentence in "code"; keep it as message.
            $rawMessage = $rawCode;
        }
        $lower = $code === null ? '' : strtolower($code);
        $status = $response->status;
        [$kind, $retryable] = match (true) {
            $status === 401 => [self::AUTH, false],
            $status === 403 && $lower === 'not_a_partner' => [self::NOT_PARTNER, false],
            $status === 403 => [self::FORBIDDEN, false],
            $status === 404 => [self::NOT_FOUND, false],
            $status === 408 => [self::NETWORK, true],
            // A 409 without a code is an in-flight duplicate; it settles by itself.
            $status === 409 => [self::CONFLICT, $code === null],
            $status === 429 => [self::RATE_LIMITED, true],
            $status >= 500 => [self::SERVER, true],
            $status >= 400 => [self::VALIDATION, false],
            default => [self::INVALID_RESPONSE, true],
        };
        $retryAfter = null;
        if ($kind === self::RATE_LIMITED) {
            $header = $response->header('retry-after');
            $retryAfter = $header !== null && ctype_digit($header) ? max(1, min(3600, (int) $header)) : 60;
        }
        $message = 'BladeCP API returned HTTP ' . $status . ($code !== null ? ' (' . $code . ')' : '')
            . ($rawMessage !== '' ? ': ' . Redactor::string(substr($rawMessage, 0, 300)) : '');
        return new self($kind, $status, $code, $retryable, $retryAfter, $message, $path);
    }

    public static function invalidResponse(string $path): self
    {
        return new self(self::INVALID_RESPONSE, 200, null, true, null, 'The BladeCP API returned an unexpected response.', (string) strtok($path, '?'));
    }

    /** Interface message key: a code specific message when known, otherwise by kind. */
    public function messageKey(): string
    {
        $lower = $this->apiCode === null ? '' : strtolower($this->apiCode);
        if ($lower !== '' && in_array($lower, self::KNOWN_CODES, true)) {
            return 'error.code.' . $lower;
        }
        return 'error.api.' . $this->kind;
    }

    public function is(string $code): bool
    {
        return $this->apiCode !== null && strcasecmp($this->apiCode, $code) === 0;
    }
}
