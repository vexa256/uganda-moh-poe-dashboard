<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ExportWriter — emits CSV / XLSX-fallback / printable PDF-source HTML for a
 * report's tabular data. All exports are streamed (no temp files), respect the
 * caller's filter state, and write a row to report_export_log on dispatch.
 *
 * No external libraries required — uses fputcsv() (CSV), tab-separated UTF-8
 * (.xls fallback that Excel opens natively), and inline-styled HTML for print.
 */
final class ExportWriter
{
    /**
     * @param  string                   $reportKey    e.g. "rpt-volume"
     * @param  string                   $format       CSV | XLSX | PDF
     * @param  array<int,string>        $headers      column labels
     * @param  iterable<int,array>      $rows         each row aligned with $headers
     * @param  array                    $filters      active filter set (logged)
     * @param  int                      $userId
     */
    public function send(
        string $reportKey,
        string $format,
        array $headers,
        iterable $rows,
        array $filters,
        int $userId,
        ?string $title = null,
    ): StreamedResponse|Response {
        $format = strtoupper($format);
        if (! in_array($format, ['CSV', 'XLSX', 'PDF'], true)) {
            $format = 'CSV';
        }

        $filename = $reportKey . '-' . date('Ymd-His');
        $logId    = $this->logStart($reportKey, $format, $filters, $userId);

        return match ($format) {
            'XLSX'  => $this->sendXls($filename, $headers, $rows, $logId),
            'PDF'   => $this->sendPrintHtml($filename, $headers, $rows, $logId, $title ?? $reportKey),
            default => $this->sendCsv($filename, $headers, $rows, $logId),
        };
    }

    private function sendCsv(string $filename, array $headers, iterable $rows, int $logId): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $logId) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, $headers);
            $count = 0; $bytes = 0;
            foreach ($rows as $r) {
                $line = $this->normaliseRow($r, $headers);
                fputcsv($out, $line);
                $count++;
                $bytes += strlen(implode(',', $line)) + 1;
            }
            fflush($out);
            fclose($out);
            $this->logFinish($logId, $count, $bytes);
        }, "{$filename}.csv", [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Cache-Control'       => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sendXls(string $filename, array $headers, iterable $rows, int $logId): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $logId) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fwrite($out, $this->tabRow($headers) . "\r\n");
            $count = 0; $bytes = 0;
            foreach ($rows as $r) {
                $line = $this->normaliseRow($r, $headers);
                $row  = $this->tabRow($line);
                fwrite($out, $row . "\r\n");
                $count++;
                $bytes += strlen($row) + 2;
            }
            fflush($out);
            fclose($out);
            $this->logFinish($logId, $count, $bytes);
        }, "{$filename}.xls", [
            'Content-Type'        => 'application/vnd.ms-excel; charset=utf-8',
            'Cache-Control'       => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function sendPrintHtml(string $filename, array $headers, iterable $rows, int $logId, string $title): Response
    {
        $rowsArray = [];
        $count = 0; $bytes = 0;
        foreach ($rows as $r) {
            $rowsArray[] = $this->normaliseRow($r, $headers);
            $count++;
        }

        $titleHtml  = e($title);
        $headersHtml = '';
        foreach ($headers as $h) {
            $headersHtml .= '<th>' . e((string) $h) . '</th>';
        }
        $bodyHtml = '';
        foreach ($rowsArray as $r) {
            $bodyHtml .= '<tr>';
            foreach ($r as $cell) {
                $bodyHtml .= '<td>' . e((string) $cell) . '</td>';
            }
            $bodyHtml .= '</tr>';
        }

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
              . '<title>' . $titleHtml . '</title>'
              . '<style>'
              . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;margin:24px;}'
              . 'h1{font-size:18px;margin:0 0 12px}'
              . 'table{width:100%;border-collapse:collapse;font-size:12px}'
              . 'th,td{border:1px solid #999;padding:6px 8px;text-align:left;vertical-align:top}'
              . 'th{background:#f3f3f3}'
              . 'tr:nth-child(even) td{background:#fafafa}'
              . '@media print{body{margin:0}.no-print{display:none}}'
              . '.no-print{margin:12px 0}'
              . '</style></head><body>'
              . '<div class="no-print"><button onclick="window.print()">Print / Save as PDF</button></div>'
              . '<h1>' . $titleHtml . '</h1>'
              . '<table><thead><tr>' . $headersHtml . '</tr></thead>'
              . '<tbody>' . $bodyHtml . '</tbody></table>'
              . '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});</script>'
              . '</body></html>';

        $bytes = strlen($html);
        $this->logFinish($logId, $count, $bytes);

        return response($html, 200, [
            'Content-Type'        => 'text/html; charset=utf-8',
            'Cache-Control'       => 'no-store, max-age=0',
            'X-Print-Hint'        => 'pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '.html"',
        ]);
    }

    private function tabRow(array $row): string
    {
        $cleaned = [];
        foreach ($row as $cell) {
            $value = (string) $cell;
            $value = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $value);
            $cleaned[] = $value;
        }
        return implode("\t", $cleaned);
    }

    private function normaliseRow(array|object $row, array $headers): array
    {
        $row = is_object($row) ? (array) $row : $row;
        if (array_is_list($row)) {
            return array_map(static fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v), $row);
        }
        $out = [];
        foreach ($headers as $label) {
            $key = Str::snake(str_replace(['/', '%', ' '], '_', strtolower($label)));
            $val = $row[$label] ?? $row[$key] ?? '';
            $out[] = is_scalar($val) || $val === null ? (string) $val : json_encode($val);
        }
        return $out;
    }

    private function logStart(string $reportKey, string $format, array $filters, int $userId): int
    {
        try {
            return (int) DB::table('report_export_log')->insertGetId([
                'user_id'      => $userId,
                'report_key'   => $reportKey,
                'filters_json' => json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'format'       => $format,
                'row_count'    => 0,
                'file_size'    => 0,
                'triggered_at' => now(),
            ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function logFinish(int $id, int $rowCount, int $bytes): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            DB::table('report_export_log')->where('id', $id)->update([
                'row_count'    => $rowCount,
                'file_size'    => $bytes,
                'completed_at' => now(),
            ]);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
