<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use InvalidArgumentException;
use XMLWriter;

final class SitemapXmlWriter
{
    public const NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public const MAX_URLS = 50000;

    public const MAX_BYTES = 52428800;

    public const MAX_GENERATED_BYTES = 8388608;

    public function urlSet(iterable $entries): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', self::NAMESPACE);
        $bytes = strlen($xml->outputMemory(false)) + strlen('</urlset>');

        foreach ($entries as $entry) {
            $entryXml = $this->urlEntry($entry);
            if ($bytes + strlen($entryXml) > self::MAX_GENERATED_BYTES) {
                throw new InvalidArgumentException('Generated sitemap exceeds the conservative 8 MB package output limit.');
            }

            $xml->startElement('url');
            $xml->writeElement('loc', $entry->location);
            if (($lastModified = SitemapDate::format($entry->lastModified)) !== null) {
                $xml->writeElement('lastmod', $lastModified);
            }
            $xml->endElement();
            $bytes += strlen($entryXml);
        }

        $xml->endElement();
        $xml->endDocument();
        $document = $xml->outputMemory();
        if (strlen($document) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Generated sitemap exceeds the 50 MB uncompressed protocol limit.');
        }

        return $document;
    }

    public function urlEntry(SitemapEntry $entry): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startElement('url');
        $xml->writeElement('loc', $entry->location);
        if (($lastModified = SitemapDate::format($entry->lastModified)) !== null) {
            $xml->writeElement('lastmod', $lastModified);
        }
        $xml->endElement();

        return $xml->outputMemory();
    }

    /** @param list<string> $locations */
    public function sitemapIndex(array $locations): string
    {
        if (count($locations) > self::MAX_URLS) {
            throw new InvalidArgumentException('Generated sitemap index exceeds the 50,000 sitemap limit.');
        }

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', self::NAMESPACE);
        $bytes = strlen($xml->outputMemory(false)) + strlen('</sitemapindex>');
        foreach ($locations as $location) {
            $entryXml = $this->sitemapEntry($location);
            if ($bytes + strlen($entryXml) > self::MAX_GENERATED_BYTES) {
                throw new InvalidArgumentException('Generated sitemap index exceeds the conservative 8 MB package output limit.');
            }

            $xml->startElement('sitemap');
            $xml->writeElement('loc', $location);
            $xml->endElement();
            $bytes += strlen($entryXml);
        }
        $xml->endElement();
        $xml->endDocument();
        $document = $xml->outputMemory();
        if (strlen($document) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Generated sitemap index exceeds the 50 MB uncompressed protocol limit.');
        }

        return $document;
    }

    private function sitemapEntry(string $location): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startElement('sitemap');
        $xml->writeElement('loc', $location);
        $xml->endElement();

        return $xml->outputMemory();
    }
}
