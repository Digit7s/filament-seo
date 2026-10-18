<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Support;

use Digit7s\FilamentSeo\Data\ResolvedSeoMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Stringable;
use Throwable;

/** Resolves effective values without loading or writing the metadata relation. */
final class SeoMetadataResolver
{
    /**
     * Resolve effective metadata from an owner and an explicitly supplied row.
     * The resolver never reads a relationship or performs persistence.
     *
     * @param  array<string, mixed>  $fallbacks  Per-call owner fallback overrides.
     * @param  array<string, mixed>  $fallbackValues  Literal or callback values applied after configured defaults.
     */
    public function resolve(
        Model $owner,
        ?Model $metadata = null,
        array $fallbacks = [],
        array $fallbackValues = [],
    ): ResolvedSeoMetadata {
        $configuredFallbacks = $this->configuredFallbacksFor($owner);
        $fallbacks = array_replace($configuredFallbacks, $fallbacks);

        $title = $this->firstText(
            $this->metadataValue($metadata, 'meta_title'),
            $this->fallbackValue($owner, $fallbacks['title'] ?? null),
            config('filament-seo.defaults.title'),
            $this->consumerFallbackValue($owner, $fallbackValues['title'] ?? null),
        );

        $description = $this->firstText(
            $this->metadataValue($metadata, 'meta_description'),
            $this->fallbackValue($owner, $fallbacks['description'] ?? null),
            config('filament-seo.defaults.description'),
            $this->consumerFallbackValue($owner, $fallbackValues['description'] ?? null),
        );

        $canonicalUrl = $this->firstSafeUrl(
            $this->metadataValue($metadata, 'canonical_url'),
            $this->fallbackValue($owner, $fallbacks['canonical'] ?? null),
            $this->consumerFallbackValue($owner, $fallbackValues['canonical'] ?? null),
        );

        $seoImageUrl = $this->firstImageUrl(
            owner: $owner,
            metadataValue: $this->metadataValue($metadata, 'seo_image'),
            fallbackValue: $this->fallbackValue($owner, $fallbacks['image'] ?? null),
            siteDefault: config('filament-seo.defaults.image'),
            consumerFallback: $this->consumerFallbackValue($owner, $fallbackValues['image'] ?? null),
            channel: 'seo',
        );

        $seoImageAlt = $this->firstText($this->metadataValue($metadata, 'seo_image_alt'));

        $ogTitle = $this->firstText($this->metadataValue($metadata, 'og_title'), $title);
        $ogDescription = $this->firstText($this->metadataValue($metadata, 'og_description'), $description);
        $ogImageUrl = $this->firstImageUrl(
            owner: $owner,
            metadataValue: $this->metadataValue($metadata, 'og_image'),
            fallbackValue: $seoImageUrl,
            siteDefault: null,
            channel: 'og',
        );
        $ogImageAlt = $this->firstText($this->metadataValue($metadata, 'og_image_alt'), $seoImageAlt);

        $twitterTitle = $this->firstText($this->metadataValue($metadata, 'twitter_title'), $ogTitle);
        $twitterDescription = $this->firstText($this->metadataValue($metadata, 'twitter_description'), $ogDescription);
        $twitterImageUrl = $this->firstImageUrl(
            owner: $owner,
            metadataValue: $this->metadataValue($metadata, 'twitter_image'),
            fallbackValue: $ogImageUrl,
            siteDefault: null,
            channel: 'twitter',
        );
        $twitterImageAlt = $this->firstText($this->metadataValue($metadata, 'twitter_image_alt'), $ogImageAlt);

        return new ResolvedSeoMetadata(
            title: $title,
            description: $description,
            canonicalUrl: $canonicalUrl,
            seoImageUrl: $seoImageUrl,
            seoImageAlt: $seoImageAlt,
            ogTitle: $ogTitle,
            ogDescription: $ogDescription,
            ogImageUrl: $ogImageUrl,
            ogImageAlt: $ogImageAlt,
            twitterTitle: $twitterTitle,
            twitterDescription: $twitterDescription,
            twitterImageUrl: $twitterImageUrl,
            twitterImageAlt: $twitterImageAlt,
            robotsIndex: $this->robotValue($metadata, 'robots_index'),
            robotsFollow: $this->robotValue($metadata, 'robots_follow'),
            keywords: $this->firstText($this->metadataValue($metadata, 'keywords')),
        );
    }

    /** @return array<string, mixed> */
    private function configuredFallbacksFor(Model $owner): array
    {
        $fallbacks = config('filament-seo.fallbacks', []);
        $configured = is_array($fallbacks) && is_array($fallbacks['default'] ?? null)
            ? $fallbacks['default']
            : [];

        $models = is_array($fallbacks) && is_array($fallbacks['models'] ?? null)
            ? $fallbacks['models']
            : [];

        $ownerClass = get_class($owner);

        if (is_array($models[$ownerClass] ?? null)) {
            $configured = array_replace($configured, $models[$ownerClass]);
        }

        return $configured;
    }

    private function metadataValue(?Model $metadata, string $attribute): mixed
    {
        return $metadata?->getAttributeValue($attribute);
    }

    private function fallbackValue(Model $owner, mixed $fallback): mixed
    {
        if ($fallback === null) {
            return null;
        }

        if (is_string($fallback)) {
            // Use attribute-value access directly; getAttribute() can resolve a
            // relationship and issue an accidental lazy query.
            return $owner->getAttributeValue($fallback);
        }

        if (is_callable($fallback)) {
            return $fallback($owner);
        }

        throw new InvalidArgumentException('SEO fallbacks must be attribute names, callables, or null.');
    }

    private function consumerFallbackValue(Model $owner, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_callable($value)) {
            return $value($owner);
        }

        if (is_string($value) || $value instanceof Stringable) {
            return $value;
        }

        throw new InvalidArgumentException('SEO consumer fallback values must be strings, callables, or null.');
    }

    private function firstText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = $this->normalizeText($value);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! $value instanceof Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function firstSafeUrl(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $text = $this->normalizeText($value);

            if ($text !== null && ($url = $this->validateAbsoluteUrl($text, false)) !== null) {
                return $url;
            }
        }

        return null;
    }

    private function firstImageUrl(
        Model $owner,
        mixed $metadataValue,
        mixed $fallbackValue,
        mixed $siteDefault,
        string $channel,
        mixed $consumerFallback = null,
    ): ?string {
        foreach ([$metadataValue, $fallbackValue, $siteDefault, $consumerFallback] as $candidate) {
            $path = $this->normalizeText($candidate);

            if ($path === null) {
                continue;
            }

            $url = $this->resolveImageUrl($path, $owner, $channel);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function resolveImageUrl(string $path, Model $owner, string $channel): ?string
    {
        if (str_starts_with($path, '//')) {
            return null;
        }

        $parts = parse_url($path);

        if ($parts === false) {
            return null;
        }

        if (isset($parts['scheme'])) {
            return $this->validateAbsoluteUrl($path, (bool) config('filament-seo.images.allow_http', false));
        }

        // A leading slash or platform separator indicates a filesystem/rooted
        // path, not a storage-relative key. Never turn it into a public URL.
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) === 1) {
            return null;
        }

        $disk = config('filament-seo.images.disk');
        $resolver = config('filament-seo.images.url_resolver');

        if ($resolver !== null && ! is_callable($resolver)) {
            throw new InvalidArgumentException('filament-seo.images.url_resolver must be callable or null.');
        }

        if (is_callable($resolver)) {
            try {
                $resolved = $resolver($path, is_string($disk) ? $disk : null, $owner, $channel);
            } catch (Throwable) {
                return null;
            }

            $resolved = $this->normalizeText($resolved);

            return $resolved === null
                ? null
                : $this->validateAbsoluteUrl($resolved, (bool) config('filament-seo.images.allow_http', false));
        }

        $publicDisks = config('filament-seo.images.public_disks', []);

        // public_disks is an explicit host assertion. Storage::url() alone
        // cannot establish that a disk or remote object is publicly readable.
        if (! is_string($disk) || $disk === '' || ! is_array($publicDisks) || ! in_array($disk, $publicDisks, true)) {
            return null;
        }

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return null;
            }

            $url = $storage->url($path);
        } catch (Throwable) {
            return null;
        }

        $url = $this->normalizeText($url);

        return $url === null
            ? null
            : $this->validateAbsoluteUrl($url, (bool) config('filament-seo.images.allow_http', false));
    }

    private function validateAbsoluteUrl(string $url, bool $allowHttp): ?string
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_starts_with($url, '//')) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($allowHttp && $scheme === 'http')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }

    private function robotValue(?Model $metadata, string $attribute): bool
    {
        if ($metadata === null) {
            return true;
        }

        $value = $metadata->getAttributeValue($attribute);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new InvalidArgumentException("SEO metadata field [{$attribute}] must contain a non-null boolean value.");
    }
}
