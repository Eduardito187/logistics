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
  `remainingStops`, `?Eta`, `?TruckPosition`, `?routeId` string, `?RoutePosition`). Referencia al envío
  y a la ruta por id string, nunca importando el agregado de otro contexto.
- Value objects `final readonly`: `TrackingToken` (opaco, no adivinable, base64url de 32 bytes
  aleatorios), `VisibleStatus` (Registered, Prepared, OnTheWay, Arriving, Delivered, Failed), `Eta`,
  `GeoPoint`, `TruckPosition`, `RoutePosition` (posición 1-based en la ruta; `stopsBefore()` deriva las
  paradas antes de la del cliente).
- Eventos: `TrackingOpened`, `TrackingStatusChanged`, `ShipmentPlacedOnRoute`.

### Paradas restantes (stops_remaining) y posición en la ruta

`stops_remaining` = cuántas paradas atiende el camión ANTES de llegar a la del cliente; `route_position`
= posición del envío en la secuencia de la ruta publicada (1 es la primera parada).

- Al **publicarse la ruta** (planning), el puente `PlaceShipmentOnRoute` fija `route_id` + `route_position`
  y deriva `stops_remaining = route_position - 1`.
- Cada vez que una **entrega previa se resuelve** (evento terminal de `execution`: entregada/fallida),
  `ProjectExecutionEvent` baja en cascada `stops_remaining` de los envíos de atrás en la misma ruta
  (una sola vez por parada, idempotente ante reintentos del relay).
- La página pública y la puerta `channels` exponen `remaining_stops`, `route_position` y un mensaje al
  cliente en español (`stops_message`, p. ej. "Tu pedido está a 3 paradas.").

## Aplicación (CQRS)

- Commands: `OpenTracking`, `ProjectExecutionEvent` (puente de proyección de eventos de ejecución; avanza
  la cascada de paradas), `PlaceShipmentOnRoute` (puente que recibe la secuencia de la ruta publicada),
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
