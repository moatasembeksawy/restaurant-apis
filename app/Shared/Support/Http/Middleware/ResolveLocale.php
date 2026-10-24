<?php

declare(strict_types=1);

namespace App\Shared\Support\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ResolveLocale
{
    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $originalLocale = App::getLocale();
        $locale = $this->requestedLocale($request);

        if ($locale !== null) {
            App::setLocale($locale);
            $request->attributes->set('requested_locale', $locale);
        }

        try {
            return $next($request);
        } finally {
            App::setLocale($originalLocale);
        }
    }

    public static function hasRequestedLocale(Request $request): bool
    {
        return $request->attributes->has('requested_locale');
    }

    private function requestedLocale(Request $request): ?string
    {
        foreach ($request->getLanguages() as $locale) {
            $language = strtolower(str_replace('_', '-', $locale));
            $language = explode('-', $language, 2)[0];

            if (in_array($language, self::SUPPORTED_LOCALES, true)) {
                return $language;
            }
        }

        return null;
    }
}
