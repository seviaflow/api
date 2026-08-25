<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Schema;
use SeviaFlow\Api\OpenApi\OpenApiCache;
use SeviaFlow\Api\SeviaFlowApiServiceProvider;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;

beforeEach(function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.middleware', ['api', 'web']);
    config()->set('seviaflow-api.openapi.enabled', true);
    config()->set('seviaflow-api.openapi.route', 'openapi.json');
    config()->set('seviaflow-api.openapi.middleware', ['api', 'web']);
    config()->set('seviaflow.resources', [
        'openapi-enabled' => OpenApiEnabledResource::class,
        'openapi-disabled' => OpenApiDisabledResource::class,
        'openapi-flow' => OpenApiFlowResource::class,
    ]);

    Schema::create('openapi_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->unsignedBigInteger('company_id')->nullable();
        $table->string('secret')->nullable();
        $table->string('status')->nullable();
        $table->timestamps();
    });

    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);
});

it('openapi endpoint returns json', function (): void {
    pest_test_case()->getJson('/api/admin/openapi.json')
        ->assertOk();
});

it('spec contains openapi version', function (): void {
    pest_test_case()->getJson('/api/admin/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0');
});

it('contains enabled resource paths', function (): void {
    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($response['paths'])->toHaveKey('/api/admin/resources/openapi-enabled')
        ->and($response['paths'])->toHaveKey('/api/admin/resources/openapi-enabled/{record}');
});

it('excludes disabled resources', function (): void {
    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($response['paths'])->not->toHaveKey('/api/admin/resources/openapi-disabled')
        ->and($response['paths'])->not->toHaveKey('/api/admin/resources/openapi-disabled/{record}');
});

it('includes readable fields and excludes non readable fields in response schema', function (): void {
    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    $showSchema = $response['paths']['/api/admin/resources/openapi-enabled/{record}']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'];

    expect($showSchema)->toHaveKeys(['id', 'name', 'email'])
        ->and($showSchema)->not->toHaveKey('secret');
});

it('create and update schemas are generated from creatable and updatable fields', function (): void {
    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    $createSchema = $response['paths']['/api/admin/resources/openapi-enabled']['post']['requestBody']['content']['application/json']['schema'];
    $updateSchema = $response['paths']['/api/admin/resources/openapi-enabled/{record}']['patch']['requestBody']['content']['application/json']['schema'];

    expect($createSchema['properties'])->toHaveKeys(['name', 'email', 'company_id'])
        ->and($createSchema['required'])->toBe(['name', 'email'])
        ->and($updateSchema['properties'])->toHaveKey('name')
        ->and($updateSchema['properties'])->toHaveKey('company_id')
        ->and($updateSchema['properties'])->not->toHaveKey('email');
});

it('represents belongsTo foreign keys as scalar id fields in schemas', function (): void {
    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    $showSchema = $response['paths']['/api/admin/resources/openapi-enabled/{record}']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties'];
    $createSchema = $response['paths']['/api/admin/resources/openapi-enabled']['post']['requestBody']['content']['application/json']['schema']['properties'];
    $updateSchema = $response['paths']['/api/admin/resources/openapi-enabled/{record}']['patch']['requestBody']['content']['application/json']['schema']['properties'];

    expect($showSchema['company_id']['type'])->toBe('integer')
        ->and($createSchema['company_id']['type'])->toBe('integer')
        ->and($updateSchema['company_id']['type'])->toBe('integer')
        ->and($showSchema)->not->toHaveKey('company');
});

it('includes flow endpoints when flow is enabled', function (): void {
    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($response['paths'])->toHaveKey('/api/admin/resources/openapi-flow/{record}/flow')
        ->and($response['paths'])->toHaveKey('/api/admin/resources/openapi-flow/{record}/flow/{transition}');
});

it('includes bearer security scheme', function (): void {
    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($response['components']['securitySchemes']['bearerAuth'])->toBe([
        'type' => 'http',
        'scheme' => 'bearer',
    ]);
});

it('caches spec when openapi cache is enabled', function (): void {
    config()->set('seviaflow.performance.cache.enabled', true);
    config()->set('seviaflow-api.openapi.cache.enabled', true);
    config()->set('seviaflow.performance.cache.store', 'array');
    flushArrayCache();

    $first = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    config()->set('seviaflow-api.prefix', 'api/changed');

    $second = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($first['paths'])->toHaveKey('/api/admin/resources/openapi-enabled')
        ->and($second['paths'])->toHaveKey('/api/admin/resources/openapi-enabled')
        ->and($second['paths'])->not->toHaveKey('/api/changed/resources/openapi-enabled');
});

it('regenerates spec when openapi cache is disabled', function (): void {
    config()->set('seviaflow.performance.cache.enabled', false);
    config()->set('seviaflow-api.openapi.cache.enabled', true);
    config()->set('seviaflow.performance.cache.store', 'array');
    flushArrayCache();

    pest_test_case()->getJson('/api/admin/openapi.json')->assertOk();

    config()->set('seviaflow-api.prefix', 'api/changed');

    $second = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($second['paths'])->toHaveKey('/api/changed/resources/openapi-enabled');
});

it('clear removes cached openapi spec', function (): void {
    config()->set('seviaflow.performance.cache.enabled', true);
    config()->set('seviaflow-api.openapi.cache.enabled', true);
    config()->set('seviaflow.performance.cache.store', 'array');
    flushArrayCache();

    pest_test_case()->getJson('/api/admin/openapi.json')->assertOk();

    config()->set('seviaflow-api.prefix', 'api/cleared');
    app(OpenApiCache::class)->clear();

    $afterClear = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($afterClear['paths'])->toHaveKey('/api/cleared/resources/openapi-enabled');
});

it('cached spec still excludes disabled resources and includes flow endpoints', function (): void {
    config()->set('seviaflow.performance.cache.enabled', true);
    config()->set('seviaflow-api.openapi.cache.enabled', true);
    config()->set('seviaflow.performance.cache.store', 'array');
    flushArrayCache();

    $response = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();
    $cachedAgain = pest_test_case()->getJson('/api/admin/openapi.json')->assertOk()->json();

    expect($response['paths'])->not->toHaveKey('/api/admin/resources/openapi-disabled')
        ->and($response['paths'])->toHaveKey('/api/admin/resources/openapi-flow/{record}/flow')
        ->and($response['paths'])->toHaveKey('/api/admin/resources/openapi-flow/{record}/flow/{transition}')
        ->and($cachedAgain['paths'])->toBe($response['paths']);
});

it('openapi cache handles api disabled safely', function (): void {
    config()->set('seviaflow-api.enabled', false);
    config()->set('seviaflow.performance.cache.enabled', true);
    config()->set('seviaflow-api.openapi.cache.enabled', true);

    $spec = app(OpenApiCache::class)->generate();

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['paths'])->toBe([]);
});

final class OpenApiEnabledResource extends Resource
{
    protected static string $model = OpenApiRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name', 'email', 'company_id'])
            ->creatable(['name', 'email', 'company_id'])
            ->updatable(['name', 'company_id'])
            ->requiredOnCreate(['name', 'email'])
            ->deletable();
    }
}

final class OpenApiDisabledResource extends Resource
{
    protected static string $model = OpenApiRecord::class;
}

final class OpenApiFlowResource extends Resource
{
    protected static string $model = OpenApiRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->flow()
            ->readable(['id', 'status']);
    }
}

final class OpenApiRecord extends Model
{
    protected $table = 'openapi_records';

    protected $guarded = [];
}
