<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use SeviaFlow\Core\Resources\ResourceRegistry;

it('does not register api routes when api is disabled', function (): void {
    config()->set('seviaflow-api.enabled', false);

    reloadApiRoutesForRegistrationTest();

    expect(Route::has('seviaflow.api.resources.show'))->toBeFalse();
    pest_test_case()->get('/api/admin/resources/test-resource')->assertNotFound();
});

it('registers api routes when api is enabled', function (): void {
    config()->set('seviaflow-api.enabled', true);

    reloadApiRoutesForRegistrationTest();

    expect(Route::has('seviaflow.api.resources.index'))->toBeTrue()
        ->and(Route::has('seviaflow.api.resources.store'))->toBeTrue()
        ->and(Route::has('seviaflow.api.resources.show'))->toBeTrue()
        ->and(Route::has('seviaflow.api.resources.flow'))->toBeTrue()
        ->and(Route::has('seviaflow.api.resources.flow.execute'))->toBeTrue()
        ->and(Route::has('seviaflow.api.resources.update'))->toBeTrue()
        ->and(Route::has('seviaflow.api.resources.destroy'))->toBeTrue();
});

it('supports custom api prefix', function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.prefix', 'api/backoffice');

    reloadApiRoutesForRegistrationTest();

    expect(Route::has('seviaflow.api.resources.index'))->toBeTrue()
        ->and(route('seviaflow.api.resources.index', ['resource' => 'test-resource']))
        ->toContain('/api/backoffice/resources/test-resource');
});

it('applies configured api middleware', function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.middleware', ['api', 'throttle:60,1', 'auth:sanctum']);

    reloadApiRoutesForRegistrationTest();

    $route = Route::getRoutes()->getByName('seviaflow.api.resources.index');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain('api')
        ->and($route?->gatherMiddleware())->toContain('throttle:60,1')
        ->and($route?->gatherMiddleware())->toContain('auth:sanctum');
});

function reloadApiRoutesForRegistrationTest(): void
{
    app('router')->setRoutes(new RouteCollection);
    app()->forgetInstance(ResourceRegistry::class);
    app()->instance(ResourceRegistry::class, new ResourceRegistry(
        config('seviaflow.resources', [])
    ));

    require __DIR__.'/../../routes/api.php';

    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();
}
