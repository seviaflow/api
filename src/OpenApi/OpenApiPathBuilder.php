<?php

declare(strict_types=1);

namespace SeviaFlow\Api\OpenApi;

use SeviaFlow\Core\Resources\ApiResource;

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
        ApiResource $api
    ): array {
        $resourcePath = '/'.$prefix.'/resources/'.$slug;
        $recordPath = '/'.$prefix.'/resources/'.$slug.'/{record}';
        $flowPath = '/'.$prefix.'/resources/'.$slug.'/{record}/flow';
        $flowExecutePath = '/'.$prefix.'/resources/'.$slug.'/{record}/flow/{transition}';

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
                                        'items' => $this->resourceObjectSchema($api->getReadableFields()),
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

        if ($api->getCreatableFields() !== []) {
            $paths[$resourcePath]['post'] = [
                'tags' => [$tag],
                'operationId' => $this->operationId($slug, 'store'),
                'security' => [['bearerAuth' => []]],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $this->writeSchema($api->getCreatableFields(), $api->getRequiredOnCreateFields()),
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
                                        'data' => $this->resourceObjectSchema($api->getReadableFields()),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

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
                                    'data' => $this->resourceObjectSchema($api->getReadableFields()),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($api->getUpdatableFields() !== []) {
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
                            'schema' => $this->writeSchema($api->getUpdatableFields(), $api->getRequiredOnUpdateFields()),
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
                                        'data' => $this->resourceObjectSchema($api->getReadableFields()),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($api->isDeletable()) {
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

        if ($api->isFlowEnabled()) {
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
    private function resourceObjectSchema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = $this->fieldSchemaMapper->map($field);
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
    private function writeSchema(array $fields, array $required): array
    {
        $schema = $this->resourceObjectSchema($fields);

        if ($required !== []) {
            $schema['required'] = array_values(array_filter(
                $required,
                static fn (string $field): bool => in_array($field, $fields, true)
            ));
        }

        return $schema;
    }
}
