<?php

declare(strict_types=1);

namespace BladeCP\CpanelMigrator\Support;

/**
 * Translation catalogue. English is the base language: a key missing in the
 * active language falls back to English, then to the key itself, so a screen
 * never fails because of a missing translation.
 */
final class I18n
{
    public const LOCALES = ['en', 'pt-BR', 'es'];
    public const DEFAULT_LOCALE = 'en';

    /** @var array<string,array<string,string>> */
    private static array $cache = [];

    private string $locale;

    public function __construct(string $locale, private readonly string $directory = BLADECP_MIGRATOR_ROOT . '/lang')
    {
        $this->locale = in_array($locale, self::LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** Value for the HTML lang attribute. */
    public function htmlLang(): string
    {
        return $this->locale;
    }

    /** @param array<string,scalar|null> $params */
    public function t(string $key, array $params = []): string
    {
        $value = $this->catalogue($this->locale)[$key]
            ?? $this->catalogue(self::DEFAULT_LOCALE)[$key]
            ?? $key;
        if ($params === []) {
            return $value;
        }
        $tokens = [];
        foreach ($params as $name => $replacement) {
            if (!is_string($name) || ($replacement !== null && !is_scalar($replacement))) {
                continue;
            }
            $tokens['{' . $name . '}'] = $replacement === null ? '' : (string) $replacement;
        }
        return strtr($value, $tokens);
    }

    public function has(string $key): bool
    {
        return isset($this->catalogue(self::DEFAULT_LOCALE)[$key]);
    }

    /** @return array<string,string> */
    public function catalogue(string $locale): array
    {
        $key = $this->directory . '|' . $locale;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $file = $this->directory . '/' . $locale . '.php';
        $entries = [];
        if (in_array($locale, self::LOCALES, true) && is_file($file)) {
            try {
                $loaded = require $file;
                if (is_array($loaded)) {
                    foreach ($loaded as $name => $text) {
                        if (is_string($name) && is_string($text)) {
                            $entries[$name] = $text;
                        }
                    }
                }
            } catch (\Throwable) {
                $entries = [];
            }
        }
        return self::$cache[$key] = $entries;
    }

    /** Maps cPanel and browser style locale codes to a supported locale. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $code = strtolower(str_replace('_', '-', trim($value)));
        if ($code === '') {
            return null;
        }
        if ($code === 'pt-br' || $code === 'pt' || str_starts_with($code, 'pt-')) {
            return 'pt-BR';
        }
        if ($code === 'es' || str_starts_with($code, 'es-')) {
            return 'es';
        }
        if ($code === 'en' || str_starts_with($code, 'en-')) {
            return 'en';
        }
        return null;
    }
}
