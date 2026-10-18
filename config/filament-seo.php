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
];
