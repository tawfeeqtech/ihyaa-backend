<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * التوطين — Arabic-first (SRS-F13). يسبق throttle حتى تصل رسائل 429 مترجمة.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(['ar', 'en']);

        app()->setLocale(in_array($preferred, ['ar', 'en'], true) ? $preferred : 'ar');

        return $next($request);
    }
}
