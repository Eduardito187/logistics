<?php

declare(strict_types=1);

use Logistics\Shipments\Application\ReadModel\AddressPartsView;

/**
 * Dirección estructurada best-effort (pedido #26 de la app): calle, número, barrio, referencia, ciudad y
 * una `short_address` de una línea, a partir del texto libre con convenciones bolivianas.
 */
it('separa calle, número, barrio y ciudad de una dirección típica', function (): void {
    $parts = AddressPartsView::fromText('Av. 6 de Agosto 2170, Sopocachi, La Paz', 'Edificio azul, piso 3');

    expect($parts->toArray())->toBe([
        'street' => 'Av. 6 de Agosto',
        'number' => '2170',
        'neighborhood' => 'Sopocachi',
        'reference' => 'Edificio azul, piso 3',
        'city' => 'La Paz',
    ])->and($parts->shortAddress)->toBe('Av. 6 de Agosto 2170, Sopocachi');
});

it('reconoce el número explícito (N° / #) y normaliza Santa Cruz de la Sierra', function (): void {
    $parts = AddressPartsView::fromText('Av. Alemana 3er anillo interno N° 1200, Barrio Sirari, Santa Cruz de la Sierra');

    expect($parts->street)->toBe('Av. Alemana 3er anillo interno')
        ->and($parts->number)->toBe('1200')
        ->and($parts->neighborhood)->toBe('Barrio Sirari')
        ->and($parts->city)->toBe('Santa Cruz')
        ->and($parts->reference)->toBeNull();

    $hash = AddressPartsView::fromText('Calle 21 de Calacoto #8050, La Paz');

    expect($hash->street)->toBe('Calle 21 de Calacoto')
        ->and($hash->number)->toBe('8050')
        ->and($hash->neighborhood)->toBeNull()
        ->and($hash->shortAddress)->toBe('Calle 21 de Calacoto 8050, La Paz');
});

it('no confunde UV/manzana con la numeración y toma el plan como barrio', function (): void {
    $parts = AddressPartsView::fromText('Av. Paurito UV 160, Manzana 8, Plan 3000, Santa Cruz de la Sierra');

    expect($parts->street)->toBe('Av. Paurito UV 160')
        ->and($parts->number)->toBeNull()
        ->and($parts->neighborhood)->toBe('Plan 3000')
        ->and($parts->city)->toBe('Santa Cruz');
});

it('descarta el país, tolera tildes y deja null lo que no reconoce', function (): void {
    $parts = AddressPartsView::fromText('Calle Bolívar 55, Centro, Potosi, Bolivia');

    expect($parts->city)->toBe('Potosí')
        ->and($parts->neighborhood)->toBe('Centro')
        ->and($parts->number)->toBe('55');

    $unknown = AddressPartsView::fromText('Km 9 carretera al norte');

    expect($unknown->city)->toBeNull()
        ->and($unknown->number)->toBeNull()
        ->and($unknown->shortAddress)->toBe('Km 9 carretera al norte');
});

it('recorta la dirección corta a una línea de pantalla', function (): void {
    $parts = AddressPartsView::fromText(str_repeat('Avenida Muy Larga ', 10).'100, La Paz');

    expect(mb_strlen($parts->shortAddress))->toBeLessThanOrEqual(80)
        ->and($parts->shortAddress)->toEndWith('…');
});
