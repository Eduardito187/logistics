<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\SettlementRecord;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Command\ReportDeliveryIssue\ReportDeliveryIssue;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Labels\Application\Command\EmitShipmentLabels\EmitShipmentLabels;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\DeliverySlot;
use Logistics\Shipments\Domain\ValueObject\GeoPoint as ShipmentGeoPoint;
use Logistics\Shipments\Domain\ValueObject\Money;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductImageUrl;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use RuntimeException;

/**
 * Enriquece el escenario de prueba (lo arma primero TodayScenarioSeeder) con VARIEDAD de estados y datos
 * para que el dueño pueda probar TODA la app Flutter desde cada rol periférico (chofer, preparador,
 * tienda/transportista, supervisor). NO reemplaza ni duplica lo de TodayScenarioSeeder: AGREGA envíos
 * nuevos (ids externos fechados) sobre el MISMO vehículo del chofer y la MISMA tienda T-SCZ-01, resueltos
 * por su código/usuario (no inventa flota ni nodos).
 *
 * Lo invoca TestDataSeeder al final:
 *   $this->call(\Database\Seeders\TestDataVarietySeeder::class);
 *
 * Qué agrega, para HOY y MAÑANA (zona America/La_Paz):
 *   1. Entregas en estados variados para el CHOFER (solo HOY, el día en curso: cerrar una entrega en el
 *      futuro no tiene sentido):
 *        - ENTREGADA con foto (POD con photo url).
 *        - EN CURSO cargada, lista para POD (sin cerrar; la foto es obligatoria al cerrar).
 *        - FALLIDA "no se pudo entregar" con motivo (cliente ausente).
 *        - Con un INCONVENIENTE reportado (DELAY) sin cerrar la entrega.
 *      MAÑANA recibe los dos estados pre-cierre plausibles al arrancar la ruta: EN CURSO (lista para POD)
 *      e INCONVENIENTE (ACCESS), ambos sobre el mismo vehículo.
 *   2. Cola de preparación para el PREPARADOR: 2–3 envíos del día listos para preparar (recibidos, con
 *      sus bultos y etiquetas QR), sin entrega iniciada, para testear escaneos de preparación.
 *   3. TRANSPORTISTA / carrier: un envío de última milla (destino tienda) por día, un transportista
 *      tercero registrado y su liquidación del período (mes en curso), y seguimiento público con token
 *      para 2–3 envíos por día (página pública / tracking).
 *   4. NOTIFICACIONES: 1–2 por día encoladas por el flujo real (QueueNotification → outbox), p. ej.
 *      "pedido en ruta" hoy y "en preparación" mañana. No pega a FCM: solo deja el mensaje encolado.
 *   5. CLIENTES con datos completos: cada envío lleva teléfono +591, dirección con referencia y
 *      coordenadas reales de Santa Cruz, y líneas de producto con imagen (pantalla "Entrega en curso").
 *
 * Idempotente y ADITIVO: cada envío se guarda por (fuente, id externo fechado); las entregas se saltan si
 * ya existen; etiquetas, seguimiento, liquidación y notificaciones se guardan por su clave de negocio. No
 * trunca ni borra nada. Solo entornos locales/dev.
 */
final class TestDataVarietySeeder extends Seeder
{
    private const string TIMEZONE = 'America/La_Paz';

    /** Días de variedad a sembrar desde hoy (dos semanas), alineado con TestDataSeeder. */
    private const int VARIETY_DAYS = 14;

    private const string STORE_CODE = 'T-SCZ-01';

    /** Camión del escenario (mismo que usa TodayScenarioSeeder); solo fallback si no hay tripulación. */
    private const string ROUTE_VEHICLE_PLATE = '904U10';

    /** Código de origen corto para la etiqueta (patrón del QR: [A-Z0-9-]{1,20}). */
    private const string LABEL_ORIGIN = 'CD-SCZ';

    private const string DEVICE_ID = 'scenario-variety-device';

    /** Transportista tercero de prueba (idempotente por tax_id). */
    private const string CARRIER_TAX_ID = 'NIT-VARIETY-01';

    private const string CARRIER_NAME = 'Expreso Cruceño (prueba)';

    private DateTimeZone $tz;

    private DateTimeImmutable $now;

    private string $today;

    private string $tomorrow;

    /** @var list<string> tokens de seguimiento abiertos, para el resumen. */
    private array $openedTrackingShipments = [];

    public function __construct(
        private readonly CommandBus $bus,
        private readonly ShipmentRepository $shipments,
        private readonly AddressConfidenceScorer $scorer,
        private readonly DeliveryRepository $deliveries,
    ) {
        $this->tz = new DateTimeZone(self::TIMEZONE);
        $this->now = new DateTimeImmutable('now', $this->tz);
        $this->today = $this->now->format('Y-m-d');
        $this->tomorrow = $this->now->modify('+1 day')->format('Y-m-d');
    }

    public function run(): void
    {
        if (!app()->environment(['local', 'development', 'dev'])) {
            $this->say('warn', 'TestDataVarietySeeder solo corre en entornos locales/dev; no se hizo nada.');

            return;
        }

        $store = $this->node(self::STORE_CODE);
        $storeId = (string) $store->id;
        $vehicleId = $this->resolveChoferVehicleId();
        $choferUserId = $this->resolveChoferUserId();

        // DOS SEMANAS (hoy + 13): HOY (día en curso) lleva los cuatro estados de entrega; los días
        // siguientes (planificados) solo estados pre-cierre plausibles al arrancar (cargado / inconveniente).
        for ($offset = 0; $offset < self::VARIETY_DAYS; $offset++) {
            $date = $this->now->modify('+'.$offset.' day')->format('Y-m-d');
            $states = $offset === 0 ? ['delivered', 'loaded', 'failed', 'issue'] : ['loaded', 'issue'];
            $this->seedDay($date, $vehicleId, $storeId, $choferUserId, $states);
        }

        // Transportista tercero + liquidación del período (una vez; el período es mensual).
        $this->ensureCarrierSettlement();

        $this->summary($vehicleId, $storeId);
    }

    // ---------------------------------------------------------------------------------------------
    // Orquestación por día
    // ---------------------------------------------------------------------------------------------

    /**
     * Arma, para una fecha, los envíos de variedad y sus estados por rol.
     *
     * @param list<string> $deliveryStates estados de entrega a sembrar (delivered|loaded|failed|issue)
     */
    private function seedDay(string $date, string $vehicleId, string $storeId, string $choferUserId, array $deliveryStates): void
    {
        $tag = (new DateTimeImmutable($date, $this->tz))->format('ymd');

        // 1) Entregas en estados variados para el chofer.
        foreach ($deliveryStates as $state) {
            $order = $this->deliveryStateOrder($tag, $state);
            $shipment = $this->ensureShipment($order, null);
            $this->ensureLabels($shipment, 'C');
            $this->markPlanned($shipment);
            $this->driveDelivery($shipment, $vehicleId, $choferUserId, $date, $state);
            $this->openTracking($shipment);
        }

        // 2) Cola de preparación: 3 envíos recibidos listos para preparar (con bultos y etiquetas).
        $prepShipments = [];
        foreach ($this->prepQueueOrders($tag) as $order) {
            $shipment = $this->ensureShipment($order, null);
            $this->ensureLabels($shipment, 'C');
            $prepShipments[] = $shipment;
        }

        // 3) Transportista: un envío de última milla (destino tienda) + seguimiento público.
        $lastMile = $this->ensureShipment($this->lastMileOrder($tag, $storeId), $storeId);
        $this->ensureLabels($lastMile, self::STORE_CODE);
        $this->openTracking($lastMile);
        // Tokens públicos para 2–3 envíos del día: el de última milla + dos de la cola de preparación.
        foreach (array_slice($prepShipments, 0, 2) as $shipment) {
            $this->openTracking($shipment);
        }

        // 4) Notificaciones de ejemplo por el outbox (sin pegar a FCM).
        $this->queueNotificationOnce($lastMile, 'EN_ROUTE');
        if (isset($prepShipments[0])) {
            $this->queueNotificationOnce($prepShipments[0], 'IN_PREPARATION');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 1) Entregas (vía casos de uso de execution)
    // ---------------------------------------------------------------------------------------------

    /**
     * Conduce la entrega de un envío por los comandos de execution hasta el estado deseado. El handler de
     * preparación abre la entrega sobre el vehículo del chofer; luego se carga y, según el caso, se cierra
     * con POD (foto), se marca fallida (motivo) o se reporta un inconveniente sin cerrar. Idempotente: si
     * ya hay entrega para el envío, no se vuelve a conducir (y cada comando trae su clave de idempotencia).
     */
    private function driveDelivery(Shipment $shipment, string $vehicleId, string $choferUserId, string $date, string $state): void
    {
        $shipmentId = $shipment->id()->toString();
        if ($this->deliveries->findByShipmentId($shipmentId) !== null) {
            return;
        }

        $units = $this->unitIds($shipmentId);
        if ($units === []) {
            // Sin bultos no hay qué escanear (p. ej. si el envío no persistió unidades); se omite.
            return;
        }

        $point = $shipment->address()->geoPoint;
        $lat = $point->lat ?? -17.78;
        $lng = $point->lng ?? -63.18;
        $cdLat = -17.7490;
        $cdLng = -63.1540;
        $key = "variety-{$date}-{$shipmentId}";

        // Línea de tiempo: hoy en el pasado (ya en reparto); mañana en la mañana del día planificado.
        $base = $date === $this->today
            ? $this->now->modify('-3 hours')
            : new DateTimeImmutable($date.' 07:00:00', $this->tz);
        $prepAt = $base;
        $loadAt = $base->modify('+40 minutes');
        $outcomeAt = $base->modify('+90 minutes');

        // Preparación en el CD (abre la entrega) y carga al camión.
        foreach ($units as $u => $unitId) {
            $this->bus->dispatch(new RecordPreparationScan(
                $shipmentId, $vehicleId, $unitId, $cdLat, $cdLng,
                self::DEVICE_ID, $choferUserId,
                $prepAt->modify("+{$u} minutes")->format(DateTimeInterface::ATOM),
                "{$key}-prep-{$u}",
            ));
        }
        foreach ($units as $u => $unitId) {
            $this->bus->dispatch(new RecordLoadScan(
                $shipmentId, $unitId, $cdLat, $cdLng,
                self::DEVICE_ID, $choferUserId,
                $loadAt->modify("+{$u} minutes")->format(DateTimeInterface::ATOM),
                "{$key}-load-{$u}",
            ));
        }

        // Desenlace según el estado buscado. 'loaded' no tiene comando: la entrega queda cargada y
        // lista para que el chofer registre el POD en la app.
        $outcome = match ($state) {
            // Cerrada con POD y FOTO (la pantalla de POD exige foto al chofer).
            'delivered' => new RecordProofOfDelivery(
                $shipmentId,
                $units[0],
                'FIRMA-'.strtoupper(substr(md5($shipmentId), 0, 8)).' '.$shipment->customerName(),
                'https://picsum.photos/seed/pod-'.substr(md5($shipmentId), 0, 8).'/400/300',
                null,
                $lat, $lng,
                self::DEVICE_ID, $choferUserId,
                $outcomeAt->format(DateTimeInterface::ATOM),
                "{$key}-pod",
            ),
            // No se pudo entregar: motivo cliente ausente.
            'failed' => new RecordFailedDelivery(
                $shipmentId,
                $units[0],
                'CUSTOMER_ABSENT',
                $lat, $lng,
                self::DEVICE_ID, $choferUserId,
                $outcomeAt->format(DateTimeInterface::ATOM),
                "{$key}-failed",
            ),
            // Inconveniente reportado SIN cerrar la entrega (queda EN CURSO/cargada).
            'issue' => new ReportDeliveryIssue(
                $shipmentId,
                $date === $this->today ? 'DELAY' : 'ACCESS',
                $date === $this->today
                    ? 'Tráfico pesado sobre la avenida; la entrega se retrasa ~30 min.'
                    : 'Portón del condominio cerrado; cliente no responde el timbre.',
                $lat, $lng,
                null,
                self::DEVICE_ID, $choferUserId,
                $outcomeAt->format(DateTimeInterface::ATOM),
                "{$key}-issue",
            ),
            // 'loaded': se deja cargada y lista para POD (sin cerrar).
            default => null,
        };

        if ($outcome !== null) {
            $this->bus->dispatch($outcome);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 3) Transportista y liquidación
    // ---------------------------------------------------------------------------------------------

    /**
     * Garantiza un transportista tercero de prueba y su liquidación del período en curso para el corredor
     * de última milla CD→tienda. Idempotente por tax_id (transportista) y por (transportista, período,
     * corredor) (liquidación). Usa las fábricas del contexto, que es la vía de demo estándar y no depende
     * del worker del outbox (igual que CarriersDemoSeeder).
     */
    private function ensureCarrierSettlement(): void
    {
        $carrier = CarrierRecord::query()->where('tax_id', self::CARRIER_TAX_ID)->first()
            ?? CarrierFactory::new()
                ->state(['name' => self::CARRIER_NAME, 'tax_id' => self::CARRIER_TAX_ID])
                ->rated(4.3, 9)
                ->withDocuments([['type' => 'insurance_policy', 'reference' => 'POL-VAR-2026-01']])
                ->createOne();

        $carrierId = (string) $carrier->getKey();
        $period = $this->now->format('Y-m');
        $from = 'cd-santa-cruz';
        $to = 'store-scz-centro';

        $exists = SettlementRecord::query()
            ->where('carrier_id', $carrierId)
            ->where('period', $period)
            ->where('corridor_from', $from)
            ->where('corridor_to', $to)
            ->exists();

        if (!$exists) {
            // Liquidación enviada (estado inicial) del mes en curso: queda pendiente de aprobación/pago.
            SettlementFactory::new()
                ->forCarrier($carrierId)
                ->forCorridorState($from, $to, 'cd_to_store', $period, 180_000)
                ->create();
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 4) Notificaciones
    // ---------------------------------------------------------------------------------------------

    /**
     * Encola una notificación de un tipo para un envío si todavía no hay una de ese tipo (QueueNotification
     * no es idempotente; se guarda por (envío, tipo) para no duplicar al re-correr).
     */
    private function queueNotificationOnce(Shipment $shipment, string $messageType): void
    {
        $shipmentId = $shipment->id()->toString();
        $already = NotificationRecord::query()
            ->where('shipment_id', $shipmentId)
            ->where('message_type', $messageType)
            ->exists();
        if ($already) {
            return;
        }

        $this->bus->dispatch(new QueueNotification(
            $shipmentId,
            $messageType,
            $shipment->customer()->email,
            $shipment->customer()->phone,
        ));
    }

    // ---------------------------------------------------------------------------------------------
    // Envíos, etiquetas, seguimiento (vía dominio / casos de uso)
    // ---------------------------------------------------------------------------------------------

    /**
     * Recibe el envío por el dominio (con bultos) y franja del día. Idempotente por (fuente, id externo).
     *
     * @param array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, date: string, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>} $order
     */
    private function ensureShipment(array $order, ?string $destinationNodeId): Shipment
    {
        $source = OrderSource::from($order['source']);
        $reference = SourceReference::of($source, $order['external_id']);

        $existing = $this->shipments->findBySourceReference($reference);
        if ($existing !== null) {
            return $existing;
        }

        $address = Address::of(
            $order['address_text'],
            $order['address_reference'],
            ShipmentGeoPoint::of($order['lat'], $order['lng']),
        );

        $lines = [];
        foreach ($order['lines'] as $line) {
            $lines[] = ShipmentLine::create(
                ShipmentLineId::generate(),
                Sku::of($line['sku']),
                ProductName::of($line['product_name']),
                $line['quantity'],
                ProductImageUrl::fromNullable($line['image_url']),
                Money::fromNullable($line['price']),
            );
        }

        $shipment = Shipment::receive(
            $this->shipments->nextIdentity(),
            $source,
            $reference,
            Customer::of($order['customer_name'], $order['customer_document_id'], $order['customer_phone'], $order['customer_email']),
            $address,
            $this->scorer->score($address),
            $lines,
            $destinationNodeId,
        );
        $shipment->assignDeliverySlot(DeliverySlot::of($order['date'], $order['window'][0], $order['window'][1]));

        $this->shipments->save($shipment);

        return $shipment;
    }

    /**
     * Emite las etiquetas QR firmadas (una por bulto) con el caso de uso real. Idempotente: si el pedido
     * ya tiene etiquetas vigentes, no reemite. Degrada sin error si falta la llave de firma.
     */
    private function ensureLabels(Shipment $shipment, string $destination): void
    {
        $orderNumber = $shipment->sourceReference()->externalId;
        if (EmittedLabelRecord::query()->where('order_id', $orderNumber)->whereNull('revoked_at')->exists()) {
            return;
        }

        $lines = [];
        foreach ($shipment->lines() as $line) {
            $lines[] = new OrderLineInput($line->sku()->value, $line->productName()->value, $line->quantity());
        }

        try {
            $this->bus->dispatch(new EmitShipmentLabels($orderNumber, self::LABEL_ORIGIN, $destination, $lines));
        } catch (RuntimeException $exception) {
            $this->say('warn', "Etiquetas de {$orderNumber} no emitidas: {$exception->getMessage()}");
        }
    }

    /**
     * Abre el seguimiento del envío (genera el token público). Idempotente por envío en el handler.
     */
    private function openTracking(Shipment $shipment): void
    {
        $shipmentId = $shipment->id()->toString();
        $this->bus->dispatch(new OpenTracking($shipmentId));
        if (!in_array($shipmentId, $this->openedTrackingShipments, true)) {
            $this->openedTrackingShipments[] = $shipmentId;
        }
    }

    /**
     * Pasa el envío a PLANNED si sigue en RECEIVED (así no queda estancado como recién recibido). Guarda la
     * transición: solo aplica desde RECEIVED.
     */
    private function markPlanned(Shipment $shipment): void
    {
        $fresh = $this->shipments->findById($shipment->id());
        if ($fresh !== null && $fresh->status() === ShipmentStatus::Received) {
            $fresh->markPlanned();
            $this->shipments->save($fresh);
        }
    }

    /**
     * Ids de los bultos físicos del envío (shipment_units), en orden estable.
     *
     * @return list<string>
     */
    private function unitIds(string $shipmentId): array
    {
        return array_values(
            PhysicalUnitRecord::query()
                ->where('shipment_id', $shipmentId)
                ->orderBy('line_id')
                ->orderBy('unit_index')
                ->orderBy('piece_index')
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Resolución de vehículo/usuario del chofer (por código/usuario, sin inventar)
    // ---------------------------------------------------------------------------------------------

    /**
     * Vehículo del chofer: el de su tripulación de HOY (lo que realmente maneja); si no hay tripulación
     * todavía, el camión del escenario por placa. No crea vehículos nuevos.
     */
    private function resolveChoferVehicleId(): string
    {
        $driverId = User::query()->where('email', 'chofer@dismac.test')->value('driver_id');

        $vehicleId = null;
        if ($driverId !== null) {
            $vehicleId = CrewModel::query()
                ->where('driver_id', (string) $driverId)
                ->whereDate('assembled_for', $this->today)
                ->value('vehicle_id');
        }

        $vehicleId ??= VehicleModel::query()->where('plate', self::ROUTE_VEHICLE_PLATE)->value('id');

        if ($vehicleId === null) {
            throw new TodayScenarioSeederException('No se pudo resolver el vehículo del chofer (correr FleetSeeder/TodayScenarioSeeder).');
        }

        return (string) $vehicleId;
    }

    /**
     * Id del usuario chofer (quien firma los escaneos). Si no existe aún, degrada a un id de dispositivo.
     */
    private function resolveChoferUserId(): string
    {
        $id = User::query()->where('email', 'chofer@dismac.test')->value('id');

        return $id !== null ? (string) $id : 'variety-chofer';
    }

    // ---------------------------------------------------------------------------------------------
    // Datos (clientes completos: teléfono, referencia, coordenadas reales, líneas con imagen)
    // ---------------------------------------------------------------------------------------------

    /**
     * Pedido para un estado de entrega concreto del chofer, en un barrio real de Santa Cruz.
     *
     * @return array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, date: string, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>}
     */
    private function deliveryStateOrder(string $tag, string $state): array
    {
        $date = $this->dateForTag($tag);

        return match ($state) {
            'delivered' => $this->order('SAP', "VAR-{$tag}-DEL", 'Fernando Áñez Cronenbold', '7001234 SC', '+591 77711223', 'fernando.anez@gmail.com',
                'Av. Alemana 3er anillo interno N° 1200, Barrio Sirari, Santa Cruz de la Sierra',
                'Casa de dos pisos con portón negro, timbre a la derecha', -17.7721, -63.1879, $date, ['08:00', '12:00'],
                [['TV-LG-OLED55', 'Televisor LG OLED 55" 4K', 1, 8990.00], ['SND-LG-SP7', 'Barra de sonido LG SP7', 1, 1690.00]]),
            'loaded' => $this->order('MAGENTO', "VAR-{$tag}-POD", 'Natalia Egüez Suárez', '6102345 SC', '+591 71122334', 'natalia.eguez@hotmail.com',
                'Calle Las Palmeras 88, Barrio Las Palmas, Santa Cruz de la Sierra',
                'Edificio Palmeras, depto 2C, llamar al llegar', -17.8012, -63.2098, $date, ['09:00', '13:00'],
                [['REF-SAMS-500', 'Refrigerador Samsung 500 L French Door', 1, 7490.00]]),
            'failed' => $this->order('SAP', "VAR-{$tag}-FAIL", 'Óscar Tapia Mendoza', '5203456 SC', '+591 76655443', null,
                'Av. Virgen de Cotoca 5to anillo, Barrio Pampa de la Isla, Santa Cruz de la Sierra',
                'Casa color verde al lado de la cancha de futsal', -17.7859, -63.1402, $date, ['10:00', '15:00'],
                [['LAV-SAMS-13', 'Lavadora Samsung 13 kg', 1, 5290.00]]),
            'issue' => $this->order('MAGENTO', "VAR-{$tag}-ISS", 'Patricia Vargas Ribera', '6304567 SC', '+591 70099887', 'patricia.vargas@gmail.com',
                'Condominio Los Lirios, Av. Beni 4to anillo, Santa Cruz de la Sierra',
                'Condominio cerrado, casa 22 (avisar en garita, portón automático)', -17.7555, -63.1803, $date, ['11:00', '16:00'],
                [['SEC-WHI-11', 'Secadora Whirlpool 11 kg', 1, 4190.00], ['PLN-PHI-5000', 'Plancha a vapor Philips 5000', 1, 320.00]]),
            default => throw new TodayScenarioSeederException("Estado de entrega desconocido: {$state}"),
        };
    }

    /**
     * Cola de preparación del día: envíos recibidos (con bultos y etiquetas) listos para que el preparador
     * los escanee. Clientes completos, barrios reales de Santa Cruz.
     *
     * @return list<array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, date: string, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>}>
     */
    private function prepQueueOrders(string $tag): array
    {
        $date = $this->dateForTag($tag);

        return [
            $this->order('SAP', "VAR-{$tag}-P1", 'Ignacio Roca Serrate', '5405678 SC', '+591 77788990', 'ignacio.roca@gmail.com',
                'Av. Piraí 2do anillo N° 345, Barrio El Trompillo, Santa Cruz de la Sierra',
                'Frente a la plaza, casa de reja verde', -17.7905, -63.1865, $date, ['09:00', '14:00'],
                [['MIC-LG-MH', 'Microondas LG 30 L', 1, 1090.00], ['BAT-TRA-10', 'Batería de cocina Tramontina 10 pz', 1, 890.00]]),
            $this->order('MAGENTO', "VAR-{$tag}-P2", 'Lorena Áñez Justiniano', '6506789 SC', '+591 71234890', 'lorena.anez@hotmail.com',
                'Calle Los Mangos 150, Barrio Hamacas, Santa Cruz de la Sierra',
                'Edificio Hamacas, piso 4, dejar en portería si no está', -17.7568, -63.1702, $date, ['10:00', '15:00'],
                [['CEL-XIA-14', 'Celular Xiaomi Redmi Note 14 256 GB', 1, 2290.00]]),
            $this->order('EXCEL', "VAR-{$tag}-P3", 'Hernán Suárez Áñez', '5607890 SC', '+591 76543210', null,
                'Av. Mutualista 4to anillo, Barrio Los Cusis, Santa Cruz de la Sierra',
                'Tienda de barrio "El Ahorro", preguntar por Hernán', -17.7692, -63.2037, $date, ['11:00', '16:00'],
                [['VEN-SAM-16', 'Ventilador de techo 16"', 1, 490.00], ['OLL-OST-6', 'Olla arrocera Oster 6 tazas', 1, 420.00]]),
        ];
    }

    /**
     * Envío de última milla con destino a la tienda (lo mueve/entrega el transportista tercero).
     *
     * @return array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, date: string, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>}
     */
    private function lastMileOrder(string $tag, string $storeNodeId): array
    {
        $date = $this->dateForTag($tag);
        $store = NodeRecord::query()->where('id', $storeNodeId)->first();
        $lat = $store !== null ? (float) $store->lat : -17.7310;
        $lng = $store !== null ? (float) $store->lng : -63.1710;

        return $this->order('MAGENTO', "VAR-{$tag}-LM", 'Mariana Céspedes Durán', '6708901 SC', '+591 70011224', 'mariana.cespedes@gmail.com',
            'Última milla tercerizada — entrega en Tienda Santa Cruz Norte, Av. Banzer 3er anillo, Santa Cruz de la Sierra',
            'El transportista deja en tienda; retira el titular con CI', $lat, $lng, $date, ['12:00', '19:00'],
            [['AUR-APP-AIR', 'Auriculares Apple AirPods (3.ª gen.)', 1, 1590.00], ['CAR-ANK-65', 'Cargador Anker 65W', 1, 290.00]]);
    }

    /**
     * Arma el array de pedido normalizando las líneas (imagen por SKU).
     *
     * @param  array{0: string, 1: string}                                                                                                                                                                                                                                                                                                                                                           $window
     * @param  list<array{0: string, 1: string, 2: int, 3: float}>                                                                                                                                                                                                                                                                                                                                   $lines
     * @return array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, date: string, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>}
     */
    private function order(
        string $source,
        string $externalId,
        string $customer,
        ?string $document,
        string $phone,
        ?string $email,
        string $address,
        ?string $reference,
        float $lat,
        float $lng,
        string $date,
        array $window,
        array $lines,
    ): array {
        $mapped = [];
        foreach ($lines as [$sku, $name, $quantity, $price]) {
            $mapped[] = [
                'sku' => $sku,
                'product_name' => $name,
                'quantity' => $quantity,
                'image_url' => 'https://picsum.photos/seed/'.strtolower($sku).'/200/200',
                'price' => $price,
            ];
        }

        return [
            'source' => $source,
            'external_id' => $externalId,
            'customer_name' => $customer,
            'customer_document_id' => $document,
            'customer_phone' => $phone,
            'customer_email' => $email,
            'address_text' => $address,
            'address_reference' => $reference,
            'lat' => $lat,
            'lng' => $lng,
            'date' => $date,
            'window' => $window,
            'lines' => $mapped,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------------------------------

    /** Reconstruye la fecha Y-m-d a partir del tag ymd (cualquier día de la ventana de dos semanas). */
    private function dateForTag(string $tag): string
    {
        $date = DateTimeImmutable::createFromFormat('ymd', $tag, $this->tz);

        return $date !== false ? $date->format('Y-m-d') : $this->today;
    }

    private function node(string $code): NodeRecord
    {
        $node = NodeRecord::query()->where('code', $code)->first();
        if ($node === null) {
            throw new TodayScenarioSeederException("Falta el nodo {$code} (correr NetworkSeeder).");
        }

        return $node;
    }

    private function say(string $level, string $message): void
    {
        if ($this->command === null) {
            return;
        }

        $level === 'warn' ? $this->command->warn($message) : $this->command->info($message);
    }

    private function summary(string $vehicleId, string $storeId): void
    {
        $this->say('info', "Variedad de prueba lista (hoy {$this->today}, mañana {$this->tomorrow}, Santa Cruz).");
        $this->say('info', sprintf('  vehículo del chofer %s | tienda %s (%s)', $vehicleId, self::STORE_CODE, $storeId));
        $this->say('info', '  entregas HOY: ENTREGADA+foto, EN CURSO (lista POD), FALLIDA (cliente ausente), INCONVENIENTE (DELAY).');
        $this->say('info', '  entregas MAÑANA: EN CURSO (lista POD), INCONVENIENTE (ACCESS).');
        $this->say('info', '  cola de preparación: 3 envíos recibidos por día con bultos y etiquetas.');
        $this->say('info', sprintf('  transportista: %s (%s) con liquidación del período %s (corredor cd_to_store).', self::CARRIER_NAME, self::CARRIER_TAX_ID, $this->now->format('Y-m')));

        foreach ($this->openedTrackingShipments as $shipmentId) {
            $token = TrackingRecord::query()->where('shipment_id', $shipmentId)->value('token');
            $this->say('info', sprintf('  tracking público envío %s token %s', $shipmentId, $token !== null ? (string) $token : '-'));
        }

        $queued = NotificationRecord::query()->whereIn('message_type', ['EN_ROUTE', 'IN_PREPARATION'])->count();
        $this->say('info', "  notificaciones encoladas (EN_ROUTE/IN_PREPARATION): {$queued}");
    }
}
