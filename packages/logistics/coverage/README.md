# logistics/coverage — Cobertura

## Responsabilidad

Jerarquía Departamento > Provincia > Municipio > Ciudad > Zona (polígono PostGIS) > Microzona. Resolución punto→zona. Reglas por zona: nodo que atiende, servicios, tiempos base, límites por producto, restricciones horarias, días de servicio. Importa el mapa de cobertura actual (KML/GeoJSON).

## No es responsable de

Cupos y capacidad (Fase 2).

## Estructura

Cuatro capas, igual que los packages de dis-commerce (ver `docs/architecture.md`):

- `src/Domain`: reglas de negocio en PHP puro, sin Laravel.
- `src/Application`: casos de uso (commands y queries).
- `src/Infrastructure`: Eloquent, clientes HTTP, providers.
- `src/Presentation`: controllers, Filament, comandos Artisan.

## Estado

Esqueleto creado. Sin modelo de dominio todavía.
