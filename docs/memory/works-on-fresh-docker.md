---
name: works-on-fresh-docker
description: Regla dura del dueño — todo debe funcionar al levantar el docker en CUALQUIER entorno (cloud/staging/instalación nueva), nunca solo por el .env local
metadata:
  type: feedback
---

El dueño se enojó fuerte (2026-11-11, "el mismo puto error que siempre... soluciones permanentes y
efectivas") al ver el mapa de flota SIN FONDO en staging. Causa: el default de `MAP_TILE_URL` era el
tileserver opcional `http://localhost:8082`; en su PC andaba solo porque su `.env` local tenía la URL
pública a mano. Lo había pedido "más de mil veces".

**Why:** un arreglo que vive en el `.env` local no es un arreglo: cada entorno nuevo (staging, cloud,
otra PC, CI) vuelve a romperse y el dueño lo vive como el mismo bug repetido.

**How to apply:**
- Los DEFAULTS de `config/*.php` y `.env.example` deben producir una app funcional sin pasos manuales.
  Servicios opcionales (perfil `geo`: osrm/vroom/nominatim/tileserver, soketi, minio) jamás pueden ser el
  default de algo que el usuario ve.
- Una URL que pide el NAVEGADOR nunca puede ser `localhost` en un servidor.
- Al arreglar algo "visible", comparar `.env` local vs `.env.example`/`render-env.sh`
  (`comm` de claves y valores) para encontrar lo que solo anda por config local.
- Dejar un CANDADO que falle si vuelve: test (p.ej. `SecurityHeadersTest` "never localhost", probado
  con mutación) y/o check en el smoke del deploy (`scripts/staging/deploy.sh` revisa `maps.tile_url`).
- Teselas: default = OSM público (`https://tile.openstreetmap.org/{z}/{x}/{y}.png`); self-host opt-in.
  Esto reemplaza la regla vieja "tiles solo self-host" de [[distrack-rebrand-no-cdn]].
Ver [[staging-deploy-ci]], [[admin-ui-maps]].
