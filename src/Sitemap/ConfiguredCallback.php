<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use InvalidArgumentException;

/** Resolves trusted config callbacks, including config-cache-safe invokable class strings. */
final class ConfiguredCallback
{
    public static function resolve(mixed $callback, string $setting): ?callable
    {
        if ($callback === null) {
            return null;
        }

        if (is_string($callback) && class_exists($callback)) {
            $instance = app($callback);
            if (is_callable($instance)) {
                return $instance;
            }
        }

        if (is_string($callback) && str_contains($callback, '@')) {
            [$class, $method] = explode('@', $callback, 2);
            if ($class !== '' && $method !== '' && class_exists($class)) {
                $instance = app($class);
                if (is_callable([$instance, $method])) {
                    return [$instance, $method];
                }
            }
        }

        if (is_callable($callback)) {
            return $callback;
        }

        throw new InvalidArgumentException("The sitemap [{$setting}] setting must be callable, a callable class string, or null.");
    }
}
