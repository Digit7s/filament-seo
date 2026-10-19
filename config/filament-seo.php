<?php

use Digit7s\FilamentSeo\Models\SeoMetadata;

return [
    /*
    | The metadata model used by the package relationship trait. Applications
    | with an existing compatible model may replace this class or keep their
    | existing owner relationship without using the package trait.
    */
    'model' => SeoMetadata::class,

    'relationship' => 'seoMetadata',

    /* Owner fallback values are attribute names or callbacks receiving Model. */
    'fallbacks' => [
        'default' => [],
        'models' => [],
    ],

    /* Optional site defaults are used after owner fallbacks. */
    'defaults' => [
        'title' => null,
        'description' => null,
        'image' => null,
    ],

    /* Upload and effective public URL settings. */
    'images' => [
        'disk' => null,
        'visibility' => 'private',
        // Storage URLs are emitted only for disks explicitly asserted public.
        'public_disks' => [],
        'allow_http' => false,
        'directories' => [
            'seo' => 'seo/images',
            'og' => 'seo/og-images',
            'twitter' => 'seo/twitter-images',
        ],
        'url_resolver' => null,
    ],

    'completeness' => [
        'required' => ['meta_title', 'meta_description'],
    ],

    /* Resolve the current named route or exact request path when no selector is supplied. */
    'static_pages' => [
        'resolve_current_request' => false,

        'navigation' => [
            'group' => 'SEO',
            'label' => 'Static Pages',
            'icon' => 'heroicon-o-globe-alt',
            'sort' => 100,
            'enabled' => true,
        ],
    ],

    /* JSON-LD is opt-in per metadata record and optionally site-wide. */
    'structured_data' => [
        'additional_types' => [],
        // Each model mapping names attributes, never relationship paths.
        'model_mappings' => [],
        'site' => [
            'enabled' => false,
            'url' => null,
            'organization' => [
                'name' => null,
                'description' => null,
                'logo' => null,
                'same_as' => [],
            ],
            'website' => [
                'name' => null,
                'description' => null,
            ],
        ],
    ],

    /* Public XML sitemaps are disabled until an application opts in. */
    'sitemap' => [
        'enabled' => false,
        'path' => 'sitemap.xml',
        'static_pages' => [
            'enabled' => true,
            // Optional fn (SeoPage $page): bool host-owned inclusion rule.
            'filter' => null,
        ],
        // Explicit model class => trusted source options. See README.
        'models' => [],
        // Optional class names implementing SitemapSource for package extensions.
        'sources' => [],
        'chunk_size' => 10000,
        'model_chunk_size' => 500,
        'cache' => [
            'enabled' => false,
            'ttl' => 3600,
            // Optional fn (): string|int|null stable for the current content scope (for example tenant ID).
            'scope_resolver' => null,
        ],
    ],
];
