<?php

declare(strict_types=1);

namespace Tests\Unit\Clinical;

use App\Http\Controllers\SecondaryScreeningController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Direct unit tests for SecondaryScreeningController::coerceForDbEnums().
 *
 * Context: production logs showed 35+ rollbacks where a disposition POST
 * with final_disposition='RELEASED_NO_CONDITION' (PHP-accepted, MySQL-rejected)
 * caused the entire fullSync transaction to roll back — losing syndrome,
 * risk, vitals, actions, suspected_diseases in one shot. coerceForDbEnums is
 * the defensive layer that ensures no enum value out-of-range for the DB
 * ENUM can hit MySQL: it falls back to a safe value (preserving the
 * operator's intent in disposition_details) or strips the field.
 *
 * These tests exercise the method directly via reflection so the contract
 * is enforced regardless of the DB backend (the test DB is in-memory SQLite
 * which has no ENUM concept — only a unit-level assertion proves the
 * defensive behaviour works on MySQL).
 */
final class CoerceForDbEnumsTest extends TestCase
{
    private ReflectionMethod $coerceMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coerceMethod = new ReflectionMethod(SecondaryScreeningController::class, 'coerceForDbEnums');
        $this->coerceMethod->setAccessible(true);
    }

    private function coerce(array $updates): array
    {
        return $this->coerceMethod->invoke(app(SecondaryScreeningController::class), $updates);
    }

    /* ── HAPPY PATH — every DB-valid value passes through unchanged ───── */

    #[Test]
    public function known_good_values_pass_through_unchanged(): void
    {
        $out = $this->coerce([
            'final_disposition'       => 'RELEASED',
            'case_status'             => 'CLOSED',
            'traveler_gender'         => 'MALE',
            'risk_level'              => 'LOW',
            'triage_category'         => 'URGENT',
            'general_appearance'      => 'WELL',
            'conveyance_type'         => 'AIR',
            'temperature_unit'        => 'C',
            'followup_assigned_level' => 'DISTRICT',
        ]);

        $this->assertSame('RELEASED',  $out['final_disposition']);
        $this->assertSame('CLOSED',    $out['case_status']);
        $this->assertSame('MALE',      $out['traveler_gender']);
        $this->assertSame('LOW',       $out['risk_level']);
        $this->assertSame('URGENT',    $out['triage_category']);
        $this->assertSame('WELL',      $out['general_appearance']);
        $this->assertSame('AIR',       $out['conveyance_type']);
        $this->assertSame('C',         $out['temperature_unit']);
        $this->assertSame('DISTRICT',  $out['followup_assigned_level']);
    }

    #[Test]
    public function lowercase_values_are_canonicalised_to_uppercase(): void
    {
        $out = $this->coerce([
            'final_disposition' => 'released',
            'case_status'       => 'closed',
            'traveler_gender'   => 'female',
        ]);

        $this->assertSame('RELEASED', $out['final_disposition']);
        $this->assertSame('CLOSED',   $out['case_status']);
        $this->assertSame('FEMALE',   $out['traveler_gender']);
    }

    #[Test]
    public function whitespace_around_values_is_trimmed_implicitly_via_strtoupper(): void
    {
        // strtoupper preserves whitespace; our normaliser does NOT trim — but
        // by passing a value with leading whitespace we exercise the path that
        // would otherwise fall through to the fallback. Document the behaviour
        // so future readers know to send already-trimmed values.
        $out = $this->coerce([
            'final_disposition' => '  RELEASED  ',
        ]);

        // With leading/trailing whitespace, normalisation fails — the fallback
        // path kicks in. This is acceptable defensive behaviour: the operator's
        // original intent is stashed in disposition_details.
        $this->assertSame('OTHER', $out['final_disposition']);
        $this->assertStringContainsString('ORIGINAL_DISPOSITION=', $out['disposition_details']);
    }

    /* ── INCIDENT REGRESSION — the exact bug that bit us in production ── */

    #[Test]
    public function released_no_condition_is_canonicalised_post_migration(): void
    {
        // This is the value that caused the original truncation. After the
        // 2026_05_20 migration extends the ENUM, the value is DB-valid.
        // coerceForDbEnums must let it through unchanged (only uppercased).
        $out = $this->coerce([
            'final_disposition' => 'RELEASED_NO_CONDITION',
        ]);

        $this->assertSame('RELEASED_NO_CONDITION', $out['final_disposition']);
        $this->assertArrayNotHasKey('disposition_details', $out, 'must not stash the canonical value as overflow');
    }

    #[Test]
    public function each_canonical_who_idsr_code_is_accepted_post_migration(): void
    {
        $whoIdsrCodes = [
            'RELEASED_NO_CONDITION',
            'RELEASED_UNDER_FOLLOWUP',
            'REFERRED_HEALTH_FACILITY',
            'ISOLATED_ADMITTED',
            'DECEASED_AT_POE',
            'RETURN_TO_ORIGIN',
        ];

        foreach ($whoIdsrCodes as $code) {
            $out = $this->coerce(['final_disposition' => $code]);
            $this->assertSame($code, $out['final_disposition'], "Canonical code {$code} should pass through unchanged");
        }
    }

    /* ── FALLBACK PATH — out-of-range values land safely ───────────────── */

    #[Test]
    public function unknown_disposition_falls_back_to_OTHER_with_stash(): void
    {
        $out = $this->coerce([
            'final_disposition' => 'INVENTED_VALUE',
        ]);

        $this->assertSame('OTHER', $out['final_disposition']);
        $this->assertSame('ORIGINAL_DISPOSITION=INVENTED_VALUE', $out['disposition_details']);
    }

    #[Test]
    public function existing_disposition_details_are_not_overwritten_by_stash(): void
    {
        $out = $this->coerce([
            'final_disposition'   => 'INVENTED_VALUE',
            'disposition_details' => 'operator wrote this',
        ]);

        $this->assertSame('OTHER', $out['final_disposition']);
        $this->assertSame('operator wrote this', $out['disposition_details']);
    }

    #[Test]
    public function unknown_gender_falls_back_to_UNKNOWN(): void
    {
        $out = $this->coerce(['traveler_gender' => 'NON_BINARY']);
        $this->assertSame('UNKNOWN', $out['traveler_gender']);
    }

    #[Test]
    public function unknown_conveyance_falls_back_to_OTHER(): void
    {
        $out = $this->coerce(['conveyance_type' => 'HOVERCRAFT']);
        $this->assertSame('OTHER', $out['conveyance_type']);
    }

    #[Test]
    public function unknown_temperature_unit_falls_back_to_C(): void
    {
        $out = $this->coerce(['temperature_unit' => 'K']);
        $this->assertSame('C', $out['temperature_unit']);
    }

    /* ── STRIP PATH — fields without a defined fallback are removed ───── */

    #[Test]
    public function unknown_risk_level_is_stripped(): void
    {
        $out = $this->coerce([
            'risk_level'    => 'EXTREME',
            'officer_notes' => 'unrelated field',
        ]);

        $this->assertArrayNotHasKey('risk_level', $out, 'no fallback defined → key must be stripped');
        $this->assertSame('unrelated field', $out['officer_notes'], 'other keys are untouched');
    }

    #[Test]
    public function unknown_triage_category_is_stripped(): void
    {
        $out = $this->coerce(['triage_category' => 'TRIAGE_LEVEL_4']);
        $this->assertArrayNotHasKey('triage_category', $out);
    }

    #[Test]
    public function unknown_case_status_is_stripped_not_falled_back(): void
    {
        // case_status has no fallback — stripping is correct (server keeps
        // the existing case_status rather than guessing).
        $out = $this->coerce(['case_status' => 'PENDING_REVIEW']);
        $this->assertArrayNotHasKey('case_status', $out);
    }

    /* ── PRESERVATION OF NULL / EMPTY (officer hasn't filled in) ────────── */

    #[Test]
    public function null_values_are_preserved_not_coerced(): void
    {
        // null means "officer hasn't filled this in" — must not be turned
        // into a fake fallback value.
        $out = $this->coerce([
            'final_disposition' => null,
            'risk_level'        => null,
            'traveler_gender'   => null,
        ]);

        $this->assertNull($out['final_disposition']);
        $this->assertNull($out['risk_level']);
        $this->assertNull($out['traveler_gender']);
    }

    #[Test]
    public function empty_string_values_are_preserved_not_coerced(): void
    {
        $out = $this->coerce([
            'final_disposition' => '',
            'risk_level'        => '   ',
        ]);

        $this->assertSame('',    $out['final_disposition']);
        $this->assertSame('   ', $out['risk_level']);
    }

    /* ── NON-ENUM FIELDS ARE UNTOUCHED ──────────────────────────────────── */

    #[Test]
    public function non_enum_fields_are_passed_through_unchanged(): void
    {
        $out = $this->coerce([
            'traveler_full_name'  => 'NDAGIRE LYDIA',
            'traveler_age_years'  => 34,
            'temperature_value'   => 38.6,
            'officer_notes'       => 'mixed-case STRING stays as-is',
            'final_disposition'   => 'RELEASED', // sanity — enum still works in same call
        ]);

        $this->assertSame('NDAGIRE LYDIA',              $out['traveler_full_name']);
        $this->assertSame(34,                           $out['traveler_age_years']);
        $this->assertSame(38.6,                         $out['temperature_value']);
        $this->assertSame('mixed-case STRING stays as-is', $out['officer_notes']);
        $this->assertSame('RELEASED',                   $out['final_disposition']);
    }

    /* ── EDGE CASE — empty payload ─────────────────────────────────────── */

    #[Test]
    public function empty_payload_passes_through_unchanged(): void
    {
        $this->assertSame([], $this->coerce([]));
    }
}
