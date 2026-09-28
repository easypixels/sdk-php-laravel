<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API base URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the EasyPixel API. Override it to point at a staging
    | installation; a single matrix may override it again below.
    |
    */

    'base_url' => env('EASYPIXEL_BASE_URL', 'https://api.easypixel.ru'),

    /*
    |--------------------------------------------------------------------------
    | Default matrix
    |--------------------------------------------------------------------------
    |
    | Name of the matrix used when EasyPixel::matrix() is called without an
    | argument, and by the facade's own shortcuts: EasyPixel::send(42).
    |
    */

    'default' => env('EASYPIXEL_MATRIX', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Matrices
    |--------------------------------------------------------------------------
    |
    | Each entry maps a name you choose to the API key of that matrix — the
    | 32-character hex string from the matrix settings in the web app. The key
    | is scoped to one matrix and can only show a scene from the scenario
    | assigned to it.
    |
    | A value is either the key itself or an array that overrides 'base_url',
    | 'timeout' and 'connect_timeout' for that matrix alone:
    |
    |     'entrance' => env('EASYPIXEL_ENTRANCE_KEY'),
    |     'lobby'    => [
    |         'api_key' => env('EASYPIXEL_LOBBY_KEY'),
    |         'timeout' => 5,
    |     ],
    |
    | Matrices whose keys live somewhere else — a database table, a tenant
    | record — do not belong here. Leave this list to the static ones and teach
    | the manager where to look, in a service provider's boot():
    |
    |     EasyPixel::resolveMatrixUsing(function ($name) {
    |         return Display::where('slug', $name)->value('easypixel_key');
    |     });
    |
    | A name listed below wins over the resolver.
    |
    */

    'matrices' => [

        'default' => env('EASYPIXEL_API_KEY'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Request and connection timeouts in seconds, applied to every matrix that
    | does not set its own.
    |
    */

    'timeout' => env('EASYPIXEL_TIMEOUT', 30),

    'connect_timeout' => env('EASYPIXEL_CONNECT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where EasyPixel::matrix(...)->queue() puts its job. Null means the
    | application's default connection and queue.
    |
    | 'tries' is the total attempt budget of one job. A 429 answer makes the
    | job release itself until Retry-After expires, and a release spends an
    | attempt, so network failures and throttling share this number.
    |
    */

    'queue' => [

        'connection' => env('EASYPIXEL_QUEUE_CONNECTION'),

        'queue' => env('EASYPIXEL_QUEUE'),

        'tries' => env('EASYPIXEL_QUEUE_TRIES', 3),

    ],

];
