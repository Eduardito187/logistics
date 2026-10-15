# Mapas y geocoder self-host: Nominatim + TileServer sobre OpenStreetMap

Runbook para levantar las teselas de mapa y el geocoder propios de la plataforma, **todo
open-source y gratis sobre OpenStreetMap**: cero Google, cero servicios de pago, cero cuotas
por llamada. Acompaña al runbook de ruteo (`docs/routing-selfhost.md`, OSRM/VROOM) y al resumen
de stack libre (`docs/opensource-stack.md`).

- **Nominatim** (`mediagis/nominatim`): geocoder OSM que resuelve `dirección → coordenada +
  confianza`. Reemplaza a Google Geocoding. Lo consume el contexto **shipments** (comando
  `GeocodeShipmentAddress`, asíncrono vía outbox) detrás del contrato `Geocoder`.
- **TileServer-GL** (`maptiler/tileserver-gl`): sirve las teselas raster del estilo **osm-bright**
  a los mapas **Leaflet** del admin (cobertura, ruta, perfil de cliente, mapa de entrega) y al
  seguimiento público. Reemplaza el uso de `tile.openstreetmap.org` en producción.

Ambos servicios están declarados en `docker-compose.yml` **siempre encendidos** (sin `profiles`).

| Servicio | Red interna (app) | Puerto host (pruebas) | Lo usa |
|---|---|---|---|
| `nominatim` | `http://nominatim:8080` | `DOCKER_NOMINATIM_PORT` (8081) | shipments (server-side) |
| `tileserver` | — | `DOCKER_TILESERVER_PORT` (8082) | Leaflet (navegador) |

Env en `.env.example` (copiar al `.env` real):

- `NOMINATIM_URL=http://nominatim:8080` — el geocoder lo alcanza por la red interna; shipments le
  agrega la ruta `/search`. Lo llama el worker del outbox, no el navegador.
- `MAP_TILE_URL=http://localhost:8082/styles/osm-bright/{z}/{x}/{y}.png` — la pide el **navegador**,
  por eso usa el puerto del host. Si se deja **vacía**, las vistas caen al tile público de OSM
  (`https://{s}.tile.openstreetmap.org/...`, **solo demo**: respeta la política de uso de OSM y no
  sirve para producción).

> **Importante:** tanto el import de Nominatim como la generación del mbtiles son **pesados**
> (descarga de ~400 MB de OSM y bastante CPU/RAM/tiempo) y **no corren automáticamente**. Hay que
> ejecutarlos una vez con los pasos de abajo. Mientras falten los datos, los contenedores
> `nominatim` y `tileserver` reinician en bucle: es esperado, es la señal de que falta el runbook.

Relacionado en el roadmap: **I-10** (tiles OSM propios) y el geocoder real de **shipments**
(C-SHP-01 / C-SHP-02, R-07).

---

## 1. Descargar el extracto de Bolivia (Geofabrik)

Un solo extracto OSM de Bolivia alimenta geocoder, tiles y ruteo. Geofabrik publica el archivo
`bolivia-latest.osm.pbf` (~400 MB).

```bash
curl -L -o /tmp/bolivia-latest.osm.pbf \
  https://download.geofabrik.de/south-america/bolivia-latest.osm.pbf
```

---

## 2. Nominatim (geocoder)

El extracto y la base del índice viven en el bind-mount `.docker/nominatim/data` (ignorado por
git) y en el volumen `nominatim_data`.

```bash
# Colocar el extracto donde el contenedor lo espera (PBF_PATH del docker-compose).
cp /tmp/bolivia-latest.osm.pbf .docker/nominatim/data/bolivia-latest.osm.pbf

# Primer arranque: mediagis/nominatim importa el PBF automáticamente (puede tardar bastante).
docker compose up -d nominatim

# Seguir el import (hasta "Import finished" / el healthcheck interno en verde).
docker compose logs -f nominatim
```

Verificar cuando termine:

```bash
# /search debe devolver un resultado JSON para una dirección boliviana.
curl "http://localhost:8081/search?q=Av.+Arce,+La+Paz&format=jsonv2&limit=1&countrycodes=bo"
```

El import persiste en el volumen `nominatim_data`: no se repite en arranques posteriores. Para
re-importar (p. ej. extracto nuevo), borrar el volumen y repetir:
`docker compose down && docker volume rm dismac-logistics_nominatim_data`.

> El geocoder respeta un throttle (~1 req/s) y cachea la forma primitiva del resultado en Valkey
> (30 días por defecto), para no saturar la instancia self-host en reprocesos masivos.

### Apuntar shipments a Nominatim

Ya viene por defecto: `SHIPMENTS_GEOCODER_DRIVER=nominatim` y
`NOMINATIM_URL=http://nominatim:8080`. Para deshabilitarlo (entornos sin servicio) y caer al stub
neutro: `SHIPMENTS_GEOCODER_NOMINATIM_ENABLED=false`. Para apuntar a una instancia en otro host o
tras un proxy, fijar la URL `/search` completa en `SHIPMENTS_GEOCODER_NOMINATIM_ENDPOINT`.

---

## 3. TileServer-GL (teselas de mapa)

TileServer-GL sirve teselas desde un `.mbtiles` (vector) con un estilo. Para raster (lo que pide
Leaflet por `{z}/{x}/{y}.png`) usa el render nativo. Hay dos caminos para obtener el `.mbtiles`:

**Opción A — descargar un mbtiles ya hecho (más simple).** OpenMapTiles / MapTiler publican
extractos regionales. Descargar el de Bolivia/Sudamérica como `.docker/tileserver/data/bolivia.mbtiles`.

**Opción B — generar el mbtiles desde el PBF con OpenMapTiles/Tilemaker (todo libre).** Ejemplo con
Tilemaker:

```bash
# En una máquina con tilemaker instalado; el proceso es pesado.
tilemaker --input /tmp/bolivia-latest.osm.pbf \
          --output .docker/tileserver/data/bolivia.mbtiles \
          --config /ruta/tilemaker/config-openmaptiles.json \
          --process /ruta/tilemaker/process-openmaptiles.lua
```

> Alternativa **Protomaps** (`.pmtiles`): un único archivo estático servible sin backend. Si se
> prefiere, generar `bolivia.pmtiles` y servirlo (TileServer-GL ≥ 4 soporta pmtiles, o un CDN/estático),
> ajustando `MAP_TILE_URL` a la ruta correspondiente.

### Config + estilo osm-bright

TileServer-GL arranca con `--config /data/config.json`. Dejar en `.docker/tileserver/data`:

- `bolivia.mbtiles` — las teselas vector (paso anterior).
- un estilo **osm-bright** (JSON de MapLibre; OpenMapTiles publica `osm-bright-gl-style` libre) con
  sus glyphs/sprites.
- `config.json` que registre el estilo con el nombre `osm-bright`, por ejemplo:

```json
{
  "options": { "paths": { "root": "/data", "mbtiles": "/data", "styles": "/data/styles" } },
  "data": { "openmaptiles": { "mbtiles": "bolivia.mbtiles" } },
  "styles": { "osm-bright": { "style": "osm-bright/style.json" } }
}
```

Con ese nombre, la ruta raster que consume Leaflet queda en
`/styles/osm-bright/{z}/{x}/{y}.png` — exactamente el `MAP_TILE_URL` por defecto.

```bash
docker compose up -d tileserver
docker compose logs -f tileserver

# Verificar una tesela (debe devolver un PNG):
curl -I "http://localhost:8082/styles/osm-bright/10/320/590.png"
```

### Apuntar los mapas a los tiles propios

`MAP_TILE_URL` ya viene apuntando al tileserver self-host. Las vistas Leaflet lo leen vía
`config/maps.php` (`maps.tile_url`), con `maps.tile_attribution` y `maps.tile_max_zoom`. El origen
del tileserver se agrega automáticamente a la CSP del admin (`App\Http\Middleware\SecurityHeaders`).
Si `MAP_TILE_URL` queda vacío, las vistas usan `maps.tile_fallback_url` (OSM público, solo demo).

---

## 4. Checklist de verificación

- [ ] `curl http://localhost:8081/search?q=...&countrycodes=bo` devuelve coordenada.
- [ ] `curl -I http://localhost:8082/styles/osm-bright/{z}/{x}/{y}.png` devuelve `image/png`.
- [ ] En el admin, el mapa de cobertura y el de ruta cargan teselas desde el host propio (no desde
      `tile.openstreetmap.org`), verificable en la pestaña Network del navegador.
- [ ] Un envío sin coordenada, tras `GeocodeShipmentAddress`, queda geocodificado contra Nominatim.
