<?php

declare(strict_types=1);

namespace Digit7s\FilamentSeo\Sitemap;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SitemapRouteRegistrar
{
    public static function register(): void
    {
        $path = config('filament-seo.sitemap.path', 'sitemap.xml');
        if (! is_string($path) || strlen($path) > 200 || preg_match('~^(?!/)(?!.*//)[A-Za-z0-9._/-]+\.xml$~', $path) !== 1) {
            throw new InvalidArgumentException('Sitemap path must be a relative, safe route path ending in .xml.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Sitemap path cannot contain traversal segments.');
            }
        }

        $chunkPath = preg_replace('/\.xml$/', '-{chunk}.xml', $path);
        if (! is_string($chunkPath)) {
            throw new InvalidArgumentException('Could not construct sitemap chunk route.');
        }

        $routes = Route::getRoutes();
        $indexName = 'filament-seo.sitemap.index';
        $chunkName = 'filament-seo.sitemap.chunk';
        $existingIndex = $routes->getByName($indexName);
        $existingChunk = $routes->getByName($chunkName);

        if ($existingIndex !== null && $existingChunk !== null) {
            if ($existingIndex->uri() === $path
                && self::actionMatches($existingIndex, SitemapController::class, '__invoke')
                && $existingChunk->uri() === $chunkPath
                && self::actionMatches($existingChunk, SitemapController::class, 'chunk')) {
                return;
            }

            throw new LogicException('Existing routes use the reserved Filament SEO sitemap route names.');
        }
        if ($existingIndex !== null || $existingChunk !== null) {
            throw new LogicException('Only part of the Filament SEO sitemap route set already exists.');
        }

        foreach ([[$path, 'filament-seo.sitemap.index'], [$chunkPath, 'filament-seo.sitemap.chunk']] as [$uri, $name]) {
            if ($routes->getByName($name) !== null || self::matchedRouteExists('/'.$uri, $uri === $chunkPath)) {
                throw new LogicException("Sitemap route [{$uri}] conflicts with an existing application route.");
            }
        }

        Route::get($path, ['uses' => SitemapController::class, 'as' => 'filament-seo.sitemap.index']);
        Route::get($chunkPath, ['uses' => SitemapController::class.'@chunk', 'as' => 'filament-seo.sitemap.chunk'])
            ->whereNumber('chunk');
        $routes->refreshNameLookups();
    }

    private static function matchedRouteExists(string $uri, bool $chunk): bool
    {
        $probe = $chunk ? str_replace('{chunk}', '1', $uri) : $uri;
        try {
            Route::getRoutes()->match(Request::create($probe, 'GET'));

            return true;
        } catch (NotFoundHttpException) {
            return false;
        }
    }

    private static function actionMatches(\Illuminate\Routing\Route $route, string $controller, string $method): bool
    {
        $uses = $route->getAction('uses');

        return $uses === $controller.'@'.$method || ($method === '__invoke' && $uses === $controller);
    }
}
