<?php

declare(strict_types=1);

namespace Tests\Unit\Clinical;

use App\Http\Controllers\SecondaryScreeningController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use stdClass;

/**
 * Regression contract for the 2026-05-20 incident where cases #1 and #2 were
 * trapped at case_status=IN_PROGRESS forever because the server rejected
 * every DISPOSITIONED sync with HTTP 422
 * ("missing_fields: syndrome_classification, risk_level, final_disposition").
 *
 * Per directive: POEs only SUSPECT — they do not classify syndromes, assess
 * risk, or pick clinical dispositions. validateStatusTransitionForSync() and
 * updateStatus() must accept a DISPOSITIONED sync with NULL clinical fields.
 *
 * If this test fails, the gating has been re-introduced and live cases will
 * silently get stuck again. DO NOT relax the assertions — fix the controller.
 */
final class NoClinicalGatingOnDispositionTest extends TestCase
{
    private ReflectionMethod $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ReflectionMethod(
            SecondaryScreeningController::class,
            'validateStatusTransitionForSync'
        );
        $this->validator->setAccessible(true);
    }

    private function fakeCase(string $currentStatus = 'IN_PROGRESS'): stdClass
    {
        $row = new stdClass();
        $row->id                      = 1;
        $row->case_status             = $currentStatus;
        $row->syndrome_classification = null;
        $row->risk_level              = null;
        $row->final_disposition       = null;
        $row->disposition_details     = null;
        $row->officer_notes           = null;
        return $row;
    }

    private function invoke(stdClass $case, string $requested, array $body = []): mixed
    {
        $request = Request::create('/dummy', 'POST', $body);

        return $this->validator->invoke(
            app(SecondaryScreeningController::class),
            $case,
            $requested,
            $request,
        );
    }

    /* ── IN_PROGRESS → DISPOSITIONED with NULL clinical fields is now allowed ── */

    #[Test]
    public function dispositioned_sync_passes_with_null_clinical_fields(): void
    {
        $result = $this->invoke($this->fakeCase('IN_PROGRESS'), 'DISPOSITIONED', [
            'syndrome_classification' => null,
            'risk_level'              => null,
            'final_disposition'       => null,
        ]);

        $this->assertNull(
            $result,
            'validateStatusTransitionForSync() must return null (no error) for a '
            . 'DISPOSITIONED transition with NULL clinical fields. Per directive '
            . '2026-05-20, POEs only suspect; clinical gating has been removed.'
        );
    }

    #[Test]
    public function dispositioned_sync_passes_when_clinical_fields_absent_from_payload(): void
    {
        $result = $this->invoke($this->fakeCase('IN_PROGRESS'), 'DISPOSITIONED', [
            // No syndrome_classification, risk_level, or final_disposition.
        ]);

        $this->assertNull($result);
    }

    #[Test]
    public function high_risk_actions_are_not_required(): void
    {
        $case = $this->fakeCase('IN_PROGRESS');
        $case->risk_level = 'CRITICAL';

        $result = $this->invoke($case, 'DISPOSITIONED', [
            'risk_level' => 'CRITICAL',
            'actions'    => [], // no ISOLATED/REFERRED_HOSPITAL
        ]);

        $this->assertNull(
            $result,
            'HIGH/CRITICAL action requirements were removed — POEs route, '
            . 'they do not gate on clinical actions.'
        );
    }

    /* ── State-machine transitions still enforced ── */

    #[Test]
    public function invalid_state_transition_still_rejected(): void
    {
        $result = $this->invoke($this->fakeCase('OPEN'), 'CLOSED');

        $this->assertInstanceOf(
            JsonResponse::class,
            $result,
            'State machine must still reject OPEN→CLOSED (only via IN_PROGRESS).'
        );
        $this->assertSame(409, $result->getStatusCode());
    }

    #[Test]
    public function in_progress_to_closed_no_longer_requires_officer_notes(): void
    {
        $result = $this->invoke($this->fakeCase('IN_PROGRESS'), 'CLOSED', [
            // no officer_notes
        ]);

        $this->assertNull(
            $result,
            'Per directive 2026-05-20, officer_notes is OPTIONAL — the 3 '
            . 'suspected diseases are the clinical record, not the narrative.'
        );
    }
}
