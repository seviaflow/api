<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Validation\ValidationException;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\Workflows\Workflow;

final class WorkflowStateFieldGuard
{
    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    public function removeStateFieldFromAllowed(string $resourceClass, array $fields): array
    {
        $stateField = $this->resolvedStateField($resourceClass);

        if ($stateField === null) {
            return $fields;
        }

        return array_values(array_filter(
            $fields,
            static fn (string $field): bool => $field !== $stateField
        ));
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @param  array<string, mixed>  $payload
     */
    public function ensurePayloadDoesNotWriteStateField(string $resourceClass, array $payload): void
    {
        $stateField = $this->resolvedStateField($resourceClass);

        if ($stateField === null || ! array_key_exists($stateField, $payload)) {
            return;
        }

        throw ValidationException::withMessages([
            $stateField => sprintf(
                'The [%s] field is workflow-controlled and cannot be changed through this API.',
                $stateField
            ),
        ]);
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function resolvedStateField(string $resourceClass): ?string
    {
        $workflow = $resourceClass::workflow(new Workflow);

        if ($workflow->getStates() === [] && $workflow->getTransitions() === [] && $workflow->getInitialState() === null) {
            return null;
        }

        return $workflow->getStateField();
    }
}
