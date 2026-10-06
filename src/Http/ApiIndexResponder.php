<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use SeviaFlow\Api\ApiResourceSerializer;
use SeviaFlow\Core\Resources\Table;
use SeviaFlow\Core\Tables\ResourceTableQuery;

final class ApiIndexResponder
{
    public function __construct(
        private readonly ApiResourceSerializer $serializer = new ApiResourceSerializer,
        private readonly ApiAuthorization $authorization = new ApiAuthorization
    ) {}

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function respond(Request $request, string $resourceClass): JsonResponse
    {
        if (! $this->authorization->allows($request, $resourceClass, 'viewAny')) {
            abort(403);
        }

        $perPage = $request->integer('per_page', (int) config('seviaflow.performance.pagination.default_per_page', 15));
        $page = max(1, $request->integer('page', 1));
        $query = ResourceTableQuery::make($resourceClass, $resourceClass::table(new Table))
            ->search(mb_substr(trim((string) $request->query('search', '')), 0, 200))
            ->sort(
                trim((string) $request->query('sort', '')),
                strtolower(trim((string) $request->query('direction', 'asc')))
            )
            ->filters($this->filterValues($request));
        $paginator = $query->paginate($perPage, $page);

        $data = [];
        foreach ($paginator->items() as $record) {
            if ($record instanceof Model) {
                $data[] = $this->serializer->serialize($resourceClass, $record);
            }
        }

        $response = [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ],
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $response['meta']['total'] = $paginator->total();
        }

        return response()->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterValues(Request $request): array
    {
        $raw = $request->query('filter', []);
        if (! is_array($raw)) {
            return [];
        }

        $filters = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $normalized = $this->normalizeFilterValue($value);
            if ($normalized !== null) {
                $filters[$key] = $normalized;
            }
        }

        return $filters;
    }

    private function normalizeFilterValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 2) {
            return null;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $nestedValue) {
                $normalized[$key] = $this->normalizeFilterValue($nestedValue, $depth + 1);
            }

            return $normalized;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }
}
