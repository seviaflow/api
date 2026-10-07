<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use SeviaFlow\Api\ApiPayloadSanitizer;
use SeviaFlow\Api\ApiResourceSerializer;
use SeviaFlow\Core\Operations\ResourceOperationContext;
use SeviaFlow\Core\Operations\ResourceOperationService;
use SeviaFlow\Core\Resources\Fields\Field;
use SeviaFlow\Core\Resources\ResourceContext;
use SeviaFlow\Core\Resources\ResourceEventContext;
use SeviaFlow\Core\Resources\ResourceEventDispatcher;
use Throwable;

final class ApiCrudResponder
{
    public function __construct(
        private readonly ApiResourceSerializer $serializer = new ApiResourceSerializer,
        private readonly ApiPayloadSanitizer $payloadSanitizer = new ApiPayloadSanitizer,
        private readonly ApiRecordResolver $recordResolver = new ApiRecordResolver,
        private readonly ResourceEventDispatcher $resourceEventDispatcher = new ResourceEventDispatcher,
        private readonly ApiErrorResponder $apiErrorResponder = new ApiErrorResponder,
        private readonly ApiAuthorization $authorization = new ApiAuthorization,
        private readonly ResourceOperationService $operationService = new ResourceOperationService,
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
            $this->operationService->execute(
                resourceClass: $resourceClass,
                resourceSlug: $resourceSlug,
                model: $record,
                fields: $this->operationFields($resourceClass, $payload),
                values: $payload,
                context: ResourceContext::Create,
                operationContext: $this->operationContext($request),
            );
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->apiErrorResponder->validationError('event', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->apiErrorResponder->validationError('operation', 'The operation could not be completed.');
        }

        return response()->json([
            'data' => $this->serializer->serialize($resourceClass, $record->refresh()),
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
        try {
            $this->operationService->execute(
                resourceClass: $resourceClass,
                resourceSlug: $resourceSlug,
                model: $found,
                fields: $this->operationFields($resourceClass, $payload),
                values: $payload,
                context: ResourceContext::Edit,
                operationContext: $this->operationContext($request),
            );
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->apiErrorResponder->validationError('event', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->apiErrorResponder->validationError('operation', 'The operation could not be completed.');
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

        $original = is_array($found->getOriginal()) ? $found->getOriginal() : [];
        try {
            $found->getConnection()->transaction(function () use (
                $request,
                $resourceClass,
                $resourceSlug,
                $found,
                $original
            ): void {
                $operationContext = $this->operationContext($request);
                $this->resourceEventDispatcher->dispatch(
                    resourceClass: $resourceClass,
                    event: ResourceEventContext::OPERATION_DELETING,
                    record: $found,
                    context: $this->eventContext(
                        resourceClass: $resourceClass,
                        resourceSlug: $resourceSlug,
                        operation: ResourceEventContext::OPERATION_DELETING,
                        record: $found,
                        original: $original,
                        changes: ['deleted' => true],
                        operationContext: $operationContext,
                        result: 'pending',
                    ),
                );
                $found->delete();
                $this->resourceEventDispatcher->dispatchAfterCommit(
                    resourceClass: $resourceClass,
                    event: ResourceEventContext::OPERATION_DELETED,
                    record: $found,
                    context: $this->eventContext(
                        resourceClass: $resourceClass,
                        resourceSlug: $resourceSlug,
                        operation: ResourceEventContext::OPERATION_DELETED,
                        record: $found,
                        original: $original,
                        changes: ['deleted' => true],
                        operationContext: $operationContext,
                        result: 'committed',
                    ),
                );
            });
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->apiErrorResponder->validationError('event', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->apiErrorResponder->validationError('operation', 'The operation could not be completed.');
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
     * @return array<string, mixed>
     */
    private function requestPayload(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        return $payload;
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     * @param  array<string, mixed>  $payload
     * @return array<int, Field>
     */
    private function operationFields(string $resourceClass, array $payload): array
    {
        return $this->operationService->fieldsForResource($resourceClass, array_keys($payload));
    }

    private function operationContext(Request $request): ResourceOperationContext
    {
        return new ResourceOperationContext(
            actor: $this->actor($request),
            source: ResourceEventContext::SOURCE_API,
            request: $request,
        );
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $changes
     */
    private function eventContext(
        string $resourceClass,
        string $resourceSlug,
        string $operation,
        Model $record,
        array $original,
        array $changes,
        ResourceOperationContext $operationContext,
        string $result,
    ): ResourceEventContext {
        return new ResourceEventContext(
            user: $operationContext->actor(),
            resourceClass: $resourceClass,
            resourceSlug: $resourceSlug,
            operation: $operation,
            source: $operationContext->source(),
            request: $operationContext->request(),
            original: $original,
            changes: $changes,
            metadata: array_merge($operationContext->metadata(), [
                'record_key' => $record->getKey(),
                'result' => $result,
            ]),
            connection: $record->getConnectionName() ?? $record->getConnection()->getName(),
            correlationId: $operationContext->correlationId(),
        );
    }
}
