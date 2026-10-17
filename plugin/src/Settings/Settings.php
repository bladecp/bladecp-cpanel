<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Settings;

use BladeCP\CpanelMigrator\Api\Client;

/**
 * Plugin settings. The API key and the CSRF secret are only read by code; the
 * interface never renders them.
 */
final class Settings
{
    public const COMMERCIAL_MODES = ['trial', 'payment_before', 'payment_after'];
    public const DOMAIN_MODES = ['account', 'review'];
    public const LANGUAGES = ['auto', 'en', 'pt-BR', 'es'];
    public const MAX_CONCURRENCY = 4;
    public const DEFAULT_WORK_DIR = '/home/bladecp-migrator';

    public const DEFAULTS = [
        'api_key' => '',
        'api_base_url' => '',
        'installation_id' => '',
        'csrf_secret' => '',
        'plan_id' => null,
        'location_id' => null,
        'commercial_mode' => 'trial',
        'trial_days' => 14,
        'payment_days' => 7,
        'native_product_id' => 1,
        'concurrency' => 1,
        'work_dir' => self::DEFAULT_WORK_DIR,
        'domain_mode' => 'account',
        'ui_language' => 'auto',
        'client_brand_name' => '',
        'client_support_url' => '',
        // "Not now" on the card that offers to pin the plugin to the WHM home page.
        'whm_pin_dismissed' => false,
        // Banner at the top of the cPanel home page of the accounts (on by default).
        'cpanel_banner' => true,
    ];

    /** @param array<string,mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @param array<string,mixed> $stored */
    public static function fromArray(array $stored): self
    {
        $values = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $stored)) {
                $values[$key] = $stored[$key];
            }
        }
        return new self($values);
    }

    public function apiKey(): string
    {
        return is_string($this->values['api_key']) ? $this->values['api_key'] : '';
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function apiBaseUrl(): string
    {
        $url = is_string($this->values['api_base_url']) ? trim($this->values['api_base_url']) : '';
        return $url === '' ? Client::DEFAULT_BASE_URL : $url;
    }

    public function usesCustomApiUrl(): bool
    {
        return $this->apiBaseUrl() !== Client::DEFAULT_BASE_URL;
    }

    public function customApiUrl(): string
    {
        return $this->usesCustomApiUrl() ? $this->apiBaseUrl() : '';
    }

    public function installationId(): string
    {
        return is_string($this->values['installation_id']) ? $this->values['installation_id'] : '';
    }

    public function csrfSecret(): string
    {
        return is_string($this->values['csrf_secret']) ? $this->values['csrf_secret'] : '';
    }

    public function planId(): ?int
    {
        return is_int($this->values['plan_id']) && $this->values['plan_id'] > 0 ? $this->values['plan_id'] : null;
    }

    public function locationId(): ?int
    {
        return is_int($this->values['location_id']) && $this->values['location_id'] > 0 ? $this->values['location_id'] : null;
    }

    public function commercialMode(): string
    {
        return in_array($this->values['commercial_mode'], self::COMMERCIAL_MODES, true) ? $this->values['commercial_mode'] : 'trial';
    }

    public function trialDays(): int
    {
        return self::days($this->values['trial_days'], 14);
    }

    public function paymentDays(): int
    {
        return self::days($this->values['payment_days'], 7);
    }

    public function nativeProductId(): int
    {
        $value = $this->values['native_product_id'];
        return is_int($value) && $value > 0 ? $value : 1;
    }

    public function concurrency(): int
    {
        $value = $this->values['concurrency'];
        return is_int($value) ? max(1, min(self::MAX_CONCURRENCY, $value)) : 1;
    }

    public function workDir(): string
    {
        $value = $this->values['work_dir'];
        return is_string($value) && $value !== '' ? $value : self::DEFAULT_WORK_DIR;
    }

    public function domainMode(): string
    {
        return in_array($this->values['domain_mode'], self::DOMAIN_MODES, true) ? $this->values['domain_mode'] : 'account';
    }

    public function uiLanguage(): string
    {
        return in_array($this->values['ui_language'], self::LANGUAGES, true) ? $this->values['ui_language'] : 'auto';
    }

    /** Name the cPanel app shows instead of the platform brand; '' when not set. */
    public function clientBrandName(): string
    {
        $value = $this->values['client_brand_name'];
        return is_string($value) ? trim($value) : '';
    }

    /** Support link (https:// or mailto:) the cPanel app offers; '' when not set. */
    public function clientSupportUrl(): string
    {
        $value = $this->values['client_support_url'];
        return is_string($value) ? trim($value) : '';
    }

    /** The administrator answered "Not now" to the WHM home page card. */
    public function whmPinDismissed(): bool
    {
        return $this->values['whm_pin_dismissed'] === true;
    }

    /** The cPanel home page banner is on (anything but an explicit false). */
    public function cpanelBanner(): bool
    {
        return $this->values['cpanel_banner'] !== false;
    }

    /** Defaults are complete enough to start migrations. */
    public function readyToMigrate(): bool
    {
        return $this->hasApiKey() && $this->installationId() !== '' && $this->planId() !== null && $this->locationId() !== null;
    }

    /**
     * Commercial policy body for the API, from a mode and its days.
     *
     * @return array<string,mixed>
     */
    public static function commercialPolicy(string $mode, int $trialDays, int $paymentDays): array
    {
        return match ($mode) {
            'trial' => ['mode' => 'trial', 'trial_days' => $trialDays],
            'payment_after' => ['mode' => 'payment_after', 'payment_days' => $paymentDays],
            default => ['mode' => 'payment_before'],
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    private static function days(mixed $value, int $fallback): int
    {
        return is_int($value) && $value >= 1 && $value <= 365 ? $value : $fallback;
    }
}
