---
name: project-architecture
description: "Monolito modular por bounded contexts, 4 capas, puertas de API, stack"
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-13T06:14:46.877Z
---

Monolito modular (ADR 001): un package Composer local por bounded context en `packages/logistics/<context>`, namespace `Logistics\<Context>\`, con 4 capas y dependencias `Presentation → Application → Domain ← Infrastructure`.

**Arquitectura objetivo (DDD + CQRS + Clean Architecture)**: el skill `bounded-context` es la guía autoritativa para crear/extender un contexto, y CLAUDE.md ya la incorpora. Puntos clave:
- **UUID v7** como identidad de dominio (ids tipados que extienden `AbstractId`); nunca enteros autoincrementales.
- **CQRS**: escritura = `Command` + `CommandHandler` (void); lectura = `Query` + `QueryHandler` que devuelve ReadModels (`final readonly`, `toArray()` primitivo). Cada par mensaje→handler se registra en `HandlerRegistry` desde `boot()` del provider.
- Agregados extienden `AggregateRoot`; VOs `final readonly` con constructor privado + named constructors; eventos extienden `BaseDomainEvent` con `eventName()` namespaced `"<ctx>.<hecho>"`.
- Handlers de escritura hacen `releaseEvents()` y los mandan al outbox (`RecordDomainEvents`) **en la misma transacción**; el despacho real corre en el relay (`MessageDispatcher`, idempotente).
- Cada contexto debe quedar **extraíble a microservicio** sin reescribir; meta de escala: respuestas rápidas sobre millones de registros y procesos transaccionales simultáneos.
- **Pendiente en disco (al 2026-10-13)**: el núcleo compartido `packages/logistics/shared` (`Logistics\Shared\...`) y `docs/adr/008-ddd-cqrs-uuidv7-outbox.md` que el skill referencia aún NO existen; verificar antes de asumir que están.

- **Domain es PHP puro**: sin Laravel, sin Eloquent, sin helpers globales. Verificable: `grep -rl Illuminate packages/logistics/<ctx>/src/Domain` debe salir vacío.
- Presentation llama a los buses CQRS (Application); nunca a Infrastructure ni a Domain directo.
- Todo I/O externo (SAP, FCM, email, SMS, transportistas) pasa por **outbox** (ADR 002); nada de HTTP síncrono dentro de un request de usuario. SAP solo se toca desde el conector.
- Eventos inmutables: cada escaneo es un evento (hora, GPS, usuario, dispositivo) que alimenta tracking, auditoría e inteligencia.

Contexts fase 1: network, coverage (PostGIS), fleet, shipments, planning, labels, execution, tracking, notifications. Fase 2 por crear: capacity, promise, sap-connector, carriers. Todos tienen carpeta `src/Domain`; `labels` es el más maduro y el skill cita network/fleet/coverage/shipments como referencia del patrón (verificar profundidad real por contexto antes de asumir).

Tres puertas de API (prefijo / quién): `/api/v1/app/*` (app Flutter) · `/api/v1/channels/*` (clientes/web/call center) · `/api/v1/integrations/*` (SAP, transportistas). Contrato en `openapi/v1.yaml` (fuente de verdad, genera cliente Dart).

Stack: Laravel 13 (PHP 8.4) · PostgreSQL 17 + PostGIS · Valkey · Filament 4 · Pest 4 · PHPStan nivel 8 · Docker. Procesos siempre encendidos: `app`+`nginx`, `queue-worker`, `scheduler`.

Detalle en `docs/architecture.md` y `docs/adr/`. Ver [[project-rules]] y [[project-tooling]].
