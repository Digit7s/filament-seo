<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\View\Components;

use Digit7s\FilamentSeo\Models\SeoPage;
use Digit7s\FilamentSeo\Support\SeoPagePath;
use Digit7s\FilamentSeo\Support\StructuredDataGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

/** Explicit JSON-LD renderer for opted-in SEO records and configured site identity. */
final class JsonLd extends Component
{
    public ?string $json;

    private bool $renderable;

    public function __construct(
        public ?Model $record = null,
        public ?string $page = null,
        public ?string $route = null,
        ?string $relationship = null,
    ) {
        if (((int) ($record !== null) + (int) ($page !== null) + (int) ($route !== null)) > 1) {
            throw new InvalidArgumentException('Pass only one of record, page, or route to the JSON-LD component.');
        }

        $owner = null;

        try {
            $owner = $this->resolveOwner($record);
            $generator = app(StructuredDataGenerator::class);
            $objects = [];

            if ($owner !== null) {
                $relationship ??= $owner instanceof SeoPage ? 'seoMetadata' : config('filament-seo.relationship', 'seoMetadata');
                if (! is_string($relationship) || $relationship === '') {
                    throw new InvalidArgumentException('The SEO metadata relationship must be a non-empty string.');
                }
                if ($owner->exists) {
                    $owner->loadMissing($relationship);
                }
                $metadata = $owner->exists ? $owner->getRelation($relationship) : null;

                if ($metadata instanceof Model
                    && $metadata->getAttributeValue('seoable_type') === $owner->getMorphClass()
                    && (string) $metadata->getAttributeValue('seoable_id') === (string) $owner->getKey()) {
                    $object = $generator->generate($owner, $metadata);
                    if ($object !== null) {
                        unset($object['@context']);
                        $objects[] = $object;
                    }
                }
            }

            $siteGraph = $generator->siteGraph();
            if ($siteGraph !== null) {
                foreach ($siteGraph['@graph'] ?? [$siteGraph] as $object) {
                    unset($object['@context']);
                    $objects[] = $object;
                }
            }

            $renderedTypes = request()->attributes->get('filament-seo.json-ld.types', []);
            $renderedTypes = is_array($renderedTypes) ? $renderedTypes : [];
            $objects = array_values(array_filter(
                $objects,
                static fn (array $object): bool => ! in_array($object['@type'] ?? null, $renderedTypes, true),
            ));
            $pendingTypes = array_values(array_unique(array_filter(array_column($objects, '@type'), 'is_string')));
            $graph = $objects === [] ? null : $generator->graph($objects);
            $this->json = $graph === null ? null : json_encode(
                $graph,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );

            if ($this->json !== null && strlen($this->json) > 32768) {
                $this->json = null;
            }

            if ($this->json !== null) {
                request()->attributes->set('filament-seo.json-ld.types', [...$renderedTypes, ...$pendingTypes]);
            }
        } catch (Throwable) {
            // Malformed or unsupported stored configuration must not break page rendering.
            $this->json = null;
        }

        $this->renderable = $this->json !== null;
    }

    public function render(): View
    {
        return view('filament-seo::components.json-ld');
    }

    public function shouldRender(): bool
    {
        return $this->renderable;
    }

    private function resolveOwner(?Model $record): ?Model
    {
        if ($record !== null) {
            return $record instanceof SeoPage && ! $record->is_active ? null : $record;
        }

        if ($this->page !== null) {
            return SeoPage::query()->where('page_key', $this->page)->where('is_active', true)->first();
        }

        if ($this->route !== null) {
            return SeoPage::query()->where('route_name', $this->route)->where('is_active', true)->first();
        }

        if (! config('filament-seo.static_pages.resolve_current_request', false)) {
            return null;
        }

        $request = request();
        $routeName = $request->route()?->getName();

        if (is_string($routeName) && $routeName !== '') {
            $page = SeoPage::query()->where('route_name', $routeName)->first();
            if ($page !== null) {
                return $page->is_active ? $page : null;
            }
        }

        $path = SeoPagePath::normalize('/'.ltrim($request->path(), '/'));
        $page = SeoPage::query()->where('path', $path)->where('is_active', true)->first();

        return $page?->path === $path ? $page : null;
    }
}
