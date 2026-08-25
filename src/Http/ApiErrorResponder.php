<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponder
{
    public function validationError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [
                $field => [$message],
            ],
        ], 422);
    }
}
