<?php

declare(strict_types=1);

namespace SeviaFlow\Api\OpenApi;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

final class OpenApiCache
{
    private const CACHE_KEY = 'seviaflow.openapi';

    public function __construct(
        private readonly OpenApiGenerator $generator
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        if (! (bool) config('seviaflow-api.enabled', true)) {
            return $this->emptySpec();
        }

        if (! $this->isEnabled()) {
            return $this->generator->generate();
        }

        $cache = $this->cache();

        /** @var array<string, mixed> $spec */
        $spec = $cache->remember(self::CACHE_KEY, $this->ttl(), fn (): array => $this->generator->generate());

        return $spec;
    }

    public function clear(): void
    {
        $this->cache()->forget(self::CACHE_KEY);
    }

    private function isEnabled(): bool
    {
        return (bool) config('seviaflow.performance.cache.enabled', false)
            && (bool) config('seviaflow-api.openapi.cache.enabled', true);
    }

    private function ttl(): int
    {
        return max(1, (int) config('seviaflow.performance.cache.ttl', 3600));
    }

    private function cache(): CacheRepository
    {
        $store = config('seviaflow.performance.cache.store');
        if (! is_string($store) || trim($store) === '') {
            return cache()->store();
        }

        return cache()->store($store);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySpec(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SeviaFlow API',
                'version' => '1.0.0',
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                    ],
                ],
            ],
        ];
    }
}
