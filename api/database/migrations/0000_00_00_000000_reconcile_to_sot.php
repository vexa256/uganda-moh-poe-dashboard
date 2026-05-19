<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $sotPath = database_path('schema/ug_poe.sot.sql');
        if (! is_file($sotPath)) {
            throw new RuntimeException(
                "SOT schema file not found at {$sotPath}. "
                ."This migration depends on the committed SOT dump."
            );
        }

        DB::unprepared('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->splitStatements(file_get_contents($sotPath)) as $stmt) {
                $upper = strtoupper(ltrim($stmt));
                if ($upper === '' || str_starts_with($upper, 'START TRANSACTION') || $upper === 'COMMIT;' || $upper === 'COMMIT') {
                    continue;
                }
                try {
                    DB::unprepared($stmt);
                } catch (\Throwable $e) {
                    if (! $this->isSafeError($e)) {
                        throw $e;
                    }
                }
            }
        } finally {
            DB::unprepared('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->backfillLedger();
    }

    public function down(): void
    {
        throw new RuntimeException(
            'reconcile_to_sot is intentionally non-reversible — rolling it '
            .'back would imply dropping 69 production tables. Use schema-'
            .'level tools if a rollback is truly needed.'
        );
    }

    protected function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        foreach (preg_split('/\R/', $sql) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $buffer .= $line."\n";
            if (str_ends_with($trimmed, ';')) {
                $statements[] = trim($buffer);
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    protected function isSafeError(\Throwable $e): bool
    {
        $safe = [
            1050, // table already exists
            1060, // duplicate column name
            1061, // duplicate key name
            1062, // duplicate entry for unique key
            1068, // multiple primary key defined
            1091, // can't drop; doesn't exist
            1826, // duplicate foreign key constraint name
        ];

        $prev = $e->getPrevious();
        $errno = is_array($prev?->errorInfo ?? null) ? ($prev->errorInfo[1] ?? null) : null;
        if ($errno !== null && in_array((int) $errno, $safe, true)) {
            return true;
        }

        $msg = $e->getMessage();
        foreach ($safe as $code) {
            if (str_contains($msg, " {$code} ") || str_contains($msg, "[{$code}]") || str_contains($msg, "({$code})")) {
                return true;
            }
        }

        return false;
    }

    protected function backfillLedger(): void
    {
        $batch = ((int) DB::table('migrations')->max('batch')) + 1;
        $existing = array_flip(DB::table('migrations')->pluck('migration')->all());

        $rows = [];
        foreach (glob(database_path('migrations').'/*.php') as $file) {
            $name = basename($file, '.php');
            if ($name === '0000_00_00_000000_reconcile_to_sot') {
                continue;
            }
            if (isset($existing[$name])) {
                continue;
            }
            $rows[] = ['migration' => $name, 'batch' => $batch];
        }

        if (! empty($rows)) {
            DB::table('migrations')->insert($rows);
        }
    }
};
