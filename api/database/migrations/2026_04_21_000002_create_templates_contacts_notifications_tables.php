<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 6 tables for the enterprise-grade aggregated template + POE notification
 * contacts + notification templates/log subsystem.
 *
 * Additive only. No existing table or column is modified.
 *
 * Seeds the WHO-baseline default aggregated template so every new country
 * starts with a large, toggle-able template.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── aggregated_templates ────────────────────────────────────────
        if (! Schema::hasTable('aggregated_templates')) {
            Schema::create('aggregated_templates', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10);
                $t->string('template_name', 120);
                $t->string('template_code', 60);
                $t->string('description', 500)->nullable();
                $t->unsignedInteger('version')->default(1);
                $t->boolean('is_active')->default(false);
                $t->boolean('is_default')->default(false);
                $t->boolean('locked')->default(false);
                $t->json('metadata')->nullable();
                $t->unsignedBigInteger('created_by_user_id');
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->unsignedBigInteger('locked_by_user_id')->nullable();
                $t->dateTime('locked_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
                $t->timestamps();
                $t->unique(['country_code', 'template_code'], 'aggregated_templates_country_code_unique');
                $t->index(['country_code', 'is_active'], 'aggregated_templates_active_idx');
                $t->index('is_default', 'aggregated_templates_default_idx');
            });
        }

        // ── aggregated_template_columns ─────────────────────────────────
        if (! Schema::hasTable('aggregated_template_columns')) {
            Schema::create('aggregated_template_columns', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('template_id');
                $t->string('column_key', 60);
                $t->string('column_label', 160);
                $t->string('category', 40)->default('CUSTOM');
                $t->enum('data_type', ['INTEGER', 'DECIMAL', 'TEXT', 'BOOLEAN', 'DATE', 'PERCENT', 'SELECT'])->default('INTEGER');
                $t->boolean('is_required')->default(false);
                $t->boolean('is_enabled')->default(true);
                $t->boolean('is_core')->default(false);
                $t->string('default_value', 120)->nullable();
                $t->decimal('min_value', 14, 4)->nullable();
                $t->decimal('max_value', 14, 4)->nullable();
                $t->json('select_options')->nullable();
                $t->json('validation_rules')->nullable();
                $t->unsignedInteger('display_order')->default(0);
                $t->string('placeholder', 160)->nullable();
                $t->string('help_text', 500)->nullable();
                $t->boolean('dashboard_visible')->default(true);
                $t->boolean('report_visible')->default(true);
                $t->enum('aggregation_fn', ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'LATEST', 'NONE'])->default('SUM');
                $t->unsignedBigInteger('created_by_user_id');
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->dateTime('deleted_at')->nullable();
                $t->timestamps();
                $t->unique(['template_id', 'column_key'], 'agg_tpl_col_unique');
                $t->index(['template_id', 'display_order'], 'agg_tpl_col_template_idx');
                $t->index('is_enabled', 'agg_tpl_col_enabled_idx');
            });
        }

        // ── aggregated_submission_values ────────────────────────────────
        if (! Schema::hasTable('aggregated_submission_values')) {
            Schema::create('aggregated_submission_values', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('submission_id');
                $t->unsignedBigInteger('template_id');
                $t->unsignedBigInteger('template_column_id');
                $t->string('column_key', 60);
                $t->decimal('value_numeric', 14, 4)->nullable();
                $t->string('value_text', 500)->nullable();
                $t->json('value_json')->nullable();
                $t->timestamps();
                $t->unique(['submission_id', 'column_key'], 'agg_sub_val_unique');
                $t->index('submission_id', 'agg_sub_val_submission_idx');
                $t->index(['template_id', 'column_key'], 'agg_sub_val_template_idx');
                $t->index('column_key', 'agg_sub_val_key_idx');
            });
        }

        // ── poe_notification_contacts ───────────────────────────────────
        if (! Schema::hasTable('poe_notification_contacts')) {
            Schema::create('poe_notification_contacts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10);
                $t->string('district_code', 30);
                $t->string('poe_code', 40);
                $t->enum('level', ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL', 'WHO']);
                $t->string('full_name', 160);
                $t->string('position', 120)->nullable();
                $t->string('organisation', 160)->nullable();
                $t->string('phone', 40)->nullable();
                $t->string('alternate_phone', 40)->nullable();
                $t->string('email', 160)->nullable();
                $t->string('alternate_email', 160)->nullable();
                $t->unsignedInteger('priority_order')->default(1);
                $t->unsignedBigInteger('escalates_to_contact_id')->nullable();
                $t->boolean('is_active')->default(true);
                $t->boolean('receives_critical')->default(true);
                $t->boolean('receives_high')->default(true);
                $t->boolean('receives_medium')->default(false);
                $t->boolean('receives_low')->default(false);
                $t->boolean('receives_tier1')->default(true);
                $t->boolean('receives_tier2')->default(true);
                $t->boolean('receives_breach_alerts')->default(true);
                $t->boolean('receives_followup_reminders')->default(true);
                $t->boolean('receives_daily_report')->default(false);
                $t->boolean('receives_weekly_report')->default(false);
                $t->enum('preferred_channel', ['EMAIL', 'SMS', 'BOTH'])->default('EMAIL');
                $t->string('notes', 500)->nullable();
                $t->dateTime('last_notified_at')->nullable();
                $t->unsignedBigInteger('created_by_user_id');
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->dateTime('deleted_at')->nullable();
                $t->timestamps();
                $t->index(['poe_code', 'level', 'priority_order'], 'poe_contacts_poe_level_idx');
                $t->index(['district_code', 'level'], 'poe_contacts_district_level_idx');
                $t->index('country_code', 'poe_contacts_country_idx');
                $t->index('is_active', 'poe_contacts_active_idx');
                $t->index('escalates_to_contact_id', 'poe_contacts_escalates_idx');
            });
        }

        // ── notification_templates ──────────────────────────────────────
        if (! Schema::hasTable('notification_templates')) {
            Schema::create('notification_templates', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('template_code', 60);
                $t->enum('channel', ['EMAIL', 'SMS', 'PUSH'])->default('EMAIL');
                $t->string('subject_template', 200);
                $t->text('body_html_template');
                $t->text('body_text_template')->nullable();
                $t->json('applicable_levels')->nullable();
                $t->boolean('is_ai_enhanced')->default(false);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['template_code', 'channel'], 'notification_templates_code_channel_unique');
                $t->index('is_active', 'notification_templates_active_idx');
            });
        }

        // ── notification_log ────────────────────────────────────────────
        if (! Schema::hasTable('notification_log')) {
            Schema::create('notification_log', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('contact_id')->nullable();
                $t->string('to_email', 160)->nullable();
                $t->string('to_phone', 40)->nullable();
                $t->enum('channel', ['EMAIL', 'SMS', 'PUSH'])->default('EMAIL');
                $t->string('template_code', 60);
                $t->string('subject', 240)->nullable();
                $t->string('body_preview', 500)->nullable();
                $t->text('body_full')->nullable();
                $t->string('related_entity_type', 40)->nullable();
                $t->unsignedBigInteger('related_entity_id')->nullable();
                $t->string('country_code', 10)->nullable();
                $t->string('district_code', 30)->nullable();
                $t->string('poe_code', 40)->nullable();
                $t->enum('status', ['QUEUED', 'SENT', 'FAILED', 'BOUNCED', 'SKIPPED'])->default('QUEUED');
                $t->string('error_message', 500)->nullable();
                $t->unsignedInteger('retry_count')->default(0);
                $t->dateTime('sent_at')->nullable();
                $t->dateTime('delivered_at')->nullable();
                $t->dateTime('failed_at')->nullable();
                $t->string('triggered_by', 40);
                $t->timestamps();
                $t->index(['status', 'created_at'], 'notification_log_status_idx');
                $t->index(['template_code', 'created_at'], 'notification_log_template_idx');
                $t->index(['related_entity_type', 'related_entity_id'], 'notification_log_entity_idx');
                $t->index('contact_id', 'notification_log_contact_idx');
            });
        }

        // Seed notification templates + Uganda default aggregated template
        $this->seedNotificationTemplates();
        $this->seedDefaultAggregatedTemplate();
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('poe_notification_contacts');
        Schema::dropIfExists('aggregated_submission_values');
        Schema::dropIfExists('aggregated_template_columns');
        Schema::dropIfExists('aggregated_templates');
    }

    private function seedNotificationTemplates(): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $templates = [
            [
                'template_code' => 'ALERT_CRITICAL',
                'subject_template' => '[CRITICAL] {{alert_title}} · {{poe_code}} · IHR response required',
                'body_html_template' => '<p><strong>CRITICAL ALERT</strong> at {{poe_code}} ({{district_code}}).</p><p>{{alert_title}}</p><p>Details: {{alert_details}}</p><p>Risk level: {{risk_level}} · Routed to: {{routed_to_level}} · IHR Tier: {{ihr_tier}}</p><p><strong>Action required within {{ack_hours}} hours.</strong> Sign in to the POE Sentinel to acknowledge.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'ALERT_HIGH',
                'subject_template' => '[HIGH] {{alert_title}} · {{poe_code}}',
                'body_html_template' => '<p>High-risk alert raised at {{poe_code}}.</p><p>{{alert_details}}</p><p>Please acknowledge within 4 hours.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'TIER1_ADVISORY',
                'subject_template' => '[IHR TIER 1] {{alert_title}} · single case = WHO notification within 24h',
                'body_html_template' => '<p><strong>IHR Tier 1 event</strong> detected at {{poe_code}}.</p><p>{{alert_title}}</p><p>A single confirmed or probable case of this event triggers <strong>mandatory WHO notification within 24 hours</strong> (IHR Art. 6) — the Annex 2 4-criteria assessment is bypassed.</p><p>Confirm the chain of notification to the National IHR Focal Point is active.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'ANNEX2_HIT',
                'subject_template' => '[ANNEX 2] {{alert_title}} · 2-of-4 criteria met',
                'body_html_template' => '<p>The Annex 2 decision instrument on alert {{alert_code}} has returned <strong>{{annex2_yes}}/4 YES</strong>.</p><p>The State Party must notify WHO within 24 hours via the National IHR Focal Point (IHR Art. 6).</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'BREACH_717',
                'subject_template' => '[7-1-7 BREACH] {{alert_code}} · {{bottleneck_phase}} stage bottleneck',
                'body_html_template' => '<p>Alert {{alert_code}} has breached the 7-1-7 performance target at the <strong>{{bottleneck_phase}}</strong> stage.</p><p>Root cause analysis is required per Resolve to Save Lives / WHO guidance.</p><p>Elapsed: {{elapsed_hours}}h · Target: {{target_hours}}h.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'FOLLOWUP_DUE',
                'subject_template' => 'Follow-up due: {{action_label}} · {{alert_code}}',
                'body_html_template' => '<p>The follow-up action <strong>{{action_label}}</strong> for alert {{alert_code}} is due in {{due_in_hours}}h.</p>',
                'is_ai_enhanced' => 0,
            ],
            [
                'template_code' => 'FOLLOWUP_OVERDUE',
                'subject_template' => '[OVERDUE] {{action_label}} · {{alert_code}}',
                'body_html_template' => '<p><strong>Overdue follow-up</strong>: {{action_label}} (alert {{alert_code}}).</p><p>Due {{due_at}}. Please update status in the Intelligence view.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'DAILY_REPORT',
                'subject_template' => 'POE Sentinel · Daily report · {{poe_code}} · {{report_date}}',
                'body_html_template' => '<h2>Daily POE Surveillance Digest</h2><p>{{poe_code}} · {{report_date}}</p><h3>Alerts</h3><p>Open: {{open_alerts}} · Critical: {{critical_alerts}} · Breach: {{breach_alerts}}</p><h3>Screenings</h3><p>Total today: {{screened_today}} · Symptomatic: {{symptomatic_today}}</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'WEEKLY_REPORT',
                'subject_template' => 'POE Sentinel · Weekly report · {{poe_code}} · wk {{week_number}}',
                'body_html_template' => '<h2>Weekly POE Surveillance Digest</h2><p>Summary below. Full dashboards available in-app.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'ESCALATION',
                'subject_template' => '[ESCALATION] {{alert_code}} → {{escalate_to_level}}',
                'body_html_template' => '<p>Alert {{alert_code}} has been escalated from {{routed_to_level}} to <strong>{{escalate_to_level}}</strong>.</p><p>Reason: {{escalation_reason}}.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'PHEIC_ADVISORY',
                'subject_template' => '[PHEIC ADVISORY] Review required · {{alert_code}}',
                'body_html_template' => '<p>Alert {{alert_code}} meets preliminary PHEIC indicators per IHR Art. 12 criteria. WHO Emergency Committee review may be warranted.</p>',
                'is_ai_enhanced' => 1,
            ],
            [
                'template_code' => 'ALERT_CLOSED',
                'subject_template' => 'Closed: {{alert_code}} · {{close_reason_short}}',
                'body_html_template' => '<p>Alert {{alert_code}} has been closed.</p><p>Reason: {{close_reason}}.</p><p>Closed by {{closed_by_name}} at {{closed_at}}.</p>',
                'is_ai_enhanced' => 0,
            ],
        ];
        foreach ($templates as $tpl) {
            DB::table('notification_templates')->insertOrIgnore([
                'template_code'      => $tpl['template_code'],
                'channel'            => 'EMAIL',
                'subject_template'   => $tpl['subject_template'],
                'body_html_template' => $tpl['body_html_template'],
                'body_text_template' => strip_tags($tpl['body_html_template']),
                'applicable_levels'  => json_encode(['POE', 'DISTRICT', 'PHEOC', 'NATIONAL']),
                'is_ai_enhanced'     => $tpl['is_ai_enhanced'],
                'is_active'          => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }
    }

    private function seedDefaultAggregatedTemplate(): void
    {
        // Only seed if a default doesn't already exist
        if (DB::table('aggregated_templates')->where('is_default', 1)->exists()) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s');
        $tplId = DB::table('aggregated_templates')->insertGetId([
            'country_code'        => 'UG',
            'template_name'       => 'WHO Baseline · POE Aggregated Report',
            'template_code'       => 'WHO_BASELINE_POE_V1',
            'description'         => 'Seeded default template covering WHO AFRO IDSR baseline + IHR mandatory counts. Admins may toggle columns off or add country-specific custom columns.',
            'version'             => 1,
            'is_active'           => 1,
            'is_default'          => 1,
            'locked'              => 0,
            'metadata'            => json_encode(['seeded' => true, 'source' => 'WHO AFRO IDSR 3rd Ed.']),
            'created_by_user_id'  => 1,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        // 57-column large default aligned with authoritative ECSA-HC / WHO AFRO
        // sources. Admin can toggle off any non-core column.
        //
        // SOURCE MAP:
        //   • WHO AFRO IDSR Technical Guidelines 3rd Ed. Booklet 1 — Annex C
        //     (PoE surveillance data elements)
        //   • Uganda MoH IDSR 3rd Ed. (Sept 2021) — PoE register
        //   • HISP DHIS2 COVID-19 PoE Tracker System Design v0.3.1 — the
        //     de-facto field dictionary retained in KE / UG / RW / ZM IDSR
        //     PoE modules
        //   • IHR 2005 Annex 1B & WHO Benchmark 17 (POE capacities)
        //   • IHR 2005 Annex 7 (yellow-fever certificate checks)
        //   • Africa CDC Cross-Border Surveillance Strategic Framework (2024)
        //
        // ECSA-HC does not publish its own PoE aggregated template; it
        // defers to WHO AFRO IDSR as the technical standard.
        //
        // Format: [key, label, category, data_type, is_required, is_core, enabled_by_default]
        $cols = [
            // ── CORE (required by system — cannot disable) ────────────────
            ['total_screened',         'Total screened',                        'CORE',     'INTEGER', 1, 1, 1],
            ['total_male',             'Total male',                            'GENDER',   'INTEGER', 1, 1, 1],
            ['total_female',           'Total female',                          'GENDER',   'INTEGER', 1, 1, 1],
            // 'total_other' + 'total_unknown_gender' retired 2026-04-21 —
            // only MALE/FEMALE are captured by POE screeners.
            ['total_symptomatic',      'Total symptomatic',                     'SYMPTOMS', 'INTEGER', 1, 1, 1],
            ['total_asymptomatic',     'Total asymptomatic',                    'SYMPTOMS', 'INTEGER', 1, 1, 1],
            // ── AGE bands ────────────────────────────────────────────────
            ['age_under_5',            'Under 5 years',                         'AGE',      'INTEGER', 0, 0, 1],
            ['age_5_17',               '5-17 years',                            'AGE',      'INTEGER', 0, 0, 1],
            ['age_18_49',              '18-49 years',                           'AGE',      'INTEGER', 0, 0, 1],
            ['age_50_plus',            '50+ years',                             'AGE',      'INTEGER', 0, 0, 1],
            // ── TRAVEL ───────────────────────────────────────────────────
            ['total_arrivals',         'Arrivals',                              'TRAVEL',   'INTEGER', 0, 0, 1],
            ['total_departures',       'Departures',                            'TRAVEL',   'INTEGER', 0, 0, 1],
            ['total_transit',          'In-transit',                            'TRAVEL',   'INTEGER', 0, 0, 1],
            ['high_risk_origin',       'Arrivals from high-risk origin country', 'TRAVEL',  'INTEGER', 0, 0, 1],
            // ── VACCINE / PROPHYLAXIS ────────────────────────────────────
            ['yellow_fever_vacc_valid', 'Yellow fever vaccination valid',       'VACCINE',  'INTEGER', 0, 0, 0],
            ['yellow_fever_vacc_missing','Yellow fever vaccination missing',    'VACCINE',  'INTEGER', 0, 0, 0],
            ['polio_vacc_valid',       'Polio vaccination valid',               'VACCINE',  'INTEGER', 0, 0, 0],
            // ── SYNDROME / DISEASE ───────────────────────────────────────
            ['cases_fever',            'Fever cases',                           'SYMPTOMS', 'INTEGER', 0, 0, 0],
            ['cases_respiratory',      'Respiratory syndrome cases',            'DISEASE',  'INTEGER', 0, 0, 0],
            ['cases_diarrhoeal',       'Diarrhoeal syndrome cases',             'DISEASE',  'INTEGER', 0, 0, 0],
            ['cases_haemorrhagic',     'Haemorrhagic syndrome cases',           'DISEASE',  'INTEGER', 0, 0, 0],
            ['cases_neurological',     'Neurological syndrome cases',           'DISEASE',  'INTEGER', 0, 0, 0],
            ['suspected_cholera',      'Suspected cholera',                     'DISEASE',  'INTEGER', 0, 0, 0],
            ['suspected_meningitis',   'Suspected meningitis',                  'DISEASE',  'INTEGER', 0, 0, 0],
            ['suspected_vhf',          'Suspected VHF',                         'DISEASE',  'INTEGER', 0, 0, 0],
            ['suspected_yellow_fever', 'Suspected yellow fever',                'DISEASE',  'INTEGER', 0, 0, 0],
            ['suspected_mpox',         'Suspected mpox',                        'DISEASE',  'INTEGER', 0, 0, 0],
            // ── REFERRALS / OUTCOMES ─────────────────────────────────────
            ['referrals_made',         'Referrals to secondary',                'CORE',     'INTEGER', 0, 0, 0],
            ['alerts_raised',          'Alerts raised',                         'CORE',     'INTEGER', 0, 0, 0],
            ['lab_samples_collected',  'Lab samples collected',                 'LAB',      'INTEGER', 0, 0, 0],
            ['lab_samples_positive',   'Lab samples positive',                  'LAB',      'INTEGER', 0, 0, 0],
            ['deaths_recorded',        'Deaths recorded',                       'CORE',     'INTEGER', 0, 0, 0],
            // ── QUALITY (legacy custom) ──────────────────────────────────
            ['data_quality_score',     'Data quality score (0-100)',            'CUSTOM',   'PERCENT', 0, 0, 0],
            ['reviewed_by_supervisor', 'Reviewed by supervisor',                'CUSTOM',   'BOOLEAN', 0, 0, 0],

            // ══ WHO AFRO IDSR / IHR Annex 1B / DHIS2 PoE additions ══════════
            // Core detection + outcomes pipeline (WHO AFRO IDSR Annex C)
            ['ill_travellers_detected',        'Ill travellers detected on arrival',                        'CORE',        'INTEGER', 0, 0, 1],
            ['fever_above_38',                 'Travellers with temperature ≥ 38 °C',                      'SYMPTOMS',    'INTEGER', 0, 0, 1],
            ['isolated_on_site',               'Travellers isolated at PoE holding area',                  'OUTCOMES',    'INTEGER', 0, 0, 1],
            ['referred_to_isolation_facility', 'Referred to designated isolation/treatment facility',      'OUTCOMES',    'INTEGER', 0, 0, 1],
            ['quarantine_home_follow_up',      'Placed under home/community quarantine follow-up',         'OUTCOMES',    'INTEGER', 0, 0, 0],
            ['contacts_listed',                'Contacts of ill travellers listed',                        'CORE',        'INTEGER', 0, 0, 1],

            // Travel / origin context (IHR events + DHIS2 PoE Tracker)
            ['arrivals_from_outbreak_country', 'Arrivals from countries with active IHR-notifiable outbreak', 'TRAVEL',    'INTEGER', 0, 0, 1],
            ['nationals_returning',            'Returning nationals / residents',                          'TRAVEL',      'INTEGER', 0, 0, 0],
            ['foreign_nationals',              'Foreign nationals',                                        'TRAVEL',      'INTEGER', 0, 0, 0],

            // Conveyance & IHR Annex 1B capacities
            ['conveyances_inspected',          'Conveyances (aircraft/ship/vehicle) inspected',            'CONVEYANCE',  'INTEGER', 0, 0, 1],
            ['ship_sanitation_certs_issued',   'Ship Sanitation Certificates issued/extended',             'CONVEYANCE',  'INTEGER', 0, 0, 0],
            ['aircraft_gen_decl_reviewed',     'Aircraft General Declarations (Part 2) reviewed',          'CONVEYANCE',  'INTEGER', 0, 0, 0],
            ['vector_control_actions',         'Vector control actions taken at PoE',                      'ENVIRONMENT', 'INTEGER', 0, 0, 0],

            // Vaccination certificate checks (IHR Annex 7)
            ['yellow_fever_cert_checked',          'Yellow fever certificates checked',                    'VACCINE',     'INTEGER', 0, 0, 1],
            ['yellow_fever_cert_invalid_refused',  'YF cert invalid — vaccination/refusal at border',      'VACCINE',     'INTEGER', 0, 0, 0],

            // IDSR priority syndromes (gapped in 35-col baseline)
            ['suspected_afp',                  'Suspected AFP / polio',                                    'DISEASE',     'INTEGER', 0, 0, 1],
            ['suspected_measles',              'Suspected measles',                                        'DISEASE',     'INTEGER', 0, 0, 1],
            ['suspected_sari_ili',             'Suspected SARI / ILI',                                     'DISEASE',     'INTEGER', 0, 0, 1],

            // Lab quality & alert verification
            ['lab_samples_rejected',           'Samples rejected / unsuitable',                            'LAB',         'INTEGER', 0, 0, 0],
            ['alerts_verified_true',           'PoE alerts verified as true events',                       'CORE',        'INTEGER', 0, 0, 0],

            // Data quality (WHO AFRO IDSR reporting SLA indicators)
            ['report_completeness_pct',        'Daily report completeness (%)',                            'QUALITY',     'PERCENT', 0, 0, 0],
            ['report_timeliness_pct',          'On-time submission (%)',                                   'QUALITY',     'PERCENT', 0, 0, 0],
        ];
        $order = 0;
        foreach ($cols as $row) {
            [$key, $label, $category, $type, $required, $core, $enabled] = $row;
            DB::table('aggregated_template_columns')->insert([
                'template_id'          => $tplId,
                'column_key'           => $key,
                'column_label'         => $label,
                'category'             => $category,
                'data_type'            => $type,
                'is_required'          => $required,
                'is_enabled'           => $core || $required ? 1 : $enabled,
                'is_core'              => $core,
                'display_order'        => $order++,
                'placeholder'          => null,
                'help_text'            => null,
                'dashboard_visible'    => 1,
                'report_visible'       => 1,
                'aggregation_fn'       => $type === 'BOOLEAN' ? 'COUNT' : ($type === 'PERCENT' ? 'AVG' : 'SUM'),
                'created_by_user_id'   => 1,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
        }
    }
};
