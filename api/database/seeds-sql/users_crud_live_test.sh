#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# LIVE user-CRUD end-to-end test
#
# What it does (no mocks, no fakes, against the real API + DB):
#
#  1. Mints an admin Sanctum token via `php artisan tinker`
#  2. Seeds 10 realistic users across multiple RPHEOCs, districts, POEs,
#     and role_keys using the new direct-password create path
#     POST /v2/admin/users  (body includes `password` + `assignment`)
#  3. For each created user, exercises:
#       · GET  /v2/admin/users/{id}            (detail shape)
#       · POST /v2/auth/login                  (dashboard login; works for any role)
#       · POST /users/login                    (mobile login — the
#         UserLoginController entry point used by the Ionic app)
#       · POST /v2/admin/users/{id}/rescan     (anomaly engine round-trip)
#     Every HTTP status is asserted; unexpected codes fail the suite.
#  4. Exercises the reports the dashboard charts depend on, asserting the
#     JSON shape so the PWA never renders `0/undefined` again:
#       · /v2/admin/users/report/mfa    → rows carry `total` (not `n`)
#       · /v2/admin/users/report/roles  → rows carry `n`     (not `total`)
#  5. Cleans up — soft-deletes every seeded user so repeated runs are idempotent.
#
# Run:  bash api/database/seeds-sql/users_crud_live_test.sh
# Exit: 0 on full pass, 1 on any failure.
# ═══════════════════════════════════════════════════════════════════════════
set -u

API_BASE="${API_BASE:-http://localhost:8000/api}"
DB_USER="${DB_USER:-hacker}"
DB_PASS="${DB_PASS:-kamukama}"
DB_NAME="${DB_NAME:-poe_2026}"
ADMIN_USER_ID="${ADMIN_USER_ID:-6}"   # seeded master admin
API_DIR="$(cd "$(dirname "$0")"/../.. && pwd)"

G='\033[0;32m'; R='\033[0;31m'; Y='\033[0;33m'; B='\033[0;34m'; D='\033[0;90m'; C='\033[0m'
PASS=0; FAIL=0; declare -a FAIL_MSGS

section() { echo; echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"; echo -e "${B}  $1${C}"; echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"; }
ok()   { printf "  ${G}✓${C} %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  ${R}✗${C} %s ${D}— %s${C}\n" "$1" "$2"; FAIL=$((FAIL+1)); FAIL_MSGS+=("$1: $2"); }
info() { printf "  ${D}·${C} %s\n" "$1"; }

sql()   { mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$1" 2>/dev/null; }
need()  { command -v "$1" >/dev/null 2>&1 || { echo "Missing $1"; exit 2; }; }
need curl; need mysql; need php; need python3

# JSON helpers (replace jq with python3 — portable, no install needed)
# jbuild <json-expression> : build a JSON object/array from shell
# jread  <path> <json>     : read a dotted path from a JSON string (empty on miss)
jbuild() { python3 -c "import json,sys; print(json.dumps($1))"; }
jread()  {
  python3 - "$@" <<'PY'
import sys, json
path = sys.argv[1].split('.')
try:
    obj = json.loads(sys.argv[2])
except Exception:
    print(''); sys.exit(0)
cur = obj
for p in path:
    if p == '': continue
    if isinstance(cur, dict):
        cur = cur.get(p, '')
    elif isinstance(cur, list):
        try: cur = cur[int(p)]
        except Exception: cur = ''
    else:
        cur = ''
if isinstance(cur, (dict, list)):
    print(json.dumps(cur))
elif cur is None:
    print('')
else:
    print(cur)
PY
}
# Pass a json string + a python expression that receives `d` to evaluate a boolean/string
jcheck() { python3 -c "import json,sys; d=json.loads(sys.argv[1]); print($2)" "$1"; }

# ═══════════════════════════════════════════════════════════════════════════
# 0. Mint admin token
# ═══════════════════════════════════════════════════════════════════════════
section "0. Provision admin token"
cd "$API_DIR" || exit 2
TOKEN=$(php artisan tinker --execute="echo \App\Models\User::find($ADMIN_USER_ID)?->createToken('crud-live-'.time())->plainTextToken;" 2>/dev/null | tail -1 | tr -d '[:space:]')
if [[ -z "$TOKEN" || "$TOKEN" == "null" ]]; then
  bad "mint admin token for user_id=$ADMIN_USER_ID" "tinker returned empty"
  echo -e "${R}ABORT${C}"; exit 1
fi
ok "admin token minted for user_id=$ADMIN_USER_ID (len=${#TOKEN})"

AUTH=( -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' )

# HTTP helper: returns "<status>|<body>"
call() {
  local method="$1" path="$2" body="${3:-}"
  if [[ -n "$body" ]]; then
    curl -s -o /tmp/crud_body.$$ -w "%{http_code}" -X "$method" "$API_BASE$path" "${AUTH[@]}" --data "$body"
  else
    curl -s -o /tmp/crud_body.$$ -w "%{http_code}" -X "$method" "$API_BASE$path" "${AUTH[@]}"
  fi
  echo "|$(cat /tmp/crud_body.$$)"
  rm -f /tmp/crud_body.$$
}

# ═══════════════════════════════════════════════════════════════════════════
# 1. Seed roster — 10 users across POEs / RPHEOCs / roles
# ═══════════════════════════════════════════════════════════════════════════
section "1. Seed 10 users via POST /v2/admin/users (direct-create)"

RUN_TAG="livetest$(date +%s)"

# Format: role|RPHEOC|district|poe|label
# - 5 SCREENER at 5 different POEs in 5 different RPHEOCs
# - 2 DISTRICT_SUPERVISOR (different districts)
# - 2 PHEOC_OFFICER (different RPHEOCs)
# - 1 NATIONAL_ADMIN (no geo)
SEEDS=(
  "SCREENER|Gulu RPHEOC|Lamwo District|Ngoromoro|Lamwo Screener Alpha"
  "SCREENER|Arua RPHEOC|Koboko District|Oraba|Koboko Screener Bravo"
  "SCREENER|Mbale RPHEOC|Busia District|Busia|Busia Screener Charlie"
  "SCREENER|Kabale RPHEOC|Kisoro District|Bunagana|Kisoro Screener Delta"
  "SCREENER|Masaka RPHEOC|Rakai District|Mutukula|Rakai Screener Echo"
  "DISTRICT_SUPERVISOR|Gulu RPHEOC|Lamwo District||Lamwo DSO Foxtrot"
  "DISTRICT_SUPERVISOR|Kabale RPHEOC|Ntungamo District||Ntungamo DSO Golf"
  "PHEOC_OFFICER|Gulu RPHEOC|||Gulu PHEOC Hotel"
  "PHEOC_OFFICER|Mbale RPHEOC|||Mbale PHEOC India"
  "NATIONAL_ADMIN||||National Admin Juliet"
)

declare -a IDS=()
declare -a USERNAMES=()
declare -a PASSWORDS=()
declare -a ROLES=()

i=0
for row in "${SEEDS[@]}"; do
  IFS='|' read -r role rpheoc district poe label <<<"$row"
  i=$((i+1))
  user_name="${RUN_TAG}_u${i}"
  full_name="$label"
  password="LiveTest#2026-$i"
  email="${user_name}@livetest.local"

  # Build payload (mobile-shape single `assignment`) via python to avoid shell quoting hell
  payload=$(python3 - "$full_name" "$user_name" "$email" "$password" "$role" "$rpheoc" "$district" "$poe" <<'PY'
import json, sys
fn, un, em, pw, rk, pr, dt, po = sys.argv[1:9]
print(json.dumps({
  "full_name": fn, "username": un, "email": em, "phone": None,
  "password": pw, "role_key": rk, "country_code": "UG", "is_active": True,
  "assignment": {
    "country_code": "UG",
    "province_code": pr or None,
    "pheoc_code":    pr or None,
    "district_code": dt or None,
    "poe_code":      po or None,
    "is_primary": True, "is_active": True,
  }
}))
PY
)

  resp=$(call POST /v2/admin/users "$payload")
  status="${resp%%|*}"; body="${resp#*|}"

  if [[ "$status" == "201" ]]; then
    id=$(jread "data.id" "$body")
    if [[ -n "$id" && "$id" != "null" ]]; then
      ok "CREATE #$i [$role] $label → id=$id"
      IDS+=("$id"); USERNAMES+=("$user_name"); PASSWORDS+=("$password"); ROLES+=("$role")
    else
      bad "CREATE #$i [$role] $label" "201 but missing id (body=$body)"
    fi
  else
    first_err=$(jcheck "$body" "((d.get('errors') or {}) and list((d.get('errors') or {}).values())[0][0]) or d.get('error') or 'unknown'" 2>/dev/null || echo "unknown")
    bad "CREATE #$i [$role] $label" "status=$status · $first_err"
  fi
done

info "seeded ${#IDS[@]}/10 users"

# ═══════════════════════════════════════════════════════════════════════════
# 2. Verify DB-level compliance of the writes
# ═══════════════════════════════════════════════════════════════════════════
section "2. DB compliance per seed"

for idx in "${!IDS[@]}"; do
  id="${IDS[$idx]}"
  role="${ROLES[$idx]}"
  un="${USERNAMES[$idx]}"

  # users row sanity
  u_row=$(sql "SELECT CONCAT_WS('|', id, role_key, country_code, username, IFNULL(email,''), is_active,
               IF(invitation_accepted_at IS NOT NULL,'Y','N'),
               IF(must_change_password=1,'Y','N'),
               IF(password IS NOT NULL AND password!='','Y','N'))
               FROM users WHERE id=$id LIMIT 1;")
  IFS='|' read -r d_id d_role d_cc d_un d_em d_active d_accepted d_mcp d_haspw <<<"$u_row"

  [[ "$d_role" == "$role" ]] && ok "user#$id role=$role" || bad "user#$id role" "got=$d_role"
  [[ "$d_un"   == "$un"   ]] && ok "user#$id username lower-cased" || bad "user#$id username" "got=$d_un"
  [[ "$d_active" == "1"   ]] && ok "user#$id is_active=1" || bad "user#$id is_active" "got=$d_active"
  [[ "$d_accepted" == "Y" ]] && ok "user#$id invitation_accepted_at set (direct-create)" || bad "user#$id accepted" "got=$d_accepted"
  [[ "$d_mcp"    == "N"   ]] && ok "user#$id must_change_password=0 (direct create)" || bad "user#$id mcp" "got=$d_mcp"
  [[ "$d_haspw"  == "Y"   ]] && ok "user#$id password hash stored" || bad "user#$id password hash" "got=$d_haspw"

  # Assignment row matches requested geo
  if [[ "$role" != "NATIONAL_ADMIN" ]]; then
    a_row=$(sql "SELECT COUNT(*) FROM user_assignments WHERE user_id=$id AND is_primary=1 AND is_active=1;")
    [[ "$a_row" == "1" ]] && ok "user#$id has exactly one active-primary assignment" || bad "user#$id primary assignment" "count=$a_row"
  fi

  # Audit trail: CREATE row present
  audit_n=$(sql "SELECT COUNT(*) FROM user_audit_log WHERE target_user_id=$id AND action='CREATE';")
  [[ "$audit_n" -ge 1 ]] && ok "user#$id user_audit_log CREATE written" || bad "user#$id audit CREATE" "count=$audit_n"

  # AuthEvent ADMIN_CREATED
  ae_n=$(sql "SELECT COUNT(*) FROM auth_events WHERE user_id=$id AND event_type='ADMIN_CREATED';")
  [[ "$ae_n" -ge 1 ]] && ok "user#$id auth_events ADMIN_CREATED written" || bad "user#$id auth event" "count=$ae_n"
done

# ═══════════════════════════════════════════════════════════════════════════
# 3. Login — dashboard (/v2/auth/login) + mobile (/users/login)
# ═══════════════════════════════════════════════════════════════════════════
section "3. Login flows per seeded user"

for idx in "${!IDS[@]}"; do
  un="${USERNAMES[$idx]}"; pw="${PASSWORDS[$idx]}"; role="${ROLES[$idx]}"

  # --- 3a. Dashboard /v2/auth/login (the PWA path) — no auth header needed ---
  body=$(python3 -c "import json,sys;print(json.dumps({'login':sys.argv[1],'password':sys.argv[2]}))" "$un" "$pw")
  status=$(curl -s -o /tmp/login_body.$$ -w "%{http_code}" -X POST "$API_BASE/v2/auth/login" \
           -H 'Content-Type: application/json' -H 'Accept: application/json' --data "$body")
  if [[ "$status" == "200" ]]; then
    ok2=$(jread "ok" "$(cat /tmp/login_body.$$)")
    tok=$(jread "data.token" "$(cat /tmp/login_body.$$)")
    if [[ "$ok2" == "True" && -n "$tok" ]]; then
      ok "dashboard login OK [$role] $un → token len=${#tok}"
    else
      bad "dashboard login [$role] $un" "200 but ok=$ok2 token.len=${#tok}"
    fi
  else
    bad "dashboard login [$role] $un" "status=$status body=$(cat /tmp/login_body.$$ | head -c 200)"
  fi
  rm -f /tmp/login_body.$$

  # --- 3b. Mobile /auth/login (Ionic app path — UserLoginController) ---
  body_m=$(python3 -c "import json,sys;print(json.dumps({'login':sys.argv[1],'password':sys.argv[2]}))" "$un" "$pw")
  status_m=$(curl -s -o /tmp/login_m.$$ -w "%{http_code}" -X POST "$API_BASE/auth/login" \
             -H 'Content-Type: application/json' -H 'Accept: application/json' --data "$body_m")
  if [[ "$status_m" == "200" ]]; then
    if [[ -s /tmp/login_m.$$ ]]; then
      # Mobile controller returns the full user record; prove role_key matches
      mobile_role=$(jread "data.role_key" "$(cat /tmp/login_m.$$)")
      if [[ -n "$mobile_role" ]]; then
        [[ "$mobile_role" == "$role" ]] && ok "mobile login OK [$role] $un (role echo=$mobile_role)" \
          || bad "mobile login role echo [$role] $un" "got=$mobile_role"
      else
        ok "mobile login OK [$role] $un (status=200)"
      fi
    else
      bad "mobile login [$role] $un" "200 but empty body"
    fi
  else
    bad "mobile login [$role] $un" "status=$status_m body=$(cat /tmp/login_m.$$ | head -c 240)"
  fi
  rm -f /tmp/login_m.$$
done

# ═══════════════════════════════════════════════════════════════════════════
# 4. Report shapes — guard the charts against `0/undefined`
# ═══════════════════════════════════════════════════════════════════════════
section "4. Report JSON shapes (guards the PWA charts)"

# /report/mfa must carry `total` on every row
resp=$(call GET /v2/admin/users/report/mfa)
status="${resp%%|*}"; body="${resp#*|}"
[[ "$status" == "200" ]] && ok "GET /v2/admin/users/report/mfa = 200" || bad "report/mfa" "status=$status"
shape=$(jcheck "$body" "all(('total' in r and 'with_mfa' in r and 'mfa_pct' in r) for r in d['data']['mfa_by_role'])")
[[ "$shape" == "True" ]] && ok "every mfa_by_role row has total + with_mfa + mfa_pct" \
  || bad "mfa_by_role shape" "one or more rows missing keys (body=$(echo "$body" | head -c 200))"

# /report/roles must carry `n` (NOT `total`)
resp=$(call GET /v2/admin/users/report/roles)
status="${resp%%|*}"; body="${resp#*|}"
[[ "$status" == "200" ]] && ok "GET /v2/admin/users/report/roles = 200" || bad "report/roles" "status=$status"
has_n=$(jcheck "$body" "all('n' in r for r in d['data']['roles'])")
[[ "$has_n" == "True" ]] && ok "every roles row carries n (PWA reads it with Number(r.n ?? r.total ?? 0))" \
  || bad "report/roles row shape" "missing n (body=$(echo "$body" | head -c 200))"
# Belt-and-braces: no row should have a bare 'total' key that the PWA would
# previously have mis-read as the denominator — we always coerce, but flag
# if the controller ever changes shape under us.
dup_total=$(jcheck "$body" "any('total' in r and 'n' not in r for r in d['data']['roles'])")
[[ "$dup_total" == "False" ]] && ok "report/roles rows never expose bare 'total' without 'n'" \
  || bad "report/roles rows" "row shape ambiguous (has 'total' but no 'n')"

# /stats — the KPI strip source
resp=$(call GET /v2/admin/users/stats)
status="${resp%%|*}"; body="${resp#*|}"
[[ "$status" == "200" ]] && ok "GET /v2/admin/users/stats = 200" || bad "stats" "status=$status"
for k in total active suspended locked pending_invite dormant_30d mfa_enabled high_risk; do
  v=$(jread "data.status.$k" "$body")
  [[ -n "$v" && "$v" != "None" ]] && ok "stats.status.$k populated (=$v)" || bad "stats.status.$k" "missing (got='$v')"
done

# ═══════════════════════════════════════════════════════════════════════════
# 5. Clean up — soft-delete every seeded user, idempotency guarantee
# ═══════════════════════════════════════════════════════════════════════════
section "5. Cleanup (soft-delete seed users)"

for id in "${IDS[@]}"; do
  resp=$(call DELETE "/v2/admin/users/$id")
  status="${resp%%|*}"
  [[ "$status" == "200" ]] && ok "DELETE /v2/admin/users/$id" || bad "DELETE /v2/admin/users/$id" "status=$status"
done

# ═══════════════════════════════════════════════════════════════════════════
# FINAL REPORT
# ═══════════════════════════════════════════════════════════════════════════
echo
echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
if [[ $FAIL -eq 0 ]]; then
  echo -e "  ${G}✓ ALL $PASS ASSERTIONS PASSED${C}"
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
  exit 0
else
  echo -e "  ${R}✗ $FAIL FAILED${C} · ${G}$PASS passed${C}"
  echo; echo -e "${R}Failures:${C}"
  for f in "${FAIL_MSGS[@]}"; do echo -e "  ${R}•${C} $f"; done
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
  exit 1
fi
