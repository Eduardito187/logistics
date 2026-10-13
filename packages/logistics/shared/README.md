# logistics/shared — Núcleo compartido (shared kernel)

## Responsabilidad

Primitivas que todos los bounded contexts comparten, en PHP puro (sin Laravel en `Domain`):

- **Identidad**: `Uuid` (UUID **v7**, ordenable en el tiempo) y `AbstractId` como base de IDs tipados
  por contexto (`NodeId`, `ShipmentId`, …). Pensado para índices eficientes a gran escala.
- **Agregados y eventos**: `AggregateRoot` que graba **eventos de dominio inmutables**
  (`DomainEvent`), base de tracking, auditoría, outbox e inteligencia (principio 3 de la arquitectura).
- **CQRS**: contratos `Command` / `CommandHandler` / `CommandBus` y `Query` / `QueryHandler` /
  `QueryBus`, con implementación sobre el contenedor de Laravel.
- **Reloj**: `Clock` + `SystemClock`, para que el dominio no llame a `now()` global.

## No es responsable de

Ningún dominio concreto. Aquí no entran nodos, envíos ni flota: solo las piezas transversales.

## Por qué UUID v7

A escala de muchos registros, un entero autoincremental acopla a un solo nodo de escritura y un UUID v4
fragmenta los índices. **UUID v7** lleva el timestamp en los bits altos: es único sin coordinación
(cada contexto genera el suyo, listo para extraerse a un servicio) y mantiene los índices casi
secuenciales. Ver ADR 008.

## Capas

- `src/Domain`: PHP puro — `Uuid`, `AbstractId`, `AggregateRoot`, `DomainEvent`, `Clock`.
- `src/Application`: contratos CQRS (`CommandBus`, `QueryBus`).
- `src/Infrastructure`: buses sobre el contenedor, `SystemClock`, `SharedServiceProvider`.

## Estado

Núcleo implementado. Verificación contra containers pendiente (ver DoD en `docs/roadmap.md`).
