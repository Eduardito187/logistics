<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Arma (idempotente) UNA orden de demo coherente para seguir el flujo COMPLETO de 0 a entregada en el
 * teléfono: aparece en la worklist del preparador (PLANNED, 0 etiquetas → se puede emitir), está
 * autorizada a emitir (parada en un plan publicado), es asignable a un camión real y, tras emitir +
 * asignar vehículo + cargar, queda lista para el POD con foto.
 *
 * Es una herramienta de DEMO/QA, no lógica de negocio: inserta directo las filas mínimas (envío + líneas
 * + bultos + plan publicado con su parada), como un seeder. Idempotente y ACOTADO: al re-ejecutar borra
 * SOLO las filas de ESTA orden de demo (por su order_reference) y las recrea; nunca toca otros datos.
 *
 * El camión se resuelve por PLACA (por defecto 904U10, el del chofer de prueba) para no depender de un id
 * fijo entre entornos; si no está, usa el primer vehículo operativo y lo informa.
 */
final class DemoOrderCommand extends Command
{
    protected $signature = 'logistics:demo:order
        {--order=DEMO-FLOW-01 : Referencia del pedido de demo (estable; re-ejecutar la resetea)}
        {--plate=904U10 : Placa del camión de reparto al que se planifica la orden}';

    protected $description = 'Arma una orden de demo coherente para seguir el flujo de 0 a entregada';

    public function handle(): int
    {
        $orderNumber = trim((string) $this->option('order')) ?: 'DEMO-FLOW-01';
        $plate = trim((string) $this->option('plate')) ?: '904U10';

        $vehicle = $this->resolveVehicle($plate);
        if ($vehicle === null) {
            $this->error('No hay ningún vehículo operativo (available/in_service) en fleet_vehicles. Sembrá la flota primero.');

            return self::FAILURE;
        }
        if ((string) $vehicle->plate !== $plate) {
            $this->warn("No se encontró el camión con placa {$plate}; se usa {$vehicle->plate} (operativo).");
        }

        try {
            DB::transaction(function () use ($orderNumber, $vehicle): void {
                $this->reset($orderNumber);
                $this->build($orderNumber, (string) $vehicle->id);
            });
        } catch (Throwable $e) {
            $this->error('No se pudo armar la orden de demo: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Orden de demo lista: {$orderNumber}");
        $this->line('  Estado: PLANNED · 0 etiquetas · entrega a domicilio (Santa Cruz)');
        $this->line("  Planificada en el camión: {$vehicle->plate}");
        $this->newLine();
        $this->line('Flujo sugerido desde el teléfono:');
        $this->line("  1. Preparador: abrir {$orderNumber} → emitir etiquetas (POST /app/orders/{$orderNumber}/labels)");
        $this->line("  2. Andén: asignar vehículo (POST /app/orders/{$orderNumber}/vehicle {vehicle_id: {$vehicle->id}})");
        $this->line('  3. Chofer: escanear carga (LOAD) → la orden aparece en «Mi ruta de hoy»');
        $this->line('  4. Chofer: entregar con foto (POST /app/deliveries/proof-of-delivery, photo_path obligatorio)');

        return self::SUCCESS;
    }

    /**
     * Vehículo operativo por placa; si no existe, el primero operativo. null si no hay ninguno.
     */
    private function resolveVehicle(string $plate): ?\stdClass
    {
        $operative = ['available', 'in_service'];

        return DB::table('fleet_vehicles')->where('plate', $plate)->whereIn('status', $operative)->first(['id', 'plate'])
            ?? DB::table('fleet_vehicles')->whereIn('status', $operative)->orderBy('plate')->first(['id', 'plate']);
    }

    /**
     * Borra SOLO las filas de esta orden de demo (acotado por order_reference), para recrearla limpia.
     */
    private function reset(string $orderNumber): void
    {
        $shipmentIds = DB::table('shipments')->where('order_reference', $orderNumber)->pluck('id')->all();
        if ($shipmentIds === []) {
            return;
        }

        $unitIds = DB::table('shipment_units')->whereIn('shipment_id', $shipmentIds)->pluck('id')->all();
        $planIds = DB::table('planning_plan_stops')->whereIn('shipment_id', $shipmentIds)->pluck('plan_id')->unique()->all();

        // Rastros de ejecución/tracking/etiquetas de ESTA orden (si ya se corrió el flujo); tolerante a que
        // alguna tabla no exista en un entorno mínimo.
        $this->deleteQuietly('execution_scan_events', 'unit_id', $unitIds);
        $this->deleteQuietly('execution_deliveries', 'shipment_id', $shipmentIds);
        $this->deleteQuietly('execution_driver_route_stops', 'shipment_id', $shipmentIds);
        $this->deleteQuietly('tracking_units', 'shipment_id', $shipmentIds);
        $this->deleteQuietly('tracking_shipments', 'shipment_id', $shipmentIds);
        $this->deleteQuietly('label_events', 'order_id', [$orderNumber]);
        $this->deleteQuietly('labels_emitted', 'order_id', [$orderNumber]);

        DB::table('planning_plan_stops')->whereIn('shipment_id', $shipmentIds)->delete();
        // Planes que quedaron SIN paradas tras quitar las nuestras: eran de esta demo, se borran.
        foreach ($planIds as $planId) {
            if (DB::table('planning_plan_stops')->where('plan_id', $planId)->doesntExist()) {
                DB::table('planning_plans')->where('id', $planId)->delete();
            }
        }

        DB::table('shipment_units')->whereIn('shipment_id', $shipmentIds)->delete();
        DB::table('shipment_lines')->whereIn('shipment_id', $shipmentIds)->delete();
        DB::table('shipments')->whereIn('id', $shipmentIds)->delete();
    }

    /**
     * @param array<mixed> $values
     */
    private function deleteQuietly(string $table, string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        try {
            DB::table($table)->whereIn($column, $values)->delete();
        } catch (Throwable) {
            // Tabla ausente en este entorno: nada que limpiar.
        }
    }

    /**
     * Crea el envío (a domicilio, PLANNED), sus líneas y bultos, y un plan PUBLICADO con la parada
     * (autoriza la emisión por orden). Coordenadas en Santa Cruz: el origen resuelve al CD más cercano.
     */
    private function build(string $orderNumber, string $vehicleId): void
    {
        $now = now();
        $shipmentId = (string) Str::uuid7();

        DB::table('shipments')->insert([
            'id' => $shipmentId,
            'source' => 'demo',
            'external_id' => $orderNumber,
            'order_reference' => $orderNumber,
            'customer_name' => 'Cliente Demo',
            'address_text' => 'Av. Cristo Redentor 2500, Santa Cruz de la Sierra',
            'address_reference' => 'Portón negro, timbre 2',
            'address_confidence' => 95,
            'address_confidence_level' => 'high',
            'customer_phone' => '+591 70000000',
            'lat' => -17.7580,
            'lng' => -63.1780,
            'destination_node_id' => null,
            'fulfillment_mode' => 'OWNED',
            'status' => 'PLANNED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Productos reales: SIEMPRE nombre + SKU (regla de etiquetado), nunca vacío.
        $lines = [
            ['sku' => 'TV-SAMS-QN55', 'product_name' => 'Televisor Samsung 55" QLED 4K', 'product_image_url' => null],
            ['sku' => 'MW-LG-NC42', 'product_name' => 'Microondas LG NeoChef 42L', 'product_image_url' => null],
        ];

        foreach ($lines as $line) {
            $lineId = (string) Str::uuid7();
            DB::table('shipment_lines')->insert([
                'id' => $lineId,
                'shipment_id' => $shipmentId,
                'sku' => $line['sku'],
                'product_name' => $line['product_name'],
                'quantity' => 1,
                'product_image_url' => $line['product_image_url'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            // Un bulto por línea (1 de 1), una pieza (1/1).
            DB::table('shipment_units')->insert([
                'id' => (string) Str::uuid7(),
                'shipment_id' => $shipmentId,
                'line_id' => $lineId,
                'unit_index' => 1,
                'unit_total' => 1,
                'piece_index' => 1,
                'piece_total' => 1,
                'fulfillment_mode' => 'owned',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Plan PUBLICADO con la parada: autoriza la emisión por orden y deja la orden "planificada". El plan
        // es único por (fecha, vehículo): si el camión ya tiene uno hoy (su ruta real), se REUSA y la orden
        // se suma como una parada más; si no, se crea. Así el pedido queda en la ruta de ese chofer.
        $existingPlanId = DB::table('planning_plans')
            ->where('plan_date', $now->toDateString())
            ->where('vehicle_id', $vehicleId)
            ->value('id');

        $planId = is_string($existingPlanId) ? $existingPlanId : (string) Str::uuid7();
        if (!is_string($existingPlanId)) {
            DB::table('planning_plans')->insert([
                'id' => $planId,
                'plan_date' => $now->toDateString(),
                'vehicle_id' => $vehicleId,
                'status' => 'PUBLISHED',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sequence = (int) DB::table('planning_plan_stops')->where('plan_id', $planId)->max('sequence') + 1;
        DB::table('planning_plan_stops')->insert([
            'id' => (string) Str::uuid7(),
            'plan_id' => $planId,
            'shipment_id' => $shipmentId,
            'sequence' => $sequence,
            'order_reference' => $orderNumber,
            'customer_name' => 'Cliente Demo',
            'address' => 'Av. Cristo Redentor 2500, Santa Cruz de la Sierra',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
