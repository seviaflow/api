<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireApiLicense
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('seviaflow-api.licensing.enforce', true)) {
            return $next($request);
        }
        $gateClass = 'SeviaFlow\\Licensing\\LicenseGate';
        if (! class_exists($gateClass) || ! app()->bound($gateClass)) {
            return response()->json(['code' => 'module_license_not_configured', 'message' => 'The API licensing adapter is not configured.'], 503);
        }
        $decision = app($gateClass)->check((string) config('seviaflow-api.licensing.product', 'api'), [
            'installation_uuid' => config('seviaflow-licensing.installation_uuid'),
            'environment' => config('seviaflow-licensing.environment'),
            'host' => $request->getHost(),
        ]);
        if (! $decision->allowed) {
            return response()->json(['code' => $decision->code, 'message' => $decision->message], 403);
        }

        return $next($request);
    }
}
