<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\ChecklistItemCriticality;
use Logistics\Fleet\Domain\ValueObject\ChecklistTemplateItem;

/**
 * Ítem de la plantilla del checklist pre-salida: clave estable (contrato con la app), texto y criticidad.
 */
it('normaliza la clave a minúsculas sin espacios en los extremos', function (): void {
    $item = ChecklistTemplateItem::of('  FRENOS_01  ', 'Revisar frenos', 'Seguridad', ChecklistItemCriticality::Critical, true);

    expect($item->key)->toBe('frenos_01')
        ->and($item->label)->toBe('Revisar frenos')
        ->and($item->category)->toBe('Seguridad')
        ->and($item->criticality)->toBe(ChecklistItemCriticality::Critical)
        ->and($item->requiresPhoto)->toBeTrue();
});

it('rechaza una clave con caracteres fuera del patrón permitido', function (): void {
    expect(fn () => ChecklistTemplateItem::of('frenos 01', 'Texto', 'Cat', ChecklistItemCriticality::Minor))
        ->toThrow(FleetException::class, 'clave del ítem del checklist');
});

it('rechaza un ítem sin texto visible', function (): void {
    expect(fn () => ChecklistTemplateItem::of('frenos', '   ', 'Cat', ChecklistItemCriticality::Minor))
        ->toThrow(FleetException::class, 'necesita un texto visible');
});

it('rechaza un ítem sin categoría', function (): void {
    expect(fn () => ChecklistTemplateItem::of('frenos', 'Texto', '   ', ChecklistItemCriticality::Minor))
        ->toThrow(FleetException::class, 'necesita una categoría');
});

it('va y vuelve entre forma primitiva y objeto', function (): void {
    $data = ['key' => 'luces', 'label' => 'Revisar luces', 'category' => 'Seguridad', 'criticality' => 'major', 'requires_photo' => true];

    $item = ChecklistTemplateItem::fromArray($data);

    expect($item->toArray())->toBe($data);
});

it('asume requires_photo falso cuando no viene en la forma primitiva', function (): void {
    $item = ChecklistTemplateItem::fromArray(['key' => 'luces', 'label' => 'Luces', 'category' => 'Seguridad', 'criticality' => 'minor']);

    expect($item->requiresPhoto)->toBeFalse();
});
