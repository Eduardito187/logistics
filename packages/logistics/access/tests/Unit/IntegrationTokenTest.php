<?php

declare(strict_types=1);

use Logistics\Access\Domain\Event\IntegrationTokenIssued;
use Logistics\Access\Domain\Event\IntegrationTokenPermissionsChanged;
use Logistics\Access\Domain\Event\IntegrationTokenRevoked;
use Logistics\Access\Domain\Event\IntegrationTokenRotated;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\IntegrationToken;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Domain\ValueObject\PermissionKey;
use Logistics\Access\Domain\ValueObject\TokenStatus;

/**
 * Emite un token de dominio con prefijo y hash ya calculados (como lo hace el Issuer de Application).
 *
 * @param list<string> $permissionKeys
 */
function emitirToken(array $permissionKeys = ['shipments.read'], ?DateTimeImmutable $expiresAt = null): IntegrationToken
{
    return IntegrationToken::issue(
        IntegrationTokenId::generate(),
        'ERP Integration',
        'itk_ab12cd34',
        'hash-del-secreto',
        $permissionKeys,
        $expiresAt,
        'admin@dismac.test',
    );
}

it('emite un token activo normalizando y deduplicando permisos', function (): void {
    $token = emitirToken(['shipments.read', 'Shipments.Read', 'fleet.read']);

    expect($token->status())->toBe(TokenStatus::Active)
        ->and($token->permissionKeys())->toBe(['shipments.read', 'fleet.read'])
        ->and($token->tokenPrefix())->toBe('itk_ab12cd34')
        ->and($token->createdBy())->toBe('admin@dismac.test');

    $events = $token->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(IntegrationTokenIssued::class)
        ->and($events[0]->eventName())->toBe('access.integration_token_issued');
});

it('rechaza emitir un token sin nombre', function (): void {
    expect(fn () => IntegrationToken::issue(
        IntegrationTokenId::generate(), '  ', 'itk_x', 'h', [], null, null,
    ))->toThrow(AccessException::class);
});

it('revoca de forma idempotente', function (): void {
    $token = emitirToken();
    $token->releaseEvents();

    $token->revoke();
    expect($token->status())->toBe(TokenStatus::Revoked);
    $token->revoke(); // idempotente

    $events = $token->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(IntegrationTokenRevoked::class);
});

it('rota el secreto de un token activo adoptando el prefijo nuevo', function (): void {
    $token = emitirToken();
    $token->releaseEvents();

    $token->rotate('itk_nuevo999', 'hash-nuevo');
    expect($token->tokenPrefix())->toBe('itk_nuevo999');

    $events = $token->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(IntegrationTokenRotated::class);
});

it('no permite rotar un token revocado', function (): void {
    $token = emitirToken();
    $token->revoke();

    expect(fn () => $token->rotate('itk_x', 'h'))->toThrow(AccessException::class);
});

it('reemplaza el conjunto de permisos grabando el cambio', function (): void {
    $token = emitirToken();
    $token->releaseEvents();

    $token->setPermissions(['fleet.read', 'fleet.write']);
    expect($token->permissionKeys())->toBe(['fleet.read', 'fleet.write']);

    $events = $token->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(IntegrationTokenPermissionsChanged::class);
});

it('es usable solo si está activo y no ha expirado', function (): void {
    $ahora = new DateTimeImmutable('2026-10-13T12:00:00+00:00');

    $sinVencimiento = emitirToken();
    expect($sinVencimiento->isUsable($ahora))->toBeTrue();

    $vigente = emitirToken(['shipments.read'], new DateTimeImmutable('2026-10-13T13:00:00+00:00'));
    expect($vigente->isUsable($ahora))->toBeTrue();

    $vencido = emitirToken(['shipments.read'], new DateTimeImmutable('2026-10-13T11:00:00+00:00'));
    expect($vencido->isUsable($ahora))->toBeFalse();

    $revocado = emitirToken();
    $revocado->revoke();
    expect($revocado->isUsable($ahora))->toBeFalse();
});

it('respeta el comodín en hasPermission', function (): void {
    $comodin = emitirToken(['*']);
    expect($comodin->hasPermission(PermissionKey::fromString('anything.read')))->toBeTrue();

    $acotado = emitirToken(['shipments.read']);
    expect($acotado->hasPermission(PermissionKey::fromString('shipments.read')))->toBeTrue()
        ->and($acotado->hasPermission(PermissionKey::fromString('shipments.write')))->toBeFalse();
});
