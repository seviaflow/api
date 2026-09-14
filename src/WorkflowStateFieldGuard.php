<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Validation\ValidationException;
use SeviaFlow\Core\Exceptions\WorkflowStateMutationException;
use SeviaFlow\Core\Workflows\WorkflowStateFieldGuard as CoreWorkflowStateFieldGuard;

final class WorkflowStateFieldGuard
{
    public function __construct(
        private readonly CoreWorkflowStateFieldGuard $coreGuard = new CoreWorkflowStateFieldGuard
    ) {}

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
        try {
            $this->coreGuard->ensurePayloadDoesNotWriteStateField($resourceClass, $payload);
        } catch (WorkflowStateMutationException $exception) {
            $stateField = $this->resolvedStateField($resourceClass) ?? 'state';

            throw ValidationException::withMessages([
                $stateField => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function resolvedStateField(string $resourceClass): ?string
    {
        return $this->coreGuard->stateField($resourceClass);
    }
}
