# ADR 012 — Auto-planificación en dos modos, asíncrona por colas

**Status:** Accepted
**Fecha:** 2026-10-13
**Deciders:** Eduard Huallata

## Contexto

Planificar a mano qué pedidos van en qué camión y en qué orden no escala con el volumen diario
(~300 entregas). Hace falta un **auto-planner** que proponga la asignación, pero el negocio no tiene
una única forma de planificar: a veces lo que manda es **aprovechar la flota** (llenar camiones y
minimizar kilómetros), y a veces lo que manda es **respetar el compromiso con el cliente** (la franja
o slot que se le prometió). Además, planificar cientos de pedidos implica construir matrices de OSRM y
correr el VRP: es trabajo pesado que no puede bloquear una petición del admin.

## Decisión

El auto-planner ofrece **dos modos** seleccionables, y corre siempre **asíncrono por colas**:

- **Modo `optimization` (por capacidad/cercanía).** Optimiza el uso de la flota: agrupa pedidos por
  cercanía y capacidad del camión y minimiza distancia/tiempo, resolviendo con el VRP del ADR 009
  (Clarke-Wright + 2-opt sobre la matriz de OSRM; VROOM si está habilitado), respetando las invariantes
  (capacidad, un-camión-un-departamento). Útil cuando el objetivo es eficiencia de ruta.
- **Modo `slots` (por calendario).** Parte de las **franjas/slots** reservados por los clientes: asigna
  respetando la ventana comprometida como restricción y recién dentro de esa restricción optimiza la
  ruta. Útil cuando el objetivo es cumplir el compromiso de entrega. Con VROOM habilitado, las ventanas
  se tratan como restricción dura (VRPTW, ver ADR 009).
- **Ejecución asíncrona.** Disparar una auto-planificación encola un job; no se resuelve dentro del
  request. El job es **idempotente** (re-ejecutarlo con la misma entrada no duplica ni corrompe el plan)
  y procesa **por lotes** para aguantar cientos de pedidos (chunking de `/table`, timeouts, reintentos).
  Puede dispararse a demanda desde el admin o programado (auto-plan diario por el scheduler, en la zona
  horaria de la aplicación — ADR 011).
- El resultado es un **plan propuesto** que el operador revisa y ajusta en el tablero; el auto-planner
  asiste, no reemplaza la decisión humana.

## Alternativas consideradas

### Un único modo "óptimo" global
- Pros: una sola cosa que mantener y explicar.
- Contras: no existe un único óptimo para el negocio: eficiencia de flota y cumplimiento de slot son
  objetivos distintos y a veces opuestos. Un solo modo obliga a elegir por el operador y esconde la
  decisión real.

### Planificación síncrona dentro del request del admin
- Pros: resultado inmediato sin colas.
- Contras: construir matrices de OSRM y correr el VRP para cientos de pedidos tarda y puede fallar por
  timeout; bloquear el request es frágil y no escala. Contradice la regla de no hacer I/O externo
  pesado dentro del request (ADR 002/008).

## Consecuencias

### Positivas
- El negocio elige el criterio (eficiencia de flota o cumplimiento de compromiso) según el día.
- Escala a cientos de pedidos sin bloquear al operador; resiliente por reintentos e idempotencia.
- Se integra con el scheduler para el auto-plan diario programado.

### Negativas / trade-offs aceptados
- Dos modos que mantener y testear (ambos cubiertos por tests: determinismo, idempotencia del job,
  ejecución por lotes).
- La planificación es asíncrona: el resultado no es instantáneo, hay que reflejar el estado del job en
  el tablero.

### Neutras
- El auto-planner no decide solo: produce una propuesta que el operador aprueba o ajusta.

## Referencias

- ADR 009 (VRP sobre OSRM, VROOM opcional, VRPTW para ventanas).
- ADR 011 (zona horaria de la app para cortes y scheduler del auto-plan).
- ADR 008 (outbox) y ADR 002 (I/O externo asíncrono).
- Roadmap: V-08, V-09, E-07, I-05 (auto-plan diario programado), C-SET-01 (reglas de auto-plan).
