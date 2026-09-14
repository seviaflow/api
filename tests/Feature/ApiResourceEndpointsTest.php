<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Schema;
use SeviaFlow\Api\SeviaFlowApiServiceProvider;
use SeviaFlow\Core\Auth\Permissions\PermissionAbilities;
use SeviaFlow\Core\Auth\Roles\Permission;
use SeviaFlow\Core\Auth\Roles\Role;
use SeviaFlow\Core\Auth\Roles\RoleAssignment;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Columns\TextColumn;
use SeviaFlow\Core\Resources\Filters\SelectFilter;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceEventContext;
use SeviaFlow\Core\Resources\ResourceEvents;
use SeviaFlow\Core\Resources\Table;
use SeviaFlow\Core\Resources\Workflows\State;
use SeviaFlow\Core\Resources\Workflows\Transition;
use SeviaFlow\Core\Resources\Workflows\Workflow;

beforeEach(function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.middleware', ['api', 'web']);
    config()->set('seviaflow.performance.pagination.max_per_page', 2);
    config()->set('seviaflow.resources', [
        'api-enabled-records' => ApiWriteableRecordResource::class,
        'api-event-records' => ApiEventRecordResource::class,
        'api-disabled-records' => ApiDisabledRecordResource::class,
        'api-non-deletable-records' => ApiNonDeletableRecordResource::class,
        'api-empty-create-records' => ApiEmptyCreateRecordResource::class,
        'api-empty-update-records' => ApiEmptyUpdateRecordResource::class,
        'api-soft-records' => ApiSoftDeleteRecordResource::class,
    ]);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    Schema::create('api_endpoint_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->unsignedBigInteger('company_id')->nullable();
        $table->string('status')->nullable();
        $table->string('secret')->nullable();
        $table->timestamps();
    });

    Schema::create('api_soft_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::create('seviaflow_roles', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->timestamps();
    });

    Schema::create('seviaflow_roleables', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('role_id')->nullable();
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->timestamps();
    });

    Schema::create('seviaflow_permissions', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('role_id')->nullable();
        $table->string('resource');
        $table->string('ability');
        $table->boolean('allowed')->default(true);
        $table->timestamps();
    });

    reloadApiRoutes();
    ApiEventTracker::reset();
});

it('global api disabled routes are unavailable', function (): void {
    config()->set('seviaflow-api.enabled', false);
    reloadApiRoutes();

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records')
        ->assertNotFound();
});

it('api enabled resource index returns data', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'Jane', 'email' => 'jane@example.com', 'secret' => 'hidden']);
    ApiEndpointRecord::query()->create(['name' => 'John', 'email' => 'john@example.com', 'secret' => 'hidden-2']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Jane')
        ->assertJsonPath('data.0.email', 'jane@example.com');
});

it('disabled resource api is denied with not found', function (): void {
    pest_test_case()->getJson('/api/admin/resources/api-disabled-records')
        ->assertNotFound();
});

it('unknown resource returns not found', function (): void {
    pest_test_case()->getJson('/api/admin/resources/unknown-resource')
        ->assertNotFound();
});

it('show returns readable fields only', function (): void {
    $record = ApiEndpointRecord::query()->create([
        'name' => 'Readable',
        'email' => 'readable@example.com',
        'secret' => 'top-secret',
    ]);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records/'.$record->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $record->getKey())
        ->assertJsonPath('data.name', 'Readable')
        ->assertJsonPath('data.email', 'readable@example.com')
        ->assertJsonPath('data.company_id', null)
        ->assertJsonMissingPath('data.company')
        ->assertJsonMissingPath('data.secret');
});

it('unauthorized index is denied', function (): void {
    config()->set('seviaflow.permissions.enabled', true);
    $user = ApiEndpointUser::query()->create(['name' => 'No Permission']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records')
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('unauthorized show is denied', function (): void {
    config()->set('seviaflow.permissions.enabled', true);
    $record = ApiEndpointRecord::query()->create(['name' => 'Protected']);
    $user = ApiEndpointUser::query()->create(['name' => 'No Permission']);
    grantApiPermission($user, 'api-enabled-records', PermissionAbilities::VIEW_ANY);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records/'.$record->getKey())
        ->assertForbidden()
        ->assertJsonStructure(['message']);
});

it('index response includes pagination meta', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'First']);
    ApiEndpointRecord::query()->create(['name' => 'Second']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);
});

it('custom api prefix works', function (): void {
    config()->set('seviaflow-api.prefix', 'api/backoffice');
    reloadApiRoutes();

    pest_test_case()->getJson('/api/backoffice/resources/api-enabled-records')
        ->assertOk();
});

it('supports page and per_page query params', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'First']);
    ApiEndpointRecord::query()->create(['name' => 'Second']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?page=2&per_page=1')
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('data.0.name', 'Second');
});

it('enforces max per_page from pagination config', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'First']);
    ApiEndpointRecord::query()->create(['name' => 'Second']);
    ApiEndpointRecord::query()->create(['name' => 'Third']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?per_page=999')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2);
});

it('searches only searchable columns', function (): void {
    ApiEndpointRecord::query()->create([
        'name' => 'Acme Corp',
        'email' => 'acme@example.com',
        'secret' => 'invisible-secret',
    ]);
    ApiEndpointRecord::query()->create([
        'name' => 'Other Company',
        'email' => 'other@example.com',
        'secret' => 'acme-hidden-marker',
    ]);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?search=acme')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Acme Corp');
});

it('sorts only sortable columns', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'Zulu']);
    ApiEndpointRecord::query()->create(['name' => 'Alpha']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?sort=name&direction=asc')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha');
});

it('ignores sorting by non sortable columns', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'First', 'email' => 'z@example.com']);
    ApiEndpointRecord::query()->create(['name' => 'Second', 'email' => 'a@example.com']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?sort=email&direction=desc')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'First');
});

it('applies defined filters', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'Paid Record', 'status' => 'paid']);
    ApiEndpointRecord::query()->create(['name' => 'Pending Record', 'status' => 'pending']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?filter[status]=paid')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Paid Record');
});

it('handles invalid filter values safely', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'Paid Record', 'status' => 'paid']);
    ApiEndpointRecord::query()->create(['name' => 'Pending Record', 'status' => 'pending']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?filter[status]=invalid')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('blocks arbitrary column filtering', function (): void {
    ApiEndpointRecord::query()->create(['name' => 'One', 'secret' => 'alpha']);
    ApiEndpointRecord::query()->create(['name' => 'Two', 'secret' => 'beta']);

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records?filter[secret]=alpha')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('create endpoint stores record and returns readable fields with created status', function (): void {
    pest_test_case()->postJson('/api/admin/resources/api-enabled-records', [
        'name' => 'Created',
        'email' => 'created@example.com',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Created')
        ->assertJsonPath('data.email', 'created@example.com')
        ->assertJsonMissingPath('data.secret');

    expect(ApiEndpointRecord::query()->where('name', 'Created')->exists())->toBeTrue();
});

it('update endpoint updates record and returns readable fields', function (): void {
    $record = ApiEndpointRecord::query()->create([
        'name' => 'Before',
        'email' => 'before@example.com',
        'status' => 'draft',
    ]);

    pest_test_case()->patchJson('/api/admin/resources/api-enabled-records/'.$record->getKey(), [
        'name' => 'After',
    ])->assertOk()
        ->assertJsonPath('data.name', 'After')
        ->assertJsonMissingPath('data.secret');

    expect($record->fresh()?->name)->toBe('After');
});

it('create and update accept belongsTo foreign key as scalar id field', function (): void {
    $create = pest_test_case()->postJson('/api/admin/resources/api-enabled-records', [
        'name' => 'BelongsTo API',
        'email' => 'belongs-to@example.com',
        'company_id' => 123,
    ])->assertCreated();

    $recordId = (int) $create->json('data.id');

    $create->assertJsonPath('data.company_id', 123)
        ->assertJsonMissingPath('data.company');

    pest_test_case()->patchJson('/api/admin/resources/api-enabled-records/'.$recordId, [
        'name' => 'BelongsTo API Updated',
        'company_id' => 456,
    ])->assertOk()
        ->assertJsonPath('data.company_id', 456)
        ->assertJsonMissingPath('data.company');
});

it('create rejects non creatable fields', function (): void {
    pest_test_case()->postJson('/api/admin/resources/api-enabled-records', [
        'name' => 'Created',
        'email' => 'created@example.com',
        'secret' => 'not-allowed',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['secret']);
});

it('update rejects non updatable fields', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Before', 'email' => 'before@example.com']);

    pest_test_case()->patchJson('/api/admin/resources/api-enabled-records/'.$record->getKey(), [
        'email' => 'changed@example.com',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('create enforces required fields', function (): void {
    pest_test_case()->postJson('/api/admin/resources/api-enabled-records', [
        'name' => 'Created',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('allows partial update without unchanged required fields', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Before', 'email' => 'before@example.com']);

    pest_test_case()->patchJson('/api/admin/resources/api-enabled-records/'.$record->getKey(), [
        'company_id' => 123,
    ])->assertOk()
        ->assertJsonPath('data.company_id', 123);

    expect($record->fresh()?->name)->toBe('Before');
});

it('rejects explicit null for required update field', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Before', 'email' => 'before@example.com']);

    pest_test_case()->patchJson('/api/admin/resources/api-enabled-records/'.$record->getKey(), [
        'name' => null,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('workflow state field cannot be created directly', function (): void {
    pest_test_case()->postJson('/api/admin/resources/api-enabled-records', [
        'name' => 'Created',
        'email' => 'created@example.com',
        'status' => 'published',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('workflow state field cannot be updated directly', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Before', 'email' => 'before@example.com', 'status' => 'draft']);

    pest_test_case()->patchJson('/api/admin/resources/api-enabled-records/'.$record->getKey(), [
        'name' => 'After',
        'status' => 'published',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('delete works when api is deletable and authorized', function (): void {
    config()->set('seviaflow.permissions.enabled', true);

    $record = ApiEndpointRecord::query()->create(['name' => 'Delete Me']);
    $user = ApiEndpointUser::query()->create(['name' => 'Deleter']);
    grantApiPermission($user, 'api-enabled-records', PermissionAbilities::DELETE);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->deleteJson('/api/admin/resources/api-enabled-records/'.$record->getKey())
        ->assertOk()
        ->assertJsonPath('message', 'Record deleted successfully.');

    expect(ApiEndpointRecord::query()->find($record->getKey()))->toBeNull();
});

it('delete is denied when api resource is not deletable', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'No Delete']);

    pest_test_case()->deleteJson('/api/admin/resources/api-non-deletable-records/'.$record->getKey())
        ->assertNotFound();
});

it('create is unavailable when no creatable fields are declared', function (): void {
    pest_test_case()->postJson('/api/admin/resources/api-empty-create-records', [
        'name' => 'Not Created',
    ])->assertNotFound();
});

it('update is unavailable when no updatable fields are declared', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Not Updated']);

    pest_test_case()->patchJson('/api/admin/resources/api-empty-update-records/'.$record->getKey(), [
        'name' => 'Changed',
    ])->assertNotFound();
});

it('unauthorized create is denied', function (): void {
    config()->set('seviaflow.permissions.enabled', true);
    $user = ApiEndpointUser::query()->create(['name' => 'No Create']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->postJson('/api/admin/resources/api-enabled-records', [
        'name' => 'Created',
        'email' => 'created@example.com',
    ])->assertForbidden();
});

it('unauthorized update is denied', function (): void {
    config()->set('seviaflow.permissions.enabled', true);
    $record = ApiEndpointRecord::query()->create(['name' => 'Before', 'email' => 'before@example.com']);
    $user = ApiEndpointUser::query()->create(['name' => 'No Update']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->patchJson('/api/admin/resources/api-enabled-records/'.$record->getKey(), [
        'name' => 'After',
    ])->assertForbidden();
});

it('unauthorized delete is denied', function (): void {
    config()->set('seviaflow.permissions.enabled', true);
    $record = ApiEndpointRecord::query()->create(['name' => 'Before']);
    $user = ApiEndpointUser::query()->create(['name' => 'No Delete']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->deleteJson('/api/admin/resources/api-enabled-records/'.$record->getKey())
        ->assertForbidden();
});

it('intersects resource authorization with a read-only token ability', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Token Read']);
    $user = ApiTokenEndpointUser::query()->create(['name' => 'Token User']);
    ApiTokenEndpointUser::$abilities = ['resources.read'];
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records/'.$record->getKey())
        ->assertOk();
    pest_test_case()->postJson('/api/admin/resources/api-enabled-records', [
        'name' => 'Token Write Denied',
        'email' => 'token-write-denied@example.com',
    ])->assertForbidden();
});

it('denies a revoked token before resource execution', function (): void {
    $user = ApiTokenEndpointUser::query()->create(['name' => 'Revoked Token User']);
    ApiTokenEndpointUser::$abilities = [];
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->getJson('/api/admin/resources/api-enabled-records')->assertForbidden();
});

it('soft delete behavior is respected', function (): void {
    $record = ApiSoftRecord::query()->create(['name' => 'Soft Delete Me']);

    pest_test_case()->deleteJson('/api/admin/resources/api-soft-records/'.$record->getKey())
        ->assertOk()
        ->assertJsonPath('message', 'Record deleted successfully.');

    expect(ApiSoftRecord::withTrashed()->find($record->getKey())?->trashed())->toBeTrue();
});

it('api create fires creating and created with source api', function (): void {
    pest_test_case()->postJson('/api/admin/resources/api-event-records', [
        'name' => 'Event Create',
        'email' => 'event-api-create@example.com',
    ])->assertCreated();

    expect(ApiEventTracker::$events)->toHaveCount(2)
        ->and(ApiEventTracker::$events[0]['event'])->toBe('creating')
        ->and(ApiEventTracker::$events[1]['event'])->toBe('created')
        ->and(ApiEventTracker::$events[0]['source'])->toBe('api');
});

it('api update fires updating and updated with source api', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Before', 'email' => 'before@api-event.test']);

    pest_test_case()->patchJson('/api/admin/resources/api-event-records/'.$record->getKey(), [
        'name' => 'After',
    ])->assertOk();

    expect(ApiEventTracker::$events)->toHaveCount(2)
        ->and(ApiEventTracker::$events[0]['event'])->toBe('updating')
        ->and(ApiEventTracker::$events[1]['event'])->toBe('updated')
        ->and(ApiEventTracker::$events[0]['source'])->toBe('api');
});

it('api delete fires deleting and deleted with source api', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Delete Event', 'email' => 'delete@api-event.test']);

    pest_test_case()->deleteJson('/api/admin/resources/api-event-records/'.$record->getKey())
        ->assertOk();

    expect(ApiEventTracker::$events)->toHaveCount(2)
        ->and(ApiEventTracker::$events[0]['event'])->toBe('deleting')
        ->and(ApiEventTracker::$events[1]['event'])->toBe('deleted')
        ->and(ApiEventTracker::$events[0]['source'])->toBe('api');
});

it('api event exception rolls back create', function (): void {
    ApiEventTracker::$throwOnCreating = true;

    pest_test_case()->postJson('/api/admin/resources/api-event-records', [
        'name' => 'Blocked by Event',
        'email' => 'blocked@api-event.test',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['event']);

    expect(ApiEndpointRecord::query()->where('email', 'blocked@api-event.test')->exists())->toBeFalse();
});

it('api updating event exception rolls back update', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Before Fail', 'email' => 'before-fail@api-event.test']);
    ApiEventTracker::$throwOnUpdating = true;

    pest_test_case()->patchJson('/api/admin/resources/api-event-records/'.$record->getKey(), [
        'name' => 'After Fail',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['event']);

    expect($record->fresh()?->name)->toBe('Before Fail');
});

it('api event handlers fire once', function (): void {
    $record = ApiEndpointRecord::query()->create(['name' => 'Once', 'email' => 'once@api-event.test']);

    pest_test_case()->patchJson('/api/admin/resources/api-event-records/'.$record->getKey(), [
        'name' => 'Once Updated',
    ])->assertOk();

    expect(count(ApiEventTracker::$events))->toBe(2);
});

function reloadApiRoutes(): void
{
    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);
}

function grantApiPermission(ApiEndpointUser $user, string $resource, string $ability): void
{
    $slug = sprintf('%s-%s-%s', $user->getKey(), $resource, $ability);

    $role = Role::query()->create([
        'name' => 'Role '.$slug,
        'slug' => $slug,
    ]);

    RoleAssignment::query()->create([
        'role_id' => $role->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
    ]);

    Permission::query()->create([
        'role_id' => $role->id,
        'resource' => $resource,
        'ability' => $ability,
        'allowed' => true,
    ]);
}

final class ApiWriteableRecordResource extends Resource
{
    protected static string $model = ApiEndpointRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name', 'email', 'company_id'])
            ->creatable(['name', 'email', 'company_id'])
            ->updatable(['name', 'status', 'company_id'])
            ->requiredOnCreate(['name', 'email'])
            ->requiredOnUpdate(['name'])
            ->deletable();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email'),
                TextColumn::make('status')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'paid' => 'Paid',
                ]),
            ]);
    }

    public static function workflow(Workflow $workflow): Workflow
    {
        return $workflow
            ->stateField('status')
            ->initialState('draft')
            ->states([
                State::make('draft'),
                State::make('published'),
            ])
            ->transitions([
                Transition::make('publish')->from('draft')->to('published'),
            ]);
    }
}

final class ApiEventRecordResource extends Resource
{
    protected static string $model = ApiEndpointRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return ApiWriteableRecordResource::api($api);
    }

    public static function events(ResourceEvents $events): ResourceEvents
    {
        return $events
            ->onCreating(function (ApiEndpointRecord $record, ResourceEventContext $context): void {
                ApiEventTracker::$events[] = [
                    'event' => 'creating',
                    'source' => $context->source(),
                ];
                if (ApiEventTracker::$throwOnCreating) {
                    throw new InvalidArgumentException('Event rejected API create.');
                }
            })
            ->onCreated(function (ApiEndpointRecord $record, ResourceEventContext $context): void {
                ApiEventTracker::$events[] = [
                    'event' => 'created',
                    'source' => $context->source(),
                ];
            })
            ->onUpdating(function (ApiEndpointRecord $record, ResourceEventContext $context): void {
                ApiEventTracker::$events[] = [
                    'event' => 'updating',
                    'source' => $context->source(),
                ];
                if (ApiEventTracker::$throwOnUpdating) {
                    throw new InvalidArgumentException('Event rejected API update.');
                }
            })
            ->onUpdated(function (ApiEndpointRecord $record, ResourceEventContext $context): void {
                ApiEventTracker::$events[] = [
                    'event' => 'updated',
                    'source' => $context->source(),
                ];
            })
            ->onDeleting(function (ApiEndpointRecord $record, ResourceEventContext $context): void {
                ApiEventTracker::$events[] = [
                    'event' => 'deleting',
                    'source' => $context->source(),
                ];
            })
            ->onDeleted(function (ApiEndpointRecord $record, ResourceEventContext $context): void {
                ApiEventTracker::$events[] = [
                    'event' => 'deleted',
                    'source' => $context->source(),
                ];
            });
    }
}

final class ApiDisabledRecordResource extends Resource
{
    protected static string $model = ApiEndpointRecord::class;
}

final class ApiNonDeletableRecordResource extends Resource
{
    protected static string $model = ApiEndpointRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name'])
            ->creatable(['name'])
            ->updatable(['name']);
    }
}

final class ApiEmptyCreateRecordResource extends Resource
{
    protected static string $model = ApiEndpointRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name']);
    }
}

final class ApiEmptyUpdateRecordResource extends Resource
{
    protected static string $model = ApiEndpointRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name']);
    }
}

final class ApiSoftDeleteRecordResource extends Resource
{
    protected static string $model = ApiSoftRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name'])
            ->deletable();
    }
}

/**
 * @property string $name
 * @property string|null $email
 * @property int|null $company_id
 * @property string|null $status
 * @property string|null $secret
 */
final class ApiEndpointRecord extends Model
{
    protected $table = 'api_endpoint_records';

    protected $guarded = [];
}

final class ApiSoftRecord extends Model
{
    use SoftDeletes;

    protected $table = 'api_soft_records';

    protected $guarded = [];
}

final class ApiEndpointUser extends AuthenticatableUser
{
    protected $table = 'users';

    protected $guarded = [];
}

final class ApiTokenEndpointUser extends AuthenticatableUser
{
    public static array $abilities = [];

    protected $table = 'users';

    protected $guarded = [];

    public function currentAccessToken(): object
    {
        return new stdClass;
    }

    public function tokenCan(string $ability): bool
    {
        return in_array($ability, self::$abilities, true);
    }
}

final class ApiEventTracker
{
    /** @var array<int, array{event:string,source:string}> */
    public static array $events = [];

    public static bool $throwOnCreating = false;

    public static bool $throwOnUpdating = false;

    public static function reset(): void
    {
        self::$events = [];
        self::$throwOnCreating = false;
        self::$throwOnUpdating = false;
    }
}
