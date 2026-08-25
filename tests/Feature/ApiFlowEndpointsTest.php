<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Schema;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceRegistry;
use SeviaFlow\Core\Resources\Workflows\State;
use SeviaFlow\Core\Resources\Workflows\Transition;
use SeviaFlow\Core\Resources\Workflows\Workflow;

beforeEach(function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.middleware', ['api', 'web']);
    config()->set('seviaflow.resources', [
        'api-flow-records' => ApiFlowRecordResource::class,
        'api-no-flow-records' => ApiNoFlowRecordResource::class,
        'api-flow-disabled-records' => ApiFlowDisabledRecordResource::class,
    ]);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    Schema::create('api_flow_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('status')->nullable();
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

    reloadFlowApiRoutes();
});

it('returns current workflow state', function (): void {
    $record = ApiFlowRecord::query()->create([
        'name' => 'Order 1',
        'status' => 'pending_payment',
    ]);

    pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertOk()
        ->assertJsonPath('data.state.name', 'pending_payment')
        ->assertJsonPath('data.state.label', 'Pending payment')
        ->assertJsonPath('data.state.color', 'yellow')
        ->assertJsonPath('data.state.final', false);
});

it('returns available transitions for current state', function (): void {
    $record = ApiFlowRecord::query()->create([
        'name' => 'Order 2',
        'status' => 'pending_payment',
    ]);

    $response = pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertOk()
        ->json('data.available_transitions');

    $names = array_map(static fn (array $transition): string => (string) $transition['name'], $response);

    expect($names)->toContain('mark_paid')
        ->toContain('cancel');
    expect(in_array('ship', $names, true))->toBeFalse();
});

it('does not list transitions that do not match current state', function (): void {
    $record = ApiFlowRecord::query()->create([
        'name' => 'Order 3',
        'status' => 'paid',
    ]);

    $response = pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertOk()
        ->json('data.available_transitions');

    $names = array_map(static fn (array $transition): string => (string) $transition['name'], $response);

    expect($names)->toContain('ship');
    expect(in_array('mark_paid', $names, true))->toBeFalse();
    expect(in_array('cancel', $names, true))->toBeFalse();
});

it('does not list hidden transitions', function (): void {
    $record = ApiFlowRecord::query()->create([
        'name' => 'Order 4',
        'status' => 'pending_payment',
    ]);

    $response = pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertOk()
        ->json('data.available_transitions');

    $names = array_map(static fn (array $transition): string => (string) $transition['name'], $response);

    expect(in_array('internal_review', $names, true))->toBeFalse();
});

it('does not list unauthorized transitions', function (): void {
    $record = ApiFlowRecord::query()->create([
        'name' => 'Order 5',
        'status' => 'pending_payment',
    ]);

    $blockedUser = ApiFlowUser::query()->create(['name' => 'blocked-user']);
    pest_test_case()->actingAs($blockedUser, 'web');

    $response = pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertOk()
        ->json('data.available_transitions');

    $names = array_map(static fn (array $transition): string => (string) $transition['name'], $response);

    expect(in_array('manager_override', $names, true))->toBeFalse();
});

it('returns 404 when resource has no workflow', function (): void {
    $record = ApiFlowRecord::query()->create(['name' => 'No Flow']);

    pest_test_case()->getJson('/api/admin/resources/api-no-flow-records/'.$record->getKey().'/flow')
        ->assertNotFound();
});

it('returns 404 when resource api flow is disabled', function (): void {
    $record = ApiFlowRecord::query()->create(['name' => 'Flow Disabled']);

    pest_test_case()->getJson('/api/admin/resources/api-flow-disabled-records/'.$record->getKey().'/flow')
        ->assertNotFound();
});

it('returns 404 when global api is disabled', function (): void {
    config()->set('seviaflow-api.enabled', false);
    reloadFlowApiRoutes();

    $record = ApiFlowRecord::query()->create(['name' => 'Global Disabled']);

    pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertNotFound();
});

it('denies unauthorized user', function (): void {
    config()->set('seviaflow.permissions.enabled', true);
    $record = ApiFlowRecord::query()->create(['name' => 'Protected', 'status' => 'pending_payment']);
    $user = ApiFlowUser::query()->create(['name' => 'No View']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertForbidden();
});

it('does not expose transition handlers', function (): void {
    $record = ApiFlowRecord::query()->create([
        'name' => 'Order 6',
        'status' => 'pending_payment',
    ]);

    $response = pest_test_case()->getJson('/api/admin/resources/api-flow-records/'.$record->getKey().'/flow')
        ->assertOk();

    $available = $response->json('data.available_transitions');
    expect(array_key_exists('handler', $available[0] ?? []))->toBeFalse();
    expect(str_contains($response->getContent(), ApiFlowTransitionHandler::class))->toBeFalse();
});

function reloadFlowApiRoutes(): void
{
    app('router')->setRoutes(new RouteCollection);
    app()->forgetInstance(ResourceRegistry::class);
    app()->instance(ResourceRegistry::class, new ResourceRegistry(
        config('seviaflow.resources', [])
    ));
    if ((bool) config('seviaflow-api.enabled', false)) {
        require __DIR__.'/../../routes/api.php';
    }
}

final class ApiFlowRecordResource extends Resource
{
    protected static string $model = ApiFlowRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->flow()
            ->readable(['id', 'name', 'status']);
    }

    public static function workflow(Workflow $workflow): Workflow
    {
        return $workflow
            ->stateField('status')
            ->initialState('pending_payment')
            ->states([
                State::make('pending_payment')->color('yellow'),
                State::make('paid')->color('green'),
                State::make('shipped')->color('blue'),
                State::make('cancelled')->color('red')->final(),
            ])
            ->transitions([
                Transition::make('mark_paid')
                    ->label('Mark as paid')
                    ->from('pending_payment')
                    ->to('paid')
                    ->requiresConfirmation()
                    ->handle(ApiFlowTransitionHandler::class),
                Transition::make('cancel')
                    ->from('pending_payment')
                    ->to('cancelled')
                    ->danger()
                    ->requiresComment(),
                Transition::make('ship')
                    ->from('paid')
                    ->to('shipped'),
                Transition::make('internal_review')
                    ->from('pending_payment')
                    ->to('pending_payment')
                    ->visibleIf(static fn (): bool => false),
                Transition::make('manager_override')
                    ->from('pending_payment')
                    ->to('paid')
                    ->authorize(static fn (mixed $user): bool => $user instanceof ApiFlowUser && $user->name === 'allowed-user'),
            ]);
    }
}

final class ApiNoFlowRecordResource extends Resource
{
    protected static string $model = ApiFlowRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->flow()
            ->readable(['id', 'name']);
    }
}

final class ApiFlowDisabledRecordResource extends Resource
{
    protected static string $model = ApiFlowRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name', 'status']);
    }

    public static function workflow(Workflow $workflow): Workflow
    {
        return ApiFlowRecordResource::workflow($workflow);
    }
}

final class ApiFlowTransitionHandler
{
    public function __invoke(ApiFlowRecord $record): void
    {
        $record->setAttribute('status', 'paid');
    }
}

/**
 * @property string $name
 * @property string|null $status
 */
final class ApiFlowRecord extends Model
{
    protected $table = 'api_flow_records';

    protected $guarded = [];
}

/**
 * @property string $name
 */
final class ApiFlowUser extends AuthenticatableUser
{
    protected $table = 'users';

    protected $guarded = [];
}
