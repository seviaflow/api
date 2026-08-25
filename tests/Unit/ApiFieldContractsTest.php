<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SeviaFlow\Api\ApiPayloadSanitizer;
use SeviaFlow\Api\ApiResourceSerializer;
use SeviaFlow\Api\ApiValidationRules;
use SeviaFlow\Core\Resources\ApiResource;
use SeviaFlow\Core\Resources\Fields\EmailField;
use SeviaFlow\Core\Resources\Fields\PasswordField;
use SeviaFlow\Core\Resources\Fields\TextField;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\Resource;
use SeviaFlow\Core\Resources\Workflows\State;
use SeviaFlow\Core\Resources\Workflows\Transition;
use SeviaFlow\Core\Resources\Workflows\Workflow;

it('serializes only readable fields', function (): void {
    $serializer = new ApiResourceSerializer;
    $record = new ApiContractTestModel;
    $record->forceFill([
        'id' => 1,
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'status' => 'draft',
        'password' => 'secret',
    ]);

    $serialized = $serializer->serialize(ApiContractResource::class, $record);

    expect($serialized)->toBe([
        'id' => 1,
        'name' => 'Jane',
        'email' => 'jane@example.com',
    ]);
});

it('excludes non readable fields from serialization', function (): void {
    $serializer = new ApiResourceSerializer;

    $serialized = $serializer->serialize(ApiContractResource::class, [
        'id' => 5,
        'name' => 'John',
        'email' => 'john@example.com',
        'status' => 'published',
    ]);

    expect($serialized)->toHaveKeys(['id', 'name', 'email'])
        ->and($serialized)->not->toHaveKey('status');
});

it('sanitizes create payload using creatable whitelist', function (): void {
    $sanitized = (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractResource::class, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
    ]);

    expect($sanitized)->toBe([
        'name' => 'Jane',
        'email' => 'jane@example.com',
    ]);
});

it('sanitizes update payload using updatable whitelist', function (): void {
    $sanitized = (new ApiPayloadSanitizer)->sanitizeForUpdate(ApiContractResource::class, [
        'name' => 'Updated Name',
        'status' => 'published',
    ]);

    expect($sanitized)->toBe([
        'name' => 'Updated Name',
        'status' => 'published',
    ]);
});

it('rejects unknown fields on create', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractResource::class, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'is_admin' => true,
    ]))->toThrow(ValidationException::class);
});

it('rejects unknown fields on update', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForUpdate(ApiContractResource::class, [
        'name' => 'Jane',
        'is_admin' => true,
    ]))->toThrow(ValidationException::class);
});

it('enforces required fields on create', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractResource::class, [
        'name' => 'Jane',
    ]))->toThrow(ValidationException::class);
});

it('enforces required fields on update', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForUpdate(ApiContractResource::class, [
        'status' => 'published',
    ]))->toThrow(ValidationException::class);
});

it('does not use form field rules for api validation by default', function (): void {
    $sanitized = (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractResource::class, [
        'name' => 'Jane',
        'email' => 'not-an-email',
    ]);

    expect($sanitized)->toBe([
        'name' => 'Jane',
        'email' => 'not-an-email',
    ]);
});

it('can opt in to use form field rules for api validation', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractFieldRulesResource::class, [
        'name' => 'Jane',
        'email' => 'not-an-email',
    ]))->toThrow(ValidationException::class);
});

it('validates only api allowed fields when form-field-rules opt-in is enabled', function (): void {
    $sanitized = (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractAllowedSubsetResource::class, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
    ]);

    expect($sanitized)->toBe([
        'name' => 'Jane',
        'email' => 'jane@example.com',
    ]);
});

it('blocks workflow state field on create when resource defines workflow', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractWorkflowResource::class, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'status' => 'published',
    ]))->toThrow(ValidationException::class);
});

it('blocks workflow state field on update when resource defines workflow', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForUpdate(ApiContractWorkflowResource::class, [
        'name' => 'Jane',
        'status' => 'published',
    ]))->toThrow(ValidationException::class);
});

it('validation rules expose required and nullable fields for create and update', function (): void {
    $rules = new ApiValidationRules;

    expect($rules->forCreate(ApiContractResource::class))->toBe([
        'name' => ['required'],
        'email' => ['required'],
    ])->and($rules->forUpdate(ApiContractResource::class))->toBe([
        'name' => ['required'],
        'status' => ['nullable'],
    ]);
});

it('validation rules exclude workflow state field from create and update', function (): void {
    $rules = new ApiValidationRules;

    expect($rules->forCreate(ApiContractWorkflowResource::class))->toBe([
        'name' => ['required'],
        'email' => ['required'],
    ])->and($rules->forUpdate(ApiContractWorkflowResource::class))->toBe([
        'name' => ['required'],
    ]);
});

it('keeps workflow state field protected when form-field-rules opt-in is enabled', function (): void {
    expect(fn () => (new ApiPayloadSanitizer)->sanitizeForCreate(ApiContractWorkflowWithFieldRulesResource::class, [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'status' => 'published',
    ]))->toThrow(ValidationException::class);
});

final class ApiContractTestModel extends Model
{
    protected $table = 'api_contract_test_models';

    protected $guarded = [];

    public $timestamps = false;
}

final class ApiContractResource extends Resource
{
    protected static string $model = ApiContractTestModel::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name', 'email'])
            ->creatable(['name', 'email'])
            ->updatable(['name', 'status'])
            ->requiredOnCreate(['name', 'email'])
            ->requiredOnUpdate(['name']);
    }
}

final class ApiContractWorkflowResource extends Resource
{
    protected static string $model = ApiContractTestModel::class;

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->readable(['id', 'name', 'email', 'status'])
            ->creatable(['name', 'email', 'status'])
            ->updatable(['name', 'status'])
            ->requiredOnCreate(['name', 'email', 'status'])
            ->requiredOnUpdate(['name', 'status']);
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

final class ApiContractFieldRulesResource extends Resource
{
    protected static string $model = ApiContractTestModel::class;

    public static function form(Form $form): Form
    {
        return $form->fields([
            TextField::make('name')->label('Name')->required()->minLength(3),
            EmailField::make('email')->label('Email address')->required(),
        ]);
    }

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->creatable(['name', 'email'])
            ->updatable(['name'])
            ->requiredOnCreate(['name', 'email'])
            ->requiredOnUpdate(['name'])
            ->useFormFieldRules();
    }
}

final class ApiContractAllowedSubsetResource extends Resource
{
    protected static string $model = ApiContractTestModel::class;

    public static function form(Form $form): Form
    {
        return $form->fields([
            TextField::make('name')->label('Name')->required(),
            EmailField::make('email')->label('Email address')->required(),
            PasswordField::make('password')->label('Password')->requiredOnCreate(),
        ]);
    }

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->creatable(['name', 'email'])
            ->requiredOnCreate(['name', 'email'])
            ->useFormFieldRules();
    }
}

final class ApiContractWorkflowWithFieldRulesResource extends Resource
{
    protected static string $model = ApiContractTestModel::class;

    public static function form(Form $form): Form
    {
        return $form->fields([
            TextField::make('name')->required(),
            EmailField::make('email')->required(),
            TextField::make('status')->required(),
        ]);
    }

    public static function api(ApiResource $api): ApiResource
    {
        return $api
            ->enabled()
            ->creatable(['name', 'email', 'status'])
            ->updatable(['name', 'status'])
            ->requiredOnCreate(['name', 'email', 'status'])
            ->requiredOnUpdate(['name', 'status'])
            ->useFormFieldRules();
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
