<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use SeviaFlow\Core\Resources\ResourceAuthorization;
use Throwable;

final class ApiAuthorization
{
    public function allows(Request $request, string $resourceClass, string $ability, ?Model $record = null): bool
    {
        $actor = $request->user();
        $actor = $actor instanceof Authenticatable ? $actor : null;

        $authorization = ResourceAuthorization::for($resourceClass);
        $allowed = match ($ability) {
            'viewAny' => $authorization->canViewAny($actor),
            'view' => $record instanceof Model && $authorization->canView($actor, $record),
            'create' => $authorization->canCreate($actor),
            'update' => $record instanceof Model && $authorization->canUpdate($actor, $record),
            'delete' => $record instanceof Model && $authorization->canDelete($actor, $record),
            'restore' => $record instanceof Model && $authorization->canRestore($actor, $record),
            'forceDelete' => $record instanceof Model && $authorization->canForceDelete($actor, $record),
            default => false,
        };

        if (! $allowed || ! $actor instanceof Authenticatable) {
            return $allowed;
        }

        return $this->tokenAllows($actor, $request, $resourceClass, $ability);
    }

    private function tokenAllows(Authenticatable $actor, Request $request, string $resourceClass, string $ability): bool
    {
        if (! method_exists($actor, 'tokenCan')) {
            return true;
        }

        $hasCurrentToken = ! method_exists($actor, 'currentAccessToken') || $actor->currentAccessToken() !== null;
        if (! $hasCurrentToken) {
            return true;
        }

        foreach ($this->abilityCandidates($request, $resourceClass, $ability) as $candidate) {
            try {
                if ((bool) $actor->tokenCan($candidate)) {
                    return true;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function abilityCandidates(Request $request, string $resourceClass, string $ability): array
    {
        $resource = (string) $request->route('resource');
        if ($resource === '') {
            $resource = $resourceClass;
        }

        $read = in_array($ability, ['viewAny', 'view'], true);
        $kind = $read ? 'read' : 'write';

        return array_values(array_unique([
            '*',
            'resources.'.$kind,
            'resources.'.$resource.'.'.$kind,
            'resources.'.$resource.'.'.$ability,
            'resource:'.$resource.':'.$kind,
            'resource:'.$resource.':'.$ability,
            $resource.'.'.$ability,
            $ability,
        ]));
    }
}
