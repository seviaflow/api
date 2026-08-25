<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SeviaFlow\Api\Http\ApiCrudResponder;
use SeviaFlow\Api\Http\ApiFlowResponder;
use SeviaFlow\Api\Http\ApiIndexResponder;
use SeviaFlow\Api\Http\ApiResourceResolver;

final class ResourceController
{
    public function __construct(
        private readonly ApiResourceResolver $resourceResolver = new ApiResourceResolver,
        private readonly ApiIndexResponder $indexResponder = new ApiIndexResponder,
        private readonly ApiCrudResponder $crudResponder = new ApiCrudResponder,
        private readonly ApiFlowResponder $flowResponder = new ApiFlowResponder
    ) {}

    public function index(Request $request, string $resource): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResource($resource);

        return $this->indexResponder->respond($request, $resourceClass);
    }

    public function show(Request $request, string $resource, int|string $record): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResource($resource);

        return $this->crudResponder->show($request, $resourceClass, $record);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResource($resource);

        return $this->crudResponder->store($request, $resourceClass, $resource);
    }

    public function update(Request $request, string $resource, int|string $record): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResource($resource);

        return $this->crudResponder->update($request, $resourceClass, $resource, $record);
    }

    public function destroy(Request $request, string $resource, int|string $record): JsonResponse
    {
        [$resourceClass, $api] = $this->resourceResolver->resolveApiResourceWithDefinition($resource);

        return $this->crudResponder->destroy($request, $resourceClass, $resource, $api->isDeletable(), $record);
    }

    public function flow(Request $request, string $resource, int|string $record): JsonResponse
    {
        [$resourceClass, $api] = $this->resourceResolver->resolveApiResourceWithDefinition($resource);

        return $this->flowResponder->flow($request, $resourceClass, $api->isEnabled(), $api->isFlowEnabled(), $record);
    }

    public function executeFlow(Request $request, string $resource, int|string $record, string $transition): JsonResponse
    {
        [$resourceClass, $api] = $this->resourceResolver->resolveApiResourceWithDefinition($resource);

        return $this->flowResponder->executeFlow(
            $request,
            $resourceClass,
            $api->isEnabled(),
            $api->isFlowEnabled(),
            $record,
            $transition
        );
    }
}
