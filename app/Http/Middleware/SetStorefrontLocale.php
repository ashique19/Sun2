<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetStorefrontLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            app()->setLocale('en');
        } else {
            app()->setLocale(config('app.storefront_locale', 'bn'));
        }

        return $next($request);
    }
}
