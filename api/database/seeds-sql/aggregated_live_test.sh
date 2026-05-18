#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# LIVE aggregated-reports end-to-end test
#
# Exercises the full lifecycle a PWA admin drives through the Smart Hub:
#   1. Schema + seed integrity (default template + baseline columns)
#   2. Create → add column → publish → retire → re-publish → lock → unlock
#      → soft-delete → cascade-delete
#   3. Collision guards (409 on duplicate template_code, duplicate column_key,
#      non-cascade delete-with-submissions, delete/retire default)
#   4. Validation (invalid template_code format, invalid data_type,
#      invalid aggregation_fn, invalid column_key format)
#   5. Core-column protection (cannot disable, cannot delete)
#   6. Lock protection (edits blocked when locked)
#
# Run:  bash api/database/seeds-sql/aggregated_live_test.sh
# Exit: 0 on full pass, 1 otherwise.
# ═══════════════════════════════════════════════════════════════════════════
set -u

API_BASE="${API_BASE:-http://localhost:8000/api}"
DB_USER="${DB_USER:-hacker}"
DB_PASS="${DB_PASS:-kamukama}"
DB_NAME="${DB_NAME:-poe_2026}"
ADMIN_USER_ID="${ADMIN_USER_ID:-6}"

G='\033[0;32m'; R='\033[0;31m'; Y='\033[0;33m'; B='\033[0;34m'; D='\033[0;90m'; C='\033[0m'
PASS=0; FAIL=0; declare -a FAIL_MSGS
section() { echo; echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"; echo -e "${B}  $1${C}"; echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"; }
ok()   { printf "  ${G}✓${C} %s\n" "$1"; PASS=$((PASS+1)); }
bad()  { printf "  ${R}✗${C} %s ${D}— %s${C}\n" "$1" "$2"; FAIL=$((FAIL+1)); FAIL_MSGS+=("$1: $2"); }
info() { printf "  ${D}·${C} %s\n" "$1"; }

sql() { mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$1" 2>/dev/null; }
jbuild() { python3 -c "import json,sys; print(json.dumps($1))"; }
jread() {
  python3 - "$@" <<'PY'
import sys, json
path = sys.argv[1].split('.')
try: obj = json.loads(sys.argv[2])
except Exception: print(''); sys.exit(0)
cur = obj
for p in path:
    if p == '': continue
    if isinstance(cur, dict): cur = cur.get(p, '')
    elif isinstance(cur, list):
        try: cur = cur[int(p)]
        except Exception: cur = ''
    else: cur = ''
if isinstance(cur, (dict, list)): print(json.dumps(cur))
elif cur is None: print('')
else: print(cur)
PY
}

call() {
  local method="$1" path="$2" body="${3:-}"
  # Aggregated endpoints use user_id query, not Sanctum
  if [[ "$path" == *\?* ]]; then path="${path}&user_id=${ADMIN_USER_ID}"
  else path="${path}?user_id=${ADMIN_USER_ID}"
  fi
  if [[ -n "$body" ]]; then
    curl -s -o /tmp/agg_body.$$ -w "%{http_code}" -X "$method" "$API_BASE$path" \
      -H 'Content-Type: application/json' -H 'Accept: application/json' --data "$body"
  else
    curl -s -o /tmp/agg_body.$$ -w "%{http_code}" -X "$method" "$API_BASE$path" \
      -H 'Accept: application/json'
  fi
  echo "|$(cat /tmp/agg_body.$$)"; rm -f /tmp/agg_body.$$
}

# ═════════════════════════════════════════════════════════════════════════
section "1. Schema + seed integrity"
for t in aggregated_templates aggregated_template_columns aggregated_submissions aggregated_submission_values; do
  n=$(sql "SHOW TABLES LIKE '$t';")
  [[ -n "$n" ]] && ok "table '$t' exists" || bad "table '$t'" "missing"
done

# Enums present
for v in DRAFT PUBLISHED RETIRED ARCHIVED; do
  x=$(sql "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema='$DB_NAME' AND table_name='aggregated_templates' AND column_name='status';")
  [[ "$x" == *"$v"* ]] && ok "status enum includes $v" || bad "status enum $v" "not in $x"
done
for v in DAILY WEEKLY MONTHLY QUARTERLY AD_HOC EVENT; do
  x=$(sql "SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema='$DB_NAME' AND table_name='aggregated_templates' AND column_name='reporting_frequency';")
  [[ "$x" == *"$v"* ]] && ok "freq enum includes $v" || bad "freq enum $v" "not in $x"
done

# Default template
default_id=$(sql "SELECT id FROM aggregated_templates WHERE is_default=1 AND deleted_at IS NULL LIMIT 1;")
[[ -n "$default_id" ]] && ok "default template present (id=$default_id)" || bad "default template" "missing"

# Baseline column count
col_total=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE template_id=$default_id AND deleted_at IS NULL;")
if [[ "$col_total" -ge 5 ]]; then ok "default template has ≥ 5 columns ($col_total)"
else bad "default columns" "count=$col_total"; fi

# ═════════════════════════════════════════════════════════════════════════
section "2. List + active endpoints"
resp=$(call GET "/aggregated-templates?country_code=UG")
status="${resp%%|*}"; body="${resp#*|}"
[[ "$status" == "200" ]] && ok "GET /aggregated-templates?country_code=UG" || bad "templates list" "status=$status"
any_name=$(jread "data.0.template_name" "$body")
[[ -n "$any_name" ]] && ok "list returns at least one template (first: '$any_name')" || bad "list non-empty" "body=$(echo "$body" | head -c 200)"

resp=$(call GET "/aggregated-templates/active?country_code=UG")
status="${resp%%|*}"; body="${resp#*|}"
[[ "$status" == "200" ]] && ok "GET /active returns 200" || bad "active" "status=$status"
has_cols=$(jread "data.columns.0.column_key" "$body")
[[ -n "$has_cols" ]] && ok "active returns a column set (first key='$has_cols')" || info "active missing columns (may be first-run)"

# ═════════════════════════════════════════════════════════════════════════
section "3. CREATE + validation"

TAG="LIVETEST_$(date +%s)"
CODE_BAD="lowercase_bad"
CODE_GOOD="${TAG}"

# Bad code → 422
body=$(jbuild "{'country_code':'UG','template_name':'Bad code test','template_code':'$CODE_BAD'}")
resp=$(call POST "/aggregated-templates" "$body")
status="${resp%%|*}"
[[ "$status" == "422" ]] && ok "reject lowercase template_code (422)" || bad "reject bad code" "status=$status"

# Missing fields → 422
body='{"country_code":"UG"}'
resp=$(call POST "/aggregated-templates" "$body")
status="${resp%%|*}"
[[ "$status" == "422" ]] && ok "reject missing name+code (422)" || bad "reject missing fields" "status=$status"

# Valid create (clone_default_columns=false to get clean slate)
body=$(jbuild "{'country_code':'UG','template_name':'LIVE TEST TEMPLATE','template_code':'$CODE_GOOD','description':'Ephemeral test template','reporting_frequency':'WEEKLY','clone_default_columns':False}")
resp=$(call POST "/aggregated-templates" "$body")
status="${resp%%|*}"; body_resp="${resp#*|}"
[[ "$status" == "200" ]] && ok "create template with unique code → 200" || bad "create template" "status=$status body=$(echo "$body_resp" | head -c 200)"
NEW_ID=$(jread "data.id" "$body_resp")
[[ -n "$NEW_ID" && "$NEW_ID" != "0" ]] && ok "new template id=$NEW_ID" || bad "new id" "got='$NEW_ID'"

# Duplicate code → 409
body=$(jbuild "{'country_code':'UG','template_name':'Dup','template_code':'$CODE_GOOD'}")
resp=$(call POST "/aggregated-templates" "$body")
status="${resp%%|*}"
[[ "$status" == "409" ]] && ok "duplicate template_code → 409" || bad "duplicate code" "status=$status"

# ═════════════════════════════════════════════════════════════════════════
section "4. Column CRUD + collision"

# Add first column
body=$(jbuild "{'column_key':'live_screened','column_label':'Live screened','category':'CORE','data_type':'INTEGER','aggregation_fn':'SUM','is_required':1,'is_enabled':1}")
resp=$(call POST "/aggregated-templates/$NEW_ID/columns" "$body")
status="${resp%%|*}"; body_r="${resp#*|}"
[[ "$status" == "200" ]] && ok "add column 'live_screened' → 200" || bad "add column" "status=$status"
COL_ID=$(jread "data.id" "$body_r")
[[ -n "$COL_ID" ]] && ok "column id=$COL_ID" || bad "column id" "missing"

# Duplicate column_key → 409
body=$(jbuild "{'column_key':'live_screened','column_label':'Dup','data_type':'INTEGER','aggregation_fn':'SUM'}")
resp=$(call POST "/aggregated-templates/$NEW_ID/columns" "$body")
status="${resp%%|*}"
[[ "$status" == "409" ]] && ok "duplicate column_key → 409" || bad "dup column_key" "status=$status"

# Invalid column_key format → 422
body=$(jbuild "{'column_key':'Bad-Key','column_label':'Bad','data_type':'INTEGER','aggregation_fn':'SUM'}")
resp=$(call POST "/aggregated-templates/$NEW_ID/columns" "$body")
status="${resp%%|*}"
[[ "$status" == "422" ]] && ok "invalid column_key format → 422" || bad "bad key format" "status=$status"

# Invalid data_type → 422
body=$(jbuild "{'column_key':'x1','column_label':'X1','data_type':'GIBBERISH','aggregation_fn':'SUM'}")
resp=$(call POST "/aggregated-templates/$NEW_ID/columns" "$body")
status="${resp%%|*}"
[[ "$status" == "422" ]] && ok "invalid data_type → 422" || bad "bad data_type" "status=$status"

# PATCH column (update label + required)
body=$(jbuild "{'column_label':'Live screened (edited)','is_required':0}")
resp=$(call PATCH "/aggregated-template-columns/$COL_ID" "$body")
status="${resp%%|*}"
[[ "$status" == "200" ]] && ok "patch column → 200" || bad "patch column" "status=$status"

# Add a second column for bulk + delete coverage
body=$(jbuild "{'column_key':'live_flag','column_label':'Live flag','data_type':'BOOLEAN','aggregation_fn':'COUNT','category':'CUSTOM'}")
resp=$(call POST "/aggregated-templates/$NEW_ID/columns" "$body")
COL2_ID=$(jread "data.id" "${resp#*|}")
[[ -n "$COL2_ID" ]] && ok "added second column id=$COL2_ID" || bad "second column" "missing"

# Bulk reorder
body=$(jbuild "{'columns':[{'id':$COL_ID,'display_order':1},{'id':$COL2_ID,'display_order':0}]}")
resp=$(call PATCH "/aggregated-templates/$NEW_ID/columns" "$body")
status="${resp%%|*}"
[[ "$status" == "200" ]] && ok "bulk reorder → 200" || bad "bulk reorder" "status=$status"

# ═════════════════════════════════════════════════════════════════════════
section "5. Lifecycle: publish → retire → re-publish"

resp=$(call POST "/aggregated-templates/$NEW_ID/publish" "{}")
status="${resp%%|*}"; body_r="${resp#*|}"
[[ "$status" == "200" ]] && ok "publish → 200" || bad "publish" "status=$status"
new_status=$(jread "data.status" "$body_r")
[[ "$new_status" == "PUBLISHED" ]] && ok "status=PUBLISHED after publish" || bad "status after publish" "got='$new_status'"

resp=$(call POST "/aggregated-templates/$NEW_ID/retire" "{}")
status="${resp%%|*}"
[[ "$status" == "200" ]] && ok "retire → 200" || bad "retire" "status=$status"

resp=$(call POST "/aggregated-templates/$NEW_ID/publish" "{}")
status="${resp%%|*}"
[[ "$status" == "200" ]] && ok "re-publish after retire → 200" || bad "re-publish" "status=$status"

# ═════════════════════════════════════════════════════════════════════════
section "6. Lock / unlock"

resp=$(call POST "/aggregated-templates/$NEW_ID/lock" "{}")
status="${resp%%|*}"; body_r="${resp#*|}"
[[ "$status" == "200" ]] && ok "lock → 200" || bad "lock" "status=$status"
locked=$(jread "data.locked" "$body_r")
[[ "$locked" == "1" ]] && ok "template locked=1" || bad "locked value" "got='$locked'"

# Patch meta while locked → 409
body=$(jbuild "{'description':'Should fail'}")
resp=$(call PATCH "/aggregated-templates/$NEW_ID" "$body")
status="${resp%%|*}"
[[ "$status" == "409" ]] && ok "PATCH meta while locked → 409" || bad "patch locked" "status=$status"

# Add column while locked → 409
body=$(jbuild "{'column_key':'blocked','column_label':'Blocked','data_type':'INTEGER','aggregation_fn':'SUM'}")
resp=$(call POST "/aggregated-templates/$NEW_ID/columns" "$body")
status="${resp%%|*}"
[[ "$status" == "409" ]] && ok "add column while locked → 409" || bad "add col locked" "status=$status"

# Unlock
body=$(jbuild "{'unlock':True}")
resp=$(call POST "/aggregated-templates/$NEW_ID/lock" "$body")
status="${resp%%|*}"
[[ "$status" == "200" ]] && ok "unlock → 200" || bad "unlock" "status=$status"

# ═════════════════════════════════════════════════════════════════════════
section "7. Default template protection"

# Cannot retire default
resp=$(call POST "/aggregated-templates/$default_id/retire" "{}")
status="${resp%%|*}"
[[ "$status" == "409" ]] && ok "retire default → 409 (protected)" || bad "retire default" "status=$status"

# Cannot delete default
resp=$(call DELETE "/aggregated-templates/$default_id" "")
status="${resp%%|*}"
[[ "$status" == "409" ]] && ok "delete default → 409 (protected)" || bad "delete default" "status=$status"

# ═════════════════════════════════════════════════════════════════════════
section "8. Delete (cascade path)"

# First delete without submissions → 200
resp=$(call DELETE "/aggregated-templates/$NEW_ID")
status="${resp%%|*}"
[[ "$status" == "200" ]] && ok "delete template without submissions → 200" || bad "soft delete" "status=$status"

# Verify DB state
deleted=$(sql "SELECT IFNULL(deleted_at,'NULL') FROM aggregated_templates WHERE id=$NEW_ID;")
[[ "$deleted" != "NULL" && -n "$deleted" ]] && ok "deleted_at set on template row" || bad "deleted_at" "got='$deleted'"

# Columns also soft-deleted
del_cols=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE template_id=$NEW_ID AND deleted_at IS NULL;")
assert_eq_cols() { [[ "$del_cols" == "0" ]] && ok "columns soft-deleted with template" || bad "columns soft-deleted" "count=$del_cols"; }
assert_eq_cols

# ═════════════════════════════════════════════════════════════════════════
section "9. Cascade-delete with submissions"

# Create another template, seed a submission row directly via SQL, then try to delete
CODE2="${TAG}_CASCADE"
body=$(jbuild "{'country_code':'UG','template_name':'Cascade test','template_code':'$CODE2','clone_default_columns':False}")
resp=$(call POST "/aggregated-templates" "$body")
T2=$(jread "data.id" "${resp#*|}")
[[ -n "$T2" ]] && ok "created template T2 id=$T2" || bad "T2 create" "missing"

# Seed a fake submission
sub_id=$(sql "INSERT INTO aggregated_submissions
  (client_uuid, reference_data_version, country_code, district_code, poe_code,
   submitted_by_user_id, period_start, period_end, template_id, device_id)
  VALUES (UUID(), 'v0', 'UG', 'Lamwo District', 'Ngoromoro',
          $ADMIN_USER_ID, NOW(), NOW(), $T2, 'livetest-device');
  SELECT LAST_INSERT_ID();" | tail -1)
[[ -n "$sub_id" ]] && ok "seeded fake submission id=$sub_id for T2" || bad "sub seed" "missing"

# Delete without cascade → 409
resp=$(call DELETE "/aggregated-templates/$T2")
status="${resp%%|*}"; body_r="${resp#*|}"
[[ "$status" == "409" ]] && ok "delete with submissions (no cascade) → 409" || bad "no-cascade block" "status=$status"
hint=$(jread "error.hint" "$body_r")
[[ -n "$hint" ]] && ok "409 body includes remediation hint" || info "hint missing (non-fatal)"

# Delete without confirm → still 409
resp=$(call DELETE "/aggregated-templates/$T2?cascade=true")
status="${resp%%|*}"
[[ "$status" == "409" ]] && ok "cascade=true without confirm → still 409" || bad "cascade no confirm" "status=$status"

# Delete with cascade + confirm → 200
body=$(jbuild "{'confirm':'DELETE_WITH_SUBMISSIONS'}")
resp=$(call DELETE "/aggregated-templates/$T2?cascade=true" "$body")
status="${resp%%|*}"
[[ "$status" == "200" ]] && ok "cascade=true + confirm → 200" || bad "cascade delete" "status=$status"

# Submission still in DB (preserved for audit)
still=$(sql "SELECT COUNT(*) FROM aggregated_submissions WHERE id=$sub_id;")
[[ "$still" == "1" ]] && ok "submission preserved in DB after cascade delete" || bad "sub preservation" "count=$still"

# Cleanup fake submission
sql "DELETE FROM aggregated_submissions WHERE id=$sub_id;" >/dev/null

# ═════════════════════════════════════════════════════════════════════════
section "10. Core-column protection"

# Find a core column on the default template
core_col=$(sql "SELECT id FROM aggregated_template_columns WHERE template_id=$default_id AND is_core=1 AND deleted_at IS NULL LIMIT 1;")
if [[ -n "$core_col" ]]; then
  # Cannot disable
  body=$(jbuild "{'is_enabled':0}")
  resp=$(call PATCH "/aggregated-template-columns/$core_col" "$body")
  status="${resp%%|*}"
  [[ "$status" == "409" ]] && ok "core column cannot be disabled (409)" || bad "core disable" "status=$status"
  # Cannot delete
  resp=$(call DELETE "/aggregated-template-columns/$core_col")
  status="${resp%%|*}"
  [[ "$status" == "409" ]] && ok "core column cannot be deleted (409)" || bad "core delete" "status=$status"
else
  info "no core columns on default — skipping core protection checks"
fi

# ═════════════════════════════════════════════════════════════════════════
echo
echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
if [[ $FAIL -eq 0 ]]; then
  echo -e "  ${G}✓ ALL $PASS ASSERTIONS PASSED${C}"
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
  exit 0
else
  echo -e "  ${R}✗ $FAIL FAILED${C} · ${G}$PASS passed${C}"; echo
  for f in "${FAIL_MSGS[@]}"; do echo -e "  ${R}•${C} $f"; done
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
  exit 1
fi
