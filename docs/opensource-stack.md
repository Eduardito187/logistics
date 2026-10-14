# Stack 100% open-source y gratuito

Regla del dueño del producto: **toda la plataforma corre sobre software open-source y gratuito. Cero
Google, cero servicios de pago, cero cuotas por llamada.** Donde un servicio externo era de pago o de
Google, se reemplaza por una alternativa libre y, en lo posible, auto-hospedada (self-host). Las
decisiones formales están en el ADR 013 (mapas/geocoder/ruteo), el ADR 014 (push) y el ADR 015
(notificaciones).

## Resumen por capa

| Capa | Antes (pago / Google) | Ahora (open-source, gratis) | Config / ADR |
| --- | --- | --- | --- |
| **Tiles de mapa** | Google Maps JS / tile.openstreetmap.org en prod | **OpenMapTiles + TileServer-GL** o **Protomaps** (`.pmtiles`) self-host | `MAP_TILES_URL` · ADR 013 |
| **Geocoder** | Google Geocoding | **Nominatim** o **Photon** self-host (datos OSM de Bolivia) | `GEOCODER_DRIVER`, `NOMINATIM_URL`/`PHOTON_URL` · ADR 013 |
| **Matriz / geometría de ruta** | Google Directions / Distance Matrix | **OSRM** self-host (`/table`, `/route`, `/trip`) | `OSRM_HOST` · ADR 009/013 |
| **Optimización VRP/VRPTW** | Google Route Optimization | **VRP propio** (Clarke-Wright + 2-opt) + **VROOM** self-host opcional | `PLANNING_ROUTING_BACKEND`, `VROOM_URL` · ADR 009 |
| **Cliente de mapa** | Google Maps JS | **Leaflet / MapLibre** + OSM | ADR 009/013 |
| **Push navegador** | Web push vía FCM (Firebase) | **Web Push estándar con VAPID** (sin Firebase) | `WEBPUSH_VAPID_*` · ADR 014 |
| **Push app interna** | FCM / Google Play Services | **ntfy + UnifiedPush** self-host | `NTFY_URL` · ADR 014 |
| **WhatsApp** | WhatsApp Business API (Meta/Twilio, de pago) | **Deshabilitado** (de pago) | ADR 015 |
| **Mensajería de cliente** | — | **Telegram** (bot propio, gratis) | `TELEGRAM_BOT_TOKEN` · ADR 015 |
| **SMS de respaldo** | Proveedor SaaS de pago | **Gateway open-source** (Kannel / Jasmin / gateway Android) con tope | `SMS_DRIVER`, `SMS_GATEWAY_URL` · ADR 015 |
| **Email** | — | **SMTP propio** (Mailpit en dev) | `MAIL_*` |
| **Base de datos** | — | **PostgreSQL + PostGIS** | ADR 003 |
| **Cache / colas / sesión** | — | **Valkey** (compatible Redis) | ADR 008 |
| **Almacenamiento de objetos** | S3 de AWS | **MinIO** (S3-compatible self-host) o R2/Spaces | `AWS_ENDPOINT` · ADR —/deploy.md |
| **Observabilidad de errores** | — | **Sentry self-host** (opcional, inerte sin DSN) | `SENTRY_LARAVEL_DSN` |

## Principios

1. **Detrás de contrato.** Todo servicio externo (geocoder, tiles, push, SMS, ruteo) vive detrás de un
   contrato en la capa Infrastructure, seleccionable por entorno. Sin el servicio configurado, el
   driver cae a un **stub/null** (degradación, nunca caída).
2. **Self-host primero.** Un solo extracto OSM de Bolivia alimenta tiles, geocoder y ruteo. Los
   servicios (TileServer/Protomaps, Nominatim/Photon, OSRM/VROOM, ntfy, MinIO) corren como contenedores
   propios; ver `docs/routing-selfhost.md` (OSRM/VROOM), `docs/maps-geocoder-selfhost.md` (Nominatim +
   teselas) y `docs/deploy.md`.
3. **Todo I/O externo por outbox.** Ninguna llamada a estos servicios es síncrona dentro de un request
   de usuario (ADR 008).
4. **Privacidad.** Las coordenadas de clientes y los datos de pedido no salen a terceros cerrados.

## Costo residual aceptado

El único costo variable que puede quedar es el **SMS de respaldo**, que lo cobra el **operador móvil**
(no una suscripción SaaS), y se mantiene con tope configurable y como último recurso (ADR 015). Todo lo
demás es infraestructura propia: su costo es de servidores/operación, no de licencias ni cuotas por
llamada.

## Qué falta para activarlo de verdad

El stack está **decidido y con los adaptadores detrás de contrato**; activarlo en producción es
desplegar los servicios y poblar unas pocas variables de entorno:

- Levantar y preprocesar los servicios OSM (OSRM/VROOM ya en compose; falta tiles y Nominatim/Photon).
- Generar claves **VAPID** y levantar un servidor **ntfy** propio.
- Levantar un **gateway SMS** libre y dar de alta el **bot de Telegram**.
- Apuntar `FILESYSTEM_DISK` a **MinIO**/S3-compatible propio.

Ver el roadmap (`docs/roadmap.md`) para el detalle de los ítems pendientes de infra y credenciales.
