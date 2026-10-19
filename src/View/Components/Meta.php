<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\View\Components;

use Digit7s\FilamentSeo\Data\ResolvedSeoMetadata;
use Digit7s\FilamentSeo\Models\SeoPage;
use Digit7s\FilamentSeo\Support\SeoMetadataResolver;
use Digit7s\FilamentSeo\Support\SeoPagePath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use Illuminate\View\View;
use InvalidArgumentException;

/** Renders SEO metadata for an explicit record/page or an explicitly enabled exact request match. */
final class Meta extends Component
{
    public readonly ?ResolvedSeoMetadata $metadata;

    public readonly bool $hasStoredMetadata;

    private bool $renderable = true;

    /**
     * @param  array<string, mixed>  $fallbacks  Per-render owner fallback overrides.
     */
    public function __construct(
        public ?Model $record = null,
        public ?string $page = null,
        public ?string $route = null,
        public array $fallbacks = [],
        ?string $relationship = null,
    ) {
        $selectors = (int) ($record !== null) + (int) ($page !== null) + (int) ($route !== null);

        if ($selectors > 1) {
            throw new InvalidArgumentException('Pass only one of record, page, or route to the SEO metadata component.');
        }

        if ($page !== null || $route !== null) {
            $record = $page !== null
                ? SeoPage::query()->where('page_key', $page)->first()
                : SeoPage::query()->where('route_name', $route)->first();

            if ($record instanceof SeoPage) {
                $identity = $page !== null ? $record->page_key : $record->route_name;

                if ($identity !== ($page ?? $route) || ! $record->is_active) {
                    $record = null;
                }
            }
        } elseif ($record === null && config('filament-seo.static_pages.resolve_current_request', false)) {
            $record = $this->resolveCurrentPage(request());
        }

        if ($record === null) {
            $this->metadata = null;
            $this->hasStoredMetadata = false;
            $this->renderable = false;

            return;
        }

        $relationship ??= $record instanceof SeoPage
            ? 'seoMetadata'
            : config('filament-seo.relationship', 'seoMetadata');

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

    public function shouldRender(): bool
    {
        return $this->renderable;
    }

    private function resolveCurrentPage(Request $request): ?SeoPage
    {
        $routeName = $request->route()?->getName();

        if (is_string($routeName) && $routeName !== '') {
            $page = SeoPage::query()->where('route_name', $routeName)->first();

            if ($page !== null) {
                return $page->route_name === $routeName && $page->is_active ? $page : null;
            }
        }

        $path = SeoPagePath::normalize('/'.ltrim($request->path(), '/'));
        $page = SeoPage::query()->where('path', $path)->where('is_active', true)->first();

        return $page?->path === $path ? $page : null;
    }

    private function belongsToRecord(Model $metadata, Model $record): bool
    {
        return $metadata->getAttributeValue('seoable_type') === $record->getMorphClass()
            && (string) $metadata->getAttributeValue('seoable_id') === (string) $record->getKey();
    }
}
