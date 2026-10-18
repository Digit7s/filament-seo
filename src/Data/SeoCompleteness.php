<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Data;

/**
 * Editorial metadata completeness result. Statuses are stable identifiers;
 * issue IDs can be translated by a consuming UI.
 *
 * @phpstan-type SeoCompletenessIssue array{id: string, field: string}
 */
final readonly class SeoCompleteness
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUS_NO_STORED_METADATA = 'no_stored_metadata';

    public const STATUS_NOT_LOADED = 'not_loaded';

    /**
     * @param  list<array{id: string, field: string}>  $issues
     * @param  list<string>  $requiredFields
     */
    public function __construct(
        public string $status,
        public array $issues,
        public bool $hasStoredMetadata,
        public array $requiredFields,
    ) {}

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }
}
