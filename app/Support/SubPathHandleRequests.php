<?php

namespace App\Support;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Livewire's own getUpdateUri() generates a *relative* URL via Laravel's
 * RouteUrlGenerator, which intentionally strips the request's detected base
 * path (Symfony's Request::getBaseUrl(), which correctly reports "/sih"
 * under our Apache Alias setup) assuming the result will be resolved
 * path-relatively by the caller. Livewire's JS instead uses the result as
 * an absolute path in fetch(), so the browser resolves it against the
 * domain root and "/sih" is lost. This re-adds it.
 */
class SubPathHandleRequests extends HandleRequests
{
    public function getUpdateUri()
    {
        $uri = parent::getUpdateUri();

        $prefix = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($prefix !== '' && ! str_starts_with($uri, $prefix.'/') && $uri !== $prefix) {
            $uri = $prefix.$uri;
        }

        return $uri;
    }
}
