<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Labels\Application\Command\EmitOrderLabels\EmitOrderLabels;
use Logistics\Shared\Application\Command\CommandBus;
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
        {--plate=904U10 : Placa del camión de reparto al que se planifica la orden}
        {--stage=planned : planned (recién planificada, 0 etiquetas) | delivery (ya emitida+cargada, entrega ABIERTA lista para POD)}';

    protected $description = 'Arma una orden de demo coherente para seguir el flujo de 0 a entregada';

    /** Cliente y dirección fijos del pedido de demo (reusados en el envío, la parada y la ruta). */
    private const string CUSTOMER_NAME = 'Cliente Demo';

    private const string CUSTOMER_ADDRESS = 'Av. Cristo Redentor 2500, Santa Cruz de la Sierra';

    public function handle(): int
    {
        $orderNumber = trim($this->stringOption('order')) ?: 'DEMO-FLOW-01';
        $plate = trim($this->stringOption('plate')) ?: '904U10';

        $vehicle = $this->resolveVehicle($plate);
        if ($vehicle === null) {
            $this->error('No hay ningún vehículo operativo (available/in_service) en fleet_vehicles. Sembrá la flota primero.');

            return self::FAILURE;
        }
        if ((string) $vehicle->plate !== $plate) {
            $this->warn("No se encontró el camión con placa {$plate}; se usa {$vehicle->plate} (operativo).");
        }

        $stage = trim($this->stringOption('stage')) === 'delivery' ? 'delivery' : 'planned';

        try {
            $shipmentId = DB::transaction(function () use ($orderNumber, $vehicle): string {
                $this->reset($orderNumber);

                return $this->build($orderNumber, (string) $vehicle->id);
            });

            if ($stage === 'delivery') {
                // Fuera de la transacción de armado (ya commiteada): emite etiquetas, prepara y carga cada
                // bulto por el bus CQRS → deja una ENTREGA ABIERTA (Loaded) en el camión, lista para el POD.
                $this->loadForDelivery($orderNumber, (string) $vehicle->id, $shipmentId);
            }
        } catch (Throwable $e) {
            $this->error('No se pudo armar la orden de demo: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Orden de demo lista: {$orderNumber}");
        if ($stage === 'delivery') {
            $this->line('  Estado: CARGADA · etiquetas emitidas · entrega ABIERTA (Loaded), lista para POD');
            $this->line("  En «Mi ruta de hoy» del camión: {$vehicle->plate} (id {$vehicle->id})");
            $this->newLine();
            $this->line('Para probar el POD desde el teléfono (chofer):');
            $this->line('  1. Subir foto: POST /app/deliveries/photos (shipment_id, photo) → devuelve photo_path');
            $this->line('  2. Entregar: POST /app/deliveries/proof-of-delivery (shipment_id, unit_id, confirmation, photo_path OBLIGATORIO, lat, lng, occurred_at)');
        } else {
            $this->line('  Estado: PLANNED · 0 etiquetas · entrega a domicilio (Santa Cruz)');
            $this->line("  Planificada en el camión: {$vehicle->plate}");
            $this->newLine();
            $this->line('Flujo sugerido desde el teléfono:');
            $this->line("  1. Preparador: abrir {$orderNumber} → emitir etiquetas (POST /app/orders/{$orderNumber}/labels)");
            $this->line("  2. Andén: asignar vehículo (POST /app/orders/{$orderNumber}/vehicle {vehicle_id: {$vehicle->id}})");
            $this->line('  3. Chofer: escanear carga (LOAD) → la orden aparece en «Mi ruta de hoy»');
            $this->line('  4. Chofer: entregar con foto (POST /app/deliveries/proof-of-delivery, photo_path obligatorio)');
            $this->newLine();
            $this->line('Para saltar directo a la entrega: --stage=delivery (deja la orden cargada y lista para POD).');
        }

        return self::SUCCESS;
    }

    /**
     * Emite las etiquetas y PREPARA + CARGA cada bulto por el bus CQRS, dejando una entrega ABIERTA (Loaded)
     * en el camión y la parada en su ruta de hoy. Es lo que necesita el POD: sin esto no hay entrega que cerrar.
     */
    private function loadForDelivery(string $orderNumber, string $vehicleId, string $shipmentId): void
    {
        $bus = app(CommandBus::class);
        $now = now();

        // 1) Etiquetas emitidas (idempotente). 2) Parada en la ruta del camión («Mi ruta de hoy»).
        $bus->dispatch(new EmitOrderLabels($orderNumber));
        $bus->dispatch(new AttachShipmentToRoute(
            vehicleId: $vehicleId,
            shipmentId: $shipmentId,
            orderReference: $orderNumber,
            customerName: self::CUSTOMER_NAME,
            address: self::CUSTOMER_ADDRESS,
        ));

        // 3) Preparación + carga de cada bulto → la entrega queda Loaded (abierta), con el vehículo asignado.
        foreach (DB::table('shipment_units')->where('shipment_id', $shipmentId)->pluck('id') as $unitId) {
            $uid = (string) $unitId;
            $bus->dispatch(new RecordPreparationScan(
                shipmentId: $shipmentId,
                vehicleId: $vehicleId,
                unitId: $uid,
                lat: -17.7580,
                lng: -63.1780,
                deviceId: 'demo-device',
                userId: 'demo',
                occurredAt: $now->toImmutable()->format(DATE_ATOM),
                idempotencyKey: 'demo-prep-'.$uid,
            ));
            $bus->dispatch(new RecordLoadScan(
                shipmentId: $shipmentId,
                unitId: $uid,
                lat: -17.7580,
                lng: -63.1780,
                deviceId: 'demo-device',
                userId: 'demo',
                occurredAt: $now->toImmutable()->format(DATE_ATOM),
                idempotencyKey: 'demo-load-'.$uid,
            ));
        }
    }

    /**
     * Vehículo operativo por placa; si no existe, el primero operativo. null si no hay ninguno.
     */
    /**
     * Opción de consola como string (vacío si no vino o no es escalar de texto). `option()` devuelve una unión
     * ancha (array|bool|...), así que un `(string)` directo no es seguro para PHPStan.
     */
    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }

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
     * Devuelve el id del envío creado.
     */
    private function build(string $orderNumber, string $vehicleId): string
    {
        $now = now();
        $shipmentId = (string) Str::uuid7();

        DB::table('shipments')->insert([
            'id' => $shipmentId,
            'source' => 'demo',
            'external_id' => $orderNumber,
            'order_reference' => $orderNumber,
            'customer_name' => self::CUSTOMER_NAME,
            'address_text' => self::CUSTOMER_ADDRESS,
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
            'customer_name' => self::CUSTOMER_NAME,
            'address' => self::CUSTOMER_ADDRESS,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $shipmentId;
    }
}
