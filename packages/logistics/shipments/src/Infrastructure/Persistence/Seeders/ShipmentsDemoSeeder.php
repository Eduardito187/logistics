<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Domain\ValueObject\Money;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductImageUrl;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Seeder propio del contexto: carga pedidos de demostración realistas (direcciones de La Paz y Santa
 * Cruz, fuentes mezcladas, direcciones de alta y baja confianza) para poblar el admin y las APIs en
 * desarrollo.
 *
 * Construye los pedidos por el dominio (Shipment::receive) y los persiste por el repositorio, así los
 * bultos se generan igual que en producción. Idempotente: si el pedido ya existe por (fuente, id
 * externo), no lo vuelve a crear, para poder reejecutar el seeder sin duplicar. No publica eventos al
 * outbox: sembrar no es un hecho de negocio.
 */
final class ShipmentsDemoSeeder extends Seeder
{
    public function run(ShipmentRepository $shipments, AddressConfidenceScorer $scorer): void
    {
        foreach ($this->demoOrders() as $order) {
            $source = OrderSource::from($order['source']);
            $reference = SourceReference::of($source, $order['external_id']);

            if ($shipments->findBySourceReference($reference) !== null) {
                continue;
            }

            $geoPoint = $order['lat'] !== null && $order['lng'] !== null
                ? GeoPoint::of($order['lat'], $order['lng'])
                : null;

            $address = Address::of($order['address_text'], $order['address_reference'], $geoPoint);

            $lines = [];
            foreach ($order['lines'] as $line) {
                $lines[] = ShipmentLine::create(
                    ShipmentLineId::generate(),
                    Sku::of($line['sku']),
                    ProductName::of($line['product_name']),
                    $line['quantity'],
                    ProductImageUrl::fromNullable($line['image_url'] ?? null),
                    Money::fromNullable($line['price'] ?? null),
                );
            }

            $customer = Customer::of(
                $order['customer_name'],
                $order['customer_document_id'] ?? null,
                $order['customer_phone'] ?? null,
                $order['customer_email'] ?? null,
            );

            $shipment = Shipment::receive(
                $shipments->nextIdentity(),
                $source,
                $reference,
                $customer,
                $address,
                $scorer->score($address),
                $lines,
            );

            $shipments->save($shipment);
        }
    }

    /**
     * Pedidos de demostración. Direcciones reales de Bolivia; algunas sin coordenadas (baja confianza).
     *
     * @return list<array{source: string, external_id: string, customer_name: string, customer_document_id?: ?string, customer_phone?: ?string, customer_email?: ?string, address_text: string, address_reference: ?string, lat: ?float, lng: ?float, lines: list<array{sku: string, product_name: string, quantity: int, image_url?: ?string, price?: ?float}>}>
     */
    private function demoOrders(): array
    {
        return [
            [
                'source' => 'SAP',
                'external_id' => 'SO-900001',
                'customer_name' => 'María Quispe',
                'customer_document_id' => '6284531 LP',
                'customer_phone' => '+591 71544820',
                'customer_email' => 'maria.quispe@gmail.com',
                'address_text' => 'Av. 6 de Agosto 2170, Sopocachi, La Paz',
                'address_reference' => 'Edificio esquina, timbre 3B',
                'lat' => -16.5095,
                'lng' => -68.1261,
                'lines' => [
                    ['sku' => 'ROP-ARM-2P', 'product_name' => 'Ropero 2 puertas', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/rop-arm-2p/200/200', 'price' => 1899.00],
                    ['sku' => 'COL-QUEEN', 'product_name' => 'Colchón Queen', 'quantity' => 2, 'image_url' => 'https://picsum.photos/seed/col-queen/200/200', 'price' => 2450.50],
                ],
            ],
            [
                'source' => 'MAGENTO',
                'external_id' => '100000452',
                'customer_name' => 'Jorge Mendoza',
                'customer_document_id' => '4921073 LP',
                'customer_phone' => '+591 67712093',
                'customer_email' => 'jorge.mendoza@hotmail.com',
                'address_text' => 'Calle 21 de Calacoto 8400, La Paz',
                'address_reference' => null,
                'lat' => -16.5420,
                'lng' => -68.0790,
                'lines' => [
                    ['sku' => 'TV-55-4K', 'product_name' => 'Televisor 55 pulgadas 4K', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/tv-55-4k/200/200', 'price' => 3299.00],
                ],
            ],
            [
                'source' => 'STORE',
                'external_id' => 'SCZ-TND-0012',
                'customer_name' => 'Ferretería El Tornillo',
                'customer_document_id' => '1028847026 SC',
                'customer_phone' => '+591 70093412',
                'customer_email' => 'ventas@ferreteriaeltornillo.com.bo',
                'address_text' => 'Av. Cristo Redentor 3er anillo, Santa Cruz de la Sierra',
                'address_reference' => 'Entregar en depósito trasero',
                'lat' => -17.7695,
                'lng' => -63.1850,
                'lines' => [
                    ['sku' => 'CEM-50KG', 'product_name' => 'Bolsa de cemento 50kg', 'quantity' => 5, 'image_url' => 'https://picsum.photos/seed/cem-50kg/200/200', 'price' => 55.00],
                    ['sku' => 'TAL-PRO', 'product_name' => 'Taladro percutor', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/tal-pro/200/200', 'price' => 420.00],
                ],
            ],
            [
                'source' => 'MANUAL',
                'external_id' => 'MAN-2026-0003',
                'customer_name' => 'Rosa Flores',
                // Pedido con contacto parcial: solo teléfono (así se ve la ficha con datos faltantes).
                'customer_phone' => '+591 76500118',
                'address_text' => 'Zona Villa Fátima, cerca de la plaza, La Paz',
                'address_reference' => 'Casa de dos pisos color verde, portón negro',
                'lat' => null,
                'lng' => null,
                // Pedido sin imagen ni precio: demuestra que la ingesta sigue funcionando sin presentación.
                'lines' => [
                    ['sku' => 'LAV-7KG', 'product_name' => 'Lavadora 7kg', 'quantity' => 1],
                ],
            ],
            [
                'source' => 'EXCEL',
                'external_id' => 'XLS-COCHA-0088',
                'customer_name' => 'Luis Vargas',
                'customer_document_id' => '5830912 CB',
                'customer_phone' => '+591 72280145',
                'customer_email' => 'luis.vargas@yahoo.es',
                'address_text' => 'Av. América Oeste 1250, Cochabamba',
                'address_reference' => null,
                'lat' => -17.3830,
                'lng' => -66.1680,
                'lines' => [
                    ['sku' => 'HEL-300L', 'product_name' => 'Heladera 300 litros', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/hel-300l/200/200', 'price' => 4150.00],
                    ['sku' => 'MIC-25L', 'product_name' => 'Microondas 25 litros', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/mic-25l/200/200', 'price' => 690.00],
                    ['sku' => 'LIC-600W', 'product_name' => 'Licuadora 600W', 'quantity' => 3, 'image_url' => 'https://picsum.photos/seed/lic-600w/200/200', 'price' => 320.00],
                ],
            ],
            [
                'source' => 'SAP',
                'external_id' => 'SO-900114',
                'customer_name' => 'Comercial Andina SRL',
                'customer_document_id' => '1023456789 (NIT)',
                'customer_phone' => '+591 68840277',
                'customer_email' => 'compras@comercialandina.bo',
                'address_text' => 'Parque Industrial PI-7, El Alto',
                'address_reference' => null,
                'lat' => null,
                'lng' => null,
                'lines' => [
                    ['sku' => 'ESC-MET', 'product_name' => 'Escritorio metálico', 'quantity' => 4, 'image_url' => 'https://picsum.photos/seed/esc-met/200/200', 'price' => 780.00],
                    ['sku' => 'SIL-ERG', 'product_name' => 'Silla ergonómica', 'quantity' => 8, 'image_url' => 'https://picsum.photos/seed/sil-erg/200/200', 'price' => 540.00],
                ],
            ],
            // Pedidos de reparto DENTRO de La Paz ciudad (todos con coordenadas reales en zonas distintas:
            // Miraflores, Max Paredes, Sopocachi y Villa Fátima). Son la materia prima de la ruta de demo
            // del chofer: un reparto COHERENTE de un solo departamento. A propósito NO están en orden de
            // cercanía en esta lista; el orden de reparto lo resuelve el optimizador por vecino más cercano.
            [
                'source' => 'SAP',
                'external_id' => 'LP-RUTA-0101',
                'customer_name' => 'Carla Mamani',
                'customer_document_id' => '7391024 LP',
                'customer_phone' => '+591 71209845',
                'customer_email' => 'carla.mamani@gmail.com',
                'address_text' => 'Av. Busch 1450, Miraflores, La Paz',
                'address_reference' => 'Frente a la plaza Villarroel',
                'lat' => -16.5089,
                'lng' => -68.1193,
                'lines' => [
                    ['sku' => 'COC-4H', 'product_name' => 'Cocina 4 hornallas', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/coc-4h/200/200', 'price' => 1290.00],
                ],
            ],
            [
                'source' => 'MAGENTO',
                'external_id' => 'LP-RUTA-0102',
                'customer_name' => 'Pedro Choque',
                'customer_document_id' => '6102938 LP',
                'customer_phone' => '+591 68812340',
                'customer_email' => 'pedro.choque@hotmail.com',
                'address_text' => 'Calle Max Paredes 820, El Tejar, La Paz',
                'address_reference' => 'Galería comercial, local 14',
                'lat' => -16.4930,
                'lng' => -68.1470,
                'lines' => [
                    ['sku' => 'VEN-16', 'product_name' => 'Ventilador de pie 16"', 'quantity' => 2, 'image_url' => 'https://picsum.photos/seed/ven-16/200/200', 'price' => 210.00],
                ],
            ],
            [
                'source' => 'STORE',
                'external_id' => 'LP-RUTA-0103',
                'customer_name' => 'Andrea Gutiérrez',
                'customer_document_id' => '5820174 LP',
                'customer_phone' => '+591 72213398',
                'customer_email' => 'andrea.gutierrez@gmail.com',
                'address_text' => 'Av. 20 de Octubre 1820, Sopocachi, La Paz',
                'address_reference' => 'Edificio San Jorge, piso 4',
                'lat' => -16.5130,
                'lng' => -68.1320,
                'lines' => [
                    ['sku' => 'TV-43-FHD', 'product_name' => 'Televisor 43 pulgadas Full HD', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/tv-43-fhd/200/200', 'price' => 1750.00],
                ],
            ],
            [
                'source' => 'MANUAL',
                'external_id' => 'LP-RUTA-0104',
                'customer_name' => 'Hugo Apaza',
                'customer_document_id' => '4728190 LP',
                'customer_phone' => '+591 76604521',
                'customer_email' => 'hugo.apaza@yahoo.es',
                'address_text' => 'Av. Tejada Sorzano 240, Villa Fátima, La Paz',
                'address_reference' => 'Portón café, timbre del fondo',
                'lat' => -16.4880,
                'lng' => -68.1120,
                'lines' => [
                    ['sku' => 'REF-SIDE', 'product_name' => 'Refrigerador side by side', 'quantity' => 1, 'image_url' => 'https://picsum.photos/seed/ref-side/200/200', 'price' => 5890.00],
                ],
            ],
        ];
    }
}
