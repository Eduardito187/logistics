#!/usr/bin/env bash
# Muestra el estado REAL de los agentes/workflows leyendo los journals que escribe el runtime
# de Claude Code (no es texto inventado por el asistente). Fuente:
#   ~/.claude/projects/-dismac-logistics/<sesion>/subagents/workflows/<run>/journal.jsonl
set -euo pipefail

BASE="${HOME}/.claude/projects/-dismac-logistics"

if [ ! -d "$BASE" ]; then
  echo "No hay carpeta de workflows todavía ($BASE)."
  exit 0
fi

python3 - "$BASE" <<'PY'
import sys, os, json, glob, time

base = sys.argv[1]
journals = glob.glob(os.path.join(base, "*", "subagents", "workflows", "*", "journal.jsonl"))
# ordenar por modificación, más reciente primero
journals.sort(key=lambda p: os.path.getmtime(p), reverse=True)

now = time.time()
printed = 0
print("=" * 64)
print(" AGENTES / WORKFLOWS  (leído de los journals del runtime)")
print("=" * 64)

for jp in journals:
    age_min = (now - os.path.getmtime(jp)) / 60.0
    # mostrar solo lo de las últimas ~3 horas
    if age_min > 180:
        continue
    run = os.path.basename(os.path.dirname(jp))
    started, done, failed, desc = {}, set(), set(), ""
    try:
        for line in open(jp):
            o = json.loads(line)
            t = o.get("type")
            if t == "launched":
                desc = o.get("description") or o.get("name") or desc
            elif t == "started":
                started[o.get("agentId")] = o.get("label")
            elif t == "result":
                done.add(o.get("agentId"))
            elif t == "failed":
                failed.add(o.get("agentId"))
    except Exception:
        continue
    if not started:
        continue

    # Agrupar por LABEL: un reintento exitoso reemplaza a un intento fallido previo.
    # Estado por label: corriendo > hecho > fallido (un label solo está fallido si NINGÚN intento salió bien).
    by_label = {}
    for aid, label in started.items():
        if aid in failed:
            st = "fail"
        elif aid in done:
            st = "ok"
        else:
            st = "run"
        prev = by_label.get(label)
        rank = {"fail": 0, "ok": 1, "run": 2}
        if prev is None or rank[st] > rank[prev]:
            by_label[label] = st

    n_ok = sum(1 for s in by_label.values() if s == "ok")
    n_run = sum(1 for s in by_label.values() if s == "run")
    n_fail = sum(1 for s in by_label.values() if s == "fail")
    state = "EN CURSO" if n_run else "terminado"
    printed += 1
    hace = f"hace {age_min:.0f} min" if age_min >= 1 else "ahora"
    print(f"\n▶ {run}  [{state}]  ({hace})")
    if desc:
        print(f"  {desc[:90]}")
    for label, st in by_label.items():
        mark = {"fail": "✗ FALLÓ ", "ok": "✓ hecho ", "run": "● CORRIENDO"}[st]
        print(f"    {mark}  {label}")
    tail = f"    — {n_ok}/{len(by_label)} hechos"
    if n_run:
        tail += f", {n_run} en curso"
    if n_fail:
        tail += f", {n_fail} sin completar"
    print(tail)

if printed == 0:
    print("\nNo hay workflows activos ni recientes. (Nada corriendo ahora.)")
print()
PY
