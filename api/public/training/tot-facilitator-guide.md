# Train-the-Trainer — Web Dashboard Facilitator Guide

**For:** the facilitators who will teach the Uganda POE web dashboard.
**Audience they will teach:** screeners, POE admins, supervisors, PHEOC and national officers.
**What this guide is not:** a developer reference. Every surface is described in plain teaching language, not by table or API.
**Updated:** 2026-05-20 (Situation Room hidden from rail; Screening Volume is the post-login landing).

---

## How to use this guide

Each surface is taught in the same six-part pattern, every time. If you teach the first one with confidence, the rest follow.

1. **What it is** — one sentence the trainee can repeat back.
2. **Who opens it day-to-day** — so the trainee knows whether this is "for me".
3. **What is on the screen, top to bottom** — so the trainee never feels lost.
4. **The walk** — what you, the facilitator, point at and in what order.
5. **The hands-on** — the small task the trainee does themselves, right after the walk.
6. **The one thing to skip during the demo** — every screen has one; ignore it during teaching.

Use the **Training dashboard** at `https://ug-poe.ecsahc.com/admin` for every demo. Never the live one.

---

## Part 0 — The dashboard's bones (10 minutes, once, at the start)

Before any individual surface, give every trainee the same mental map.

**The sidebar (left rail)** groups the work into six families:

| Group | What it holds | Who lives here |
|---|---|---|
| **Quick Reports** | Eleven one-question reports — the everyday reading list. | Everyone reads these. |
| **Alert Lifecycle** | One entry — *Alerts*. The single workflow surface where alerts are acknowledged, closed, reassigned, reopened. | Supervisors and above act here; everyone else reads. |
| **Geography** | The reference data behind the country — Regions, Districts, Hospitals, Countries. | National admin writes; everyone else reads. |
| **Workforce** | One unified page that manages people, roles, and assignments. | National admin writes; supervisors read their own scope. |
| **PoEs · Annex-1A** | The four operational surfaces for the borders themselves — the registry, the capacity score, the open / closed status, and the contact roster. | National admin writes; PHEOC, district and POE staff read their scope. |

**The post-login landing** is **Screening Volume** (one of the Quick Reports). Everyone arrives there. The Situation Room entry has been removed from the rail.

**Three things that are true on every page:**

- The data you can see is filtered to your scope. National sees everything; PHEOC sees their region; district sees their district; POE sees their POE. The page does not say "filtered" — the filter is silent.
- Every list has filters at the top, a chart in the middle, and a table at the bottom. Memorise that shape — eleven of the twenty-two surfaces follow it identically.
- Every report has an **Export** button. CSV, full cohort, no truncation.

The trainees' very first practical exercise of the day on the dashboard:

> **Two-minute orientation drill.** Without clicking anything, name aloud the six sidebar groups. Then point at your post-login landing page (Screening Volume) and read out its title and the number in its first KPI card.

---

## Part 1 — Quick Reports (eleven surfaces, one shape)

### The shape every Quick Report has

If a trainee can read one Quick Report, they can read any of them. Teach the shape **once**, then walk through each report briefly.

```
┌──────────────────────────────────────────────────────────────────┐
│  Page title + sub-title                                          │
│  Filter bar  ── date range · POE · status · risk · search ──     │
├──────────────────────────────────────────────────────────────────┤
│  KPI cards  ── 5 to 8 small numbers across the top ──            │
├──────────────────────────────────────────────────────────────────┤
│  One chart                                                       │
│  ── single bar chart that re-shapes to whichever question        │
│     gives the cleanest answer for the data in view ──            │
├──────────────────────────────────────────────────────────────────┤
│  One table — 20 rows on screen, full export to CSV               │
│  ── sortable columns; each row deep-links to the case file        │
│     where one exists ──                                          │
└──────────────────────────────────────────────────────────────────┘
```

**The four-finger walk** — do this once with the first report you open, and never again:

1. **Filters at the top** — the question you can change.
2. **KPIs underneath** — the headline numbers, in order of importance left to right.
3. **The chart** — the picture of the headline. Read the axis labels aloud.
4. **The table** — the names behind the numbers. Click one row to open its case file.

### The eleven reports — what each one answers

Read these aloud to the trainees in the order shown. The order reflects clinical priority — sickest first, workload last.

| # | Report | The one question it answers | Open when… |
|---|---|---|---|
| 1 | **Suspected Cases** | Who do we suspect right now, of what, and how risky? | A supervisor asks "are there cases I should look at today?" |
| 2 | **Confirmed Cases** | Of the suspects, who has been confirmed, probable, or ruled out? | Lab results are back and you want to see what landed. |
| 3 | **Alert Database** | Every alert ever — open, acknowledged, closed, reopened — with owners. | You need the full alert ledger. |
| 4 | **Alert Analysis** | Where are the HIGH / CRITICAL and IHR-tier-1 spikes hiding? | Weekly review with PHEOC. |
| 5 | **Alert Outcomes** | How fast did we acknowledge and close? How many slipped past 24h or 7d? | SLA review with leadership. |
| 6 | **Symptom Spread** | What symptoms are turning up, and which are red flags? | Surveillance morning briefing. |
| 7 | **POE Analysis** | Which POEs are busy, which are dark, which are producing alerts? | Comparing borders. |
| 8 | **Country Analysis** | Which nationalities are arriving and which countries have they visited or transited? | Travel-history review. |
| 9 | **Daily Screening** | How many travellers per day, and what is the escalation rate? | Daily ops dashboard. |
| 10 | **Screening Volume** | Total primary vs secondary, gender split, age bands. *(This is the post-login landing.)* | Anyone who just signed in. |
| 11 | **User Analysis** | Which officers are active, which are dormant, who is carrying the load? | Monthly workforce review. |

### Cross-cutting teaching points (say these once, not eleven times)

- **The chart adapts.** It is one bar chart, but it re-shapes to the cleanest version of the question — top diseases, or top POEs, or top days. The trainee does not pick a chart type. The report picks.
- **20 rows on screen, full export.** If the trainee asks "where are the rest?", point at the Export button — the CSV has every row.
- **Click a row, get a case file.** Where the row is an alert or a screening, clicking opens its case file at `/admin/alerts/{id}/case-file`. The case file is the deep view; the report is the summary.
- **Suspected ≠ confirmed.** Suspected is the engine's guess from the phone. Confirmed is the lab. The two reports live next to each other on purpose — read them as a pair.
- **The placeholder `no_specific_suspicion`** never appears in charts. The mobile app pads to three diseases when fewer are produced; the report quietly drops the placeholder so counts stay clinically honest. Do not mention this unless a trainee asks.

### Hands-on exercise — Quick Reports (10 minutes)

In pairs, each trainee:

1. Opens **Screening Volume**, sets date range to *Today*, reads the top KPI to their partner.
2. Opens **Suspected Cases**, finds one row, clicks it, lands in the case file, reads the traveller's name and the three suspected diseases.
3. Backs out, opens **Alert Outcomes**, names the median acknowledge time.
4. Switches partner, repeats with a different three reports.

**The practical test** at the end of this block — facilitator calls out a question, trainee names the report they would open. *"How many travellers today?"* → Screening Volume. *"Median time to close alerts this week?"* → Alert Outcomes. *"Which border is dark?"* → POE Analysis. Three correct in a row passes.

### What to skip during the demo — Quick Reports

Do not change the date range to *All time* on Alert Database during a demo — on a populated server it pulls thousands of rows and the table loads visibly. Keep date ranges to **Past 7 days** or **Past 30 days** while teaching.

---

## Part 2 — Alerts (the workflow surface)

The Alert Lifecycle group has only one live entry on the rail — *Alerts*. Companion surfaces (Followups, Ownership, Case Room, SLA) still work at their URLs but are not on the rail. Teach **only** the main Alerts page; mention the companions exist but do not navigate to them today.

### What it is

The single workflow surface for moving an alert from **Open** → **Acknowledged** → **Closed** (with **Reopened** as a fourth state). Every alert raised by a closed secondary screening lives here.

### Who opens it day-to-day

- **Screeners** read it. They cannot act on alerts.
- **District Supervisors, PHEOC Officers and National Admin** act on it. They acknowledge, close, reassign, reopen.
- **POE-level staff** see only their POE's alerts.

### What is on the screen, top to bottom

1. **Five tabs at the top** — *New* (open) · *Being worked on* (acknowledged) · *Closed* · *Reopened* · *All*. **New** is the default.
2. **Filter bar** — date window, risk level, response team, district, POE, free-text search.
3. **The table** — each row shows: tier colour-dot, traveller name + classification, risk pill, status pill, up to three suspected-disease chips, a red badge if there are blocking follow-ups, owner name + role, when the alert was created, the POE + district it came from.
4. **Action strip on the right of each row** — a small group of icons. The two that matter for teaching are the **✓ Acknowledge** icon and the **📋 Open case file** icon.

### The walk

1. Land on the page. Read the New tab count aloud — *"this many open alerts in my scope right now."*
2. Click into one row. **Do not click the row itself.** Instead click the **📋 case-file icon** in the action strip on the right. This opens the case file — the single page that shows 100 % of what was captured on the phone.
3. Inside the case file, walk the six tabs across the top: primary + timeline, suspected diseases, symptoms / exposures / actions / samples, follow-ups, case room, closure.
4. Back out. Click the row itself (anywhere not the icon). A modal appears asking *"what do you want to do with this case?"* — this is the gateway modal. Close it without clicking anything.
5. Return to the alert list. Switch to the *Closed* tab. Note that the table shape stays the same — same columns, different filter.

### The hands-on

Each supervisor-or-above trainee:

1. Finds one open alert in their scope.
2. Opens its case file via the 📋 icon. Reads the traveller name, the three suspected diseases, and the case status aloud.
3. Returns to the list. Opens the gateway modal on the same row (clicks the row body). Names aloud the actions available to their role.
4. **Closes the gateway modal without acting.** Do not actually acknowledge or close during the demo.

POE-level trainees do read-only: open the list, find a row from their POE, open the case file, read the same three things, back out.

### What to skip during the demo — Alerts

The gateway modal **shows action buttons that work**. If you click *Acknowledge* during a demo you really do acknowledge the alert on the training server. Walk to the modal, name what you see, close the modal. Do not click an action.

If a trainee asks about *Followups* / *Ownership* / *Case Room* / *SLA*: those four surfaces exist at their URLs and work, but they are hidden from the rail by design — the daily flow goes through this Alerts page and the case file. Move on.

---

## Part 3 — Workforce (the unified people page)

### What it is

One page that replaced three older ones (Users, Roles, Assignments). It is the only place where people are created in the system, and the only place their roles and POE assignments live.

### Who opens it day-to-day

**National Admin only.** All user creation is gated to NATIONAL_ADMIN. Other roles can read scope-filtered slices but cannot create users. State this plainly to non-national trainees: *"Adding a person is a National-Admin action. You will see this page work today, but you will not be the one clicking it in your day-to-day."*

### What is on the screen, top to bottom

1. **Three tabs** — *Users · Roles · Assignments*. Users is the default.
2. **Top-right** — a single primary button: **+ Add person**.
3. **Users tab table** — name, username, email, role, assigned POE / district, status (Active / Suspended / Pending invitation / Disabled), last sign-in.
4. **Roles tab** — read-only registry of the role names and what scope each one lives at. Cannot be edited from this page today.
5. **Assignments tab** — the cross-reference of who is assigned where. Create, edit and end an assignment from here.

### The atomic add-person wizard

The **+ Add person** button opens a four-step wizard. The wizard is *atomic* — every step has to succeed or the whole person is not created. There is no half-saved user.

| Step | What the wizard asks | Why it matters |
|---|---|---|
| 1 — Identity | Full name, username, email, phone (optional). | Username and email must be unique across the whole country, not just within one POE. |
| 2 — Role | Pick a role from the registry — radio cards. | This decides what menu the new user will see and what scope they will be allowed to read. |
| 3 — Jurisdiction | Conditional on the role you picked: National skips this; PHEOC picks a region; District picks a district; POE picks a POE (and the district / region are derived for you). | Where the person works. Once set on the primary assignment, it is the scope of everything they see. |
| 4 — Invite mode | *Credential* — generates a temporary password the wizard shows you once and forces a change on first sign-in. *Email invite* — sends a one-time link that expires in seven days. | How the new user gets in. Credential is the right choice when the trainer is sitting with the new user; email is the right choice when they are remote. |

When the wizard finishes, the new user appears at the top of the *Users* tab.

### Existing-user actions (the icons on each user row)

- **Edit** — change name, email, phone, role.
- **Suspend / Unsuspend** — temporarily block sign-in without losing the user.
- **Reset password** — issue a temporary password.
- **Regenerate / revoke invitation** — for pending invitees.
- **Reset MFA** — clear the second-factor enrolment.
- **Unlock** — clear too-many-attempts lockouts.
- **Disable** — soft-delete; restore is also possible.

### The walk

1. Open Workforce. Read the Users count at the top of the table aloud.
2. Click *+ Add person*. Walk through the four wizard steps **without submitting**. Read each step's prompt out loud. Stop on Step 4 and close the wizard.
3. Return to the table. Click into one existing user. Walk the icon strip on the right and name what each does.
4. Switch to the *Assignments* tab. Show one assignment. Point at the *Start date* and *End date* columns — *"this is how we off-board someone without deleting their history."*
5. Switch to the *Roles* tab. Read out three or four role names — POE_PRIMARY, POE_ADMIN, DISTRICT_SUPERVISOR, NATIONAL_ADMIN. *"These names decide what each person sees in the app and the dashboard."*

### The hands-on

The national admin trainees:

1. Walk the four wizard steps on a **practice account** (name = "Training Test 1 — Your Initials", username = unique). Stop at Step 4 without submitting.
2. On the Assignments tab, identify one assignment in their scope and read its POE and start-date aloud.

Non-national trainees: read-only walk of the Users tab and the Roles tab. They do not open the wizard.

### What to skip during the demo — Workforce

Do not actually create a user during the demo unless a national-admin trainee has been told beforehand. The wizard writes a row, and the row stays. If you must demo creation, use the format *"Training Test 1 — [initials]"* as the name so test users are easy to find and disable afterwards.

Email-invite mode in Step 4 actually sends an email. Pick the **Credential** option during demos so nothing leaves the building.

---

## Part 4 — Geography (the reference hierarchy)

Four surfaces in this group. All four manage *reference data* — the lookup tables behind everything else. All four are write-locked to National Admin; everyone else reads.

### The four, in the order they sit in the tree

| Order | Surface | What it manages |
|---|---|---|
| 1 | **Regions** | The country's regional PHEOCs. (Stored as `ref_provinces` in the database; the UI calls them Regions.) |
| 2 | **Districts** | Border-adjacent districts. Each district sits under one region. |
| 3 | **Hospitals** | The referral hospital roster. Each hospital sits under one district. |
| 4 | **Countries** | The country itself — Uganda. A single row today. |

Teach this hierarchy aloud once: *"Country → Region → District → Hospital. Borders (PoEs) sit under districts. Travellers come from countries."*

### What is on each screen

All four follow the same shape:

- Filter bar (country, parent geo, status, search).
- A table with a row per item — name, code, status, a count of children (how many districts in this region, how many PoEs in this district).
- Edit / delete / restore icons on each row.
- A *+ New* button top-right.

### The cascading rename rule

This is the one teaching point unique to Geography:

> **Renaming a region or a district changes every PoE underneath it.** The PoE's *district* and *region* fields update automatically, and the mobile app re-syncs on its next bundle check.

If the trainee asks "what happens if I rename Kampala to something else?", that is the right answer.

### The walk

1. Open *Regions*. Point at the *PoE count* and *District count* columns. *"These tell you what is underneath."*
2. Click into one region. Walk the edit form. **Close without saving.**
3. Switch to *Districts*. Notice the same shape. Notice the *Region* column — each district has a parent.
4. Switch to *Hospitals*. Same shape, with two parents (region and district).
5. Switch to *Countries*. Note there is only one row — Uganda. The page exists so the country name and ISO code can be edited without a code change.

### The hands-on

National-admin trainees:

1. Open *Districts*. Filter by their region. Read the count aloud.
2. Open one district's edit form. Read the *Region* it sits under, then close the form.

Everyone else: read-only walk of *Regions* and *Districts*.

### What to skip during the demo — Geography

Do not rename anything during the demo. A rename cascades to every PoE, and the bundle version bumps. On the training server this is recoverable but visibly affects every screener device that re-syncs. Teach the rule by saying it, not by doing it.

---

## Part 5 — PoEs · Annex-1A (the four border surfaces)

The borders themselves get four screens — one per concern: who they are, how capable they are, whether they are open, who to call.

### The four, in the order to teach them

| Surface | The one question it answers |
|---|---|
| **PoE Registry** | Who are our PoEs and where are they? |
| **PoE Capacity** | How well-equipped is each PoE against the WHO IHR Annex-1A capacities? |
| **PoE Status** | Is each PoE open, closed, on reduced hours, in emergency closure, or in maintenance? |
| **Roster & Ladder** | Who do we call at each PoE — and if they do not answer, who is next on the ladder? |

All four are written by National Admin; PHEOC, district and POE staff read their scope.

### PoE Registry

**What it is.** The list of every gazetted Point of Entry — airports, ports, land borders, island entry points, rail crossings.

**What is on the screen.** Filter bar (country, region, district, PoE type, transport mode, status, search) → table (name, code, type, region, district, status, last updated).

**Create / edit is a five-step wizard.** Steps: Identity → Location → Flags (major entry / OSBP / national-level) → Context (notes, source URL) → Review.

**Two clever things to mention once:**

- When the trainee starts typing a PoE name, the system suggests its type, transport mode, and a unique external ID (e.g. `UG-CEN-KAM-ENT-001`).
- The system pre-flights for duplicate codes and shows a *"this looks like an existing PoE"* warning before saving.

**Walk:** open the page, filter to your region, click *+ New*, walk the five wizard steps **without saving**, close.

**Hands-on (national admin):** open the wizard, fill Step 1 with *"Practice Entry — your initials"*, click through to Step 5, **close without saving**.

**Skip during demo:** do not save a new PoE — every save bumps the bundle version and every phone in the field re-syncs.

### PoE Capacity

**What it is.** The WHO IHR Annex-1A capacity scoring — eight dimensions (inspection facilities, equipped medical, trained personnel, vector control, decontamination, traveller safety, animal health, communications), each scored **1 to 5**.

**The workflow is a one-way ratchet:** **DRAFT → SUBMITTED → REVIEWED**. Once submitted you can no longer edit (unless reviewer kicks it back). Once reviewed it is locked.

**What is on the screen.** Three tabs across the top — DRAFT / SUBMITTED / REVIEWED. Each row is one assessment for one PoE on one date, with the overall score (0–100, computed from the eight 1-to-5 scores).

**Walk:** open the page, switch the three tabs, click into one assessment to see the eight-dimension scoring panel. Close.

**Hands-on:** national-admin trainees identify one DRAFT in their scope and read the overall score aloud. Everyone else: walk the three tabs only.

**Skip during demo:** do not move an assessment from DRAFT to SUBMITTED during the demo — the transition is one-way and stamps the database with your user ID.

### PoE Status

**What it is.** A time-series log of every status change at every PoE. The latest row with no *end date* is the "current" status.

**Five statuses:** OPEN · CLOSED · REDUCED_HOURS · EMERGENCY_CLOSED · MAINTENANCE.

**What is on the screen.** For each PoE in scope: the current status (one row), with a recent-changes log (up to 30 events) below.

**The behaviour to teach:** *"Posting a new status automatically closes the previous one. You don't 'edit' a status — you record the next one."*

**Walk:** open the page, point at the current status for one PoE, then at the log of past changes for that PoE.

**Hands-on:** national-admin trainees identify the current status of one PoE in their scope and read its start date aloud.

**Skip during demo:** do not post a new status during the demo — it closes the live "current" row and inserts a new one. If a trainee asks, say *"we will record an emergency closure on the live system only when there is a real emergency closure."*

### Roster & Ladder

**What it is.** The contact list for raising alerts — name, organisation, phone, email, **what they receive** (ten yes-or-no flags: critical alerts, high alerts, medium, low, tier-1, tier-2, breach alerts, follow-up reminders, daily report, weekly report) and a five-level escalation chain (PoE → District → PHEOC → National → WHO).

**What is on the screen.** A roster table — filter chips for level, district, PoE, channel; a search box; the table itself shows name, level, organisation, phone, email, preferred channel, what they receive.

**Two things to teach:**

1. Every contact has at least one of phone or email. The page rejects a save that has neither.
2. Every contact *can* point to who escalates above them. The page rejects a self-reference and any cycle (A → B → A). The chain is capped at five hops.

**Walk:** open the page, filter to one PoE, point at the *Level* column to show the ladder, then point at the receives-flags row of one contact.

**Hands-on:** every trainee identifies the contact for their own PoE and reads their name and phone aloud.

**Skip during demo:** do not add or deactivate a contact during the demo. The roster is what real alerts route against — changing it on the training server has no operational consequence, but a trainee will struggle to follow a change of a real person's record.

---

## Part 6 — The facilitator's last-thirty-seconds checklist

Before any session, the lead facilitator confirms:

- [ ] Training dashboard at `https://ug-poe.ecsahc.com/admin` loads in a browser.
- [ ] Post-login lands on **Screening Volume** with non-zero data.
- [ ] The sidebar shows the five groups — Quick Reports · Alert Lifecycle · PoEs · Annex-1A · Geography · Workforce. **No Situation Room.**
- [ ] At least three alerts exist in the *New* tab of /admin/alerts (so the walkthrough has something to click).
- [ ] At least one capacity assessment in DRAFT and one in REVIEWED (so both tabs have content).
- [ ] At least one PoE in OPEN status and one in REDUCED_HOURS (so the status surface shows variety).

If any of those are not true, fix them with a co-facilitator off-stage **before** trainees enter the room.

---

## Appendix A — Demo discipline (the five rules)

These five rules apply to every screen on the dashboard. Internalise them once; they prevent every easily-avoidable problem.

1. **Read, never write, during a demo.** Walk to the form, name what you see, close the form. Real writes (rename a region, save a PoE, acknowledge an alert) belong in the hands-on minutes, not the walk minutes.
2. **Walk in the order taught in this guide.** Quick Reports → Alerts → Workforce → Geography → PoEs. Trainees who jump around get lost; trainees who follow the order build a map.
3. **Use the training URL only.** Production at `https://poes.health.go.ug/admin` is never on a facilitator screen during a session. If you accidentally landed on production, close the tab, do not narrate, re-open the training URL.
4. **The chart adapts; do not promise a specific chart shape.** If a trainee says *"yesterday you showed a bar chart by POE here, today it's by day"*, the right answer is *"the chart picks the cleanest version of the question for the data in view."*
5. **One thing per click.** Never click two things in quick succession during a demo. Click → name what changed → wait for a hand to go up → next click.

---

## Appendix B — Recovery cues

If a page loads slowly or shows an error, do **not** investigate on stage.

- **Blank page or 500 error:** *"This page is loading from a busy training server — let's keep moving and I will come back to it."* Move to the next surface. Resolve later with a co-facilitator.
- **A row in the table will not open:** *"That row is mid-update — let me pick another."* Pick a different row.
- **A filter resets unexpectedly:** *"Filters live in the URL — let me re-apply."* Re-apply once. If it resets again, move on.
- **An export takes more than ten seconds:** *"On a live server this returns in under a second — the training server is throttled. Trust that it works."* Move on.

A facilitator who never investigates on stage will always look in command. A facilitator who debugs in front of trainees loses the room.

---

## Appendix C — The facilitator's one-sentence pitch per surface

Use these when a trainee asks *"and what is this one for?"* The answer is one sentence; the rest of this guide explains why.

| Surface | One sentence |
|---|---|
| Screening Volume | How many travellers, split by primary, secondary, gender and age band. |
| Daily Screening | The same question, but day by day. |
| POE Analysis | The same question, but POE by POE. |
| Country Analysis | The same question, but by where the traveller is from and where they have been. |
| Suspected Cases | Who is currently flagged, of what disease, at what risk. |
| Confirmed Cases | Of the suspects, who came back confirmed, probable, or ruled out. |
| Symptom Spread | Which symptoms are appearing and which are red flags. |
| Alert Database | Every alert ever — the full ledger. |
| Alert Analysis | Where the HIGH-risk and tier-1 alerts cluster. |
| Alert Outcomes | How quickly we acknowledge, close, and how often we slip past 24 hours. |
| User Analysis | Which officers are active, which are dormant, who is carrying the load. |
| Alerts (hub) | The workflow surface where alerts are acknowledged, closed, reopened. |
| Workforce | The one place we add a person, change their role, change where they work. |
| Regions / Districts / Hospitals / Countries | The reference hierarchy that everything else sits inside. |
| PoE Registry | Who our PoEs are and where they are. |
| PoE Capacity | How well-equipped each PoE is against the WHO Annex-1A standard. |
| PoE Status | Whether each PoE is open, closed, reduced, in emergency closure, or in maintenance. |
| Roster & Ladder | Who to call for each PoE, and if they don't answer, who is next. |
