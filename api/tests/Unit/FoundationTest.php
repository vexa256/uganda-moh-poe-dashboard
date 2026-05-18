<?php

namespace Tests\Unit;

use App\Support\ChartPayloadBuilder;
use App\Support\DiseaseResolver;
use App\Support\EnumTranslator;
use App\Support\KpiBuilder;
use App\Support\TimelineBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Foundation unit tests — cover the pure helpers built in STEP A7.
 *
 * These are zero-dependency tests (no DB, no app bootstrap for the
 * pure-php helpers). They validate the contract every admin view will
 * rely on.
 */
final class FoundationTest extends TestCase
{
    /* ── EnumTranslator ───────────────────────────────────────────── */

    #[Test]
    public function enum_translator_resolves_risk_levels_to_plain_english(): void
    {
        $t = new EnumTranslator();
        $this->assertSame('Critical', $t->riskLevel('CRITICAL'));
        $this->assertSame('High',     $t->riskLevel('high'));
        $this->assertSame('Medium',   $t->riskLevel('MEDIUM'));
        $this->assertSame('Low',      $t->riskLevel('LOW'));
        $this->assertSame('Unknown',  $t->riskLevel(null));
    }

    #[Test]
    public function enum_translator_maps_risk_levels_to_semantic_tones(): void
    {
        $t = new EnumTranslator();
        $this->assertSame('critical', $t->riskTone('CRITICAL'));
        $this->assertSame('high',     $t->riskTone('HIGH'));
        $this->assertSame('medium',   $t->riskTone('MEDIUM'));
        $this->assertSame('low',      $t->riskTone('LOW'));
        $this->assertSame('default',  $t->riskTone(null));
    }

    #[Test]
    public function enum_translator_never_leaks_raw_close_categories(): void
    {
        $t = new EnumTranslator();
        $this->assertSame('False positive',            $t->closeCategory('FALSE_POSITIVE'));
        $this->assertSame('Lost to follow-up',         $t->closeCategory('LOST_TO_FOLLOWUP'));
        $this->assertSame('Transferred out of country',$t->closeCategory('TRANSFERRED_OUT_OF_COUNTRY'));
        $this->assertSame('Deceased',                  $t->closeCategory('DECEASED'));
        $this->assertSame('Duplicate (merged into another case)', $t->closeCategory('DUPLICATE'));
    }

    #[Test]
    public function enum_translator_flags_the_six_rtsl_actions_that_block_closure(): void
    {
        $t = new EnumTranslator();
        $blocking = ['CASE_INVESTIGATION', 'ISOLATION', 'CONTACT_LISTING', 'CONTACT_TRACING', 'EOC_ACTIVATION', 'WHO_NOTIFICATION'];
        foreach ($blocking as $code) {
            $this->assertTrue($t->blocksClosure($code), "Expected {$code} to block closure");
        }
        // Non-blocking actions
        foreach (['COORDINATION_MEETING', 'SAMPLE_COLLECTION', 'RISK_COMMS', 'VACCINATION'] as $code) {
            $this->assertFalse($t->blocksClosure($code), "Expected {$code} NOT to block closure");
        }
    }

    #[Test]
    public function enum_translator_generic_humanize_fallback_works(): void
    {
        $t = new EnumTranslator();
        $this->assertSame('Some Unknown Code', $t->humanize('SOME_UNKNOWN_CODE'));
        $this->assertSame('—',                 $t->humanize(null));
        $this->assertSame('—',                 $t->humanize(''));
    }

    #[Test]
    public function enum_translator_label_dispatcher_routes_by_kind(): void
    {
        $t = new EnumTranslator();
        $this->assertSame('Critical',          $t->label('risk', 'CRITICAL'));
        $this->assertSame('Acknowledged',      $t->label('alert_status', 'ACKNOWLEDGED'));
        $this->assertSame('National administrator', $t->label('role', 'NATIONAL_ADMIN'));
        $this->assertSame('Critical alert',    $t->label('notification_template', 'ALERT_CRITICAL'));
    }

    /* ── KpiBuilder ───────────────────────────────────────────────── */

    #[Test]
    public function kpi_builder_computes_delta_percentage(): void
    {
        $k = (new KpiBuilder())->build([
            'label'    => 'Caseload',
            'value'    => 128,
            'baseline' => 114,
            'spark'    => [6,9,7,12,10],
            'caption'  => 'vs baseline',
            'tone'     => 'brand',
        ]);

        $this->assertSame('Caseload', $k['label']);
        $this->assertSame('128',      $k['display']);
        $this->assertSame('up',       $k['direction']);
        $this->assertEqualsWithDelta(12.28, $k['delta_pct'], 0.01);
        $this->assertSame('brand',    $k['tone']);
        $this->assertSame([6.0, 9.0, 7.0, 12.0, 10.0], $k['spark']);
    }

    #[Test]
    public function kpi_builder_handles_flat_delta(): void
    {
        $k = (new KpiBuilder())->build(['label' => 'x', 'value' => 50, 'baseline' => 50]);
        $this->assertSame('flat', $k['direction']);
    }

    #[Test]
    public function kpi_builder_handles_null_value(): void
    {
        $k = (new KpiBuilder())->build(['label' => 'x', 'value' => null]);
        $this->assertSame('—', $k['display']);
    }

    #[Test]
    public function kpi_builder_formats_percent_value(): void
    {
        $k = (new KpiBuilder())->build(['label' => '7-1-7', 'value' => 98.0, 'format' => 'percent']);
        $this->assertSame('98%', $k['display']);
    }

    #[Test]
    public function kpi_builder_respects_good_direction_for_tone(): void
    {
        // When 'good'='down' (lower is better), a downward delta is green.
        $k = (new KpiBuilder())->build(['label' => 'p50', 'value' => 40, 'baseline' => 50, 'good' => 'down']);
        $this->assertSame('down', $k['direction']);
        $this->assertSame('up',   $k['delta_tone']); // inverted: down direction + good=down = "up" (positive) tone
    }

    /* ── ChartPayloadBuilder ─────────────────────────────────────── */

    #[Test]
    public function chart_payload_builder_emits_valid_line_config(): void
    {
        $p = (new ChartPayloadBuilder())->line(
            ['Primary' => [10, 20, 30], 'Secondary' => [5, 10, 15]],
            ['Mon', 'Tue', 'Wed'],
        );
        $this->assertSame('line', $p['type']);
        $this->assertCount(2, $p['data']['datasets']);
        $this->assertSame(['Mon', 'Tue', 'Wed'], $p['data']['labels']);
    }

    #[Test]
    public function chart_payload_builder_emits_valid_bar_config(): void
    {
        $p = (new ChartPayloadBuilder())->bar(['Critical' => [1,2,3]], ['Jan', 'Feb', 'Mar']);
        $this->assertSame('bar', $p['type']);
        $this->assertSame('Critical', $p['data']['datasets'][0]['label']);
    }

    #[Test]
    public function chart_payload_builder_emits_valid_doughnut_config(): void
    {
        $p = (new ChartPayloadBuilder())->doughnut(['Open' => 4, 'Closed' => 96]);
        $this->assertSame('doughnut', $p['type']);
        $this->assertSame([4, 96], $p['data']['datasets'][0]['data']);
    }

    /* ── DiseaseResolver (pure helper — no DB required) ─────────── */

    #[Test]
    public function disease_resolver_handles_unknown_code_gracefully(): void
    {
        $r = new DiseaseResolver(new EnumTranslator());
        $view = $r->resolve('NOT_A_REAL_DISEASE');

        $this->assertFalse($view['known']);
        $this->assertNotEmpty($view['tier_label']);
        $this->assertIsArray($view['immediate_actions']);
    }

    #[Test]
    public function disease_resolver_exposes_known_diseases_from_intel(): void
    {
        $r = new DiseaseResolver(new EnumTranslator());
        // Pick any code from DiseaseIntel::REGISTRY (they're lowercased)
        $codes = array_keys(\App\Support\DiseaseIntel::REGISTRY);
        $this->assertNotEmpty($codes, 'DiseaseIntel registry should be non-empty');

        $view = $r->resolve(strtoupper($codes[0]));
        $this->assertTrue($view['known']);
        $this->assertNotEmpty($view['name']);
        $this->assertNotEmpty($view['tier_label']);
    }

    /* ── TimelineBuilder ──────────────────────────────────────────── */

    #[Test]
    public function timeline_builder_orders_events_newest_first(): void
    {
        $b = new TimelineBuilder(new EnumTranslator());
        $events = [
            ['id' => 1, 'event_code' => 'ALERT_CREATED', 'created_at' => '2026-04-20 09:00:00'],
            ['id' => 2, 'event_code' => 'ACKNOWLEDGED', 'created_at' => '2026-04-20 09:30:00'],
            ['id' => 3, 'event_code' => 'ESCALATED',    'created_at' => '2026-04-20 10:00:00'],
        ];
        $out = $b->build($events);
        $this->assertCount(3, $out);
        $this->assertSame(3, $out[0]['id']); // newest first
        $this->assertSame('Alert escalated', $out[0]['event_label']);
    }

    #[Test]
    public function timeline_builder_summarise_handles_empty_list(): void
    {
        $b = new TimelineBuilder(new EnumTranslator());
        $this->assertSame('No activity recorded on this alert yet.', $b->summarise([]));
    }
}
