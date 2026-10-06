<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Schema;
use SeviaFlow\Api\SeviaFlowApiServiceProvider;

beforeEach(function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.middleware', ['api', 'web']);
    config()->set('seviaflow-api.docs.enabled', true);
    config()->set('seviaflow-api.docs.route', 'api-docs');
    config()->set('seviaflow-api.docs.middleware', ['web', 'auth']);
    config()->set('seviaflow-api.openapi.enabled', true);
    config()->set('seviaflow-api.openapi.route', 'openapi.json');
    config()->set('seviaflow-api.openapi.middleware', ['api', 'web']);

    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });
});

it('api docs page exists when enabled', function (): void {
    $user = ApiDocsUser::query()->create(['name' => 'Docs User']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->get('/api/admin/api-docs')
        ->assertOk()
        ->assertSee('SeviaFlow API Documentation')
        ->assertSee('OpenAPI JSON');
});

it('guest cannot access api docs page', function (): void {
    config()->set('seviaflow-api.docs.middleware', ['web', 'auth']);

    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);
    app('router')->get('/login', static fn () => response('', 200))->name('login');

    pest_test_case()->get('/api/admin/api-docs')
        ->assertStatus(302);
});

it('supports custom api prefix for api docs page', function (): void {
    config()->set('seviaflow-api.prefix', 'api/backoffice');

    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);

    $user = ApiDocsUser::query()->create(['name' => 'Backoffice Docs User']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->get('/api/backoffice/api-docs')
        ->assertOk();
});

it('page includes swagger ui container and references openapi route', function (): void {
    $user = ApiDocsUser::query()->create(['name' => 'Docs User']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->get('/api/admin/api-docs')
        ->assertOk()
        ->assertSee('OpenAPI JSON')
        ->assertSee('openapi.json', false);
});

it('shows helpful message when api is disabled', function (): void {
    config()->set('seviaflow-api.enabled', false);

    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);

    $user = ApiDocsUser::query()->create(['name' => 'Docs User']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->get('/api/admin/api-docs')
        ->assertOk()
        ->assertSee('API is disabled. Enable it in')
        ->assertDontSee('OpenAPI JSON');
});

it('api docs route is unavailable when api docs are disabled', function (): void {
    config()->set('seviaflow-api.docs.enabled', false);

    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);

    $user = ApiDocsUser::query()->create(['name' => 'Docs User']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->get('/api/admin/api-docs')
        ->assertNotFound();
});

final class ApiDocsUser extends AuthenticatableUser
{
    protected $table = 'users';

    protected $guarded = [];
}
