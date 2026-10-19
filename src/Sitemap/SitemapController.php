<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class SitemapController
{
    public function __invoke(): Response
    {
        return $this->respond('index', function (): string {
            // Keep the index and all numbered documents from one source snapshot together.
            app(SitemapCache::class)->clear();
            $generator = app(SitemapGenerator::class);
            $first = null;
            $locations = [];
            foreach ($generator->documents() as $document) {
                app(SitemapCache::class)->remember(
                    'chunk-'.$document['number'],
                    static fn (): string => $document['xml'],
                );
                if ($first === null) {
                    $first = $document['xml'];
                }
                $locations[] = $this->documentUrl($document['number']);
            }

            return count($locations) <= 1
                ? ($first ?? app(SitemapXmlWriter::class)->urlSet([]))
                : app(SitemapXmlWriter::class)->sitemapIndex($locations);
        });
    }

    public function chunk(Request $request, string $chunk): Response
    {
        if (! ctype_digit($chunk) || (int) $chunk < 1 || (int) $chunk > SitemapXmlWriter::MAX_URLS) {
            return response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $this->respond('chunk-'.$chunk, function () use ($chunk): string {
            foreach (app(SitemapGenerator::class)->documents() as $document) {
                if ($document['number'] === (int) $chunk) {
                    return $document['xml'];
                }
                if ($document['number'] > (int) $chunk) {
                    break;
                }
            }

            abort(SymfonyResponse::HTTP_NOT_FOUND);
        });
    }

    private function respond(string $key, callable $render): Response
    {
        try {
            $body = app(SitemapCache::class)->remember($key, $render);
            $headers = ['Content-Type' => 'application/xml; charset=UTF-8'];
            if (config('filament-seo.sitemap.cache.enabled', false)) {
                $headers['Cache-Control'] = 'public, max-age='.max(1, (int) config('filament-seo.sitemap.cache.ttl', 3600));
            }

            return response($body, SymfonyResponse::HTTP_OK, $headers);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 404) {
                return response('', SymfonyResponse::HTTP_NOT_FOUND);
            }

            Log::error('Unable to generate the configured XML sitemap.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response('Sitemap temporarily unavailable.', SymfonyResponse::HTTP_SERVICE_UNAVAILABLE, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }
    }

    private function documentUrl(int $number): string
    {
        $path = (string) config('filament-seo.sitemap.path', 'sitemap.xml');
        $dot = strrpos($path, '.xml');
        $chunkPath = $dot === false
            ? $path.'-'.$number.'.xml'
            : substr($path, 0, $dot).'-'.$number.'.xml';

        return app(SitemapUrlPolicy::class)->fromPath('/'.$chunkPath)
            ?? throw new \InvalidArgumentException('Could not safely construct a sitemap chunk URL.');
    }
}
