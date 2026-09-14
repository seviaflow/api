<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use SeviaFlow\Core\Exceptions\WorkflowTransitionConflictException;
use SeviaFlow\Core\Resources\Workflows\State;
use SeviaFlow\Core\Resources\Workflows\Transition;
use SeviaFlow\Core\Resources\Workflows\Workflow;
use SeviaFlow\Core\Workflows\WorkflowTransitionExecutor;
use SeviaFlow\Core\Workflows\WorkflowTransitionResolver;
use Throwable;

final class ApiFlowResponder
{
    public function __construct(
        private readonly WorkflowTransitionResolver $workflowTransitionResolver = new WorkflowTransitionResolver,
        private readonly WorkflowTransitionExecutor $workflowTransitionExecutor = new WorkflowTransitionExecutor,
        private readonly ApiRecordResolver $recordResolver = new ApiRecordResolver,
        private readonly ApiErrorResponder $errorResponder = new ApiErrorResponder,
        private readonly ApiAuthorization $authorization = new ApiAuthorization
    ) {}

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function flow(Request $request, string $resourceClass, bool $apiEnabled, bool $flowEnabled, int|string $record): JsonResponse
    {
        if (! $apiEnabled || ! $flowEnabled) {
            abort(404);
        }

        $found = $this->recordResolver->resolve($resourceClass, $record);
        if (! $found instanceof Model) {
            abort(404);
        }

        if (! $this->authorization->allows($request, $resourceClass, 'view', $found)) {
            abort(403);
        }

        $workflow = $this->resolveWorkflowForApi($resourceClass);
        if (! $workflow instanceof Workflow) {
            abort(404);
        }

        $currentStateName = $this->workflowTransitionResolver->currentState($resourceClass, $found);
        $currentState = $currentStateName !== null ? $workflow->getState($currentStateName) : null;
        $availableTransitions = $this->workflowTransitionResolver->availableTransitions($resourceClass, $found, $this->actor($request));

        return response()->json([
            'data' => [
                'state' => $this->serializeState($currentStateName, $currentState),
                'available_transitions' => array_map(
                    fn (Transition $transition): array => $this->serializeTransition($transition),
                    $availableTransitions
                ),
            ],
        ]);
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function executeFlow(
        Request $request,
        string $resourceClass,
        bool $apiEnabled,
        bool $flowEnabled,
        int|string $record,
        string $transition
    ): JsonResponse {
        if (! $apiEnabled || ! $flowEnabled) {
            abort(404);
        }

        $found = $this->recordResolver->resolve($resourceClass, $record);
        if (! $found instanceof Model) {
            abort(404);
        }

        if (! $this->authorization->allows($request, $resourceClass, 'update', $found)) {
            abort(403);
        }

        $workflow = $this->resolveWorkflowForApi($resourceClass);
        if (! $workflow instanceof Workflow) {
            abort(404);
        }

        $transitionDefinition = $workflow->getTransition($transition);
        if (! $transitionDefinition instanceof Transition) {
            return $this->errorResponder->validationError('transition', 'The selected transition is not available.');
        }

        $confirmed = (bool) $request->boolean('confirmed', false);
        $commentInput = $request->input('comment');
        $comment = is_string($commentInput) ? trim($commentInput) : null;
        if ($comment === '') {
            $comment = null;
        }

        try {
            $this->workflowTransitionExecutor->execute(
                resourceClass: $resourceClass,
                record: $found,
                transitionName: $transition,
                user: $this->actor($request),
                comment: $comment,
                confirmed: $confirmed
            );

            $recordAfter = $found->refresh();
            $stateName = $this->workflowTransitionResolver->currentState($resourceClass, $recordAfter);
            $state = $stateName !== null ? $workflow->getState($stateName) : null;

            return response()->json([
                'data' => [
                    'id' => $recordAfter->getKey(),
                    'state' => [
                        'name' => $stateName ?? 'unknown',
                        'label' => $state?->getLabel() ?? ucfirst(str_replace('_', ' ', (string) $stateName)),
                        'color' => $state?->getColor(),
                    ],
                ],
                'message' => $transitionDefinition->getSuccessMessage() ?? 'Transition completed successfully.',
            ]);
        } catch (WorkflowTransitionConflictException $exception) {
            return $this->errorResponder->conflict('transition', $exception->getMessage());
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->errorResponder->validationError('transition', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $transitionDefinition->getErrorMessage() ?? 'The transition could not be completed.',
            ], 422);
        }
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    private function resolveWorkflowForApi(string $resourceClass): ?Workflow
    {
        $workflow = $resourceClass::workflow(new Workflow);

        if ($workflow->getStates() === [] || $workflow->getTransitions() === []) {
            return null;
        }

        return $workflow;
    }

    private function serializeState(?string $stateName, ?State $state): array
    {
        $resolvedName = $stateName ?? 'unknown';

        return [
            'name' => $resolvedName,
            'label' => $state?->getLabel() ?? ucfirst(str_replace('_', ' ', $resolvedName)),
            'color' => $state?->getColor(),
            'final' => $state?->isFinal() ?? false,
        ];
    }

    /**
     * @return array{
     *   name: string,
     *   label: string,
     *   danger: bool,
     *   requires_confirmation: bool,
     *   requires_comment: bool
     * }
     */
    private function serializeTransition(Transition $transition): array
    {
        return [
            'name' => $transition->name(),
            'label' => $transition->getLabel(),
            'danger' => $transition->isDanger(),
            'requires_confirmation' => $transition->shouldRequireConfirmation(),
            'requires_comment' => $transition->shouldRequireComment(),
        ];
    }

    private function actor(Request $request): ?Authenticatable
    {
        $user = $request->user();

        return $user instanceof Authenticatable ? $user : null;
    }
}
