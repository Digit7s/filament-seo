<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Data;

/** Immutable metadata-quality score and the rules used to calculate it. */
final readonly class SeoScoreResult
{
    public const STATUS_GOOD = 'good';

    public const STATUS_NEEDS_IMPROVEMENT = 'needs_improvement';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_NOT_LOADED = 'not_loaded';

    public const STATUS_EXCLUDED = 'excluded';

    /**
     * @param  list<string>  $findings  Stable rule identifiers with issues.
     * @param  list<string>  $applicableRules
     * @param  list<string>  $skippedRules
     */
    public function __construct(
        public ?int $score,
        public string $status,
        public array $findings = [],
        public array $applicableRules = [],
        public array $skippedRules = [],
    ) {}

    public static function notConfigured(): self
    {
        return new self(score: null, status: self::STATUS_NOT_CONFIGURED);
    }

    public static function notLoaded(): self
    {
        return new self(score: null, status: self::STATUS_NOT_LOADED);
    }

    public function label(): string
    {
        if ($this->status === self::STATUS_NOT_CONFIGURED || $this->status === self::STATUS_NOT_LOADED) {
            return __('filament-seo::filament-seo.statuses.seo_score.'.$this->status);
        }

        return __('filament-seo::filament-seo.statuses.seo_score.'.$this->status, [
            'score' => $this->score,
        ]);
    }

    public function color(): string
    {
        return match ($this->status) {
            self::STATUS_GOOD => 'success',
            self::STATUS_NEEDS_IMPROVEMENT => ($this->score ?? 0) < 50 ? 'danger' : 'warning',
            default => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this->status) {
            self::STATUS_GOOD => 'heroicon-o-check-circle',
            self::STATUS_NEEDS_IMPROVEMENT => 'heroicon-o-exclamation-triangle',
            self::STATUS_EXCLUDED => 'heroicon-o-no-symbol',
            default => 'heroicon-o-minus-circle',
        };
    }

    /** A plain-text summary; finding values are package translations, not metadata HTML. */
    public function summary(): string
    {
        if ($this->status === self::STATUS_NOT_CONFIGURED || $this->status === self::STATUS_NOT_LOADED) {
            return __('filament-seo::filament-seo.messages.seo_score.'.$this->status);
        }

        $summary = __('filament-seo::filament-seo.messages.seo_score.summary', [
            'status' => $this->label(),
            'score' => $this->score,
        ]);

        if ($this->findings === []) {
            return $summary.' '.__('filament-seo::filament-seo.messages.seo_score.no_findings');
        }

        $findings = array_map(
            static fn (string $finding): string => __('filament-seo::filament-seo.issues.seo_score.'.$finding),
            $this->findings,
        );

        return $summary.' '.implode(' ', $findings);
    }
}
