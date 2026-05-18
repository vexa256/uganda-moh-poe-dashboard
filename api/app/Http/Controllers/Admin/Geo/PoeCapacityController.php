<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · PoE · Annex-1A Capacity Assessment.
 *
 * WHO IHR-2005 Annex-1A core capacities for designated Points of Entry.
 * 8 dimensions (per Annex-1A.1) scored 1-5 with evidence + gap notes.
 * Overall score derives from the average.
 *
 * Workflow: DRAFT → SUBMITTED → REVIEWED → ARCHIVED.
 *
 * Auth + scope enforced by route middleware.
 */
final class PoeCapacityController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }
    private const STATUSES = ['DRAFT', 'SUBMITTED', 'REVIEWED', 'ARCHIVED'];

    /** Annex-1A core capacities catalogue (canonical labels per WHO 2005). */
    public const CAPACITIES = [
        'INSPECTION_FACILITIES' => [
            'label'  => 'Inspection facilities',
            'detail' => 'Premises and trained personnel to inspect conveyances, containers, baggage, and goods.',
        ],
        'EQUIPPED_MEDICAL'     => [
            'label'  => 'Equipped medical facilities',
            'detail' => 'Medical facility with diagnostic equipment to assess and care for ill travellers.',
        ],
        'TRAINED_PERSONNEL'    => [
            'label'  => 'Trained personnel',
            'detail' => 'Sufficient and appropriately trained personnel to operate Annex-1A capacities.',
        ],
        'VECTOR_CONTROL'       => [
            'label'  => 'Vector & reservoir control',
            'detail' => 'Programmes for control of vectors and reservoirs in and near the PoE perimeter.',
        ],
        'DECONTAMINATION'      => [
            'label'  => 'Disinfection / decontamination',
            'detail' => 'Disinfection / decontamination of conveyances, baggage, cargo, and goods.',
        ],
        'TRAVELLER_SAFETY'     => [
            'label'  => 'Traveller safe environment',
            'detail' => 'Hygiene, sanitation, and safe environment for travellers using PoE facilities.',
        ],
        'ANIMAL_HEALTH'        => [
            'label'  => 'Programme for animal health',
            'detail' => 'Veterinary services for live animals and animal products at the PoE.',
        ],
        'COMMUNICATIONS'       => [
            'label'  => 'Communication systems',
            'detail' => 'Communication systems linking the PoE to the IHR National Focal Point.',
        ],
    ];

    public function index(Request $r)
    {
        return view('admin.geo.poe-capacity.index');
    }

    public function data(Request $r): JsonResponse
    {
        try {
            $scope    = ScopeFilter::fromRequest($r);
            $country  = (string) $r->query('country', self::defaultCountry());
            $tab      = strtolower((string) $r->query('status', 'all'));
            $poeCode  = trim((string) $r->query('poe_code', ''));

            $query = DB::table('poe_capacity_assessments')->where('country_code', $country)->whereNull('deleted_at');
            $query = ScopeFilter::applyToPoeOpsByPoeCode($query, $scope);
            if ($tab !== 'all') $query->where('status', strtoupper($tab));
            if ($poeCode !== '') $query->where('poe_code', $poeCode);

            $rows = $query->orderByDesc('assessment_date')->orderByDesc('id')->limit(100)->get();

            $tabCounts = [
                'all'       => ScopeFilter::applyToPoeOpsByPoeCode(DB::table('poe_capacity_assessments')->where('country_code', $country)->whereNull('deleted_at'), $scope)->count(),
                'draft'     => ScopeFilter::applyToPoeOpsByPoeCode(DB::table('poe_capacity_assessments')->where('country_code', $country)->where('status','DRAFT')->whereNull('deleted_at'), $scope)->count(),
                'submitted' => ScopeFilter::applyToPoeOpsByPoeCode(DB::table('poe_capacity_assessments')->where('country_code', $country)->where('status','SUBMITTED')->whereNull('deleted_at'), $scope)->count(),
                'reviewed'  => ScopeFilter::applyToPoeOpsByPoeCode(DB::table('poe_capacity_assessments')->where('country_code', $country)->where('status','REVIEWED')->whereNull('deleted_at'), $scope)->count(),
            ];

            return $this->ok([
                'rows'  => $rows->map(fn ($a) => $this->castHeader($a))->all(),
                'total' => $rows->count(),
            ], 'Assessments.', ['tabs' => $tabCounts]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('poe_capacity_assessments')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Assessment not found.');
            $scores = DB::table('poe_capacity_scores')
                ->where('assessment_id', $id)
                ->orderBy('id')
                ->get()
                ->map(fn ($s) => [
                    'capacity_code'  => (string) $s->capacity_code,
                    'capacity_label' => (string) $s->capacity_label,
                    'score'          => (int) $s->score,
                    'evidence'       => $s->evidence,
                    'gap_notes'      => $s->gap_notes,
                ])->all();

            $detail = $this->castHeader($row);
            $detail['scores']     = $scores;
            $detail['catalogue']  = self::CAPACITIES;
            return $this->ok($detail, 'Assessment retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $poesQ = DB::table('ref_poes')->where('country_code', self::defaultCountry())->whereNull('deleted_at');
            $poesQ = ScopeFilter::applyToPoes($poesQ, $scope);
            $poes  = $poesQ->orderBy('display_order')->get(['poe_code','poe_name','admin_level_1','district'])
                ->map(fn ($p) => [
                    'poe_code' => (string) $p->poe_code,
                    'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->admin_level_1,
                    'district' => (string) $p->district,
                ])->all();
            return $this->ok([
                'statuses'   => self::STATUSES,
                'poes'       => $poes,
                'capacities' => self::CAPACITIES,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    public function store(Request $r): JsonResponse
    {
        $admin = (int) auth()->id();
        $data = $r->all();

        $poeCode = trim((string) ($data['poe_code'] ?? ''));
        if ($poeCode === '') return $this->err(422, 'poe_code is required.');

        try {
            $now = Carbon::now();
            $assessmentDate = ! empty($data['assessment_date']) ? Carbon::parse((string) $data['assessment_date']) : $now;

            $scores = $this->normaliseScores($data['scores'] ?? []);
            $overall = $this->computeOverall($scores);

            $aId = DB::table('poe_capacity_assessments')->insertGetId([
                'country_code'      => self::defaultCountry(),
                'poe_code'          => $poeCode,
                'assessment_date'   => $assessmentDate,
                'status'            => 'DRAFT',
                'overall_score'     => $overall,
                'summary'           => $data['summary']         ?? null,
                'gaps_identified'   => $data['gaps_identified'] ?? null,
                'action_plan'       => $data['action_plan']     ?? null,
                'assessor_user_id'  => $admin,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            $this->insertScores($aId, $scores);

            $fresh = DB::table('poe_capacity_assessments')->where('id', $aId)->first();
            return $this->ok($this->castHeader($fresh), 'Assessment created.');
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $admin = (int) auth()->id();
        try {
            $row = DB::table('poe_capacity_assessments')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err(404, 'Assessment not found.');
            $data = $r->all();

            $patch = ['updated_at' => Carbon::now()];
            foreach (['summary','gaps_identified','action_plan'] as $f) {
                if (array_key_exists($f, $data)) $patch[$f] = $data[$f] === '' ? null : $data[$f];
            }
            if (array_key_exists('status', $data)) {
                $s = strtoupper((string) $data['status']);
                if (! in_array($s, self::STATUSES, true)) return $this->err(422, 'Invalid status.', ['allowed' => self::STATUSES]);
                $patch['status'] = $s;
                if ($s === 'SUBMITTED' && empty($row->submitted_at)) $patch['submitted_at'] = Carbon::now();
                if ($s === 'REVIEWED') {
                    $patch['reviewed_by_user_id'] = $admin;
                    $patch['reviewed_at']         = Carbon::now();
                }
            }

            // Replace scores if supplied (DELETE+INSERT pattern, mirroring §A.5).
            if (isset($data['scores']) && is_array($data['scores'])) {
                $scores = $this->normaliseScores($data['scores']);
                DB::table('poe_capacity_scores')->where('assessment_id', $id)->delete();
                $this->insertScores($id, $scores);
                $patch['overall_score'] = $this->computeOverall($scores);
            }

            DB::table('poe_capacity_assessments')->where('id', $id)->update($patch);
            $fresh = DB::table('poe_capacity_assessments')->where('id', $id)->first();
            return $this->ok($this->castHeader($fresh), 'Assessment updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    private function normaliseScores(array $rawScores): array
    {
        $out = [];
        foreach ($rawScores as $code => $data) {
            $code = strtoupper((string) $code);
            if (! isset(self::CAPACITIES[$code])) continue;
            $score = (int) ($data['score'] ?? 0);
            if ($score < 1 || $score > 5) continue;
            $out[$code] = [
                'score'     => $score,
                'evidence'  => isset($data['evidence'])  ? (string) $data['evidence']  : null,
                'gap_notes' => isset($data['gap_notes']) ? (string) $data['gap_notes'] : null,
            ];
        }
        return $out;
    }

    private function insertScores(int $assessmentId, array $scores): void
    {
        $now = Carbon::now();
        $rows = [];
        foreach ($scores as $code => $s) {
            $rows[] = [
                'assessment_id'  => $assessmentId,
                'capacity_code'  => $code,
                'capacity_label' => self::CAPACITIES[$code]['label'] ?? $code,
                'score'          => $s['score'],
                'evidence'       => $s['evidence'],
                'gap_notes'      => $s['gap_notes'],
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }
        if ($rows) DB::table('poe_capacity_scores')->insert($rows);
    }

    private function computeOverall(array $scores): ?int
    {
        if (empty($scores)) return null;
        $sum = 0; $n = 0;
        foreach ($scores as $s) { $sum += $s['score']; $n++; }
        // Convert 1-5 average to 0-100.
        return (int) round(($sum / $n - 1) / 4 * 100);
    }

    private function castHeader(object $r): array
    {
        return [
            'id'                  => (int) $r->id,
            'country_code'        => (string) $r->country_code,
            'poe_code'            => (string) $r->poe_code,
            'assessment_date'     => $r->assessment_date,
            'status'              => (string) $r->status,
            'overall_score'       => $r->overall_score !== null ? (int) $r->overall_score : null,
            'summary'             => $r->summary,
            'gaps_identified'     => $r->gaps_identified,
            'action_plan'         => $r->action_plan,
            'assessor_user_id'    => $r->assessor_user_id !== null ? (int) $r->assessor_user_id : null,
            'reviewed_by_user_id' => $r->reviewed_by_user_id !== null ? (int) $r->reviewed_by_user_id : null,
            'submitted_at'        => $r->submitted_at,
            'reviewed_at'         => $r->reviewed_at,
            'created_at'          => $r->created_at,
            'updated_at'          => $r->updated_at,
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Geo\\PoeCapacity][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
