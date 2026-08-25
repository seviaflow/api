<?php

declare(strict_types=1);

use SeviaFlow\Api\Http\ApiErrorResponder;

it('returns standardized validation error json payload', function (): void {
    $response = (new ApiErrorResponder)->validationError('transition', 'The selected transition is not available.');
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(422)
        ->and($payload)->toBe([
            'message' => 'The selected transition is not available.',
            'errors' => [
                'transition' => ['The selected transition is not available.'],
            ],
        ]);
});
