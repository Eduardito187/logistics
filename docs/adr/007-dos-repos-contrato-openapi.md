# ADR 007 — Dos repos unidos por un contrato OpenAPI

**Status:** Accepted
**Fecha:** 2026-10-02
**Deciders:** Eduard Huallata

## Contexto

El proyecto tiene un backend (API, workers, admin) y una app móvil interna con ciclos de vida,
herramientas y despliegues distintos. La app de clientes y la web de Dismac consumirán la API más
adelante, pero no se construyen en estos repos.

## Decisión

- `logistics`: backend, servicios y admin web (este repo).
- `logistics-app`: app interna Flutter, uso solo interno (chofer, preparador, tienda, transportista,
  supervisor). Se distribuye por Managed Google Play, no en la tienda pública. Proyecto Firebase propio.
- El contrato es `openapi/v1.yaml`, en este repo: fuente de verdad. La app genera su cliente Dart desde él.
- Versionado `/v1`; un cambio incompatible exige `/v2` porque siempre habrá choferes con versiones viejas.

## Consecuencias

### Positivas
- Cada repo con su CI y su ritmo.
- La app y el backend no se desincronizan: el cliente se genera, no se escribe a mano.

### Negativas / trade-offs aceptados
- Un cambio de contrato toca dos repos: primero backend (publica la spec), después la app.
