# logistics/shipments — Recepción de envíos

## Responsabilidad

API única de ingreso de pedidos para entregar (SAP, Magento, tiendas, carga manual o Excel; dis-commerce más adelante). Normaliza cada pedido al modelo propio: envío, líneas y unidades físicas. Geocodifica y puntúa la confianza de la dirección.

## No es responsable de

Validar stock (decisión de negocio: solo se reciben pedidos para entregar). Hablar con SAP directamente: eso es del conector.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Dominio, aplicación (CQRS) e infraestructura (Eloquent) implementados.

- **Domain**: agregado `Shipment` (raíz) con entidades `ShipmentLine` y `PhysicalUnit` (un bulto por
  unidad de producto, "N de M" de unidad y de pieza). Value objects: `OrderSource`, `SourceReference`
  (clave de idempotencia), `Address`/`GeoPoint`, `AddressConfidence` (+ `AddressConfidenceLevel` por
  umbrales), `Sku`, `ProductName`, `Fraction`, `ShipmentStatus`, IDs tipados. La línea lleva además, de
  forma OPCIONAL, `ProductImageUrl` (imagen del producto) y `Money` (precio unitario, por defecto BOB):
  datos de presentación que consume el notificador para mostrar productos con miniatura en mail/push y
  precio en el detalle; su ausencia no rompe la ingesta existente (default null). Eventos
  `ShipmentReceived`/`ShipmentPlanned`/`ShipmentAddressRescored`. Contratos `ShipmentRepository` y
  `AddressConfidenceScorer`.
- **Application**: `ReceiveShipment` (API única de ingreso, idempotente por referencia de origen; la
  línea acepta `image_url`/`price` opcionales), `GeocodeShipmentAddress`, consultas
  `FindShipment`/`ListShipments`/`ListLowConfidenceAddresses` y `ListShipmentProducts` (lista por envío
  `[{sku, name, qty, image_url, price, currency}]` que el contexto `notifications` consume por el
  QueryBus para armar el mail y el collage del push). Read models
  `ShipmentView`/`ShipmentLineView`/`ShipmentProductView`.
- **Infrastructure**: `EloquentShipmentRepository` + mapper, records `shipments`/`shipment_lines`/
  `shipment_units`, `NullAddressConfidenceScorer` (confianza media fija; la integración de
  geocodificación real llega después) y el `ShipmentsServiceProvider` que cablea el contexto completo.
- **Presentation**: comando `logistics:shipments:receive-manual` para ingesta manual. Sin Filament ni
  endpoints HTTP todavía.

Pendiente: integración con el outbox (hoy los handlers liberan los eventos sin despacharlos),
geocodificador real y verificación contra los containers (tests + PHPStan nivel 8 + Pint).

## Avance del estado del envío (`shipments.status`)

El envío avanza de forma **monótona e idempotente** (nunca retrocede; un envío entregado, fallido o
anulado no se toca) por el reactor `ShipmentStatusProgressionDispatcher`, registrado en el outbox
(último en cada abanico, nunca lanza):

| Hecho                                   | Estado del envío |
|-----------------------------------------|------------------|
| `planning.plan_published` (sus paradas) | PLANNED          |
| `execution.driver_route_assembled`      | PLANNED          |
| `execution.route_stop_attached`         | PLANNED          |
| `execution.shipment_prepared` / `shipment_loaded` / `shipment_departed` | IN_EXECUTION |
| `execution.delivery_failed`             | IN_EXECUTION (como mínimo) |
| `execution.shipment_delivered`          | DELIVERED        |

PLANNED se marca al **publicar** el plan, no al proponerlo: el auto-planificador re-arma los planes
propuestos del día con los envíos RECEIVED, y marcarlos antes los dejaría fuera del plan rehecho.

El cambio pasa por el agregado (`AdvanceShipmentStatus` → `Shipment::progressTo`, con bloqueo de fila).
Para reparar envíos históricos atascados: `php artisan shipments:sync-status [--only=status|nodes] [--dry-run]`.

## Nodo destino (retiro en tienda / traspaso)

`destination_node_id` se resuelve al ingresar el envío, en cascada:

1. el `destination_node_id` explícito de la fuente (commerce multishipping, `POST /integrations/shipments`);
2. el UUID del sufijo `#n:{uuid}` del `external_id` (envío hermano de una orden que va a un nodo);
3. el `destination_node_code` (código de tienda/CD) traducido por `network_nodes.code`.

Sin ninguno, el envío es a domicilio (null) y la tienda no lo ve en «Recepción de mercadería» ni en
«Recojo en tienda». `shipments:sync-status --only=nodes` completa el nodo de las filas viejas con sufijo.
