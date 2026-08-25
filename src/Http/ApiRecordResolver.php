<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SeviaFlow\Core\Resources\Resource;

final class ApiRecordResolver
{
    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function resolve(string $resourceClass, int|string $record): ?Model
    {
        $found = $this->resourceQuery($resourceClass)->find($record);

        return $found instanceof Model ? $found : null;
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function resourceQuery(string $resourceClass): Builder
    {
        $modelClass = $resourceClass::model();
        /** @var Model $model */
        $model = new $modelClass;

        return $resourceClass::query($model->newQuery());
    }
}
