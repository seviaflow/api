@php
    $apiEnabled = (bool) config('seviaflow-api.enabled', true);
    $openApiRouteName = (string) config('seviaflow-api.route_name_prefix', 'seviaflow.api.').'openapi';
    $openApiRouteExists = \Illuminate\Support\Facades\Route::has($openApiRouteName);
    $specUrl = $openApiRouteExists ? route($openApiRouteName) : null;
@endphp

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SeviaFlow API docs</title>
    <style>
        body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; background: #f8fafc; color: #0f172a; }
        main { max-width: 960px; margin: 48px auto; padding: 0 24px; }
        .sf-card { border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); }
        .sf-card-body { padding: 28px; }
        .sf-muted { color: #475569; }
        .sf-empty { margin-top: 20px; border-radius: 12px; background: #f1f5f9; padding: 16px; color: #334155; }
    </style>
</head>
<body>
<main>
    <div class="sf-card">
        <div class="sf-card-body">
            <h1>SeviaFlow API Documentation</h1>
            <p class="sf-muted">Interactive OpenAPI explorer for SeviaFlow API.</p>

            @if (! $apiEnabled || ! $openApiRouteExists)
                <div class="sf-empty" id="sf-api-docs-disabled-message">
                    API is disabled. Enable it in <code>config/seviaflow-api.php</code> to expose API endpoints and OpenAPI documentation.
                </div>
            @else
                <p><a href="{{ $specUrl }}">OpenAPI JSON</a></p>
            @endif
        </div>
    </div>
</main>
</body>
</html>
