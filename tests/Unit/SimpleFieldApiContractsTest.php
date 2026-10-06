<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SeviaFlow\Api\ApiPayloadSanitizer;
use SeviaFlow\Api\ApiResourceSerializer;
use SeviaFlow\Api\OpenApi\OpenApiFieldSchemaMapper;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Fields\CheckboxGroupField;
use SeviaFlow\Core\Resources\Fields\ColorField;
use SeviaFlow\Core\Resources\Fields\JsonField;
use SeviaFlow\Core\Resources\Fields\KeyValueField;
use SeviaFlow\Core\Resources\Fields\RadioGroupField;
use SeviaFlow\Core\Resources\Fields\RichTextField;
use SeviaFlow\Core\Resources\Fields\TagsField;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\Resource;

it('maps all simple and rich text fields to explicit API schemas', function (): void {
    $fields = SimpleFieldApiResource::form(new Form)->getAllFields();
    $schemas = array_map(
        static fn ($field): array => (new OpenApiFieldSchemaMapper)->map($field),
        $fields,
    );

    expect($schemas)->toHaveCount(7)
        ->and($schemas[0]['type'])->toBe('string')
        ->and($schemas[1]['type'])->toBe('array')
        ->and($schemas[2]['uniqueItems'])->toBeTrue()
        ->and($schemas[3]['type'])->toBe('object')
        ->and($schemas[4]['x-seviaflow-json']['root'])->toBe('object')
        ->and($schemas[5]['pattern'])->toBe('^#[0-9A-Fa-f]{6}$')
        ->and($schemas[6]['contentMediaType'])->toBe('text/html')
        ->and($schemas[6]['x-seviaflow-richtext']['format'])->toBe('sanitized-html');
});

it('roundtrips valid simple field API payloads through field preparation', function (): void {
    $payload = (new ApiPayloadSanitizer)->sanitizeForCreate(SimpleFieldApiResource::class, [
        'kind' => ' person ',
        'channels' => ['email', 'sms'],
        'tags' => 'vip, eu, vip',
        'metadata' => '{"region":"EU"}',
        'payload' => '{"enabled":true}',
        'brand_color' => ' #aabbcc ',
        'description' => '<p>Zażółć <strong>gęślą</strong></p><a href="https://example.test">Link</a><script>alert(1)</script>',
    ]);

    expect($payload)->toBe([
        'kind' => 'person',
        'channels' => ['email', 'sms'],
        'tags' => ['vip', 'eu'],
        'metadata' => ['region' => 'EU'],
        'payload' => ['enabled' => true],
        'brand_color' => '#AABBCC',
        'description' => '<p>Zażółć <strong>gęślą</strong></p><a href="https://example.test">Link</a>',
    ]);
});

it('sanitizes legacy rich text while serializing readable API data', function (): void {
    $serialized = (new ApiResourceSerializer)->serialize(SimpleFieldApiResource::class, [
        'description' => '<div data-wrapper="unsafe"><span onclick="alert(1)"><p onmouseover="alert(2)">Stary tekst</p>'
            .'<svg><script>alert(3)</script></svg></span></div>',
    ]);

    expect($serialized['description'])->toBe('<p>Stary tekst</p>');
});

it('rejects invalid roots, collections and colors at the API boundary', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(SimpleFieldApiResource::class, [
        'kind' => 'person',
        'channels' => ['fax'],
        'tags' => 'one',
        'metadata' => '["not-a-map"]',
        'payload' => '[1,2,3]',
        'brand_color' => 'red',
        'description' => str_repeat('<p>x</p>', 20000),
    ]))->toThrow(ValidationException::class);
});

final class SimpleFieldApiRecord extends Model
{
    protected $table = 'simple_field_api_records';

    protected $guarded = [];

    public $timestamps = false;
}

final class SimpleFieldApiResource extends Resource
{
    protected static string $model = SimpleFieldApiRecord::class;

    public static function form(Form $form): Form
    {
        return $form->fields([
            RadioGroupField::make('kind')->options(['person' => 'Person', 'company' => 'Company']),
            CheckboxGroupField::make('channels')->options(['email' => 'Email', 'sms' => 'SMS']),
            TagsField::make('tags')->maxTags(5),
            KeyValueField::make('metadata')->maxEntries(5),
            JsonField::make('payload')->rootType('object'),
            ColorField::make('brand_color'),
            RichTextField::make('description')->maxBytes(4096),
        ]);
    }

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'kind', 'channels', 'tags', 'metadata', 'payload', 'brand_color', 'description'])
            ->creatable(['kind', 'channels', 'tags', 'metadata', 'payload', 'brand_color', 'description'])
            ->requiredOnCreate(['kind'])
            ->useFormFieldRules();
    }
}
