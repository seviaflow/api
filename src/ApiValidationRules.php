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
     * @param  array<string, mixed>  $fieldValues
     * @return array<string, array<int, mixed>>
     */
    public function forCreate(string $resourceClass, ?Model $record = null, array $fieldValues = []): array
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
            record: $record,
            fieldValues: $fieldValues,
        );
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $fieldValues
     * @return array<string, array<int, mixed>>
     */
    public function forUpdate(
        string $resourceClass,
        ?Model $record = null,
        array $fieldValues = [],
        ?array $presentFields = null,
    ): array {
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
            record: $record,
            fieldValues: $fieldValues,
            presentFields: $presentFields,
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
        ?Model $record = null,
        array $fieldValues = [],
        ?array $presentFields = null,
    ): array {
        $requiredLookup = array_fill_keys($required, true);
        $rules = [];
        $fieldRules = [];

        if ($withFieldRules) {
            $fieldRules = $this->fieldValidationBridge
                ->build($resourceClass, $context, $allowed, $record, $fieldValues)['rules'];
        }

        foreach ($allowed as $field) {
            $baseRules = $context === ResourceContext::Edit
                ? ['sometimes']
                : [];
            $isPresent = $presentFields === null || in_array($field, $presentFields, true);
            $baseRules[] = isset($requiredLookup[$field]) && $isPresent
                ? 'required'
                : 'nullable';

            $fieldRulesForField = $fieldRules[$field] ?? [];
            if ($context === ResourceContext::Edit && $presentFields !== null && ! $isPresent) {
                $fieldRulesForField = array_values(array_filter(
                    $fieldRulesForField,
                    static fn (mixed $rule): bool => ! is_string($rule) || strtolower(trim($rule)) !== 'required'
                ));
            }

            $rules[$field] = $this->deduplicateRules(array_values(array_merge(
                $baseRules,
                $fieldRulesForField
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
