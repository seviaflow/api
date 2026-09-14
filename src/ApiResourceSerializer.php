<?php

declare(strict_types=1);

namespace SeviaFlow\Api;

use Illuminate\Database\Eloquent\Model;
use SeviaFlow\Core\Resources\ApiResource;
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
        $serialized = [];

        foreach ($api->getReadableFields() as $field) {
            if (SensitiveDataPolicy::isSensitive($field)) {
                continue;
            }

            $serialized[$field] = data_get($record, $field);
        }

        return $serialized;
    }
}
