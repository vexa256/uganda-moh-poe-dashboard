<?php

use App\Http\Controllers\Admin\AggregatedController as AdminAggregatedController;
use App\Http\Controllers\Admin\Alerts\AlertsController as AdminAlertsController;
use App\Http\Controllers\Admin\Alerts\CaseRoomController as AdminAlertCaseRoomController;
use App\Http\Controllers\Admin\Alerts\ExternalRequestsController as AdminAlertExternalController;
use App\Http\Controllers\Admin\Alerts\FollowupsController as AdminAlertFollowupsController;
use App\Http\Controllers\Admin\Alerts\OwnershipController as AdminAlertOwnershipController;
use App\Http\Controllers\Admin\Alerts\SlaController as AdminAlertSlaController;
use App\Http\Controllers\Admin\Alerts\TimelineController as AdminAlertTimelineController;
use App\Http\Controllers\Admin\Alerts\WizardController as AdminAlertWizardController;
use App\Http\Controllers\Public\RespondersController as PublicRespondersController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Auth\InviteController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\Geo\CountriesController as AdminGeoCountriesController;
use App\Http\Controllers\Admin\Geo\DistrictsController as AdminGeoDistrictsController;
use App\Http\Controllers\Admin\Geo\HospitalsController as AdminGeoHospitalsController;
use App\Http\Controllers\Admin\Geo\PoeContactsController as AdminGeoPoeContactsController;
use App\Http\Controllers\Admin\Geo\PoeStatusController as AdminGeoPoeStatusController;
use App\Http\Controllers\Admin\Geo\PoeCapacityController as AdminGeoPoeCapacityController;
use App\Http\Controllers\Admin\Geo\PoesController as AdminGeoPoesController;
use App\Http\Controllers\Admin\Geo\ProvincesController as AdminGeoProvincesController;
use App\Http\Controllers\Admin\Workforce\AssignmentsController as AdminWfAssignmentsController;
use App\Http\Controllers\Admin\Workforce\RolesController as AdminWfRolesController;
use App\Http\Controllers\Admin\Workforce\TrainingController as AdminWfTrainingController;
use App\Http\Controllers\Admin\Workforce\UsersController as AdminWfUsersController;
use App\Http\Controllers\Admin\Governance\AuthEventsController as AdminGovAuthEventsController;
use App\Http\Controllers\Admin\Governance\NotificationLogController as AdminGovNotifLogController;
use App\Http\Controllers\Admin\Governance\RemindersController as AdminGovRemindersController;
use App\Http\Controllers\Admin\Governance\TemplatesController as AdminGovTemplatesController;
use App\Http\Controllers\Admin\Governance\DataQualityController as AdminGovDqController;
use App\Http\Controllers\Admin\Governance\RetentionController as AdminGovRetentionController;
use App\Http\Controllers\Admin\System\CronController as AdminSysCronController;
use App\Http\Controllers\Admin\System\MigrationsController as AdminSysMigrationsController;
use App\Http\Controllers\Admin\System\MailController as AdminSysMailController;
use App\Http\Controllers\Admin\System\MobileController as AdminSysMobileController;
use App\Http\Controllers\Admin\System\WhoConnectorController as AdminSysWhoController;
use App\Http\Controllers\Admin\Clinical\DiseasesController as AdminClinDiseasesController;
use App\Http\Controllers\Admin\Clinical\SymptomsController as AdminClinSymptomsController;
use App\Http\Controllers\Admin\Clinical\ExposuresController as AdminClinExposuresController;
use App\Http\Controllers\Admin\Clinical\BoostsController as AdminClinBoostsController;
use App\Http\Controllers\Admin\Clinical\EndemicController as AdminClinEndemicController;
use App\Http\Controllers\Admin\Clinical\VaccinesController as AdminClinVaccinesController;
use App\Http\Controllers\Admin\Intelligence\RankController as AdminIntelRankController;
use App\Http\Controllers\Admin\Intelligence\GeoController as AdminIntelGeoController;
use App\Http\Controllers\Admin\Intelligence\TripwiresController as AdminIntelTripController;
use App\Http\Controllers\Admin\Intelligence\DigestsController as AdminIntelDigestsController;
use App\Http\Controllers\Admin\Intelligence\CopilotController as AdminIntelCopilotController;
use App\Http\Controllers\Admin\Reports\ReportsMetaController        as AdminReportsMetaController;
use App\Http\Controllers\Admin\Reports\ScreeningVolumeController    as AdminReportR1Controller;
use App\Http\Controllers\Admin\Reports\SuspectedCasesController     as AdminReportR2Controller;
use App\Http\Controllers\Admin\Reports\GeoIntelligenceController    as AdminReportR3Controller;
use App\Http\Controllers\Admin\Reports\ContactTracingController     as AdminReportR4Controller;
use App\Http\Controllers\Admin\Reports\CasesRegistryController      as AdminReportR5Controller;
use App\Http\Controllers\Admin\Reports\AgeGenderController          as AdminReportR6Controller;
use App\Http\Controllers\Admin\Reports\SymptomExposureController    as AdminReportR7Controller;
use App\Http\Controllers\Admin\Reports\ScreeningOutcomesController         as AdminReportR8Controller;
use App\Http\Controllers\Admin\Reports\SuspectedDiseaseAnalyticsController as AdminReportR9Controller;
use App\Http\Controllers\Admin\Reports\CaseConfirmationController          as AdminReportR10Controller;
use App\Http\Controllers\Admin\Reports\AlertAcknowledgementController      as AdminReportR11Controller;
use App\Http\Controllers\Admin\Reports\SymptomDistributionController       as AdminReportR12Controller;
use App\Http\Controllers\Admin\Reports\CountryAnalyticsController          as AdminReportR13Controller;
use App\Http\Controllers\Admin\Reports\PoeOperationsController             as AdminReportR14Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PHEOC Command Centre · Admin web routes (Phase 0 RBAC live)
|--------------------------------------------------------------------------
| Auth · session-based via Admin\Auth\LoginController. Three credentials
| seeded by AdminUserSeeder (admin / eastern / chipata).
|
| Middleware stack on every authenticated /admin/* route:
|   web      — session, csrf, cookies (default)
|   auth     — must be signed in
|   scope    — resolves PheocScope, attaches to $request->attributes('scope')
|   role:…   — comma-separated allowed role_keys / account_types
|
| Scope rule (foundational):
|   NATIONAL_ADMIN sees everything · PHEOC_OFFICER sees its province ·
|   DISTRICT_SUPERVISOR sees its district · per dashboard.txt §0.3 + the
|   memorised feedback_scope_rule.md.
|
| RoleGate emits 403 JSON for write routes (NATIONAL_ADMIN-only) and the
| ResolveScope middleware publishes the descriptor to every controller for
| read-time row filtering.
|
| api/routes/api.php and api/routes/console.php are untouched: the mobile
| app contract and the cron schedule keep running.
*/

Route::get('/', fn () => auth()->check() ? redirect('/admin/reports/rpt-national-dashboard') : redirect('/login'));

/*--------------------------------------------------------------------------
 | Public auth — login / logout (no auth middleware on the GET form)
 |-------------------------------------------------------------------------*/
Route::middleware('web')->group(function () {
    Route::get ('/login',  [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login',  [LoginController::class, 'login'])->name('login.post');
    Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

    // Public invite-acceptance flow. Token in the URL is the plaintext;
    // sha256(token) must match users.invitation_token_hash to resolve.
    Route::get ('/invite/{token}', [InviteController::class, 'show'])  ->where('token', '[A-Za-z0-9_\-]{20,256}')->name('invite.show');
    Route::post('/invite/{token}', [InviteController::class, 'accept'])->where('token', '[A-Za-z0-9_\-]{20,256}')->name('invite.accept');

    // Public external-responder portal. Token is the plaintext request_token
    // stored on responder_info_requests. Single-use, expiry-aware, audited.
    Route::get ('/respond/{token}', [PublicRespondersController::class, 'show'])  ->where('token', '[A-Za-z0-9_\-]{32,128}')->name('public.responder.show');
    Route::post('/respond/{token}', [PublicRespondersController::class, 'submit'])->where('token', '[A-Za-z0-9_\-]{32,128}')->name('public.responder.submit');

    // Alerts refactor §3.3 — guest landing for non-account email recipients.
    // Single-use signed tokens, no session created, append-only audit, hard
    // headers (no-index, no-referrer, no-cache).
    Route::get ('/g/alert/{token}/view', [\App\Http\Controllers\Public\AlertGuestController::class, 'view'])
        ->where('token', '[A-Fa-f0-9]{48}')->name('public.alert.guest.view');
    Route::get ('/g/alert/{token}/ack',  [\App\Http\Controllers\Public\AlertGuestController::class, 'ackForm'])
        ->where('token', '[A-Fa-f0-9]{48}')->name('public.alert.guest.ack');
    Route::post('/g/alert/{token}/ack',  [\App\Http\Controllers\Public\AlertGuestController::class, 'ackSubmit'])
        ->where('token', '[A-Fa-f0-9]{48}')->name('public.alert.guest.ack.submit');
    Route::get ('/account/dead-end',     [\App\Http\Controllers\Public\AlertGuestController::class, 'deadEnd'])
        ->name('public.account.dead-end');
});

/*--------------------------------------------------------------------------
 | Admin · authenticated + scope-resolved
 |
 |   READ-ONLY routes (data, meta, version, suggest, dupe-check, show, index)
 |   are visible to ANY role (NATIONAL_ADMIN, PHEOC_OFFICER, DISTRICT_SUPERVISOR,
 |   POE_DATA_OFFICER) — the controllers apply scope filtering so each role
 |   only sees rows in its jurisdiction.
 |
 |   WRITE routes (store, update, destroy, restore) require NATIONAL_ADMIN
 |   via inline ->middleware('role:NATIONAL_ADMIN') groups below. Geography
 |   reference data (countries, provinces, districts) is master data; only
 |   the National Admin mutates it. PoE rows likewise.
 |-------------------------------------------------------------------------*/
Route::prefix('admin')->name('admin.')
    ->middleware(['web', 'auth', 'scope'])
    ->group(function () {

    // Phase 7 (ported from rw-poe 2026-05-18): /admin/dashboard now lands on
    // the Wave-3 V2 National Dashboard instead of the legacy PHEOC cockpit.
    // The legacy AdminDashboardController is kept for /admin/dashboard/snapshot
    // (live-refresh JSON) so any in-browser Alpine page loops keep working.
    Route::get('/dashboard',          [\App\Http\Controllers\Admin\Reports\V2\NationalDashboardController::class, 'index'])->name('dashboard');
    // Situation Room live-refresh snapshot — JSON, hit by the Alpine page
    // loop every 15 s. Read-only; same scope rules as the index render.
    Route::get('/dashboard/snapshot', [AdminDashboardController::class, 'snapshot'])->name('dashboard.snapshot');

    // Client error telemetry viewer. Server-rendered shell + JS-driven
    // realtime tail. Hits /api/client-logs/* endpoints (same origin) for
    // both the initial paint and the long-poll stream cursor. Read-only;
    // every authenticated admin can view.
    Route::get('/client-logs', function () {
        return view('admin.client_logs.index');
    })->name('client-logs');

    // ─── Geo · PoEs ───────────────────────────────────────────────────────
    Route::prefix('geo/poes')->name('geo.poes.')->group(function () {
        // Reads (any authenticated admin role)
        Route::get   ('/',             [AdminGeoPoesController::class, 'index'])    ->name('index');
        Route::get   ('/data',         [AdminGeoPoesController::class, 'data'])     ->name('data');
        Route::get   ('/meta',         [AdminGeoPoesController::class, 'meta'])     ->name('meta');
        Route::get   ('/version',      [AdminGeoPoesController::class, 'version'])  ->name('version');
        Route::post  ('/suggest',      [AdminGeoPoesController::class, 'suggest'])  ->name('suggest');
        Route::post  ('/dupe-check',   [AdminGeoPoesController::class, 'dupeCheck'])->name('dupe-check');
        Route::get   ('/{id}',         [AdminGeoPoesController::class, 'show'])     ->whereNumber('id')->name('show');

        // Writes — NATIONAL_ADMIN only
        Route::middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::post  ('/',             [AdminGeoPoesController::class, 'store'])    ->name('store');
            Route::patch ('/{id}',         [AdminGeoPoesController::class, 'update'])   ->whereNumber('id')->name('update');
            Route::delete('/{id}',         [AdminGeoPoesController::class, 'destroy'])  ->whereNumber('id')->name('destroy');
            Route::post  ('/{id}/restore', [AdminGeoPoesController::class, 'restore'])  ->whereNumber('id')->name('restore');
        });
    });

    // ─── Geo · Provinces ──────────────────────────────────────────────────
    Route::prefix('geo/provinces')->name('geo.provinces.')->group(function () {
        Route::get   ('/',             [AdminGeoProvincesController::class, 'index'])  ->name('index');
        Route::get   ('/data',         [AdminGeoProvincesController::class, 'data'])   ->name('data');
        Route::get   ('/meta',         [AdminGeoProvincesController::class, 'meta'])   ->name('meta');
        Route::get   ('/version',      [AdminGeoProvincesController::class, 'version'])->name('version');
        Route::get   ('/{id}',         [AdminGeoProvincesController::class, 'show'])   ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::post  ('/',             [AdminGeoProvincesController::class, 'store'])  ->name('store');
            Route::patch ('/{id}',         [AdminGeoProvincesController::class, 'update']) ->whereNumber('id')->name('update');
            Route::delete('/{id}',         [AdminGeoProvincesController::class, 'destroy'])->whereNumber('id')->name('destroy');
            Route::post  ('/{id}/restore', [AdminGeoProvincesController::class, 'restore'])->whereNumber('id')->name('restore');
        });
    });

    // ─── Geo · Districts ──────────────────────────────────────────────────
    Route::prefix('geo/districts')->name('geo.districts.')->group(function () {
        Route::get   ('/',             [AdminGeoDistrictsController::class, 'index'])  ->name('index');
        Route::get   ('/data',         [AdminGeoDistrictsController::class, 'data'])   ->name('data');
        Route::get   ('/meta',         [AdminGeoDistrictsController::class, 'meta'])   ->name('meta');
        Route::get   ('/version',      [AdminGeoDistrictsController::class, 'version'])->name('version');
        Route::get   ('/{id}',         [AdminGeoDistrictsController::class, 'show'])   ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::post  ('/',             [AdminGeoDistrictsController::class, 'store'])  ->name('store');
            Route::patch ('/{id}',         [AdminGeoDistrictsController::class, 'update']) ->whereNumber('id')->name('update');
            Route::delete('/{id}',         [AdminGeoDistrictsController::class, 'destroy'])->whereNumber('id')->name('destroy');
            Route::post  ('/{id}/restore', [AdminGeoDistrictsController::class, 'restore'])->whereNumber('id')->name('restore');
        });
    });

    // ─── Geo · Hospitals ──────────────────────────────────────────────────
    Route::prefix('geo/hospitals')->name('geo.hospitals.')->group(function () {
        Route::get   ('/',             [AdminGeoHospitalsController::class, 'index'])  ->name('index');
        Route::get   ('/data',         [AdminGeoHospitalsController::class, 'data'])   ->name('data');
        Route::get   ('/meta',         [AdminGeoHospitalsController::class, 'meta'])   ->name('meta');
        Route::get   ('/{id}',         [AdminGeoHospitalsController::class, 'show'])   ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::post  ('/',             [AdminGeoHospitalsController::class, 'store'])  ->name('store');
            Route::patch ('/{id}',         [AdminGeoHospitalsController::class, 'update']) ->whereNumber('id')->name('update');
            Route::delete('/{id}',         [AdminGeoHospitalsController::class, 'destroy'])->whereNumber('id')->name('destroy');
            Route::post  ('/{id}/restore', [AdminGeoHospitalsController::class, 'restore'])->whereNumber('id')->name('restore');
        });
    });

    // ─── Geo · Countries (read-only for non-admins; edit NATIONAL_ADMIN only)
    Route::prefix('geo/countries')->name('geo.countries.')->group(function () {
        Route::get  ('/',       [AdminGeoCountriesController::class, 'index']) ->name('index');
        Route::get  ('/data',   [AdminGeoCountriesController::class, 'data'])  ->name('data');
        Route::get  ('/{code}', [AdminGeoCountriesController::class, 'show'])  ->name('show');
        Route::patch('/{code}', [AdminGeoCountriesController::class, 'update'])->middleware('role:NATIONAL_ADMIN')->name('update');
    });

    // ─── PoE Operational · Contacts (poe_notification_contacts CRUD) ──────
    Route::prefix('poe/contacts')->name('poe.contacts.')->group(function () {
        Route::get   ('/',           [AdminGeoPoeContactsController::class, 'index']) ->name('index');
        Route::get   ('/data',       [AdminGeoPoeContactsController::class, 'data'])  ->name('data');
        Route::get   ('/meta',       [AdminGeoPoeContactsController::class, 'meta'])  ->name('meta');
        Route::get   ('/{id}',       [AdminGeoPoeContactsController::class, 'show'])  ->whereNumber('id')->name('show');
        Route::get   ('/{id}/chain', [AdminGeoPoeContactsController::class, 'chain']) ->whereNumber('id')->name('chain');

        // Roster mutation: NATIONAL_ADMIN, PHEOC_OFFICER, PHEOC_ADMIN
        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN')->group(function () {
            Route::post  ('/',             [AdminGeoPoeContactsController::class, 'store'])  ->name('store');
            Route::patch ('/{id}',         [AdminGeoPoeContactsController::class, 'update']) ->whereNumber('id')->name('update');
            Route::delete('/{id}',         [AdminGeoPoeContactsController::class, 'destroy'])->whereNumber('id')->name('destroy');
            Route::post  ('/{id}/restore', [AdminGeoPoeContactsController::class, 'restore'])->whereNumber('id')->name('restore');
        });
    });

    // ─── PoE Operational · Open / Closed Status ───────────────────────────
    Route::prefix('poe/status')->name('poe.status.')->group(function () {
        Route::get   ('/',     [AdminGeoPoeStatusController::class, 'index']) ->name('index');
        Route::get   ('/data', [AdminGeoPoeStatusController::class, 'data'])  ->name('data');
        Route::get   ('/meta', [AdminGeoPoeStatusController::class, 'meta'])  ->name('meta');
        Route::get   ('/{id}', [AdminGeoPoeStatusController::class, 'show'])  ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN')->group(function () {
            Route::post  ('/',     [AdminGeoPoeStatusController::class, 'store']) ->name('store');
            Route::patch ('/{id}', [AdminGeoPoeStatusController::class, 'update'])->whereNumber('id')->name('update');
        });
    });

    /*------------------------------------------------------------------
     | Section 03 · Alert Lifecycle
     |
     | Reads (index/data/meta/show + dispatch-receipt) are visible to
     | any authenticated role — controllers apply ScopeFilter so each
     | role only sees alerts in its jurisdiction.
     |
     | Writes are gated by the FSM-aware downstream controllers (which
     | re-check ACKNOWLEDGE_ROLES per routed_to_level), but we still
     | role-gate the route here as a defence-in-depth: only operational
     | roles may touch alerts at all.  NATIONAL_ADMIN may ALWAYS write;
     | DISTRICT_SUPERVISOR / PHEOC_OFFICER may write per their level.
     *-----------------------------------------------------------------*/
    Route::prefix('alerts')->name('alerts.')->group(function () {

        // Master list (alert-hub)
        Route::get ('/',                 [AdminAlertsController::class, 'index']) ->name('index');
        Route::get ('/data',             [AdminAlertsController::class, 'data'])  ->name('data');
        Route::get ('/meta',             [AdminAlertsController::class, 'meta'])  ->name('meta');
        Route::get ('/insights',         [AdminAlertsController::class, 'insights'])->name('insights');

        // Reassignment picker (alerts refactor §3.1) — read-only candidate search
        // for the "who can I reassign this to?" UX. No state change; routed under
        // /reassign-* prefix so the static segment is matched BEFORE /{id} below.
        Route::get ('/reassign-meta',         [AdminAlertsController::class, 'reassignMeta'])      ->name('reassign-meta');
        Route::get ('/reassign-candidates',   [AdminAlertsController::class, 'reassignCandidates'])->name('reassign-candidates');

        // Per-alert dossier
        Route::get ('/{id}',                       [AdminAlertsController::class, 'show'])             ->whereNumber('id')->name('show');
        Route::get ('/{id}/dispatch-receipt',      [AdminAlertsController::class, 'dispatchReceipt']) ->whereNumber('id')->name('dispatch-receipt');

        // Case File 6-tab wizard (alerts refactor §3.4 + §3.5).
        // The .show URL renders the workspace; .data returns the JSON payload
        // with full §6 data surface + intelligence derivatives + advisor.
        Route::get ('/{id}/case-file',          [\App\Http\Controllers\Admin\Alerts\CaseFileController::class, 'show'])->whereNumber('id')->name('case-file');
        Route::get ('/{id}/case-file/data',     [\App\Http\Controllers\Admin\Alerts\CaseFileController::class, 'data'])->whereNumber('id')->name('case-file.data');

        // Followups (cross-alert lens at /admin/alerts/followups)
        Route::prefix('followups')->name('followups.')->group(function () {
            Route::get  ('/',     [AdminAlertFollowupsController::class, 'index'])->name('index');
            Route::get  ('/data', [AdminAlertFollowupsController::class, 'data']) ->name('data');
            Route::get  ('/meta', [AdminAlertFollowupsController::class, 'meta']) ->name('meta');

            Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_SUPERVISOR,DISTRICT_ADMIN,POE_ADMIN,POE_OFFICER,POE_DATA_OFFICER,SCREENER')
                ->group(function () {
                    Route::patch('/{id}',     [AdminAlertFollowupsController::class, 'update'])     ->whereNumber('id')->name('update');
                    Route::post ('/bulk-status', [AdminAlertFollowupsController::class, 'bulkStatus'])->name('bulk-status');
                });
        });

        // FSM transitions — delegated to canonical controllers downstream.
        // Acknowledge / close — district supervisor & up.
        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_SUPERVISOR,DISTRICT_ADMIN')
            ->group(function () {
                Route::patch('/{id}/acknowledge', [AdminAlertsController::class, 'acknowledge']) ->whereNumber('id')->name('acknowledge');
                Route::patch('/{id}/close',       [AdminAlertsController::class, 'close'])       ->whereNumber('id')->name('close');
                Route::post ('/{id}/escalate',    [AdminAlertsController::class, 'escalate'])    ->whereNumber('id')->name('escalate');
                Route::post ('/{id}/reassign',    [AdminAlertsController::class, 'reassign'])    ->whereNumber('id')->name('reassign');
            });

        // Reopen — PHEOC and up only (per dashboard.txt §M2 — war-room authority).
        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN')
            ->group(function () {
                Route::post('/{id}/reopen', [AdminAlertsController::class, 'reopen'])->whereNumber('id')->name('reopen');
            });

        /*------------------------------------------------------------------
         | Resolution Wizard — start-to-finish guided closure.
         | Reads use scope only. Writes use scope + role + idempotency
         | so a flaky network does not double-record decisions.
         *-----------------------------------------------------------------*/
        Route::prefix('{id}/wizard')->whereNumber('id')->name('wizard.')->group(function () {
            // Reads — scope inherited from parent group.
            Route::get ('/',             [AdminAlertWizardController::class, 'show'])         ->name('show');
            Route::get ('/step',         [AdminAlertWizardController::class, 'step'])         ->name('step');
            Route::get ('/progress',     [AdminAlertWizardController::class, 'progress'])     ->name('progress');
            Route::get ('/stakeholders', [AdminAlertWizardController::class, 'stakeholders']) ->name('stakeholders');
            Route::get ('/gateway',      [AdminAlertWizardController::class, 'gateway'])      ->name('gateway');

            // Writes — district & up may answer; idempotent so retries are safe.
            Route::middleware(['idempotent', 'role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_SUPERVISOR,DISTRICT_ADMIN,POE_OFFICER,POE_ADMIN'])
                ->group(function () {
                    Route::post('/answer',      [AdminAlertWizardController::class, 'answer'])    ->name('answer');
                    Route::post('/contact',     [AdminAlertWizardController::class, 'contact'])   ->name('contact');
                    Route::post('/false-alarm', [AdminAlertWizardController::class, 'falseAlarm'])->name('false-alarm');
                });

            // Master-close — national admin only.
            Route::middleware(['idempotent', 'role:NATIONAL_ADMIN'])
                ->group(function () {
                    Route::post('/master-close', [AdminAlertWizardController::class, 'masterClose'])->name('master-close');
                });
        });

        /*------------------------------------------------------------------
         | Phase 3 · Ownership Trail (read-only — writes live on AlertsCtrl)
         *-----------------------------------------------------------------*/
        Route::prefix('ownership')->name('ownership.')->group(function () {
            Route::get('/',       [AdminAlertOwnershipController::class, 'index'])  ->name('index');
            Route::get('/data',   [AdminAlertOwnershipController::class, 'data'])   ->name('data');
            Route::get('/matrix', [AdminAlertOwnershipController::class, 'matrix']) ->name('matrix');
        });

        /*------------------------------------------------------------------
         | Phase 3 · Case Room — composite + write delegators
         *-----------------------------------------------------------------*/
        Route::prefix('case-room')->name('case-room.')->group(function () {
            Route::get('/',     [AdminAlertCaseRoomController::class, 'index'])->name('index');
            Route::get('/data', [AdminAlertCaseRoomController::class, 'data']) ->name('data');
            Route::get('/meta', [AdminAlertCaseRoomController::class, 'meta']) ->name('meta');
        });

        // Collaborators / comments / evidence / handoffs — district & up may write.
        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_SUPERVISOR,DISTRICT_ADMIN,POE_OFFICER,POE_ADMIN,POE_DATA_OFFICER')
            ->group(function () {
                // Collaborators (alert-anchored on POST; row-anchored on PATCH/DELETE)
                Route::post   ('/{id}/collaborators',   [AdminAlertCaseRoomController::class, 'addCollaborator'])->whereNumber('id')->name('collaborators.add');
                Route::patch  ('/collaborators/{id}',   [AdminAlertCaseRoomController::class, 'updateCollaborator'])->whereNumber('id')->name('collaborators.update');
                Route::delete ('/collaborators/{id}',   [AdminAlertCaseRoomController::class, 'removeCollaborator'])->whereNumber('id')->name('collaborators.remove');

                // Comments
                Route::post   ('/{id}/comments',       [AdminAlertCaseRoomController::class, 'postComment'])  ->whereNumber('id')->name('comments.post');
                Route::patch  ('/comments/{id}',       [AdminAlertCaseRoomController::class, 'editComment'])  ->whereNumber('id')->name('comments.edit');
                Route::delete ('/comments/{id}',       [AdminAlertCaseRoomController::class, 'deleteComment'])->whereNumber('id')->name('comments.delete');
                Route::post   ('/comments/{id}/pin',   [AdminAlertCaseRoomController::class, 'togglePin'])    ->whereNumber('id')->name('comments.pin');
                Route::post   ('/comments/{id}/react', [AdminAlertCaseRoomController::class, 'reactToComment'])->whereNumber('id')->name('comments.react');

                // Evidence
                Route::post   ('/{id}/evidence',  [AdminAlertCaseRoomController::class, 'addEvidence'])   ->whereNumber('id')->name('evidence.add');
                Route::delete ('/evidence/{id}',  [AdminAlertCaseRoomController::class, 'deleteEvidence'])->whereNumber('id')->name('evidence.delete');

                // Handoffs
                Route::post   ('/{id}/handoffs',          [AdminAlertCaseRoomController::class, 'createHandoff'])->whereNumber('id')->name('handoffs.create');
                Route::post   ('/handoffs/{id}/accept',   [AdminAlertCaseRoomController::class, 'acceptHandoff'])->whereNumber('id')->name('handoffs.accept');
                Route::post   ('/handoffs/{id}/reject',   [AdminAlertCaseRoomController::class, 'rejectHandoff'])->whereNumber('id')->name('handoffs.reject');
            });

        /*------------------------------------------------------------------
         | Phase 4 · External Requests (admin side)
         *-----------------------------------------------------------------*/
        Route::prefix('external')->name('external.')->group(function () {
            Route::get('/',     [AdminAlertExternalController::class, 'index'])->name('index');
            Route::get('/data', [AdminAlertExternalController::class, 'data']) ->name('data');
            Route::get('/meta', [AdminAlertExternalController::class, 'meta']) ->name('meta');
            Route::get('/{id}', [AdminAlertExternalController::class, 'show']) ->whereNumber('id')->name('show');

            Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_SUPERVISOR,DISTRICT_ADMIN')
                ->group(function () {
                    Route::post('/{id}/cancel', [AdminAlertExternalController::class, 'cancel'])->whereNumber('id')->name('cancel');
                    Route::post('/{id}/resend', [AdminAlertExternalController::class, 'resend'])->whereNumber('id')->name('resend');
                    Route::get ('/responders',  [AdminAlertExternalController::class, 'respondersIndex'])->name('responders.index');
                    Route::post('/responders',  [AdminAlertExternalController::class, 'respondersStore'])->name('responders.store');
                });
        });
        // Create-on-alert nested under /admin/alerts/{id}/external-requests (already inside this prefix group).
        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_SUPERVISOR,DISTRICT_ADMIN')->group(function () {
            Route::post('/{id}/external-requests', [AdminAlertExternalController::class, 'create'])->whereNumber('id')->name('external.create');
        });

        /*------------------------------------------------------------------
         | Phase 5 · SLA & Breaches
         *-----------------------------------------------------------------*/
        Route::prefix('sla')->name('sla.')->group(function () {
            Route::get('/',          [AdminAlertSlaController::class, 'index'])    ->name('index');
            Route::get('/data',      [AdminAlertSlaController::class, 'data'])     ->name('data');
            Route::get('/aggregate', [AdminAlertSlaController::class, 'aggregate'])->name('aggregate');
            Route::get('/reports',   [AdminAlertSlaController::class, 'reports'])  ->name('reports');
        });
        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_SUPERVISOR,DISTRICT_ADMIN')->group(function () {
            Route::post ('/{id}/breach-reports',        [AdminAlertSlaController::class, 'logBreach']) ->whereNumber('id')->name('sla.log-breach');
            Route::patch('/breach-reports/{id}',         [AdminAlertSlaController::class, 'updateBreach'])->whereNumber('id')->name('sla.update-breach');
        });

        /*------------------------------------------------------------------
         | Alert Operations · Case History (timeline)
         | Cross-case lens over alert_timeline_events. Read-only. Writes
         | live on the canonical mutation paths via TimelineRecorder.
         *-----------------------------------------------------------------*/
        Route::prefix('timeline')->name('timeline.')->group(function () {
            Route::get('/',           [AdminAlertTimelineController::class, 'index'])      ->name('index');
            Route::get('/data',       [AdminAlertTimelineController::class, 'data'])       ->name('data');
            Route::get('/meta',       [AdminAlertTimelineController::class, 'meta'])       ->name('meta');
            Route::get('/case/{id}',  [AdminAlertTimelineController::class, 'caseStream']) ->whereNumber('id')->name('case');
        });
    });

    // ─── Workforce · Users ────────────────────────────────────────────────
    Route::prefix('workforce/users')->name('workforce.users.')->group(function () {
        Route::get ('/',     [AdminWfUsersController::class, 'index'])->name('index');
        Route::get ('/data', [AdminWfUsersController::class, 'data']) ->name('data');
        Route::get ('/meta', [AdminWfUsersController::class, 'meta']) ->name('meta');
        Route::get ('/{id}', [AdminWfUsersController::class, 'show']) ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::post  ('/',                       [AdminWfUsersController::class, 'store'])              ->name('store');
            Route::patch ('/{id}',                   [AdminWfUsersController::class, 'update'])             ->whereNumber('id')->name('update');
            Route::delete('/{id}',                   [AdminWfUsersController::class, 'destroy'])            ->whereNumber('id')->name('destroy');
            Route::post  ('/{id}/restore',           [AdminWfUsersController::class, 'restore'])            ->whereNumber('id')->name('restore');
            Route::post  ('/{id}/suspend',           [AdminWfUsersController::class, 'suspend'])            ->whereNumber('id')->name('suspend');
            Route::post  ('/{id}/unsuspend',         [AdminWfUsersController::class, 'unsuspend'])          ->whereNumber('id')->name('unsuspend');
            Route::post  ('/{id}/password-reset',    [AdminWfUsersController::class, 'forcePasswordReset']) ->whereNumber('id')->name('password-reset');
            Route::post  ('/{id}/regenerate-invite', [AdminWfUsersController::class, 'regenerateInvite'])  ->whereNumber('id')->name('regenerate-invite');
            Route::post  ('/{id}/revoke-invite',     [AdminWfUsersController::class, 'revokeInvite'])      ->whereNumber('id')->name('revoke-invite');
            Route::post  ('/{id}/mfa-reset',         [AdminWfUsersController::class, 'resetMfa'])           ->whereNumber('id')->name('mfa-reset');
            Route::post  ('/{id}/unlock',            [AdminWfUsersController::class, 'unlock'])             ->whereNumber('id')->name('unlock');
        });
    });

    // ─── Workforce · Roles ────────────────────────────────────────────────
    Route::prefix('workforce/roles')->name('workforce.roles.')->group(function () {
        Route::get  ('/',      [AdminWfRolesController::class, 'index'])->name('index');
        Route::get  ('/data',  [AdminWfRolesController::class, 'data']) ->name('data');
        Route::get  ('/{key}', [AdminWfRolesController::class, 'show']) ->where('key','[A-Z_]+')->name('show');
        Route::patch('/{key}', [AdminWfRolesController::class, 'update'])->where('key','[A-Z_]+')->middleware('role:NATIONAL_ADMIN')->name('update');
    });

    // ─── Workforce · Assignments ──────────────────────────────────────────
    Route::prefix('workforce/assignments')->name('workforce.assignments.')->group(function () {
        Route::get ('/',     [AdminWfAssignmentsController::class, 'index'])->name('index');
        Route::get ('/data', [AdminWfAssignmentsController::class, 'data']) ->name('data');
        Route::get ('/meta', [AdminWfAssignmentsController::class, 'meta']) ->name('meta');
        Route::get ('/{id}', [AdminWfAssignmentsController::class, 'show']) ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN')->group(function () {
            Route::post  ('/',             [AdminWfAssignmentsController::class, 'store'])  ->name('store');
            Route::patch ('/{id}',         [AdminWfAssignmentsController::class, 'update']) ->whereNumber('id')->name('update');
            Route::delete('/{id}',         [AdminWfAssignmentsController::class, 'destroy'])->whereNumber('id')->name('destroy');
            Route::post  ('/{id}/restore', [AdminWfAssignmentsController::class, 'restore'])->whereNumber('id')->name('restore');
        });
    });

    // ─── Workforce · Training ─────────────────────────────────────────────
    Route::prefix('workforce/training')->name('workforce.training.')->group(function () {
        Route::get ('/',     [AdminWfTrainingController::class, 'index'])->name('index');
        Route::get ('/data', [AdminWfTrainingController::class, 'data']) ->name('data');
        Route::get ('/meta', [AdminWfTrainingController::class, 'meta']) ->name('meta');
        Route::get ('/{id}', [AdminWfTrainingController::class, 'show']) ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN,DISTRICT_ADMIN,DISTRICT_SUPERVISOR')->group(function () {
            Route::post  ('/',     [AdminWfTrainingController::class, 'store']) ->name('store');
            Route::patch ('/{id}', [AdminWfTrainingController::class, 'update'])->whereNumber('id')->name('update');
            Route::delete('/{id}', [AdminWfTrainingController::class, 'destroy'])->whereNumber('id')->name('destroy');
        });
    });

    /*------------------------------------------------------------------
     | Section 06 · Aggregated Reports / IDSR (rebuild 2026-04-24)
     |
     | Three consolidated surfaces replacing the legacy 10-item section:
     |   /admin/aggregated/studio       → template library + builder wizard +
     |                                    columns editor + versions + lifecycle
     |   /admin/aggregated/submissions  → submissions browser + rollups +
     |                                    late reporters + CSV export
     |   /admin/aggregated/sync         → UNSYNCED / FAILED queue + diagnostics
     |
     | Reads are scope-filtered inside the controller (NATIONAL / PHEOC /
     | DISTRICT / POE visibility mirrors the mobile AggregatedController).
     | Writes (template CRUD, column CRUD, lifecycle, resync) are
     | NATIONAL_ADMIN only — role-gated here as defence in depth, then
     | re-checked by AdminAggregatedController::requireAdmin().
     |
     | The mobile /aggregated and /aggregated-templates endpoints in
     | routes/api.php are UNTOUCHED — this admin surface owns its own
     | JSON endpoints so mobile clients stay on a stable contract.
     *-----------------------------------------------------------------*/
    Route::prefix('aggregated')->name('aggregated.')->group(function () {

        // ── Blade shells (read-only for any role; scope-filtered internally) ──
        Route::get('/studio',      [AdminAggregatedController::class, 'studio'])     ->name('studio');
        Route::get('/submissions', [AdminAggregatedController::class, 'submissions'])->name('submissions');
        Route::get('/reports',     [AdminAggregatedController::class, 'reports'])    ->name('reports');
        Route::get('/sync',        [AdminAggregatedController::class, 'sync'])       ->name('sync');

        // ── Reports JSON (dynamic per-template analytics engine) ──
        Route::get('/reports/data',                  [AdminAggregatedController::class, 'reportsData'])    ->name('reports.data');
        Route::get('/reports/template/{id}',         [AdminAggregatedController::class, 'reportTemplate']) ->whereNumber('id')->name('reports.template');
        Route::get('/reports/template/{id}/export',  [AdminAggregatedController::class, 'reportExport'])   ->whereNumber('id')->name('reports.export');

        // ── Studio JSON ──────────────────────────────────────────────
        Route::get ('/studio/data',                   [AdminAggregatedController::class, 'studioData'])      ->name('studio.data');
        Route::get ('/studio/template/{id}',          [AdminAggregatedController::class, 'studioTemplate'])  ->whereNumber('id')->name('studio.template.show');

        // Idempotent middleware honours an Idempotency-Key header — if a
        // double-clicked admin or an automated retry sends the same key
        // within 24h the original response is replayed. Header is OPTIONAL,
        // so legacy clients that omit it pass through unchanged.
        Route::middleware(['role:NATIONAL_ADMIN', 'idempotent'])->group(function () {
            Route::post  ('/studio/template',                  [AdminAggregatedController::class, 'studioCreateTemplate']) ->name('studio.template.store');
            Route::patch ('/studio/template/{id}',             [AdminAggregatedController::class, 'studioUpdateTemplate']) ->whereNumber('id')->name('studio.template.update');
            Route::delete('/studio/template/{id}',             [AdminAggregatedController::class, 'studioDeleteTemplate']) ->whereNumber('id')->name('studio.template.destroy');
            Route::post  ('/studio/template/{id}/lifecycle',   [AdminAggregatedController::class, 'studioLifecycle'])      ->whereNumber('id')->name('studio.template.lifecycle');
            Route::post  ('/studio/template/{id}/columns',     [AdminAggregatedController::class, 'studioAddColumn'])      ->whereNumber('id')->name('studio.template.column.store');
            Route::patch ('/studio/template/{id}/columns/bulk',[AdminAggregatedController::class, 'studioBulkColumns'])    ->whereNumber('id')->name('studio.template.columns.bulk');
            Route::patch ('/studio/columns/{colId}',           [AdminAggregatedController::class, 'studioUpdateColumn'])   ->whereNumber('colId')->name('studio.column.update');
            Route::delete('/studio/columns/{colId}',           [AdminAggregatedController::class, 'studioDeleteColumn'])   ->whereNumber('colId')->name('studio.column.destroy');
        });

        // ── Intelligence JSON ────────────────────────────────────────
        Route::get('/submissions/data',           [AdminAggregatedController::class, 'submissionsData']) ->name('submissions.data');
        Route::get('/submissions/rollups',        [AdminAggregatedController::class, 'rollups'])        ->name('submissions.rollups');
        Route::get('/submissions/late-reporters', [AdminAggregatedController::class, 'lateReporters']) ->name('submissions.late');
        Route::get('/submissions/export',         [AdminAggregatedController::class, 'export'])         ->name('submissions.export');
        Route::get('/submissions/{id}',           [AdminAggregatedController::class, 'submissionShow']) ->whereNumber('id')->name('submissions.show');

        // ── Sync queue JSON ──────────────────────────────────────────
        Route::get('/sync/data', [AdminAggregatedController::class, 'syncData'])->name('sync.data');
        Route::middleware(['role:NATIONAL_ADMIN', 'idempotent'])->group(function () {
            Route::post('/sync/{id}/resync', [AdminAggregatedController::class, 'syncResync'])->whereNumber('id')->name('sync.resync');
        });
    });

    // ─── PoE Operational · Annex-1A Capacity Assessment ───────────────────
    Route::prefix('poe/capacity')->name('poe.capacity.')->group(function () {
        Route::get   ('/',     [AdminGeoPoeCapacityController::class, 'index']) ->name('index');
        Route::get   ('/data', [AdminGeoPoeCapacityController::class, 'data'])  ->name('data');
        Route::get   ('/meta', [AdminGeoPoeCapacityController::class, 'meta'])  ->name('meta');
        Route::get   ('/{id}', [AdminGeoPoeCapacityController::class, 'show'])  ->whereNumber('id')->name('show');

        Route::middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,PHEOC_ADMIN')->group(function () {
            Route::post  ('/',     [AdminGeoPoeCapacityController::class, 'store']) ->name('store');
            Route::patch ('/{id}', [AdminGeoPoeCapacityController::class, 'update'])->whereNumber('id')->name('update');
        });
    });

    /*------------------------------------------------------------------
     | Section 12 · Governance · Auth Events (gov-auth)
     |
     | Read-only forensic view onto `auth_events` (append-only by
     | AuthEventLogger from every auth-relevant branch), plus
     | lockouts (users.locked_until > now()), suspensions,
     | and the live user_anomaly_flags feed.
     |
     | Gate: NATIONAL_ADMIN only (PII + security telemetry).
     | Mobile API: UNTOUCHED — this surface has its own web JSON
     | endpoints; auth_events itself is only written by server-side
     | services, not by any mobile route.
     *-----------------------------------------------------------------*/
    Route::prefix('governance/auth-events')->name('governance.auth-events.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',           [AdminGovAuthEventsController::class, 'index'])    ->name('index');
            Route::get ('/data',       [AdminGovAuthEventsController::class, 'data'])     ->name('data');
            Route::get ('/summary',    [AdminGovAuthEventsController::class, 'summary'])  ->name('summary');
            Route::get ('/lockouts',   [AdminGovAuthEventsController::class, 'lockouts']) ->name('lockouts');
            Route::get ('/anomalies',  [AdminGovAuthEventsController::class, 'anomalies'])->name('anomalies');
            Route::get ('/export',     [AdminGovAuthEventsController::class, 'export'])   ->name('export');
            Route::post('/anomalies/{id}/clear', [AdminGovAuthEventsController::class, 'clearAnomaly'])
                ->whereNumber('id')->name('anomalies.clear');
        });

    /*------------------------------------------------------------------
     | Section 12 · Governance · Delivery Audit (gov-notif-log)
     |
     | Read-only per-recipient delivery audit on notification_log.
     | Gate: NATIONAL_ADMIN only (email / phone + subject PII).
     | Mobile API: UNTOUCHED — notification_log is written server-side
     | only; no mobile route references it.
     *-----------------------------------------------------------------*/
    Route::prefix('governance/notification-log')->name('governance.notification-log.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',        [AdminGovNotifLogController::class, 'index'])  ->name('index');
            Route::get('/data',    [AdminGovNotifLogController::class, 'data'])   ->name('data');
            Route::get('/summary', [AdminGovNotifLogController::class, 'summary'])->name('summary');
            Route::get('/meta',    [AdminGovNotifLogController::class, 'meta'])   ->name('meta');
            Route::get('/export',  [AdminGovNotifLogController::class, 'export']) ->name('export');
        });

    /*------------------------------------------------------------------
     | Section 12 · Governance · Reminders & Retry (gov-reminders)
     |
     | Future-facing view: upcoming FOLLOWUP_DUE / FOLLOWUP_OVERDUE,
     | active suppression windows, retry queue, contact freshness.
     | Gate: NATIONAL_ADMIN only. Read-only (the q15m retry cron is
     | the write-side; this surface only observes it).
     *-----------------------------------------------------------------*/
    Route::prefix('governance/reminders')->name('governance.reminders.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',             [AdminGovRemindersController::class, 'index'])       ->name('index');
            Route::get('/summary',      [AdminGovRemindersController::class, 'summary'])     ->name('summary');
            Route::get('/followups',    [AdminGovRemindersController::class, 'followups'])   ->name('followups');
            Route::get('/retry',        [AdminGovRemindersController::class, 'retryQueue'])  ->name('retry');
            Route::get('/suppressions', [AdminGovRemindersController::class, 'suppressions'])->name('suppressions');
        });

    // gov-templates — 15 notification templates + Mustache preview + suppression pairing.
    Route::prefix('governance/templates')->name('governance.templates.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',             [AdminGovTemplatesController::class, 'index'])  ->name('index');
            Route::get ('/data',         [AdminGovTemplatesController::class, 'data'])   ->name('data');
            Route::get ('/{id}/preview', [AdminGovTemplatesController::class, 'preview'])->whereNumber('id')->name('preview');
            Route::post('/{id}/preview', [AdminGovTemplatesController::class, 'preview'])->whereNumber('id')->name('preview.post');
            Route::patch('/{id}/toggle', [AdminGovTemplatesController::class, 'toggle']) ->whereNumber('id')->name('toggle');
        });

    // gov-dq — cross-table DQ scorecard.
    Route::prefix('governance/data-quality')->name('governance.data-quality.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',            [AdminGovDqController::class, 'index'])     ->name('index');
            Route::get('/summary',     [AdminGovDqController::class, 'summary'])   ->name('summary');
            Route::get('/stragglers',  [AdminGovDqController::class, 'stragglers'])->name('stragglers');
        });

    // gov-retention — secondary_screenings PII retention + export log.
    Route::prefix('governance/retention')->name('governance.retention.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',          [AdminGovRetentionController::class, 'index'])       ->name('index');
            Route::get ('/summary',   [AdminGovRetentionController::class, 'summary'])     ->name('summary');
            Route::get ('/breached',  [AdminGovRetentionController::class, 'breached'])    ->name('breached');
            Route::post('/exports',   [AdminGovRetentionController::class, 'recordExport'])->name('exports.record');
        });

    // sys-cron — scheduled jobs + last-run evidence (v4 read-only + manual trigger).
    Route::prefix('system/cron')->name('system.cron.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',         [AdminSysCronController::class, 'index'])   ->name('index');
            Route::get ('/summary',  [AdminSysCronController::class, 'summary']) ->name('summary');
            Route::get ('/runs',     [AdminSysCronController::class, 'runs'])    ->name('runs');
            Route::get ('/failures', [AdminSysCronController::class, 'failures'])->name('failures');
            Route::post('/trigger',  [AdminSysCronController::class, 'trigger']) ->name('trigger');
        });

    // sys-mail — outbound mail health (v4).
    Route::prefix('system/mail')->name('system.mail.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',        [AdminSysMailController::class, 'index'])  ->name('index');
            Route::get('/summary', [AdminSysMailController::class, 'summary'])->name('summary');
            Route::get('/sends',   [AdminSysMailController::class, 'sends'])  ->name('sends');
        });

    // sys-mobile — device sync, platform, app-version distribution (v4).
    Route::prefix('system/mobile')->name('system.mobile.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',        [AdminSysMobileController::class, 'index'])  ->name('index');
            Route::get('/summary', [AdminSysMobileController::class, 'summary'])->name('summary');
            Route::get('/pending', [AdminSysMobileController::class, 'pending'])->name('pending');
            Route::get('/quiet',   [AdminSysMobileController::class, 'quiet'])  ->name('quiet');
        });

    // sys-migrations — NATIONAL_ADMIN-only schema management surface.
    // Two routes:
    //   GET  /admin/system/migrations         → status page (Blade) + JSON via ?json=1
    //   GET  /admin/system/migrations/status  → JSON status (machine-readable)
    //   POST /admin/system/migrations/run     → run pending migrations
    //                                          · ?dry=1 to preview SQL without writing
    Route::prefix('system/migrations')->name('system.migrations.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',       [AdminSysMigrationsController::class, 'index']) ->name('index');
            Route::get ('/status', [AdminSysMigrationsController::class, 'status'])->name('status');
            Route::post('/run',    [AdminSysMigrationsController::class, 'run'])   ->name('run');
        });

    // sys-who — IHR EIS gateway placeholder (not yet connected) (v4).
    Route::prefix('system/who')->name('system.who.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',          [AdminSysWhoController::class, 'index'])    ->name('index');
            Route::get ('/contract',  [AdminSysWhoController::class, 'contract']) ->name('contract');
            Route::post('/notify-me', [AdminSysWhoController::class, 'notifyMe']) ->name('notify-me');
        });

    // clin-diseases — read-only reference (Paranoid v2 brief §9.1).
    // The toggle endpoint remains for URL-surface compatibility but the
    // controller returns 403; the worked-example endpoint is new.
    Route::prefix('clinical/diseases')->name('clinical.diseases.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get  ('/',                        [AdminClinDiseasesController::class, 'index'])->name('index');
            Route::get  ('/data',                    [AdminClinDiseasesController::class, 'data']) ->name('data');
            Route::get  ('/{id}',                    [AdminClinDiseasesController::class, 'show']) ->whereNumber('id')->name('show');
            Route::get  ('/{id}/worked-example',     [AdminClinDiseasesController::class, 'workedExample'])->whereNumber('id')->name('worked-example');
            Route::patch('/{id}/toggle',             [AdminClinDiseasesController::class, 'toggle'])->whereNumber('id')->name('toggle');
        });

    // clin-symptoms — read-only reference + symptom-combinations endpoint.
    Route::prefix('clinical/symptoms')->name('clinical.symptoms.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get  ('/combinations', [AdminClinSymptomsController::class, 'combinations'])->name('combinations');
            Route::get  ('/',           [AdminClinSymptomsController::class, 'index'])->name('index');
            Route::get  ('/data',       [AdminClinSymptomsController::class, 'data']) ->name('data');
            Route::get  ('/{id}',       [AdminClinSymptomsController::class, 'show']) ->whereNumber('id')->name('show');
            Route::patch('/{id}/toggle',[AdminClinSymptomsController::class, 'toggle'])->whereNumber('id')->name('toggle');
        });

    /*------------------------------------------------------------------
     | Section · My Reports
     | Operational, surveillance, and epidemiological reports scoped to
     | the user's access level. Reads only — no writes to mobile-owned
     | tables. Row-level scope is enforced inside each controller via
     | App\Services\Reports\ReportScope. Sidebar visibility is enforced
     | via App\Services\Reports\ReportAccess in the sidebar partial.
     | The mobile API surface is UNTOUCHED.
     *-----------------------------------------------------------------*/
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/meta', [AdminReportsMetaController::class, 'meta'])->name('meta');

        Route::prefix('rpt-volume')->name('rpt-volume.')->group(function () {
            Route::get ('/',       [AdminReportR1Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR1Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR1Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR1Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-suspected')->name('rpt-suspected.')->group(function () {
            Route::get ('/',       [AdminReportR2Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR2Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR2Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR2Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-geo')->name('rpt-geo.')->group(function () {
            Route::get ('/',       [AdminReportR3Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR3Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR3Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR3Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-contact-tracing')->name('rpt-contact-tracing.')->group(function () {
            Route::get ('/',       [AdminReportR4Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR4Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR4Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR4Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-registry')->name('rpt-registry.')->group(function () {
            Route::get ('/',       [AdminReportR5Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR5Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR5Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR5Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-age-gender')->name('rpt-age-gender.')->group(function () {
            Route::get ('/',       [AdminReportR6Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR6Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR6Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR6Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-symptom-exposure')->name('rpt-symptom-exposure.')->group(function () {
            Route::get ('/',       [AdminReportR7Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR7Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR7Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR7Controller::class, 'export'])->name('export.post');
        });

        // Wave 2 — National Reports analytical surfaces.
        Route::prefix('rpt-screening-outcomes')->name('rpt-screening-outcomes.')->group(function () {
            Route::get ('/',       [AdminReportR8Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR8Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR8Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR8Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-suspected-disease-analytics')->name('rpt-suspected-disease-analytics.')->group(function () {
            Route::get ('/',       [AdminReportR9Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR9Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR9Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR9Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-case-confirmation')->name('rpt-case-confirmation.')->group(function () {
            Route::get ('/',       [AdminReportR10Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR10Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR10Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR10Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-alert-acknowledgement')->name('rpt-alert-acknowledgement.')->group(function () {
            Route::get ('/',       [AdminReportR11Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR11Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR11Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR11Controller::class, 'export'])->name('export.post');
        });
        // R12 legacy rpt-symptom-distribution superseded by Wave-3 V2
        // SymptomDistributionController — see the V2 route group below.
        Route::prefix('rpt-symptom-distribution-legacy')->name('rpt-symptom-distribution-legacy.')->group(function () {
            Route::get ('/',       [AdminReportR12Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR12Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR12Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR12Controller::class, 'export'])->name('export.post');
        });
        Route::prefix('rpt-country-analytics')->name('rpt-country-analytics.')->group(function () {
            Route::get ('/',       [AdminReportR13Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR13Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR13Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR13Controller::class, 'export'])->name('export.post');
        });

        // R8 (new slot 2026-05-18) — Point of Entry Operations.
        Route::prefix('rpt-poe-operations')->name('rpt-poe-operations.')->group(function () {
            Route::get ('/',       [AdminReportR14Controller::class, 'index']) ->name('index');
            Route::get ('/data',   [AdminReportR14Controller::class, 'data'])  ->name('data');
            Route::get ('/export', [AdminReportR14Controller::class, 'export'])->name('export');
            Route::post('/export', [AdminReportR14Controller::class, 'export'])->name('export.post');
        });

        /*--------------------------------------------------------------
         | Wave 3 — Executive Reporting Module V2 (ported from rw-poe
         | 2026-04-27). Clean controllers under V2 namespace; render
         | data by default instead of falling back to the filter wizard.
         | The legacy rpt-* surfaces above are scheduled for demolition.
         | Mobile API contract untouched.
         *--------------------------------------------------------------*/
        Route::prefix('rpt-screening-overview')->name('rpt-screening-overview.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\ScreeningOverviewController::class;
            Route::get('/',                     [$c, 'index'])       ->name('index');
            Route::get('/meta',                 [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',                 [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',        [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv',    [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',              [$c, 'records'])     ->name('records');
            Route::get('/records/{poe}',        [$c, 'recordDetail'])->name('records.detail');
        });
        Route::prefix('rpt-gender')->name('rpt-gender.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\GenderAnalyticsController::class;
            Route::get('/',                     [$c, 'index'])       ->name('index');
            Route::get('/meta',                 [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',                 [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',        [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv',    [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',              [$c, 'records'])     ->name('records');
            Route::get('/records/{poe}',        [$c, 'recordDetail'])->name('records.detail');
        });
        Route::prefix('rpt-symptom-distribution')->name('rpt-symptom-distribution.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\SymptomDistributionController::class;
            Route::get('/',                     [$c, 'index'])       ->name('index');
            Route::get('/meta',                 [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',                 [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',        [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv',    [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',              [$c, 'records'])     ->name('records');
            Route::get('/records/{symptom}',    [$c, 'recordDetail'])->name('records.detail');
        });
        Route::prefix('rpt-ops-risk')->name('rpt-ops-risk.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\OperationalRiskController::class;
            Route::get('/',                       [$c, 'index'])       ->name('index');
            Route::get('/meta',                   [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',                   [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',          [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv',      [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',                [$c, 'records'])     ->name('records');
            Route::get('/records/{type}/{key}',   [$c, 'recordDetail'])->name('records.detail');
        });
        Route::prefix('rpt-alert-intel')->name('rpt-alert-intel.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\AlertIntelligenceController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{id}',      [$c, 'recordDetail'])->whereNumber('id')->name('records.detail');
        });
        Route::prefix('rpt-response-time')->name('rpt-response-time.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\ResponseTimelinessController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{id}',      [$c, 'recordDetail'])->whereNumber('id')->name('records.detail');
        });
        Route::prefix('rpt-resolution-db')->name('rpt-resolution-db.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\ResolutionDatabaseController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{id}',      [$c, 'recordDetail'])->whereNumber('id')->name('records.detail');
        });
        Route::prefix('rpt-case-files')->name('rpt-case-files.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\CaseFileRegistryController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{id}',      [$c, 'recordDetail'])->whereNumber('id')->name('records.detail');
            // Full-page case file (replaces the cramped 460px side-sheet for
            // deep links from other reports). Registers AFTER the literal
            // /records routes so /records/{id} keeps returning JSON.
            Route::get('/{id}',              [$c, 'show'])        ->whereNumber('id')->name('show');
        });
        Route::prefix('rpt-poe-performance')->name('rpt-poe-performance.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\PoePerformanceController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{key}',     [$c, 'recordDetail'])->name('records.detail');
        });
        Route::prefix('rpt-user-activity')->name('rpt-user-activity.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\UserActivityController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{key}',     [$c, 'recordDetail'])->name('records.detail');
        });
        Route::prefix('rpt-country-travel')->name('rpt-country-travel.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\CountryTravelController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{key}',     [$c, 'recordDetail'])->name('records.detail');
        });
        Route::prefix('rpt-national-dashboard')->name('rpt-national-dashboard.')->group(function () {
            $c = \App\Http\Controllers\Admin\Reports\V2\NationalDashboardController::class;
            Route::get('/',                  [$c, 'index'])       ->name('index');
            Route::get('/meta',              [$c, 'meta'])        ->name('meta');
            Route::get('/kpis',              [$c, 'kpis'])        ->name('kpis');
            Route::get('/chart/{chart}',     [$c, 'chart'])       ->name('chart');
            Route::get('/chart/{chart}/csv', [$c, 'chartCsv'])    ->name('chart.csv');
            Route::get('/records',           [$c, 'records'])     ->name('records');
            Route::get('/records/{key}',     [$c, 'recordDetail'])->name('records.detail');
        });
    });

    /*------------------------------------------------------------------
     | Section · Quick Reports
     | Minimalistic 1-chart / 1-table operational shortcuts. Same RBAC
     | + memoise + ExportWriter machinery as /admin/reports, but each
     | surface is locked to one question, one chart, one table — no
     | tabs, no kanban, no 6-card KPI walls. Default window: past 7d.
     | Mobile API contracts: untouched.
     *-----------------------------------------------------------------*/
    Route::prefix('quick-reports')->name('quick.')->group(function () {
        Route::prefix('suspected-cases')->name('suspected.')->group(function () {
            $c = \App\Http\Controllers\Admin\QuickReports\SuspectedCasesController::class;
            Route::get ('/',       [$c, 'index']) ->name('index');
            Route::get ('/data',   [$c, 'data'])  ->name('data');
            Route::get ('/export', [$c, 'export'])->name('export');
        });
    });

    // clin-exposures — ref_exposures + ref_exposure_mappings.
    Route::prefix('clinical/exposures')->name('clinical.exposures.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get  ('/',           [AdminClinExposuresController::class, 'index']) ->name('index');
            Route::get  ('/data',       [AdminClinExposuresController::class, 'data'])  ->name('data');
            Route::patch('/{id}/toggle',[AdminClinExposuresController::class, 'toggle'])->whereNumber('id')->name('toggle');
        });

    // clin-boosts — ref_engine_config scoring rules.
    Route::prefix('clinical/boosts')->name('clinical.boosts.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get  ('/',           [AdminClinBoostsController::class, 'index']) ->name('index');
            Route::get  ('/data',       [AdminClinBoostsController::class, 'data'])  ->name('data');
            Route::patch('/{id}/toggle',[AdminClinBoostsController::class, 'toggle'])->whereNumber('id')->name('toggle');
        });

    // clin-endemic — ref_endemic_countries outbreak map.
    Route::prefix('clinical/endemic')->name('clinical.endemic.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get  ('/',          [AdminClinEndemicController::class, 'index'])      ->name('index');
            Route::get  ('/data',      [AdminClinEndemicController::class, 'data'])       ->name('data');
            Route::patch('/{id}/level',[AdminClinEndemicController::class, 'updateLevel'])->whereNumber('id')->name('level');
        });

    // clin-vaccines — documentation rules + submission rollups.
    Route::prefix('clinical/vaccines')->name('clinical.vaccines.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',     [AdminClinVaccinesController::class, 'index'])->name('index');
            Route::get('/data', [AdminClinVaccinesController::class, 'data']) ->name('data');
        });

    // intel-rank — rolling disease ranking + confidence bands.
    Route::prefix('intelligence/rank')->name('intelligence.rank.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',        [AdminIntelRankController::class, 'index'])  ->name('index');
            Route::get('/summary', [AdminIntelRankController::class, 'summary'])->name('summary');
        });

    // intel-geo — PoE benchmark + case density.
    Route::prefix('intelligence/geo')->name('intelligence.geo.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',        [AdminIntelGeoController::class, 'index'])  ->name('index');
            Route::get('/summary', [AdminIntelGeoController::class, 'summary'])->name('summary');
        });

    // intel-trip — 5-tripwire deterministic surveillance.
    Route::prefix('intelligence/tripwires')->name('intelligence.tripwires.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get('/',                    [AdminIntelTripController::class, 'index'])  ->name('index');
            Route::get('/summary',             [AdminIntelTripController::class, 'summary'])->name('summary');
            // §2.8 / §9.3 — Suppressed By Cadence visibility surface.
            Route::get('/suppressed-by-cadence', [AdminIntelTripController::class, 'suppressedByCadence'])->name('suppressed-by-cadence');
        });

    // intel-digests — scheduled digest builder + manual trigger + cron history.
    Route::prefix('intelligence/digests')->name('intelligence.digests.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',        [AdminIntelDigestsController::class, 'index'])  ->name('index');
            Route::get ('/summary', [AdminIntelDigestsController::class, 'summary'])->name('summary');
            Route::post('/preview', [AdminIntelDigestsController::class, 'preview'])->name('preview');
            Route::post('/trigger', [AdminIntelDigestsController::class, 'trigger'])->name('trigger');
        });

    // intel-copilot — deterministic recommendations + alert narrations.
    Route::prefix('intelligence/copilot')->name('intelligence.copilot.')
        ->middleware('role:NATIONAL_ADMIN')->group(function () {
            Route::get ('/',                [AdminIntelCopilotController::class, 'index'])  ->name('index');
            Route::get ('/summary',         [AdminIntelCopilotController::class, 'summary'])->name('summary');
            Route::get ('/alerts/{id}/narrate', [AdminIntelCopilotController::class, 'narrate'])
                ->whereNumber('id')->name('narrate');
            Route::post('/ask',             [AdminIntelCopilotController::class, 'ask'])    ->name('ask');
        });
});
