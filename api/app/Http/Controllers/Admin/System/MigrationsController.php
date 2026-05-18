<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Services\AuthEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin · System · Migrations
 * ─────────────────────────────────────────────────────────────────────────
 * NATIONAL_ADMIN-only management surface for inspecting and running pending
 * Laravel migrations against the live application database. Built for the
 * production rollout where DBAs may not have shell access to the host but
 * still need a controlled way to bring schema up to spec after a code push.
 *
 *  GET   /admin/system/migrations             → status page (Blade)
 *  GET   /admin/system/migrations/status      → JSON status (machine-readable)
 *  POST  /admin/system/migrations/run         → run pending migrations
 *                                               · ?dry=1 → --pretend (no writes)
 *                                               · ?step=1 → --step (one batch each)
 *
 * Hard rules (each is enforced, not advisory):
 *   · NATIONAL_ADMIN only (route middleware).
 *   · A single distributed lock prevents concurrent runs.
 *   · Every run is wrapped in try/catch — no error escapes as 500.
 *   · Every call (success or fail) is written to auth_events.
 *   · The runner never modifies the .env, the migrations table by hand,
 *     or any file on disk. It only invokes Artisan::call('migrate').
 *   · The DB connection is sanity-checked before any work is done.
 */
final class MigrationsController
{
    private const LOCK_KEY = 'admin:system:migrations:run';
    private const LOCK_TTL = 300; // seconds

    /* ─────────────────────────────────────────────────────────────────── */
    /* GET  /admin/system/migrations  — full-page Blade view               */
    /* ─────────────────────────────────────────────────────────────────── */
    public function index(Request $request): Response
    {
        $payload = $this->buildStatus($request);
        if ($request->wantsJson() || $request->boolean('json')) {
            return response()->json($payload);
        }
        return response()->view('admin.system.migrations.index', ['data' => $payload]);
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* GET  /admin/system/migrations/status  — JSON only                   */
    /* ─────────────────────────────────────────────────────────────────── */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->buildStatus($request));
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* POST /admin/system/migrations/run                                   */
    /* ─────────────────────────────────────────────────────────────────── */
    public function run(Request $request): JsonResponse
    {
        $dry  = $request->boolean('dry');
        $step = $request->boolean('step');
        $user = $request->user();

        // 1) Acquire the lock — concurrent runs are unsafe.
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);
        if (! $lock->get()) {
            $this->audit('MIGRATION_RUN_LOCKED', $user?->id, 'WARN', ['dry' => $dry, 'step' => $step], $request);
            return response()->json([
                'ok' => false,
                'code' => 'LOCKED',
                'error' => 'Another migration run is in progress. Try again in a minute.',
            ], 423);
        }

        try {
            // 2) Sanity check the DB connection BEFORE doing anything heavy.
            try {
                DB::connection()->getPdo();
                DB::select('SELECT 1');
            } catch (Throwable $e) {
                $this->audit('MIGRATION_DB_UNREACHABLE', $user?->id, 'ERROR',
                    ['dry' => $dry, 'msg' => $e->getMessage()], $request);
                return response()->json([
                    'ok' => false,
                    'code' => 'DB_UNREACHABLE',
                    'error' => 'Database connection failed. Check api/.env DB_* settings.',
                    'detail' => $e->getMessage(),
                ], 503);
            }

            // 3) Build artisan options. --force is required outside `local`.
            $opts = ['--force' => true, '--no-interaction' => true];
            if ($dry)  { $opts['--pretend'] = true; }
            if ($step) { $opts['--step']    = true; }

            // 4) Run.
            $startedAt = microtime(true);
            $exit = Artisan::call('migrate', $opts);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $output = trim((string) Artisan::output());

            // 5) Post-run status snapshot for the response.
            $after = $this->buildStatus($request);

            $ok = $exit === 0;
            $this->audit(
                $ok ? 'MIGRATION_RUN_OK' : 'MIGRATION_RUN_FAILED',
                $user?->id,
                $ok ? 'INFO' : 'ERROR',
                [
                    'dry' => $dry,
                    'step' => $step,
                    'exit' => $exit,
                    'elapsed_ms' => $elapsedMs,
                    'pending_before' => $after['pending_count'] ?? null,
                    'output_excerpt' => mb_substr($output, 0, 4000),
                ],
                $request,
            );

            return response()->json([
                'ok' => $ok,
                'code' => $ok ? 'OK' : 'MIGRATION_FAILED',
                'dry_run' => $dry,
                'step' => $step,
                'exit_code' => $exit,
                'elapsed_ms' => $elapsedMs,
                'output' => $output,
                'status_after' => $after,
            ], $ok ? 200 : 500);
        } catch (Throwable $e) {
            $this->audit('MIGRATION_RUN_EXCEPTION', $user?->id, 'ERROR',
                ['dry' => $dry, 'msg' => $e->getMessage(), 'trace_head' => mb_substr((string) $e->getTraceAsString(), 0, 2000)],
                $request,
            );
            Log::error('Migration run threw', ['msg' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'code' => 'EXCEPTION',
                'error' => $e->getMessage(),
                'class' => $e::class,
            ], 500);
        } finally {
            $lock->release();
        }
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /* Internal — build the status payload                                 */
    /* ─────────────────────────────────────────────────────────────────── */
    private function buildStatus(Request $request): array
    {
        $applied = [];
        $dbReachable = true;
        $dbError = null;

        try {
            if (Schema::hasTable('migrations')) {
                $applied = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
            }
        } catch (Throwable $e) {
            $dbReachable = false;
            $dbError = $e->getMessage();
        }

        $files = $this->listMigrationFiles();

        $appliedSet = array_flip($applied);
        $filesSet   = array_flip($files);

        $pending  = array_values(array_filter($files, fn ($f) => ! isset($appliedSet[$f])));
        $phantoms = array_values(array_filter($applied, fn ($a) => ! isset($filesSet[$a])));

        $tables = [];
        try {
            if ($dbReachable) {
                $rows = DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name');
                $tables = array_map(fn ($r) => $r->t, $rows);
            }
        } catch (Throwable $e) {
            // non-fatal
        }

        return [
            'app_env' => (string) config('app.env'),
            'db_connection' => (string) config('database.default'),
            'db_database' => (string) config('database.connections.' . config('database.default') . '.database'),
            'db_reachable' => $dbReachable,
            'db_error' => $dbError,
            'maintenance_mode' => app()->isDownForMaintenance(),
            'now' => now()->toIso8601String(),
            'files_count' => count($files),
            'applied_count' => count($applied),
            'pending_count' => count($pending),
            'phantom_count' => count($phantoms),
            'tables_count' => count($tables),
            'pending' => $pending,
            'phantoms' => $phantoms,
            'tables' => $tables,
            'lock_held' => Cache::has(self::LOCK_KEY),
        ];
    }

    /**
     * @return list<string>
     */
    private function listMigrationFiles(): array
    {
        $dir = database_path('migrations');
        if (! is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (File::files($dir) as $f) {
            $name = $f->getFilenameWithoutExtension();
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    private function audit(string $event, ?int $userId, string $severity, array $meta, Request $request): void
    {
        try {
            AuthEventLogger::log($event, (int) ($userId ?? 0), null, $severity, $meta, 5, $request);
        } catch (Throwable $e) {
            Log::warning('MigrationsController audit failed', ['msg' => $e->getMessage()]);
        }
    }
}
