# Migración de WSL → Windows (Docker Desktop) sin perder datos

Guía para mover el proyecto completo (código + Docker + volúmenes + red + base de datos) desde el
filesystem de WSL a `C:\Users\<usuario>\Documents\APP\logistics`, conservando **todos los datos** y con
el rendimiento lo más cerca posible del de WSL.

## Por qué NO se pierden datos

- El `docker-compose.yml` fija **`name: dismac-logistics`** y la red **`dismac_lg_network`** de forma
  explícita (no dependen del nombre de la carpeta). Al mover la carpeta, Docker Compose **reutiliza los
  mismos volúmenes y la misma red** — nada se recrea.
- La base de datos vive en el **volumen nombrado `dismac-logistics_postgres_data`**, administrado por
  Docker Desktop **fuera de la carpeta del proyecto**. Copiar la carpeta ni lo roza.
- Aun así se hace un **backup de la base** (`php artisan backup:create`: estructura completa + datos +
  `manifest.json` con conteos por tabla) como red de seguridad, se guarda **fuera del proyecto** y al
  final se **verifica** (`backup:verify` + conteos de la base contra el manifest).
- El backup es **solo de la base de datos**. Contenedores, imágenes y volúmenes no se respaldan: la
  migración no los toca. El código se copia con robocopy y se controla con `git status` (Fases 2 y 5).

## Por qué el rendimiento baja en NTFS (y cómo lo mitigamos)

Hoy es rápido porque el código está en el ext4 de WSL. En `C:\...\APP` (NTFS) el bind-mount del código
cruza la frontera WSL↔Windows y es más lento para PHP (miles de archivos). Mitigaciones ya incluidas:

- **`vendor/` y `node_modules/` ya son volúmenes nombrados** → el hot-path pesado NO cruza NTFS.
- **OPcache afinado** (`.docker/php/php.windows.ini`, vía el override `docker-compose.windows.yml`): elimina
  el `stat()` por archivo en cada request (el verdadero cuello de botella), con file-cache en RAM.
- **VirtioFS** (ajuste de Docker Desktop, abajo): el compartir archivos más rápido que el 9p viejo.

---

## Pre-requisitos (una sola vez)

1. **Docker Desktop** instalado y corriendo, con **WSL 2 engine** (Settings → General → "Use the WSL 2
   based engine") y la **integración WSL activada** para tu distro (Settings → Resources → WSL integration).
2. **VirtioFS activado**: Settings → Resources → File sharing → **VirtioFS** (más rápido que gRPC/9p).
3. **PowerShell** (viene con Windows) y **Git para Windows** (para la verificación con `git status`).

Averiguá el nombre de tu distro WSL (lo vas a necesitar para la ruta `\\wsl$`):

```powershell
wsl -l -q
```

## Resumen: lo hace el script

`scripts/windows/migrate.ps1` corre las fases 0 a 5 en orden y termina con código ≠ 0 si la
verificación encuentra algo raro:

```powershell
# En PowerShell. Con el stack del ORIGEN (WSL) todavía ARRIBA: el script hace el backup y lo apaga él.
powershell -ExecutionPolicy Bypass -File \\wsl$\<DISTRO>\dismac\logistics\scripts\windows\migrate.ps1 -Distro <DISTRO>
```

Parámetros útiles:

| Parámetro | Default | Para qué |
|---|---|---|
| `-Dest` | `$HOME\Documents\APP\logistics` | Carpeta del proyecto en Windows. |
| `-BackupDir` | `$HOME\Documents\backups` | Dónde queda el bundle de la base (FUERA del proyecto). |
| `-LinuxPath` | `/dismac/logistics` | Ruta del proyecto dentro de la distro. |
| `-SkipCopy` | — | Ya copiaste: solo override + levantar + verificar. |
| `-SkipBackup -Bundle <tar>` | — | Usar un bundle hecho a mano (sigue verificando contra él). |
| `-CopyOnly -Source <dir> -Dest <dir>` | — | Solo la copia (robocopy), para probar las exclusiones. |

Las fases de abajo explican qué hace el script y cómo hacerlo a mano.

---

## Fase 0 — Backup de la base (stack del origen ARRIBA)

```bash
# En la terminal WSL, dentro del proyecto actual:
docker compose up -d                       # el stack tiene que estar arriba
docker compose exec -T app php artisan backup:create \
    --path=/var/www/html/storage/app/backups/premigracion-logistics-<ts>.tar
```

Copiá el bundle **fuera del proyecto** y comprobá que la copia sea idéntica:

```powershell
New-Item -ItemType Directory -Force "$HOME\Documents\backups" | Out-Null
$src = "\\wsl$\<DISTRO>\dismac\logistics\storage\app\backups\premigracion-logistics-<ts>.tar"
Copy-Item $src "$HOME\Documents\backups\"
Get-FileHash $src, "$HOME\Documents\backups\premigracion-logistics-<ts>.tar"   # los dos hashes iguales
```

`storage/app/backups` NO viaja con el proyecto (Fase 2): el bundle viaja solo por `-BackupDir`.

## Fase 1 — Apagar el stack del origen SIN borrar volúmenes

```bash
# En WSL:
docker compose down        # ⚠️ NUNCA uses `-v`: eso borraría la base de datos.
```

## Fase 2 — Copiar el proyecto a C:\Users\<usuario>\Documents\APP\logistics

robocopy `/XD` acepta **nombres** (excluyen una carpeta con ese nombre a CUALQUIER profundidad) o
**rutas completas** (excluyen SOLO esa carpeta). Usar siempre rutas completas ancladas al origen:

```powershell
$S = "\\wsl$\<DISTRO>\dismac\logistics"
$D = "$HOME\Documents\APP\logistics"
robocopy $S $D /E /COPY:DAT /DCOPY:DAT /R:1 /W:1 `
  /XD "$S\vendor" "$S\node_modules" "$S\storage\app\backups" `
      "$S\.docker\osrm\data" "$S\.docker\nominatim\data" "$S\.docker\tileserver\data" `
  /XF "*.log"
# De los dirs de datos geo, solo su .gitignore versionado (los datos pesados no):
foreach ($g in '.docker\osrm\data', '.docker\nominatim\data', '.docker\tileserver\data') {
  robocopy "$S\$g" "$D\$g" .gitignore /COPY:DAT /R:1 /W:1
}
git -C $D config core.fileMode false    # NTFS no tiene bit de ejecución (ver "Trampas")
```

- `vendor` y `node_modules` **de la raíz** NO se copian: son volúmenes nombrados y se repueblan solos.
  `public/vendor/leaflet` y `public/vendor/leaflet-geoman` (versionados) **sí** se copian.
- **`.git` SÍ se copia**: sin él no se puede comprobar que no falte ningún archivo versionado, se pierde
  la rama y los cambios sin commitear quedan sin referencia.
- Los datos geo (`osrm/nominatim/tileserver`) son varios GB y opt-in (`--profile geo`): se regeneran con
  sus runbooks. De esas carpetas solo se copia el `.gitignore` (mantiene el dir para el bind-mount).
- `/XF "*.log"` sí es por nombre (robocopy no acepta comodines con ruta en `/XF`); no hay ningún `.log`
  versionado.
- El `.env` SÍ se copia (robocopy copia dotfiles).

## Fase 3 — Activar el override de Windows (perf)

En `C:\Users\<usuario>\Documents\APP\logistics\.env`, agregá estas líneas (el script lo hace solo):

```env
COMPOSE_PATH_SEPARATOR=:
COMPOSE_FILE=docker-compose.yml:.docker/compose/docker-compose.windows.yml
```

`COMPOSE_PATH_SEPARATOR` es obligatorio: en Windows el separador por defecto de Compose es `;`, y sin
esa línea `docker compose` falla con "The filename, directory name, or volume label syntax is incorrect".

Así cada `docker compose ...` aplica el PHP afinado para NTFS automáticamente.

## Fase 4 — Levantar desde la nueva ubicación

```powershell
cd $HOME\Documents\APP\logistics
docker compose up -d --build
```

Como el nombre del proyecto (`dismac-logistics`) y la red (`dismac_lg_network`) están fijos, Compose
**reutiliza los volúmenes existentes**: la base de datos aparece intacta. El entrypoint corre
`composer install` y repuebla el volumen `vendor` (rápido, ya estaba poblado); el servicio `assets`
recompila el tema.

## Fase 5 — Verificar (cero pérdida)

El script lo hace solo y falla si algo no cuadra. A mano:

```powershell
docker compose ps                                  # todo "healthy"
$B = "premigracion-logistics-<ts>.tar"

# (a) El bundle es íntegro: lo restaura en una base scratch y lo compara con su manifest (no destructivo).
Copy-Item "$HOME\Documents\backups\$B" storage\app\backups\
docker compose exec -T app php artisan backup:verify /var/www/html/storage/app/backups/$B

# (b) La base del destino tiene todo lo del backup: conteos exactos por tabla vs. el manifest.
tar.exe -xOf "$HOME\Documents\backups\$B" manifest.json
docker compose exec -T postgres psql -U logistics -d logistics -c "SELECT count(*) FROM shipments"

# (c) No falta ningún archivo versionado:
git status --short | Select-String '^( D|D )'      # debe salir vacío
```

En (b), una tabla **faltante** o con **menos** filas que el manifest es pérdida de datos (falla). Con
**más** filas es actividad posterior al backup (workers/scheduler arrancan enseguida): se avisa, no falla.

Si (c) lista archivos borrados, se recuperan con `git checkout -- <ruta>` (por eso hay que copiar `.git`).

Prueba de rendimiento (la 2ª corrida, con OPcache caliente, debe ser notablemente más rápida):

```powershell
Measure-Command { curl.exe -s -o NUL http://localhost:8110/admin/login }
Measure-Command { curl.exe -s -o NUL http://localhost:8110/admin/login }
```

## Fase 6 — Restore (SOLO si algo salió mal: base vacía o incompleta)

`backup:restore` restaura por **swap**: la base actual queda renombrada como `logistics_prev_<ts>` (no se
borra), así un restore equivocado también se puede deshacer.

```powershell
Copy-Item "$HOME\Documents\backups\$B" storage\app\backups\
docker compose exec -T app php artisan backup:verify  /var/www/html/storage/app/backups/$B
docker compose exec -T app php artisan backup:restore /var/www/html/storage/app/backups/$B --force
```

## Limpieza (opcional, al final y ya verificado)

Cuando todo funcione desde `C:\...\APP\logistics` **y la Fase 5 pasó**, podés borrar la copia vieja en
WSL (`/dismac/logistics`). **No borres los volúmenes de Docker**: son los que tienen tus datos y los
seguís usando desde la nueva carpeta. Conservá el bundle de `-BackupDir` al menos unos días.

---

## Trampas que ya pasaron

- **robocopy `/XD` por nombre**: `/XD vendor node_modules .git` excluye CUALQUIER carpeta con ese nombre
  a cualquier profundidad. Así se perdieron `public/vendor/leaflet` y `public/vendor/leaflet-geoman`
  (versionados, los usa el mapa en vivo) y no se copió `.git`. Además, una ruta RELATIVA con `\` en `/XD`
  (`.docker\osrm\data`) no matchea nada. Regla: **en `/XD` solo rutas completas ancladas al origen**.
- **`.gitignore` de los dirs de datos geo**: `.docker/{osrm,nominatim,tileserver}/data/.gitignore` son
  versionados (mantienen el dir para el bind-mount). Excluir el dir entero los pierde: se copian aparte.
- **`COMPOSE_FILE` con `:` en Windows**: el separador por defecto de Compose en Windows es `;`. Con
  `COMPOSE_FILE=a.yml:b.yml` y sin `COMPOSE_PATH_SEPARATOR=:` en el `.env`, todo `docker compose` falla
  con "The filename, directory name, or volume label syntax is incorrect". Ya arreglado (el script lo
  agrega).
- **NTFS sin bit de ejecución**: los scripts versionados como `100755` aparecen modificados
  (`old mode 100755 / new mode 100644`) en `git status`. Solución: `git config core.fileMode false` en el
  clon de Windows (el script lo hace).
- **Encoding del `.ps1`**: Windows PowerShell 5.1 lee un `.ps1` UTF-8 **sin BOM** como ANSI; un `—`
  dentro de un string se decodifica a un byte que PowerShell toma como comilla y rompe el parseo.
  `migrate.ps1` se guarda en UTF-8 **con BOM**.
- **Backup programado vacío sin aviso** (`pg-backup`): dejaba `.dump.tmp` de 0 bytes cuando una corrida
  fallaba o se interrumpía, y el nombre del archivo iba en UTC. Ya corregido (ver
  `.docker/backups/README.md`): valida el dump, borra el `.tmp`, usa `TZ=America/La_Paz`, deja
  `last-success` y el servicio queda `unhealthy` si no hay backup OK en 26 h.

## Notas de rendimiento

- Si querés que los cambios de código se reflejen al instante (en vez de ≤5 s), bajá
  `opcache.revalidate_freq` a `0` en `.docker/php/php.windows.ini` (a costa de algo de velocidad).
- Para depurar con Xdebug, poné `xdebug.mode = develop,debug` en ese mismo archivo y reiniciá `app`
  (`docker compose restart app`). Dejalo en `off` para el día a día: Xdebug penaliza mucho.
- El máximo rendimiento sigue siendo con el código en el ext4 de WSL; esta configuración es para acercarse
  lo más posible cuando el código vive en NTFS por decisión de ubicación.
