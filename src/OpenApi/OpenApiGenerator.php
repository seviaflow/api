<?php

declare(strict_types=1);

namespace SeviaFlow\Api\OpenApi;

use Illuminate\Support\Str;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceRegistry;

final class OpenApiGenerator
{
    public function __construct(
        private readonly ResourceRegistry $resourceRegistry,
        private readonly OpenApiPathBuilder $pathBuilder = new OpenApiPathBuilder,
        private readonly OpenApiSecurityScheme $securityScheme = new OpenApiSecurityScheme,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];
        $prefix = trim((string) config('seviaflow-api.prefix', 'api/admin'), '/');

        foreach ($this->resourceRegistry->all() as $slug => $resourceClass) {
            $api = $resourceClass::api(new ApiResource);

            if (! $api->isEnabled()) {
                continue;
            }

            $paths = $this->pathBuilder->buildForResource(
                paths: $paths,
                prefix: $prefix,
                slug: $slug,
                tag: $this->resourceLabel($slug, $resourceClass),
                api: $api
            );
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SeviaFlow API',
                'version' => '1.0.0',
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => $this->securityScheme->bearerScheme(),
                ],
            ],
        ];
    }

    /**
     * @param  class-string<resource>  $resourceClass
     */
    private function resourceLabel(string $slug, string $resourceClass): string
    {
        $navigationLabel = $resourceClass::navigationLabel();
        if (is_string($navigationLabel) && trim($navigationLabel) !== '') {
            return $navigationLabel;
        }

        if (method_exists($resourceClass, 'pluralLabel')) {
            $label = $resourceClass::pluralLabel();
            if (is_string($label) && trim($label) !== '') {
                return $label;
            }
        }

        if (method_exists($resourceClass, 'label')) {
            $label = $resourceClass::label();
            if (is_string($label) && trim($label) !== '') {
                return $label;
            }
        }

        return Str::of($slug)->replace('-', ' ')->headline()->toString();
    }
}
