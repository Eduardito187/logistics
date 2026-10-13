# ADR 008 — DDD táctico + CQRS + UUID v7 + outbox como base de los contextos

**Status:** Accepted
**Fecha:** 2026-10-13
**Deciders:** Eduard Huallata

## Contexto

El ADR 001 fijó un monolito modular por bounded contexts, extraíble a servicios cuando el volumen lo
pida. Hace falta decidir **cómo se construye por dentro cada contexto** para que esa extracción sea
mover un package, no reescribirlo, y para que el modelo aguante crecer sin acoplarse a un único nodo
de escritura ni fragmentar los índices.

## Decisión

Un **núcleo compartido** (`logistics/shared`) define las primitivas que todos los contextos usan:

- **DDD táctico.** Cada contexto tiene agregados (`AggregateRoot`), value objects inmutables
  (`final readonly`), eventos de dominio (`DomainEvent`) y contratos de repositorio. El `Domain` es
  PHP puro (sin Laravel), como ya exige `CLAUDE.md`.
- **CQRS.** La escritura va por comandos (`Command` → un `CommandHandler`) y la lectura por consultas
  (`Query` → `QueryHandler`), despachados por un `CommandBus`/`QueryBus` sobre el contenedor. Las
  lecturas pueden saltarse el dominio y usar modelos de lectura; las escrituras siempre pasan por el
  agregado. Separa los dos caminos para poder optimizarlos por separado.
- **UUID v7 como identidad.** Todo id es un UUID v7 (timestamp en los bits altos, RFC 9562),
  generado en el propio contexto sin coordinación. Es único entre contextos y nodos (listo para
  extraer a servicio) y mantiene los índices B-tree casi secuenciales, a diferencia de un
  autoincremental (acopla a un escritor) o un UUID v4 (fragmenta el índice).
- **Eventos inmutables + outbox.** Los agregados graban eventos; la capa de aplicación los manda al
  `logistics/outbox` en la misma transacción del cambio. Un relay asíncrono los despacha a SAP, FCM,
  email, SMS o webhooks con reintentos e idempotencia por `event_id` (principio 4 de la arquitectura,
  ADR 002). Nada de HTTP síncrono dentro del request del usuario.

## Alternativas consideradas

### Active Record / CRUD directo en controladores
- Pros: menos ceremonia al inicio.
- Contras: la lógica se dispersa, el dominio se acopla a Eloquent y extraer un contexto obliga a
  reescribir. No escala en complejidad.

### Microservicios reales desde el día 1 (con su propia base cada uno)
- Pros: escalado y despliegue independientes "de verdad".
- Contras: complejidad operativa sin volumen que la justifique (lo ya rechazado en ADR 001). CQRS +
  UUID v7 + outbox dan el 90% de los beneficios (límites claros, identidad distribuida, integración
  asíncrona) con una fracción del costo, y dejan la puerta abierta a extraer cuando haga falta.

### Entero autoincremental como id
- Pros: índice compacto, simple.
- Contras: acopla a un único nodo de escritura y filtra el orden de inserción entre contextos; estorba
  la extracción a servicio.

## Consecuencias

### Positivas
- Cada contexto es coherente por dentro y aislable: se extrae a servicio moviendo el package.
- Identidad única sin coordinación e índices sanos a gran escala.
- Integración externa resiliente por outbox; el escaneo nunca espera a un sistema externo.

### Negativas / trade-offs aceptados
- Más piezas por caso de uso (comando, handler, evento) que un CRUD directo: la disciplina se paga por
  adelantado y se cobra en mantenibilidad.
- Un UUID ocupa más que un entero (16 bytes); aceptable para el volumen y los beneficios.
