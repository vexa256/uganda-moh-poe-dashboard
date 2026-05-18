<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Support\DiseaseIntel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single translation layer between database codes and what the user sees.
 *
 * Every JSON endpoint that ships data to a Blade view must pipe through one
 * of these methods. Views render only the `human` block; raw codes stay
 * server-side as the `code` field for client-side state, never for display.
 *
 * The contract is enforced by tests/Feature/Admin/Alerts/NoRawEnumsLeakTest.
 */
final class HumanLabels
{
    /**
     * Disease code → group bucket. Used by diseaseHeadline() and the master
     * list grouper in AlertsController. The buckets match
     * resources/lang/en/alerts.php → disease_group.
     *
     * v1 — clinician sign-off pending. Update when the canonical priority
     * table is approved.
     */
    private const DISEASE_GROUP = [
        'ebola_virus_disease'              => 'vhf',
        'marburg_virus_disease'            => 'vhf',
        'lassa_fever'                      => 'vhf',
        'cchf'                             => 'vhf',
        'rift_valley_fever'                => 'vhf',
        'hantavirus'                       => 'vhf',
        'nipah_virus'                      => 'vhf',

        'cholera'                          => 'cholera_diarrhoeal',
        'awd_non_cholera'                  => 'cholera_diarrhoeal',
        'shigellosis_dysentery'            => 'cholera_diarrhoeal',

        'sars'                             => 'novel_respiratory',
        'mers'                             => 'novel_respiratory',
        'influenza_new_subtype_zoonotic'   => 'novel_respiratory',
        'smallpox'                         => 'novel_respiratory',

        'measles'                          => 'measles_family',
        'rubella'                          => 'measles_family',
        'polio'                            => 'measles_family',
        'mpox'                             => 'measles_family',

        'yellow_fever'                     => 'vector_borne',
        'dengue'                           => 'vector_borne',
        'dengue_severe'                    => 'vector_borne',
        'malaria_uncomplicated'            => 'vector_borne',
        'malaria_severe'                   => 'vector_borne',
        'japanese_encephalitis'            => 'vector_borne',
        'west_nile_fever'                  => 'vector_borne',
        'chikungunya'                      => 'vector_borne',
        'zika'                             => 'vector_borne',
        'rickettsia_scrub_typhus'          => 'vector_borne',

        'meningococcal_meningitis'         => 'meningitis',

        'bubonic_plague'                   => 'plague',
        'pneumonic_plague'                 => 'plague',

        'rabies'                           => 'zoonotic',
        'anthrax_cutaneous'                => 'zoonotic',
        'anthrax_pulmonary'                => 'zoonotic',
        'brucellosis'                      => 'zoonotic',
        'tularemia'                        => 'zoonotic',
        'leptospirosis'                    => 'zoonotic',

        'typhoid_fever'                    => 'foodborne',
        'hepatitis_a'                      => 'foodborne',
        'hepatitis_e'                      => 'foodborne',

        'influenza_seasonal'               => 'seasonal_flu',
    ];

    /**
     * Lookup cache for ref_diseases.display_name keyed by disease_code.
     * Avoids hitting the DB once per row in big lists.
     *
     * @var array<string,array{display_name:string,ihr_tier:int}>|null
     */
    private static ?array $refDiseases = null;

    public static function action(string $code): array
    {
        $key = "alerts.action.{$code}";
        if (trans()->has($key)) {
            $entry = (array) trans($key);
            return [
                'code'  => $code,
                'title' => (string) ($entry['title'] ?? self::prettify($code)),
                'short' => (string) ($entry['short'] ?? ''),
                'why'   => (string) ($entry['why']   ?? ''),
                'icon'  => (string) ($entry['icon']  ?? 'circle'),
            ];
        }

        return [
            'code'  => $code,
            'title' => self::prettify($code),
            'short' => '',
            'why'   => '',
            'icon'  => 'circle',
        ];
    }

    public static function status(string $code): array
    {
        $key = "alerts.status.{$code}";
        if (trans()->has($key)) {
            $entry = (array) trans($key);
            return [
                'code'  => $code,
                'label' => (string) ($entry['label'] ?? self::prettify($code)),
                'tone'  => (string) ($entry['tone']  ?? 'info'),
            ];
        }

        return ['code' => $code, 'label' => self::prettify($code), 'tone' => 'info'];
    }

    public static function alertStatus(string $code): array
    {
        $key = "alerts.alert_status.{$code}";
        if (trans()->has($key)) {
            $entry = (array) trans($key);
            return [
                'code'  => $code,
                'label' => (string) ($entry['label'] ?? self::prettify($code)),
                'tone'  => (string) ($entry['tone']  ?? 'info'),
            ];
        }

        return ['code' => $code, 'label' => self::prettify($code), 'tone' => 'info'];
    }

    public static function risk(string $code): array
    {
        $key = "alerts.risk_level.{$code}";
        if (trans()->has($key)) {
            $entry = (array) trans($key);
            return [
                'code'  => $code,
                'label' => (string) ($entry['label'] ?? self::prettify($code)),
                'short' => (string) ($entry['short'] ?? self::prettify($code)),
                'tone'  => (string) ($entry['tone']  ?? 'info'),
            ];
        }

        return ['code' => $code, 'label' => self::prettify($code), 'short' => self::prettify($code), 'tone' => 'info'];
    }

    /**
     * Reconciles every flavour of tier we have:
     *   - numeric ref_diseases.ihr_tier (1, 2, 3)
     *   - DiseaseIntel string enum (TIER_1_ALWAYS_NOTIFIABLE, ANNEX2_EPIDEMIC_PRONE,
     *     WHO_NOTIFIABLE, SYNDROMIC)
     *   - alert.ihr_tier free text (TIER_1, TIER_2, anything else)
     */
    public static function tier(int|string|null $tier): array
    {
        $bucket = self::tierBucket($tier);
        $entry  = (array) trans("alerts.tier.{$bucket}");

        return [
            'bucket' => $bucket,
            'label'  => (string) ($entry['label'] ?? 'Routine reporting'),
            'short'  => (string) ($entry['short'] ?? 'Routine'),
            'dot'    => (string) ($entry['dot']   ?? 'grey'),
        ];
    }

    public static function routedTo(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'Not routed yet';
        }

        $key = "alerts.routed_to.{$code}";
        return trans()->has($key) ? (string) trans($key) : self::prettify($code);
    }

    public static function closeCategory(string $code): array
    {
        $key = "alerts.close_category.{$code}";
        if (trans()->has($key)) {
            $entry = (array) trans($key);
            return [
                'code'  => $code,
                'label' => (string) ($entry['label'] ?? self::prettify($code)),
                'help'  => (string) ($entry['help']  ?? ''),
            ];
        }

        return ['code' => $code, 'label' => self::prettify($code), 'help' => ''];
    }

    /**
     * Builds the "Suspected Ebola — viral haemorrhagic fever" headline for a
     * disease code. Falls back gracefully for unknown codes.
     *
     * @return array{
     *   code:string, name:string, group:string, group_label:string,
     *   headline:string, tier:array{bucket:string,label:string,short:string,dot:string}
     * }
     */
    public static function disease(?string $code): array
    {
        if ($code === null || $code === '') {
            $tier = self::tier(null);
            return [
                'code'        => '',
                'name'        => 'Unidentified illness',
                'group'       => 'syndromic_unknown',
                'group_label' => (string) trans('alerts.disease_group.syndromic_unknown'),
                'headline'    => 'Unidentified illness — under review',
                'tier'        => $tier,
            ];
        }

        $intel    = DiseaseIntel::get($code);
        $name     = self::loadRefDiseaseName($code) ?? (string) ($intel['name'] ?? self::prettify($code));
        $group    = self::DISEASE_GROUP[$code] ?? 'syndromic_unknown';
        $tier     = self::tier($intel['ihr_tier'] ?? self::loadRefDiseaseTier($code));
        $groupLbl = (string) trans("alerts.disease_group.{$group}");

        return [
            'code'        => $code,
            'name'        => $name,
            'group'       => $group,
            'group_label' => $groupLbl,
            'headline'    => "Suspected {$name}",
            'tier'        => $tier,
        ];
    }

    /**
     * Translates a raw timestamp into "overdue by 4 hours", "in 2 days",
     * "just now", or "no deadline set".
     */
    public static function dueHuman(?string $dueAt, ?Carbon $now = null): string
    {
        if (!$dueAt) {
            return (string) trans('alerts.due.no_due');
        }

        $now = $now ?: Carbon::now();
        try {
            $due = Carbon::parse($dueAt);
        } catch (\Throwable) {
            return (string) trans('alerts.due.no_due');
        }

        $diffSeconds = (int) $due->diffInSeconds($now, false);

        if (abs($diffSeconds) < 60) {
            return (string) trans('alerts.due.just_now');
        }

        $amount = self::humanDuration((int) abs($diffSeconds));

        if ($due->lessThan($now)) {
            return (string) trans('alerts.due.overdue_amount', ['amount' => $amount]);
        }

        return (string) trans('alerts.due.in_amount', ['amount' => $amount]);
    }

    /**
     * Translates a 0–100 confidence percent into a single ranked word.
     */
    public static function confidence(?float $pct): string
    {
        if ($pct === null) {
            return (string) trans('alerts.confidence.possible');
        }
        if ($pct >= 60.0) return (string) trans('alerts.confidence.most_likely');
        if ($pct >= 25.0) return (string) trans('alerts.confidence.possible');
        return (string) trans('alerts.confidence.less_likely');
    }

    /**
     * Last-resort prettifier for any code that escapes the registry.
     * Splits on _ and titlecases. Never used on a user-facing critical path
     * if the lang file is well maintained.
     */
    public static function prettify(string $code): string
    {
        $code = strtolower(str_replace(['-', '_'], ' ', $code));
        return Str::ucfirst($code);
    }

    /**
     * Wraps a raw alert_followups row with a `human` block. The `code`
     * field is preserved so the front-end can keep referring to it for
     * state, but the views render only the `human.*` keys.
     *
     * @param array<string,mixed>|object $row
     * @return array<string,mixed>
     */
    public static function wrapFollowup(array|object $row, ?Carbon $now = null): array
    {
        $r = is_object($row) ? (array) $row : $row;

        $action = self::action((string) ($r['action_code'] ?? ''));
        $status = self::status((string) ($r['status']      ?? 'PENDING'));

        $r['human'] = [
            'title'        => $action['title'],
            'short'        => $action['short'],
            'why'          => $action['why'],
            'icon'         => $action['icon'],
            'status_label' => $status['label'],
            'status_tone'  => $status['tone'],
            'due_human'    => self::dueHuman($r['due_at'] ?? null, $now),
            'blocks_close' => (bool) ($r['blocks_closure'] ?? false),
        ];

        return $r;
    }

    /**
     * Wraps a raw alerts row with a `human` block. Includes the disease
     * headline that the master list groups by.
     *
     * @param array<string,mixed>|object $row
     * @param string|null $topDiseaseCode  optionally pre-fetched top suspected disease
     * @param string|null $travellerName   optionally pre-fetched traveller display name
     * @return array<string,mixed>
     */
    public static function wrapAlert(array|object $row, ?string $topDiseaseCode = null, ?string $travellerName = null): array
    {
        $r = is_object($row) ? (array) $row : $row;

        $disease = self::disease($topDiseaseCode);
        $status  = self::alertStatus((string) ($r['status'] ?? 'OPEN'));
        $risk    = self::risk((string) ($r['risk_level'] ?? 'MEDIUM'));

        $headline = self::composeHeadline($travellerName, $topDiseaseCode, $disease, $r['ihr_tier'] ?? null);

        $r['human'] = [
            'title'           => $headline,
            'traveller_name'  => $travellerName ?: 'Unnamed traveller',
            'classification'  => self::classificationLabel($topDiseaseCode, $disease, $r['ihr_tier'] ?? null),
            'disease'         => $disease,
            'risk_label'      => $risk['short'],
            'risk_tone'       => $risk['tone'],
            'status_label'    => $status['label'],
            'status_tone'     => $status['tone'],
            'routed_to'       => self::routedTo($r['routed_to_level'] ?? null),
            'created_human'   => self::dueHuman($r['created_at'] ?? null),
        ];

        return $r;
    }

    /**
     * Builds the user-facing headline:
     *   "John Doe (Suspected Ebola)"
     *   "Sarah Mwale (Suspected illness — under review)"
     *   "Unnamed traveller (Under review)"
     *
     * @param array<string,mixed> $disease
     */
    public static function composeHeadline(?string $travellerName, ?string $diseaseCode, array $disease, int|string|null $tier = null): string
    {
        $name  = trim((string) $travellerName) !== '' ? (string) $travellerName : 'Unnamed traveller';
        $class = self::classificationLabel($diseaseCode, $disease, $tier);

        return "{$name} ({$class})";
    }

    /**
     * The bracketed classification phrase. Falls back gracefully to the
     * tier-bucket label, then to "Under review".
     *
     * @param array<string,mixed> $disease
     */
    public static function classificationLabel(?string $diseaseCode, array $disease, int|string|null $tier = null): string
    {
        if (!empty($diseaseCode) && !empty($disease['name']) && $disease['group'] !== 'syndromic_unknown') {
            return 'Suspected ' . (string) $disease['name'];
        }

        $bucket = self::tierBucket($tier);
        return match ($bucket) {
            'top'    => 'Suspected illness — top priority, under review',
            'high'   => 'Suspected illness — high priority, under review',
            default  => 'Under review',
        };
    }

    /**
     * Bulk wrapper for a list — keeps controllers tidy.
     *
     * @param iterable<array<string,mixed>|object> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function wrapMany(iterable $rows, callable $wrapper): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $wrapper($row);
        }
        return $out;
    }

    /**
     * Standardises the `meta` block that every JSON endpoint exposes for the
     * chip palettes the views render. Keeps Blade out of the business of
     * inventing labels.
     *
     * @return array<string,mixed>
     */
    public static function metaPalette(): array
    {
        $statuses = [];
        foreach (['PENDING','IN_PROGRESS','BLOCKED','COMPLETED','NOT_APPLICABLE'] as $s) {
            $statuses[$s] = self::status($s);
        }

        $alertStatuses = [];
        foreach (['OPEN','ACKNOWLEDGED','CLOSED','REOPENED'] as $s) {
            $alertStatuses[$s] = self::alertStatus($s);
        }

        $risks = [];
        foreach (['CRITICAL','HIGH','MEDIUM','LOW'] as $s) {
            $risks[$s] = self::risk($s);
        }

        $closeCats = [];
        foreach (array_keys((array) trans('alerts.close_category')) as $code) {
            $closeCats[$code] = self::closeCategory($code);
        }

        return [
            'statuses'         => $statuses,
            'alert_statuses'   => $alertStatuses,
            'risks'            => $risks,
            'close_categories' => $closeCats,
        ];
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    private static function tierBucket(int|string|null $tier): string
    {
        if ($tier === null || $tier === '') return 'normal';

        if (is_int($tier)) {
            return match ($tier) {
                1       => 'top',
                2       => 'high',
                default => 'normal',
            };
        }

        $u = strtoupper((string) $tier);
        if (str_contains($u, 'TIER_1')) return 'top';
        if (str_contains($u, 'ANNEX2')) return 'high';
        if (str_contains($u, 'WHO_NOTIFIABLE')) return 'high';
        if (str_contains($u, 'TIER_2')) return 'high';
        if (str_contains($u, 'SYNDROMIC')) return 'normal';
        if ($u === '1') return 'top';
        if ($u === '2') return 'high';
        return 'normal';
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 3600) {
            $m = max(1, (int) round($seconds / 60));
            return $m === 1 ? '1 minute' : "{$m} minutes";
        }
        if ($seconds < 86400) {
            $h = max(1, (int) round($seconds / 3600));
            return $h === 1 ? '1 hour' : "{$h} hours";
        }
        $d = max(1, (int) round($seconds / 86400));
        return $d === 1 ? '1 day' : "{$d} days";
    }

    /** @return array<string,array{display_name:string,ihr_tier:int}> */
    private static function refDiseases(): array
    {
        if (self::$refDiseases !== null) return self::$refDiseases;

        try {
            self::$refDiseases = DB::table('ref_diseases')
                ->where('is_active', 1)
                ->get(['disease_code', 'display_name', 'ihr_tier'])
                ->keyBy('disease_code')
                ->map(fn ($row) => [
                    'display_name' => (string) $row->display_name,
                    'ihr_tier'     => (int)    $row->ihr_tier,
                ])
                ->all();
        } catch (\Throwable) {
            self::$refDiseases = [];
        }

        return self::$refDiseases;
    }

    private static function loadRefDiseaseName(string $code): ?string
    {
        $row = self::refDiseases()[$code] ?? null;
        return $row['display_name'] ?? null;
    }

    private static function loadRefDiseaseTier(string $code): ?int
    {
        $row = self::refDiseases()[$code] ?? null;
        return $row['ihr_tier'] ?? null;
    }
}
