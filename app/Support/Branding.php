<?php

declare(strict_types=1);

namespace App\Support;

final class Branding
{
    /** The product name, shown when no company name is set. */
    public const PRODUCT = 'RadiusOne';

    /**
     * The name the portal goes by (Settings → Branding → Company name): sidebar, sign-in page, browser
     * tab, emails, alerts, PDFs and authenticator apps. The product name when none is set.
     */
    public static function name(): string
    {
        $name = trim(Settings::value('company_name', ''));
        return $name !== '' ? $name : self::PRODUCT;
    }

    /** Saves the company name; empty goes back to the product name. */
    public static function saveName(string $name): void
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if (mb_strlen($name) > 60 || preg_match('/[\x00-\x1f\x7f<>]/', $name)) {
            throw new \InvalidArgumentException('Enter a company name of up to 60 characters, without < or >.');
        }
        Settings::save('company_name', $name);
    }

    /** Validate a browser-loaded logo URL; never fetch remote URLs on the server. */
    public static function httpsUrl(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)
            || !$parts || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new \InvalidArgumentException('Enter a complete HTTPS image URL without a username or password.');
        }
        return $value;
    }

    public static function source(string $value): ?string
    {
        if (str_starts_with($value, '/uploads/branding/') && self::file(substr($value, strlen('/uploads/branding/')))) {
            return $value;
        }
        try {
            return self::httpsUrl($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public static function directory(): string
    {
        return rtrim((string) App::env('BRANDING_STORAGE', Paths::data('branding')), '/');
    }
    public static function file(string $name): ?string
    {
        if (!preg_match('/^logo-[a-f0-9]+\.(png|jpg|webp)$/D', $name)) {
            return null;
        }
        foreach ([self::directory(), App::root('public/uploads/branding')] as $dir) {
            $file = $dir . '/' . $name;
            if (is_file($file) && !is_link($file)) {
                return $file;
            }
        }
        return null;
    }
}
