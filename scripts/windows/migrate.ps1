#Requires -Version 5.1
<#
.SYNOPSIS
    Migra el proyecto logistics desde el filesystem de WSL a C:\Users\<usuario>\Documents\APP\logistics
    (Docker Desktop) sin perder datos ni archivos, lo levanta con el tuning de Windows y VERIFICA el
    resultado.

.DESCRIPTION
    Fases (ver docs/migracion-windows.md):
      1. Backup de la BASE en el origen, con el stack todavía arriba: `php artisan backup:create`
         (estructura completa + datos + manifest con conteos por tabla). El bundle se copia FUERA del
         proyecto (-BackupDir) y se comprueba que la copia sea idéntica (SHA-256).
      2. Apaga el stack del origen con `docker compose down` (NUNCA -v: los volúmenes son los datos).
      3. Copia el código con robocopy. Las exclusiones son RUTAS ABSOLUTAS ancladas al origen, no
         nombres: `/XD vendor` a secas excluye CUALQUIER carpeta llamada vendor a cualquier
         profundidad (así se perdieron public/vendor/leaflet y public/vendor/leaflet-geoman).
         `.git` SÍ se copia. De los dirs de datos geo solo se copia su `.gitignore` versionado.
      4. Activa el override de Windows (COMPOSE_PATH_SEPARATOR + COMPOSE_FILE en .env) y
         `git config core.fileMode false` (NTFS no tiene bit de ejecución).
      5. Levanta el stack desde el destino (reutiliza los volúmenes: misma base).
      6. Verificación (falla con código != 0 si algo no cuadra):
         a. `php artisan backup:verify` del bundle (restaura en base scratch y compara con el manifest).
         b. Conteo exacto de filas de la base del destino vs. el manifest del bundle.
         c. `git status` en el destino: ningún archivo versionado puede faltar.

    El backup es SOLO de la base de datos (estructura + datos). No respalda contenedores, imágenes,
    volúmenes ni archivos: la base vive en el volumen `dismac-logistics_postgres_data`, que la
    migración no toca; el bundle es la red de seguridad por si algo sale mal.

.PARAMETER Distro
    Nombre de la distro WSL (ver `wsl -l -q`). Si se omite, toma la primera.

.PARAMETER LinuxPath
    Ruta del proyecto dentro de la distro. Default: /dismac/logistics

.PARAMETER Source
    Ruta origen en formato Windows. Default: \\wsl$\<Distro><LinuxPath>. Útil para probar la copia.

.PARAMETER Dest
    Carpeta destino en Windows (la carpeta DEL PROYECTO). Default: $HOME\Documents\APP\logistics

.PARAMETER BackupDir
    Carpeta FUERA del proyecto donde queda el bundle de la base. Default: $HOME\Documents\backups

.PARAMETER SkipBackup
    No generar el backup de la base (NO recomendado; solo si ya lo hiciste a mano y lo pasás con -Bundle).

.PARAMETER Bundle
    Bundle ya existente (ruta Windows) a usar en la verificación cuando se pasa -SkipBackup.

.PARAMETER SkipCopy
    No copiar; solo (re)activar el override, levantar y verificar (útil si ya copiaste).

.PARAMETER CopyOnly
    Solo la fase de copia (robocopy) de -Source a -Dest. Sirve para probar las exclusiones.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\migrate.ps1 -Distro Ubuntu

.EXAMPLE
    # Probar solo las exclusiones de robocopy contra un árbol de prueba:
    powershell -ExecutionPolicy Bypass -File .\migrate.ps1 -CopyOnly -Source C:\tmp\src -Dest C:\tmp\dst
#>
[CmdletBinding()]
param(
    [string]$Distro,
    [string]$LinuxPath = '/dismac/logistics',
    [string]$Source,
    [string]$Dest = (Join-Path $HOME 'Documents\APP\logistics'),
    [string]$BackupDir = (Join-Path $HOME 'Documents\backups'),
    [switch]$SkipBackup,
    [string]$Bundle,
    [switch]$SkipCopy,
    [switch]$CopyOnly
)

$ErrorActionPreference = 'Stop'

function Info($m) { Write-Host "[migrate] $m" -ForegroundColor Cyan }
function Ok($m)   { Write-Host "[migrate] $m" -ForegroundColor Green }
function Warn($m) { Write-Host "[migrate] $m" -ForegroundColor Yellow }
function Fail($m) { Write-Host "[migrate] $m" -ForegroundColor Red }

# Dirs de datos geo: pesados, opt-in (--profile geo) y regenerables con sus runbooks. De cada uno se
# copia SOLO su .gitignore (versionado: mantiene el dir para el bind-mount en un clon limpio).
$GeoDataDirs = @('.docker\osrm\data', '.docker\nominatim\data', '.docker\tileserver\data')

# Corre un comando dentro de la distro WSL, parado en el proyecto origen.
function Invoke-Wsl {
    param([Parameter(Mandatory)][string[]]$Command)
    & wsl.exe -d $Distro --cd $LinuxPath -- @Command
    if ($LASTEXITCODE -ne 0) { throw "Falló en WSL ($LASTEXITCODE): $($Command -join ' ')" }
}

# Copia el proyecto de -Source a -Dest. Las exclusiones son rutas ABSOLUTAS del origen: robocopy
# /XD acepta nombres (que matchean a CUALQUIER profundidad) o rutas completas (que matchean SOLO esa
# carpeta). Se usan siempre rutas completas.
function Copy-Project {
    param([Parameter(Mandatory)][string]$From, [Parameter(Mandatory)][string]$To)

    $excludeDirs = @(
        (Join-Path $From 'vendor'),                 # volumen nombrado; lo repuebla composer
        (Join-Path $From 'node_modules'),           # volumen nombrado; lo repuebla npm
        (Join-Path $From 'storage\app\backups')     # dumps viejos; el bundle nuevo va a -BackupDir
    ) + ($GeoDataDirs | ForEach-Object { Join-Path $From $_ })

    # /XF '*.log' SÍ es por nombre (robocopy no acepta comodines con ruta en /XF): no hay ningún .log
    # versionado (git ls-files '*.log' vacío) y el chequeo de git status final lo detectaría.
    Info 'Copiando el proyecto (robocopy, incluye .git)...'
    $roboArgs = @($From, $To, '/E', '/COPY:DAT', '/DCOPY:DAT', '/R:1', '/W:1', '/NFL', '/NDL', '/NP',
                  '/XD') + $excludeDirs + @('/XF', '*.log')
    & robocopy.exe @roboArgs | Out-Null
    # robocopy: exit 0-7 = éxito; 8+ = error.
    if ($LASTEXITCODE -ge 8) { throw "robocopy falló con código $LASTEXITCODE." }
    Ok "Copia principal completa (código robocopy $LASTEXITCODE)."

    # De los dirs geo, solo el .gitignore versionado.
    foreach ($geo in $GeoDataDirs) {
        $srcGeo = Join-Path $From $geo
        if (Test-Path (Join-Path $srcGeo '.gitignore')) {
            & robocopy.exe $srcGeo (Join-Path $To $geo) '.gitignore' '/COPY:DAT' '/R:1' '/W:1' '/NFL' '/NDL' '/NP' | Out-Null
            if ($LASTEXITCODE -ge 8) { throw "robocopy de $geo\.gitignore falló con código $LASTEXITCODE." }
        }
    }
    Ok 'Copiados los .gitignore de los dirs de datos geo (sin los datos pesados).'
}

# Conteo exacto de filas por tabla de la base del destino (vía el contenedor postgres).
function Get-LiveRowCounts {
    $sql = "SELECT table_schema || '.' || table_name, (xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM %I.%I', table_schema, table_name), false, true, '')))[1]::text FROM information_schema.tables WHERE table_type = 'BASE TABLE' AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY 1"
    $lines = docker compose exec -T postgres psql -U logistics -d logistics -At -F '|' -c $sql
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron contar las filas de la base del destino.' }
    $counts = @{}
    foreach ($l in $lines) {
        $p = $l -split '\|', 2
        if ($p.Count -eq 2) { $counts[($p[0] -replace '^public\.', '')] = [long]$p[1] }
    }
    return $counts
}

# Lee los conteos por tabla del manifest.json del bundle (`database.row_counts`: { "public.tabla": n }).
# Tolera variantes (`row_counts`/`tables`/`counts` en la raíz o en `database`; objeto nombre→n,
# nombre→{rows|count} o lista de {table|name, rows|count}). Ignora listas de nombres (p. ej. el inventario).
function Get-ManifestRowCounts {
    param([Parameter(Mandatory)][string]$BundlePath)
    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("migrate-manifest-" + [guid]::NewGuid())
    New-Item -ItemType Directory -Force $tmp | Out-Null
    try {
        & tar.exe -xf $BundlePath -C $tmp manifest.json
        if ($LASTEXITCODE -ne 0) { throw "No se pudo extraer manifest.json de $BundlePath." }
        $m = Get-Content (Join-Path $tmp 'manifest.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    } finally {
        Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
    }
    $node = $null
    foreach ($k in @('row_counts', 'counts', 'tables')) {
        if ($m.PSObject.Properties['database'] -and $m.database.PSObject.Properties[$k]) { $node = $m.database.$k; break }
        if ($m.PSObject.Properties[$k]) { $node = $m.$k; break }
    }
    if ($null -eq $node) { return $null }

    $counts = @{}
    $toN = {
        param($v)
        if ($v -is [System.Management.Automation.PSCustomObject]) {
            foreach ($f in @('rows', 'count', 'row_count')) { if ($v.PSObject.Properties[$f]) { return [long]$v.$f } }
            return $null
        }
        if ("$v" -match '^\d+$') { return [long]$v }
        return $null
    }
    if ($node -is [System.Array]) {
        foreach ($it in $node) {
            $name = $null
            foreach ($f in @('table', 'name')) { if ($it.PSObject.Properties[$f]) { $name = $it.$f; break } }
            $n = & $toN $it
            if ($name -and $null -ne $n) { $counts[($name -replace '^public\.', '')] = $n }
        }
    } else {
        foreach ($prop in $node.PSObject.Properties) {
            $n = & $toN $prop.Value
            if ($null -ne $n) { $counts[($prop.Name -replace '^public\.', '')] = $n }
        }
    }
    return $counts
}

# --- 0. Resolver origen ---
if (-not $Source) {
    if (-not $Distro) {
        $Distro = (wsl.exe -l -q | Where-Object { $_ -and $_.Trim() } | Select-Object -First 1).Trim()
        Warn "Distro no indicada; uso la primera: '$Distro' (si no es la correcta, pasá -Distro <nombre>)."
    }
    $Source = "\\wsl`$\$Distro" + ($LinuxPath -replace '/', '\')
}
if (-not (Test-Path $Source)) {
    throw "No existe el origen '$Source'. Verificá el nombre de la distro con: wsl -l -q"
}
Info "Origen:  $Source"
Info "Destino: $Dest"

if ($CopyOnly) {
    Copy-Project -From $Source -To $Dest
    Ok 'CopyOnly: listo (no se tocó Docker ni la base).'
    exit 0
}

# --- 1. Backup de la BASE en el origen (stack arriba) y copia FUERA del proyecto ---
$bundleOutside = $null
if (-not $SkipBackup) {
    if (-not $Distro) { throw 'El backup en el origen necesita -Distro (se corre docker compose dentro de WSL).' }
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $bundleName = "premigracion-logistics-$stamp.tar"
    Info "Backup de la base en el origen: php artisan backup:create -> $bundleName"
    Invoke-Wsl @('docker', 'compose', 'exec', '-T', 'app', 'php', 'artisan', 'backup:create',
                 "--path=/var/www/html/storage/app/backups/$bundleName")
    $bundleInSource = Join-Path $Source "storage\app\backups\$bundleName"
    if (-not (Test-Path $bundleInSource)) { throw "backup:create no dejó el bundle en $bundleInSource." }

    New-Item -ItemType Directory -Force $BackupDir | Out-Null
    $bundleOutside = Join-Path $BackupDir $bundleName
    Copy-Item $bundleInSource $bundleOutside
    $h1 = (Get-FileHash $bundleInSource -Algorithm SHA256).Hash
    $h2 = (Get-FileHash $bundleOutside -Algorithm SHA256).Hash
    if ($h1 -ne $h2) { throw "La copia del bundle a $BackupDir no es idéntica (SHA-256 distinto)." }
    Ok "Bundle de la base a salvo fuera del proyecto: $bundleOutside ($h2)"
} elseif ($Bundle) {
    if (-not (Test-Path $Bundle)) { throw "No existe el bundle indicado: $Bundle" }
    $bundleOutside = (Resolve-Path $Bundle).Path
    Warn "SkipBackup: uso el bundle existente $bundleOutside para verificar."
} else {
    Warn 'SkipBackup sin -Bundle: NO hay red de seguridad de la base ni verificación contra manifest.'
}

# --- 2. Apagar el stack del origen (SIN -v) ---
if (-not $SkipCopy -and $Distro) {
    Info 'Apagando el stack del origen (docker compose down, SIN -v: los volúmenes quedan)...'
    Invoke-Wsl @('docker', 'compose', 'down')
}

# --- 3. Copiar ---
if (-not $SkipCopy) {
    Copy-Project -From $Source -To $Dest
} else {
    Warn 'SkipCopy: no se copió nada.'
}

# --- 4. Override de Windows en el .env + fileMode de git ---
$envPath = Join-Path $Dest '.env'
if (-not (Test-Path $envPath)) { throw "No se encontró $envPath (¿se copió el .env?)." }
$composeLine = 'COMPOSE_FILE=docker-compose.yml:.docker/compose/docker-compose.windows.yml'
# En Windows el separador por defecto de Compose es ';': sin esto, el ':' de COMPOSE_FILE rompe todo comando.
$separatorLine = 'COMPOSE_PATH_SEPARATOR=:'
$envContent = Get-Content $envPath -Raw
if ($envContent -notmatch '(?m)^\s*COMPOSE_PATH_SEPARATOR=') {
    Add-Content -Path $envPath -Value "`r`n$separatorLine"
    Ok 'Agregué COMPOSE_PATH_SEPARATOR al .env.'
}
if ($envContent -notmatch '(?m)^\s*COMPOSE_FILE=') {
    Add-Content -Path $envPath -Value "`r`n# Override de rendimiento para Windows (ver docs/migracion-windows.md)`r`n$composeLine"
    Ok 'Agregué COMPOSE_FILE al .env (override de Windows activado).'
} else {
    Warn 'Ya existe una línea COMPOSE_FILE en el .env; no la toco. Verificá que incluya el override de Windows:'
    Warn "  $composeLine"
}
if (Test-Path (Join-Path $Dest '.git')) {
    # NTFS no guarda el bit de ejecución: sin esto TODOS los scripts aparecen como modificados (755 -> 644).
    & git -C $Dest config core.fileMode false
    Ok 'git config core.fileMode false en el destino.'
}

# --- 5. Levantar el stack desde el destino ---
$problems = New-Object System.Collections.Generic.List[string]
Push-Location $Dest
try {
    Info 'Levantando el stack (docker compose up -d --build)...'
    docker compose up -d --build
    if ($LASTEXITCODE -ne 0) { throw "docker compose up falló (código $LASTEXITCODE). ¿Docker Desktop está corriendo?" }

    Info 'Esperando a PostgreSQL...'
    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        docker compose exec -T postgres pg_isready -U logistics -d logistics | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 2
    }
    if (-not $ready) { throw 'PostgreSQL no quedó listo en 2 minutos.' }
    docker compose ps

    # --- 6a. backup:verify del bundle (no destructivo: restaura en una base scratch) ---
    if ($bundleOutside) {
        $bundleName = Split-Path $bundleOutside -Leaf
        $inDest = Join-Path $Dest "storage\app\backups\$bundleName"
        New-Item -ItemType Directory -Force (Split-Path $inDest) | Out-Null
        Copy-Item $bundleOutside $inDest -Force
        Info "Verificando el bundle: php artisan backup:verify $bundleName"
        docker compose exec -T app php artisan backup:verify "/var/www/html/storage/app/backups/$bundleName"
        if ($LASTEXITCODE -ne 0) {
            $problems.Add("backup:verify falló (código $LASTEXITCODE): el bundle no coincide con su manifest.")
        } else {
            Ok 'backup:verify OK.'
        }

        # --- 6b. Base del destino vs. manifest del bundle ---
        Info 'Comparando conteos de filas de la base del destino contra el manifest...'
        $expected = Get-ManifestRowCounts -BundlePath $bundleOutside
        if ($null -eq $expected -or $expected.Count -eq 0) {
            $problems.Add('El manifest no trae conteos por tabla reconocibles: no se pudo comparar la base del destino.')
        } else {
            $live = Get-LiveRowCounts
            $missing = 0; $fewer = 0; $more = 0
            foreach ($t in ($expected.Keys | Sort-Object)) {
                if (-not $live.ContainsKey($t)) {
                    Fail "  FALTA la tabla $t (manifest: $($expected[$t]) filas)"; $missing++
                } elseif ($live[$t] -lt $expected[$t]) {
                    Fail "  $t : destino $($live[$t]) < manifest $($expected[$t])"; $fewer++
                } elseif ($live[$t] -gt $expected[$t]) {
                    # Los workers/scheduler pueden escribir apenas el stack arranca (outbox, métricas).
                    Warn "  $t : destino $($live[$t]) > manifest $($expected[$t]) (actividad posterior al backup)"; $more++
                }
            }
            Info "  Tablas en el manifest: $($expected.Count) | en el destino: $($live.Count) | faltantes: $missing | con menos filas: $fewer | con más filas: $more"
            if ($missing -gt 0 -or $fewer -gt 0) {
                $problems.Add("La base del destino perdió datos respecto del backup ($missing tablas faltantes, $fewer con menos filas).")
            } else {
                Ok 'La base del destino tiene todas las tablas y filas del backup.'
            }
        }
    } else {
        Warn 'Sin bundle: se omite backup:verify y la comparación contra el manifest.'
    }

    # --- 6c. git status: ningún archivo versionado puede faltar ---
    if (Test-Path (Join-Path $Dest '.git')) {
        Info 'Revisando git status en el destino...'
        $status = & git -C $Dest status --porcelain
        $deleted = @($status | Where-Object { $_ -match '^( D|D |AD|MD)' })
        $modified = @($status | Where-Object { $_ -match '^( M|M |MM)' })
        Info "  Versionados borrados: $($deleted.Count) | modificados: $($modified.Count) (cambios sin commitear del origen cuentan acá)"
        if ($deleted.Count -gt 0) {
            $deleted | Select-Object -First 50 | ForEach-Object { Fail "  $_" }
            $problems.Add("Faltan $($deleted.Count) archivos versionados en el destino (ver lista; recuperables con git checkout -- <ruta>).")
        }
    } else {
        $problems.Add('No hay .git en el destino: no se puede comprobar que no falten archivos versionados.')
    }
} finally {
    Pop-Location
}

if ($problems.Count -gt 0) {
    Fail 'La migración terminó CON PROBLEMAS:'
    $problems | ForEach-Object { Fail "  - $_" }
    Fail 'Si la base aparece vacía o incompleta, restaurá el bundle (Fase 6 del runbook).'
    exit 1
}
Ok 'Migración verificada: bundle OK, base completa y ningún archivo versionado faltante.'
Ok 'Abrí http://localhost:8110 para comprobar el admin.'
