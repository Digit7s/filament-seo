<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Data;

/**
 * Effective SEO values resolved for one owner and its explicitly supplied
 * metadata record. This object contains no persistence behavior.
 */
final readonly class ResolvedSeoMetadata
{
    public function __construct(
        public ?string $title,
        public ?string $description,
        public ?string $canonicalUrl,
        public ?string $seoImageUrl,
        public ?string $seoImageAlt,
        public ?string $ogTitle,
        public ?string $ogDescription,
        public ?string $ogImageUrl,
        public ?string $ogImageAlt,
        public ?string $twitterTitle,
        public ?string $twitterDescription,
        public ?string $twitterImageUrl,
        public ?string $twitterImageAlt,
        public bool $robotsIndex,
        public bool $robotsFollow,
        public ?string $keywords = null,
    ) {}
}
