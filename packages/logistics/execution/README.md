# logistics/execution — Ejecución

## Responsabilidad

Preparación (escaneo de EAN → etiqueta → carril), carga validada, ruta del chofer, descarga guiada, prueba de entrega (QR del cliente, foto), entregas fallidas, recepción en nodos. Registra cada escaneo como evento inmutable.

## No es responsable de

Notificar al cliente: publica eventos que consume tracking/notifications.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Modelo de dominio

Agregado raíz `Delivery` (una entrega por envío, referencia a envío y vehículo por id string). Su
corazón es el **escaneo como hecho inmutable** (`ScanEvent`, append-only): hora, GPS (`GeoPoint`),
usuario y dispositivo (`DeviceContext`). La operación es una máquina de estados (`DeliveryStatus`):

```
Pending --preparación--> Prepared --carga--> Loaded --(recepción en nodo)--> InTransit
                                                   \--prueba de entrega--> Delivered
                                                    \--fallo (FailureReason)--> Failed
```

Métodos del agregado (cada uno agrega un `ScanEvent` y graba su evento de dominio):
`recordPreparationScan` (`ShipmentPrepared`), `recordLoadScan` (`ShipmentLoaded`),
`recordNodeReception` (`NodeReceptionRecorded`), `recordProofOfDelivery` (`ShipmentDelivered`,
con `ProofOfDelivery`: QR/firma + foto opcional), `recordFailed` (`DeliveryFailed`).

### Offline-first / idempotencia

Cada método es **idempotente por `idempotencyKey`** (la clave que genera el dispositivo por evento):
el reenvío del mismo escaneo —típico sin red— no duplica el hecho ni re-emite eventos. A nivel de
base lo respalda el índice único en `execution_scan_events.idempotency_key`.

## Casos de uso (CQRS)

- Commands: `RecordPreparationScan`, `RecordLoadScan`, `RecordProofOfDelivery`, `RecordFailedDelivery`,
  `RecordNodeReception` (todos idempotentes por `idempotencyKey`).
- Queries: `GetDeliveryState` (→ `?DeliveryView`), `ListDeliveriesByVehicle` (→ `list<DeliveryView>`).

Los handlers de escritura liberan los eventos del agregado con `releaseEvents()`; el despacho real al
outbox queda marcado con `TODO(outbox)` hasta integrar los contextos.

## Estado

Dominio, aplicación (CQRS) e infraestructura (Eloquent + migraciones) con cobertura de tests unitarios.
Pendiente: despacho vía outbox, capa de presentación (HTTP/Filament) y verificación real contra los
containers (Docker no disponible en esta iteración).
