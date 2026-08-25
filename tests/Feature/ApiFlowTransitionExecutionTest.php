<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceRegistry;
use SeviaFlow\Core\Resources\Workflows\State;
use SeviaFlow\Core\Resources\Workflows\Transition;
use SeviaFlow\Core\Resources\Workflows\TransitionContext;
use SeviaFlow\Core\Resources\Workflows\Workflow;
use SeviaFlow\Core\Workflows\WorkflowActivity;

beforeEach(function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.middleware', ['api', 'web']);
    config()->set('seviaflow.resources', [
        'api-flow-exec-records' => ApiFlowExecRecordResource::class,
    ]);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    Schema::create('api_flow_exec_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('status')->nullable();
        $table->string('note')->nullable();
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

    Schema::create('seviaflow_workflow_activities', function (Blueprint $table): void {
        $table->id();
        $table->string('resource');
        $table->string('record_type');
        $table->string('record_id');
        $table->string('transition');
        $table->string('from_state')->nullable();
        $table->string('to_state');
        $table->string('user_type')->nullable();
        $table->string('user_id')->nullable();
        $table->text('comment')->nullable();
        $table->timestamps();
    });

    if (Schema::hasTable('seviaflow_workflow_activities')) {
        DB::table('seviaflow_workflow_activities')->delete();
    }

    reloadFlowExecuteRoutes();
});

it('executes valid transition and returns state payload', function (): void {
    $record = ApiFlowExecRecord::query()->create([
        'name' => 'Order 1',
        'status' => 'pending_payment',
    ]);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/mark_paid', [
        'confirmed' => true,
    ])->assertOk()
        ->assertJsonPath('data.id', $record->getKey())
        ->assertJsonPath('data.state.name', 'paid')
        ->assertJsonPath('data.state.label', 'Paid')
        ->assertJsonPath('data.state.color', 'green');
});

it('changes record state when transition executes', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 2', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/mark_paid', [
        'confirmed' => true,
    ])->assertOk();

    expect($record->fresh()?->status)->toBe('paid');
});

it('logs workflow activity on transition execution', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 3', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/mark_paid', [
        'confirmed' => true,
    ])->assertOk();

    $activity = WorkflowActivity::query()->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->transition)->toBe('mark_paid')
        ->and($activity?->from_state)->toBe('pending_payment')
        ->and($activity?->to_state)->toBe('paid');
});

it('rejects invalid transition from current state', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 4', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/ship')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transition']);
});

it('rejects unknown transition', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 5', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/unknown')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transition']);
});

it('requires confirmation when transition requires confirmation', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 6', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/mark_paid')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['transition']);
});

it('requires comment when transition requires comment', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 7', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/reject', [
        'confirmed' => true,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['transition']);
});

it('accepts comment when provided and stores it in activity log', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 7b', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/reject', [
        'confirmed' => true,
        'comment' => 'Payment failed',
    ])->assertOk()
        ->assertJsonPath('data.state.name', 'rejected');

    $activity = WorkflowActivity::query()->latest('id')->first();

    expect($activity?->comment)->toBe('Payment failed');
});

it('denies unauthorized execution', function (): void {
    config()->set('seviaflow.permissions.enabled', true);
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 8', 'status' => 'pending_payment']);
    $user = ApiFlowExecUser::query()->create(['name' => 'No Update']);
    pest_test_case()->actingAs($user, 'web');

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/mark_paid', [
        'confirmed' => true,
    ])->assertForbidden();
});

it('returns safe error and rolls back state when handler throws', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 9', 'status' => 'pending_payment']);

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/explode', [
        'confirmed' => true,
    ])->assertStatus(422)
        ->assertJsonPath('message', 'The transition could not be completed.');

    expect($record->fresh()?->status)->toBe('pending_payment')
        ->and(WorkflowActivity::query()->count())->toBe(0);
});

it('workflow state cannot be bypassed by patch but can change via flow endpoint', function (): void {
    $record = ApiFlowExecRecord::query()->create(['name' => 'Order 10', 'status' => 'pending_payment']);

    pest_test_case()->patchJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey(), [
        'name' => 'Renamed',
        'status' => 'paid',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($record->fresh()?->status)->toBe('pending_payment');

    pest_test_case()->postJson('/api/admin/resources/api-flow-exec-records/'.$record->getKey().'/flow/mark_paid', [
        'confirmed' => true,
    ])->assertOk()
        ->assertJsonPath('data.state.name', 'paid');

    expect($record->fresh()?->status)->toBe('paid');
});

function reloadFlowExecuteRoutes(): void
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

final class ApiFlowExecRecordResource extends Resource
{
    protected static string $model = ApiFlowExecRecord::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->flow()
            ->readable(['id', 'name', 'status'])
            ->updatable(['name', 'status'])
            ->requiredOnUpdate(['name']);
    }

    public static function workflow(Workflow $workflow): Workflow
    {
        return $workflow
            ->stateField('status')
            ->states([
                State::make('pending_payment')->color('yellow'),
                State::make('paid')->color('green'),
                State::make('rejected')->color('red'),
            ])
            ->transitions([
                Transition::make('mark_paid')
                    ->from('pending_payment')
                    ->to('paid')
                    ->requiresConfirmation()
                    ->successMessage('Order marked as paid.'),
                Transition::make('ship')
                    ->from('paid')
                    ->to('paid'),
                Transition::make('reject')
                    ->from('pending_payment')
                    ->to('rejected')
                    ->requiresComment(),
                Transition::make('explode')
                    ->from('pending_payment')
                    ->to('paid')
                    ->handle(ApiFlowExecFailingHandler::class),
            ]);
    }
}

final class ApiFlowExecFailingHandler
{
    public function __invoke(ApiFlowExecRecord $record, TransitionContext $context): void
    {
        throw new RuntimeException('handler failed');
    }
}

/**
 * @property string $name
 * @property string|null $status
 * @property string|null $note
 */
final class ApiFlowExecRecord extends Model
{
    protected $table = 'api_flow_exec_records';

    protected $guarded = [];
}

final class ApiFlowExecUser extends AuthenticatableUser
{
    protected $table = 'users';

    protected $guarded = [];
}
