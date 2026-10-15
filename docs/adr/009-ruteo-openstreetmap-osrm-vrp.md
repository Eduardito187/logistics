# ADR 009 — Ruteo sobre OpenStreetMap con OSRM + VRP propio (VROOM opcional)

**Status:** Accepted
**Fecha:** 2026-10-13
**Deciders:** Eduard Huallata

## Contexto

La plataforma necesita dos cosas distintas sobre un mapa real de calles: calcular **distancias y
tiempos** entre muchos puntos (depósito y clientes) y, con esa matriz, **resolver el ruteo de flota**
(qué pedidos lleva cada camión y en qué orden). El ADR 005 mencionó Google Maps JS para el tablero de
planificación; eso arrastra una dependencia de pago (Directions, Distance Matrix y, para el ruteo,
Google Route Optimization) con costo por llamada, cuotas y los datos de los clientes saliendo a un
tercero. El volumen esperado (~300 entregas/día, matrices de cientos de puntos recalculadas a diario)
hace que el costo por llamada sea el factor dominante, y la operación es en Bolivia, donde OpenStreetMap
tiene buena cobertura de calles.

## Decisión

El ruteo se construye sobre **OpenStreetMap**, sin servicios de ruteo de pago:

- **Motor de calles: OSRM.** Las matrices de distancia/tiempo salen de OSRM `/table` y las geometrías
  de ruta real de `/route`. En el mapa se usan tiles de OpenStreetMap con **Leaflet** (reemplaza a
  Google Maps JS del ADR 005). El host es configurable por `OSRM_HOST`: arranca contra un OSRM público
  y migra a **OSRM self-host** con un extracto OSM de Bolivia preprocesado (CH) sin tocar código.
- **VRP propio, backend enchufable.** El solver de ruteo es una interfaz con tres implementaciones
  seleccionables por configuración: `builtin` (heurística propia **Clarke-Wright savings + 2-opt**
  sobre la matriz de OSRM), `osrm` (para casos simples apoyándose en `/trip`) y `vroom` (ver abajo).
  El `builtin` es el camino por defecto: es determinista, corre sin servicios extra y respeta las
  invariantes del negocio (capacidad del camión, un-camión-un-departamento).
- **VROOM opcional para escala y VRPTW.** Cuando el volumen o las restricciones lo pidan (ventanas
  horarias duras — VRPTW —, múltiples capacidades, múltiples depósitos) se habilita **VROOM**
  self-host sobre el OSRM propio, vía `VROOM_URL`. Es opt-in: si no está configurado, el `builtin`
  sigue resolviendo.

Todas las llamadas a OSRM/VROOM son I/O externo: salen fuera del request del usuario (jobs de cola),
nunca de forma síncrona en una petición interactiva.

## Alternativas consideradas

### Google Maps / Google Route Optimization (lo que insinuaba el ADR 005)
- Pros: calidad de datos y de solver "llave en mano", sin operar infraestructura.
- Contras: costo por llamada que escala con el volumen diario; cuotas; coordenadas de clientes enviadas
  a un tercero; acopla el dominio a un proveedor cerrado y difícil de reemplazar.

### Solo OSRM (`/trip`) sin VRP propio
- Pros: nada que implementar; OSRM resuelve el TSP de una ruta.
- Contras: `/trip` ordena **una** ruta, no asigna pedidos a varios camiones ni respeta capacidad o la
  invariante un-camión-un-departamento. Insuficiente como VRP.

### VROOM obligatorio desde el día 1
- Pros: VRP y VRPTW de calidad desde el inicio.
- Contras: otro servicio que operar antes de tener el volumen que lo justifique. El `builtin` da un
  resultado determinista y suficiente para el volumen actual; VROOM entra cuando haga falta.

## Consecuencias

### Positivas
- Costo de ruteo cercano a cero y sin cuotas de un tercero; las coordenadas no salen a un proveedor
  cerrado (más aún con OSRM/VROOM self-host).
- Backend de ruteo intercambiable por configuración: se sube de `builtin` a `vroom` sin reescribir.
- Camino por defecto determinista y testeable (fallback sin OSRM incluido).

### Negativas / trade-offs aceptados
- Operar OSRM (y, opcionalmente, VROOM) self-host es infraestructura propia a mantener y monitorear
  (healthchecks, caída de OSRM en los runbooks).
- La heurística Clarke-Wright + 2-opt da buenas soluciones, no el óptimo global; aceptable para el
  volumen y las restricciones actuales.
- El OSRM demo público tiene límites de uso y de tamaño de matriz: hay que chunkear `/table` y,
  en serio, pasar al self-host.

### Neutras
- Leaflet + OpenStreetMap sustituye a Google Maps JS en el tablero y la torre de control; el ADR 005
  sigue vigente en su decisión de Filament + React, solo cambia la capa de mapas.

## Referencias

- Revisa parcialmente el ADR 005 (reemplaza Google Maps JS por Leaflet + OpenStreetMap).
- ADR 002 (fuera del dominio SAP) y ADR 008 (outbox): todo I/O externo asíncrono.
- Roadmap: ítems R-01/R-02/R-03 (OSRM/VROOM self-host, VRPTW), I-08/I-09, E-07.
- OSRM (`/table`, `/route`, `/trip`), VROOM, algoritmo Clarke-Wright savings, mejora local 2-opt.
