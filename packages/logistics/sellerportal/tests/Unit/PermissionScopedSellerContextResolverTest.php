<?php

declare(strict_types=1);

use Logistics\SellerPortal\Domain\Exception\SellerPortalException;
use Logistics\SellerPortal\Infrastructure\Identity\PermissionScopedSellerContextResolver;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Resolución del seller del token a partir de sus permisos. PHP puro, sin base de datos.
 */
it('resuelve el seller desde el permiso de scope', function (): void {
    $sellerId = Uuid::generate()->value;
    $resolver = new PermissionScopedSellerContextResolver;

    $resolved = $resolver->resolve([
        'sellerportal.read',
        PermissionScopedSellerContextResolver::scopeKeyFor($sellerId),
    ]);

    expect($resolved)->toBe($sellerId);
});

it('arma y lee la clave de scope de forma simétrica (sin guiones, minúscula)', function (): void {
    $sellerId = Uuid::generate()->value;

    $key = PermissionScopedSellerContextResolver::scopeKeyFor($sellerId);

    expect($key)->toBe('sellerportal.seller.'.str_replace('-', '', $sellerId))
        ->and($key)->not->toContain('-');
});

it('rechaza un token sin scope de seller (incluido el comodín)', function (): void {
    $resolver = new PermissionScopedSellerContextResolver;

    expect(fn () => $resolver->resolve(['sellerportal.read']))
        ->toThrow(SellerPortalException::class);

    expect(fn () => $resolver->resolve(['*']))
        ->toThrow(SellerPortalException::class);
});

it('rechaza un token con scopes de dos sellers distintos (ambiguo)', function (): void {
    $resolver = new PermissionScopedSellerContextResolver;

    $keys = [
        PermissionScopedSellerContextResolver::scopeKeyFor(Uuid::generate()->value),
        PermissionScopedSellerContextResolver::scopeKeyFor(Uuid::generate()->value),
    ];

    expect(fn () => $resolver->resolve($keys))->toThrow(SellerPortalException::class);
});

it('ignora permisos no relacionados y hex inválido', function (): void {
    $sellerId = Uuid::generate()->value;
    $resolver = new PermissionScopedSellerContextResolver;

    $resolved = $resolver->resolve([
        'shipments.read',
        'fleet.read',
        'sellerportal.seller.nohex',
        PermissionScopedSellerContextResolver::scopeKeyFor($sellerId),
    ]);

    expect($resolved)->toBe($sellerId);
});
