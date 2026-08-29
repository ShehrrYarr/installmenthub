<?php

// Livewire's own script tag (livewire.min.js) is the one asset URL in the
// framework that doesn't go through Laravel's normal URL generation — it
// uses the raw registered route path, so under a sub-path deployment (this
// app is served at APP_URL's own path, e.g. /sih, not domain root) the
// browser was requesting it from the domain root and getting a 404, which
// silently prevented Livewire from ever initializing (forms fell back to
// plain HTML GET submits — e.g. login submitted the password as a URL query
// string). Every other Livewire URL (the /livewire/update AJAX endpoint,
// wire:navigate, etc.) already derives correctly from APP_URL; this is the
// one exception the package itself provides `asset_url` to override.
$basePath = rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');

return [
    'asset_url' => $basePath.'/livewire/livewire'.(config('app.debug') ? '.js' : '.min.js'),
];
