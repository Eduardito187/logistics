<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Resources\Pages\PageRegistration;
use Logistics\Settings\Presentation\Filament\Resources\IntegrationResource;

/**
 * Cubre las definiciones estáticas de IntegrationResource: etiquetas de navegación/modelo en inglés
 * (regla 5), el bloqueo de crear/editar/eliminar (no es un CRUD: solo redirige a «Configuration») y el
 * registro de su única página. Son métodos puros; no tocan la base.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('expone etiquetas en inglés y agrupa bajo Integrations', function (): void {
    expect(IntegrationResource::getNavigationGroup())->toBe(__('Integrations'))
        ->and(IntegrationResource::getNavigationLabel())->toBe(__('Integrations'))
        ->and(IntegrationResource::getModelLabel())->toBe(__('Integration'))
        ->and(IntegrationResource::getPluralModelLabel())->toBe(__('Integrations'));
});

it('no permite crear, editar ni eliminar (solo redirige a Configuration)', function (): void {
    expect(IntegrationResource::canCreate())->toBeFalse()
        ->and(IntegrationResource::canEdit(null))->toBeFalse()
        ->and(IntegrationResource::canDelete(null))->toBeFalse();
});

it('registra una sola página de gestión', function (): void {
    $pages = IntegrationResource::getPages();

    expect($pages)->toHaveKey('index')
        ->and($pages['index'])->toBeInstanceOf(PageRegistration::class);
});
