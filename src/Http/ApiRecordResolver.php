<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Database\Eloquent\Model;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceScopedQuery;

final class ApiRecordResolver
{
    /**
     * @param  class-string<resource>  $resourceClass
     */
    public function resolve(string $resourceClass, int|string $record): ?Model
    {
        return ResourceScopedQuery::find($resourceClass, $record);
    }
}
