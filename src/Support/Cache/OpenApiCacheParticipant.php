<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Support\Cache;

use SeviaFlow\Api\OpenApi\OpenApiCache;
use SeviaFlow\Core\Support\Cache\CacheOperationResult;
use SeviaFlow\Core\Support\Cache\CacheParticipant;

final class OpenApiCacheParticipant implements CacheParticipant
{
    public function __construct(
        private readonly OpenApiCache $cache
    ) {}

    public function name(): string
    {
        return 'OpenAPI schema';
    }

    public function warm(): CacheOperationResult
    {
        if (! $this->cacheEnabled()) {
            return CacheOperationResult::skipped('OpenAPI cache skipped (disabled)');
        }

        $this->cache->generate();

        return CacheOperationResult::done('OpenAPI schema cached');
    }

    public function clear(): CacheOperationResult
    {
        $this->cache->clear();

        return CacheOperationResult::done('OpenAPI cache cleared');
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('seviaflow.performance.cache.enabled', false)
            && (bool) config('seviaflow-api.openapi.cache.enabled', true);
    }
}
