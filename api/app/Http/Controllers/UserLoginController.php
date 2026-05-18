<?php

declare (strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * UserLoginController
 *
 * Single responsibility: verify credentials and return the user row
 * together with all active user_assignments rows.
 *
 * ┌────────────────────────────────────────────────────────────────────┐
 * │  INPUT  : { login, password }                                      │
 * │  OUTPUT : every column of users table (no passwords)               │
 * │           + assignment  (primary user_assignments row | null)      │
 * │           + all_assignments (all active rows for this user)        │
 * │           + poe_code / district_code / province_code / pheoc_code  │
 * │             flattened to root of data for App.vue geographic scope  │
 * └────────────────────────────────────────────────────────────────────┘
 *
 * users table columns (poe_2026.sql):
 *   id                bigint UNSIGNED   PK
 *   role_key          varchar(60)
 *   country_code      varchar(10)
 *   full_name         varchar(150)
 *   username          varchar(80)       UNIQUE
 *   password_hash     varchar(255)      bcrypt — POE mobile pathway
 *   email             varchar(190)      UNIQUE
 *   phone             varchar(40)
 *   is_active         tinyint(1)
 *   last_login_at     datetime
 *   created_at        datetime
 *   updated_at        datetime
 *   email_verified_at timestamp
 *   password          varchar(200)      bcrypt — Laravel standard pathway
 *   name              varchar(200)
 *
 * user_assignments columns (poe_2026.sql):
 *   id             bigint UNSIGNED   PK
 *   user_id        bigint UNSIGNED   FK → users.id
 *   country_code   varchar(10)
 *   province_code  varchar(30)       nullable
 *   pheoc_code     varchar(30)       nullable
 *   district_code  varchar(30)       nullable
 *   poe_code       varchar(40)       nullable
 *   is_primary     tinyint(1)
 *   is_active      tinyint(1)
 *   starts_at      datetime          nullable
 *   ends_at        datetime          nullable
 *   created_at     datetime
 *   updated_at     datetime
 *
 * NEVER returned to client: password, password_hash
 * All other users columns are returned as-is.
 */
final class UserLoginController extends Controller
{
    // ────────────────────────────────────────────────────────────────
    // POST /auth/login
    // ────────────────────────────────────────────────────────────────

    public function login(Request $request): JsonResponse
    {
        // ── STEP 1: VALIDATE INPUT ───────────────────────────────────
        //
        // Using Validator::make() — NOT $request->validate().
        //
        // $request->validate() throws ValidationException which Laravel
        // catches in its exception handler and may redirect or return HTML
        // depending on middleware (especially if the route is accidentally
        // in web.php instead of api.php, or if VerifyCsrfToken fires first).
        //
        // Validator::make() returns control to us so we always emit JSON.

        $v = Validator::make($request->all(), [
            'login'    => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        if ($v->fails()) {
            return $this->err(
                status: 422,
                message: 'Validation failed.',
                error: [
                    'validation_errors' => $v->errors()->toArray(),
                    'received_keys'     => array_keys($request->all()),
                    'required_fields'   => ['login' => 'string, max 190', 'password' => 'string'],
                    'hint'              => 'If received_keys is empty the request body did not arrive. '
                    . 'Ensure the route is in routes/api.php, not routes/web.php, '
                    . 'and that Content-Type: application/json is set.',
                ]
            );
        }

        $login    = trim((string) $v->validated()['login']);
        $password = (string) $v->validated()['password'];

        // ── STEP 2: FIND USER ────────────────────────────────────────
        //
        // Try username first (users.username VARCHAR(80) UNIQUE).
        // Fall back to email  (users.email    VARCHAR(190) UNIQUE).
        // Both columns use utf8mb4_0900_ai_ci — case-insensitive match.

        try {
            $user = DB::table('users')->where('username', $login)->first() ?? DB::table('users')->where('email', $login)->first();
        } catch (Throwable $e) {
            return $this->dbError($e, 'users lookup');
        }

        if (! $user) {
            return $this->err(
                status: 401,
                message: 'Invalid credentials.',
                error: [
                    'reason'          => 'No user record matched the provided login.',
                    'checked_columns' => ['users.username', 'users.email'],
                    'login_provided'  => $login,
                ]
            );
        }

        // ── STEP 3: VERIFY PASSWORD ──────────────────────────────────
        //
        // Two bcrypt columns exist on the users table:
        //   users.password      VARCHAR(200) — written by Laravel Auth scaffolding
        //   users.password_hash VARCHAR(255) — written by POE mobile-sync pathway
        //
        // Try users.password first. If NULL or no match, try users.password_hash.

        $passwordColsTried  = [];
        $passwordColMatched = null;
        $credentialsOk      = false;

        if (! empty($user->password)) {
            $passwordColsTried[] = 'password';
            if (Hash::check($password, $user->password)) {
                $credentialsOk      = true;
                $passwordColMatched = 'password';
            }
        }

        if (! $credentialsOk && ! empty($user->password_hash)) {
            $passwordColsTried[] = 'password_hash';
            if (Hash::check($password, $user->password_hash)) {
                $credentialsOk      = true;
                $passwordColMatched = 'password_hash';
            }
        }

        if (! $credentialsOk) {
            return $this->err(
                status: 401,
                message: 'Invalid credentials.',
                error: [
                    'reason'              => empty($passwordColsTried)
                        ? 'User has no password set in either bcrypt column.'
                        : 'Password did not match.',
                    'password_cols_tried' => $passwordColsTried,
                    'password_col_null'   => [
                        'password'      => empty($user->password),
                        'password_hash' => empty($user->password_hash),
                    ],
                    'hint'                => empty($passwordColsTried)
                        ? 'Set a password via: DB::table("users")->where("id",' . $user->id . ')->update(["password"=>Hash::make("newpassword")]);'
                        : 'Hash mismatch. The stored hash does not match the supplied plaintext.',
                ]
            );
        }

        // ── STEP 4: CHECK ACCOUNT IS ACTIVE ─────────────────────────

        if (! (bool) $user->is_active) {
            return $this->err(
                status: 403,
                message: 'Account is inactive.',
                error: [
                    'reason'  => 'users.is_active is 0 for this user.',
                    'user_id' => $user->id,
                    'hint'    => 'Run: DB::table("users")->where("id",' . $user->id . ')->update(["is_active"=>1]);',
                ]
            );
        }

        // ── STEP 5: STAMP last_login_at ──────────────────────────────

        try {
            DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'last_login_at' => now()->toDateTimeString(),
                    'updated_at'    => now()->toDateTimeString(),
                ]);

            // Re-read so the response contains the fresh timestamp
            $user = DB::table('users')->where('id', $user->id)->first();
        } catch (Throwable $e) {
            // Non-fatal — log it but continue. The user authenticated correctly;
            // failing to stamp the timestamp must not block login.
            Log::error('[UserLoginController] last_login_at update failed', [
                'user_id'   => $user->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
        }

        // ── STEP 6b: FETCH user_assignments ──────────────────────────
        //
        // Query ALL active rows for this user, ordered so that is_primary=1
        // rows sort first. The first row with is_primary=1 becomes the
        // "assignment" shortcut. All rows are returned as "all_assignments"
        // so the client can render multi-POE awareness if needed.
        //
        // Filter: is_active = 1 only — expired/revoked rows are excluded.
        // Column names match poe_2026.sql user_assignments exactly.

        $allAssignments    = [];
        $primaryAssignment = null;

        try {
            $rows = DB::table('user_assignments')
                ->where('user_id', $user->id)
                ->where('is_active', 1)
                ->orderByDesc('is_primary') // is_primary=1 rows first
                ->orderBy('id')             // stable secondary sort
                ->get();

            foreach ($rows as $row) {
                $mapped = [
                    'id'            => $row->id,
                    'user_id'       => $row->user_id,
                    'country_code'  => $row->country_code,
                    'province_code' => $row->province_code,
                    'pheoc_code'    => $row->pheoc_code,
                    'district_code' => $row->district_code,
                    'poe_code'      => $row->poe_code,
                    'is_primary'    => (bool) $row->is_primary,
                    'is_active'     => (bool) $row->is_active,
                    'starts_at'     => $row->starts_at,
                    'ends_at'       => $row->ends_at,
                    'created_at'    => $row->created_at,
                    'updated_at'    => $row->updated_at,
                ];

                $allAssignments[] = $mapped;

                // First is_primary=1 row wins — guaranteed by ORDER BY is_primary DESC
                if ($primaryAssignment === null && (bool) $row->is_primary) {
                    $primaryAssignment = $mapped;
                }
            }
        } catch (Throwable $e) {
            // Non-fatal — log it but do not block login.
            // The client will receive null assignment and handle gracefully.
            Log::error('[UserLoginController] user_assignments fetch failed', [
                'user_id'   => $user->id,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
        }

        // ── STEP 6c: BUILD RESPONSE ───────────────────────────────────
        //
        // Geographic shortcut fields (poe_code, district_code, province_code,
        // pheoc_code) are flattened to the root of data so App.vue can read
        // them directly at userData.poe_code etc. without traversing the
        // assignment object. They mirror the primary assignment values exactly.
        //
        // App.vue reads these at lines 1051–1054:
        //   userData.poe_code      ?? null
        //   userData.district_code ?? null
        //   userData.province_code ?? null
        //   userData.pheoc_code    ?? null

        Log::info('[UserLoginController] login ok', [
            'user_id'          => $user->id,
            'password_col'     => $passwordColMatched,
            'assignment_count' => count($allAssignments),
            'has_primary'      => $primaryAssignment !== null,
            'ip'               => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                // ── users row (every column, no passwords) ─────────────
                'id'                => $user->id,
                'role_key'          => $user->role_key,
                'country_code'      => $user->country_code,
                'full_name'         => $user->full_name,
                'username'          => $user->username,
                'email'             => $user->email,
                'phone'             => $user->phone,
                'is_active'         => (bool) $user->is_active,
                'last_login_at'     => $user->last_login_at,
                'created_at'        => $user->created_at,
                'updated_at'        => $user->updated_at,
                'email_verified_at' => $user->email_verified_at,
                'name'              => $user->name,
                // password (varchar 200)      : OMITTED
                // password_hash (varchar 255) : OMITTED

                // ── user_assignments — primary row (or null) ────────────
                // App.vue checks: if (userData.assignment === undefined || null)
                'assignment'        => $primaryAssignment,

                // ── user_assignments — all active rows ──────────────────
                // App.vue reads: userData.all_assignments?.length
                'all_assignments'   => $allAssignments,

                // ── Geographic shortcuts — flattened from primary row ───
                // App.vue reads these directly at userData.poe_code etc.
                'poe_code'          => $primaryAssignment['poe_code'] ?? null,
                'district_code'     => $primaryAssignment['district_code'] ?? null,
                'province_code'     => $primaryAssignment['province_code'] ?? null,
                'pheoc_code'        => $primaryAssignment['pheoc_code'] ?? null,
            ],
        ], 200);
    }

    // ────────────────────────────────────────────────────────────────
    // PRIVATE — ERROR RESPONSE BUILDER
    // ────────────────────────────────────────────────────────────────

    /**
     * Return a structured error JSON response.
     *
     * Shape:
     * {
     *   "success" : false,
     *   "message" : "...",
     *   "error"   : { ...raw detail... }
     * }
     */
    private function err(int $status, string $message, array $error): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $error,
        ], $status);
    }

    /**
     * Catch-all for DB or unexpected exceptions.
     * Returns the raw exception class, message, file, and line.
     * Stack trace is included outside production to speed up debugging.
     */
    private function dbError(Throwable $e, string $context): JsonResponse
    {
        Log::error('[UserLoginController] exception during ' . $context, [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'A server error occurred.',
            'error'   => [
                'context'   => $context,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => app()->environment('production')
                    ? '[redacted in production]'
                    : array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            ],
        ], 500);
    }
}

/*
 * ═══════════════════════════════════════════════════════════════════
 * ROUTE — routes/api.php   (MUST be api.php, NOT web.php)
 * ═══════════════════════════════════════════════════════════════════
 *
 * use App\Http\Controllers\UserLoginController;
 *
 * Route::post('/auth/login', [UserLoginController::class, 'login']);
 *
 * ═══════════════════════════════════════════════════════════════════
 * SUCCESSFUL RESPONSE SHAPE (200 OK) — after this fix
 * ═══════════════════════════════════════════════════════════════════
 *
 * {
 *   "success": true,
 *   "message": "Login successful.",
 *   "data": {
 *
 *     // ── users row ─────────────────────────────────────────────
 *     "id": 3,
 *     "role_key": "SCREENER",
 *     "country_code": "UG",
 *     "full_name": "AYEBARE TIMOTHY KAMUKAMA",
 *     "username": "admin",
 *     "email": "ati@gmail.com",
 *     "phone": "25678927376",
 *     "is_active": true,
 *     "last_login_at": "2026-03-24 14:40:20",
 *     "created_at": "2026-03-24 13:22:51",
 *     "updated_at": "2026-03-24 14:40:20",
 *     "email_verified_at": null,
 *     "name": "AYEBARE TIMOTHY KAMUKAMA",
 *
 *     // ── primary assignment ────────────────────────────────────
 *     "assignment": {
 *       "id": 3,
 *       "user_id": 3,
 *       "country_code": "UG",
 *       "province_code": "Kabale Provincial PHEOC",
 *       "pheoc_code": "Kabale Provincial PHEOC",
 *       "district_code": "Kisoro District",
 *       "poe_code": "Bunagana",
 *       "is_primary": true,
 *       "is_active": true,
 *       "starts_at": "2026-03-24 13:22:51",
 *       "ends_at": null,
 *       "created_at": "2026-03-24 13:22:51",
 *       "updated_at": "2026-03-24 13:22:51"
 *     },
 *
 *     // ── all active assignments ────────────────────────────────
 *     "all_assignments": [ { ...same shape as above... } ],
 *
 *     // ── geographic shortcuts (flattened from primary row) ─────
 *     "poe_code":      "Bunagana",
 *     "district_code": "Kisoro District",
 *     "province_code": "Kabale Provincial PHEOC",
 *     "pheoc_code":    "Kabale Provincial PHEOC"
 *   }
 * }
 */
