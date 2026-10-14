# ADR 013 — Todo OpenStreetMap self-host, sin Google ni servicios de pago

**Status:** Accepted (consolida y revisa el ADR 005 en su capa de mapas, y amplía el ADR 009)
**Fecha:** 2026-10-13
**Autores:** @eduard
**Deciders:** Eduard Huallata

## Contexto

La plataforma usa mapas en tres puntos distintos: **tiles** (el fondo del mapa en el admin, el SPA
y la página pública de tracking), **geocodificación** (dirección de texto → coordenada, con score de
confianza) y **ruteo** (matrices de distancia/tiempo y resolución del VRP de flota). El ADR 005
insinuaba Google Maps JS para el tablero; el ADR 009 ya movió el **ruteo** a OpenStreetMap con OSRM +
VRP propio (VROOM opcional). Quedaban abiertas dos decisiones en el roadmap (D-02 ruteo, D-05
geocoder) y una dependencia frágil: el admin y el SPA tiraban tiles de `tile.openstreetmap.org`, cuya
política de uso prohíbe tráfico productivo (ítem I-10).

El dueño del producto fija una regla transversal que cierra esas decisiones: **todo el stack de mapas
es 100% open-source, gratuito y auto-hospedado. Cero Google, cero servicios de pago, cero cuotas por
llamada, y las coordenadas de los clientes no salen a terceros.** La operación es en Bolivia, donde
OpenStreetMap tiene buena cobertura de calles.

## Decisión

Las tres capas de mapas se construyen sobre **OpenStreetMap self-host**, detrás de contratos de la
capa Infrastructure, con host configurable por entorno (nunca una API key de pago):

- **Tiles — OpenMapTiles + TileServer-GL (o Protomaps `.pmtiles`).** Se sirven tiles propios desde un
  extracto OSM de Bolivia; el fondo del mapa deja de depender de `tile.openstreetmap.org`. Protomaps
  (un único archivo `.pmtiles` servido como estático/CDN) es la opción de menor costo operativo;
  TileServer-GL es la alternativa cuando se quiera renderizado vectorial/raster servido. El cliente
  (Leaflet / MapLibre) apunta a `MAP_TILES_URL`, no a un proveedor de pago. Cierra I-10 y R-09.
- **Geocoder — Nominatim o Photon self-host.** La geocodificación de direcciones corre contra un
  **Nominatim** propio (o **Photon**, construido sobre datos de Nominatim, mejor para autocompletado)
  sobre el mismo extracto de Bolivia. Queda detrás del contrato de geocoder ya existente en
  `shipments`, seleccionable por `GEOCODER_DRIVER` y `NOMINATIM_URL` / `PHOTON_URL`; con el servicio
  sin configurar, el driver cae a un stub/null (degradación, no caída). Resuelve D-05 y reemplaza la
  idea de "geocoder Google" del roadmap (C-SHP-01).
- **Ruteo — OSRM + VROOM self-host.** Se mantiene lo del ADR 009: OSRM para matrices y geometrías,
  VRP `builtin` (Clarke-Wright + 2-opt) por defecto, VROOM opcional para VRPTW/escala. Resuelve D-02.

Toda llamada a estos servicios es **I/O externo** (jobs de cola, ADR 008): nunca síncrona dentro de un
request interactivo.

## Alternativas consideradas

### Google Maps / Geocoding / Route Optimization (lo que insinuaba el ADR 005)
- Pros: calidad de datos y solver "llave en mano" sin operar infraestructura.
- Contras: costo por llamada que escala con el volumen; cuotas; coordenadas de clientes enviadas a un
  tercero; acopla el dominio a un proveedor cerrado. **Lo descarta la regla del dueño.**

### Tiles de `tile.openstreetmap.org` en producción
- Pros: cero infraestructura.
- Contras: su política de uso **prohíbe** tráfico productivo; sin SLA y con riesgo de bloqueo. Sirve
  solo para desarrollo.

### Proveedores OSM gestionados de pago (Mapbox, MapTiler, Stadia, LocationIQ)
- Pros: OSM sin operar servidores.
- Contras: vuelven a ser **servicios de pago** con cuotas; contradicen la regla. Descartados.

## Consecuencias

### Positivas
- Costo de mapas/geocoder/ruteo cercano a cero y sin cuotas de terceros.
- Privacidad: las coordenadas de clientes no salen de la infraestructura propia.
- Un solo origen de datos (extracto OSM de Bolivia) alimenta tiles, geocoder y ruteo.
- Stack intercambiable por configuración (`MAP_TILES_URL`, `GEOCODER_DRIVER`, `OSRM_HOST`, `VROOM_URL`).

### Negativas / trade-offs aceptados
- Más infraestructura propia a operar y monitorear: TileServer/Protomaps, Nominatim/Photon, OSRM/VROOM
  (healthchecks, actualización periódica del extracto, espacio en disco del preprocesado).
- El geocoder OSM puede tener menor cobertura de direcciones informales que Google; se compensa con el
  score de confianza, la re-geocodificación y la corrección manual de Fase 0.
- La calidad de los tiles/estilo depende del esfuerzo de estilo propio (OpenMapTiles/Protomaps styles).

### Neutras
- Leaflet/MapLibre + OSM ya era la capa cliente del SPA (ADR 009); solo cambia el origen de los tiles
  de público a propio. El ADR 005 sigue vigente en Filament + tablero, solo cambia la capa de mapas.

## Referencias

- Revisa el ADR 005 (reemplaza por completo Google Maps por OSM self-host en todas las capas).
- Amplía el ADR 009 (ruteo OSRM/VROOM) a tiles y geocoder.
- ADR 008 (outbox): todo I/O externo es asíncrono.
- Cierra las decisiones abiertas D-02 (ruteo) y D-05 (geocoder) del roadmap; cubre I-10 y R-09 (tiles),
  C-SHP-01 y R-07 (geocoder), I-08/I-09 (OSRM/VROOM self-host).
- `docs/opensource-stack.md` (resumen del stack), `docs/routing-selfhost.md` (runbook OSRM/VROOM),
  `docs/maps-geocoder-selfhost.md` (runbook Nominatim + teselas).
- OpenMapTiles, TileServer-GL, Protomaps (`.pmtiles`), Nominatim, Photon, OSRM, VROOM.
