<?php

use App\Http\Controllers\AggregatedController as AGC;
use App\Http\Controllers\AlertsController as AC;
use App\Http\Controllers\HomeDashboardController as HDC;
use App\Http\Controllers\PrimaryScreeningController;
use App\Http\Controllers\PrimaryScreeningDashboardController as PSDC;
use App\Http\Controllers\PrimaryScreeningRecordsController as PSRC;
use App\Http\Controllers\SecondaryScreeningController as SSC;
use App\Http\Controllers\SecondaryScreeningRecordsController as SSRC;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserLoginController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════════════════
//  POE Sentinel — routes/api.php
//  ECSA-HC · WHO IHR 2005 Aligned
//
//  ORDERING LAW: declare specific/named paths BEFORE parameterised {id} paths
//  in every group. Laravel matches top-to-bottom. "summary", "stats", "today",
//  "records", "by-notification" are all valid {id} values — they MUST come first.
//
//  AUTH: ALL routes are open by design. No auth middleware is applied here.
//  Auth middleware (auth:sanctum) will be added as a separate layer.
//  NEVER add Authorization headers in frontend fetches until that is done.
// ══════════════════════════════════════════════════════════════════════════════

// ── Auth ───────────────────────────────────────────────────────────────────
Route::post('/auth/login', [UserLoginController::class, 'login']);
Route::post('/auth/logout', [UserLoginController::class, 'logout']);

// ── Home Dashboard ─────────────────────────────────────────────────────────
Route::get('/home/summary', [HDC::class, 'summary']);
Route::get('/home/live', [HDC::class, 'live']);
Route::get('/home/activity', [HDC::class, 'activity']);

// ── Primary Screening Dashboard ────────────────────────────────────────────
Route::get('/dashboard/summary', [PSDC::class, 'summary']);
Route::get('/dashboard/trend', [PSDC::class, 'trend']);
Route::get('/dashboard/heatmap', [PSDC::class, 'heatmap']);
Route::get('/dashboard/funnel', [PSDC::class, 'funnel']);
Route::get('/dashboard/epi', [PSDC::class, 'epi']);
Route::get('/dashboard/poe-comparison', [PSDC::class, 'poeComparison']);
Route::get('/dashboard/screener-report', [PSDC::class, 'screenerReport']);
Route::get('/dashboard/device-health', [PSDC::class, 'deviceHealth']);
Route::get('/dashboard/alerts-summary', [PSDC::class, 'alertsSummary']);
Route::get('/dashboard/weekly-report', [PSDC::class, 'weeklyReport']);
Route::get('/dashboard/live', [PSDC::class, 'live']);

// ── Primary Screenings ─────────────────────────────────────────────────────
// ⚠ /stats/today and /referral-queue BEFORE /{id}
Route::get('/primary-screenings/stats/today', [PrimaryScreeningController::class, 'stats']);
Route::get('/primary-screenings', [PrimaryScreeningController::class, 'index']);
Route::post('/primary-screenings', [PrimaryScreeningController::class, 'store']);
Route::get('/primary-screenings/{id}', [PrimaryScreeningController::class, 'show']);
Route::patch('/primary-screenings/{id}/void', [PrimaryScreeningController::class, 'void']);

Route::get('/referral-queue', [PrimaryScreeningController::class, 'queue']);
Route::patch('/referral-queue/{notifId}/cancel', [PrimaryScreeningController::class, 'cancelReferral']);

// ── Primary Records (officer record register + analytics) ─────────────────
// ⚠ /stats, /heatmap, /trend, /export BEFORE /{id}
Route::get('/primary-records/stats', [PSRC::class, 'stats']);
Route::get('/primary-records/heatmap', [PSRC::class, 'heatmap']);
Route::get('/primary-records/trend', [PSRC::class, 'trend']);
Route::get('/primary-records/export', [PSRC::class, 'export']);
Route::get('/primary-records', [PSRC::class, 'index']);
Route::get('/primary-records/{id}', [PSRC::class, 'show']);
Route::patch('/primary-records/{id}/void', [PSRC::class, 'void']);

// ── Notifications (read-only hydration for IDB miss) ───────────────────────
// Used when the mobile app opens a case file via notification UUID but the
// notification was never synced to this device (fresh device / cross-account).
Route::get('/notifications/by-uuid/{uuid}', [SSC::class, 'showNotificationByUuid']);

// ── Secondary Screenings ───────────────────────────────────────────────────
// ⚠ /by-notification/{uuid} BEFORE /{id} — "by-notification" would match {id}
Route::get('/secondary-screenings/by-notification/{uuid}', [SSC::class, 'showByNotification']);
Route::get('/secondary-screenings', [SSC::class, 'index']);
Route::post('/secondary-screenings', [SSC::class, 'store']);
Route::get('/secondary-screenings/{id}', [SSC::class, 'show']);
Route::delete('/secondary-screenings/{id}', [SSC::class, 'softDelete']);
Route::patch('/secondary-screenings/{id}', [SSC::class, 'update']);
Route::patch('/secondary-screenings/{id}/status', [SSC::class, 'updateStatus']);
Route::post('/secondary-screenings/{id}/symptoms', [SSC::class, 'syncSymptoms']);
Route::post('/secondary-screenings/{id}/exposures', [SSC::class, 'syncExposures']);
Route::post('/secondary-screenings/{id}/actions', [SSC::class, 'syncActions']);
Route::post('/secondary-screenings/{id}/samples', [SSC::class, 'syncSamples']);
Route::post('/secondary-screenings/{id}/travel', [SSC::class, 'syncTravel']);
Route::post('/secondary-screenings/{id}/diseases', [SSC::class, 'syncDiseases']);
Route::post('/secondary-screenings/{id}/sync', [SSC::class, 'fullSync']);
// In-app self-test endpoint. Returns a compact, verification-shaped payload
// (scalar fields + child counts grouped by Biodata / Travel / Vitals / Engine /
// Disposition) so the mobile view can confirm every UI-captured field actually
// landed in the database after a sync. Read-only, no side effects.
Route::get('/secondary-screenings/{id}/verify', [SSC::class, 'verify']);

// ── Secondary Screening Records (case register read) ──────────────────────
// ⚠ /stats BEFORE /{id}
Route::get('/screening-records/stats', [SSRC::class, 'stats']);
Route::get('/screening-records', [SSRC::class, 'index']);
Route::get('/screening-records/{id}', [SSRC::class, 'show']);

// ── Alerts ─────────────────────────────────────────────────────────────────
// ⚠ /summary BEFORE /{id} — "summary" would match /{id} otherwise
// Role enforcement: DISTRICT_SUPERVISOR (DISTRICT), PHEOC_OFFICER (PHEOC), NATIONAL_ADMIN (NATIONAL)
Route::get('/alerts/summary', [AC::class, 'summary']);
Route::get('/alerts/compliance', [\App\Http\Controllers\AlertFollowupsController::class, 'compliance']);
Route::get('/alerts/close-categories', [AC::class, 'closeCategories']);
Route::get('/alerts', [AC::class, 'index']);
Route::post('/alerts', [AC::class, 'store']);
Route::get('/alerts/{id}', [AC::class, 'show']);
Route::patch('/alerts/{id}/acknowledge', [AC::class, 'acknowledge']);
Route::patch('/alerts/{id}/close', [AC::class, 'close']);
// /alerts/{id}/reopen is registered below under ACC (collaboration controller) —
// keep this comment so nobody re-adds the AC duplicate that shadows it.
Route::get('/alerts/{id}/followups', [\App\Http\Controllers\AlertFollowupsController::class, 'index']);
Route::post('/alerts/{id}/followups', [\App\Http\Controllers\AlertFollowupsController::class, 'store']);
Route::patch('/alert-followups/{id}', [\App\Http\Controllers\AlertFollowupsController::class, 'update']);

// ── Aggregated Data Submissions ────────────────────────────────────────────
// Roles: POE_DATA_OFFICER, POE_ADMIN, NATIONAL_ADMIN only
Route::get('/aggregated', [AGC::class, 'index']);
Route::post('/aggregated', [AGC::class, 'store']);
Route::get('/aggregated/{id}', [AGC::class, 'show']);

// ── Aggregated Templates (admin-managed, country-scoped) ──────────────────
// /active BEFORE /{id} to prevent "active" being treated as an integer id.
// /active + /published BEFORE /{id} so Laravel doesn't treat them as numeric id.
Route::get   ('/aggregated-templates/active',        [\App\Http\Controllers\AggregatedTemplatesController::class, 'active']);
Route::get   ('/aggregated-templates/published',     [\App\Http\Controllers\AggregatedTemplatesController::class, 'published']);
Route::get   ('/aggregated-templates',               [\App\Http\Controllers\AggregatedTemplatesController::class, 'index']);
Route::post  ('/aggregated-templates',               [\App\Http\Controllers\AggregatedTemplatesController::class, 'store']);
Route::get   ('/aggregated-templates/{id}',          [\App\Http\Controllers\AggregatedTemplatesController::class, 'show']);
Route::patch ('/aggregated-templates/{id}',          [\App\Http\Controllers\AggregatedTemplatesController::class, 'update']);
Route::delete('/aggregated-templates/{id}',          [\App\Http\Controllers\AggregatedTemplatesController::class, 'destroy']);
Route::post  ('/aggregated-templates/{id}/publish',  [\App\Http\Controllers\AggregatedTemplatesController::class, 'publish']);
Route::post  ('/aggregated-templates/{id}/retire',   [\App\Http\Controllers\AggregatedTemplatesController::class, 'retire']);
Route::post  ('/aggregated-templates/{id}/activate', [\App\Http\Controllers\AggregatedTemplatesController::class, 'activate']); // alias for publish
Route::post  ('/aggregated-templates/{id}/lock',     [\App\Http\Controllers\AggregatedTemplatesController::class, 'lock']);
Route::post  ('/aggregated-templates/{id}/columns',  [\App\Http\Controllers\AggregatedTemplatesController::class, 'addColumn']);
Route::patch ('/aggregated-templates/{id}/columns',  [\App\Http\Controllers\AggregatedTemplatesController::class, 'bulkUpdateColumns']);
Route::patch ('/aggregated-template-columns/{colId}',  [\App\Http\Controllers\AggregatedTemplatesController::class, 'updateColumn']);
Route::delete('/aggregated-template-columns/{colId}',  [\App\Http\Controllers\AggregatedTemplatesController::class, 'deleteColumn']);

// ── POE Notification Contacts ─────────────────────────────────────────────
Route::get   ('/poe-contacts/escalation-chain', [\App\Http\Controllers\PoeContactsController::class, 'escalationChain']);
Route::get   ('/poe-contacts/system-users',     [\App\Http\Controllers\PoeContactsController::class, 'systemUsers']);
Route::get   ('/poe-contacts',             [\App\Http\Controllers\PoeContactsController::class, 'index']);
Route::post  ('/poe-contacts',             [\App\Http\Controllers\PoeContactsController::class, 'store']);
Route::patch ('/poe-contacts/{id}',        [\App\Http\Controllers\PoeContactsController::class, 'update']);
Route::delete('/poe-contacts/{id}',        [\App\Http\Controllers\PoeContactsController::class, 'destroy']);

// ── Enterprise Notifications (alerts, escalations, reminders, reports) ────
Route::post('/notifications/alert-broadcast',   [\App\Http\Controllers\NotificationsController::class, 'alertBroadcast']);
Route::post('/notifications/escalation',        [\App\Http\Controllers\NotificationsController::class, 'escalation']);
Route::post('/notifications/followup-reminder', [\App\Http\Controllers\NotificationsController::class, 'followupReminder']);
Route::post('/notifications/pheic-advisory',    [\App\Http\Controllers\NotificationsController::class, 'pheicAdvisory']);
Route::post('/notifications/daily-report',      [\App\Http\Controllers\NotificationsController::class, 'dailyReport']);
Route::post('/notifications/weekly-report',     [\App\Http\Controllers\NotificationsController::class, 'weeklyReport']);
Route::post('/notifications/send',              [\App\Http\Controllers\NotificationsController::class, 'send']);
Route::post('/notifications/retry-failed',      [\App\Http\Controllers\NotificationsController::class, 'retryFailed']);
Route::get ('/notifications/log',               [\App\Http\Controllers\NotificationsController::class, 'log']);
Route::get ('/notifications/stats',             [\App\Http\Controllers\NotificationsController::class, 'stats']);

// ══════════════════════════════════════════════════════════════════════════
//  COLLABORATION / WAR-ROOM — PWA admin endpoints
//  All routes mount under the same /alerts/{id}/… hierarchy + companion
//  resource routes on /alert-comments, /alert-collaborators, etc.
// ══════════════════════════════════════════════════════════════════════════
use App\Http\Controllers\AlertCollaborationController as ACC;
Route::get   ('/alerts/{id}/war-room',                    [ACC::class, 'warRoom']);
Route::get   ('/alerts/{id}/timeline',                    [ACC::class, 'timeline']);
Route::get   ('/alerts/{id}/collaborators',               [ACC::class, 'collaborators']);
Route::post  ('/alerts/{id}/collaborators',               [ACC::class, 'addCollaborator']);
Route::patch ('/alert-collaborators/{id}',                [ACC::class, 'updateCollaborator']);
Route::delete('/alert-collaborators/{id}',                [ACC::class, 'removeCollaborator']);
Route::get   ('/alerts/{id}/comments',                    [ACC::class, 'comments']);
Route::post  ('/alerts/{id}/comments',                    [ACC::class, 'postComment']);
Route::patch ('/alert-comments/{id}',                     [ACC::class, 'editComment']);
Route::delete('/alert-comments/{id}',                     [ACC::class, 'deleteComment']);
Route::post  ('/alert-comments/{id}/pin',                 [ACC::class, 'togglePin']);
Route::post  ('/alert-comments/{id}/react',               [ACC::class, 'reactToComment']);
Route::get   ('/alerts/{id}/evidence',                    [ACC::class, 'evidence']);
Route::post  ('/alerts/{id}/evidence',                    [ACC::class, 'addEvidence']);
Route::delete('/alert-evidence/{id}',                     [ACC::class, 'deleteEvidence']);
Route::get   ('/alerts/{id}/handoffs',                    [ACC::class, 'handoffs']);
Route::post  ('/alerts/{id}/handoffs',                    [ACC::class, 'createHandoff']);
Route::post  ('/alert-handoffs/{id}/accept',              [ACC::class, 'acceptHandoff']);
Route::post  ('/alert-handoffs/{id}/reject',              [ACC::class, 'rejectHandoff']);
Route::post  ('/alerts/{id}/escalate',                    [ACC::class, 'escalate']);
Route::post  ('/alerts/{id}/reopen',                      [ACC::class, 'reopen']);
Route::post  ('/alerts/{id}/reassign',                    [ACC::class, 'reassign']);
Route::post  ('/alerts/{id}/breach-report',               [ACC::class, 'logBreachReport']);
Route::get   ('/alerts/{id}/breach-reports',              [ACC::class, 'breachReports']);
Route::patch ('/alert-breach-reports/{id}',               [ACC::class, 'updateBreachReport']);
Route::post  ('/alerts/{id}/pheic-declare',               [ACC::class, 'declarePheic']);
Route::post  ('/alerts/{id}/request-external-info',       [ACC::class, 'requestExternalInfo']);

// ══════════════════════════════════════════════════════════════════════════
//  Mobile lifecycle endpoints (case-file aggregator, advisor, case-outcome,
//  blocker resolver, comms inbox). Every route in this block is paranoid:
//  resolves actor, validates active assignment, enforces geographic scope,
//  role-gates writes, emits timeline events. See AlertsLifecycleController.
// ══════════════════════════════════════════════════════════════════════════
use App\Http\Controllers\AlertsLifecycleController as ALC;
Route::get   ('/alerts/{id}/case-file',                   [ALC::class, 'caseFile']);
Route::get   ('/alerts/{id}/advisor',                     [ALC::class, 'advisor']);
Route::get   ('/alerts/{id}/case-outcome',                [ALC::class, 'getOutcome']);
Route::post  ('/alerts/{id}/case-outcome',                [ALC::class, 'upsertOutcome']);
Route::get   ('/alerts/{id}/comms-inbox',                 [ALC::class, 'commsInbox']);
Route::post  ('/alert-followups/{id}/resolve-blocker',    [ALC::class, 'resolveBlocker']);

// ── External Responders (registry) ─────────────────────────────────────────
use App\Http\Controllers\ExternalRespondersController as ERC;
Route::get   ('/external-responders/stats',               [ERC::class, 'stats']);
Route::get   ('/external-responders',                     [ERC::class, 'index']);
Route::post  ('/external-responders',                     [ERC::class, 'store']);
Route::get   ('/external-responders/{id}',                [ERC::class, 'show']);
Route::patch ('/external-responders/{id}',                [ERC::class, 'update']);
Route::delete('/external-responders/{id}',                [ERC::class, 'destroy']);

// ── Responder Info Requests (inbound response loop) ────────────────────────
use App\Http\Controllers\ResponderInfoRequestsController as RIR;
Route::get  ('/responder-info-requests',                          [RIR::class, 'index']);
Route::get  ('/responder-info-requests/by-token/{token}',         [RIR::class, 'byToken']);
Route::post ('/responder-info-requests/by-token/{token}/respond', [RIR::class, 'respond']);
Route::get  ('/responder-info-requests/{id}',                     [RIR::class, 'show']);
Route::post ('/responder-info-requests/{id}/cancel',              [RIR::class, 'cancel']);

// ── Notification Templates (admin CRUD + preview) ──────────────────────────
use App\Http\Controllers\NotificationTemplatesController as NTC;
Route::get   ('/notification-templates/token-reference',   [NTC::class, 'tokenReference']);
Route::get   ('/notification-templates',                   [NTC::class, 'index']);
Route::post  ('/notification-templates',                   [NTC::class, 'store']);
Route::get   ('/notification-templates/{code}/usage',      [NTC::class, 'usage']);
Route::post  ('/notification-templates/{code}/preview',    [NTC::class, 'preview']);
Route::get   ('/notification-templates/{code}',            [NTC::class, 'show']);
Route::patch ('/notification-templates/{code}',            [NTC::class, 'update']);
Route::delete('/notification-templates/{code}',            [NTC::class, 'destroy']);

// ── Intelligence Dashboard ─────────────────────────────────────────────────
use App\Http\Controllers\IntelligenceController as IC;
Route::get('/intelligence/dashboard',               [IC::class, 'dashboard']);
Route::get('/intelligence/silent-poes',             [IC::class, 'silentPoes']);
Route::get('/intelligence/unsubmitted',             [IC::class, 'unsubmitted']);
Route::get('/intelligence/dormant-accounts',        [IC::class, 'dormantAccounts']);
Route::get('/intelligence/stuck-alerts',            [IC::class, 'stuckAlerts']);
Route::get('/intelligence/overdue-followups',       [IC::class, 'overdueFollowups']);
Route::get('/intelligence/case-spikes',             [IC::class, 'caseSpikes']);
Route::get('/intelligence/kpi/seven-one-seven',     [IC::class, 'sevenOneSeven']);
Route::get('/intelligence/timeline/national',       [IC::class, 'nationalTimeline']);
Route::get('/intelligence/disease-ranking',         [IC::class, 'diseaseRankingAction']);
Route::get('/intelligence/heatmap/poes',            [IC::class, 'heatmapPoes']);
Route::get('/intelligence/map/latest',              [IC::class, 'mapLatest']);

// ── Digests (admin preview + manual trigger) ───────────────────────────────
use App\Http\Controllers\DigestsController as DC;
Route::get ('/digests/daily/preview',        [DC::class, 'previewDaily']);
Route::get ('/digests/national/preview',     [DC::class, 'previewNational']);
Route::post('/digests/daily/send',           [DC::class, 'sendDaily']);
Route::post('/digests/national/send',        [DC::class, 'sendNational']);
Route::post('/digests/followups/send',       [DC::class, 'sendFollowups']);
Route::post('/digests/retry-failed',         [DC::class, 'retryFailed']);
Route::get ('/digests/history',              [DC::class, 'history']);

// ── Notifications Inbox (per-user) ─────────────────────────────────────────
use App\Http\Controllers\NotificationsInboxController as NIC;
Route::get ('/inbox/unread-count',  [NIC::class, 'unreadCount']);
Route::get ('/inbox/facets',        [NIC::class, 'facets']);
Route::post('/inbox/mark-read',     [NIC::class, 'markRead']);
Route::post('/inbox/mark-unread',   [NIC::class, 'markUnread']);
Route::post('/inbox/mark-all-read', [NIC::class, 'markAllRead']);
Route::get ('/inbox',               [NIC::class, 'index']);
Route::get ('/inbox/{id}',          [NIC::class, 'show']);

// ══════════════════════════════════════════════════════════════════════════
//  DASHBOARD AUTH (v2) — native Laravel auth + Sanctum bearer tokens
//  Only the master web PWA uses these. The mobile app keeps its custom
//  /auth/login pathway above (UserLoginController).
// ══════════════════════════════════════════════════════════════════════════
use App\Http\Controllers\Auth\DashboardAuthController as DAC;
use App\Http\Controllers\Auth\DashboardEmailVerificationController as DEVC;
use App\Http\Controllers\Auth\DashboardPasswordResetController as DPRC;
use App\Http\Controllers\Auth\TwoFactorController as TFC;
use App\Http\Controllers\Auth\TrustedDeviceController as TDC;
use App\Http\Controllers\Admin\UsersAdminController as UAC;
use App\Http\Controllers\Admin\UserAssignmentsController as UASC;
use App\Http\Controllers\Admin\GeographyController as GEO;
use App\Http\Controllers\Admin\SystemHealthController as SHC;
use App\Http\Controllers\Admin\AuditController as AUC;

// ── Public endpoints (no auth) ────────────────────────────────────────────
Route::prefix('v2/auth')->group(function () {
    Route::post('/login',                 [DAC::class, 'login']);
    Route::post('/2fa-verify',            [DAC::class, 'twoFaVerify']);
    Route::post('/password/forgot',       [DPRC::class, 'forgot']);
    Route::post('/password/reset',        [DPRC::class, 'reset']);
    Route::post('/verify-email/confirm',  [DEVC::class, 'confirm']);
    Route::post('/verify-email/send-for', [DEVC::class, 'sendFor']);
    Route::post('/accept-invitation',     [UAC::class,  'acceptInvitation']);
});

// ── Authenticated endpoints (Sanctum bearer) ──────────────────────────────
Route::prefix('v2')->middleware('auth:sanctum')->group(function () {

    // Self-service auth
    Route::get  ('/auth/me',                [DAC::class, 'me']);
    Route::patch('/auth/me',                [DAC::class, 'updateMe']);
    Route::post ('/auth/logout',            [DAC::class, 'logout']);
    Route::post('/auth/logout-all',        [DAC::class, 'logoutAll']);
    Route::post('/auth/refresh',           [DAC::class, 'refresh']);
    Route::post('/auth/change-password',   [DAC::class, 'changePassword']);
    Route::get ('/auth/sessions',          [DAC::class, 'sessions']);
    Route::delete('/auth/sessions/{id}',   [DAC::class, 'revokeSession']);

    Route::post('/auth/verify-email/send', [DEVC::class, 'send']);

    Route::get ('/auth/2fa/status',              [TFC::class, 'status']);
    Route::post('/auth/2fa/setup',               [TFC::class, 'setup']);
    Route::post('/auth/2fa/confirm',             [TFC::class, 'confirm']);
    Route::post('/auth/2fa/disable',             [TFC::class, 'disable']);
    Route::post('/auth/2fa/recovery-codes',      [TFC::class, 'regenerateRecoveryCodes']);

    Route::get ('/auth/trusted-devices',           [TDC::class, 'index']);
    Route::post('/auth/trusted-devices',           [TDC::class, 'register']);
    Route::delete('/auth/trusted-devices/{id}',    [TDC::class, 'revoke']);
    Route::post('/auth/trusted-devices/revoke-all',[TDC::class, 'revokeAll']);

    // Admin surface — requires NATIONAL_ADMIN / PHEOC_OFFICER / DISTRICT_SUPERVISOR / POE_ADMIN
    Route::prefix('admin')->middleware('role:NATIONAL_ADMIN,PHEOC_OFFICER,DISTRICT_SUPERVISOR,POE_ADMIN')->group(function () {
        // Users
        Route::get   ('/users/stats',                    [UAC::class, 'stats']);
        Route::get   ('/users/report/risk',              [UAC::class, 'reportRisk']);
        Route::get   ('/users/report/roles',             [UAC::class, 'reportRoles']);
        Route::get   ('/users/report/dormant',           [UAC::class, 'reportDormant']);
        Route::get   ('/users/report/mfa',               [UAC::class, 'reportMfa']);
        Route::post  ('/users/bulk',                     [UAC::class, 'bulk']);
        Route::post  ('/users/scan-all',                 [UAC::class, 'scanAll']);
        Route::get   ('/users',                          [UAC::class, 'index']);
        Route::post  ('/users',                          [UAC::class, 'store']);
        Route::get   ('/users/{id}',                     [UAC::class, 'show']);
        Route::patch ('/users/{id}',                     [UAC::class, 'update']);
        Route::delete('/users/{id}',                     [UAC::class, 'destroy']);
        Route::post  ('/users/{id}/suspend',             [UAC::class, 'suspend']);
        Route::post  ('/users/{id}/reactivate',          [UAC::class, 'reactivate']);
        Route::post  ('/users/{id}/reset-password',      [UAC::class, 'resetPassword']);
        Route::post  ('/users/{id}/force-mfa-reset',     [UAC::class, 'forceMfaReset']);
        Route::post  ('/users/{id}/rescan',              [UAC::class, 'rescan']);
        Route::get   ('/users/{id}/activity',            [UAC::class, 'activity']);
        Route::get   ('/users/{id}/flags',               [UAC::class, 'flags']);
        Route::post  ('/users/{id}/flags/{flagId}/clear',[UAC::class, 'clearFlag']);

        // User assignments
        Route::get   ('/users/{id}/assignments',         [UASC::class, 'indexForUser']);
        Route::post  ('/users/{id}/assignments',         [UASC::class, 'store']);
        Route::get   ('/assignments',                    [UASC::class, 'searchAll']);
        Route::patch ('/user-assignments/{id}',          [UASC::class, 'update']);
        Route::delete('/user-assignments/{id}',          [UASC::class, 'destroy']);

        // Geography
        Route::get('/geography/countries', [GEO::class, 'countries']);
        Route::get('/geography/districts', [GEO::class, 'districts']);
        Route::get('/geography/poes',      [GEO::class, 'poes']);
        Route::get('/geography/tree',      [GEO::class, 'tree']);

        // System Health
        Route::get('/system/health', [SHC::class, 'health']);

        // Audit
        Route::get('/audit/feed',          [AUC::class, 'feed']);
        Route::get('/audit/auth',          [AUC::class, 'auth']);
        Route::get('/audit/users',         [AUC::class, 'users']);
        Route::get('/audit/alerts',        [AUC::class, 'alerts']);
        Route::get('/audit/notifications', [AUC::class, 'notifications']);
        Route::get('/audit/stats',         [AUC::class, 'stats']);

        // PHEOC Copilot (deterministic advisor — zero LLM)
        Route::get ('/copilot/recommend',                         [\App\Http\Controllers\Admin\PheocCopilotController::class, 'recommend']);
        Route::get ('/copilot/triage-brief',                      [\App\Http\Controllers\Admin\PheocCopilotController::class, 'triageBrief']);
        Route::post('/copilot/ask',                               [\App\Http\Controllers\Admin\PheocCopilotController::class, 'ask']);
        Route::get ('/copilot/alerts/{id}/narrate',               [\App\Http\Controllers\Admin\PheocCopilotController::class, 'narrate'])->whereNumber('id');
        Route::get ('/copilot/alerts/{id}/close-reason',          [\App\Http\Controllers\Admin\PheocCopilotController::class, 'closeReason'])->whereNumber('id');
        Route::get ('/copilot/alerts/{id}/escalation',            [\App\Http\Controllers\Admin\PheocCopilotController::class, 'escalationRationale'])->whereNumber('id');
        Route::get ('/copilot/cases/{id}/differentials',          [\App\Http\Controllers\Admin\PheocCopilotController::class, 'differentials'])->whereNumber('id');
    });
});

// ── Users ──────────────────────────────────────────────────────────────────
// ⚠ /me BEFORE /{id}
Route::get('/users/me', [UserController::class, 'me']);
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::get('/users/{id}', [UserController::class, 'show']);
Route::patch('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
Route::patch('/users/{id}/status', [UserController::class, 'toggleStatus']);

// ══════════════════════════════════════════════════════════════════════════
//  Geo Hierarchy — Points of Entry bundle + CRUD for the mobile app.
//  Bundle endpoint is byte-equivalent to the legacy hardcoded POEs.js.
//  Write endpoints require user_id of a NATIONAL_ADMIN.
// ══════════════════════════════════════════════════════════════════════════
use App\Http\Controllers\GeoHierarchyController as GHC;

// Canonical bundle for src/POEs.js loader
Route::get('/poes/bundle',          [GHC::class, 'bundle']);
Route::get('/poes/bundle/version',  [GHC::class, 'bundleVersion']);

// Countries
Route::get   ('/geo/countries',          [GHC::class, 'indexCountries']);
Route::post  ('/geo/countries',          [GHC::class, 'storeCountry']);
Route::get   ('/geo/countries/{code}',   [GHC::class, 'showCountry']);
Route::patch ('/geo/countries/{code}',   [GHC::class, 'updateCountry']);
Route::delete('/geo/countries/{code}',   [GHC::class, 'destroyCountry']);

// Provinces (PHEOCs)
Route::get   ('/geo/provinces',          [GHC::class, 'indexProvinces']);
Route::post  ('/geo/provinces',          [GHC::class, 'storeProvince']);
Route::get   ('/geo/provinces/{id}',     [GHC::class, 'showProvince'])->whereNumber('id');
Route::patch ('/geo/provinces/{id}',     [GHC::class, 'updateProvince'])->whereNumber('id');
Route::delete('/geo/provinces/{id}',     [GHC::class, 'destroyProvince'])->whereNumber('id');

// Districts
Route::get   ('/geo/districts',          [GHC::class, 'indexDistricts']);
Route::post  ('/geo/districts',          [GHC::class, 'storeDistrict']);
Route::get   ('/geo/districts/{id}',     [GHC::class, 'showDistrict'])->whereNumber('id');
Route::patch ('/geo/districts/{id}',     [GHC::class, 'updateDistrict'])->whereNumber('id');
Route::delete('/geo/districts/{id}',     [GHC::class, 'destroyDistrict'])->whereNumber('id');

// POEs (normalised CRUD; mobile bundle is served separately above)
Route::get   ('/geo/poes',               [GHC::class, 'indexPoes']);
Route::post  ('/geo/poes',               [GHC::class, 'storePoe']);
Route::get   ('/geo/poes/{id}',          [GHC::class, 'showPoe'])->whereNumber('id');
Route::patch ('/geo/poes/{id}',          [GHC::class, 'updatePoe'])->whereNumber('id');
Route::delete('/geo/poes/{id}',          [GHC::class, 'destroyPoe'])->whereNumber('id');

// Hospitals (new capability — not in legacy bundle)
Route::get   ('/geo/hospitals',          [GHC::class, 'indexHospitals']);
Route::post  ('/geo/hospitals',          [GHC::class, 'storeHospital']);
Route::get   ('/geo/hospitals/{id}',     [GHC::class, 'showHospital'])->whereNumber('id');
Route::patch ('/geo/hospitals/{id}',     [GHC::class, 'updateHospital'])->whereNumber('id');
Route::delete('/geo/hospitals/{id}',     [GHC::class, 'destroyHospital'])->whereNumber('id');

// ─────────────────────────────────────────────────────────────────────────
// Client error telemetry — receives EVERY JS error from the mobile + web
// app. No auth on POST so login/auth-flow errors still reach us. Per-IP
// throttle prevents log floods. See app/Http/Controllers/ClientLogController.php.
// ─────────────────────────────────────────────────────────────────────────
use App\Http\Controllers\ClientLogController;
Route::middleware('throttle:120,1')->group(function () {
    Route::post('/client-logs', [ClientLogController::class, 'store']);
});
Route::get  ('/client-logs',         [ClientLogController::class, 'index']);
Route::get  ('/client-logs/stream',  [ClientLogController::class, 'stream']);
Route::get  ('/client-logs/stats',   [ClientLogController::class, 'stats']);
Route::get  ('/client-logs/{id}',    [ClientLogController::class, 'show'])->whereNumber('id');
