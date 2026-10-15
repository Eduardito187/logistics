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
base lo respalda el índice único en `execution_scan_events.idempotency_key` y en
`execution_node_reception_units.idempotency_key`.

### Contrato de sincronización offline (cola de la app)

La app Flutter trabaja offline y mantiene una **cola local de escaneos** que vacía contra estos
endpoints cuando recupera red. Reglas del contrato:

- **Clave de idempotencia obligatoria** en toda escritura: la app la genera una vez por evento (UUID)
  y la manda en el header `Idempotency-Key` (preferido) o en el cuerpo `idempotency_key`. La validación
  la exige por una de las dos vías (`ScanRequest`): una escritura sin clave, o con clave en blanco, se
  rechaza con **422**. Nunca se persiste una clave vacía (colisionaría en el índice único).
- **Reenvío seguro**: al reintentar un escaneo ya aplicado (misma clave), el agregado lo descarta y el
  endpoint devuelve el **estado actual** de la entrega/recepción (misma forma que la primera vez), para
  que la app reconcilie su vista local en una sola llamada. No hay doble efecto ni evento duplicado.
- **Endpoints de escritura cubiertos** (todos idempotentes por clave): preparación
  (`deliveries/scans/preparation`), carga (`…/load`), descarga (`…/unload`), recepción en nodo
  (`…/node-reception`), prueba de entrega (`deliveries/proof-of-delivery`), fallida (`deliveries/failed`)
  y recepción conciliada en nodos (`node-receptions/units`). Abrir/conciliar una recepción
  (`node-receptions`, `node-receptions/reconcile`) es idempotente por naturaleza (por el par
  envío+destino y por el estado), sin clave.
- **Orden y reloj**: `occurred_at` lo fija la app en terreno (no el servidor); el servidor acepta el
  momento del escaneo tal cual para reconstruir el recorrido real aunque la sincronización llegue tarde.

### Evidencia (POD y daños) a S3

La foto/firma de la prueba de entrega y la de daños se suben con `POST deliveries/photos`
(multipart), detrás de la puerta `EvidenceStorage` (Application → adapter `FilesystemEvidenceStorage`):

- El campo `kind` elige el disco S3-compatible: `pod` (prueba de entrega, privado) o `damage`
  (daños/reclamos, privado). Sin `kind`, se asume `pod`. Los discos los define `config/filesystems.php`.
- La subida **reintenta** ante fallos transitorios de red (configurable,
  `logistics-execution.evidence_upload_attempts`); si agota los intentos devuelve **500** y la app
  reencola.
- Devuelve la **referencia durable** `{ kind, disk, path, url }`. El `path` (relativo al disco) es la
  referencia canónica; la `url` puede ser `null` en discos privados.
- Esa referencia viaja luego como `photo_path` en `deliveries/proof-of-delivery` y queda guardada en la
  prueba de entrega (`ProofOfDelivery::photoPath`, columna `execution_deliveries.pod_photo_path`).

### Verificación offline de la etiqueta firmada

`POST labels/verify` es la **contraverificación del servidor** de la firma Ed25519 de una etiqueta QR
(la app también verifica offline con el bundle de claves públicas). Detrás del contrato
`LabelVerificationGateway`: si hay claves públicas cargadas en config
(`logistics-execution.label_public_keys`, mapa `id => base64`) se usa el verificador REAL
(`SodiumLabelVerificationGateway`, `sodium_crypto_sign_verify_detached`); si no, cae al stub. El
resultado es un hecho de negocio (`valid` / `invalid_signature` / `unknown_key`), nunca un error HTTP.

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
