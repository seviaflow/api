<?php

declare(strict_types=1);

namespace SeviaFlow\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** @deprecated API is free. Retained for applications with published legacy middleware configuration. */
final class RequireApiLicense
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
