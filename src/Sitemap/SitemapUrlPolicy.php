<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use InvalidArgumentException;

/** Resolves sitemap locations against the configured application origin, never the request host. */
final class SitemapUrlPolicy
{
    /** @var array{scheme:string,host:string,port:?int,path:string} */
    private array $origin;

    public function __construct()
    {
        $appUrl = config('app.url');
        if (! is_string($appUrl) || ! ($parts = parse_url($appUrl)) || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The application URL must be an absolute HTTP or HTTPS origin for sitemap generation.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The application URL for sitemap generation must be a safe HTTP or HTTPS URL without credentials, query, or fragment.');
        }

        $this->origin = [
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => $this->normalizedPort($scheme, $parts['port'] ?? null),
            'path' => rtrim($parts['path'] ?? '', '/'),
        ];
    }

    public function fromPath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) === 1) {
            return $this->fromCandidate($path);
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        if ($this->hasTraversal($path)) {
            return null;
        }

        $path = $this->normalizePath($path);

        return $this->fromCandidate($this->originUrl().$this->origin['path'].$path);
    }

    public function canonicalOrPath(mixed $canonical, string $fallbackPath): ?string
    {
        if (is_string($canonical) && trim($canonical) !== '') {
            return $this->fromCandidate(trim($canonical));
        }

        return $this->fromPath($fallbackPath);
    }

    public function fromCandidate(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '' || preg_match('/[\x00-\x1f\x7f]/', $candidate) === 1 || str_starts_with($candidate, '//') || str_contains($candidate, '\\')) {
            return null;
        }

        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        if (! isset($parts['scheme'], $parts['host'])) {
            if (isset($parts['scheme'])) {
                return null;
            }

            return $this->fromPath($candidate);
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true) || strtolower($parts['host']) !== $this->origin['host'] || $this->normalizedPort($scheme, $parts['port'] ?? null) !== $this->origin['port']) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if ($this->hasTraversal($path)) {
            return null;
        }

        // Canonical URLs must remain on the configured site origin and scheme.
        if ($scheme !== $this->origin['scheme']) {
            return null;
        }

        $path = $this->encodePath($this->normalizePath($path));
        if ($this->origin['path'] !== '' && ! ($path === $this->origin['path'] || str_starts_with($path, $this->origin['path'].'/'))) {
            return null;
        }

        $url = $this->originUrl().$path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?'.$parts['query'];
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && strlen($url) <= 2048 ? $url : null;
    }

    public function deduplicationKey(string $url): string
    {
        $parts = parse_url($url);
        $path = $this->normalizePath($parts['path'] ?? '/');

        return strtolower($this->originUrl()).$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function originUrl(): string
    {
        return $this->origin['scheme'].'://'.$this->origin['host'].($this->origin['port'] === null ? '' : ':'.$this->origin['port']);
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        if ($path === '') {
            return '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(static function (string $segment): string {
            return rawurlencode(rawurldecode($segment));
        }, explode('/', $path)));
    }

    private function normalizedPort(string $scheme, ?int $port): ?int
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? null : $port;
    }

    private function hasTraversal(string $path): bool
    {
        $decoded = rawurldecode($path);

        return str_contains($decoded, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decoded) === 1;
    }
}
