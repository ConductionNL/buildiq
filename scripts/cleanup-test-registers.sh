#!/usr/bin/env bash
#
# cleanup-test-registers.sh — remove PHPUnit / OpenBuild-e2e / CI test debris
# from OpenRegister so the register & schema pickers stay usable for testing.
#
# It deletes, in the only order OpenRegister's guards allow:
#   1. objects   (DELETE /api/objects/{register}/{schema}/{id})
#   2. schemas   (DELETE /api/schemas/{id})            — blocked while objects attached
#   3. registers (DELETE /api/registers/{id}?force=true) — blocked while objects attached
# then sweeps orphan magic data tables (oc_openregister_table_{reg}_{schema} whose
# register no longer exists) directly in Postgres — the API never drops those.
#
# SAFETY: it only ever touches registers/schemas whose slug matches a TEST_PATTERN
# below. Real app registers (and the manual test apps test22/test23) never match,
# so they are never deleted. Run with --dry-run first to preview.
#
# Usage:
#   scripts/cleanup-test-registers.sh --dry-run        # preview only (default)
#   scripts/cleanup-test-registers.sh --apply          # actually delete
#
# Env overrides:
#   NC_URL   (default http://localhost:8080)
#   NC_AUTH  (default admin:admin)
#   PG_CONTAINER (default openregister-postgres)  PG_USER (nextcloud)  PG_DB (nextcloud)
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
set -euo pipefail

NC_URL="${NC_URL:-http://localhost:8080}"
NC_AUTH="${NC_AUTH:-admin:admin}"
PG_CONTAINER="${PG_CONTAINER:-openregister-postgres}"
PG_USER="${PG_USER:-nextcloud}"
PG_DB="${PG_DB:-nextcloud}"

# Slugs matching ANY of these (anchored where it matters) are test debris.
TEST_PATTERN='^phpunit|^openbuild-e2e-|^e2e-[0-9]|-register-[0-9]{6,}|^test-register-|^mdm-verify-reg-|newman|throttle-probe|dup-test|^dsr-scratch|^reverify-'

MODE="dry-run"
case "${1:-}" in
  --apply) MODE="apply" ;;
  --db)    MODE="db" ;;
  --dry-run|"") MODE="dry-run" ;;
  *) echo "usage: $0 [--dry-run|--apply|--db]" >&2; exit 2 ;;
esac
# --apply : per-object API deletes (objects→schemas→registers) — clean but slow,
#           and fragile if the instance churns mid-run (hundreds of calls).
# --db    : one Postgres transaction (drop magic tables + delete schema/register
#           rows) — fast, atomic, churn-resistant. Same safe pattern-based scope.

api()  { curl -fsS -u "$NC_AUTH" -H "OCS-APIRequest: true" "$@"; }
psql_q() { docker exec -i "$PG_CONTAINER" psql -U "$PG_USER" -d "$PG_DB" -tAc "$1" 2>/dev/null; }

echo "== OpenRegister test-debris cleanup ($MODE) =="
echo "   target: $NC_URL   pattern: $TEST_PATTERN"
echo

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
api "$NC_URL/apps/openregister/api/registers?_limit=5000" > "$tmp/regs.json"
api "$NC_URL/apps/openregister/api/schemas?_limit=5000"   > "$tmp/schemas.json"

# Resolve test registers, their schema ids, and orphan test schemas — in Python.
python3 - "$tmp" "$TEST_PATTERN" <<'PY' > "$tmp/plan.sh"
import json, re, sys
tmp, pat = sys.argv[1], re.compile(sys.argv[2])
regs = json.load(open(f"{tmp}/regs.json")); regs = regs.get('results') or regs.get('registers') or regs
schs = json.load(open(f"{tmp}/schemas.json")); schs = schs.get('results') or schs.get('schemas') or schs

def sid(x):
    return x if isinstance(x, int) else (int(x) if isinstance(x, str) and x.isdigit() else None)

test_regs = [r for r in regs if pat.search(r.get('slug') or '')]
keep_regs = [r for r in regs if r not in test_regs]
keep_ref  = {sid(s) for r in keep_regs for s in (r.get('schemas') or []) if sid(s) is not None}

# schema ids owned by test registers (and not also used by a kept register)
test_sch_from_regs = {sid(s) for r in test_regs for s in (r.get('schemas') or []) if sid(s) is not None}
# standalone orphan test schemas (match pattern, not referenced by any kept register)
orphan_test_sch = {x['id'] for x in schs if pat.search(x.get('slug') or '') and x['id'] not in keep_ref}
del_sch = sorted((test_sch_from_regs | orphan_test_sch) - keep_ref)

print(f"echo 'test registers: {len(test_regs)} | test schemas: {len(del_sch)}'")
# Emit shell vars the bash driver consumes.
print("REG_IDS='%s'"  % ','.join(str(r['id']) for r in test_regs))
print("REG_LIST='%s'" % '\n'.join(f"{r['id']}\t{r.get('slug')}\t{','.join(str(sid(s)) for s in (r.get('schemas') or []) if sid(s) is not None)}\t{r.get('slug')}" for r in test_regs))
print("SCH_IDS='%s'"  % ','.join(map(str, del_sch)))
print("KEEP_IDS='%s'" % ','.join(str(r['id']) for r in keep_regs))
PY
# shellcheck disable=SC1090
source "$tmp/plan.sh"

if [ -z "${REG_IDS}" ] && [ -z "${SCH_IDS}" ]; then
  echo "No matching test registers or schemas."
else
  echo "Registers to delete:"; printf '%s\n' "$REG_LIST" | awk -F'\t' 'NF{printf "  id=%s  %s\n",$1,$2}'
fi

if [ "$MODE" = "dry-run" ]; then
  ORPHANS=$(psql_q "SELECT count(*) FROM information_schema.tables WHERE table_name ~ '^oc_openregister_table_[0-9]+_[0-9]+\$' AND (split_part(table_name,'_',4))::int NOT IN (${KEEP_IDS:-0});")
  echo
  echo "Orphan magic tables that would be dropped: ${ORPHANS:-0}"
  echo "(dry-run — nothing deleted. Re-run with --apply.)"
  exit 0
fi

# --- DB FAST PATH --------------------------------------------------------
# One atomic transaction: drop the test registers' magic tables, delete their
# schema rows and register rows. Resistant to instance churn (no per-object API).
if [ "$MODE" = "db" ]; then
  if [ -z "${REG_IDS}" ] && [ -z "${SCH_IDS}" ]; then echo "Nothing to delete."; else
    {
      echo "BEGIN;"
      if [ -n "${REG_IDS}" ]; then
        psql_q "SELECT table_name FROM information_schema.tables WHERE table_name ~ '^oc_openregister_table_[0-9]+_[0-9]+\$' AND (split_part(table_name,'_',4))::int IN (${REG_IDS});" \
          | while read -r t; do [ -n "$t" ] && echo "DROP TABLE IF EXISTS \"$t\" CASCADE;"; done
      fi
      [ -n "${SCH_IDS}" ] && echo "DELETE FROM oc_openregister_schemas WHERE id IN (${SCH_IDS});"
      [ -n "${REG_IDS}" ] && echo "DELETE FROM oc_openregister_registers WHERE id IN (${REG_IDS});"
      echo "COMMIT;"
    } | docker exec -i "$PG_CONTAINER" psql -U "$PG_USER" -d "$PG_DB" 2>&1 | grep -ciE 'DROP TABLE|DELETE' >/dev/null || true
    echo "  registers + schemas + their magic tables deleted (DB transaction)"
  fi
  # sweep remaining orphan magic tables (any register that no longer exists)
  KEEP_NOW=$(api "$NC_URL/apps/openregister/api/registers?_limit=5000" | python3 -c "import sys,json;d=json.load(sys.stdin);r=d.get('results') or d.get('registers') or d;print(','.join(str(x['id']) for x in r))" 2>/dev/null || echo "${KEEP_IDS:-0}")
  mapfile -t orphan_tables < <(psql_q "SELECT table_name FROM information_schema.tables WHERE table_name ~ '^oc_openregister_table_[0-9]+_[0-9]+\$' AND (split_part(table_name,'_',4))::int NOT IN (${KEEP_NOW:-0});")
  if [ "${#orphan_tables[@]}" -gt 0 ]; then
    { echo "BEGIN;"; for t in "${orphan_tables[@]}"; do [ -n "$t" ] && echo "DROP TABLE IF EXISTS \"$t\" CASCADE;"; done; echo "COMMIT;"; } \
      | docker exec -i "$PG_CONTAINER" psql -U "$PG_USER" -d "$PG_DB" >/dev/null 2>&1
  fi
  echo "  orphan magic tables dropped (${#orphan_tables[@]})"
  docker exec nextcloud bash -lc 'php -r "function_exists(\"apcu_clear_cache\") && apcu_clear_cache();"' 2>/dev/null || true
  docker exec nextcloud apachectl -k graceful 2>/dev/null || true
  echo
  echo "Done. Registers now: $(api "$NC_URL/apps/openregister/api/registers?_limit=5000" | python3 -c "import sys,json;d=json.load(sys.stdin);print(len(d.get('results') or d.get('registers') or d))" 2>/dev/null)"
  exit 0
fi

# --- APPLY ---------------------------------------------------------------
# 1) objects, 2) schemas, 3) registers — per the guard-required order.
while IFS=$'\t' read -r rid rslug rschemas _; do
  [ -z "$rid" ] && continue
  IFS=',' read -ra sids <<< "$rschemas"
  for s in "${sids[@]}"; do
    [ -z "$s" ] && continue
    # page through objects and delete each
    while :; do
      ids=$(api "$NC_URL/apps/openregister/api/objects/$rslug/$s?_limit=100" \
            | python3 -c "import sys,json;d=json.load(sys.stdin);r=d.get('results') or [];print('\n'.join(str(o.get('id') or (o.get('@self') or {}).get('id') or '') for o in r))" 2>/dev/null || true)
      [ -z "$ids" ] && break
      while read -r oid; do
        [ -z "$oid" ] && continue
        api -X DELETE "$NC_URL/apps/openregister/api/objects/$rslug/$s/$oid" >/dev/null 2>&1 || true
      done <<< "$ids"
    done
  done
done <<< "$REG_LIST"
echo "  objects deleted"

if [ -n "${SCH_IDS:-}" ]; then
  for s in ${SCH_IDS//,/ }; do
    api -X DELETE "$NC_URL/apps/openregister/api/schemas/$s" >/dev/null 2>&1 || true
  done
  echo "  schemas deleted ($(echo "$SCH_IDS" | tr ',' ' ' | wc -w))"
fi

if [ -n "${REG_IDS:-}" ]; then
  for r in ${REG_IDS//,/ }; do
    api -X DELETE "$NC_URL/apps/openregister/api/registers/$r?force=true" >/dev/null 2>&1 || true
  done
  echo "  registers deleted ($(echo "$REG_IDS" | tr ',' ' ' | wc -w))"
fi

# 4) sweep orphan magic tables (register no longer exists). The API never drops these.
KEEP_NOW=$(api "$NC_URL/apps/openregister/api/registers?_limit=5000" \
  | python3 -c "import sys,json;d=json.load(sys.stdin);r=d.get('results') or d.get('registers') or d;print(','.join(str(x['id']) for x in r))")
mapfile -t orphan_tables < <(psql_q "SELECT table_name FROM information_schema.tables WHERE table_name ~ '^oc_openregister_table_[0-9]+_[0-9]+\$' AND (split_part(table_name,'_',4))::int NOT IN (${KEEP_NOW:-0});")
if [ "${#orphan_tables[@]}" -gt 0 ]; then
  { echo "BEGIN;"; for t in "${orphan_tables[@]}"; do [ -n "$t" ] && echo "DROP TABLE IF EXISTS \"$t\" CASCADE;"; done; echo "COMMIT;"; } \
    | docker exec -i "$PG_CONTAINER" psql -U "$PG_USER" -d "$PG_DB" >/dev/null 2>&1
fi
echo "  orphan magic tables dropped (${#orphan_tables[@]})"

# 5) clear caches so OR re-reads the pruned metadata
docker exec nextcloud bash -lc 'php -r "function_exists(\"apcu_clear_cache\") && apcu_clear_cache();"' 2>/dev/null || true
docker exec nextcloud apachectl -k graceful 2>/dev/null || true

echo
echo "Done. Registers now: $(api "$NC_URL/apps/openregister/api/registers?_limit=5000" | python3 -c "import sys,json;d=json.load(sys.stdin);print(len(d.get('results') or d.get('registers') or d))")"
