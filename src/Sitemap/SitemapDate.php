<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/** Formats reliable sitemap modification dates without interpreting relative text. */
final class SitemapDate
{
    public static function format(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_W3C);
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', trim($value)) !== 1) {
            return null;
        }

        $value = trim($value);

        try {
            $timezone = new DateTimeZone((string) config('app.timezone', 'UTC'));

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
                $errors = DateTimeImmutable::getLastErrors();

                return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                    ? $date->format('Y-m-d')
                    : null;
            }

            $date = new DateTimeImmutable($value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)
                ? $date->format(DATE_W3C)
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
