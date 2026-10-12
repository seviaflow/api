<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Database\Eloquent\Model;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Fields\BelongsToManyField;
use SeviaFlow\Core\Resources\Fields\Field;
use SeviaFlow\Core\Resources\Fields\RichTextField;
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
        $formFields = $this->formFields($resourceClass);
        $formSchemas = [];
        foreach ($formFields as $fieldName => $field) {
            $formSchemas[$fieldName] = $field->getSchema();
        }
        $serialized = [];

        foreach ($api->getReadableFields() as $field) {
            $schema = $api->schemaFor($field) ?? ($formSchemas[$field] ?? null);
            if (SensitiveDataPolicy::isSensitive($field) || (($schema['writeOnly'] ?? false) === true)) {
                continue;
            }

            $value = data_get($record, $field);
            $formField = $formFields[$field] ?? null;
            if ($formField instanceof BelongsToManyField && $record instanceof Model) {
                $value = $formField->valueForRecord($record);
            }
            if ($formField instanceof RichTextField) {
                $value = $formField->sanitizeForDisplay($value);
            }

            $serialized[$field] = $value;
        }

        return $serialized;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<string, Field>
     */
    private function formFields(string $resourceClass): array
    {
        $fields = [];

        foreach ($resourceClass::form(new Form)->getAllFields() as $field) {
            if (! $field instanceof Field) {
                continue;
            }

            $fields[$field->name()] = $field;
        }

        return $fields;
    }
}
