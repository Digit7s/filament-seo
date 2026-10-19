<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Support;

use InvalidArgumentException;

/** Normalizes static request paths without decoding or matching route parameters. */
final class SeoPagePath
{
    public static function normalize(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) === 1) {
            throw new InvalidArgumentException('SEO page paths must be relative URL paths, not absolute URLs.');
        }

        // Query strings and fragments do not form part of a route identity.
        $path = preg_split('/[?#]/', $path, 2)[0] ?? '';
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        $path = trim($path, '/');

        return $path === '' ? '/' : '/'.$path;
    }
}
