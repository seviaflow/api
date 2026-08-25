<?php

declare(strict_types=1);

namespace SeviaFlow\Api\OpenApi;

final class OpenApiFieldSchemaMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(string $field): array
    {
        $normalized = strtolower($field);

        if ($normalized === 'id' || str_ends_with($normalized, '_id')) {
            return ['type' => 'integer'];
        }

        if (str_ends_with($normalized, '_at')) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (str_contains($normalized, 'date') && ! str_contains($normalized, 'datetime')) {
            return ['type' => 'string', 'format' => 'date'];
        }

        if (str_starts_with($normalized, 'is_') || str_starts_with($normalized, 'has_')) {
            return ['type' => 'boolean'];
        }

        if (str_contains($normalized, 'count')
            || str_contains($normalized, 'amount')
            || str_contains($normalized, 'price')
            || str_contains($normalized, 'total')
            || str_contains($normalized, 'number')) {
            return ['type' => 'number'];
        }

        if (str_ends_with($normalized, '_ids') || str_ends_with($normalized, '_list')) {
            return [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ];
        }

        return ['type' => 'string'];
    }
}
