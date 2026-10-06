<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SeviaFlow\Api\ApiPayloadSanitizer;
use SeviaFlow\Api\OpenApi\OpenApiFieldSchemaMapper;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Fields\MoneyField;
use SeviaFlow\Core\Resources\Fields\NumberField;
use SeviaFlow\Core\Resources\Fields\RepeaterField;
use SeviaFlow\Core\Resources\Fields\SelectField;
use SeviaFlow\Core\Resources\Fields\TextField;
use SeviaFlow\Core\Resources\Fields\ToggleField;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\Resource;

it('maps and roundtrips the bounded repeater API contract', function (): void {
    $field = RepeaterField::make('items')
        ->fields([
            TextField::make('label')->required(),
            NumberField::make('quantity')->min(1),
            MoneyField::make('amount')->scale(2),
            SelectField::make('kind')->options(['service' => 'Service']),
            ToggleField::make('enabled'),
        ])
        ->maxItems(3);
    $schema = (new OpenApiFieldSchemaMapper)->map($field);

    expect($schema['type'])->toBe('array')
        ->and($schema['items']['additionalProperties'])->toBeFalse()
        ->and($schema['x-seviaflow-repeater']['allowedItemTypes'])
        ->toBe(['text', 'number', 'money', 'select', 'toggle']);

    $payload = (new ApiPayloadSanitizer)->sanitizeForCreate(RepeaterApiResource::class, [
        'items' => '[{"label":"  Usługa  ","quantity":"2","amount":"12.50","kind":"service","enabled":"true"}]',
    ]);

    expect($payload)->toBe([
        'items' => [[
            'label' => 'Usługa',
            'quantity' => '2',
            'amount' => '12.50',
            'kind' => 'service',
            'enabled' => true,
        ]],
    ]);
});

it('rejects unknown nested keys, nested repeaters and excessive rows at the API boundary', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(RepeaterApiResource::class, [
        'items' => [[
            'label' => 'Unsafe',
            '_ui_key' => 'not-a-domain-value',
        ]],
    ]))->toThrow(ValidationException::class);

    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(RepeaterApiResource::class, [
        'items' => array_fill(0, 4, ['label' => 'Too many']),
    ]))->toThrow(ValidationException::class);
});

final class RepeaterApiRecord extends Model
{
    protected $table = 'repeater_api_records';

    protected $guarded = [];

    public $timestamps = false;
}

final class RepeaterApiResource extends Resource
{
    protected static string $model = RepeaterApiRecord::class;

    public static function form(Form $form): Form
    {
        return $form->fields([
            RepeaterField::make('items')
                ->fields([
                    TextField::make('label')->required(),
                    NumberField::make('quantity')->min(1),
                    MoneyField::make('amount')->scale(2),
                    SelectField::make('kind')->options(['service' => 'Service']),
                    ToggleField::make('enabled'),
                ])
                ->maxItems(3),
        ]);
    }

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->creatable(['items'])
            ->readable(['items'])
            ->useFormFieldRules();
    }
}
