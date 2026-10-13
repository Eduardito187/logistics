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
  umbrales), `Sku`, `ProductName`, `Fraction`, `ShipmentStatus`, IDs tipados. Eventos
  `ShipmentReceived`/`ShipmentPlanned`/`ShipmentAddressRescored`. Contratos `ShipmentRepository` y
  `AddressConfidenceScorer`.
- **Application**: `ReceiveShipment` (API única de ingreso, idempotente por referencia de origen),
  `GeocodeShipmentAddress`, consultas `FindShipment`/`ListShipments`/`ListLowConfidenceAddresses` y
  read models `ShipmentView`/`ShipmentLineView`.
- **Infrastructure**: `EloquentShipmentRepository` + mapper, records `shipments`/`shipment_lines`/
  `shipment_units`, `NullAddressConfidenceScorer` (confianza media fija; la integración de
  geocodificación real llega después) y el `ShipmentsServiceProvider` que cablea todo.
- **Presentation**: comando `logistics:shipments:receive-manual` para ingesta manual. Sin Filament ni
  endpoints HTTP todavía.

Pendiente: integración con el outbox (hoy los handlers liberan los eventos sin despacharlos),
geocodificador real y verificación contra los containers (tests + PHPStan nivel 8 + Pint).
