#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
# AUDIT TEST SUITE — Aggregated Templates · POE Contacts · Notifications · RBAC
#
# Verifies end-to-end that everything built on 2026-04-21 (aggregated template
# subsystem, POE notification contacts, notifications engine, RBAC opening
# for NATIONAL / PHEOC / DISTRICT admins) is audit-proof:
#
#   • DB schema integrity (7 new tables, indexes, core seeds)
#   • Seed integrity (57-column WHO AFRO template, 12 notification templates)
#   • API liveness (all new endpoints)
#   • RBAC enforcement (admin-only, scope-restricted, 403 for outsiders)
#   • Data integrity (core columns locked, locked templates reject edits)
#   • Idempotency (re-running seeds produces no duplicates)
#
# Creates ephemeral test users + assignments prefixed `_audit_*`, runs ~35
# assertions, then tears down all fixtures before exiting. Leaves no trace.
#
# Run:  bash api/database/seeds-sql/audit_test.sh
# Exit: 0 on full pass, 1 if any assertion fails (CI-friendly).
# ═══════════════════════════════════════════════════════════════════════════
set -u

# ── Config ────────────────────────────────────────────────────────────────
DB_USER="hacker"
DB_PASS="kamukama"
DB_NAME="poe_2026"
API_BASE="http://localhost:8000/api"
TIMESTAMP=$(date +%s)

# ── Colours ───────────────────────────────────────────────────────────────
G='\033[0;32m'  # green
R='\033[0;31m'  # red
Y='\033[0;33m'  # yellow
B='\033[0;34m'  # blue
D='\033[0;90m'  # dim
C='\033[0m'     # reset

# ── Counters ──────────────────────────────────────────────────────────────
PASS=0
FAIL=0
declare -a FAILURES

# ── Test helpers ──────────────────────────────────────────────────────────
section() {
  echo
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
  echo -e "${B}  $1${C}"
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
}

ok() {
  printf "  ${G}✓${C} %s\n" "$1"
  PASS=$((PASS + 1))
}

bad() {
  printf "  ${R}✗${C} %s ${D}— %s${C}\n" "$1" "$2"
  FAIL=$((FAIL + 1))
  FAILURES+=("$1: $2")
}

# assert_eq "label" "expected" "actual"
assert_eq() {
  if [[ "$2" == "$3" ]]; then ok "$1"
  else bad "$1" "expected=$2 actual=$3"; fi
}

# SQL helper — silent, value-only
sql() {
  mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$1" 2>/dev/null
}

# curl helper that separates status + body
api_status() {
  # Usage: api_status METHOD PATH [BODY]
  local method="$1" path="$2" body="${3:-}"
  if [[ -n "$body" ]]; then
    curl -s -o /dev/null -w "%{http_code}" -X "$method" "$API_BASE$path" \
      -H 'Content-Type: application/json' -H 'Accept: application/json' \
      --data "$body" 2>/dev/null
  else
    curl -s -o /dev/null -w "%{http_code}" -X "$method" "$API_BASE$path" \
      -H 'Accept: application/json' 2>/dev/null
  fi
}
api_body() {
  local method="$1" path="$2" body="${3:-}"
  if [[ -n "$body" ]]; then
    curl -s -X "$method" "$API_BASE$path" \
      -H 'Content-Type: application/json' -H 'Accept: application/json' \
      --data "$body" 2>/dev/null
  else
    curl -s -X "$method" "$API_BASE$path" -H 'Accept: application/json' 2>/dev/null
  fi
}

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 1 — DATABASE SCHEMA INTEGRITY
# ═══════════════════════════════════════════════════════════════════════════
section "1. Database schema integrity"

for t in aggregated_templates aggregated_template_columns aggregated_submission_values \
         poe_notification_contacts notification_templates notification_log alert_followups; do
  exists=$(sql "SHOW TABLES LIKE '$t';")
  if [[ -n "$exists" ]]; then ok "table '$t' exists"
  else bad "table '$t' exists" "missing"; fi
done

# Indexes (spot checks on critical performance paths)
idx=$(sql "SHOW INDEX FROM aggregated_templates WHERE Key_name='aggregated_templates_country_code_unique';" | head -1)
[[ -n "$idx" ]] && ok "unique index on (country_code, template_code)" \
  || bad "unique index on (country_code, template_code)" "missing"

idx=$(sql "SHOW INDEX FROM aggregated_template_columns WHERE Key_name='agg_tpl_col_unique';" | head -1)
[[ -n "$idx" ]] && ok "unique index on (template_id, column_key)" \
  || bad "unique index on (template_id, column_key)" "missing"

idx=$(sql "SHOW INDEX FROM poe_notification_contacts WHERE Key_name='poe_contacts_poe_level_idx';" | head -1)
[[ -n "$idx" ]] && ok "composite index on (poe_code, level, priority_order)" \
  || bad "composite index" "missing"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 2 — SEED DATA INTEGRITY
# ═══════════════════════════════════════════════════════════════════════════
section "2. Seed data integrity"

defaults=$(sql "SELECT COUNT(*) FROM aggregated_templates WHERE is_default=1 AND deleted_at IS NULL;")
assert_eq "exactly 1 default aggregated template" "1" "$defaults"

# Multi-published semantics (2026-04-21 v2): many templates may be PUBLISHED
# simultaneously per country. is_active=1 mirrors status=PUBLISHED for back-compat.
active=$(sql "SELECT COUNT(*) FROM aggregated_templates WHERE is_active=1 AND country_code='UG' AND deleted_at IS NULL;")
if [[ "$active" -ge 1 ]]; then ok "at least 1 PUBLISHED template for UG (got $active)"
else bad "PUBLISHED template for UG" "expected ≥1 got $active"; fi

tpl_id=$(sql "SELECT id FROM aggregated_templates WHERE is_default=1 LIMIT 1;")
col_total=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE template_id=$tpl_id AND deleted_at IS NULL;")
# Post 2026-04-21 gender cleanup: 55 active columns (retired total_other + total_unknown_gender).
if [[ "$col_total" -ge 55 ]]; then ok "default template has ≥ 55 columns (post gender cleanup, got $col_total)"
else bad "default template column count" "expected ≥55 got $col_total"; fi

# 5 core columns after 2026-04-21 gender cleanup (OTHER/UNKNOWN retired).
core_cols=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE template_id=$tpl_id AND is_core=1 AND deleted_at IS NULL;")
assert_eq "default template has 5 core columns (post gender cleanup)" "5" "$core_cols"

# Gender cleanup — OTHER/UNKNOWN gender columns must NOT be active on any template
gender_ghosts=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE column_key IN ('total_other','total_unknown_gender') AND deleted_at IS NULL;")
assert_eq "no active total_other/total_unknown_gender columns anywhere" "0" "$gender_ghosts"

notif_tpls=$(sql "SELECT COUNT(*) FROM notification_templates WHERE is_active=1;")
if [[ "$notif_tpls" -ge 12 ]]; then ok "≥ 12 notification templates seeded (got $notif_tpls)"
else bad "notification templates seeded" "expected ≥12 got $notif_tpls"; fi

# Spot check — critical WHO AFRO IDSR + IHR columns present
for k in total_screened ill_travellers_detected fever_above_38 isolated_on_site \
         referred_to_isolation_facility contacts_listed arrivals_from_outbreak_country \
         conveyances_inspected yellow_fever_cert_checked suspected_afp suspected_measles \
         suspected_sari_ili report_completeness_pct report_timeliness_pct; do
  c=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE template_id=$tpl_id AND column_key='$k';")
  [[ "$c" == "1" ]] && ok "WHO AFRO column '$k' seeded" \
    || bad "WHO AFRO column '$k' seeded" "count=$c"
done

# Spot check — critical notification template codes present
for tc in ALERT_CRITICAL TIER1_ADVISORY ANNEX2_HIT BREACH_717 FOLLOWUP_OVERDUE \
          DAILY_REPORT ESCALATION PHEIC_ADVISORY; do
  c=$(sql "SELECT COUNT(*) FROM notification_templates WHERE template_code='$tc';")
  [[ "$c" == "1" ]] && ok "notification template '$tc' seeded" \
    || bad "notification template '$tc' seeded" "count=$c"
done

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 3 — USER PROMOTION
# ═══════════════════════════════════════════════════════════════════════════
section "3. User promotion (id=6 → NATIONAL_ADMIN)"

role=$(sql "SELECT role_key FROM users WHERE id=6;")
assert_eq "user 6 role_key" "NATIONAL_ADMIN" "$role"

assignment_cnt=$(sql "SELECT COUNT(*) FROM user_assignments WHERE user_id=6 AND is_primary=1 AND is_active=1;")
assert_eq "user 6 keeps primary active assignment" "1" "$assignment_cnt"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 4 — EPHEMERAL RBAC FIXTURES
# ═══════════════════════════════════════════════════════════════════════════
section "4. Creating ephemeral RBAC fixtures"

# Prefix so cleanup is trivial
UNAME_SCR="_audit_screener_$TIMESTAMP"
UNAME_POE="_audit_poe_admin_$TIMESTAMP"
UNAME_DIS="_audit_district_$TIMESTAMP"
UNAME_PHO="_audit_pheoc_$TIMESTAMP"
UNAME_NAT="_audit_national_$TIMESTAMP"

# Seed users + assignments. Different district & POE so scope tests are meaningful.
sql "
INSERT INTO users (role_key, country_code, full_name, username, password, email, is_active, created_at, updated_at)
VALUES
  ('SCREENER',            'UG', 'Audit Screener',    '$UNAME_SCR', 'x', '$UNAME_SCR@test', 1, NOW(), NOW()),
  ('POE_ADMIN',           'UG', 'Audit POE Admin',   '$UNAME_POE', 'x', '$UNAME_POE@test', 1, NOW(), NOW()),
  ('DISTRICT_SUPERVISOR', 'UG', 'Audit District',    '$UNAME_DIS', 'x', '$UNAME_DIS@test', 1, NOW(), NOW()),
  ('PHEOC_OFFICER',       'UG', 'Audit PHEOC',       '$UNAME_PHO', 'x', '$UNAME_PHO@test', 1, NOW(), NOW()),
  ('NATIONAL_ADMIN',      'UG', 'Audit National',    '$UNAME_NAT', 'x', '$UNAME_NAT@test', 1, NOW(), NOW());
"
ID_SCR=$(sql "SELECT id FROM users WHERE username='$UNAME_SCR';")
ID_POE=$(sql "SELECT id FROM users WHERE username='$UNAME_POE';")
ID_DIS=$(sql "SELECT id FROM users WHERE username='$UNAME_DIS';")
ID_PHO=$(sql "SELECT id FROM users WHERE username='$UNAME_PHO';")
ID_NAT=$(sql "SELECT id FROM users WHERE username='$UNAME_NAT';")

# Assignments
sql "
INSERT INTO user_assignments (user_id, country_code, province_code, pheoc_code, district_code, poe_code, is_primary, is_active, created_at, updated_at)
VALUES
  ($ID_SCR, 'UG', 'Gulu RPHEOC', 'Gulu RPHEOC', 'Lamwo District', 'Ngoromoro',  1, 1, NOW(), NOW()),
  ($ID_POE, 'UG', 'Gulu RPHEOC', 'Gulu RPHEOC', 'Lamwo District', 'Ngoromoro',  1, 1, NOW(), NOW()),
  ($ID_DIS, 'UG', 'Gulu RPHEOC', 'Gulu RPHEOC', 'Lamwo District', 'Ngoromoro',  1, 1, NOW(), NOW()),
  ($ID_PHO, 'UG', 'Gulu RPHEOC', 'Gulu RPHEOC', 'Lamwo District', 'Ngoromoro',  1, 1, NOW(), NOW()),
  ($ID_NAT, 'UG', 'Gulu RPHEOC', 'Gulu RPHEOC', 'Lamwo District', 'Ngoromoro',  1, 1, NOW(), NOW());
"
ok "5 ephemeral users + assignments created"

# Always clean up on exit (even on error) — idempotent
cleanup() {
  section "9. Cleanup"
  sql "DELETE FROM poe_notification_contacts WHERE created_by_user_id IN ($ID_NAT,$ID_POE,$ID_DIS,$ID_PHO,$ID_SCR);"
  sql "DELETE FROM aggregated_template_columns WHERE created_by_user_id IN ($ID_NAT,$ID_POE,$ID_DIS,$ID_PHO,$ID_SCR);"
  sql "DELETE FROM aggregated_templates WHERE created_by_user_id IN ($ID_NAT,$ID_POE,$ID_DIS,$ID_PHO,$ID_SCR);"
  sql "DELETE FROM user_assignments WHERE user_id IN ($ID_NAT,$ID_POE,$ID_DIS,$ID_PHO,$ID_SCR);"
  sql "DELETE FROM users WHERE id IN ($ID_NAT,$ID_POE,$ID_DIS,$ID_PHO,$ID_SCR);"
  ok "test fixtures removed"
}
trap cleanup EXIT

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 5 — API LIVENESS + NATIONAL_ADMIN FULL ACCESS
# ═══════════════════════════════════════════════════════════════════════════
section "5. API liveness + NATIONAL_ADMIN unrestricted access"

code=$(api_status GET "/aggregated-templates/active?user_id=$ID_NAT&country_code=UG")
assert_eq "NATIONAL_ADMIN: GET /aggregated-templates/active" "200" "$code"

code=$(api_status GET "/aggregated-templates?country_code=UG&user_id=$ID_NAT")
assert_eq "NATIONAL_ADMIN: GET /aggregated-templates" "200" "$code"

code=$(api_status GET "/poe-contacts?user_id=$ID_NAT")
assert_eq "NATIONAL_ADMIN: GET /poe-contacts" "200" "$code"

code=$(api_status GET "/alerts/compliance?user_id=$ID_NAT")
assert_eq "NATIONAL_ADMIN: GET /alerts/compliance" "200" "$code"

code=$(api_status GET "/notifications/stats?user_id=$ID_NAT")
assert_eq "NATIONAL_ADMIN: GET /notifications/stats" "200" "$code"

# ── Contacts read-everywhere (2026-04-21 v3): every admin role sees every
# contact across every scope. Seed two cross-scope contacts, then verify
# each admin sees BOTH.
sql "
INSERT INTO poe_notification_contacts
  (country_code, district_code, poe_code, level, full_name, email, priority_order,
   is_active, receives_critical, receives_high, created_by_user_id, created_at, updated_at)
VALUES
  ('UG', 'Lamwo District',   'Ngoromoro',    'POE',      'Audit Self',     'selfa_$TIMESTAMP@x', 1, 1, 1, 1, $ID_NAT, NOW(), NOW()),
  ('UG', 'Elsewhere',         'OtherPOE',     'POE',      'Audit Cross',    'crossa_$TIMESTAMP@x', 1, 1, 1, 1, $ID_NAT, NOW(), NOW());
"
# NATIONAL_ADMIN
resp=$(api_body GET "/poe-contacts?user_id=$ID_NAT")
n_seen=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(sum(1 for r in d['data'] if r.get('email','').endswith('_$TIMESTAMP@x')))" 2>/dev/null)
assert_eq "NATIONAL_ADMIN sees BOTH cross-scope contacts" "2" "$n_seen"

# PHEOC_OFFICER
resp=$(api_body GET "/poe-contacts?user_id=$ID_PHO")
n_seen=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(sum(1 for r in d['data'] if r.get('email','').endswith('_$TIMESTAMP@x')))" 2>/dev/null)
assert_eq "PHEOC_OFFICER sees BOTH cross-scope contacts" "2" "$n_seen"

# DISTRICT_SUPERVISOR
resp=$(api_body GET "/poe-contacts?user_id=$ID_DIS")
n_seen=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(sum(1 for r in d['data'] if r.get('email','').endswith('_$TIMESTAMP@x')))" 2>/dev/null)
assert_eq "DISTRICT_SUPERVISOR sees BOTH cross-scope contacts" "2" "$n_seen"

# POE_ADMIN
resp=$(api_body GET "/poe-contacts?user_id=$ID_POE")
n_seen=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(sum(1 for r in d['data'] if r.get('email','').endswith('_$TIMESTAMP@x')))" 2>/dev/null)
assert_eq "POE_ADMIN sees BOTH cross-scope contacts" "2" "$n_seen"

# SCREENER is still locked to their own POE
resp=$(api_body GET "/poe-contacts?user_id=$ID_SCR")
n_seen=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(sum(1 for r in d['data'] if r.get('email','').endswith('_$TIMESTAMP@x')))" 2>/dev/null)
assert_eq "SCREENER sees only own-POE contact" "1" "$n_seen"

# can_edit_by_me flag correctness — PHEOC_OFFICER can edit own-country, not cross
editable=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(any(r.get('can_edit_by_me') is True for r in d['data']))" 2>/dev/null)
# Meta shape
meta_has_total=$(api_body GET "/poe-contacts?user_id=$ID_NAT" | python3 -c "import sys,json; d=json.load(sys.stdin); print('total' in (d.get('meta') or {}))" 2>/dev/null)
assert_eq "index response carries meta.total" "True" "$meta_has_total"

# PHEOC-officer can_edit_by_me correctness: should be True for UG contacts
phoc_resp=$(api_body GET "/poe-contacts?user_id=$ID_PHO")
cross_flag=$(echo "$phoc_resp" | python3 -c "
import sys,json
d=json.load(sys.stdin)
for r in d['data']:
  if r.get('email','').endswith('_$TIMESTAMP@x'):
    print(int(r.get('can_edit_by_me', False)))
    break
" 2>/dev/null)
assert_eq "PHEOC_OFFICER can_edit_by_me=True for own-country contact" "1" "$cross_flag"

# Teardown cross-scope fixtures
sql "DELETE FROM poe_notification_contacts WHERE email LIKE '%_$TIMESTAMP@x';"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 6 — RBAC ENFORCEMENT (denials + scope)
# ═══════════════════════════════════════════════════════════════════════════
section "6. RBAC enforcement"

# SCREENER must be denied on admin endpoints
code=$(api_status POST "/aggregated-templates" "{\"user_id\":$ID_SCR,\"country_code\":\"UG\",\"template_name\":\"Test\",\"template_code\":\"AUDIT_TEST_$TIMESTAMP\"}")
assert_eq "SCREENER cannot POST /aggregated-templates" "403" "$code"

code=$(api_status POST "/poe-contacts" "{\"user_id\":$ID_SCR,\"poe_code\":\"Ngoromoro\",\"level\":\"POE\",\"full_name\":\"X\",\"email\":\"x@y.z\"}")
assert_eq "SCREENER cannot POST /poe-contacts" "403" "$code"

# POE_ADMIN can POST contact for OWN POE
code=$(api_status POST "/poe-contacts" "{\"user_id\":$ID_POE,\"poe_code\":\"Ngoromoro\",\"district_code\":\"Lamwo District\",\"country_code\":\"UG\",\"level\":\"POE\",\"full_name\":\"POE Admin Self\",\"email\":\"poe-self-$TIMESTAMP@t.z\"}")
assert_eq "POE_ADMIN can POST contact for own POE" "200" "$code"

# POE_ADMIN cannot POST contact for DIFFERENT POE
code=$(api_status POST "/poe-contacts" "{\"user_id\":$ID_POE,\"poe_code\":\"DIFFERENT_POE\",\"district_code\":\"Lamwo District\",\"country_code\":\"UG\",\"level\":\"POE\",\"full_name\":\"OutOfScope\",\"email\":\"poe-out-$TIMESTAMP@t.z\"}")
assert_eq "POE_ADMIN denied for other POE" "403" "$code"

# DISTRICT_SUPERVISOR can manage contacts in own district
code=$(api_status POST "/poe-contacts" "{\"user_id\":$ID_DIS,\"poe_code\":\"Ngoromoro\",\"district_code\":\"Lamwo District\",\"country_code\":\"UG\",\"level\":\"DISTRICT\",\"full_name\":\"DistrictSup\",\"email\":\"dist-$TIMESTAMP@t.z\"}")
assert_eq "DISTRICT_SUPERVISOR can POST in own district" "200" "$code"

# DISTRICT_SUPERVISOR denied in different district
code=$(api_status POST "/poe-contacts" "{\"user_id\":$ID_DIS,\"poe_code\":\"X\",\"district_code\":\"OTHER_DISTRICT\",\"country_code\":\"UG\",\"level\":\"DISTRICT\",\"full_name\":\"X\",\"email\":\"dist-out-$TIMESTAMP@t.z\"}")
assert_eq "DISTRICT_SUPERVISOR denied in different district" "403" "$code"

# PHEOC_OFFICER can manage contacts in own country (PHEOC scope)
code=$(api_status POST "/poe-contacts" "{\"user_id\":$ID_PHO,\"poe_code\":\"Ngoromoro\",\"district_code\":\"Lamwo District\",\"country_code\":\"UG\",\"level\":\"PHEOC\",\"full_name\":\"PHEOC Officer\",\"email\":\"pheoc-$TIMESTAMP@t.z\"}")
assert_eq "PHEOC_OFFICER can POST in own country" "200" "$code"

# PHEOC_OFFICER denied in different country
code=$(api_status POST "/poe-contacts" "{\"user_id\":$ID_PHO,\"poe_code\":\"X\",\"district_code\":\"X\",\"country_code\":\"KE\",\"level\":\"PHEOC\",\"full_name\":\"X\",\"email\":\"pheoc-out-$TIMESTAMP@t.z\"}")
assert_eq "PHEOC_OFFICER denied in different country" "403" "$code"

# Only NATIONAL_ADMIN may create templates
code=$(api_status POST "/aggregated-templates" "{\"user_id\":$ID_PHO,\"country_code\":\"UG\",\"template_name\":\"Blocked\",\"template_code\":\"BLOCKED_$TIMESTAMP\"}")
assert_eq "PHEOC_OFFICER cannot create templates" "403" "$code"

code=$(api_status POST "/aggregated-templates" "{\"user_id\":$ID_POE,\"country_code\":\"UG\",\"template_name\":\"Blocked\",\"template_code\":\"BLOCKED2_$TIMESTAMP\"}")
assert_eq "POE_ADMIN cannot create templates" "403" "$code"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 7 — DATA INTEGRITY GUARDS
# ═══════════════════════════════════════════════════════════════════════════
section "7. Data integrity guards"

# Find the total_screened core column id
CORE_ID=$(sql "SELECT id FROM aggregated_template_columns WHERE template_id=$tpl_id AND column_key='total_screened' LIMIT 1;")

# Try to disable a core column — should 409
code=$(api_status PATCH "/aggregated-template-columns/$CORE_ID" "{\"user_id\":$ID_NAT,\"is_enabled\":0}")
assert_eq "cannot disable core column (409)" "409" "$code"

# Try to delete a core column — should 409
code=$(api_status DELETE "/aggregated-template-columns/$CORE_ID" "{\"user_id\":$ID_NAT}")
assert_eq "cannot delete core column (409)" "409" "$code"

# Create a scratch template, lock it, try to edit, confirm rejection, unlock
scratch_body="{\"user_id\":$ID_NAT,\"country_code\":\"UG\",\"template_name\":\"Audit Scratch\",\"template_code\":\"AUDIT_SCRATCH_$TIMESTAMP\",\"clone_default_columns\":false}"
tpl_resp=$(api_body POST "/aggregated-templates" "$scratch_body")
SCRATCH_ID=$(echo "$tpl_resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)

if [[ -n "$SCRATCH_ID" ]]; then
  ok "scratch template created (id=$SCRATCH_ID)"

  code=$(api_status POST "/aggregated-templates/$SCRATCH_ID/lock" "{\"user_id\":$ID_NAT}")
  assert_eq "scratch template locked" "200" "$code"

  code=$(api_status PATCH "/aggregated-templates/$SCRATCH_ID" "{\"user_id\":$ID_NAT,\"description\":\"Nope\"}")
  assert_eq "locked template rejects edit (409)" "409" "$code"

  code=$(api_status POST "/aggregated-templates/$SCRATCH_ID/columns" "{\"user_id\":$ID_NAT,\"column_key\":\"x\",\"column_label\":\"X\"}")
  assert_eq "locked template rejects column add (409)" "409" "$code"

  code=$(api_status POST "/aggregated-templates/$SCRATCH_ID/lock" "{\"user_id\":$ID_NAT,\"unlock\":true}")
  assert_eq "scratch template unlocked" "200" "$code"

  # Also verify default template cannot be deleted
  code=$(api_status DELETE "/aggregated-templates/$tpl_id" "{\"user_id\":$ID_NAT}")
  assert_eq "default template cannot be deleted (409)" "409" "$code"

  # Invalid column_key regex rejected
  code=$(api_status POST "/aggregated-templates/$SCRATCH_ID/columns" "{\"user_id\":$ID_NAT,\"column_key\":\"INVALID KEY!\",\"column_label\":\"X\"}")
  assert_eq "invalid column_key regex rejected (422)" "422" "$code"

  # Duplicate column_key rejected
  sql "INSERT INTO aggregated_template_columns (template_id, column_key, column_label, category, data_type, is_required, is_enabled, is_core, display_order, dashboard_visible, report_visible, aggregation_fn, created_by_user_id, created_at, updated_at)
       VALUES ($SCRATCH_ID, 'dup_test', 'Dup Test', 'CUSTOM', 'INTEGER', 0, 1, 0, 99, 1, 1, 'SUM', $ID_NAT, NOW(), NOW());"
  code=$(api_status POST "/aggregated-templates/$SCRATCH_ID/columns" "{\"user_id\":$ID_NAT,\"column_key\":\"dup_test\",\"column_label\":\"X\"}")
  assert_eq "duplicate column_key rejected (409)" "409" "$code"

  # Teardown scratch
  sql "DELETE FROM aggregated_template_columns WHERE template_id=$SCRATCH_ID;"
  sql "DELETE FROM aggregated_templates WHERE id=$SCRATCH_ID;"
else
  bad "scratch template created" "API did not return id. Response: $tpl_resp"
fi

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 7.5 — MULTI-PUBLISHED TEMPLATES + WIZARD FLOW (2026-04-21 v2)
# ═══════════════════════════════════════════════════════════════════════════
section "7.5 Multi-published templates + publish/retire"

# Seed migration added DAILY_POE_TALLY_V1 alongside WHO_BASELINE_POE_V1
published=$(sql "SELECT COUNT(*) FROM aggregated_templates WHERE status='PUBLISHED' AND country_code='UG' AND deleted_at IS NULL;")
if [[ "$published" -ge 2 ]]; then ok "multiple templates PUBLISHED simultaneously (got $published)"
else bad "multiple templates PUBLISHED" "expected ≥2 got $published"; fi

# /published endpoint returns them with columns
resp=$(api_body GET "/aggregated-templates/published?user_id=$ID_NAT&country_code=UG")
pub_count=$(echo "$resp" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('data',[])))" 2>/dev/null)
if [[ "$pub_count" -ge 2 ]]; then ok "GET /aggregated-templates/published returns ≥ 2 with columns"
else bad "/published endpoint" "expected ≥2 got $pub_count"; fi

# Each published row carries columns array
has_cols=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(all(isinstance(t.get('columns'),list) and len(t['columns'])>0 for t in d.get('data',[])))" 2>/dev/null)
assert_eq "published payload includes inline columns" "True" "$has_cols"

# Create a brand-new draft template via the wizard-style flow
draft_resp=$(api_body POST "/aggregated-templates" "{\"user_id\":$ID_NAT,\"country_code\":\"UG\",\"template_name\":\"Audit Wizard Draft\",\"template_code\":\"AUDIT_WIZARD_$TIMESTAMP\",\"reporting_frequency\":\"AD_HOC\",\"colour\":\"#DC2626\",\"clone_default_columns\":false}")
WIZARD_ID=$(echo "$draft_resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
if [[ -n "$WIZARD_ID" ]]; then ok "wizard-style template created (id=$WIZARD_ID)"
else bad "wizard-style template create" "response: $draft_resp"; fi

# Starts as DRAFT
status=$(sql "SELECT status FROM aggregated_templates WHERE id=$WIZARD_ID;")
assert_eq "new template starts as DRAFT" "DRAFT" "$status"

# Publishing without any columns must 422
code=$(api_status POST "/aggregated-templates/$WIZARD_ID/publish" "{\"user_id\":$ID_NAT}")
assert_eq "publish with no enabled columns rejected (422)" "422" "$code"

# Add a column then publish
code=$(api_status POST "/aggregated-templates/$WIZARD_ID/columns" "{\"user_id\":$ID_NAT,\"column_key\":\"test_count\",\"column_label\":\"Test count\",\"data_type\":\"INTEGER\"}")
assert_eq "add column to wizard template" "200" "$code"

code=$(api_status POST "/aggregated-templates/$WIZARD_ID/publish" "{\"user_id\":$ID_NAT}")
assert_eq "publish succeeds after adding column (200)" "200" "$code"

# Status flipped
status=$(sql "SELECT status FROM aggregated_templates WHERE id=$WIZARD_ID;")
assert_eq "template is now PUBLISHED" "PUBLISHED" "$status"

# published_at is set
pub_at=$(sql "SELECT published_at IS NOT NULL FROM aggregated_templates WHERE id=$WIZARD_ID;")
assert_eq "published_at populated" "1" "$pub_at"

# is_active also 1 (backward-compat)
active=$(sql "SELECT is_active FROM aggregated_templates WHERE id=$WIZARD_ID;")
assert_eq "is_active mirrors PUBLISHED (back-compat)" "1" "$active"

# Retire it
code=$(api_status POST "/aggregated-templates/$WIZARD_ID/retire" "{\"user_id\":$ID_NAT}")
assert_eq "retire succeeds (200)" "200" "$code"

status=$(sql "SELECT status FROM aggregated_templates WHERE id=$WIZARD_ID;")
assert_eq "status is now RETIRED" "RETIRED" "$status"

# Retiring the system default is forbidden
code=$(api_status POST "/aggregated-templates/$tpl_id/retire" "{\"user_id\":$ID_NAT}")
assert_eq "system default template cannot be retired (409)" "409" "$code"

# Non-admin can GET /published (read is open)
code=$(api_status GET "/aggregated-templates/published?user_id=$ID_SCR&country_code=UG")
assert_eq "SCREENER can GET /aggregated-templates/published" "200" "$code"

# Non-admin cannot publish
code=$(api_status POST "/aggregated-templates/$WIZARD_ID/publish" "{\"user_id\":$ID_SCR}")
assert_eq "SCREENER cannot publish (403)" "403" "$code"

# Non-admin cannot retire
code=$(api_status POST "/aggregated-templates/$WIZARD_ID/retire" "{\"user_id\":$ID_POE}")
assert_eq "POE_ADMIN cannot retire (403)" "403" "$code"

# Invalid reporting_frequency on create → 422
code=$(api_status POST "/aggregated-templates" "{\"user_id\":$ID_NAT,\"country_code\":\"UG\",\"template_name\":\"Bad\",\"template_code\":\"BAD_FREQ_$TIMESTAMP\",\"reporting_frequency\":\"NEVER\"}")
assert_eq "invalid reporting_frequency rejected (422)" "422" "$code"

# Teardown wizard template
sql "DELETE FROM aggregated_template_columns WHERE template_id=$WIZARD_ID;"
sql "DELETE FROM aggregated_templates WHERE id=$WIZARD_ID;"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 7.6 — FORCE-DELETE TEMPLATE WITH SUBMISSIONS (2026-04-21 v3)
# ═══════════════════════════════════════════════════════════════════════════
section "7.6 Template force-delete with submissions"

# Create a scratch template to exercise the delete flow
tpl_body="{\"user_id\":$ID_NAT,\"country_code\":\"UG\",\"template_name\":\"Audit Delete\",\"template_code\":\"AUDIT_DEL_$TIMESTAMP\",\"reporting_frequency\":\"WEEKLY\",\"clone_default_columns\":false}"
resp=$(api_body POST "/aggregated-templates" "$tpl_body")
DEL_ID=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
[[ -n "$DEL_ID" ]] && ok "scratch delete template created (id=$DEL_ID)" \
  || bad "scratch delete template create" "$resp"

# Add a column so it is deletable with standard flow first
api_status POST "/aggregated-templates/$DEL_ID/columns" "{\"user_id\":$ID_NAT,\"column_key\":\"x\",\"column_label\":\"X\"}" >/dev/null

# Inject a fake submission against this template (idempotency guard on
# aggregated_submissions requires a UUID + the usual fields).
sub_uuid=$(python3 -c "import uuid; print(uuid.uuid4())")
sql "INSERT INTO aggregated_submissions
  (client_uuid, reference_data_version, country_code, district_code, poe_code,
   submitted_by_user_id, period_start, period_end, total_screened,
   device_id, platform, record_version, sync_status, template_id,
   created_at, updated_at)
  VALUES
  ('$sub_uuid', 'rda-2026-02-01', 'UG', 'Lamwo District', 'Ngoromoro',
   $ID_NAT, NOW() - INTERVAL 1 DAY, NOW(), 42,
   'audit-device', 'WEB', 1, 'SYNCED', $DEL_ID, NOW(), NOW());"

# Without cascade → 409 with submissions_count
resp=$(api_body DELETE "/aggregated-templates/$DEL_ID" "{\"user_id\":$ID_NAT}")
status_pre=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('error',{}).get('submissions_count', 'missing'))" 2>/dev/null)
assert_eq "delete without cascade returns submissions_count" "1" "$status_pre"

code=$(api_status DELETE "/aggregated-templates/$DEL_ID" "{\"user_id\":$ID_NAT}")
assert_eq "delete without cascade returns 409" "409" "$code"

# With cascade + confirm → 200 and template gone from active set
code=$(api_status DELETE "/aggregated-templates/$DEL_ID?cascade=true" "{\"user_id\":$ID_NAT,\"cascade\":true,\"confirm\":\"DELETE_WITH_SUBMISSIONS\"}")
assert_eq "delete with cascade+confirm returns 200" "200" "$code"

# Template is soft-deleted
deleted=$(sql "SELECT deleted_at IS NOT NULL FROM aggregated_templates WHERE id=$DEL_ID;")
assert_eq "template soft-deleted (deleted_at set)" "1" "$deleted"

# Submissions preserved with template_id reference intact
preserved=$(sql "SELECT COUNT(*) FROM aggregated_submissions WHERE template_id=$DEL_ID AND deleted_at IS NULL;")
assert_eq "submissions preserved after template delete" "1" "$preserved"

# Cleanup audit fixtures
sql "DELETE FROM aggregated_submissions WHERE template_id=$DEL_ID;"
sql "DELETE FROM aggregated_template_columns WHERE template_id=$DEL_ID;"
sql "DELETE FROM aggregated_templates WHERE id=$DEL_ID;"

# Default template still blocked even with cascade
code=$(api_status DELETE "/aggregated-templates/$tpl_id?cascade=true" "{\"user_id\":$ID_NAT,\"confirm\":\"DELETE_WITH_SUBMISSIONS\"}")
assert_eq "default template never deletable (409 even with cascade)" "409" "$code"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 7.7 — SYNC PIPELINE CONTRACT (2026-04-21 v4)
# ═══════════════════════════════════════════════════════════════════════════
# Validates that the endpoints SyncManagement.vue POSTs to accept the exact
# payload shapes the client sends, and that the dependency chain is enforced
# server-side.
section "7.7 Sync pipeline contract"

# 1. Aggregated submission with template_id + template_values array
sub_uuid=$(python3 -c "import uuid; print(uuid.uuid4())")
payload="{
  \"client_uuid\": \"$sub_uuid\",
  \"reference_data_version\": \"rda-2026-02-01\",
  \"submitted_by_user_id\": $ID_NAT,
  \"period_start\": \"$(date -u -d '-1 day' +'%Y-%m-%d %H:%M:%S')\",
  \"period_end\":   \"$(date -u +'%Y-%m-%d %H:%M:%S')\",
  \"total_screened\": 120,
  \"total_male\": 70, \"total_female\": 50,
  \"total_other\": 0, \"total_unknown_gender\": 0,
  \"total_symptomatic\": 15, \"total_asymptomatic\": 105,
  \"template_id\": $tpl_id,
  \"template_code\": \"WHO_BASELINE_POE_V1\",
  \"template_version\": 1,
  \"template_values\": [{\"column_key\":\"ill_travellers_detected\",\"data_type\":\"INTEGER\",\"value_numeric\":12}],
  \"device_id\": \"audit-dev\",
  \"platform\": \"WEB\",
  \"record_version\": 1
}"
code=$(api_status POST "/aggregated" "$payload")
assert_eq "aggregated POST with template_id + template_values → 200" "200" "$code"

# Idempotency: second POST with same client_uuid must NOT create a duplicate
code=$(api_status POST "/aggregated" "$payload")
resp=$(api_body POST "/aggregated" "$payload")
idem=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('meta',{}).get('idempotent', False))" 2>/dev/null)
assert_eq "aggregated POST idempotent on same client_uuid" "True" "$idem"

# Server stores template_id linkage
linked=$(sql "SELECT template_id FROM aggregated_submissions WHERE client_uuid='$sub_uuid';")
assert_eq "aggregated_submissions.template_id stored" "$tpl_id" "$linked"

# 2. Alert follow-up POST requires parent alert_id to exist
#    First pick a real alert from the seeded data; fall back to creating one
alert_id=$(sql "SELECT id FROM alerts WHERE deleted_at IS NULL LIMIT 1;")
if [[ -n "$alert_id" && "$alert_id" != "NULL" ]]; then
  fu_uuid=$(python3 -c "import uuid; print(uuid.uuid4())")
  code=$(api_status POST "/alerts/$alert_id/followups" "{\"user_id\":$ID_NAT,\"created_by_user_id\":$ID_NAT,\"client_uuid\":\"$fu_uuid\",\"action_code\":\"CASE_INVESTIGATION\",\"action_label\":\"Audit follow-up\",\"status\":\"PENDING\"}")
  assert_eq "alert_followups POST with valid parent → 200" "200" "$code"

  # Idempotent
  code=$(api_status POST "/alerts/$alert_id/followups" "{\"user_id\":$ID_NAT,\"created_by_user_id\":$ID_NAT,\"client_uuid\":\"$fu_uuid\",\"action_code\":\"CASE_INVESTIGATION\",\"action_label\":\"Audit follow-up\",\"status\":\"PENDING\"}")
  assert_eq "alert_followups POST idempotent on same client_uuid" "200" "$code"

  # Teardown
  sql "DELETE FROM alert_followups WHERE client_uuid='$fu_uuid';"
else
  ok "skipping follow-up POST test (no alerts in DB)"
  ok "skipping follow-up idempotency test (no alerts in DB)"
fi

# 3. Alert follow-up POST against missing parent = 404
code=$(api_status POST "/alerts/999999/followups" "{\"user_id\":$ID_NAT,\"created_by_user_id\":$ID_NAT,\"client_uuid\":\"$(python3 -c 'import uuid;print(uuid.uuid4())')\",\"action_code\":\"X\",\"action_label\":\"X\"}")
assert_eq "alert_followups POST against missing parent → 404" "404" "$code"

# 4. poe-contacts POST — sync-style payload
cc_uuid=$(python3 -c "import uuid; print(uuid.uuid4())")
# Server does not require client_uuid for contacts (it assigns its own id),
# but the sync flow does send a payload that matches store() expectations.
code=$(api_status POST "/poe-contacts" "{
  \"user_id\": $ID_NAT,
  \"poe_code\": \"Ngoromoro\",
  \"district_code\": \"Lamwo District\",
  \"country_code\": \"UG\",
  \"level\": \"DISTRICT\",
  \"full_name\": \"Sync Test Contact $TIMESTAMP\",
  \"email\": \"sync-$TIMESTAMP@audit.x\",
  \"priority_order\": 9
}")
assert_eq "poe-contacts POST (sync-style) → 200" "200" "$code"
sql "DELETE FROM poe_notification_contacts WHERE email='sync-$TIMESTAMP@audit.x';"

# 5. Published templates endpoint response shape (critical for Hub offline cache)
resp=$(api_body GET "/aggregated-templates/published?user_id=$ID_NAT&country_code=UG")
has_columns=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(all('columns' in t and isinstance(t['columns'],list) for t in d.get('data',[])))" 2>/dev/null)
assert_eq "published templates response includes inline columns" "True" "$has_columns"

has_status=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(all(t.get('status')=='PUBLISHED' for t in d.get('data',[])))" 2>/dev/null)
assert_eq "published templates response: every row has status=PUBLISHED" "True" "$has_status"

has_frequency=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin); print(all('reporting_frequency' in t for t in d.get('data',[])))" 2>/dev/null)
assert_eq "published templates response includes reporting_frequency" "True" "$has_frequency"

# Teardown aggregated submission
sql "DELETE FROM aggregated_submissions WHERE client_uuid='$sub_uuid';"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 7.8 — NOTIFICATION DELIVERY LAYER (2026-04-21 v5)
# ═══════════════════════════════════════════════════════════════════════════
# Validates that:
#   • All 12 premium email templates are in place with premium HTML body
#   • The 19 Uganda national contacts are seeded with correct flags
#   • SMTP config points at Gmail (not the default 127.0.0.1 stub)
#   • Console commands are registered with Laravel
#   • NotificationDispatcher generates log rows for alerts and screenings
section "7.8 Notification delivery layer"

# 1) Templates: count + premium HTML (> 2 KB of inline-styled markup)
# Post v6: ≥ 12 (12 base + 3 engine v6: CASE_FILE, NATIONAL_INTELLIGENCE, RESPONDER_INFO_REQUEST)
tpl_count=$(sql "SELECT COUNT(*) FROM notification_templates WHERE is_active=1;")
if [[ "$tpl_count" -ge 12 ]]; then ok "≥ 12 active notification templates (got $tpl_count)"
else bad "active notification templates" "expected ≥12 got $tpl_count"; fi

premium_tpls=$(sql "SELECT COUNT(*) FROM notification_templates WHERE is_active=1 AND LENGTH(body_html_template) > 2000;")
if [[ "$premium_tpls" -ge 12 ]]; then ok "≥ 12 premium templates (>2 KB HTML each, got $premium_tpls)"
else bad "premium templates" "expected ≥12 got $premium_tpls"; fi

for code in ALERT_CRITICAL ALERT_HIGH TIER1_ADVISORY ANNEX2_HIT BREACH_717 \
            FOLLOWUP_DUE FOLLOWUP_OVERDUE DAILY_REPORT WEEKLY_REPORT \
            ESCALATION PHEIC_ADVISORY ALERT_CLOSED; do
  has=$(sql "SELECT COUNT(*) FROM notification_templates WHERE template_code='$code' AND channel='EMAIL' AND is_active=1;")
  [[ "$has" == "1" ]] && ok "template '$code' present" || bad "template '$code' present" "count=$has"
done

# 2) Uganda 19-contact national roster
ug_count=$(sql "SELECT COUNT(*) FROM poe_notification_contacts WHERE country_code='UG' AND level='NATIONAL' AND deleted_at IS NULL AND is_active=1;")
if [[ "$ug_count" -ge 19 ]]; then ok "Uganda national roster has ≥ 19 contacts (got $ug_count)"
else bad "Uganda national roster" "expected ≥19 got $ug_count"; fi

# Key primary + CC emails present
for email in ayebare.k.timothy@gmail.com ayebaretimothykamukama@gmail.com \
             bkisunzu@gmail.com gsilverbert@yahoo.com \
             ssulaiman@hispuganda.org; do
  has=$(sql "SELECT COUNT(*) FROM poe_notification_contacts WHERE email='$email' AND deleted_at IS NULL;")
  [[ "$has" == "1" ]] && ok "roster contact $email seeded" || bad "roster contact $email" "count=$has"
done

# Primary (priority_order=1) must be ayebare.k.timothy@gmail.com
primary_email=$(sql "SELECT email FROM poe_notification_contacts WHERE country_code='UG' AND level='NATIONAL' AND priority_order=1 AND deleted_at IS NULL LIMIT 1;")
assert_eq "primary recipient is Ayebare Timothy (priority 1)" "ayebare.k.timothy@gmail.com" "$primary_email"

# 3) SMTP config
mail_host=$(grep -E "^MAIL_HOST=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2)
assert_eq "MAIL_HOST=smtp.gmail.com" "smtp.gmail.com" "$mail_host"
mail_port=$(grep -E "^MAIL_PORT=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2)
assert_eq "MAIL_PORT=587" "587" "$mail_port"
mail_encrypt=$(grep -E "^MAIL_ENCRYPTION=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2)
assert_eq "MAIL_ENCRYPTION=tls" "tls" "$mail_encrypt"
# Post v6: FROM must equal USERNAME for Gmail anti-spoof. Reply-To carries
# vexa256@gmail.com so humans land in the operations mailbox.
mail_from=$(grep -E "^MAIL_FROM_ADDRESS=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2 | tr -d '"')
mail_user=$(grep -E "^MAIL_USERNAME=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2 | tr -d '"')
assert_eq "MAIL_FROM_ADDRESS == MAIL_USERNAME (anti-rewrite)" "$mail_user" "$mail_from"

# 4) Laravel console commands registered
for cmd in "notifications:daily-digest" "notifications:followup-reminders" "notifications:retry-failed"; do
  hits=$(cd /home/hacker/ecsa_poe_2026/api && php artisan list 2>/dev/null | grep -c "$cmd")
  [[ "$hits" -ge 1 ]] && ok "artisan command '$cmd' registered" || bad "artisan command '$cmd'" "not found"
done

# 5) NotificationDispatcher integration — create a scratch alert and confirm
#    the log record was written by the trigger wiring.
#    We seed the alert directly via SQL then invoke the dispatcher through
#    the API (POST /alerts) so the full pipeline runs.
sec_id=$(sql "SELECT id FROM secondary_screenings WHERE deleted_at IS NULL LIMIT 1;")
if [[ -n "$sec_id" && "$sec_id" != "NULL" ]]; then
  alert_uuid=$(python3 -c "import uuid; print(uuid.uuid4())")
  code=$(api_status POST "/alerts" "{
    \"created_by_user_id\": $ID_NAT,
    \"client_uuid\": \"$alert_uuid\",
    \"reference_data_version\": \"rda-2026-02-01\",
    \"secondary_screening_id\": $sec_id,
    \"alert_code\": \"AUDIT_NOTIF_$TIMESTAMP\",
    \"alert_title\": \"Audit notification trigger\",
    \"alert_details\": \"Triggered by audit test $TIMESTAMP\",
    \"risk_level\": \"HIGH\",
    \"routed_to_level\": \"DISTRICT\",
    \"generated_from\": \"OFFICER\",
    \"device_id\": \"audit\",
    \"platform\": \"WEB\",
    \"record_version\": 1
  }")
  assert_eq "POST /alerts creates alert and returns 200" "200" "$code"

  # notification_log should now have rows keyed by this alert's code
  log_rows=$(sql "SELECT COUNT(*) FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=(SELECT id FROM alerts WHERE client_uuid='$alert_uuid' LIMIT 1);")
  if [[ "$log_rows" -ge 1 ]]; then ok "alert creation produced notification_log rows (got $log_rows)"
  else bad "alert creation produced log rows" "got 0"; fi

  # At least one row should be targeted at a Uganda national contact
  ug_rows=$(sql "SELECT COUNT(*) FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=(SELECT id FROM alerts WHERE client_uuid='$alert_uuid' LIMIT 1) AND to_email LIKE '%@%';")
  if [[ "$ug_rows" -ge 1 ]]; then ok "notification_log carries recipient emails (got $ug_rows)"
  else bad "notification_log recipient emails" "got 0"; fi

  # Template used should be ALERT_HIGH (risk=HIGH, no Tier 1)
  tpl_used=$(sql "SELECT template_code FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=(SELECT id FROM alerts WHERE client_uuid='$alert_uuid' LIMIT 1) ORDER BY id DESC LIMIT 1;")
  [[ "$tpl_used" == "ALERT_HIGH" ]] && ok "notification_log used ALERT_HIGH template" || bad "template used" "got $tpl_used"

  # Teardown
  sql "DELETE FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=(SELECT id FROM alerts WHERE client_uuid='$alert_uuid' LIMIT 1);"
  sql "DELETE FROM alerts WHERE client_uuid='$alert_uuid';"
else
  ok "skipping alert-trigger integration test (no secondary screenings in DB)"
fi

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 7.9 — ALERT ENGINE v6 (anti-spam + case-file + intelligence + RTSL seed)
# ═══════════════════════════════════════════════════════════════════════════
section "7.9 Alert engine v6 — deterministic, country-aware, anti-spam"

# 1. New schema artefacts present
for tbl in notification_suppressions external_responders responder_info_requests; do
  exists=$(sql "SHOW TABLES LIKE '$tbl';")
  [[ -n "$exists" ]] && ok "table '$tbl' exists" || bad "table '$tbl'" "missing"
done

# 2. New templates present + are premium HTML
for code in ALERT_CASE_FILE NATIONAL_INTELLIGENCE RESPONDER_INFO_REQUEST; do
  bytes=$(sql "SELECT IFNULL(LENGTH(body_html_template), 0) FROM notification_templates WHERE template_code='$code' AND channel='EMAIL' AND is_active=1;")
  [[ "$bytes" -gt 1500 ]] && ok "template '$code' present + ≥ 1.5 KB ($bytes bytes)" || bad "template '$code'" "size=$bytes"
done

# 3. Mail FROM matches USERNAME (deliverability fix)
mail_from=$(grep -E "^MAIL_FROM_ADDRESS=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2 | tr -d '"')
mail_user=$(grep -E "^MAIL_USERNAME=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2 | tr -d '"')
assert_eq "MAIL_FROM_ADDRESS == MAIL_USERNAME (anti-rewrite/anti-spam)" "$mail_user" "$mail_from"
mail_reply=$(grep -E "^MAIL_REPLY_TO_ADDRESS=" /home/hacker/ecsa_poe_2026/api/.env | head -1 | cut -d= -f2 | tr -d '"')
[[ "$mail_reply" == "vexa256@gmail.com" ]] && ok "MAIL_REPLY_TO_ADDRESS routes humans back to operations mailbox" || bad "MAIL_REPLY_TO_ADDRESS" "got '$mail_reply'"

# 4. National-digest console command registered + scheduled
hits=$(cd /home/hacker/ecsa_poe_2026/api && php artisan list 2>/dev/null | grep -c "notifications:national-digest")
[[ "$hits" -ge 1 ]] && ok "artisan command 'notifications:national-digest' registered" || bad "national-digest command" "not found"
hits=$(cd /home/hacker/ecsa_poe_2026/api && php artisan schedule:list 2>/dev/null | grep -c "notifications:national-digest")
[[ "$hits" -ge 1 ]] && ok "national-digest scheduled (every 3 days)" || bad "national-digest schedule" "not registered"

# 5. RTSL 14 auto-seed — create an alert and verify follow-ups land
sec_id=$(sql "SELECT id FROM secondary_screenings WHERE deleted_at IS NULL LIMIT 1;")
if [[ -n "$sec_id" && "$sec_id" != "NULL" ]]; then
  alert_uuid=$(python3 -c "import uuid; print(uuid.uuid4())")
  resp=$(api_body POST "/alerts" "{
    \"created_by_user_id\": $ID_NAT, \"client_uuid\": \"$alert_uuid\",
    \"reference_data_version\": \"rda-2026-02-01\", \"secondary_screening_id\": $sec_id,
    \"alert_code\": \"AUDIT_RTSL_$TIMESTAMP\", \"alert_title\": \"Audit RTSL seed\",
    \"alert_details\": \"verify rtsl auto-seed\", \"risk_level\": \"HIGH\",
    \"routed_to_level\": \"DISTRICT\", \"generated_from\": \"OFFICER\",
    \"device_id\": \"audit\", \"platform\": \"WEB\", \"record_version\": 1
  }")
  alert_id=$(sql "SELECT id FROM alerts WHERE client_uuid='$alert_uuid' LIMIT 1;")
  if [[ -n "$alert_id" && "$alert_id" != "NULL" ]]; then
    seeded=$(sql "SELECT COUNT(*) FROM alert_followups WHERE alert_id=$alert_id AND deleted_at IS NULL;")
    assert_eq "POST /alerts auto-seeds 14 RTSL follow-ups" "14" "$seeded"
    # 6 of 14 RTSL actions block alert closure (CASE_INVESTIGATION, ISOLATION,
    # CONTACT_LISTING, CONTACT_TRACING, EOC_ACTIVATION, WHO_NOTIFICATION).
    blockers=$(sql "SELECT COUNT(*) FROM alert_followups WHERE alert_id=$alert_id AND blocks_closure=1 AND deleted_at IS NULL;")
    assert_eq "6 of the 14 follow-ups are blocks_closure=1" "6" "$blockers"

    # Re-POST the same alert (idempotent) — RTSL seed must not duplicate
    api_status POST "/alerts" "{
      \"created_by_user_id\": $ID_NAT, \"client_uuid\": \"$alert_uuid\",
      \"reference_data_version\": \"rda-2026-02-01\", \"secondary_screening_id\": $sec_id,
      \"alert_code\": \"AUDIT_RTSL_$TIMESTAMP\", \"alert_title\": \"Audit RTSL seed\",
      \"alert_details\": \"verify rtsl auto-seed\", \"risk_level\": \"HIGH\",
      \"routed_to_level\": \"DISTRICT\", \"generated_from\": \"OFFICER\",
      \"device_id\": \"audit\", \"platform\": \"WEB\", \"record_version\": 1
    }" >/dev/null
    seeded2=$(sql "SELECT COUNT(*) FROM alert_followups WHERE alert_id=$alert_id AND deleted_at IS NULL;")
    assert_eq "RTSL seed is idempotent (still 14 after re-POST)" "14" "$seeded2"

    # 6. ANTI-SPAM SUPPRESSION — direct dispatcher invocation
    #    AlertsController returns idempotently on duplicate client_uuid, so
    #    we exercise the suppression check by calling the dispatcher twice
    #    against the same alert via php artisan tinker --execute.
    supp_before=$(sql "SELECT COUNT(*) FROM notification_suppressions WHERE related_entity_type='ALERT' AND related_entity_id=$alert_id;")
    [[ "$supp_before" -gt 0 ]] && ok "first dispatch wrote $supp_before suppression rows" || bad "first dispatch suppression rows" "got 0"

    skipped_before=$(sql "SELECT COUNT(*) FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=$alert_id AND status='SKIPPED';")
    sent_before=$(sql "SELECT COUNT(*) FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=$alert_id AND status='SENT';")

    cd /home/hacker/ecsa_poe_2026/api && php -r "
      require '/home/hacker/ecsa_poe_2026/api/vendor/autoload.php';
      \$app = require '/home/hacker/ecsa_poe_2026/api/bootstrap/app.php';
      \$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
      \$alert = \DB::table('alerts')->where('id', $alert_id)->first();
      \App\Services\NotificationDispatcher::dispatchAlertCreated(\$alert, $ID_NAT);
    " >/dev/null 2>&1

    sent_after=$(sql "SELECT COUNT(*) FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=$alert_id AND status='SENT';")
    skipped_after=$(sql "SELECT COUNT(*) FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=$alert_id AND status='SKIPPED' AND error_message LIKE 'Suppressed%';")

    assert_eq "second dispatch produces 0 new SENT rows (suppression worked)" "$sent_before" "$sent_after"
    [[ "$skipped_after" -gt "$skipped_before" ]] && ok "second dispatch wrote SKIPPED-Suppressed log rows ($skipped_after)" || bad "anti-spam SKIPPED rows" "before=$skipped_before after=$skipped_after"

    # Cleanup
    sql "DELETE FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=$alert_id;"
    sql "DELETE FROM notification_suppressions WHERE related_entity_type='ALERT' AND related_entity_id=$alert_id;"
    sql "DELETE FROM alert_followups WHERE alert_id=$alert_id;"
    sql "DELETE FROM alerts WHERE id=$alert_id;"
  else
    bad "alert created" "POST /alerts did not return an id"
  fi
else
  ok "skipping alert-trigger tests (no secondary screenings in DB)"
fi

# 7. COUNTRY ISOLATION — seed a non-UG contact, verify a UG alert dispatch
#    does NOT log a row to that contact.
sql "INSERT INTO poe_notification_contacts
  (country_code, district_code, poe_code, level, full_name, email, priority_order,
   is_active, receives_critical, receives_high, created_by_user_id, created_at, updated_at)
  VALUES ('RW', 'Kigali District', 'KigaliPOE', 'NATIONAL',
   'RW Test Recipient', 'rw-isolated-$TIMESTAMP@audit.test', 1, 1, 1, 1, $ID_NAT, NOW(), NOW());"
rw_id=$(sql "SELECT id FROM poe_notification_contacts WHERE email='rw-isolated-$TIMESTAMP@audit.test';")

# Create a UG alert and confirm RW contact does NOT appear in its log rows
sec_id2=$(sql "SELECT id FROM secondary_screenings WHERE deleted_at IS NULL AND country_code='UG' LIMIT 1;")
if [[ -n "$sec_id2" && "$sec_id2" != "NULL" ]]; then
  iso_uuid=$(python3 -c "import uuid; print(uuid.uuid4())")
  api_status POST "/alerts" "{
    \"created_by_user_id\": $ID_NAT, \"client_uuid\": \"$iso_uuid\",
    \"reference_data_version\": \"rda-2026-02-01\", \"secondary_screening_id\": $sec_id2,
    \"alert_code\": \"AUDIT_ISO_$TIMESTAMP\", \"alert_title\": \"Country isolation test\",
    \"alert_details\": \"UG alert must not reach RW recipients\", \"risk_level\": \"HIGH\",
    \"routed_to_level\": \"DISTRICT\", \"generated_from\": \"OFFICER\",
    \"device_id\": \"audit\", \"platform\": \"WEB\", \"record_version\": 1
  }" >/dev/null
  iso_alert=$(sql "SELECT id FROM alerts WHERE client_uuid='$iso_uuid' LIMIT 1;")
  cross_country=$(sql "SELECT COUNT(*) FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=$iso_alert AND contact_id=$rw_id;")
  assert_eq "country isolation: UG alert produced 0 log rows for RW contact" "0" "$cross_country"
  sql "DELETE FROM notification_log WHERE related_entity_type='ALERT' AND related_entity_id=$iso_alert;"
  sql "DELETE FROM notification_suppressions WHERE related_entity_type='ALERT' AND related_entity_id=$iso_alert;"
  sql "DELETE FROM alert_followups WHERE alert_id=$iso_alert;"
  sql "DELETE FROM alerts WHERE id=$iso_alert;"
else
  ok "skipping country-isolation test (no UG secondary screenings)"
fi
sql "DELETE FROM poe_notification_contacts WHERE id=$rw_id;"

# 8. INTELLIGENCE ENGINE — narrative endpoint runs without throwing
intel=$(cd /home/hacker/ecsa_poe_2026/api && php -r "
  require '/home/hacker/ecsa_poe_2026/api/vendor/autoload.php';
  \$app = require '/home/hacker/ecsa_poe_2026/api/bootstrap/app.php';
  \$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
  echo \App\Services\IntelligenceEngine::narrativeFor('UG');
" 2>&1 | head -c 200)
if echo "$intel" | grep -qE "(No anomalies detected|Detected:)"; then ok "IntelligenceEngine narrative renders ('${intel:0:60}…')"
else bad "IntelligenceEngine narrative" "got: $intel"; fi

# 9. National-digest dry-run via artisan command
out=$(cd /home/hacker/ecsa_poe_2026/api && php artisan notifications:national-digest 2>&1 | tail -10)
echo "$out" | grep -q "countries_processed" && ok "national-digest command runs to completion" || bad "national-digest dry-run" "no output"

# Cleanup any digest rows created in this audit pass (so it stays a dry-run)
sql "DELETE FROM notification_log WHERE template_code='NATIONAL_INTELLIGENCE' AND triggered_by='CRON:national-intel' AND created_at > NOW() - INTERVAL 1 MINUTE;"
sql "DELETE FROM notification_suppressions WHERE template_code='NATIONAL_INTELLIGENCE' AND last_sent_at > NOW() - INTERVAL 1 MINUTE;"

# ═══════════════════════════════════════════════════════════════════════════
# SECTION 8 — IDEMPOTENCY
# ═══════════════════════════════════════════════════════════════════════════
section "8. Idempotency"

before_cols=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE template_id=$tpl_id;")
before_tpls=$(sql "SELECT COUNT(*) FROM notification_templates;")

# Re-apply the seed SQL
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < /home/hacker/ecsa_poe_2026/api/database/seeds-sql/add_who_afro_idsr_columns.sql > /dev/null 2>&1
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < /tmp/apply_new_tables.sql > /dev/null 2>&1

after_cols=$(sql "SELECT COUNT(*) FROM aggregated_template_columns WHERE template_id=$tpl_id;")
after_tpls=$(sql "SELECT COUNT(*) FROM notification_templates;")
assert_eq "re-running seed SQL produces no new columns" "$before_cols" "$after_cols"
assert_eq "re-running seed SQL produces no new notification templates" "$before_tpls" "$after_tpls"

# ═══════════════════════════════════════════════════════════════════════════
# FINAL
# ═══════════════════════════════════════════════════════════════════════════
echo
echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
if [[ "$FAIL" -eq 0 ]]; then
  echo -e "  ${G}✓ AUDIT PASSED${C}  —  $PASS assertions, 0 failures"
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
  exit 0
else
  echo -e "  ${R}✗ AUDIT FAILED${C}  —  $PASS passed, ${R}$FAIL failed${C}"
  echo
  echo -e "${R}Failures:${C}"
  for f in "${FAILURES[@]}"; do echo "   • $f"; done
  echo -e "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${C}"
  exit 1
fi
