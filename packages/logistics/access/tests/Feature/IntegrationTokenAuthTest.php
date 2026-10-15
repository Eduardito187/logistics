<?php

declare(strict_types=1);

use Logistics\Access\Application\Query\AuthenticateIntegrationToken\AuthenticateIntegrationToken;
use Logistics\Access\Application\ReadModel\IssuedTokenView;
use Logistics\Access\Application\ReadModel\TokenAuthResultView;
use Logistics\Access\Application\Service\IntegrationTokenIssuer;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Emisión + autenticación real de un token de integración contra PostgreSQL (base logistics_test).
 * El Issuer revela el secreto en claro una sola vez; la autenticación va por el QueryBus.
 * Se marca skip cuando no hay PostgreSQL para no romper la suite Unit.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->issuer = app(IntegrationTokenIssuer::class);
    $this->queries = app(QueryBus::class);
});

it('emite un token con permisos y lo autentica por su valor completo', function (): void {
    /** @var IntegrationTokenIssuer $issuer */
    $issuer = $this->issuer;
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $issued = $issuer->issue('ERP Integration', ['shipments.read', 'fleet.read'], null, 'admin@dismac.test');
    expect($issued)->toBeInstanceOf(IssuedTokenView::class)
        ->and($issued->plainToken)->toContain('.');

    $result = $queries->ask(new AuthenticateIntegrationToken($issued->plainToken));

    expect($result)->toBeInstanceOf(TokenAuthResultView::class);
    /** @var TokenAuthResultView $result */
    expect($result->tokenId)->toBe($issued->id)
        ->and($result->permissionKeys)->toEqualCanonicalizing(['shipments.read', 'fleet.read']);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('devuelve null si el secreto es incorrecto', function (): void {
    /** @var IntegrationTokenIssuer $issuer */
    $issuer = $this->issuer;
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $issued = $issuer->issue('ERP Integration', ['shipments.read'], null, null);

    // Mismo prefijo, secreto adulterado: no debe autenticar.
    $fullTokenWrong = $issued->tokenPrefix.'.secreto-incorrecto';

    expect($queries->ask(new AuthenticateIntegrationToken($fullTokenWrong)))->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);
