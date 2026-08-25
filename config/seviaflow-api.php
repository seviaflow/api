<?php

declare(strict_types=1);

return [
    'enabled' => env('SEVIAFLOW_API_ENABLED', true),
    'prefix' => 'api/admin',
    'middleware' => ['api', 'auth:sanctum'],
    'route_name_prefix' => 'seviaflow.api.',
    'openapi' => [
        'enabled' => true,
        'route' => 'openapi.json',
        'middleware' => ['web', 'auth'],
        'cache' => [
            'enabled' => true,
        ],
    ],
    'docs' => [
        'enabled' => true,
        'route' => 'api-docs',
        'middleware' => ['web', 'auth'],
        'view' => 'seviaflow-api::api-docs',
    ],
];
