<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AggressiveEmailTemplatesSeeder
 *
 * Replaces the 15 notification_templates rows with aggressively detailed,
 * visually-differentiated bodies. Each template is a decision-grade brief —
 * WHO / WHAT / WHEN / WHERE / WHY / HOW / WHAT NEXT — with the specific
 * epidemiological + public-health intelligence relevant to its alert type.
 *
 * Uses CaseContextBuilder tokens including {{{html_block}}} triple-brace
 * tokens for pre-rendered HTML fragments (symptoms, vitals, disease intel).
 *
 * Idempotent: uses updateOrInsert on template_code.
 *
 * Run: php artisan db:seed --class=AggressiveEmailTemplatesSeeder
 */
class AggressiveEmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $t) {
            DB::table('notification_templates')->updateOrInsert(
                ['template_code' => $t['template_code'], 'channel' => 'EMAIL'],
                [
                    'subject_template'     => $t['subject'],
                    'body_html_template'   => $t['html'],
                    'body_text_template'   => $t['text'],
                    'applicable_levels'    => json_encode($t['levels'] ?? ['DISTRICT', 'PHEOC', 'NATIONAL', 'WHO']),
                    'is_ai_enhanced'       => 0,
                    'is_active'            => 1,
                    'updated_at'           => now(),
                    'created_at'           => now(),
                ]
            );
        }
    }

    private function templates(): array
    {
        return array_merge(
            (new \Database\Seeders\AggressiveTemplates\AlertTemplates)->all(),
            (new \Database\Seeders\AggressiveTemplates\FollowupTemplates)->all(),
            (new \Database\Seeders\AggressiveTemplates\ReportTemplates)->all(),
        );
    }
}
