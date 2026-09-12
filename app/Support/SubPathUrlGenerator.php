<?php

namespace App\Support;

use Illuminate\Routing\UrlGenerator;

/**
 * When this app is served from a sub-path via an Apache Alias (e.g.
 * APP_URL=http://host/sih), Laravel's *relative* URL generation
 * (route(..., absolute: false), used by Livewire's own update endpoint and
 * by the Breeze auth scaffolding's post-login redirects) intentionally
 * strips the request's detected base path assuming the caller will resolve
 * the result path-relatively. In practice every caller treats the result as
 * an absolute path (fetch(), Location header, wire:navigate), so the browser
 * resolves it against the domain root and "/sih" is lost. This re-adds it
 * for every relative route() call, app-wide.
 */
class SubPathUrlGenerator extends UrlGenerator
{
    /**
     * Relatively-signed routes must NOT be prefixed here.
     *
     * Laravel signs the relative path and later validates the signature
     * against `$request->path()`, which under the Alias already has "/sih"
     * stripped — so prefixing the signed path guarantees a mismatch and a 401
     * on every upload. Livewire re-absolutizes these with URL::to(), which
     * picks the sub-path back up from the request root, so the browser still
     * hits the right URL.
     */
    private const RELATIVELY_SIGNED_ROUTES = [
        'livewire.upload-file',
        'livewire.preview-file',
    ];

    public function toRoute($route, $parameters, $absolute)
    {
        $uri = parent::toRoute($route, $parameters, $absolute);

        if (! $absolute && ! in_array($route->getName(), self::RELATIVELY_SIGNED_ROUTES, true)) {
            $uri = $this->withSubPath($uri);
        }

        return $uri;
    }

    protected function withSubPath(string $uri): string
    {
        $prefix = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($prefix === '' || str_starts_with($uri, $prefix.'/') || $uri === $prefix) {
            return $uri;
        }

        return $prefix.$uri;
    }
}
