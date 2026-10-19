<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class SitemapCache
{
    public function remember(string $document, callable $callback): string
    {
        if (! config('filament-seo.sitemap.cache.enabled', false)) {
            return $callback();
        }

        $ttl = max(1, (int) config('filament-seo.sitemap.cache.ttl', 3600));
        $key = $this->key($document);
        $cached = Cache::remember($key, now()->addSeconds($ttl), $callback);
        $manifestKey = $this->key('manifest');
        $keys = Cache::get($manifestKey, []);
        if (is_array($keys) && ! in_array($key, $keys, true)) {
            $keys[] = $key;
            Cache::put($manifestKey, $keys, now()->addSeconds($ttl));
        }

        return $cached;
    }

    /** Clear this application's package sitemap documents from its configured cache store. */
    public function clear(): void
    {
        if (! config('filament-seo.sitemap.cache.enabled', false)) {
            return;
        }

        $manifestKey = $this->key('manifest');
        $keys = Cache::get($manifestKey, []);
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (is_string($key)) {
                    Cache::forget($key);
                }
            }
        }
        Cache::forget($manifestKey);
    }

    private function key(string $document): string
    {
        $scopeResolver = ConfiguredCallback::resolve(
            config('filament-seo.sitemap.cache.scope_resolver'),
            'cache.scope_resolver',
        );
        $scope = $scopeResolver === null ? null : $scopeResolver();
        if ($scope !== null && ! is_string($scope) && ! is_int($scope)) {
            throw new InvalidArgumentException('The sitemap cache scope resolver must return a string, integer, or null.');
        }

        $fingerprint = hash('sha256', implode('|', [
            (string) config('app.name'),
            (string) config('app.url'),
            json_encode($this->normalize(config('filament-seo.sitemap', [])), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            hash('sha256', (string) $scope),
        ]));

        return 'filament-seo:sitemap:'.$fingerprint.':'.$document;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \Closure || is_callable($value)) {
            return is_object($value) ? $value::class : (string) $value;
        }
        if (is_object($value)) {
            return $value::class;
        }
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalize($item);
        }
        ksort($normalized);

        return $normalized;
    }
}
