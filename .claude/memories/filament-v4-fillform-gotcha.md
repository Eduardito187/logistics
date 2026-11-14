# Memoria: Gotcha de Filament v4 — fillForm no sobreescribe campos de mutateFormDataBeforeFill (2026-11-14)

## El bug

En tests de Livewire para páginas `EditRecord` de Filament v4 (v4.15.1+), `fillForm()` **NO
sobreescribe** campos cuyo valor fue seteado por `mutateFormDataBeforeFill()`.

### Ejemplo concreto

```php
// mutateFormDataBeforeFill convierte price_cents=372 → price_bs=3.72
// fillForm intenta poner price_bs='4.00', pero el state conserva 3.72
Livewire::test(EditFuelPrice::class, ['record' => $id])
    ->fillForm(['price_bs' => '4.00'])  // ← NO funciona, queda 3.72
    ->call('save');
// handleRecordUpdate recibe price_bs=3.72, no 4.00
```

## La causa

Filament v4 hydrata el estado del formulario con `mutateFormDataBeforeFill` al montar el
componente. El `fillForm()` del testing helper no logra sobreescribir campos que ya tienen un
valor seteado por esa mutación (parece que el state interno del form tiene precedencia).

## El fix en tests

Usar `->set('data.<field>', value)` de Livewire directamente para forzar el valor en el state:

```php
Livewire::test(EditFuelPrice::class, ['record' => $id])
    ->assertOk()
    ->fillForm([
        'currency' => 'BOB',           // campos normales → fillForm OK
        'effective_from' => '2026-02-01',
    ])
    ->set('data.price_bs', 4.00)       // campo virtual → set directo
    ->call('save')
    ->assertHasNoFormErrors();
```

## Archivos afectados

- `packages/logistics/fleetops/tests/Feature/CoverageMutationPagesTest.php`
  - Test de FuelPrice: `fillForm` + `set('data.price_bs', 4.00)`
  - Test de VehicleProfile: `set('data.brand', ...)`, `set('data.model', ...)`, `set('data.year', ...)`

## Regla general

En tests de Filament v4 con `EditRecord`:
- **Campos que existen en el modelo**: `fillForm()` funciona bien
- **Campos virtuales** (creados por `mutateFormDataBeforeFill`, como `price_bs` derivado de
  `price_cents`): usar `->set('data.<field>', value)` para forzar el nuevo valor
- **Campos del modelo que `mutateFormDataBeforeFill` transforma**: también usar `->set()` para
  estar seguro

## No es un bug de producción

El formulario en el navegador SÍ funciona correctamente (el usuario escribe un valor nuevo y
Livewire lo propaga). El problema es exclusivo del testing helper `fillForm()`. El código de
producción (`EditFuelPrice`, `EditVehicleProfile`) no necesitó cambios.
