<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SeviaFlow\Api\ApiPayloadSanitizer;
use SeviaFlow\Api\ApiResourceSerializer;
use SeviaFlow\Core\Operations\ResourceOperationService;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Fields\BelongsToManyField;
use SeviaFlow\Core\Resources\Fields\TextField;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\ResourceContext;

beforeEach(function (): void {
    Schema::create('choice_api_records', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('choice_api_labels', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->boolean('allowed')->default(true);
    });
    Schema::create('choice_api_pivot', function (Blueprint $table): void {
        $table->foreignId('record_id');
        $table->foreignId('label_id');
    });
});

it('roundtrips relation IDs through the API without exposing related model attributes', function (): void {
    $label = ChoiceApiLabel::query()->create(['name' => 'Private label']);
    $payload = (new ApiPayloadSanitizer)->sanitizeForCreate(ChoiceApiResource::class, ['name' => 'Record', 'labels' => [$label->getKey()]]);
    $record = new ChoiceApiRecord;
    (new ResourceOperationService)->persist($record, ChoiceApiResource::form(new Form)->getAllFields(), $payload, ResourceContext::Create);
    expect((new ApiResourceSerializer)->serialize(ChoiceApiResource::class, $record))
        ->toBe(['name' => 'Record', 'labels' => [(string) $label->getKey()]]);
    $update = (new ApiPayloadSanitizer)->sanitizeForUpdate(ChoiceApiResource::class, ['name' => 'Renamed'], $record);
    (new ResourceOperationService)->persist($record, ChoiceApiResource::form(new Form)->getAllFields(), $update, ResourceContext::Edit);
    expect($record->labels()->count())->toBe(1);
});

it('rejects an out-of-scope relation at the API boundary', function (): void {
    $label = ChoiceApiLabel::query()->create(['name' => 'Forbidden', 'allowed' => false]);
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(ChoiceApiResource::class, ['name' => 'Record', 'labels' => [$label->getKey()]]))
        ->toThrow(ValidationException::class);
});

final class ChoiceApiRecord extends Model
{
    protected $table = 'choice_api_records';

    protected $guarded = [];

    public $timestamps = false;

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ChoiceApiLabel::class, 'choice_api_pivot', 'record_id', 'label_id');
    }
}

final class ChoiceApiLabel extends Model
{
    protected $table = 'choice_api_labels';

    protected $guarded = [];

    public $timestamps = false;
}

final class ChoiceApiResource extends Resource
{
    protected static string $model = ChoiceApiRecord::class;

    public static function form(Form $form): Form
    {
        return $form->fields([TextField::make('name'), BelongsToManyField::make('labels')->relationship('labels', 'name')->query(static fn ($query) => $query->where('allowed', true))]);
    }

    public static function api(ApiResource $api): ApiResource
    {
        return $api->enabled()->creatable(['name', 'labels'])->updatable(['name', 'labels'])->readable(['name', 'labels'])->useFormFieldRules();
    }
}
