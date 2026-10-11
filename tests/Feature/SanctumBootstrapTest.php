<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\SanctumServiceProvider;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Resource;
use Tests\TestCase;

final class SanctumBootstrapTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', SanctumBootstrapUser::class);
        $app['config']->set('seviaflow.resources', ['sanctum-customers' => SanctumCustomerResource::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });

        Schema::create('sanctum_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Gate::policy(SanctumCustomer::class, SanctumCustomerPolicy::class);
        SanctumCustomer::query()->create(['name' => 'Example customer', 'email' => 'private@example.test']);
    }

    public function test_default_sanctum_middleware_denies_anonymous_access(): void
    {
        $this->getJson('/api/admin/resources/sanctum-customers')->assertUnauthorized();
    }

    public function test_valid_token_does_not_bypass_the_resource_policy(): void
    {
        $user = SanctumBootstrapUser::query()->create(['name' => 'Restricted user']);
        $token = $user->createToken('test')->plainTextToken;

        $this->getJson('/api/admin/resources/sanctum-customers', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();
    }

    public function test_authorized_token_reads_only_allowed_fields_without_a_license(): void
    {
        $user = SanctumBootstrapUser::query()->create(['name' => 'Operator', 'is_admin' => true]);
        $token = $user->createToken('test')->plainTextToken;

        $this->assertFalse(class_exists('SeviaFlow\\Licensing\\LicenseGate'));
        $this->getJson('/api/admin/resources/sanctum-customers', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Example customer')
            ->assertJsonMissingPath('data.0.email');
    }

    public function test_revoked_token_is_denied(): void
    {
        $user = SanctumBootstrapUser::query()->create(['name' => 'Operator', 'is_admin' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $user->tokens()->delete();

        $this->getJson('/api/admin/resources/sanctum-customers', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
    }
}

final class SanctumBootstrapUser extends AuthenticatableUser
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['is_admin' => 'boolean'];
}

final class SanctumCustomer extends Model
{
    protected $table = 'sanctum_customers';

    protected $guarded = [];
}

final class SanctumCustomerPolicy
{
    public function viewAny(SanctumBootstrapUser $user): bool
    {
        return $user->getAttribute('is_admin') === true;
    }

    public function view(SanctumBootstrapUser $user, SanctumCustomer $customer): bool
    {
        return $user->getAttribute('is_admin') === true;
    }
}

final class SanctumCustomerResource extends Resource
{
    protected static string $model = SanctumCustomer::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api->enabled()->readable(['id', 'name']);
    }
}
