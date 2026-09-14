<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SeviaFlow\Api\ApiPayloadSanitizer;
use SeviaFlow\Api\ApiResourceSerializer;
use SeviaFlow\Core\Resources\ResourceEventContext;
use SeviaFlow\Core\Resources\ResourceEventDispatcher;

final class ApiCrudResponder
{
    public function __construct(
        private readonly ApiResourceSerializer $serializer = new ApiResourceSerializer,
        private readonly ApiPayloadSanitizer $payloadSanitizer = new ApiPayloadSanitizer,
        private readonly ApiRecordResolver $recordResolver = new ApiRecordResolver,
        private readonly ResourceEventDispatcher $resourceEventDispatcher = new ResourceEventDispatcher,
        private readonly ApiErrorResponder $apiErrorResponder = new ApiErrorResponder,
        private readonly ApiAuthorization $authorization = new ApiAuthorization
    ) {}

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function show(Request $request, string $resourceClass, int|string $record): JsonResponse
    {
        $found = $this->recordResolver->resolve($resourceClass, $record);
        if (! $found instanceof Model) {
            abort(404);
        }

        if (! $this->authorization->allows($request, $resourceClass, 'view', $found)) {
            abort(403);
        }

        return response()->json([
            'data' => $this->serializer->serialize($resourceClass, $found),
        ]);
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function store(Request $request, string $resourceClass, string $resourceSlug): JsonResponse
    {
        if (! $this->authorization->allows($request, $resourceClass, 'create')) {
            abort(403);
        }

        $payload = $this->payloadSanitizer->sanitizeForCreate($resourceClass, $this->requestPayload($request));
        $record = $this->newResourceModel($resourceClass);
        try {
            DB::transaction(function () use ($request, $resourceClass, $resourceSlug, $record, $payload): void {
                $this->dispatch($request, $resourceClass, $resourceSlug, ResourceEventContext::OPERATION_CREATING, $record, [], $payload);
                $this->fillAndSave($record, $payload);
                $this->dispatch($request, $resourceClass, $resourceSlug, ResourceEventContext::OPERATION_CREATED, $record, [], $payload);
            });
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->apiErrorResponder->validationError('event', $exception->getMessage());
        }

        return response()->json([
            'data' => $this->serializer->serialize($resourceClass, $record),
        ], 201);
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function update(Request $request, string $resourceClass, string $resourceSlug, int|string $record): JsonResponse
    {
        $found = $this->recordResolver->resolve($resourceClass, $record);
        if (! $found instanceof Model) {
            abort(404);
        }

        if (! $this->authorization->allows($request, $resourceClass, 'update', $found)) {
            abort(403);
        }

        $payload = $this->payloadSanitizer->sanitizeForUpdate($resourceClass, $this->requestPayload($request), $found);
        $original = $found->getOriginal();
        try {
            DB::transaction(function () use ($request, $resourceClass, $resourceSlug, $found, $payload, $original): void {
                $this->dispatch($request, $resourceClass, $resourceSlug, ResourceEventContext::OPERATION_UPDATING, $found, is_array($original) ? $original : [], $payload);
                $this->fillAndSave($found, $payload);
                $this->dispatch($request, $resourceClass, $resourceSlug, ResourceEventContext::OPERATION_UPDATED, $found, is_array($original) ? $original : [], $payload);
            });
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->apiErrorResponder->validationError('event', $exception->getMessage());
        }

        return response()->json([
            'data' => $this->serializer->serialize($resourceClass, $found->refresh()),
        ]);
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function destroy(Request $request, string $resourceClass, string $resourceSlug, bool $deletable, int|string $record): JsonResponse
    {
        $found = $this->recordResolver->resolve($resourceClass, $record);
        if (! $found instanceof Model) {
            abort(404);
        }

        if (! $deletable) {
            abort(404);
        }

        if (! $this->authorization->allows($request, $resourceClass, 'delete', $found)) {
            abort(403);
        }

        $original = $found->getOriginal();
        try {
            DB::transaction(function () use ($request, $resourceClass, $resourceSlug, $found, $original): void {
                $normalizedOriginal = is_array($original) ? $original : [];
                $this->dispatch($request, $resourceClass, $resourceSlug, ResourceEventContext::OPERATION_DELETING, $found, $normalizedOriginal, ['deleted' => true]);
                $found->delete();
                $this->dispatch($request, $resourceClass, $resourceSlug, ResourceEventContext::OPERATION_DELETED, $found, $normalizedOriginal, ['deleted' => true]);
            });
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->apiErrorResponder->validationError('event', $exception->getMessage());
        }

        return response()->json([
            'message' => 'Record deleted successfully.',
        ]);
    }

    private function actor(Request $request): ?Authenticatable
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    private function newResourceModel(string $resourceClass): Model
    {
        $modelClass = $resourceClass::model();

        return new $modelClass;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fillAndSave(Model $model, array $payload): void
    {
        foreach ($payload as $field => $value) {
            $model->setAttribute($field, $value);
        }

        $model->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $original
     * @param  array<string,mixed>  $changes
     */
    private function dispatch(
        Request $request,
        string $resourceClass,
        string $resourceSlug,
        string $operation,
        Model $record,
        array $original,
        array $changes
    ): void {
        $this->resourceEventDispatcher->dispatch(
            resourceClass: $resourceClass,
            event: $operation,
            record: $record,
            context: new ResourceEventContext(
                user: $this->actor($request),
                resourceClass: $resourceClass,
                resourceSlug: $resourceSlug,
                operation: $operation,
                source: ResourceEventContext::SOURCE_API,
                request: $request,
                original: $original,
                changes: $changes,
                metadata: []
            )
        );
    }
}
