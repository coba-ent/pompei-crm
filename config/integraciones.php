<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MercadoLibre
    |--------------------------------------------------------------------------
    |
    | Credenciales de la aplicación (app id/secret) para el flujo OAuth2 de
    | MercadoLibre. Nunca se commitean: viven sólo en `.env` (research D2/D9).
    |
    */
    'mercadolibre' => [
        'client_id' => env('ML_CLIENT_ID'),
        'client_secret' => env('ML_CLIENT_SECRET'),
        'redirect_uri' => env('ML_REDIRECT_URI'),
        'base_url' => env('ML_BASE_URL', 'https://api.mercadolibre.com'),
        'auth_url' => env('ML_AUTH_URL', 'https://auth.mercadolibre.com.ar/authorization'),
    ],

    /*
    |--------------------------------------------------------------------------
    | TiendaNube
    |--------------------------------------------------------------------------
    |
    | Credenciales de la aplicación (app id/secret) para el flujo OAuth2 de
    | TiendaNube, más el secreto usado para validar la firma de los webhooks
    | entrantes (research D2/D9/D11).
    |
    */
    'tiendanube' => [
        'client_id' => env('TN_CLIENT_ID'),
        'client_secret' => env('TN_CLIENT_SECRET'),
        'redirect_uri' => env('TN_REDIRECT_URI'),
        'base_url' => env('TN_BASE_URL', 'https://api.tiendanube.com'),
        'auth_url' => env('TN_AUTH_URL', 'https://www.tiendanube.com/apps/authorize'),
        'webhook_secret' => env('TN_WEBHOOK_SECRET'),
    ],

];
