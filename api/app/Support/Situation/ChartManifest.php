<?php

declare(strict_types=1);

namespace App\Support\Situation;

/**
 * ChartManifest — deterministic chart inventory for the Situation Room
 * cockpit canvas. Given a scope descriptor, returns the ordered list of
 * charts the canvas should render and the layout span each one takes
 * within the four-column grid.
 *
 * The chart **content** (explainer wizard, glossary, deep-link target)
 * lives in CoachManifest. This class only owns the layout/order
 * decision per scope — i.e. the answer to "which charts does the user
 * see today, and how big is each one on the grid?"
 *
 * No DB access. No reflection. Pure value object — the same scope
 * always returns the same chart order. Determinism is the contract.
 */
final class ChartManifest
{
    /**
     * Returns an ordered list of chart slots. Each slot:
     *
     *   [
     *     'key'      => 'rings_717',              // matches coach_situation.charts.<key>
     *     'col_span' => 2,                         // 1..4 inside the four-column grid
     *     'row_span' => 1,                         // 1..2
     *     'priority' => 1,                         // 1 = topmost; used by walk-me-through tour
     *     'data_key' => 'rings',                   // payload key the view reads
     *   ]
     *
     * @param array<string,mixed> $scope PheocScope descriptor
     * @return array<int,array<string,mixed>>
     */
    public static function forScope(array $scope): array
    {
        $level = strtoupper((string) ($scope['scope_level'] ?? 'NATIONAL'));

        // Common to every scope (in priority order) — the cockpit's spine.
        $common = [
            ['key' => 'rings_717',             'col_span' => 2, 'row_span' => 1, 'priority' => 1, 'data_key' => 'rings'],
            ['key' => 'alert_pulse',           'col_span' => 2, 'row_span' => 1, 'priority' => 2, 'data_key' => 'kpis.alerts.spark'],
            ['key' => 'ack_compliance',        'col_span' => 1, 'row_span' => 1, 'priority' => 5, 'data_key' => 'kpis.compliance'],
            ['key' => 'followup_completeness', 'col_span' => 1, 'row_span' => 1, 'priority' => 6, 'data_key' => 'rings.respond'],
            ['key' => 'classification_donut',  'col_span' => 1, 'row_span' => 1, 'priority' => 4, 'data_key' => 'alerts_feed'],
            ['key' => 'dispatch_sanity',       'col_span' => 1, 'row_span' => 1, 'priority' => 7, 'data_key' => 'system_strip'],
            ['key' => 'tripwires',             'col_span' => 2, 'row_span' => 1, 'priority' => 8, 'data_key' => 'tripwires'],
            ['key' => 'copilot_brief',         'col_span' => 2, 'row_span' => 1, 'priority' => 9, 'data_key' => 'recommendations'],
            ['key' => 'alerts_feed',           'col_span' => 4, 'row_span' => 1, 'priority' => 10,'data_key' => 'alerts_feed'],
        ];

        // The "where is the trouble" slot is scope-aware:
        // - National / PHEOC: country / regional POE pin board.
        // - District: the district's POE pin board.
        // - POE: a peer-comparison bar instead of the map (the map of one
        //        port is not informative; the comparison against neighbours is).
        $trouble = match ($level) {
            'POE'      => ['key' => 'poe_map', 'col_span' => 2, 'row_span' => 1, 'priority' => 3, 'data_key' => 'poe_pins', 'mode' => 'peer_comparison'],
            default    => ['key' => 'poe_map', 'col_span' => 2, 'row_span' => 1, 'priority' => 3, 'data_key' => 'poe_pins', 'mode' => 'pin_board'],
        };

        // Observers see aggregates only — drop the live alert feed and
        // the copilot brief because both can surface case-level identity.
        if ($level === 'OBSERVER') {
            $common = array_values(array_filter($common, static fn ($s) => ! in_array($s['key'], ['alerts_feed', 'copilot_brief'], true)));
        }

        $slots = array_merge($common, [$trouble]);
        usort($slots, static fn ($a, $b) => ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99));
        return array_values($slots);
    }

    /**
     * Returns the chart keys in priority order — used by the master
     * "Walk me through this room" tour to decide the order of stops.
     *
     * @param array<string,mixed> $scope
     * @return array<int,string>
     */
    public static function tourOrder(array $scope): array
    {
        $slots = self::forScope($scope);
        return array_map(static fn ($s) => (string) $s['key'], $slots);
    }
}
