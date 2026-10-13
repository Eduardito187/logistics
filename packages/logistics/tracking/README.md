# logistics/tracking — Tracking

## Responsabilidad

Estado visible de cada envío y bulto, paradas restantes, ETA, página pública por token y posición en vivo del camión.

Es principalmente un **read model / proyección**: se alimenta de los eventos de `execution` (vía el
comando puente `ProjectExecutionEvent`), pero el estado se modela como agregado propio
(`ShipmentTracking`) para poder evolucionar sus reglas de forma independiente.

## No es responsable de

Elegir canal de notificación. Tracking solo informa que el estado visible cambió (evento
`tracking.status_changed`); quién y cómo avisa al cliente lo decide otro contexto.

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Modelo de dominio

- Agregado `ShipmentTracking` (id `TrackingId`, `shipmentId` string, `TrackingToken`, `VisibleStatus`,
  `remainingStops`, `?Eta`, `?TruckPosition`). Referencia al envío por id string, nunca importando el
  agregado de otro contexto.
- Value objects `final readonly`: `TrackingToken` (opaco, no adivinable, base64url de 32 bytes
  aleatorios), `VisibleStatus` (Registered, Prepared, OnTheWay, Arriving, Delivered, Failed), `Eta`,
  `GeoPoint`, `TruckPosition`.
- Eventos: `TrackingOpened`, `TrackingStatusChanged`.

## Aplicación (CQRS)

- Commands: `OpenTracking`, `ProjectExecutionEvent` (puente de proyección de eventos de ejecución),
  `UpdateTruckPosition`.
- Queries: `GetPublicTracking` (por token → `PublicTrackingView`, **sin PII**),
  `GetShipmentTracking` (por id de envío → `ShipmentTrackingView`, interno).

## Integración (pendiente)

Los handlers de escritura liberan los eventos del agregado con `releaseEvents()` y hoy quedan marcados
con `// TODO(outbox)`: al integrar los contextos, esos eventos se despacharán al `logistics/outbox`
dentro de la misma transacción (ADR 002), y `ProjectExecutionEvent` se suscribirá a los eventos de
`execution`. No hay HTTP ni página pública real todavía: solo dominio y aplicación.

## Estado

Dominio y aplicación implementados. Sin HTTP/Presentation real todavía.
