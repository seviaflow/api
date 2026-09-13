<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Support\ServiceProvider;
use SeviaFlow\Api\Http\Middleware\RequireApiLicense;
use SeviaFlow\Api\OpenApi\OpenApiCache;
use SeviaFlow\Api\OpenApi\OpenApiGenerator;
use SeviaFlow\Api\Support\Cache\OpenApiCacheParticipant;
use SeviaFlow\Core\Support\Cache\CacheParticipant;
use SeviaFlow\Core\Support\Capabilities\CapabilityRegistry;

final class SeviaFlowApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/seviaflow-api.php', 'seviaflow-api');

        $this->app->singleton(OpenApiCache::class, fn ($app): OpenApiCache => new OpenApiCache($app->make(OpenApiGenerator::class)));
        $this->app->singleton(OpenApiCacheParticipant::class);
        $this->app->tag([OpenApiCacheParticipant::class], CacheParticipant::class);
    }

    public function boot(CapabilityRegistry $capabilities): void
    {
        $this->app->make('router')->aliasMiddleware('seviaflow.api.license', RequireApiLicense::class);
        $capabilities->register('api', (bool) config('seviaflow-api.enabled', true), 'Headless REST API');
        $capabilities->register('openapi', (bool) config('seviaflow-api.openapi.enabled', true), 'OpenAPI schema generation');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'seviaflow-api');

        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/seviaflow-api.php' => config_path('seviaflow-api.php'),
        ], 'seviaflow-api-config');
    }
}
