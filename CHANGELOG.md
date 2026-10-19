# Changelog

All notable changes to `digit7s/filament-seo` are documented here.

## [0.2.0] - 2026-10-19

### Added

- Add an optional `SeoPage` registry and Filament resource for static-route SEO metadata.
- Reuse the existing polymorphic metadata storage, resolver, completeness analysis, scoring, and Blade renderer.
- Add explicit page-key and named-route Blade selectors, plus opt-in exact current-route/path matching.
- Add an additive `seo_pages` migration; existing `seo_metadata` rows are unchanged.
- Add configurable Static Pages navigation group, label, icon, sort order, and visibility with Filament 5 defaults.
- Add opt-in JSON-LD storage on shared SEO metadata rows for Eloquent and static page owners.
- Add a shared generator for WebPage, AboutPage, ContactPage, WebSite, Organization, Article, NewsArticle, and BreadcrumbList objects.
- Add the explicit `<x-filament-seo::json-ld>` renderer, optional site identity graph, model attribute mappings, validation, and a live Filament preview.
- Add an additive migration for structured-data fields; existing records remain disabled and model-based SEO rendering remains unchanged.
- Add opt-in XML sitemap routes for active Static Pages and explicitly configured Eloquent model sources.
- Add same-origin canonical URL policy, robots-index eligibility, deduplication, XMLWriter escaping, protocol limits with conservative 8 MB output and 50,000 total URL safety ceilings, sitemap indexes, optional TTL caching, and host-controlled publishing filters.
- Add stable UUID-compatible keyset model iteration with eager-loaded SEO metadata and explicit source extension contract.

### Improved

- Enforce registered Gate abilities and model policies for Static Page SEO resource access.
- Keep the JSON-LD preview current while editing, validate only the selected schema's properties, and handle related metadata records safely.
- Accept an empty URL for the final breadcrumb item while continuing to reject unsafe breadcrumb URLs.
- Omit invalid and relative sitemap modification dates instead of generating misleading `lastmod` values.
- Add an optional trusted sitemap cache scope resolver for tenant-aware source isolation.

### Notes

- Static Page registration, JSON-LD rendering, and sitemap routes require explicit application setup; sitemap routes and structured data are opt-in.
- Sitemap model sources are explicitly configured and rely on host-owned publication/private-content query rules.
- Sitemap cache freshness for host model changes follows its TTL or explicit invalidation.
- Request-time sitemap generation is capped at 50,000 unique URLs, with generated documents targeted below 8 MiB.
- Existing Model SEO integration and metadata rows remain supported; database changes are additive.

## [Unreleased]

## 0.1.0 - 2026-10-19

- Add an explicitly placed Blade component for rendering resolved SEO metadata.
- Add safe HTML escaping, HTTPS canonical validation, robots directives, Open Graph and Twitter card output.
- Omit images from private or non-public storage and when a public-disk file is missing.
- Add consumer News listing and detail pages demonstrating the renderer.
- Preserve existing Filament forms, tabs, status, score, infolist, and localization APIs.
