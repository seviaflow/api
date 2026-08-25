<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Database\Eloquent\Model;
use SeviaFlow\Core\Forms\FieldValidationBridge;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceContext;

final class ApiValidationRules
{
    public function __construct(
        private readonly WorkflowStateFieldGuard $workflowStateFieldGuard = new WorkflowStateFieldGuard,
        private readonly FieldValidationBridge $fieldValidationBridge = new FieldValidationBridge
    ) {}

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, array<int, string>>
     */
    public function forCreate(string $resourceClass, ?Model $record = null): array
    {
        $api = $this->apiDefinition($resourceClass);
        $allowed = $this->workflowStateFieldGuard->removeStateFieldFromAllowed(
            $resourceClass,
            $api->getCreatableFields()
        );
        $required = $this->workflowStateFieldGuard->removeStateFieldFromAllowed(
            $resourceClass,
            $api->getRequiredOnCreateFields()
        );

        return $this->buildRules(
            allowed: $allowed,
            required: $required,
            resourceClass: $resourceClass,
            context: ResourceContext::Create,
            withFieldRules: $api->usesFormFieldRules(),
            record: $record
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, array<int, string>>
     */
    public function forUpdate(string $resourceClass, ?Model $record = null): array
    {
        $api = $this->apiDefinition($resourceClass);
        $allowed = $this->workflowStateFieldGuard->removeStateFieldFromAllowed(
            $resourceClass,
            $api->getUpdatableFields()
        );
        $required = $this->workflowStateFieldGuard->removeStateFieldFromAllowed(
            $resourceClass,
            $api->getRequiredOnUpdateFields()
        );

        return $this->buildRules(
            allowed: $allowed,
            required: $required,
            resourceClass: $resourceClass,
            context: ResourceContext::Edit,
            withFieldRules: $api->usesFormFieldRules(),
            record: $record
        );
    }

    /**
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $required
     * @param  class-string<resource>  $resourceClass
     * @return array<string, array<int, mixed>>
     */
    private function buildRules(
        array $allowed,
        array $required,
        string $resourceClass,
        ResourceContext $context,
        bool $withFieldRules,
        ?Model $record = null
    ): array {
        $requiredLookup = array_fill_keys($required, true);
        $rules = [];
        $fieldRules = [];

        if ($withFieldRules) {
            $fieldRules = $this->fieldValidationBridge
                ->build($resourceClass, $context, $allowed, $record)['rules'];
        }

        foreach ($allowed as $field) {
            $baseRules = isset($requiredLookup[$field])
                ? ['required']
                : ['nullable'];

            $rules[$field] = $this->deduplicateRules(array_values(array_merge(
                $baseRules,
                $fieldRules[$field] ?? []
            )));
        }

        return $rules;
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return array<int, mixed>
     */
    private function deduplicateRules(array $rules): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                $deduplicated[] = $rule;

                continue;
            }

            $key = strtolower(trim($rule));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $rule;
        }

        return $deduplicated;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function apiDefinition(string $resourceClass): ApiResource
    {
        return $resourceClass::api(new ApiResource);
    }
}
