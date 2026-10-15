<?php

declare(strict_types=1);

use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\AuditAction;

it('acepta una acción con formato sujeto.verbo', function (): void {
    $action = AuditAction::fromString('user.suspended');

    expect($action->value)->toBe('user.suspended');
});

it('acepta acciones con guion bajo y varios segmentos', function (): void {
    expect(AuditAction::fromString('role.permissions_changed')->value)->toBe('role.permissions_changed')
        ->and(AuditAction::fromString('token.permissions_changed')->value)->toBe('token.permissions_changed');
});

it('normaliza mayúsculas y espacios alrededor', function (): void {
    expect(AuditAction::fromString('  User.Suspended  ')->value)->toBe('user.suspended');
});

it('rechaza una acción sin punto separador', function (): void {
    expect(fn () => AuditAction::fromString('suspended'))->toThrow(AccessException::class);
});

it('rechaza una acción con espacios internos', function (): void {
    expect(fn () => AuditAction::fromString('user suspended'))->toThrow(AccessException::class);
});

it('rechaza una acción vacía', function (): void {
    expect(fn () => AuditAction::fromString('   '))->toThrow(AccessException::class);
});

it('compara dos acciones por su valor', function (): void {
    expect(AuditAction::fromString('user.suspended')->equals(AuditAction::fromString('user.suspended')))->toBeTrue()
        ->and(AuditAction::fromString('user.suspended')->equals(AuditAction::fromString('user.reactivated')))->toBeFalse();
});
