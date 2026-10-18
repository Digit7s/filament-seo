<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\View\Components;

use Digit7s\FilamentSeo\Data\ResolvedSeoMetadata;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use Illuminate\View\View;
use InvalidArgumentException;

/** Renders effective SEO metadata for one explicitly selected Eloquent record. */
final class Meta extends Component
{
    public readonly ResolvedSeoMetadata $metadata;

    public readonly bool $hasStoredMetadata;

    /**
     * @param  array<string, mixed>  $fallbacks  Per-render owner fallback overrides.
     */
    public function __construct(
        public Model $record,
        public array $fallbacks = [],
        ?string $relationship = null,
    ) {
        $relationship ??= config('filament-seo.relationship', 'seoMetadata');

        if (! is_string($relationship) || $relationship === '') {
            throw new InvalidArgumentException('The SEO metadata relationship must be a non-empty string.');
        }

        if ($record->exists) {
            $record->loadMissing($relationship);
        }

        $related = $record->exists ? $record->getRelation($relationship) : null;

        if ($related !== null && ! $related instanceof Model) {
            throw new InvalidArgumentException(sprintf(
                'The SEO metadata relationship [%s] must be a single Eloquent model or null.',
                $relationship,
            ));
        }

        $metadata = $related instanceof Model && $this->belongsToRecord($related, $record)
            ? $related
            : null;

        $this->hasStoredMetadata = $metadata !== null;
        $this->metadata = app(SeoMetadataResolver::class)->resolve(
            $record,
            $metadata,
            fallbackValues: $fallbacks,
        );
    }

    public function render(): View
    {
        return view('filament-seo::components.meta');
    }

    private function belongsToRecord(Model $metadata, Model $record): bool
    {
        return $metadata->getAttributeValue('seoable_type') === $record->getMorphClass()
            && (string) $metadata->getAttributeValue('seoable_id') === (string) $record->getKey();
    }
}
