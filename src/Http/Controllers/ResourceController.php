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
        $resourceClass = $this->resourceResolver->resolveApiResourceForOperation($resource, 'index');

        return $this->indexResponder->respond($request, $resourceClass);
    }

    public function show(Request $request, string $resource, int|string $record): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResourceForOperation($resource, 'show');

        return $this->crudResponder->show($request, $resourceClass, $record);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResourceForOperation($resource, 'store');

        return $this->crudResponder->store($request, $resourceClass, $resource);
    }

    public function update(Request $request, string $resource, int|string $record): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResourceForOperation($resource, 'update');

        return $this->crudResponder->update($request, $resourceClass, $resource, $record);
    }

    public function destroy(Request $request, string $resource, int|string $record): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResourceForOperation($resource, 'destroy');

        return $this->crudResponder->destroy($request, $resourceClass, $resource, true, $record);
    }

    public function flow(Request $request, string $resource, int|string $record): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResourceForOperation($resource, 'flow');

        return $this->flowResponder->flow($request, $resourceClass, true, true, $record);
    }

    public function executeFlow(Request $request, string $resource, int|string $record, string $transition): JsonResponse
    {
        $resourceClass = $this->resourceResolver->resolveApiResourceForOperation($resource, 'execute-flow');

        return $this->flowResponder->executeFlow(
            $request,
            $resourceClass,
            true,
            true,
            $record,
            $transition
        );
    }
}
