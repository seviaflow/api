<?php

declare(strict_types=1);

namespace SeviaFlow\Api\OpenApi;

use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Support\SensitiveDataPolicy;

final class OpenApiPathBuilder
{
    public function __construct(
        private readonly OpenApiFieldSchemaMapper $fieldSchemaMapper = new OpenApiFieldSchemaMapper
    ) {}

    /**
     * @param  array<string, mixed>  $paths
     * @return array<string, mixed>
     */
    public function buildForResource(
        array $paths,
        string $prefix,
        string $slug,
        string $tag,
        ApiResource $api,
        array $fieldSchemas = []
    ): array {
        $resourcePath = '/'.$prefix.'/resources/'.$slug;
        $recordPath = '/'.$prefix.'/resources/'.$slug.'/{record}';
        $flowPath = '/'.$prefix.'/resources/'.$slug.'/{record}/flow';
        $flowExecutePath = '/'.$prefix.'/resources/'.$slug.'/{record}/flow/{transition}';

        if ($api->allowsOperation('index')) {
            $paths[$resourcePath]['get'] = [
                'tags' => [$tag],
                'operationId' => $this->operationId($slug, 'index'),
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                    ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                    ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string']],
                    ['name' => 'direction', 'in' => 'query', 'schema' => ['type' => 'string']],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'OK',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => [
                                            'type' => 'array',
                                            'items' => $this->resourceObjectSchema($api->getReadableFields(), $api, $fieldSchemas, true),
                                        ],
                                        'meta' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'current_page' => ['type' => 'integer'],
                                                'per_page' => ['type' => 'integer'],
                                                'total' => ['type' => 'integer'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($api->allowsOperation('store')) {
            $paths[$resourcePath]['post'] = [
                'tags' => [$tag],
                'operationId' => $this->operationId($slug, 'store'),
                'security' => [['bearerAuth' => []]],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $this->writeSchema($api->getCreatableFields(), $api->getRequiredOnCreateFields(), $api, $fieldSchemas),
                        ],
                    ],
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Created',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => $this->resourceObjectSchema($api->getReadableFields(), $api, $fieldSchemas, true),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($api->allowsOperation('show')) {
            $paths[$recordPath]['get'] = [
                'tags' => [$tag],
                'operationId' => $this->operationId($slug, 'show'),
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'record', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'OK',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => $this->resourceObjectSchema($api->getReadableFields(), $api, $fieldSchemas, true),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($api->allowsOperation('update')) {
            $paths[$recordPath]['patch'] = [
                'tags' => [$tag],
                'operationId' => $this->operationId($slug, 'update'),
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'record', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $this->writeSchema($api->getUpdatableFields(), $api->getRequiredOnUpdateFields(), $api, $fieldSchemas),
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'OK',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'data' => $this->resourceObjectSchema($api->getReadableFields(), $api, $fieldSchemas, true),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($api->allowsOperation('destroy')) {
            $paths[$recordPath]['delete'] = [
                'tags' => [$tag],
                'operationId' => $this->operationId($slug, 'destroy'),
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'record', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'responses' => [
                    '200' => ['description' => 'Deleted'],
                ],
            ];
        }

        if ($api->allowsOperation('flow')) {
            $flowTag = $tag.' Flow';
            $paths[$flowPath]['get'] = [
                'tags' => [$flowTag],
                'operationId' => $this->operationId($slug, 'flow'),
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'record', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'responses' => [
                    '200' => ['description' => 'OK'],
                ],
            ];
        }

        if ($api->allowsOperation('execute-flow')) {
            $flowTag = $tag.' Flow';
            $paths[$flowExecutePath]['post'] = [
                'tags' => [$flowTag],
                'operationId' => $this->operationId($slug, 'flowExecute'),
                'security' => [['bearerAuth' => []]],
                'parameters' => [
                    ['name' => 'record', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'transition', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ],
                'requestBody' => [
                    'required' => false,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'confirmed' => ['type' => 'boolean'],
                                    'comment' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => ['description' => 'OK'],
                ],
            ];
        }

        foreach ([$resourcePath, $recordPath, $flowPath, $flowExecutePath] as $path) {
            foreach (array_keys($paths[$path] ?? []) as $method) {
                if (! is_array($paths[$path][$method])) {
                    continue;
                }

                $paths[$path][$method]['responses'] = array_replace(
                    $this->commonErrorResponses(),
                    $paths[$path][$method]['responses'] ?? []
                );
            }
        }

        return $paths;
    }

    private function operationId(string $slug, string $action): string
    {
        return 'eloquentAdmin_'.str_replace('-', '_', $slug).'_'.$action;
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function resourceObjectSchema(array $fields, ApiResource $api, array $fieldSchemas, bool $forResponse = false): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $schema = $api->schemaFor($field) ?? ($fieldSchemas[$field] ?? null);
            $schema ??= $this->fieldSchemaMapper->map($field);

            if ($forResponse && (SensitiveDataPolicy::isSensitive($field) || (($schema['writeOnly'] ?? false) === true))) {
                continue;
            }

            $properties[$field] = $this->fieldSchemaMapper->map($field, $schema);
        }

        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private function writeSchema(array $fields, array $required, ApiResource $api, array $fieldSchemas): array
    {
        $schema = $this->resourceObjectSchema($fields, $api, $fieldSchemas);

        if ($required !== []) {
            $schema['required'] = array_values(array_filter(
                $required,
                static fn (string $field): bool => in_array($field, $fields, true)
            ));
        }

        return $schema;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function commonErrorResponses(): array
    {
        $errorSchema = [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        return [
            '401' => ['description' => 'Unauthenticated', 'content' => ['application/json' => ['schema' => $errorSchema]]],
            '403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => $errorSchema]]],
            '404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => $errorSchema]]],
            '409' => ['description' => 'Conflict', 'content' => ['application/json' => ['schema' => $errorSchema]]],
            '422' => ['description' => 'Validation error', 'content' => ['application/json' => ['schema' => $errorSchema]]],
        ];
    }
}
