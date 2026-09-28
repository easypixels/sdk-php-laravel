# EasyPixel Laravel SDK

Laravel integration for [`easypixels/sdk-php`](https://github.com/easypixels/sdk-php), the PHP
SDK that shows scenes on [EasyPixel](https://easypixel.ru) LED matrices.

[![Tests](https://github.com/easypixels/sdk-php-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/easypixels/sdk-php-laravel/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

The SDK itself is framework-agnostic and stays that way. This package adds what a Laravel
application expects around it: matrices named in a config file, a facade, a queued job that
backs off when the API throttles, and an artisan command.

```php
use EasyPixel\Laravel\Facades\EasyPixel;

EasyPixel::send(42, ['count' => 15]);                       // default matrix, inline
EasyPixel::matrix('entrance')->send(42, ['count' => 15]);   // a named matrix
EasyPixel::matrix('entrance')->queue(42, ['count' => 15]);  // through the queue
```

## Requirements

- PHP 7.4 or higher
- Laravel 8, 9, 10, 11 or 12
- PHP extensions: `curl`, `json`

## Installation

Neither this package nor the SDK is published on Packagist, and Composer only reads the
`repositories` section of the **root** `composer.json`. Both repositories therefore go into the
application:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/easypixels/sdk-php-laravel.git" },
        { "type": "vcs", "url": "https://github.com/easypixels/sdk-php.git" }
    ],
    "require": {
        "easypixels/sdk-php-laravel": "^1.0"
    }
}
```

```bash
composer update easypixels/sdk-php-laravel
```

The service provider and the `EasyPixel` facade are registered by package discovery. Publish the
config file with:

```bash
php artisan vendor:publish --tag=easypixel-config
```

## Configuration

`config/easypixel.php` describes the matrices you drive. A value is the matrix API key — the
32-character hex string from the matrix settings in the web app — or an array that overrides the
base URL and timeouts for that matrix alone:

```php
'default'  => env('EASYPIXEL_MATRIX', 'entrance'),

'matrices' => [
    'entrance' => env('EASYPIXEL_ENTRANCE_KEY'),
    'exit'     => env('EASYPIXEL_EXIT_KEY'),
    'lobby'    => [
        'api_key' => env('EASYPIXEL_LOBBY_KEY'),
        'timeout' => 5,
    ],
],
```

Keys belong in the environment, not in the repository: one is enough to draw on that display.
The key is scoped to a single matrix and can do exactly one thing — show a scene from the
scenario assigned to it.

Asking for a matrix that neither the config nor the resolver below describes, or one whose key
is empty, throws `MatrixNotConfiguredException`. It extends the SDK's `EasyPixelException`, so a
`catch` around sending also catches a typo in the configuration.

### Keys that live in the database

A config file suits a fixed set of displays. When the key belongs to a tenant, a customer or a
row that operators edit, hand the manager a resolver instead — in a service provider's `boot()`:

```php
use EasyPixel\Laravel\Facades\EasyPixel;

EasyPixel::resolveMatrixUsing(function ($name) {
    return Display::where('slug', $name)->value('easypixel_key');
});
```

From then on any name works the same way:

```php
EasyPixel::matrix($display->slug)->send(42, ['free_spots' => 15]);
EasyPixel::matrix($display->slug)->queue(42, ['free_spots' => 15]);
```

The callback returns the API key, or an array with `api_key` plus any of `base_url`, `timeout`
and `connect_timeout`. Returning `null` means "not mine" and produces the usual unknown-matrix
exception.

Names listed in `matrices` never reach the resolver, so a static entry overrides a stored one.

Resolved matrices are **not** cached: the resolver runs on every call, and a key rotated in the
database takes effect immediately rather than living on inside a queue worker that started
yesterday. Cache inside the callback if the lookup is expensive.

This is also what makes queued sending work with stored keys. The job carries the name and calls
the resolver when it runs, so the key never reaches the queue payload — see
[Queued sending](#queued-sending).

### A key you already hold

For a one-off send where the key is simply at hand:

```php
EasyPixel::withApiKey($display->easypixel_key)->send(42, ['free_spots' => 15]);
```

The optional second argument overrides `base_url`, `timeout` and `connect_timeout`. Such a
connection has no name, so it cannot be queued — queue by name through a resolver instead.

## Sending scenes

```php
use EasyPixel\Laravel\Facades\EasyPixel;

$response = EasyPixel::matrix('entrance')->send(42, [
    'free_spots' => 15,
    'status'     => 'open',
]);

// [
//     'message'           => 'Webhook accepted.',
//     'scene_id'          => 42,
//     'matrix_id'         => 7,
//     'variables_updated' => ['free_spots', 'status'],
// ]
```

Without `matrix()` the call goes to `config('easypixel.default')`. The scene must belong to the
scenario currently assigned to that matrix, otherwise the API answers `422`.

The API answers `202 Accepted` and renders the scene afterwards, so a successful call means the
request was accepted, not that the panel has changed. `variables_updated` lists only the
variables the matrix's scenario actually defines — unknown names are ignored without an error.

One `Webhook`, and one HTTP client, is built per matrix and reused. `webhook()` hands it over
for anything this package does not wrap:

```php
EasyPixel::matrix('entrance')->webhook()->getHttpClient()->setTimeout(3);
```

## Queued sending

```php
EasyPixel::matrix('entrance')->queue(42, ['free_spots' => 15]);
```

`queue()` returns a `PendingDispatch`, so the usual chaining works:

```php
EasyPixel::matrix('entrance')->queue(42)->delay(now()->addMinute());
```

The connection, the queue and the attempt budget come from `config('easypixel.queue')`.

The job carries the matrix **name**, not the API key: a serialised job sits in Redis or in the
`jobs` table and shows up in Horizon and in `failed_jobs`, and the name is resolved back into a
connection when the job runs — through the configuration or through the resolver, whichever owns
that name.

On `429` the job releases itself for the number of seconds in the `Retry-After` header, or for a
minute when the server sends no such header. A release spends an attempt, so `tries` is one
budget shared by throttling and by network failures — raise it if you drive several matrices
from one host, where the per-IP limit bites first.

## Artisan command

```bash
php artisan easypixel:send 42 --matrix=entrance --var=free_spots=15 --var=status=open
```

`--matrix` defaults to the configured default matrix, `--var` is repeatable and splits on the
first `=`, and `--queue` dispatches the job instead of sending inline. The command prints which
variables the scenario picked up, warns about the ones it ignored, and exits non-zero on any API
error.

## Error handling

Sending throws the SDK's own exceptions, unchanged:

```php
use EasyPixel\Exception\EasyPixelException;
use EasyPixel\Exception\RateLimitException;
use EasyPixel\Exception\ValidationException;
use EasyPixel\Laravel\Exception\MatrixNotConfiguredException;
use EasyPixel\Laravel\Facades\EasyPixel;

try {
    EasyPixel::matrix('entrance')->send(42, ['count' => 15]);
} catch (MatrixNotConfiguredException $e) {
    // The name is not in config/easypixel.php, or its key is empty
} catch (ValidationException $e) {
    // 422: no scenario assigned, or the scene is not in it
} catch (RateLimitException $e) {
    // 429: $e->getRetryAfter() is the Retry-After header in seconds, or null
} catch (EasyPixelException $e) {
    // Everything else: other HTTP errors, cURL failures
}
```

The full hierarchy is in the [SDK README](https://github.com/easypixels/sdk-php#error-handling).

## Rate limits

The webhook endpoint is throttled twice, and both limits apply at once:

| Limit | Scope |
|---|---|
| 120 requests / minute | per client IP address |
| 60 requests / minute | per matrix API key |

Driving several matrices from one host hits the IP limit first.

## Testing an application that uses this package

The HTTP client is built by a factory in the container. Rebind it and nothing leaves the
machine:

```php
use EasyPixel\HttpClient;
use EasyPixel\Laravel\EasyPixelServiceProvider;

$this->app->bind(EasyPixelServiceProvider::HTTP_FACTORY, function () {
    return function (array $config) {
        return new MyRecordingClient($config['base_url']);
    };
});
```

`MyRecordingClient` extends `EasyPixel\HttpClient` and overrides the protected `executeCurl()`.
`tests/Fixtures/RecordingHttpClient.php` in this repository is a working example.

For queued sending, `Queue::fake()` and `Queue::assertPushed(SendSceneJob::class, ...)` work as
with any other job.

## Development

The SDK is resolved through a path repository pointing at `../sdk-php`, so both repositories are
checked out side by side:

```bash
docker run --rm -v "$PWD":/app -v "$PWD/../sdk-php":/sdk-php -w /app composer:2 \
    composer install --no-interaction

docker run --rm -v "$PWD":/app -v "$PWD/../sdk-php":/sdk-php -w /app composer:2 \
    php vendor/bin/phpunit --testdox
```

## License

MIT © EasyPixel
