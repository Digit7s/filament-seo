<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Builds small, allow-listed Schema.org objects from SEO metadata and explicit overrides. */
final class StructuredDataGenerator
{
    /** @var list<string> */
    public const TYPES = [
        'WebPage', 'AboutPage', 'ContactPage', 'WebSite', 'Organization',
        'Article', 'NewsArticle', 'BreadcrumbList',
    ];

    /** @var array<string, list<string>> */
    private const PROPERTIES = [
        'WebPage' => ['name', 'description', 'url', 'image'],
        'AboutPage' => ['name', 'description', 'url', 'image'],
        'ContactPage' => ['name', 'description', 'url', 'image'],
        'WebSite' => ['name', 'description', 'url', 'alternateName'],
        'Organization' => ['name', 'description', 'url', 'logo', 'sameAs'],
        'Article' => ['headline', 'description', 'url', 'image', 'author', 'publisher', 'datePublished', 'dateModified'],
        'NewsArticle' => ['headline', 'description', 'url', 'image', 'author', 'publisher', 'datePublished', 'dateModified'],
        'BreadcrumbList' => ['itemListElement'],
    ];

    public function __construct(private readonly SeoMetadataResolver $metadataResolver) {}

    /** @return array<string, mixed>|null */
    public function generate(Model $owner, ?Model $metadata = null): ?array
    {
        if (! $metadata?->getAttributeValue('structured_data_enabled')) {
            return null;
        }

        $type = $metadata->getAttributeValue('structured_data_type');
        $values = $metadata->getAttributeValue('structured_data');

        if (! is_string($type)) {
            return null;
        }

        $values ??= [];
        if (! is_array($values)) {
            return null;
        }

        $resolved = $this->metadataResolver->resolve($owner, $metadata);
        $seo = [
            'name' => $resolved->title,
            'headline' => $resolved->title,
            'description' => $resolved->description,
            'url' => $resolved->canonicalUrl,
            'image' => $resolved->seoImageUrl,
        ];

        foreach ($this->modelMapping($owner, $type) as $property => $attribute) {
            if (($values[$property] ?? null) !== null || ! is_string($attribute)) {
                continue;
            }

            // Attribute-value access never traverses a relationship or causes a lazy query.
            $value = $owner->getAttributeValue($attribute);
            $values[$property] = $this->normalizeMappedValue($value);
        }

        return $this->build($type, $values, $seo);
    }

    /**
     * Build an object from unsaved form state. Used by the admin preview and
     * the persisted renderer, so both use the same generation rules.
     *
     * @param  array<string, mixed>  $values
     * @param  array{name?: mixed, headline?: mixed, description?: mixed, url?: mixed, image?: mixed}  $seo
     * @return array<string, mixed>|null
     */
    public function build(string $type, array $values, array $seo = []): ?array
    {
        $properties = self::PROPERTIES[$type] ?? $this->customProperties($type);

        if ($properties === null || count($values, COUNT_RECURSIVE) > 128) {
            return null;
        }

        $object = ['@context' => 'https://schema.org', '@type' => $type];

        if ($type === 'BreadcrumbList') {
            $items = $values['itemListElement'] ?? null;

            if (! is_array($items) || count($items) < 1 || count($items) > 30) {
                return null;
            }

            // Filament repeaters may persist UUID-keyed rows; insertion order is preserved.
            $items = array_values($items);

            $list = [];

            foreach ($items as $index => $item) {
                if (! is_array($item) || ! is_string($item['name'] ?? null) || trim($item['name']) === '') {
                    return null;
                }

                $entry = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => trim($item['name'])];
                $rawUrl = $item['url'] ?? null;
                $url = $this->safeUrl($rawUrl);

                // The last breadcrumb may describe the current page without a link.
                if ($url !== null) {
                    $entry['item'] = $url;
                } elseif ($index !== array_key_last($items) || (is_string($rawUrl) && trim($rawUrl) !== '')) {
                    return null;
                }

                $list[] = $entry;
            }

            $object['itemListElement'] = $list;

            return $object;
        }

        $propertyValues = array_intersect_key($values, array_flip($properties));

        foreach ($properties as $property) {
            $value = $propertyValues[$property] ?? $seo[$property] ?? null;

            if ($property === 'headline') {
                $value ??= $seo['name'] ?? null;
            }

            if ($property === 'image' || $property === 'logo') {
                if ($property === 'image' && ($value === null || $value === '')) {
                    $value = $seo['image'] ?? null;
                }
                $url = $this->safeUrl($value, image: true)
                    ?? ($property === 'image' ? $this->safeUrl($seo['image'] ?? null, image: true) : null);

                if ($url !== null) {
                    $object[$property] = $url;
                }

                continue;
            }

            if ($property === 'url') {
                $url = $this->safeUrl($value) ?? $this->safeUrl($seo[$property] ?? null);

                if ($url !== null) {
                    $object[$property] = $url;
                }

                continue;
            }

            if (in_array($property, ['datePublished', 'dateModified'], true)) {
                $date = $this->isoDate($value);

                if ($date !== null) {
                    $object[$property] = $date;
                }

                continue;
            }

            if ($property === 'author' || $property === 'publisher') {
                $person = $this->namedEntity($value, $property === 'author' ? 'Person' : 'Organization');

                if ($person !== null) {
                    $object[$property] = $person;
                }

                continue;
            }

            if ($property === 'sameAs') {
                $urls = $this->safeUrlList($value);

                if ($urls !== []) {
                    $object[$property] = $urls;
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $object[$property] = trim($value);
            }
        }

        if (in_array($type, ['WebSite', 'Organization'], true) && (! isset($object['name']) || ! isset($object['url']))) {
            return null;
        }

        if (in_array($type, ['Article', 'NewsArticle'], true) && ! isset($object['headline'])) {
            return null;
        }

        if (count($object) === 2) {
            return null;
        }

        return $object;
    }

    /** @return list<string> */
    public function supportedTypes(): array
    {
        $custom = config('filament-seo.structured_data.additional_types', []);
        $customTypes = is_array($custom)
            ? array_filter(array_keys($custom), static fn ($type): bool => is_string($type)
                && preg_match('/^[A-Z][A-Za-z0-9]{0,79}$/', $type) === 1
                && is_array($custom[$type]))
            : [];

        return [...self::TYPES, ...array_values($customTypes)];
    }

    /** @return list<string>|null */
    public function propertiesFor(string $type): ?array
    {
        return self::PROPERTIES[$type] ?? $this->customProperties($type);
    }

    /** @return array<string, mixed> mapped fields read without lazy-loading relationships */
    public function mappedValuesFor(Model $owner, string $type): array
    {
        $values = [];

        foreach ($this->modelMapping($owner, $type) as $property => $attribute) {
            $values[$property] = $this->normalizeMappedValue($owner->getAttributeValue($attribute));
        }

        return $values;
    }

    /** @param array<string, mixed> $values
     * @return array<string, string> machine-readable issue codes
     */
    public function validationIssues(string $type, array $values): array
    {
        $properties = $this->propertiesFor($type);

        if ($properties === null) {
            return ['schema_type' => 'Unsupported Schema.org type.'];
        }

        $issues = [];
        foreach (array_keys($values) as $property) {
            if (! in_array($property, $properties, true)) {
                $issues['unsupported_property'] = 'One or more properties are not supported for this schema type.';
                break;
            }
        }

        foreach (['url', 'image', 'logo'] as $property) {
            if (($values[$property] ?? null) !== null && $values[$property] !== '' && $this->safeUrl($values[$property], image: $property !== 'url') === null) {
                $issues['invalid_url'] = "The {$property} property must be a safe absolute URL.";
            }
        }

        foreach (['datePublished', 'dateModified'] as $property) {
            if (($values[$property] ?? null) !== null && $values[$property] !== '' && $this->isoDate($values[$property]) === null) {
                $issues['invalid_date'] = "The {$property} property must be a valid date or date-time.";
            }
        }

        if (array_key_exists('sameAs', $values)) {
            $sameAs = $values['sameAs'];
            if (! is_array($sameAs) || ! array_is_list($sameAs) || $this->safeUrlList($sameAs) !== $sameAs) {
                $issues['invalid_same_as'] = 'Social profile entries must be safe absolute URLs.';
            }
        }

        if ($type === 'BreadcrumbList' && $this->build($type, $values) === null) {
            $issues['invalid_breadcrumb_list'] = 'Breadcrumb items need ordered names and safe URLs, with only the last URL optional.';
        }

        if (in_array($type, ['WebSite', 'Organization'], true)) {
            if ($this->text($values['name'] ?? null) === null) {
                $issues['missing_name'] = 'A name is required for this schema type.';
            }
            if (($values['url'] ?? null) === null || $this->safeUrl($values['url']) === null) {
                $issues['missing_url'] = 'A safe absolute URL is required for this schema type.';
            }
        }

        if (in_array($type, ['Article', 'NewsArticle'], true) && $this->text($values['headline'] ?? null) === null) {
            $issues['missing_headline'] = 'A headline is required unless a meta title is available.';
        }

        return $issues;
    }

    /** @return array<string, mixed>|null */
    public function siteGraph(): ?array
    {
        $site = config('filament-seo.structured_data.site', []);

        if (! is_array($site) || ($site['enabled'] ?? false) !== true) {
            return null;
        }

        $objects = [];
        $url = $this->safeUrl($site['url'] ?? null);
        $organizationSettings = is_array($site['organization'] ?? null) ? $site['organization'] : [];
        $websiteSettings = is_array($site['website'] ?? null) ? $site['website'] : [];
        $organizationName = $this->text($organizationSettings['name'] ?? null);

        if ($url !== null && $organizationName !== null) {
            $organization = ['@type' => 'Organization', '@id' => rtrim($url, '/').'#organization', 'name' => $organizationName, 'url' => $url];
            foreach (['description' => 'description', 'logo' => 'logo'] as $property => $key) {
                $value = $property === 'logo' ? $this->safeUrl($organizationSettings[$key] ?? null, image: true) : $this->text($organizationSettings[$key] ?? null);
                if ($value !== null) {
                    $organization[$property] = $value;
                }
            }
            $sameAs = $this->safeUrlList($organizationSettings['same_as'] ?? []);
            if ($sameAs !== []) {
                $organization['sameAs'] = $sameAs;
            }
            $objects[] = $organization;
        }

        $websiteName = $this->text($websiteSettings['name'] ?? null);
        if ($url !== null && $websiteName !== null) {
            $website = ['@type' => 'WebSite', '@id' => rtrim($url, '/').'#website', 'name' => $websiteName, 'url' => $url];
            $description = $this->text($websiteSettings['description'] ?? null);
            if ($description !== null) {
                $website['description'] = $description;
            }
            $organization = $organizationName !== null ? rtrim($url, '/').'#organization' : null;
            if ($organization !== null) {
                $website['publisher'] = ['@id' => $organization];
            }
            $objects[] = $website;
        }

        return $objects === [] ? null : $this->graph($objects);
    }

    /** @param list<array<string, mixed>> $objects
     * @return array<string, mixed>
     */
    public function graph(array $objects): array
    {
        $merged = [];
        foreach ($objects as $object) {
            $type = $object['@type'] ?? null;
            if (! is_string($type)) {
                continue;
            }
            if (isset($merged[$type])) {
                $merged[$type] = array_replace($merged[$type], $object);
            } else {
                $merged[$type] = $object;
            }
        }

        $values = array_values($merged);

        return count($values) === 1
            ? ['@context' => 'https://schema.org', ...$values[0]]
            : ['@context' => 'https://schema.org', '@graph' => array_map(static function (array $object): array {
                unset($object['@context']);

                return $object;
            }, $values)];
    }

    /** @return array<string, string> */
    private function modelMapping(Model $owner, string $type): array
    {
        $mapping = config('filament-seo.structured_data.model_mappings.'.get_class($owner), []);

        if (! is_array($mapping) || ($mapping['type'] ?? null) !== $type || ! is_array($mapping['fields'] ?? null)) {
            return [];
        }

        return array_filter($mapping['fields'], static fn ($attribute): bool => is_string($attribute) && $attribute !== '');
    }

    private function normalizeMappedValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }

    /** @return list<string>|null */
    private function customProperties(string $type): ?array
    {
        $custom = config('filament-seo.structured_data.additional_types.'.$type);

        if (! preg_match('/^[A-Z][A-Za-z0-9]{0,79}$/', $type) || ! is_array($custom)) {
            return null;
        }

        return array_values(array_filter($custom, static fn ($property): bool => is_string($property) && preg_match('/^[a-z][A-Za-z0-9]{0,79}$/', $property) === 1));
    }

    private function safeUrl(mixed $value, bool $image = false): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $url = trim($value);
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $allowHttp = $image && (bool) config('filament-seo.images.allow_http', false);
        if (! in_array(strtolower($parts['scheme']), $allowHttp ? ['https', 'http'] : ['https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    /** @return list<string> */
    private function safeUrlList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/', $value, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($value) || ! array_is_list($value) || count($value) > 30) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($url): ?string => $this->safeUrl($url), $value)));
    }

    private function isoDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, string>|null */
    private function namedEntity(mixed $value, string $type): ?array
    {
        $name = is_array($value) ? $this->text($value['name'] ?? null) : $this->text($value);

        return $name === null ? null : ['@type' => $type, 'name' => $name];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
