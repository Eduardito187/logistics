<?php

declare(strict_types=1);

namespace Logistics\Labels\Database\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Labels\Database\Factories\EmittedLabelRecordFactory;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Seeder propio del contexto Etiquetas. Deja etiquetas de demo verosímiles y autoconsistentes (QR
 * firmado válido + ZPL) para que el admin y las APIs tengan material realista: varios pedidos con
 * varios bultos, y un pedido con etiquetas revocadas por reimpresión (caso de ADR 004).
 *
 * `order_id` es una referencia blanda al pedido: para que sea trazable en el admin, se alinea con los
 * `external_id` REALES de los envíos ya sembrados (en lugar de un correlativo DM-2601... inventado). Si
 * todavía no hay envíos, cae en un juego de pedidos de demo fijo para no depender del orden de seed.
 *
 * No se auto-registra en el DatabaseSeeder raíz (archivo compartido): el integrador lo invoca desde
 * allí con `$this->call(LabelsSeeder::class)`.
 */
final class LabelsSeeder extends Seeder
{
    /**
     * Cantidad de bultos (etiquetas) por pedido, en orden; se mapea sobre los envíos reales disponibles.
     *
     * @var list<int>
     */
    private const LABEL_COUNTS = [3, 1, 5, 2];

    /**
     * Juego de pedidos de respaldo (order_id => nº de bultos) cuando no hay envíos reales sembrados.
     *
     * @var array<string, int>
     */
    private const FALLBACK_ORDERS = [
        'DM-260100001' => 3,
        'DM-260100002' => 1,
        'DM-260100003' => 5,
        'DM-260100004' => 2,
    ];

    public function run(): void
    {
        // Idempotente: si ya hay etiquetas de demo sembradas, no se vuelve a emitir ninguna (las
        // factories generan etiquetas con firma/ZPL y un segundo run las duplicaría). El contexto
        // Etiquetas es dueño exclusivo de su tabla, así que basta con que tenga filas.
        if (EmittedLabelRecord::query()->exists()) {
            return;
        }

        /** @var list<string> $orderRefs */
        $orderRefs = ShipmentRecord::query()->orderBy('external_id')->pluck('external_id')->all();

        if ($orderRefs === []) {
            $this->seedFallback();

            return;
        }

        // Un pedido por envío real (hasta donde haya cuentas de bultos definidas), con order_id = external_id.
        foreach (self::LABEL_COUNTS as $index => $labelCount) {
            $orderNumber = $orderRefs[$index] ?? null;
            if ($orderNumber === null) {
                break;
            }

            EmittedLabelRecordFactory::new()
                ->forOrder($orderNumber)
                ->count($labelCount)
                ->create();
        }

        // Un pedido reimpreso: 2 etiquetas revocadas (lote viejo) + 2 vigentes (lote nuevo). Usa el
        // último envío real para no pisar los pedidos de arriba; si no alcanza, reutiliza el primero.
        $reprintOrder = $orderRefs[count(self::LABEL_COUNTS)] ?? $orderRefs[0];
        EmittedLabelRecordFactory::new()
            ->forOrder($reprintOrder)
            ->revoked('label damaged in the yard')
            ->count(2)
            ->create();
        EmittedLabelRecordFactory::new()
            ->forOrder($reprintOrder)
            ->count(2)
            ->create();
    }

    /**
     * Pedidos de demo fijos cuando no hay envíos reales (p. ej. al correr el seeder aislado).
     */
    private function seedFallback(): void
    {
        foreach (self::FALLBACK_ORDERS as $orderNumber => $labelCount) {
            EmittedLabelRecordFactory::new()
                ->forOrder($orderNumber)
                ->count($labelCount)
                ->create();
        }

        EmittedLabelRecordFactory::new()
            ->forOrder('DM-260100005')
            ->revoked('label damaged in the yard')
            ->count(2)
            ->create();
        EmittedLabelRecordFactory::new()
            ->forOrder('DM-260100005')
            ->count(2)
            ->create();
    }
}
