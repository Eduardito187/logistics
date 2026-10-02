# ADR 005 — Admin en Filament; tablero de planificación en React

**Status:** Accepted
**Fecha:** 2026-10-02
**Deciders:** Eduard Huallata

## Contexto

El admin tiene dos tipos de pantallas: CRUD y configuración (datos maestros, reglas, flota,
reconciliación SAP) y pantallas de operación muy interactivas (tablero de planificación con arrastrar
pedidos entre camiones sobre un mapa, torre de control con flota en vivo).

## Decisión

- Filament 4 para todo lo que es CRUD y configuración (el equipo ya lo domina en dis-commerce).
- React vía Inertia + Google Maps JS solo para el tablero de planificación y la torre de control.
  Se agrega cuando se construyan esas pantallas.
- Todo texto visible del admin en inglés.

## Consecuencias

### Positivas
- Velocidad en el 80% de las pantallas; UI fluida donde la operación la necesita.

### Negativas / trade-offs aceptados
- Dos tecnologías de frontend en el mismo admin.
