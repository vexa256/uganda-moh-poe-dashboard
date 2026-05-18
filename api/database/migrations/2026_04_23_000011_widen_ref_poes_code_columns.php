<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen ref_poes.poe_code from varchar(40) → varchar(200) so that
 * entries whose poe_code is set to poe_name (per POEs.js metadata
 * convention) — e.g. "Simon Mwansa Kapwepwe International Airport" —
 * fit without truncation.  Mirrors poe_name width.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ref_poes')) {
            DB::statement('ALTER TABLE ref_poes MODIFY poe_code VARCHAR(200) NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ref_poes')) {
            DB::statement('ALTER TABLE ref_poes MODIFY poe_code VARCHAR(40) NOT NULL');
        }
    }
};
