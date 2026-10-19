<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use InvalidArgumentException;

/** Builds bounded XML documents over stable source iterators. */
final class SitemapGenerator
{
    /** A fixed safety ceiling keeps the exact cross-source deduplication set bounded. */
    public const MAX_TOTAL_URLS = 50000;

    /** Lower than the protocol maximum to bound per-request XML and entry buffers. */
    public const TARGET_DOCUMENT_BYTES = SitemapXmlWriter::MAX_GENERATED_BYTES;

    public function __construct(
        private readonly SitemapSourceRegistry $sources,
        private readonly SitemapUrlPolicy $urls,
        private readonly SitemapXmlWriter $writer,
    ) {}

    /** @return iterable<array{number:int, xml:string}> */
    public function documents(): iterable
    {
        $limit = config('filament-seo.sitemap.chunk_size', 10000);
        if (! is_int($limit) || $limit < 1 || $limit > SitemapXmlWriter::MAX_URLS) {
            throw new InvalidArgumentException('Sitemap chunk_size must be an integer between 1 and 50,000.');
        }

        $seen = [];
        $number = 1;
        $entries = [];
        $bytes = strlen($this->writer->urlSet([]));
        $count = 0;

        foreach ($this->candidateEntries() as $entry) {
            $key = hash('sha256', $this->urls->deduplicationKey($entry->location));
            if (isset($seen[$key])) {
                continue;
            }
            if (count($seen) >= self::MAX_TOTAL_URLS) {
                throw new InvalidArgumentException('Sitemap generation exceeds the package safety limit of 50,000 unique URLs.');
            }
            $seen[$key] = true;

            $entryXml = $this->writer->urlEntry($entry);
            $emptyBytes = strlen($this->writer->urlSet([]));
            if (strlen($entryXml) + $emptyBytes > SitemapXmlWriter::MAX_BYTES) {
                continue;
            }

            $candidateBytes = $bytes + strlen($entryXml);
            if ($count > 0 && ($count >= $limit || $candidateBytes > self::TARGET_DOCUMENT_BYTES)) {
                yield ['number' => $number++, 'xml' => $this->writer->urlSet($entries)];
                $entries = [];
                $count = 0;
                $bytes = $emptyBytes;
            }

            $entries[] = $entry;
            $count++;
            $bytes += strlen($entryXml);
        }

        if ($entries !== [] || $number === 1) {
            yield ['number' => $number, 'xml' => $this->writer->urlSet($entries)];
        }
    }

    /** @return iterable<SitemapEntry> */
    private function candidateEntries(): iterable
    {
        foreach ($this->sources->sources() as $source) {
            foreach ($source->entries() as $entry) {
                if ($entry instanceof SitemapEntry && ($location = $this->urls->fromCandidate($entry->location)) !== null) {
                    yield new SitemapEntry($location, $entry->lastModified);
                }
            }
        }
    }
}
