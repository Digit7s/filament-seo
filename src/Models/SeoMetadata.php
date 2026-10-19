<?php

namespace Digit7s\FilamentSeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SeoMetadata extends Model
{
    protected $table = 'seo_metadata';

    /** @var list<string> */
    protected $fillable = [
        'meta_title',
        'meta_description',
        'keywords',
        'canonical_url',
        'seo_image',
        'seo_image_alt',
        'og_title',
        'og_description',
        'og_image',
        'og_image_alt',
        'twitter_title',
        'twitter_description',
        'twitter_image',
        'twitter_image_alt',
        'robots_index',
        'robots_follow',
        'structured_data_enabled',
        'structured_data_type',
        'structured_data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
            'structured_data_enabled' => 'boolean',
            'structured_data' => 'array',
        ];
    }

    /** Normalize empty Filament fields out of the persisted JSON configuration. */
    public function setStructuredDataAttribute(mixed $value): void
    {
        if (! is_array($value)) {
            $this->attributes['structured_data'] = null;

            return;
        }

        $value = $this->removeEmptyStructuredDataValues($value);
        $this->attributes['structured_data'] = $value === []
            ? null
            : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function removeEmptyStructuredDataValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = $this->removeEmptyStructuredDataValues($value);
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            if ($value === null || $value === '' || $value === []) {
                unset($values[$key]);
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /** @return MorphTo<Model, $this> */
    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }
}
