<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Database\Eloquent\Model;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Fields\Field;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Support\SensitiveDataPolicy;

final class ApiResourceSerializer
{
    /**
     * @param  class-string<resource>  $resourceClass
     * @param  Model|array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function serialize(string $resourceClass, Model|array $record): array
    {
        $api = $resourceClass::api(new ApiResource);
        $formSchemas = $this->formSchemas($resourceClass);
        $serialized = [];

        foreach ($api->getReadableFields() as $field) {
            $schema = $api->schemaFor($field) ?? ($formSchemas[$field] ?? null);
            if (SensitiveDataPolicy::isSensitive($field) || (($schema['writeOnly'] ?? false) === true)) {
                continue;
            }

            $serialized[$field] = data_get($record, $field);
        }

        return $serialized;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, array<string, mixed>>
     */
    private function formSchemas(string $resourceClass): array
    {
        $schemas = [];

        foreach ($resourceClass::form(new Form)->getAllFields() as $field) {
            if (! $field instanceof Field) {
                continue;
            }

            $schemas[$field->name()] = $field->getSchema();
        }

        return $schemas;
    }
}
