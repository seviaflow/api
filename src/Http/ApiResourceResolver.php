<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use SeviaFlow\Core\Exceptions\ResourceSlugNotRegisteredException;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceRegistry;

final class ApiResourceResolver
{
    /**
     * @return class-string<resource>
     */
    public function resolveApiResource(string $resource): string
    {
        [$resourceClass, $api] = $this->resolveApiResourceWithDefinition($resource);

        if (! $api->isEnabled()) {
            abort(404);
        }

        return $resourceClass;
    }

    /**
     * @return class-string<resource>
     */
    public function resolveApiResourceForOperation(string $resource, string $operation): string
    {
        [$resourceClass, $api] = $this->resolveApiResourceWithDefinition($resource);

        $allowed = match ($operation) {
            'index', 'show' => $api->isReadable(),
            'store' => $api->isCreatable(),
            'update' => $api->isUpdatable(),
            'destroy' => $api->allowsDelete(),
            'flow' => $api->isReadable() && $api->allowsFlow(),
            'execute-flow' => $api->allowsFlow(),
            default => false,
        };

        if (! $allowed) {
            abort(404);
        }

        return $resourceClass;
    }

    /**
     * @return array{0: class-string<resource>, 1: ApiResource}
     */
    public function resolveApiResourceWithDefinition(string $resource): array
    {
        try {
            $resourceClass = app(ResourceRegistry::class)->resolve($resource);
        } catch (ResourceSlugNotRegisteredException) {
            abort(404);
        }

        $api = $resourceClass::api(new ApiResource);

        return [$resourceClass, $api];
    }
}
