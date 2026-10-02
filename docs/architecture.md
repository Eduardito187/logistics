# Arquitectura — logistics

## Principios

1. **Monolito modular por bounded contexts.** Un package Composer local por dominio, con límites
   claros. Se puede extraer un módulo a servicio aparte el día que el volumen lo pida, no antes (ADR 001).
2. **Fuera del dominio SAP.** SAP es un sistema externo; solo el conector lo conoce (ADR 002).
3. **Eventos inmutables.** Cada escaneo es un evento con hora, GPS, usuario y dispositivo. Tracking,
   auditoría, integraciones e inteligencia se alimentan de ese flujo.
4. **Todo I/O externo por outbox.** Nada de HTTP síncrono dentro de un request de usuario.
5. **Reglas configurables y versionadas**, no código: coberturas, cupos, cortes, tarifas.
6. **La disponibilidad real la dan el modo offline de la app y la asincronía**, no los microservicios.

## Bounded contexts

| Package | Responsabilidad | Fase |
| --- | --- | --- |
| `logistics/network` | Nodos (CD, tiendas, puntos de recojo) y tramos | 1 |
| `logistics/coverage` | Jerarquía geográfica, zonas (PostGIS), reglas por zona, importación del mapa actual | 1 |
| `logistics/fleet` | Vehículos, choferes, ayudantes, documentos, tripulaciones, checklist | 1 |
| `logistics/shipments` | Recepción de pedidos multi-fuente, envíos, líneas y unidades | 1 |
| `logistics/planning` | Propuesta pedido→camión, secuencia, ETA, plan de carga; luego consolidación por corredor | 1 |
| `logistics/labels` | QR firmado Ed25519, verificación, rotación, ZPL, pre-emisión | 1 (implementado) |
| `logistics/execution` | Preparación, carga, ruta, POD, fallidas, recepción en nodos | 1 |
| `logistics/tracking` | Estado visible, paradas restantes, ETA, página pública | 1 |
| `logistics/notifications` | Cascada de canales: push FCM, web push, email, SMS de respaldo | 1 |
| `logistics/capacity` | Cupos en unidades de capacidad | 2 (por crear) |
| `logistics/promise` | API de promesa de entrega para canales | 2 (por crear) |
| `logistics/sap-connector` | Lectura de pedidos y traspasos de stock, idempotente | 2 (por crear) |
| `logistics/carriers` | Transportistas terceros, tarifas por corredor, calificación | 2 (por crear) |

Outbox y configuración editable: se reutilizan los patrones ya construidos en dis-commerce
(`dismac/outbox`, `dismac/settings`). Pendiente decidir si se extraen a un repo compartido o se copian.

## Capas de cada package

Igual que en dis-commerce:

```
packages/logistics/<context>/
├── composer.json            # PSR-4: Logistics\<Context>\
├── README.md
├── config/                  # opcional
├── database/migrations/
├── src/
│   ├── Domain/              # PHP puro: Model, ValueObject, Event, Exception, Contract, Service
│   ├── Application/         # Command/<UseCase>, Query/<Query>, EventListener
│   ├── Infrastructure/      # Persistence/Eloquent, Http, Providers, Crypto...
│   └── Presentation/        # Http/Controllers, Filament, Console
└── tests/
    ├── Unit/                # sin Laravel
    ├── Feature/             # stack completo contra PostgreSQL
    └── Support/
```

Dependencias: `Presentation → Application → Domain ← Infrastructure`.

## Las tres puertas de la API

| Puerta | Prefijo | Quién | Autenticación |
| --- | --- | --- | --- |
| App interna | `/api/v1/app/*` | logistics-app (Flutter) | Token por usuario + dispositivo, roles |
| Canales | `/api/v1/channels/*` | App de clientes, web, call center (después) | Credenciales por canal, rate limit |
| Integraciones | `/api/v1/integrations/*` | SAP, transportistas, webhooks | Credenciales de servicio |

El contrato es `openapi/v1.yaml`. La app genera su cliente Dart desde ahí.

## Procesos

| Proceso | Container | Qué hace |
| --- | --- | --- |
| API + admin | `app` + `nginx` | HTTP |
| Workers | `queue-worker` | Outbox, notificaciones, planificación, pre-emisión de etiquetas |
| Scheduler | `scheduler` | Tareas programadas |

Siempre encendidos (sin `profiles`): lección de un incidente en dis-commerce.
A futuro, despliegue separado para el conector SAP y la ingesta de GPS.

## Datos

- PostgreSQL 17 + PostGIS: zonas, geocercas, distancias (ADR 003).
- Valkey: cache, colas, sesiones, locks.
- S3-compatible: fotos de POD, checklist y daños.
