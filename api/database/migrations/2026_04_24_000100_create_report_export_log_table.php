<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('report_export_log')) {
            return;
        }

        Schema::create('report_export_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('report_key', 40);
            $t->json('filters_json')->nullable();
            $t->enum('format', ['CSV', 'XLSX', 'PDF']);
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('file_size')->default(0);
            $t->dateTime('triggered_at')->useCurrent();
            $t->dateTime('completed_at')->nullable();
            $t->unsignedInteger('download_count')->default(0);
            $t->index('user_id');
            $t->index(['report_key', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_export_log');
    }
};
