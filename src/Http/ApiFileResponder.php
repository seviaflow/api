<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use SeviaFlow\Core\Files\FilePathGuard;
use SeviaFlow\Core\Forms\FormFields;
use SeviaFlow\Core\Resources\Fields\FileField;
use SeviaFlow\Core\Resources\Form;
use SeviaFlow\Core\Resources\ResourceContext;
use Symfony\Component\HttpFoundation\Response;

final class ApiFileResponder
{
    public function __construct(
        private readonly ApiRecordResolver $recordResolver = new ApiRecordResolver,
        private readonly ApiAuthorization $authorization = new ApiAuthorization,
    ) {}

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    public function download(Request $request, string $resourceClass, int|string $record, string $field): Response
    {
        $found = $this->recordResolver->resolve($resourceClass, $record);
        if ($found === null) {
            abort(404);
        }

        if (! $this->authorization->allows($request, $resourceClass, 'view', $found)) {
            abort(403);
        }

        $fileField = $this->fileField($resourceClass, $field);
        if (! $fileField instanceof FileField || ! $fileField->canRead($request->user(), $found)) {
            abort(403);
        }

        $path = $found->getAttribute($field);
        if (! is_string($path) || ! FilePathGuard::isManaged($fileField, $path)) {
            abort(404);
        }

        $disk = Storage::disk($fileField->getDisk());
        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download($path);
    }

    /**
     * @param  class-string<\SeviaFlow\Core\Resources\Resource>  $resourceClass
     */
    private function fileField(string $resourceClass, string $name): ?FileField
    {
        $fields = (new FormFields)->visible(
            $resourceClass::show(new Form)->getAllFields(),
            ResourceContext::Show,
        );

        foreach ($fields as $field) {
            if ($field instanceof FileField && $field->name() === $name) {
                return $field;
            }
        }

        return null;
    }
}
