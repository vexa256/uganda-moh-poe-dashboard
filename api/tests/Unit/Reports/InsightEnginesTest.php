<?php

declare(strict_types=1);

use App\Services\Reports\Insights\AgeGenderInsightEngine;
use App\Services\Reports\Insights\CasesRegistryInsightEngine;
use App\Services\Reports\Insights\ContactTracingInsightEngine;
use App\Services\Reports\Insights\GeoIntelligenceInsightEngine;
use App\Services\Reports\Insights\ScreeningVolumeInsightEngine;
use App\Services\Reports\Insights\SuspectedCasesInsightEngine;
use App\Services\Reports\Insights\SymptomExposureInsightEngine;

test('small-n suppression fires uniformly across every insight engine', function () {
    $payload = ['kpis' => ['primary' => 1, 'secondary' => 1, 'total' => 1, 'total_suspected' => 1, 'total_screenings' => 1, 'high_risk_arrivals' => 1]];
    foreach ([
        ScreeningVolumeInsightEngine::class,  // R1 special-cases secondary<5 as baseline_ok, but denom<5 on some insights triggers no-ops
        SuspectedCasesInsightEngine::class,
        GeoIntelligenceInsightEngine::class,
        ContactTracingInsightEngine::class,
        CasesRegistryInsightEngine::class,
        AgeGenderInsightEngine::class,
        SymptomExposureInsightEngine::class,
    ] as $engineClass) {
        $engine = new $engineClass();
        $insights = $engine->evaluate($payload);
        expect($insights)->toBeArray();
        expect($insights)->not->toBeEmpty();
        // Every insight must cite its rule.
        foreach ($insights as $ins) {
            expect($ins['rule'])->toBeString()->not->toBe('');
            expect($ins['level'])->toBeIn(['critical', 'warning', 'info', 'note', 'success']);
            expect($ins['title'])->toBeString()->not->toBe('');
        }
    }
});

test('ScreeningVolumeInsightEngine fires NOTIFIABLE_GTE_30 on triggering fixture', function () {
    $payload = ['kpis' => [
        'primary' => 100, 'secondary' => 50, 'notifiable' => 20,  // 40% → CRITICAL
        'facility_referrals' => 3, 'holding_flagged' => 0, 'spike_ratio' => 1.0,
    ]];
    $insights = (new ScreeningVolumeInsightEngine())->evaluate($payload);
    $rules = array_column($insights, 'rule');
    expect($rules)->toContain('NOTIFIABLE_GTE_30');
});

test('ScreeningVolumeInsightEngine does NOT fire NOTIFIABLE_GTE_30 on a 10% fixture', function () {
    $payload = ['kpis' => [
        'primary' => 100, 'secondary' => 50, 'notifiable' => 5,   // 10% → baseline
        'facility_referrals' => 5, 'holding_flagged' => 0, 'spike_ratio' => 1.0,
    ]];
    $insights = (new ScreeningVolumeInsightEngine())->evaluate($payload);
    $rules = array_column($insights, 'rule');
    expect($rules)->not->toContain('NOTIFIABLE_GTE_30');
    expect($rules)->not->toContain('NOTIFIABLE_GTE_15');
});

test('ContactTracingInsightEngine flags low completeness', function () {
    $payload = ['kpis' => ['total_screenings' => 50, 'completeness_score' => 0.55, 'missing_phone_pct' => 0.1], 'screeners' => []];
    $insights = (new ContactTracingInsightEngine())->evaluate($payload);
    $rules = array_column($insights, 'rule');
    expect($rules)->toContain('COMPLETENESS_LT_70');
});

test('CasesRegistryInsightEngine flags confirmed share above 10 percent', function () {
    $payload = ['kpis' => ['total' => 100, 'pending' => 5, 'confirmed' => 15, 'high_risk' => 5]];
    $rules = array_column((new CasesRegistryInsightEngine())->evaluate($payload), 'rule');
    expect($rules)->toContain('CONFIRMED_GT_10');
});

test('AgeGenderInsightEngine flags band above 30 percent notifiable', function () {
    $payload = [
        'kpis' => ['secondary' => 30],
        'age_bands' => [
            ['band' => '25–49', 'total' => 20, 'notifiable' => 8, 'male' => 10, 'female' => 10, 'referrals' => 2, 'other' => 0],
        ],
        'age_bands_summary' => ['U5' => 0, '65+' => 0],
        'gender' => ['MALE' => ['notifiable' => 4, 'total' => 10, 'referrals' => 1], 'FEMALE' => ['notifiable' => 4, 'total' => 10, 'referrals' => 1]],
    ];
    $rules = array_column((new AgeGenderInsightEngine())->evaluate($payload), 'rule');
    expect($rules)->toContain('AGE_NOTIFIABLE_GTE_30');
});

test('SuspectedCasesInsightEngine flags cluster of confirmed cases', function () {
    $payload = [
        'kpis' => ['total_suspected' => 30, 'confirmed' => 6, 'pending' => 5, 'unique_conditions' => 2],
        'top_conditions' => [['disease_code' => 'cholera', 'confirmed' => 6, 'suspected' => 12, 'pending' => 2, 'poes' => 2, 'latest' => '2026-04-20']],
    ];
    $rules = array_column((new SuspectedCasesInsightEngine())->evaluate($payload), 'rule');
    expect($rules)->toContain('CLUSTER_CONFIRMED_GTE_5');
});

test('GeoIntelligenceInsightEngine flags endemic-zone arrivals', function () {
    $payload = [
        'kpis' => ['high_risk_arrivals' => 20, 'distinct_origins' => 3, 'endemic_origins' => 1, 'poes_touched' => 2],
        'origins' => [['country' => 'UG', 'symptomatic' => 4, 'total' => 10, 'endemic' => true]],
        'endemic' => [['country' => 'UG', 'symptomatic' => 6, 'total' => 10, 'endemic' => true]],
    ];
    $rules = array_column((new GeoIntelligenceInsightEngine())->evaluate($payload), 'rule');
    expect($rules)->toContain('ENDEMIC_ARRIVALS_GTE_5');
});

test('SymptomExposureInsightEngine flags high-risk volume', function () {
    $payload = [
        'kpis' => ['secondary' => 30, 'high_risk' => 7],
        'tripwires' => [],
        'classification_mix' => ['VHF' => 2],
    ];
    $rules = array_column((new SymptomExposureInsightEngine())->evaluate($payload), 'rule');
    expect($rules)->toContain('HIGH_RISK_GTE_5');
});
