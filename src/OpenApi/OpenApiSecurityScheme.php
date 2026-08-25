<?php

declare(strict_types=1);

namespace SeviaFlow\Api\OpenApi;

final class OpenApiSecurityScheme
{
    /**
     * @return array<string, mixed>
     */
    public function bearerScheme(): array
    {
        return [
            'type' => 'http',
            'scheme' => 'bearer',
        ];
    }
}
