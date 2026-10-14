# Documentación — logistics

| Documento | Para qué |
| --- | --- |
| [vision.md](vision.md) | Problema, decisiones, fases y KPIs (resumen del documento de visión) |
| [architecture.md](architecture.md) | Bounded contexts, capas, puertas de API, procesos |
| [adr/](adr) | Decisiones de arquitectura, una por archivo |
| [opensource-stack.md](opensource-stack.md) | Stack 100% open-source y gratuito (sin Google ni servicios de pago) |
| [roadmap.md](roadmap.md) | Roadmap vivo: qué está hecho y qué falta para producción |
| [../openapi/v1.yaml](../openapi/v1.yaml) | Contrato de la API |

## ADRs

| # | Decisión |
| --- | --- |
| [001](adr/001-monolito-modular-laravel.md) | Monolito modular en Laravel 13 |
| [002](adr/002-fuera-del-dominio-sap.md) | La plataforma vive fuera del dominio SAP |
| [003](adr/003-postgresql-postgis.md) | PostgreSQL + PostGIS como base de datos |
| [004](adr/004-etiquetas-qr-firmadas-ed25519.md) | Etiquetas QR con JSON compacto firmado Ed25519 |
| [005](adr/005-admin-filament-y-react.md) | Admin en Filament; tablero de planificación en React |
| [006](adr/006-notificaciones-sin-whatsapp.md) | Notificaciones sin WhatsApp: push, web push, email, SMS de respaldo (revisado por ADR 010, 014 y 015) |
| [007](adr/007-dos-repos-contrato-openapi.md) | Dos repos unidos por un contrato OpenAPI |
| [008](adr/008-ddd-cqrs-uuidv7-outbox.md) | DDD táctico + CQRS + UUID v7 + outbox como base de los contextos |
| [009](adr/009-ruteo-openstreetmap-osrm-vrp.md) | Ruteo sobre OpenStreetMap con OSRM + VRP propio (VROOM opcional) |
| [009b](adr/009-app-web-vue-inertia.md) | App web operativa en Vue + Inertia, híbrida con Filament (enmienda ADR 005) |
| [010](adr/010-habilitar-whatsapp-y-telegram.md) | Habilitar WhatsApp y Telegram (superado en parte por ADR 015: sin WhatsApp de pago) |
| [011](adr/011-zona-horaria-configurable-la-paz.md) | Zona horaria configurable por entorno, default America/La_Paz |
| [012](adr/012-auto-planificacion-dos-modos-async.md) | Auto-planificación en dos modos (capacidad/cercanía y slots), asíncrona por colas |
| [013](adr/013-todo-openstreetmap-self-host-sin-pago.md) | Todo OpenStreetMap self-host, sin Google ni servicios de pago (tiles, geocoder, ruteo) |
| [014](adr/014-push-sin-firebase-ntfy-unifiedpush-webpush.md) | Push sin Firebase: ntfy/UnifiedPush + Web Push (VAPID) |
| [015](adr/015-notificaciones-open-source-sin-whatsapp-de-pago.md) | Notificaciones 100% open-source: sin WhatsApp de pago, Telegram y SMS por gateway libre |
