<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource\Pages\ListEmittedLabels;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource\Pages\ViewEmittedLabel;
use Logistics\Labels\Tests\Support\LabelTestEnv;

// Ejercita las páginas Livewire del resource de etiquetas emitidas (Presentation). Montar la página
// corre las definiciones de table()/infolist() y los constructores de las acciones de cabecera y de
// fila; con filas reales de la factory se evalúan además los closures de estado, color y ruta. Las
// acciones de los flujos (emitir, pre-emitir, reimprimir, claves públicas) resuelven por los buses
// CQRS, así que se fija una clave de firma demo. No se caza nada: solo se ejercita el render real.

beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }

    // Los resources resuelven el panel actual para render; fuera de HTTP hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');
    LabelTestEnv::configureSigner();

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el listado de etiquetas vacío', function (): void {
    Livewire::test(ListEmittedLabels::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el listado de etiquetas con filas (vigente y revocada)', function (): void {
    // Una vigente y una revocada para que la tabla evalúe los closures de estado, color y ruta.
    EmittedLabelRecord::factory()->forOrder('DM-260099001')->create();
    EmittedLabelRecord::factory()->forOrder('DM-260099001')->revoked()->create();

    Livewire::test(ListEmittedLabels::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista de una etiqueta (infolist con payload firmado)', function (): void {
    $record = EmittedLabelRecord::factory()->forOrder('DM-260099002')->create();

    Livewire::test(ViewEmittedLabel::class, ['record' => $record->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('monta las acciones de cabecera del listado (flujos CQRS)', function (): void {
    // Montar cada acción construye y renderiza su modal: corre el schema del formulario de pedido de
    // emitir/pre-emitir/reimprimir, y el modalContent de las claves públicas (bundle por el QueryBus).
    Livewire::test(ListEmittedLabels::class)
        ->mountAction('emitLabels')
        ->assertOk();

    Livewire::test(ListEmittedLabels::class)
        ->mountAction('preEmitLabels')
        ->assertOk();

    Livewire::test(ListEmittedLabels::class)
        ->mountAction('reprintLabels')
        ->assertOk();

    Livewire::test(ListEmittedLabels::class)
        ->mountAction('publicKeys')
        ->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('monta la acción de fila que arma el modal de etiquetas y ZPL del pedido', function (): void {
    $record = EmittedLabelRecord::factory()->forOrder('DM-260099003')->create();

    // Resuelve GetShipmentLabels por el QueryBus y arma la tabla legible + el ZPL vigente concatenado.
    Livewire::test(ListEmittedLabels::class)
        ->mountTableAction('orderLabels', $record->getKey())
        ->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('arma el título humano del registro a partir del QR firmado', function (): void {
    $record = EmittedLabelRecord::factory()->forOrder('DM-260099004')->create();

    // Con datos válidos el título es legible (pedido · SKU · unidad), nunca el UUID/ULID crudo.
    expect(EmittedLabelResource::getRecordTitle($record))->toContain('DM-260099004');

    // Sin pedido ni QR decodificable, cae al label_id para no quedar sin título.
    $corrupt = EmittedLabelRecord::factory()->create([
        'order_id' => '',
        'label_id' => 'LBL-FALLBACK',
        'signed_json' => 'not-json',
    ]);
    expect(EmittedLabelResource::getRecordTitle($corrupt))->toBe('LBL-FALLBACK');

    // Un modelo que no es una etiqueta no tiene título.
    expect(EmittedLabelResource::getRecordTitle(null))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
