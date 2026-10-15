<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Dominios con estado (stateful)
    |--------------------------------------------------------------------------
    |
    | La API es puramente por token (app Flutter, canales e integraciones): no se
    | usa autenticación por sesión/cookie. Se deja el default por compatibilidad.
    */

    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        sprintf(
            '%s%s',
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
            Sanctum::currentApplicationUrlWithPort(),
        ),
    )),

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiración de tokens (minutos)
    |--------------------------------------------------------------------------
    |
    | Los tokens de la app interna expiran y se rotan con `POST app/auth/refresh`.
    | Default: 12 horas. `null` = sin expiración (no recomendado para la app).
    */

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 720),

    /*
    |--------------------------------------------------------------------------
    | Prefijo de tokens
    |--------------------------------------------------------------------------
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
