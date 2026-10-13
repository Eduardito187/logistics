# Documentación — logistics

| Documento | Para qué |
| --- | --- |
| [vision.md](vision.md) | Problema, decisiones, fases y KPIs (resumen del documento de visión) |
| [architecture.md](architecture.md) | Bounded contexts, capas, puertas de API, procesos |
| [adr/](adr) | Decisiones de arquitectura, una por archivo |
| [../openapi/v1.yaml](../openapi/v1.yaml) | Contrato de la API |

## ADRs

| # | Decisión |
| --- | --- |
| [001](adr/001-monolito-modular-laravel.md) | Monolito modular en Laravel 13 |
| [002](adr/002-fuera-del-dominio-sap.md) | La plataforma vive fuera del dominio SAP |
| [003](adr/003-postgresql-postgis.md) | PostgreSQL + PostGIS como base de datos |
| [004](adr/004-etiquetas-qr-firmadas-ed25519.md) | Etiquetas QR con JSON compacto firmado Ed25519 |
| [005](adr/005-admin-filament-y-react.md) | Admin en Filament; tablero de planificación en React |
| [006](adr/006-notificaciones-sin-whatsapp.md) | Notificaciones sin WhatsApp: push, web push, email, SMS de respaldo (revisado por ADR 010) |
| [007](adr/007-dos-repos-contrato-openapi.md) | Dos repos unidos por un contrato OpenAPI |
| [008](adr/008-ddd-cqrs-uuidv7-outbox.md) | DDD táctico + CQRS + UUID v7 + outbox como base de los contextos |
| [009](adr/009-ruteo-openstreetmap-osrm-vrp.md) | Ruteo sobre OpenStreetMap con OSRM + VRP propio (VROOM opcional) |
| [010](adr/010-habilitar-whatsapp-y-telegram.md) | Habilitar WhatsApp y Telegram como canales de notificación |
| [011](adr/011-zona-horaria-configurable-la-paz.md) | Zona horaria configurable por entorno, default America/La_Paz |
| [012](adr/012-auto-planificacion-dos-modos-async.md) | Auto-planificación en dos modos (capacidad/cercanía y slots), asíncrona por colas |
