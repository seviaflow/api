<?php

declare(strict_types=1);

namespace SeviaFlow\Api\OpenApi;

use InvalidArgumentException;
use SeviaFlow\Core\Resources\Fields\Field;

final class OpenApiFieldSchemaMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(string|Field $field, ?array $schema = null): array
    {
        if ($field instanceof Field) {
            return $this->normalizeSchema($field->getSchema());
        }

        if ($schema !== null) {
            return $this->normalizeSchema($schema);
        }

        $normalized = strtolower($field);
        if ($normalized === 'id' || str_ends_with($normalized, '_id')) {
            return ['type' => 'integer'];
        }

        return ['type' => 'string'];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        if (! isset($schema['type']) && ! isset($schema['oneOf']) && ! isset($schema['anyOf'])) {
            throw new InvalidArgumentException('An API field schema must declare type, oneOf, or anyOf.');
        }

        return $schema;
    }
}
