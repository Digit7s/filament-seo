<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Filament\Forms;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use InvalidArgumentException;

/** Builds a native relationship-bound SEO fields section. */
final class SeoFields
{
    /** @var array<string, list<string>> */
    private const GROUPS = [
        'basic' => ['meta_title', 'meta_description', 'keywords', 'canonical_url', 'seo_image', 'seo_image_alt'],
        'social' => ['og_title', 'og_description', 'og_image', 'og_image_alt', 'twitter_title', 'twitter_description', 'twitter_image', 'twitter_image_alt'],
        'robots' => ['robots_index', 'robots_follow'],
    ];

    /** @var array<string, string> */
    private const TYPES = [
        'meta_title' => 'text', 'meta_description' => 'textarea', 'keywords' => 'text', 'canonical_url' => 'url',
        'seo_image' => 'image', 'seo_image_alt' => 'text',
        'og_title' => 'text', 'og_description' => 'textarea', 'og_image' => 'image', 'og_image_alt' => 'text',
        'twitter_title' => 'text', 'twitter_description' => 'textarea', 'twitter_image' => 'image', 'twitter_image_alt' => 'text',
        'robots_index' => 'toggle', 'robots_follow' => 'toggle',
    ];

    /**
     * @param  list<string>  $groups
     * @param  list<string>|null  $fields
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $defaults
     * @param  array<string, int>  $maxLengths
     * @param  array<string, string|Closure>  $recommendations
     * @param  array<string, bool|Closure>  $required
     * @param  array<string, string|Closure>  $imageDirectories
     * @param  bool  $tabbed  Render the existing field groups as native Filament tabs.
     */
    public static function make(
        string $relationship = 'seoMetadata',
        array $groups = ['basic', 'social', 'robots'],
        ?array $fields = null,
        array $labels = [],
        array $defaults = [],
        array $maxLengths = [],
        array $recommendations = [],
        array $required = [],
        string|Closure|null $imageDisk = null,
        string|Closure|null $imageVisibility = null,
        array $imageDirectories = [],
        bool|Closure $visible = true,
        bool|Closure $disabled = false,
        bool $collapsible = true,
        bool $collapsed = true,
        bool $tabbed = false,
    ): Section {
        foreach ($groups as $group) {
            if (! isset(self::GROUPS[$group])) {
                throw new InvalidArgumentException("Unsupported SEO field group [{$group}].");
            }
        }

        foreach ([$fields, array_keys($labels), array_keys($defaults), array_keys($maxLengths), array_keys($recommendations), array_keys($required)] as $identifiers) {
            foreach ($identifiers ?? [] as $field) {
                if (! is_string($field) || ! isset(self::TYPES[$field])) {
                    throw new InvalidArgumentException("Unsupported SEO field [{$field}].");
                }
            }
        }

        foreach (array_keys($imageDirectories) as $channel) {
            if (! in_array($channel, ['seo', 'og', 'twitter'], true)) {
                throw new InvalidArgumentException("Unsupported SEO image channel [{$channel}].");
            }
        }

        foreach ($maxLengths as $field => $maxLength) {
            if (! is_string($field) || ! isset(self::TYPES[$field]) || in_array(self::TYPES[$field], ['image', 'toggle'], true)) {
                throw new InvalidArgumentException("SEO max length is not supported for field [{$field}].");
            }

            if (! is_int($maxLength) || $maxLength < 1) {
                throw new InvalidArgumentException("SEO max length for field [{$field}] must be a positive integer.");
            }
        }

        $selected = $fields === null ? null : array_values(array_unique($fields));
        $components = [];

        foreach ($groups as $group) {
            $groupFields = array_values(array_filter(
                self::GROUPS[$group],
                fn (string $field): bool => $selected === null || in_array($field, $selected, true),
            ));

            if ($groupFields === []) {
                continue;
            }

            $groupComponents = [];

            foreach ($groupFields as $field) {
                $groupComponents[] = self::field(
                    $field,
                    $labels[$field] ?? null,
                    $defaults[$field] ?? null,
                    $maxLengths[$field] ?? null,
                    $recommendations[$field] ?? null,
                    $required[$field] ?? false,
                    $imageDisk,
                    $imageVisibility,
                    $imageDirectories[self::imageChannel($field)] ?? self::defaultImageDirectory($field),
                    $disabled,
                );
            }

            if ($tabbed) {
                $tab = Tab::make(fn (): string => __('filament-seo::filament-seo.groups.'.$group))
                    ->key('seo-'.$group)
                    ->schema($groupComponents)
                    // Filament 5.9 exposes tab roles but does not yet provide the
                    // keyboard roving-focus behavior documented in later 5.x.
                    ->extraAttributes([
                        'x-bind:tabindex' => "tab === 'seo-{$group}' ? 0 : -1",
                        'x-on:keydown.stop' => <<<'JS'
                            if ([35, 36, 37, 39].includes($event.keyCode)) {
                                const tabs = [...$el.parentElement.children].filter((tab) => tab.offsetParent !== null)
                                const index = tabs.indexOf($el)
                                const isRtl = getComputedStyle($el.parentElement).direction.charCodeAt(0) === 114
                                const direction = $event.keyCode === 39 ? (isRtl ? -1 : 1) : (isRtl ? 1 : -1)
                                const next = $event.keyCode === 36
                                    ? 0
                                    : ($event.keyCode === 35
                                        ? tabs.length - 1
                                        : (index + direction + tabs.length) % tabs.length)

                                $event.preventDefault()
                                tabs[next]?.focus()
                            }
                            JS,
                    ])
                    ->badge(static function (Tab $component): ?int {
                        $fieldPaths = array_filter(array_map(
                            static fn ($field): ?string => $field->getStatePath(),
                            $component->getChildSchema()->getComponents(),
                        ));
                        $errorPaths = $component->getLivewire()->getErrorBag()->keys();

                        $count = count(array_intersect($fieldPaths, $errorPaths));

                        return $count > 0 ? $count : null;
                    })
                    ->badgeColor('danger')
                    ->badgeTooltip(fn (): string => __('filament-seo::filament-seo.messages.tab_validation_errors'));

                $components[] = $tab;

                continue;
            }

            $components[] = Section::make(fn (): string => __('filament-seo::filament-seo.groups.'.$group))
                ->schema($groupComponents);
        }

        $content = $tabbed
            ? Tabs::make(fn (): string => __('filament-seo::filament-seo.groups.seo'))
                ->tabs($components)
                ->activeTab(1)
                ->contained(false)
            : $components;

        $section = Section::make(fn (): string => __('filament-seo::filament-seo.groups.seo'))
            ->schema($tabbed ? [$content] : $components)
            ->relationship($relationship)
            ->visible($visible)
            ->disabled($disabled);

        if ($collapsible) {
            $section->collapsible()->collapsed($collapsed);
        }

        return $section;
    }

    private static function field(
        string $name,
        ?string $label,
        mixed $default,
        ?int $maxLength,
        string|Closure|null $recommendation,
        bool|Closure $required,
        string|Closure|null $imageDisk,
        string|Closure|null $imageVisibility,
        string|Closure|null $directory,
        bool|Closure $disabled,
    ): TextInput|Textarea|FileUpload|Toggle {
        $label ??= fn (): string => __('filament-seo::filament-seo.fields.'.$name);
        $helperText = $recommendation === null
            ? null
            : (is_string($recommendation) ? $recommendation : $recommendation);

        $component = match (self::TYPES[$name]) {
            'textarea' => Textarea::make($name)->rows(3),
            'url' => TextInput::make($name)->url(),
            'image' => FileUpload::make($name)
                ->image()
                // Filament's image() configures the chooser's accepted types;
                // enforce the image file rule on the server as well.
                ->rule('image')
                // Laravel's image rule checks the inferred extension. A minimum
                // dimension also inspects bytes, so a client cannot spoof the MIME.
                ->rule('dimensions:min_width=1')
                // Do not store a permanent file when the host disables the field.
                ->storeFiles(static fn (FileUpload $component): bool => ! $component->isDisabled())
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'])
                ->maxSize(10240)
                // Preserve compatible host paths even when their storage disk differs.
                ->fetchFileInformation(false)
                ->disk($imageDisk ?? config('filament-seo.images.disk') ?? config('filesystems.default', 'local'))
                ->visibility($imageVisibility ?? config('filament-seo.images.visibility', 'private'))
                ->directory($directory),
            'toggle' => Toggle::make($name),
            default => TextInput::make($name),
        };

        $component->label($label)->required($required)->disabled($disabled);

        if ($default !== null) {
            $component->default($default);
        } elseif ($name === 'robots_index' || $name === 'robots_follow') {
            $component->default(true);
        }

        if ($maxLength !== null && $component instanceof TextInput) {
            $component->maxLength($maxLength);
        }

        if ($maxLength !== null && $component instanceof Textarea) {
            $component->maxLength($maxLength);
        }

        if ($helperText !== null) {
            $component->helperText($helperText);
        }

        return $component;
    }

    private static function defaultImageDirectory(string $field): string|Closure
    {
        $channel = self::imageChannel($field);

        return fn (): string => (string) config("filament-seo.images.directories.{$channel}", "seo/{$channel}-images");
    }

    private static function imageChannel(string $field): string
    {
        return match ($field) {
            'seo_image' => 'seo',
            'og_image' => 'og',
            default => 'twitter',
        };
    }
}
