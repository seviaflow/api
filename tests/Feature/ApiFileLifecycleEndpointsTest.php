<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SeviaFlow\Api\SeviaFlowApiServiceProvider;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Fields\FileField;
use SeviaFlow\Core\Resources\Fields\TextField;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceRegistry;

beforeEach(function (): void {
    config()->set('seviaflow-api.enabled', true);
    config()->set('seviaflow-api.middleware', ['api', 'web']);
    config()->set('seviaflow.resources', [
        'api-file-lifecycle-records' => ApiFileLifecycleResource::class,
    ]);

    Schema::create('api_file_lifecycle_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('document_path')->nullable();
        $table->string('thumbnail_path')->nullable();
        $table->string('local_path')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    reloadFileApiRoutes();
});

it('keeps a same-record reference during a partial API replacement and removes it last', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('documents/same-record-api.pdf', 'shared');
    $record = ApiFileLifecycleRecord::query()->create([
        'name' => 'Same record',
        'document_path' => 'documents/same-record-api.pdf',
        'thumbnail_path' => 'documents/same-record-api.pdf',
    ]);

    pest_test_case()->patch(
        '/api/admin/resources/api-file-lifecycle-records/'.$record->getKey(),
        ['document_path' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')]
    )->assertOk();

    $record->refresh();
    expect($record->thumbnail_path)->toBe('documents/same-record-api.pdf')
        ->and($record->document_path)->not->toBe('documents/same-record-api.pdf');
    Storage::disk('public')->assertExists('documents/same-record-api.pdf');
    Storage::disk('public')->assertExists((string) $record->document_path);

    pest_test_case()->patch(
        '/api/admin/resources/api-file-lifecycle-records/'.$record->getKey(),
        ['thumbnail_path' => 'remove']
    )->assertOk();

    Storage::disk('public')->assertMissing('documents/same-record-api.pdf');
});

it('keeps a reference owned by another record during a partial API removal', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('documents/other-record-api.pdf', 'shared');
    $first = ApiFileLifecycleRecord::query()->create([
        'name' => 'First record',
        'document_path' => 'documents/other-record-api.pdf',
    ]);
    $second = ApiFileLifecycleRecord::query()->create([
        'name' => 'Second record',
        'thumbnail_path' => 'documents/other-record-api.pdf',
    ]);

    pest_test_case()->patch(
        '/api/admin/resources/api-file-lifecycle-records/'.$first->getKey(),
        ['document_path' => 'remove']
    )->assertOk();

    Storage::disk('public')->assertExists('documents/other-record-api.pdf');
    expect($second->fresh()?->thumbnail_path)->toBe('documents/other-record-api.pdf');

    pest_test_case()->patch(
        '/api/admin/resources/api-file-lifecycle-records/'.$second->getKey(),
        ['thumbnail_path' => 'remove']
    )->assertOk();

    Storage::disk('public')->assertMissing('documents/other-record-api.pdf');
});

it('keeps the same path on another disk outside the reference set', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    Storage::disk('public')->put('documents/same-path-api.pdf', 'public');
    Storage::disk('local')->put('documents/same-path-api.pdf', 'local');
    $record = ApiFileLifecycleRecord::query()->create([
        'name' => 'Different disks',
        'document_path' => 'documents/same-path-api.pdf',
        'local_path' => 'documents/same-path-api.pdf',
    ]);

    pest_test_case()->patch(
        '/api/admin/resources/api-file-lifecycle-records/'.$record->getKey(),
        ['document_path' => 'remove']
    )->assertOk();

    Storage::disk('public')->assertMissing('documents/same-path-api.pdf');
    Storage::disk('local')->assertExists('documents/same-path-api.pdf');
});

function reloadFileApiRoutes(): void
{
    app('router')->setRoutes(new RouteCollection);
    app()->register(SeviaFlowApiServiceProvider::class, true);
    app()->forgetInstance(ResourceRegistry::class);
    app()->instance(ResourceRegistry::class, new ResourceRegistry(
        config('seviaflow.resources', [])
    ));
}

final class ApiFileLifecycleResource extends Resource
{
    protected static string $model = ApiFileLifecycleRecord::class;

    public static function form(Form $form): Form
    {
        return $form->fields([
            TextField::make('name'),
            FileField::make('document_path')->disk('public')->directory('documents'),
            FileField::make('thumbnail_path')->disk('public')->directory('documents'),
            FileField::make('local_path')->disk('local')->directory('documents'),
        ]);
    }

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name', 'document_path', 'thumbnail_path', 'local_path'])
            ->creatable(['name', 'document_path', 'thumbnail_path', 'local_path'])
            ->updatable(['name', 'document_path', 'thumbnail_path', 'local_path'])
            ->deletable();
    }
}

/**
 * @property string $name
 * @property string|null $document_path
 * @property string|null $thumbnail_path
 * @property string|null $local_path
 */
final class ApiFileLifecycleRecord extends Model
{
    use SoftDeletes;

    protected $table = 'api_file_lifecycle_records';

    protected $guarded = [];
}
