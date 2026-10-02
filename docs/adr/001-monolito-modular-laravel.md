# ADR 001 — Monolito modular en Laravel 13

**Status:** Accepted
**Fecha:** 2026-10-02
**Deciders:** Eduard Huallata

## Contexto

La carga técnica es baja (~100 camiones, ~300 entregas diarias, ~3 req/s de GPS). El equipo ya domina
Laravel y dis-commerce (Laravel 13 + DDD) tiene patrones probados: outbox, settings, packages por
bounded context. La construcción es asistida con Claude, que rinde mejor con límites claros por módulo.

## Decisión

Un solo backend Laravel 13 (PHP 8.4) organizado como monolito modular: un package Composer local por
bounded context en `packages/logistics/<context>`, con las mismas cuatro capas que dis-commerce.
Procesos separados (API, workers, scheduler) desde el mismo código.

## Alternativas consideradas

### Microservicios desde el día 1
- Pros: escalado y despliegue independientes.
- Contras: complejidad operativa (red, observabilidad, consistencia) sin volumen que la justifique.

### Node o Go
- Pros: buen rendimiento en I/O.
- Contras: rompe con el stack del equipo y con lo reutilizable de dis-commerce, sin ganancia decisiva.

## Consecuencias

### Positivas
- Reutilización directa de patrones y convenciones de dis-commerce.
- Un módulo se puede extraer a servicio cuando el volumen lo pida (candidatos: conector SAP, ingesta de GPS).

### Negativas / trade-offs aceptados
- Disciplina obligatoria en los límites entre packages: nadie importa Infrastructure de otro contexto.
