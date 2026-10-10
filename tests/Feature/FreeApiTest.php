<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use SeviaFlow\Api\Http\Middleware\RequireApiLicense;

it('keeps legacy API license middleware compatible without a licensing adapter', function (): void {
    config()->set('seviaflow-api.licensing.enforce', true);

    $response = (new RequireApiLicense)->handle(Request::create('/api/admin'), fn () => response('free-api'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('free-api')
        ->and(class_exists('SeviaFlow\\Licensing\\LicenseGate'))->toBeFalse();
});

it('does not add a paid license gate to API or documentation routes', function (): void {
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'seviaflow.api.')) {
            expect($route->gatherMiddleware())->not->toContain('seviaflow.api.license');
        }
    }
});

it('still denies anonymous access to free API endpoints', function (): void {
    Route::get('/free-api-auth-regression', fn () => response('protected'))->middleware(['api', 'auth']);

    pest_test_case()->getJson('/free-api-auth-regression')->assertUnauthorized();
});
