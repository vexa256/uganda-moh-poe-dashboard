<?php

declare(strict_types=1);

namespace Tests\Unit\Workforce;

use App\Http\Controllers\Admin\Workforce\WorkforceController;
use Illuminate\Http\JsonResponse;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Contract tests for WorkforceController::resolveJurisdictionForRole().
 *
 * This private method is the heart of the wizard's atomic create — it maps
 * a role's scope_level + the operator's jurisdiction input into a fully-
 * specified user_assignments row spec, OR returns a 422 with a hint when
 * the input is incomplete.
 *
 * If any of these tests fail, the wizard can silently create users in the
 * wrong jurisdiction (or no jurisdiction), which breaks ScopeFilter on
 * every subsequent request from that user.
 *
 * Note: POE-based auto-derivation tests are NOT exercised here because they
 * touch the live ref_poes table; they are covered by the live smoke test
 * against con-dev2 after deploy.
 */
final class WizardJurisdictionResolverTest extends TestCase
{
    private ReflectionMethod $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ReflectionMethod(WorkforceController::class, 'resolveJurisdictionForRole');
        $this->resolver->setAccessible(true);
    }

    private function resolve(string $scopeLevel, array $jur): mixed
    {
        return $this->resolver->invoke(
            app(WorkforceController::class),
            $scopeLevel,
            $jur,
        );
    }

    /* ── NATIONAL: no jurisdiction needed ────────────────────────────── */

    #[Test]
    public function national_scope_returns_empty_jurisdiction(): void
    {
        $out = $this->resolve('NATIONAL', []);
        $this->assertIsArray($out);
        $this->assertNull($out['province_code']);
        $this->assertNull($out['district_code']);
        $this->assertNull($out['poe_code']);
        $this->assertNull($out['pheoc_code']);
    }

    #[Test]
    public function national_scope_ignores_provided_jurisdiction(): void
    {
        $out = $this->resolve('NATIONAL', [
            'province_code' => 'Kampala',
            'district_code' => 'Kampala District',
            'poe_code'      => 'UG-WAKISO-001',
        ]);
        // National admins are not restricted; the wizard must NOT pin them
        // to a province even if one was accidentally selected.
        $this->assertNull($out['province_code']);
        $this->assertNull($out['district_code']);
        $this->assertNull($out['poe_code']);
    }

    /* ── PHEOC: province required ────────────────────────────────────── */

    #[Test]
    public function pheoc_scope_requires_province(): void
    {
        $out = $this->resolve('PHEOC', []);
        $this->assertInstanceOf(JsonResponse::class, $out);
        $this->assertSame(422, $out->getStatusCode());
        $this->assertStringContainsString('province', $out->getData(true)['message']);
    }

    #[Test]
    public function pheoc_scope_mirrors_province_to_pheoc(): void
    {
        $out = $this->resolve('PHEOC', ['province_code' => 'Central Region']);
        $this->assertIsArray($out);
        $this->assertSame('Central Region', $out['province_code']);
        $this->assertSame('Central Region', $out['pheoc_code'], 'pheoc_code must mirror province_code — required by ScopeFilter');
        $this->assertNull($out['district_code']);
        $this->assertNull($out['poe_code']);
    }

    /* ── DISTRICT: district required ─────────────────────────────────── */

    #[Test]
    public function district_scope_requires_district(): void
    {
        $out = $this->resolve('DISTRICT', ['province_code' => 'Central Region']);
        $this->assertInstanceOf(JsonResponse::class, $out);
        $this->assertSame(422, $out->getStatusCode());
        $this->assertStringContainsString('district', $out->getData(true)['message']);
    }

    #[Test]
    public function district_scope_keeps_province_if_provided(): void
    {
        $out = $this->resolve('DISTRICT', [
            'province_code' => 'Central Region',
            'district_code' => 'Wakiso District',
        ]);
        $this->assertIsArray($out);
        $this->assertSame('Wakiso District',  $out['district_code']);
        $this->assertSame('Central Region',   $out['province_code']);
        $this->assertNull($out['poe_code']);
    }

    /* ── POE: POE required ───────────────────────────────────────────── */

    #[Test]
    public function poe_scope_requires_poe(): void
    {
        $out = $this->resolve('POE', ['district_code' => 'Wakiso District']);
        $this->assertInstanceOf(JsonResponse::class, $out);
        $this->assertSame(422, $out->getStatusCode());
        $this->assertStringContainsString('point of entry', $out->getData(true)['message']);
    }

    /* ── SELF: everything optional ───────────────────────────────────── */

    #[Test]
    public function self_scope_accepts_empty(): void
    {
        $out = $this->resolve('SELF', []);
        $this->assertIsArray($out);
        $this->assertNull($out['poe_code']);
        $this->assertNull($out['province_code']);
    }

    #[Test]
    public function self_scope_persists_optional_location(): void
    {
        $out = $this->resolve('SELF', ['province_code' => 'Northern Region']);
        $this->assertIsArray($out);
        $this->assertSame('Northern Region', $out['province_code']);
    }

    /* ── Field name aliases (province vs province_code) ──────────────── */

    #[Test]
    public function aliases_short_form_field_names(): void
    {
        // Front-end may send 'province' instead of 'province_code'.
        $out = $this->resolve('PHEOC', ['province' => 'Central Region']);
        $this->assertIsArray($out);
        $this->assertSame('Central Region', $out['province_code']);
    }
}
