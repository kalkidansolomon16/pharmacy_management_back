<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a tabular report into a CSV (opens in Excel) or a branded PDF.
 */
class ReportExporter
{
    /**
     * @param  array<string, string>  $columns  key => heading
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, string|int|float>  $summary  label => value
     */
    public function export(string $format, string $title, string $subtitle, array $columns, iterable $rows, array $summary = []): Response
    {
        $filename = str($title)->slug().'-'.now()->format('Ymd-His');

        return $format === 'pdf'
            ? $this->pdf($filename, $title, $subtitle, $columns, $rows, $summary)
            : $this->csv($filename, $title, $subtitle, $columns, $rows, $summary);
    }

    private function csv(string $filename, string $title, string $subtitle, array $columns, iterable $rows, array $summary): StreamedResponse
    {
        return response()->streamDownload(function () use ($title, $subtitle, $columns, $rows, $summary) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Amharic correctly
            fputcsv($out, [$title]);
            fputcsv($out, [$subtitle]);
            foreach ($summary as $label => $value) {
                fputcsv($out, [$label, $value]);
            }
            fputcsv($out, []);
            fputcsv($out, array_values($columns));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($key) => $row[$key] ?? '', array_keys($columns)));
            }
            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pdf(string $filename, string $title, string $subtitle, array $columns, iterable $rows, array $summary): Response
    {
        return Pdf::loadView('reports.table', [
            'title' => $title,
            'subtitle' => $subtitle,
            'columns' => $columns,
            'rows' => $rows,
            'summary' => $summary,
            'organization' => auth()->user()?->tenant?->name ?? 'MedLink Ethiopia',
            'generatedBy' => auth()->user()?->name,
        ])->setPaper('a4', count($columns) > 6 ? 'landscape' : 'portrait')->download("{$filename}.pdf");
    }
}
