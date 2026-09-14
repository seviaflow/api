<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SeviaFlow\Core\Forms\FieldValidationBridge;
use SeviaFlow\Core\Operations\ResourceOperationService;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceContext;

final class ApiPayloadSanitizer
{
    public function __construct(
        private readonly WorkflowStateFieldGuard $workflowStateFieldGuard = new WorkflowStateFieldGuard,
        private readonly ApiValidationRules $apiValidationRules = new ApiValidationRules,
        private readonly FieldValidationBridge $fieldValidationBridge = new FieldValidationBridge,
        private readonly ResourceOperationService $operationService = new ResourceOperationService,
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizeForCreate(string $resourceClass, array $payload, ?Model $record = null): array
    {
        $api = $this->apiDefinition($resourceClass);
        $allowed = $this->workflowStateFieldGuard->removeStateFieldFromAllowed(
            $resourceClass,
            $api->getCreatableFields()
        );

        $this->workflowStateFieldGuard->ensurePayloadDoesNotWriteStateField($resourceClass, $payload);
        $this->ensureNoUnknownFields($payload, $allowed);
        $payload = $this->preparePayload($resourceClass, $payload);
        $this->validatePayload(
            payload: $payload,
            rules: $this->apiValidationRules->forCreate($resourceClass, $record, $payload),
            attributes: $this->attributesFor($resourceClass, ResourceContext::Create, $allowed, $record, $api)
        );

        return $this->onlyAllowed($payload, $allowed);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitizeForUpdate(string $resourceClass, array $payload, ?Model $record = null): array
    {
        $api = $this->apiDefinition($resourceClass);
        $allowed = $this->workflowStateFieldGuard->removeStateFieldFromAllowed(
            $resourceClass,
            $api->getUpdatableFields()
        );

        $this->workflowStateFieldGuard->ensurePayloadDoesNotWriteStateField($resourceClass, $payload);
        $this->ensureNoUnknownFields($payload, $allowed);
        $payload = $this->preparePayload($resourceClass, $payload);
        $fieldValues = is_array($record?->getAttributes())
            ? array_replace($record->getAttributes(), $payload)
            : $payload;
        $this->validatePayload(
            payload: $payload,
            rules: $this->apiValidationRules->forUpdate(
                resourceClass: $resourceClass,
                record: $record,
                fieldValues: $fieldValues,
                presentFields: array_keys($payload),
            ),
            attributes: $this->attributesFor($resourceClass, ResourceContext::Edit, $allowed, $record, $api)
        );

        return $this->onlyAllowed($payload, $allowed);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function apiDefinition(string $resourceClass): ApiResource
    {
        return $resourceClass::api(new ApiResource);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $allowed
     */
    private function ensureNoUnknownFields(array $payload, array $allowed): void
    {
        $allowedLookup = array_fill_keys($allowed, true);
        $errors = [];

        foreach (array_keys($payload) as $field) {
            if (! isset($allowedLookup[$field])) {
                $errors[$field] = sprintf('The [%s] field is not allowed.', $field);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<int, mixed>>  $rules
     * @param  array<string, string>  $attributes
     */
    private function validatePayload(array $payload, array $rules, array $attributes = []): void
    {
        $validator = Validator::make($payload, $rules, [], $attributes);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $allowed
     * @return array<string, mixed>
     */
    private function onlyAllowed(array $payload, array $allowed): array
    {
        $sanitized = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $payload)) {
                $sanitized[$field] = $payload[$field];
            }
        }

        return $sanitized;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preparePayload(string $resourceClass, array $payload): array
    {
        return $this->operationService->prepareForValidation(
            $this->operationService->fieldsForResource($resourceClass, array_keys($payload)),
            $payload,
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<int, string>  $allowed
     * @return array<string, string>
     */
    private function attributesFor(
        string $resourceClass,
        ResourceContext $context,
        array $allowed,
        ?Model $record,
        ApiResource $api
    ): array {
        if (! $api->usesFormFieldRules()) {
            return [];
        }

        return $this->fieldValidationBridge
            ->build($resourceClass, $context, $allowed, $record)['attributes'];
    }
}
