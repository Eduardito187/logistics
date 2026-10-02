# ADR 003 — PostgreSQL + PostGIS como base de datos

**Status:** Accepted
**Fecha:** 2026-10-02
**Deciders:** Eduard Huallata

## Contexto

El dominio es geoespacial: zonas de cobertura como polígonos (importadas del mapa actual por
departamento), resolver en qué zona cae una coordenada, geocercas de llegada, distancias y la jerarquía
Departamento > Provincia > Municipio. dis-commerce usa MariaDB.

## Decisión

PostgreSQL 17 con PostGIS (`postgis/postgis:17-3.5`). Los tests también corren sobre PostgreSQL real,
en una base aparte (`logistics_test`), porque SQLite no tiene geometría.

## Alternativas consideradas

### MariaDB (como dis-commerce)
- Pros: mismo motor que el resto del equipo.
- Contras: soporte espacial limitado frente a PostGIS (índices, funciones, precisión geográfica).

## Consecuencias

### Positivas
- Punto→zona, geocercas y distancias resueltos en la base, con índices espaciales.

### Negativas / trade-offs aceptados
- Un motor distinto al de dis-commerce: el equipo convive con dos.
- Los tests Feature necesitan PostgreSQL levantado (Docker o servicio en CI).
