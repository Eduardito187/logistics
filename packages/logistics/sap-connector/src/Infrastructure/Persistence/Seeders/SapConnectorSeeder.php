<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferKind;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\StockTransferRecord;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Datos de demo del conector SAP: un mapa de traducción realista (sku propio ↔ coordenada SAP) y
 * traspasos de stock en los distintos estados del ciclo de despacho, para poder recorrer el panel de
 * reconciliación (P2-SAP-04) sin tener SAP conectado.
 *
 * Es idempotente: se puede correr varias veces (upsert por clave natural). El integrador lo llama desde
 * database/seeders/DatabaseSeeder.php; no toca ese archivo compartido.
 */
final class SapConnectorSeeder extends Seeder
{
    /**
     * Mapa de traducción de demo: sku propio => [material SAP, centro, almacén].
     *
     * @var array<string, array{material: string, center: string, warehouse: string}>
     */
    private const MAPPINGS = [
        'SKU-REFRI-7001' => ['material' => '100007001', 'center' => 'C1001', 'warehouse' => 'W10'],
        'SKU-LAVA-7002' => ['material' => '100007002', 'center' => 'C1001', 'warehouse' => 'W10'],
        'SKU-TV-5501' => ['material' => '100005501', 'center' => 'C1001', 'warehouse' => 'W20'],
        'SKU-COCINA-3300' => ['material' => '100003300', 'center' => 'C1002', 'warehouse' => 'W11'],
        'SKU-MICRO-2100' => ['material' => '100002100', 'center' => 'C1002', 'warehouse' => 'W11'],
    ];

    public function run(): void
    {
        $this->seedMappings();
        $this->seedTransfers();
        $this->seedConsignmentDemo();
    }

    private function seedMappings(): void
    {
        foreach (self::MAPPINGS as $sku => $coordinate) {
            MaterialMappingRecord::query()->updateOrCreate(
                ['sku' => $sku],
                [
                    'id' => MaterialMappingId::generate()->toString(),
                    'sap_material' => $coordinate['material'],
                    'center' => $coordinate['center'],
                    'warehouse' => $coordinate['warehouse'],
                ],
            );
        }
    }

    private function seedTransfers(): void
    {
        // Un traspaso por estado del ciclo, con coordenadas coherentes con el mapa de arriba, para que
        // el panel de reconciliación muestre pendientes, en vuelo, confirmados y fallidos.
        $demo = [
            ['sku' => 'SKU-REFRI-7001', 'ref' => 'SHP-20001', 'qty' => 2, 'status' => TransferStatus::Acknowledged, 'error' => null],
            ['sku' => 'SKU-LAVA-7002', 'ref' => 'SHP-20002', 'qty' => 1, 'status' => TransferStatus::Sent, 'error' => null],
            ['sku' => 'SKU-TV-5501', 'ref' => 'SHP-20003', 'qty' => 3, 'status' => TransferStatus::Pending, 'error' => null],
            ['sku' => 'SKU-COCINA-3300', 'ref' => 'SHP-20004', 'qty' => 1, 'status' => TransferStatus::Failed, 'error' => 'SAP respondió 503 al empujar el traspaso'],
        ];

        // `shipment_ref` es una referencia blanda al envío: se alinea con los external_id reales ya
        // sembrados para que sea trazable en el panel; si no hay envíos, se deja el SHP-200xx de demo.
        /** @var list<string> $shipmentRefs */
        $shipmentRefs = ShipmentRecord::query()->orderBy('external_id')->pluck('external_id')->all();

        foreach ($demo as $index => $row) {
            $coordinate = self::MAPPINGS[$row['sku']];
            $shipmentRef = $shipmentRefs[$index] ?? $row['ref'];
            // event_id es columna uuid: se deriva un uuid determinista por fila para que el seeder sea
            // idempotente (misma clave de recepción en cada corrida) y la columna siga siendo válida.
            StockTransferRecord::query()->updateOrCreate(
                ['event_id' => $this->deterministicUuid('sap-demo-reception-'.($index + 1))],
                [
                    'id' => StockTransferId::generate()->toString(),
                    'shipment_ref' => $shipmentRef,
                    'material' => $coordinate['material'],
                    'center' => $coordinate['center'],
                    'warehouse' => $coordinate['warehouse'],
                    'quantity' => $row['qty'],
                    'status' => $row['status']->value,
                    'last_error' => $row['error'],
                ],
            );
        }
    }

    /**
     * Demo de la dimensión de propiedad (ADR 017): un mapeo de consignación de un seller y un traspaso de
     * consignación, para poder filtrar por propiedad en el admin. Es aditivo e idempotente, y solo corre
     * si hay al menos un seller sembrado (si no, el conector sigue siendo 100% retail).
     */
    private function seedConsignmentDemo(): void
    {
        /** @var string|null $sellerId */
        $sellerId = SellerRecord::query()->orderBy('legal_name')->value('id');
        if (!is_string($sellerId) || $sellerId === '') {
            return;
        }

        // Mapeo de la coordenada SAP del material de un seller (consignación), con su propio sku de seller.
        MaterialMappingRecord::query()->updateOrCreate(
            ['sku' => 'SELLER-AUDIO-9001'],
            [
                'id' => MaterialMappingId::generate()->toString(),
                'sap_material' => '900009001',
                'center' => 'C1001',
                'warehouse' => 'W30',
                'seller_id' => $sellerId,
            ],
        );

        // Traspaso de consignación: nunca se postea a SAP como material propio de Dismac.
        StockTransferRecord::query()->updateOrCreate(
            ['event_id' => $this->deterministicUuid('sap-demo-consignment-1')],
            [
                'id' => StockTransferId::generate()->toString(),
                'shipment_ref' => 'SHP-CONSIGN-9001',
                'material' => '900009001',
                'center' => 'C1001',
                'warehouse' => 'W30',
                'quantity' => 4,
                'status' => TransferStatus::Pending->value,
                'last_error' => null,
                'transfer_kind' => TransferKind::Consignment->value,
                'owner_party_id' => $sellerId,
            ],
        );
    }

    /**
     * Deriva un uuid determinista (forma v4) desde una semilla estable, para claves de demo idempotentes.
     */
    private function deterministicUuid(string $seed): string
    {
        $hash = md5($seed);

        return sprintf(
            '%08s-%04s-4%03s-%04x-%012s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000,
            substr($hash, 20, 12),
        );
    }
}
