<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use SeviaFlow\Api\Http\Controllers\ResourceController;
use SeviaFlow\Api\OpenApi\OpenApiCache;

/** @var string $routeNamePrefix */
$routeNamePrefix = (string) config('seviaflow-api.route_name_prefix', 'seviaflow.api.');

/**
 * @return array<int, string>
 */
$resolveApiMiddleware = static function (): array {
    /** @var mixed $apiMiddleware */
    $apiMiddleware = config('seviaflow-api.middleware');

    if (is_array($apiMiddleware) && $apiMiddleware !== []) {
        return array_values(array_filter($apiMiddleware, static fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    return ['api', 'auth:sanctum'];
};

if ((bool) config('seviaflow-api.enabled', true)) {
    $apiMiddleware = $resolveApiMiddleware();
    if ((bool) config('seviaflow-api.throttle.enabled', true)) {
        $apiMiddleware[] = 'throttle:seviaflow.api';
    }

    Route::prefix((string) config('seviaflow-api.prefix', 'api/admin'))
        ->as($routeNamePrefix)
        ->middleware(array_merge(array_values(array_unique($apiMiddleware)), ['seviaflow.api.license']))
        ->group(function (): void {
            Route::get('/resources/{resource}', [ResourceController::class, 'index'])
                ->name('resources.index');
            Route::post('/resources/{resource}', [ResourceController::class, 'store'])
                ->name('resources.store');
            Route::get('/resources/{resource}/{record}', [ResourceController::class, 'show'])
                ->name('resources.show');
            Route::get('/resources/{resource}/{record}/files/{field}', [ResourceController::class, 'file'])
                ->name('resources.files.download');
            Route::get('/resources/{resource}/{record}/flow', [ResourceController::class, 'flow'])
                ->name('resources.flow');
            Route::post('/resources/{resource}/{record}/flow/{transition}', [ResourceController::class, 'executeFlow'])
                ->name('resources.flow.execute');
            Route::patch('/resources/{resource}/{record}', [ResourceController::class, 'update'])
                ->name('resources.update');
            Route::delete('/resources/{resource}/{record}', [ResourceController::class, 'destroy'])
                ->name('resources.destroy');
        });
}

if ((bool) config('seviaflow-api.openapi.enabled', true)) {
    $docsRoute = trim((string) config('seviaflow-api.openapi.route', 'openapi.json'), '/');
    $docsMiddleware = config('seviaflow-api.openapi.middleware', []);
    $resolvedDocsMiddleware = is_array($docsMiddleware)
        ? array_values(array_filter($docsMiddleware, static fn (mixed $value): bool => is_string($value) && $value !== ''))
        : [];

    Route::prefix((string) config('seviaflow-api.prefix', 'api/admin'))
        ->as($routeNamePrefix)
        ->middleware(array_merge($resolvedDocsMiddleware, ['seviaflow.api.license']))
        ->group(function () use ($docsRoute): void {
            Route::get('/'.$docsRoute, static function (): JsonResponse {
                return response()->json(app(OpenApiCache::class)->generate());
            })->name('openapi');
        });
}

if ((bool) config('seviaflow-api.docs.enabled', true)) {
    $docsRoute = trim((string) config('seviaflow-api.docs.route', 'api-docs'), '/');
    $docsMiddleware = config('seviaflow-api.docs.middleware', []);
    $resolvedDocsMiddleware = is_array($docsMiddleware)
        ? array_values(array_filter($docsMiddleware, static fn (mixed $value): bool => is_string($value) && $value !== ''))
        : [];

    Route::prefix((string) config('seviaflow-api.prefix', 'api/admin'))
        ->as($routeNamePrefix)
        ->middleware(array_merge($resolvedDocsMiddleware, ['seviaflow.api.license']))
        ->group(function () use ($docsRoute): void {
            Route::view('/'.$docsRoute, (string) config('seviaflow-api.docs.view', 'seviaflow-api::api-docs'))
                ->name('docs');
        });
}
