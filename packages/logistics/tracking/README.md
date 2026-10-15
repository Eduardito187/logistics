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
- Agregado `UnitTracking` (id `UnitTrackingId`, `shipmentId` string, `unitId` string, `UnitStatus`,
  `?lastLocationId` string, `?lastSeenAt`): proyección PER-BULTO del flujo de eventos de execution. Un
  seguimiento por bulto (unit_id único). Se autocompleta desde el stream: la primera vez que se ve un
  bulto (su preparación) se abre su seguimiento. Es monótono por `lastSeenAt` (descarta reportes fuera
  de orden) y no retrocede desde un estado terminal. `UnitStatus`: Registered, Prepared, Loaded,
  Unloaded, Received, Delivered, Failed.

### Posición en vivo del camión (GPS)

`ShipmentTracking::updatePosition()` es MONÓTONO: descarta un reporte más viejo que el último conocido
(buffer offline del dispositivo, reintentos del relay, ingesta ~3 req/s). `TruckPosition::isLiveAt()`
decide, contra el Clock de la app, si la posición es "en vivo" (ventana de 5 min) o solo la última
conocida; los read models exponen `position_is_live` (bool|null).
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

### Encuesta de satisfacción post-entrega (K-06)

Cuando el envío está **ENTREGADO**, el cliente puede calificar la entrega desde la página pública (1-5
estrellas + comentario opcional + motivo opcional si la nota es baja).

- Agregado `SatisfactionSurvey` (id `SurveyId`, `shipmentId` string, `token`/scope, VO `Rating` 1-5,
  `?comment`, `?SurveyReason`, `submittedAt`). Graba el evento `tracking.satisfaction_survey_recorded`.
- **Idempotencia (decisión):** UNA respuesta por envío. Un segundo intento se **RECHAZA** (409), no se
  reemplaza la original; lo respalda el índice único por `shipment_id` y lo verifica el handler.
- El comando `RecordSatisfactionSurvey` corre por el `CommandBus` (transaccional) y libera el evento al
  `logistics/outbox`. El NPS se adapta a la escala 1-5 (promotor = 5, pasivo = 4, detractor = 1-3).
- Admin (solo lectura): `SatisfactionSurveyResource` lista las respuestas (estrellas, motivo, comentario,
  envío) y la cabecera muestra `SatisfactionStatsWidget` (NPS, promedio, volumen). Texto en inglés.

### Web Push en la página pública (K-04)

Botón "Notificarme" que suscribe el navegador a Web Push estándar (`PushManager.subscribe` con la clave
pública VAPID expuesta por config). Degrada con gracia: el botón solo aparece si el navegador soporta
Service Worker + Push **y** hay clave VAPID configurada (`logistics-notifications.web_push.vapid_public_key`).

- Agregado `TrackingPushSubscription` + VO `WebPushSubscription` (endpoint https + claves p256dh/auth).
  Comando `RegisterPushSubscription` por el `CommandBus`; **idempotente por endpoint** (upsert). No hace
  I/O externo ni emite eventos: solo persiste la suscripción.
- **El envío real de web push REUSA el `WebPushChannelSender`/VAPID existente** (ADR 014, ntfy): lo hace
  el relay del outbox a partir de los eventos del envío; este contexto NO reimplementa el envío.
- Service worker servido en `/t/sw.js` (scope `/t/`): recibe el evento `push` y muestra la notificación.

## Aplicación (CQRS)

- Commands: `OpenTracking`, `RecordSatisfactionSurvey`, `RegisterPushSubscription`, `ProjectExecutionEvent` (puente de proyección de eventos de ejecución; avanza
  la cascada de paradas), `ProjectUnitExecutionEvent` (puente per-bulto: proyecta preparado/cargado/
  descargado/recepción y, sin unit_id, aplica el desenlace del envío en cascada a sus bultos),
  `PlaceShipmentOnRoute` (puente que recibe la secuencia de la ruta publicada), `UpdateTruckPosition`.
- Queries: `GetPublicTracking` (por token → `PublicTrackingView`, **sin PII**),
  `GetShipmentTracking` (por id de envío → `ShipmentTrackingView`, interno),
  `GetShipmentUnits` (por id de envío → `list<UnitTrackingView>`, interno, con etiqueta "N/M"),
  `GetPublicUnits` (por token → `list<PublicUnitView>`, **sin PII**, etiqueta en español).

## HTTP y página pública

- Puerta **channels**: `GET /api/v1/channels/tracking/{token}` (vista pública del envío) y
  `GET /api/v1/channels/tracking/{token}/units` (desglose de bultos, sin PII).
- Puerta **app**: `GET /api/v1/app/tracking/shipments/{shipmentId}` y `.../units`;
  `POST .../position` (ingesta GPS del chofer).
- Puerta **integrations**: `POST /api/v1/integrations/tracking/shipments/{shipmentId}/position`.
- Página web pública (sin auth): `GET /t/{token}` → Blade `tracking::public` con estado, mensaje
  "a N paradas", ETA, mapa Leaflet + OpenStreetMap con la posición del camión y el desglose de bultos.
  Token inexistente → 404 `tracking::not-found`, sin revelar si el token existe.
- Escrituras públicas por token (fuera de las puertas `/api/v1`, con `throttle` por IP, sin sesión ni
  CSRF; la credencial es el token): `POST /t/{token}/survey` (encuesta K-06) y
  `POST /t/{token}/push-subscriptions` (suscripción web push K-04); `GET /t/sw.js` (service worker).
  No van a `openapi/v1.yaml`: son parte de la página pública, igual que `GET /t/{token}` (que tampoco
  está en el contrato de API). Códigos: 201 ok, 404 token inválido/inexistente (genérico), 422 no
  elegible/validación, 409 ya calificado.

## Integración (pendiente)

Los handlers de escritura liberan los eventos del agregado con `releaseEvents()` y hoy quedan marcados
con `// TODO(outbox)`: al integrar los contextos, esos eventos se despacharán al `logistics/outbox`
dentro de la misma transacción (ADR 002), y `ProjectExecutionEvent` se suscribirá a los eventos de
`execution`. No hay HTTP ni página pública real todavía: solo dominio y aplicación.

## Estado

Dominio, aplicación y presentación implementados: read model por envío y por bulto, ingesta/exposición
de la posición en vivo del camión, página pública por token y endpoints en las tres puertas. Pendiente
solo el cableado del relay (suscribir `ProjectExecutionEvent` / `ProjectUnitExecutionEvent` a los
eventos de execution vía outbox), hoy marcado donde corresponde.
