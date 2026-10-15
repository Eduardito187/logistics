<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Domain\Contract\Geocoder;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Cobertura de los controllers HTTP del contexto shipments (Patrón F del brief W2).
 *
 * Se omite el middleware de puerta (service.auth/auth:sanctum/device/idempotency) para ejercitar solo el
 * contrato de los controllers, su FormRequest (ReceiveShipmentRequest) y la cadena CQRS detrás (comandos,
 * consultas, ReadModels y repositorios). Recorre el happy-path real de cada endpoint más algún 404/422.
 * Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un pedido recibido por el caso de uso de ingreso y devuelve su id interno.
 */
function seedShipmentForHttp(string $externalId, ?float $lat = -16.5, ?float $lng = -68.15): string
{
    app(CommandBus::class)->dispatch(new ReceiveShipment(
        source: 'STORE',
        externalId: $externalId,
        customerName: 'Cliente HTTP',
        addressText: 'Av. 6 de Agosto 2170, La Paz',
        addressReference: 'Oficina 4',
        lat: $lat,
        lng: $lng,
        lines: [new ShipmentLineInput('SKU-HTTP-1', 'Cocina', 1, null, 1200.50)],
    ));

    $shipment = app(ShipmentRepository::class)->findBySourceReference(
        SourceReference::of(OrderSource::Store, $externalId),
    );

    return $shipment?->id()->toString() ?? throw new RuntimeException("envío no encontrado: {$externalId}");
}

it('recibe un pedido por la puerta de integraciones (202)', function (): void {
    $this->postJson('/api/v1/integrations/shipments', [
        'source' => 'STORE',
        'external_id' => 'HTTP-'.uniqid(),
        'customer_name' => 'Cliente Integración',
        'customer_phone' => '+591 70000000',
        'customer_email' => 'cliente@example.test',
        'address_text' => 'Av. Arce 2345, La Paz',
        'address_reference' => 'Edificio Multicentro',
        'lat' => -16.5,
        'lng' => -68.15,
        'lines' => [
            [
                'sku' => 'SKU-INT-1',
                'product_name' => 'Lavadora',
                'quantity' => 2,
                'image_url' => 'https://example.test/img.png',
                'price' => 3200.00,
                'currency' => 'BOB',
            ],
        ],
    ])->assertStatus(202)
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.source', 'STORE');
});

it('rechaza con 422 un ingreso sin líneas', function (): void {
    $this->postJson('/api/v1/integrations/shipments', [
        'source' => 'STORE',
        'external_id' => 'HTTP-'.uniqid(),
        'customer_name' => 'Cliente Integración',
        'address_text' => 'Av. Arce 2345, La Paz',
        'lines' => [],
    ])->assertStatus(422);
});

it('importa una planilla CSV por la puerta de integraciones', function (): void {
    $ref = 'HTTP-CSV-'.uniqid();
    $csv = implode("\n", [
        'source,external_id,customer_name,address_text,address_reference,lat,lng,sku,product_name,quantity',
        "STORE,{$ref},Cliente CSV,Calle Falsa 123 La Paz,Ref,-16.5,-68.15,SKU-HTTP-CSV,Televisor,1",
    ]);

    $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

    $this->post('/api/v1/integrations/shipments/import', ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['data' => ['total', 'imported', 'duplicates', 'failed']]);
});

it('rechaza con 422 una importación sin archivo', function (): void {
    $this->postJson('/api/v1/integrations/shipments/import', [])
        ->assertStatus(422);
});

it('muestra el detalle de un pedido por su id (puerta app)', function (): void {
    $id = seedShipmentForHttp('HTTP-SHOW-'.uniqid());

    $this->getJson("/api/v1/app/shipments/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id);
});

it('devuelve 404 al mostrar un pedido inexistente', function (): void {
    $this->getJson('/api/v1/app/shipments/01920000-0000-7000-8000-000000000000')
        ->assertNotFound();
});

it('lista las direcciones de baja confianza (colección y paginada)', function (): void {
    // Sin coordenada: confianza baja, candidato a confirmar.
    seedShipmentForHttp('HTTP-LOW-'.uniqid(), lat: null, lng: null);

    $this->getJson('/api/v1/app/shipments/low-confidence-addresses')
        ->assertOk()
        ->assertJsonStructure(['data']);

    // Rama aditiva de paginación (E-02): al pedir página se devuelve el envoltorio paginado.
    $this->getJson('/api/v1/app/shipments/low-confidence-addresses?page=1&per_page=10')
        ->assertOk();
});

it('re-geocodifica la dirección de un pedido existente (puerta app)', function (): void {
    $id = seedShipmentForHttp('HTTP-GEO-'.uniqid());

    // Geocoder falso para evitar HTTP real a Nominatim: devuelve un punto exacto y confianza alta.
    app()->instance(Geocoder::class, new class implements Geocoder
    {
        public function geocode(Address $address): GeocodeResult
        {
            return GeocodeResult::of(
                AddressConfidence::of(95),
                GeocodeMatchType::Exact,
                GeoPoint::of(-16.5002, -68.1503),
            );
        }

        public function score(Address $address): AddressConfidence
        {
            return AddressConfidence::of(95);
        }
    });

    $this->postJson("/api/v1/app/shipments/{$id}/geocode")
        ->assertOk()
        ->assertJsonPath('data.id', $id);
});

it('devuelve 404 al re-geocodificar un pedido inexistente', function (): void {
    $this->postJson('/api/v1/app/shipments/01920000-0000-7000-8000-000000000000/geocode')
        ->assertNotFound();
});
