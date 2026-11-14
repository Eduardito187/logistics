---
name: ci-quality-gates
description: >-
  Pasar los gates de CI del PR en UN lote, sin el loop "pushear → falla un gate → arreglar uno →
  repetir": Quality Gate de SonarCloud (Security/Reliability/Duplication on New Code), `composer audit`,
  PHPStan nivel 8, Pint y Pest. Usar cuando CI falle con SonarCloud/SonarQube Cloud, "Quality Gate
  failed", "composer audit", advisories de seguridad, o se pida arreglar los errores/warnings del PR.
---

# CI Quality Gates (Dismac logistics)

Objetivo: cerrar el PR en verde **en una sola pasada**. El loop histórico viene de arreglar issues de
ruido que **no** bloquean el gate, y de adivinar cuál bloquea. No adivinar: **consultar la API de Sonar**.

## Paso 0 — ¿El fix llegó al CI? (el CI corre el commit PUSHEADO, no tu working tree)

Antes de re-diagnosticar nada: **si el dueño pega el MISMO error de CI byte-por-byte igual tras un "fix", el
fix casi seguro NO está commiteado/pusheado.** El CI buildea el commit del remoto, no tu working tree. Chequear:
`git status --short` (¿el archivo sale como `M`/`??`?) y `git show HEAD:<archivo> | grep <lo-que-arreglaste>`
(¿está en HEAD?). Si el fix está solo en el working tree, decirle que falta `git add/commit/push` (por la regla
de no commitear sin pedido explícito no lo hago solo; ofrecer hacerlo si lo autoriza en el turno). Caso real
2026-11-13: `composer.lock` arreglado pero sin commitear → el dueño pegó el error idéntico dos veces.

## Paso 0b — `composer install` FATAL: package nuevo ausente del lock

`Error: Required package "logistics/<ctx>" is not present in the lock file.` (exit 4) = un package nuevo quedó
en `require` del `composer.json` pero **`composer.lock` no se regeneró**. NO es un bug de código. Fix normal
(dueño): `composer update logistics/<ctx>`. Fix a mano sin docker: insertar la entrada en el array `packages`
del lock espejando una entrada `path` existente (molde `logistics/carriers`), en orden alfabético; validar con
`python -c "json.load(...)"`. El `content-hash` desfasado solo da el warning cosmético *"lock file is not up to
date"* (NO frena el install). Detalle: [[new-package-composer-lock]], [[bounded-context]] paso 5.

## Regla de oro: el Quality Gate SOLO falla por 3 cosas

En **New Code** (el diff del PR), el gate falla por:

1. **Security Rating < A** — hay ≥1 *vulnerability*.
2. **Reliability Rating < A** — hay ≥1 *bug*.
3. **Duplication on New Code > 3%**.

**TODO lo demás NO bloquea el gate** y NO hay que tocarlo por riesgo: TODO comments, "too many
parameters/methods", "N returns > 3", cognitive complexity, "immediate return", literal duplicado,
`!important` CSS, "unused private method", selector CSS duplicado, nested ternary. Son *maintainability*.
Refactorizarlos a ciegas (reducir complejidad/returns) mete bugs y **re-rompe** el gate. Ofrecer barrerlos
aparte solo si el usuario quiere el PR sin ningún annotation.

## Paso 1 — No adivinar: preguntar a la API (proyecto público, sin auth)

En este Windows NO hay PHP en el host (y el dueño prohíbe docker salvo pedido): parsear con **python**.

```bash
PK=Eduardito187_logistics ; PR=<numero-del-PR>
for T in VULNERABILITY BUG; do
  curl -s "https://sonarcloud.io/api/issues/search?componentKeys=$PK&pullRequest=$PR&types=$T&resolved=false&ps=100" \
  | python -c "
import json,sys;d=json.load(sys.stdin);print('$T',d.get('total'))
for i in d.get('issues',[]):print(' [%s] %s %s:%s\n   %s'%(i['severity'],i['rule'],i['component'].split(':',1)[1],i.get('line',''),i['message']))"
done
# Security Hotspots (también pueden bajar el Security Rating):
curl -s "https://sonarcloud.io/api/hotspots/search?projectKey=$PK&pullRequest=$PR&ps=100" | python -c "
import json,sys;d=json.load(sys.stdin);print('HOTSPOTS',d.get('paging',{}).get('total'))
for i in d.get('hotspots',[]):print(' %s %s:%s %s'%(i['vulnerabilityProbability'],i['component'].split(':',1)[1],i.get('line',''),i['message']))"
# Comprobar si una supresión surte efecto: &rules=php:S107
```

Caso real PR 47 (2026-11-12): la lista pegada tenía ~45 annotations, pero el gate D/C lo causaban SOLO 3:
`php:S4790` (`sha1()` en un ETag HTTP → `hash('sha256', …)`) y 2× `php:S836` (FP de data-flow en un test →
`// NOSONAR — <motivo>`). Arreglar esos 3 primero; el resto es mantenibilidad.

Esto da regla + archivo + línea + severidad. Es la **fuente autoritativa**; los annotations de GitHub
mezclan ruido y a veces están viejos.

## Paso 2 — Gotcha crítico: el Análisis Automático NO honra `sonar.issue.ignore`

**VERIFICADO (2026-11-09):** el Análisis Automático lee `.sonarcloud.properties` (NO
`sonar-project.properties`), pero **solo respeta `sonar.cpd.exclusions` (duplicación); NO respeta
`sonar.issue.ignore.multicriteria`** (p. ej. `php:S107` seguía abierto pese a su regla de exclusión).

Implicación: **una vulnerability/bug NO se quita suprimiéndola en el archivo.** Hay que:
- (i) cambiar el **código** para que la regla no dispare (ver recetas abajo), o
- (ii) marcarla en la **UI** de SonarCloud (Issues "Won't fix" / Security Hotspot "Safe") — requiere login
  del dueño; si es la única vía, decirlo claro.

Mantener igual las entradas `issue.ignore` existentes (documentan intención y sirven si algún día usan el
scanner de CI, que sí las honra).

**PERO `sonar.exclusions` (exclusión de ARCHIVOS del análisis) SÍ se honra** por el Análisis Automático
(verificado 2026-11-13, PR 75), igual que `sonar.cpd.exclusions`. Lo que NO se honra es solo
`sonar.issue.ignore.multicriteria` (supresión por regla). Implicación práctica: si una vulnerability/bug/
hotspot que baja el Rating cae en **código que no es de producción** (p. ej. `loadtest/**` = scripts k6 de
carga, que no se despliegan), la vía más limpia y que limpia TODO de una es excluir ese directorio del
análisis: `sonar.exclusions=<glob>`. Caso real PR 75: Security C (8× `javascript:S2245` Math.random de jitter +
`S5332` http al target) y Reliability D (`javascript:S2871` sort) estaban TODOS en `loadtest/`; con
`sonar.exclusions=loadtest/**` el gate vuelve a A/A sin tocar los scripts. NO excluir código de producción
así: ahí el fix va en el código.

## Paso 3 — Recetas por tipo (fix por código)

### Security (vulnerabilities)
- **S4790 hash débil** (`md5()`/`sha1()`), típico en una clave de caché → `hash('sha256', $x)`.
- **S5693 "content length limit"** en subida de archivo: dispara por el **string** de validación
  `['...,'file','image','max:N']` (NO por el valor — bajar `10240`→`8192` NO lo quita). Dodge con la
  regla-objeto fluida de Laravel `Illuminate\Validation\Rules\File`:
  ```php
  use Illuminate\Validation\Rules\File;
  'photo' => ['required', File::image()->max(8 * 1024)],   // imagen, 8 MB
  'file'  => ['required', File::default()->max(10 * 1024)], // archivo genérico, 10 MB
  ```
  Verificado: quita el patrón y los tests de subida/import siguen verdes. Aplicarlo a TODOS los sitios con
  ese patrón (`grep -rn "'file'" **/Http/Request **/Http/Controller`), no solo al del New Code.

- **docker:S6471 "image runs with root as the default user"** (PR 27, bajó el Security Rating a B): una
  imagen de servicio sin `USER`. Para nginx usar `nginxinc/nginx-unprivileged:<ver>-alpine` (uid 101,
  escucha 8080: reescribir `listen 80` → `8080` en el conf, healthcheck a `:8080`, el compose publica
  `<host>:8080`). Verificar: `docker inspect -f '{{.Config.User}}'` y `ps -o user,args` dentro del
  contenedor (el master también debe ser `nginx`).
- **"Make sure the copied resource cannot be modified by a non-root user"** (sale si se hace
  `COPY --chown=<usuario-del-servicio>`): NO darle los archivos al usuario que corre el proceso. Patrón:
  `USER root` → `COPY` sin `--chown` → `RUN` ajustes + `chown -R root:root` + dirs 755 / archivos 644 →
  `USER 101` al final. Probar que `uid 101` NO puede escribir (`touch` falla) y que igual sirve (200).

### Reliability (bugs) — en esta plataforma casi siempre son de accesibilidad en blades
- **S6853 input sin label** → agregar `id` + `aria-label` al `<input>`/`<textarea>`/`<select>`.
- **S1082 `<div>`/`<li>` con `@click` sin teclado** → agregar `@keydown.*` simétrico (`@keydown.escape`,
  `@keydown.stop`). Sonar reconoce la sintaxis Alpine `@click`/`@keydown`.
- **`<x-slot:head>` pide `<title>`** (FP: el analizador lo lee como `<head>`) → usar `<x-slot name="head">`.
- **FP de data-flow en test** ("use of uninitialized value" sobre `foreach`/clase anónima) → `// NOSONAR`
  con explicación en español en la misma línea.

### Duplication (CPD)
- Va en `sonar.cpd.exclusions` de `.sonarcloud.properties` (ESO sí se honra). Ya excluidos a propósito:
  `**/Presentation/**`, `app/Filament/**`, tests, seeders (duplicación intencional por ADR 001/008).
- **`lang/**` también va excluido (2026-11-13).** Un archivo de traducciones (p. ej. `lang/es/validation.php`,
  el de Laravel) es inherentemente repetitivo (mismas claves/estructura para cada regla) → disparó Duplication
  10.8% y tumbó el gate. Agregar `lang/**` a `sonar.cpd.exclusions`. No es deuda: la repetición es del formato.

### Mantenibilidad: recetas seguras cuando el dueño pide "fix all" (no bloquean el gate)

Cuando el dueño pide arreglar TODO, hacerlo, pero con cambios mecánicos y probados (tests del archivo):
- **"Define and throw a dedicated exception"** → excepción propia del módulo que EXTIENDE la genérica
  (`final class BackupException extends RuntimeException {}`): ningún `catch` existente se rompe.
- **"N returns > 3" / cognitive complexity** → extraer pasos a métodos privados que devuelvan `?valor`
  (null = error ya impreso) y dejar el método público como orquestador de ≤3 returns. Preservar mensajes
  y orden de las comprobaciones; correr los tests del comando.
- **Literal duplicado ×3** → constante de clase; en tests de Pest (scope global) `const` con prefijo
  ÚNICO por archivo (`BACKUP_CMD_TEST_DIR`) para no chocar con otros archivos; en SQL repetido, un closure
  que arme la cola común.
- **"Immediate return"** en `fetchAll` tipado → `return $stmt !== false ? array_values(array_filter($stmt->fetchAll(PDO::FETCH_NUM), is_array(...))) : [];`
- **Nested ternary** → `if (...) { return ...; }` + ternario simple.
- **Parámetro sin usar en closure** que exige una firma `callable(string)` → quitar el parámetro (PHP
  acepta closures con menos parámetros) y comentar por qué.
- **Bloque vacío `function (...) {}`** como logger no-op → propiedad `?Closure` + método `say()` que
  chequea null. OJO al reemplazar en masa `($this->log)(` por `$this->say(`: no tocar la línea DENTRO de
  `say()` (recursión infinita).
- **Shell**: `[` → `[[ … && … ]]` en scripts bash (no en `#!/bin/sh`); `case` sin `*)` → agregar
  `*) ;;` comentado; "assign positional parameter" → `local path="$1"`.
- **PowerShell complejidad** → partir funciones y eliminar ramas para formatos que ya no existen.

### Falsos positivos de texto
- Sonar marca la palabra española **"todo"** suelta como TODO → usar `cada`/`completo`/`íntegramente`.
  Pasa también en comentarios de TESTS (PR 27: "porque todo se filtraba por hoy").

### Bash del harness en Windows (al aplicar fixes)
La herramienta Bash de este entorno colapsa `\\` en `sed`/`perl` → un `use App\Support\...` sale como
`use AppSupport...`. Para imports/namespace con barras invertidas usar el editor (Edit), no sed. Después de
cualquier reemplazo masivo: `php -l` de cada archivo + PHPStan.

## Paso 3b — Pint SIN poder correr pint (arreglo a mano)

Cuando el dueño pega la salida de Pint (`FAIL … N style issues` + `⨯ archivo regla, regla…`) y no se puede
usar docker, arreglar a mano con las reglas REALES de `pint.json` (preset laravel + overrides). **Leer
`pint.json` y un archivo que pase en `origin/production` antes de dar reglas a agentes** — el 2026-11-12 se
dieron 3 reglas al revés y hubo que corregir a 4 agentes:

| Regla de Pint | Qué exige en ESTE repo |
|---|---|
| `phpdoc_separation` | `@param` y `@return` **juntos, SIN** línea ` *` entre ellos. Blanca entre descripción y tags, y entre grupos distintos (`@throws`, `@var`, `@template`). |
| `phpdoc_align` | Vertical: columna de tipo y de variable rellenadas al más ancho del grupo. Con `@return` en el grupo: `@param  tipo  $var` (2 espacios). `@property-read` NO entra en la alineación por defecto. Molde: `shipments/Infrastructure/ReadModel/DbShipmentListReader.php`. |
| `types_spaces: single` | Uniones en CÓDIGO con espacios: `): array \| Page` (en phpdoc NO). |
| `increment_style` | **Post**-incremento `$i++`. |
| `ordered_imports` alpha / `no_unused_imports` | `use` alfabético (case-insensitive, `\` cuenta como espacio); sin imports muertos. |
| `fully_qualified_strict_types` | Importar en vez de `\FQCN` en código; **también** `{@see \FQCN}` en docblocks dispara (escribir el nombre en texto plano). |
| `single_line_empty_body`, `braces_position` | `) {}` en la misma línea. A menudo Pint los lista como **efecto colateral** de otra regla del mismo archivo: si no se encuentra el problema, arreglar la otra regla y listo. |
| `binary_operator_spaces` | Un espacio alrededor de `=`/`=>`, sin padding de alineación. |
| `single_quote` | Comillas simples salvo interpolación. |

Verificación sin pint: script Python que reconstruye cada grupo de tags con la alineación esperada y compara
(lista de archivos = `git diff --name-only` + `git ls-files --others --exclude-standard`, solo `.php`), más
greps de `++$`/`--$` y de uniones sin espacios fuera de docblocks. Después el dueño corre `make pint-fix`,
que deja cualquier resto fino.

## Paso 4 — `composer audit` (job aparte del gate Sonar)

```bash
composer update <paquetes-afectados> --with-all-dependencies --no-interaction   # reescribe solo esos + deps
docker compose exec -T app composer install                                      # sincroniza el vendor del container
composer audit --locked                                                          # debe decir "No security vulnerability advisories found."
```
Laravel 13.x admite symfony 7.4 y 8 (`^7.4||^8.0`): el salto 7.4→8 es válido pero **verificar con la suite
completa** que no rompe.

## Paso 5 — DoD local antes de reportar (todo en Docker — SOLO si el dueño lo pide)

Regla del dueño ([[no-docker-unless-asked]]): no levantar docker ni correr estos comandos por iniciativa
propia; si no los pidió, entregarle esta lista de comandos para que la corra él.

```bash
docker compose exec -T app ./vendor/bin/pint <archivos>                      # PASS
docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G    # 0 errores (nivel 8)
docker compose exec -T app ./vendor/bin/pest                                 # exit 0, reportar N passed
```
No pipear `pest` a `tail -N` en background: trunca el resumen "Tests: N passed". Confiar en exit 0 + cero FAIL.

**Cobertura local: forzar pcov, NUNCA dejar que tome Xdebug (2026-11-13).** El contenedor `app` trae pcov
Y xdebug; por defecto `pest --coverage` usa **Xdebug**, que es ~10-20× más lento (la suite completa tardó
>20 min y no terminó; con `COLUMNS`/`tail` no da progreso intermedio, el archivo queda en 0 bytes hasta el
final). CI ya usa pcov (`shivammathur/setup-php` con `coverage: pcov` + `pcov.directory` en `ci.yml`). Para
replicarlo rápido local:
```bash
docker compose exec -T -e XDEBUG_MODE=off app php -d xdebug.mode=off -d pcov.enabled=1 \
  ./vendor/bin/pest --coverage --min=75
```
Para solo confirmar que la suite pasa (sin el gate de %), correrla **sin `--coverage`**: mucho más rápida y
suficiente para validar un fix puntual; el % lo valida CI. Si hay que matar un run colgado:
`docker compose exec -T app sh -c 'pkill -f "vendor/bin/pest"'` (el `TaskStop` del wrapper local NO mata el
proceso dentro del contenedor).

**NO usar `pest --parallel`.** Las bases paralelas `logistics_test_test_1..4` NO tienen PostGIS (las
migraciones no crean la extensión; solo el init de la imagen la puso en `logistics_test`) → ~1.000 fallas
falsas `type "geography" does not exist`. La suite válida es la serial (igual que CI): `./vendor/bin/pest`.

### Gotchas de Pest que rompen al escribir tests nuevos (arreglar a mano, sin adivinar)

- **`UniqueConstraintViolationException` en un campo único derivado de un UUID v7** (p. ej.
  `fleet_vehicles_plate_unique` al crear 2 vehículos en un test): el UUID v7 lleva el timestamp en el PREFIJO,
  así que `substr($id, 0, N)` colisiona entre entidades creadas en el mismo milisegundo. Usar la COLA
  aleatoria: `strtoupper(substr(str_replace('-', '', $id), -6))`. Vale para plate/external_id/cualquier
  derivado. (Ver [[testing-coverage-filament-gotchas]].)
- **Lecturas que degradan a `null`**: varias queries devuelven `null` en el caso legítimo "no hay nada"
  (p. ej. `GetDriverRoute` solo retorna rutas ABIERTAS → null si la ruta quedó sin paradas pendientes). El
  test debe tratar `null` como el caso esperado, no asumir el objeto (si no: "read property X on null").
- **`fulfillment_mode` y enums string en minúscula**: el valor canónico es minúscula (`owned`/`fbd`/`dropship`);
  sembrar `'OWNED'` en un test revienta con `ValueError ... not a valid backing value`. Sembrar en minúscula.
- **Floats por acumulación** (distancias): comparar con `toEqualWithDelta($esperado, 1.0)`, no `===`.
- **`assertSent`/payload `(object)`**: un payload enviado como `(object)` es stdClass, no array; castear
  `(array) ($body['payload'] ?? [])` antes de leerlo.
- **`->skip(config(...), 'motivo')` ROMPE con "Target class [config] does not exist"**: el argumento del
  `->skip()` se evalúa en tiempo de COLECCIÓN (app sin bootear), y `config()` necesita el container. Fix:
  pasar un CLOSURE que se evalúa al correr → `->skip(fn (): bool => config('x') !== 'y', 'motivo')`. OJO:
  `env(...)` en skip NO rompe (lee `$_ENV` sin container); solo `config()`/helpers que resuelven del container.
- **Pint `no_blank_lines_after_phpdoc`** en tests Pest: un docblock de archivo (`/** ... */`) colocado entre
  los `use` y el primer `it(`, con una línea en blanco antes del `it(`, se marca. Pint quita esa blanca
  (el docblock queda pegado al `it(`). Escribir el docblock del archivo SIN línea en blanco antes del primer
  `it(`, o como comentario `//`.
- **Un cambio de comportamiento INTENCIONAL rompe los tests que afirmaban el viejo — actualizarlos, no
  revertir (2026-11-13).** Cuando el dueño pide un cambio de regla (p. ej. POD sobre una entrega ya Delivered
  pasó de lanzar 422 a ser idempotente 200), los tests que esperaban el throw/422 van a FALLAR: eso es
  correcto, NO una regresión. Actualizar el test a la nueva conducta (y renombrar/reescribir el comentario
  que explica el porqué) en el MISMO cambio que el código. Confirmar que el único motivo del fallo es el
  cambio pedido (leer el diff del test), nunca "arreglar" el fallo volviendo el código atrás.
- **`WARN`/`risky` (`!` amarillo) de Pest = fallo del gate aunque el test "pase" (2026-11-13).** El job corre
  con `--fail-on-risky`, así que un test risky da exit ≠ 0 igual que un FAIL. El caso más común: Pest
  `->throwsNoExceptions()` **chocando con un `expect()` real** en el mismo test →
  *"This test is not expected to perform assertions but performed 1 assertion"*. `throwsNoExceptions()` declara
  que el test NO debe afirmar nada; si el cuerpo igual hace un `expect()`, es contradictorio. Fix: **quitar
  `->throwsNoExceptions()`** cuando hay una aserción real (esa aserción ya prueba el camino feliz, y si el SUT
  lanzara el test fallaría antes de llegar a ella). Dejar `throwsNoExceptions()` SOLO en tests sin ningún `expect`.
- **Leer el motivo COMPLETO de un risky/warn que Pest trunca** (sale `→ This t…`): correr con terminal ancho,
  `docker compose exec -T -e COLUMNS=400 app ./vendor/bin/pest <archivo> --filter="<nombre>"`. El `--log-junit`
  a `/tmp` a veces no escribe; `COLUMNS` es lo fiable.
- **Opciones de artisan en test llegan con su TIPO crudo, no como string (2026-11-13).** `$this->artisan('cmd',
  ['--drivers' => 2])` pasa el **int** `2`; por CLI la MISMA opción llega como **string** `'2'`. Un comando que
  lea la opción con `is_string($this->option($k)) ? ... : <default>` DESCARTA el int del test y usa el default
  → el test ve el comportamiento por defecto, no el pedido. Síntoma real (PR 75): el test pedía 2+1+1=4 usuarios
  pero contó 110 = exactamente los defaults 50+50+10. Fix en el COMANDO (no en el test): leer la opción cruda y
  aceptar escalar numérico, `$v = $this->option($k); is_numeric($v) ? (int) $v : $default;`. Regla general: un
  parser de opciones debe tolerar int Y string (programático vs CLI). Pista diagnóstica: si un conteo esperado
  sale igual a la suma de los defaults del `$signature`, las opciones no están llegando.

### Pint a mano (el dueño suele prohibir docker)

Reglas reales en [[pint-rules-by-hand]]. Las que más fallan al escribir docblocks: `phpdoc_align` es
**vertical** → con `@param` y `@return` en el mismo grupo, `@param` lleva **dos** espacios (`@param  tipo $var`)
para alinear la columna de tipo con `@return`, y la variable se alinea a la columna del tipo más ancho del
grupo. `@param`/`@return` van JUNTOS (sin línea ` *` en blanco entre ellos). Verificar contra un archivo de
`origin/production` que ya pase, columna por columna.

## Mapa de workflows (`.github/workflows/`)

| Workflow | Cuándo | Runner | Qué valida |
|---|---|---|---|
| `ci.yml` (jobs lint/test/assets/security/image) | PR y push a `dev`/`staging`/`production` | `ubuntu-latest` | Pint, PHPStan 8, Pest `--coverage --min=75`, build de assets, `composer audit` + `npm audit`, build de imagen `prod` |
| `ci.yml` job `staging-build` | PR → `staging` (solo ramas del repo, no forks) | self-hosted OCI | build de imágenes de staging (`scripts/staging/deploy.sh <env> build`) |
| `ci.yml` job `deploy-staging` | push a `staging` (solo ese ref), `needs` todos los anteriores | self-hosted OCI | build + migrate + up + smoke. Ver skill `staging-deploy` |
| `branch-policy.yml` | PR | `ubuntu-latest` | origen permitido (feature→dev→staging→production; hotfix/*) |

Si falla `staging-build` pero el resto del CI pasa, el problema está en el Dockerfile/compose de staging,
no en el código: reproducir con la skill `staging-deploy` (prueba local). Validar SIEMPRE los workflows
con actionlint antes de pushear (ver skill `staging-deploy`).

## Fin de línea (evita diffs fantasma)

`.gitattributes`: `* text=auto eol=lf` y `*.ps1 text eol=crlf`. Los `.sh` deben quedar LF (CRLF rompe el
`entrypoint.sh` en el contenedor: `bash\r`). En Windows `git config core.fileMode false` (NTFS sin bit de
ejecución → 755→644 falsos). Verificar: `git ls-files --eol <archivo>`.

## Gotchas PHPStan nivel 8 (recurrentes)
- `DB::table()->first()` → tipar el método como `?\stdClass` (acceso a props de stdClass exento). NO
  `@return object{...}` (error return.type) ni `@var` temporal (dispara "immediate return" de Sonar).
- `bindec()`/binario → castear `(int)`.
- **Método de repositorio cuyo resultado cambia entre llamadas con el MISMO argumento → `@phpstan-impure`
  (2026-11-13).** PHPStan 8 asume que una función pura con los mismos args devuelve lo mismo, así que
  "narrowea" tras un `if ($repo->findByX(...) === null)` y luego marca "always null"/"always true" en una
  segunda llamada. Si el estado puede cambiar entre llamadas (p. ej. el backfill crea el producto y luego
  `findByCanonicalSku` lo vuelve a buscar para publicarlo, o un repo con outbox), anotar el método del
  CONTRATO con `@phpstan-impure` y documentar el porqué. No usar `@var`/casts para tapar el falso narrowing.
- **El error de PHPStan "always null / Cannot access property on null" puede ser un fantasma de la caché de
  resultados.** Antes de reescribir la lógica por un error que contradice lo que ves (p. ej. "never null" en
  una propiedad que el código deja como null-safe), confiar en el ÚLTIMO error tras limpiar la caché; no
  oscilar entre `?->x` y `->x` según errores de corridas distintas.
- **`Cannot access property $x on T|null`: respetar el TIPO del getter, no el comentario de invariante
  (2026-11-13).** Un getter tipado `?Odometer` accedido con `->km` falla aunque un comentario diga "una salida
  abierta SIEMPRE lleva su odómetro". Fix: `?->` + guard (`$v = $x->getter()?->prop; if ($v === null) return;`),
  no castear ni confiar en el invariante. Caso: reactor `CloseDepartureOnRouteClosed` (fleetops).
- **`Method Redis::pipeline() invoked with 1 parameter, 0 required` (2026-11-13).** `Illuminate\Redis\
  Connections\Connection` (abstracto) NO declara `pipeline()`; PHPStan lo resuelve por `__call` al stub de
  phpredis `\Redis::pipeline()` (0 params). El método real `pipeline(?callable $callback = null)` vive en
  `Illuminate\Redis\Connections\PhpRedisConnection`. Fix: narrowing a esa clase concreta con el patrón del repo
  `assert($conn instanceof PhpRedisConnection)` (tras `Redis::connection(...)`, que PHPStan tipa como el
  abstracto). **Y alinear el doble de test**: `Mockery::mock(PhpRedisConnection::class)`, NO el abstracto, o el
  `assert` lanza en los tests (caso `tests/Support/FakeMetricsRedis.php`). Mismo criterio para Predis.
- **`Call to an undefined method ConnectionInterface::getDriverName()` (2026-11-13).** `getDriverName()`,
  `getConfig()`, `getPdo()` viven en el `Illuminate\Database\Connection` CONCRETO, no en la interfaz. Tipar el
  parámetro/propiedad como `Connection` (DatabaseManager::connection() ya lo devuelve), no `ConnectionInterface`.
- **`array_chunk($x, $n)` → "Parameter #2 $length expects int<1, max>, int given" (2026-11-13).** PHPStan no
  sabe que `$n >= 1`. Fix: `array_chunk($x, max(1, $n))`.
- **`cast.string` "Cannot cast array|bool|float|int|string to string" en `(string) $this->option('x')`
  (2026-11-13).** Un option de artisan es `array|bool|string|null`. Usar `is_string()` como guard en vez de
  castear: `$v = $this->option('x'); if (!is_string($v) || trim($v) === '') { ... }`.
- **`Cannot access property $x on T|null`: respetar el TIPO del getter, no el comentario de invariante
  (2026-11-13).** Un getter tipado `?Odometer` accedido con `->km` falla aunque un comentario diga "una salida
  abierta SIEMPRE lleva su odómetro". El fix no es castear ni confiar en el invariante: usar `?->` y un guard
  explícito (`$v = $x->getter()?->prop; if ($v === null) { return; }` y aplicar la regla de negocio —p. ej.
  `max()`— solo cuando el valor se conoce). Caso real: reactor `CloseDepartureOnRouteClosed` (fleetops).
- **Un bump de `laravel/framework` (minor) destapa errores PHPStan NUEVOS por tipos más estrictos
  (2026-11-13, 13.35→13.40).** No son regresión tuya: el bump endurece firmas. Los recurrentes:
  - `(string) $this->argument('x')` / `$this->option('x')`: `argument()`/`option()` devuelven
    `array|bool|float|int|string|null`; castear a string falla por el `array`. Fix: `$v = $this->argument('x');
    is_string($v) ? $v : ''` (o un helper privado `stringOption()` si hay varios).
  - `round($event->time, 2)` con `time` nullable → `round($event->time ?? 0.0, 2)`.
  - `$this->container->make(X)` en un Seeder: `$container` es `?Container` → usar `app(X::class)` (no-null, tipado
    por larastan).
  Tras `composer update laravel/framework --with-all-dependencies`, correr PHPStan y barrer estos patrones.
- **Advisory de seguridad de un paquete = `composer update <pkg> --with-all-dependencies`, NO editar el lock a
  mano** (el lock trae ref/dist + content-hash; cambiar la versión a mano rompe el checksum de `composer install`).
  Si el constraint del composer.json ya permite la versión parcheada (p. ej. `^13.17` admite 13.40), NO se toca
  composer.json, solo se regenera el lock con composer. Verificar con `composer audit --locked`.

## Gotchas de warnings de `composer dump-autoload` / git (no fallan el gate pero el dueño los quiere en cero)
- **PSR-4 "does not comply" por clases helper co-ubicadas en archivos de test** (fakes/stubs/excepciones
  simuladas definidas INLINE en un `*Test.php` Pest, namespace global): composer las saltea del classmap
  optimizado (los tests igual corren porque Pest carga el archivo). Fix: mover cada clase doble a su PROPIO archivo
  con el namespace PSR-4 correcto (`Logistics\<Ctx>\Tests\Feature|Unit`), nombre = clase, y `use` en el test. El
  archivo nuevo NO debe terminar en `Test.php` (si no PHPUnit lo toma como test).
- **git "dubious ownership" en el contenedor** (`fatal: detected dubious ownership in repository at
  '/var/www/html'`): el bind-mount tiene dueño del host ≠ usuario del contenedor. Fix a nivel imagen (cubre todo,
  incluso `docker compose run --entrypoint composer`): `RUN git config --system --add safe.directory /var/www/html`
  en el Dockerfile, y además en el entrypoint por si la imagen no se reconstruyó.
- **Test aleatorio intermitente (flaky)**: un test que genera un valor con CSPRNG y lo valida (p. ej.
  `VoucherCode::generate()` → `fromString()`) falla ~X% de las corridas por un bug que solo dispara con ciertos
  valores. El fix es doble: (1) arreglar el bug, (2) agregar un **roundtrip exhaustivo** (p. ej. 1000 iteraciones)
  que lo haga DETERMINISTA (pasa siempre o falla siempre), no dejarlo al azar del generador. Bug real visto:
  `str_replace(['DT-','-'], '', $code)` removía TODAS las ocurrencias de `'DT-'`, no solo el prefijo (si el primer
  bloque terminaba en `DT`) → usar los grupos de captura del regex, no `str_replace`.

Relacionado: memoria [[ci-quality-gates-playbook]], [[project-rules]].
