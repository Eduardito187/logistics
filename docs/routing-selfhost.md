# Ruteo self-host: OSRM + VROOM sobre OpenStreetMap

Runbook para levantar el motor de rutas propio de la plataforma. Implementa el ADR 009
(ruteo sobre OpenStreetMap sin servicios de pago) y el ítem **I-08** del roadmap.

- **OSRM** (`osrm/osrm-backend`): sirve la matriz de distancias/tiempos (`/table`) y las
  geometrías de ruta (`/route`, `/trip`). Es lo que consume el VRP `builtin` (Clarke-Wright
  + 2-opt) y el map-matching de telemetría.
- **VROOM** (`vroomvrp/vroom-docker`): solver VRP/VRPTW opcional (`PLANNING_ROUTING_BACKEND=vroom`),
  que resuelve apoyándose en el OSRM propio. Con el backend `builtin` queda ocioso pero encendido.

Ambos servicios están declarados en `docker-compose.yml` **siempre encendidos** (sin `profiles`).
La app los alcanza por la red interna:

- `OSRM_HOST=http://osrm:5000`
- `VROOM_URL=http://vroom:3000`

(definidos en `.env.example`; copiarlos al `.env` real). Externamente quedan publicados en
`DOCKER_OSRM_PORT` (5010 por defecto) y `DOCKER_VROOM_PORT` (3010 por defecto) para pruebas con curl.

> **Importante:** el preprocesado de OSM es **pesado** (descarga de ~400 MB y varios minutos de
> CPU/RAM) y **no corre automáticamente**. Hay que ejecutarlo una vez con los pasos de abajo.
> Mientras no exista `bolivia-latest.osrm`, el contenedor `osrm` reinicia en bucle: es esperado.

---

## 1. Descargar el extracto de Bolivia (Geofabrik)

El extracto OSM de Bolivia y todos los artefactos del preprocesado viven en
`.docker/osrm/data` (bind-mount del servicio `osrm`, ignorado por git).

```bash
cd .docker/osrm/data
curl -L -O https://download.geofabrik.de/south-america/bolivia-latest.osm.pbf
# opcional, verificar integridad:
curl -L -O https://download.geofabrik.de/south-america/bolivia-latest.osm.pbf.md5
md5sum -c bolivia-latest.osm.pbf.md5
cd -
```

## 2. Preprocesar con OSRM (extract → partition → customize, algoritmo MLD)

Se usa el **mismo** `osrm/osrm-backend` del compose, montando `.docker/osrm/data` en `/data`.
El perfil `car.lua` viene dentro de la imagen en `/opt/car.lua`. Se corre MLD (no CH) porque
`osrm-routed` arranca con `--algorithm mld`; los tres pasos deben usar MLD de forma consistente.

```bash
# 1) extract: construye el grafo desde el .pbf con el perfil de auto
docker run --rm -t -v "$(pwd)/.docker/osrm/data:/data" osrm/osrm-backend \
  osrm-extract -p /opt/car.lua /data/bolivia-latest.osm.pbf

# 2) partition: particiona el grafo (requisito de MLD)
docker run --rm -t -v "$(pwd)/.docker/osrm/data:/data" osrm/osrm-backend \
  osrm-partition /data/bolivia-latest.osrm

# 3) customize: calcula los pesos de las celdas (requisito de MLD)
docker run --rm -t -v "$(pwd)/.docker/osrm/data:/data" osrm/osrm-backend \
  osrm-customize /data/bolivia-latest.osrm
```

Esto deja en `.docker/osrm/data` el juego de archivos `bolivia-latest.osrm*` que necesita el
servidor. El `.pbf` ya no hace falta en runtime (se puede conservar para reconstruir).

> Si en el futuro se opta por **CH** en lugar de MLD: reemplazar partition+customize por
> `osrm-contract` y cambiar el comando del servicio `osrm` a `--algorithm ch`. MLD es la opción
> por defecto porque permite re-`customize` (actualizar tiempos) sin recontraer todo el grafo.

## 3. Levantar los servicios

```bash
docker compose up -d osrm vroom
```

El servicio `osrm` corre `osrm-routed --algorithm mld --max-table-size 10000 /data/bolivia-latest.osrm`.
`vroom` arranca después (`depends_on: osrm`) y lee `.docker/vroom/config.yml`, que apunta a
`osrm:5000` por la red interna.

## 4. Verificar

```bash
# OSRM: ruta entre dos puntos de La Paz (lon,lat) -> debe responder code: "Ok"
curl "http://localhost:${DOCKER_OSRM_PORT:-5010}/route/v1/driving/-68.1193,-16.4897;-68.1500,-16.5000?overview=false"

# OSRM: matriz /table (lo que consume el VRP builtin)
curl "http://localhost:${DOCKER_OSRM_PORT:-5010}/table/v1/driving/-68.1193,-16.4897;-68.1500,-16.5000"

# VROOM: healthcheck del wrapper HTTP
curl "http://localhost:${DOCKER_VROOM_PORT:-3010}/health"
```

Desde dentro de la red (p. ej. `docker compose exec app`), los hosts son `http://osrm:5000` y
`http://vroom:3000`, que es exactamente lo que leen `OSRM_HOST` y `VROOM_URL`.

---

## Apuntar la app al OSRM/VROOM propio

El cambio es solo de configuración, sin tocar código (ADR 009):

1. En `.env`, dejar:
   ```env
   OSRM_HOST=http://osrm:5000
   VROOM_URL=http://vroom:3000
   ```
   `config/logistics-planning.php` y `config/logistics-telemetry.php` leen `OSRM_HOST`
   (default: el OSRM demo público) y `VROOM_URL` desde estas variables.
2. Para activar el solver VROOM: `PLANNING_ROUTING_BACKEND=vroom`. Con `builtin` (por defecto),
   el VRP propio resuelve usando solo la matriz de OSRM; VROOM no se invoca.
3. Volver al OSRM público es comentar `OSRM_HOST` (vuelve al default
   `https://router.project-osrm.org`). Útil como fallback si el self-host está en mantenimiento.

## Mantenimiento

- **Actualizar el mapa:** repetir los pasos 1 y 2 con un `.pbf` nuevo y reiniciar `osrm`
  (`docker compose restart osrm`). Con MLD basta re-`customize` si solo cambian pesos/tiempos.
- **OSRM caído:** el ruteo `builtin` degrada a distancias haversine
  (`PLANNING_FALLBACK_SPEED_KMH`), así que la planificación no se bloquea, solo pierde precisión.
  Ver runbook de incidentes (roadmap O-09) y healthchecks de readiness (O-08).
- **Tamaño de la matriz:** `--max-table-size` (compose) y `OSRM_TABLE_MAX_COORDINATES`
  (planning) acotan cuántos puntos entran por llamada a `/table`; el backend chunkea por encima.
