<?php

declare(strict_types=1);

return [
    'enabled' => env('SEVIAFLOW_API_ENABLED', true),
    'prefix' => 'api/admin',
    'middleware' => ['api', 'auth:sanctum'],
    'throttle' => [
        'enabled' => true,
        'max_attempts' => 60,
        'decay_minutes' => 1,
    ],
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
