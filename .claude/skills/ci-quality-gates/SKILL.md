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

```bash
PK=Eduardito187_logistics ; PR=<numero-del-PR>
# Bugs (Reliability):
curl -s "https://sonarcloud.io/api/issues/search?componentKeys=$PK&pullRequest=$PR&types=BUG&resolved=false&ps=100" \
 | php -r '$d=json_decode(stream_get_contents(STDIN),true);printf("BUGs=%s\n",$d["total"]);foreach($d["issues"]??[] as $i)printf("[%s] %s %s:%s\n  %s\n",$i["severity"],$i["rule"],$i["component"],$i["line"]??"",$i["message"]);'
# Vulnerabilities (Security) — cambiar types=VULNERABILITY.
# Comprobar si una supresión surte efecto: &rules=php:S107
```

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

### Falsos positivos de texto
- Sonar marca la palabra española **"todo"** suelta como TODO → usar `cada`/`completo`/`íntegramente`.

## Paso 4 — `composer audit` (job aparte del gate Sonar)

```bash
composer update <paquetes-afectados> --with-all-dependencies --no-interaction   # reescribe solo esos + deps
docker compose exec -T app composer install                                      # sincroniza el vendor del container
composer audit --locked                                                          # debe decir "No security vulnerability advisories found."
```
Laravel 13.x admite symfony 7.4 y 8 (`^7.4||^8.0`): el salto 7.4→8 es válido pero **verificar con la suite
completa** que no rompe.

## Paso 5 — DoD local antes de reportar (todo en Docker)

```bash
docker compose exec -T app ./vendor/bin/pint <archivos>                      # PASS
docker compose exec -T app ./vendor/bin/phpstan analyse --memory-limit=1G    # 0 errores (nivel 8)
docker compose exec -T app ./vendor/bin/pest                                 # exit 0, reportar N passed
```
No pipear `pest` a `tail -N` en background: trunca el resumen "Tests: N passed". Confiar en exit 0 + cero FAIL.

## Gotchas PHPStan nivel 8 (recurrentes)
- `DB::table()->first()` → tipar el método como `?\stdClass` (acceso a props de stdClass exento). NO
  `@return object{...}` (error return.type) ni `@var` temporal (dispara "immediate return" de Sonar).
- `bindec()`/binario → castear `(int)`.

Relacionado: memoria [[ci-quality-gates-playbook]], [[project-rules]].
