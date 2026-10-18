<?php

namespace Digit7s\FilamentSeo\Concerns;

use Digit7s\FilamentSeo\Models\SeoMetadata;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use ReflectionClass;

trait HasSeoMetadata
{
    public function seoMetadata(): MorphOne
    {
        $metadataModel = $this->seoMetadataModel();

        $this->assertCompatibleSeoMetadataModel($metadataModel);

        return $this->morphOne($metadataModel, 'seoable');
    }

    /**
     * Override on an owner model to select its application-owned metadata model.
     * The global config value is used when this method is not overridden.
     *
     * @return class-string<Model>
     */
    protected function seoMetadataModel(): string
    {
        $model = config('filament-seo.model', SeoMetadata::class);

        if (! is_string($model) || $model === '') {
            throw new InvalidArgumentException(
                'The [filament-seo.model] configuration value must be an Eloquent model class name.'
            );
        }

        return $model;
    }

    /**
     * @param  class-string  $modelClass
     */
    private function assertCompatibleSeoMetadataModel(string $modelClass): void
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException(sprintf(
                'SEO metadata model [%s] must exist and extend [%s].',
                $modelClass,
                Model::class,
            ));
        }

        if (! (new ReflectionClass($modelClass))->isInstantiable()) {
            throw new InvalidArgumentException(sprintf(
                'SEO metadata model [%s] must be instantiable.',
                $modelClass,
            ));
        }

        /** @var Model $model */
        $model = new $modelClass;

        $incompatibleProperties = [];

        if ($model->getTable() !== 'seo_metadata') {
            $incompatibleProperties[] = 'table name seo_metadata';
        }

        if ($model->getKeyName() !== 'id' || ! $model->getIncrementing() || $model->getKeyType() !== 'int') {
            $incompatibleProperties[] = 'incrementing integer id primary key';
        }

        if (! $model->usesTimestamps()) {
            $incompatibleProperties[] = 'Eloquent timestamps';
        }

        foreach ([
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
        ] as $attribute) {
            if (! $model->isFillable($attribute)) {
                $incompatibleProperties[] = sprintf('fillable attribute %s', $attribute);
            }
        }

        $casts = $model->getCasts();

        foreach (['robots_index', 'robots_follow'] as $attribute) {
            if (! in_array($casts[$attribute] ?? null, ['bool', 'boolean'], true)) {
                $incompatibleProperties[] = sprintf('boolean cast for %s', $attribute);
            }
        }

        if (! method_exists($model, 'seoable') || ! ($model->seoable() instanceof MorphTo)) {
            $incompatibleProperties[] = 'seoable(): MorphTo inverse relation';
        }

        if ($incompatibleProperties !== []) {
            throw new InvalidArgumentException(sprintf(
                'SEO metadata model [%s] does not match the package storage contract: %s.',
                $modelClass,
                implode(', ', $incompatibleProperties),
            ));
        }
    }
}
