<?php

namespace App\Http\Middleware;

use App\Services\AutomationTokenService;
use App\Services\RequestContext;
use Closure;

class AutomationTokenAuth
{
    public function handle($request, Closure $next)
    {
        RequestContext::begin($request);
        RequestContext::set('start_time', microtime(true));
        RequestContext::set('header_language', $request->header('language'));
        RequestContext::updateBaseUrl($request);
        AutomationTokenService::authenticate($request);
        return $next($request);
    }

    public function terminate(): void
    {
        RequestContext::clean();
    }
}
