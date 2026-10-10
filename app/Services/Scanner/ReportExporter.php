<?php

namespace App\Services\Scanner;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Membuat laporan hasil scan dalam bentuk CSV, PDF, atau SARIF 2.1.0.
 */
class ReportExporter
{
    public function csv(array $result): StreamedResponse
    {
        $filename = 'laporan-scan-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 agar Excel mengenali karakter non-ASCII
            fwrite($out, "\xEF\xBB\xBF");

            // Delimiter ";" agar langsung rapi di Excel locale Indonesia
            fputcsv($out, [
                'Tipe', 'File', 'Paket', 'Constraint', 'Versi Terpasang', 'Dev', 'Status',
                'Severity', 'Advisory', 'CVE', 'Versi Terdampak', 'Detail', 'Saran Upgrade',
            ], ';');

            foreach ($result['files'] as $file) {
                foreach ($file['packages'] as $package) {
                    if (! $package['checked']) {
                        fputcsv($out, [
                            'DILEWATI', $file['file'], $package['name'], $package['constraint'],
                            $package['installed_version'] ?? '',
                            self::dev($package), $package['skip_reason'] ?? '-',
                            '', '', '', '', '', '',
                        ], ';');

                        continue;
                    }

                    if ($package['advisories'] === []) {
                        fputcsv($out, [
                            'AMAN', $file['file'], $package['name'], $package['constraint'],
                            $package['installed_version'] ?? '',
                            self::dev($package), 'AMAN',
                            '', '', '', '', '', '',
                        ], ';');

                        continue;
                    }

                    foreach ($package['advisories'] as $advisory) {
                        fputcsv($out, [
                            'ADVISORY', $file['file'], $package['name'], $package['constraint'],
                            $package['installed_version'] ?? '',
                            self::dev($package), 'RENTAN',
                            $advisory['severity'] ?? '',
                            $advisory['title'] ?? '',
                            $advisory['cve'] ?? '',
                            $advisory['affected_versions'] ?? '',
                            implode(' | ', $advisory['sources'] ?? []),
                            self::upgradeText($package),
                        ], ';');
                    }
                }
            }

            fputcsv($out, ['', '', '', '', '', '', '', '', '', '', '', ''], ';');

            $summary = $result['summary'];
            fputcsv($out, ['RINGKASAN', '', 'Total paket', $summary['total_packages']], ';');
            fputcsv($out, ['RINGKASAN', '', 'Paket rentan', $summary['vulnerable_packages']], ';');
            fputcsv($out, ['RINGKASAN', '', 'Total advisory', $summary['total_advisories']], ';');
            fputcsv($out, ['RINGKASAN', '', 'Level risiko', strtoupper($summary['risk'])], ';');

            foreach ($result['warnings'] as $warning) {
                fputcsv($out, ['RINGKASAN', '', 'Peringatan', $warning], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function pdf(array $result): Response
    {
        $filename = 'laporan-scan-'.now()->format('Ymd-His').'.pdf';

        $pdf = Pdf::loadView('reports.scan-pdf', [
            'result' => $result,
            'generatedAt' => now()->format('d M Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download($filename);
    }

    /**
     * Unduh laporan sebagai SARIF 2.1.0 — siap di-upload ke GitHub
     * Code Scanning (github/codeql-action/upload-sarif).
     */
    public function sarif(array $result): Response
    {
        $filename = 'laporan-scan-'.now()->format('Ymd-His').'.sarif';

        $json = json_encode(
            (new SarifReport)->render($result),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return response()->make($json, 200, [
            'Content-Type' => 'application/sarif+json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private static function dev(array $package): string
    {
        return ! empty($package['dev']) ? 'ya' : 'tidak';
    }

    /**
     * Teks saran upgrade untuk kolom CSV, mis. "7.15.5 (cukup update)".
     */
    private static function upgradeText(array $package): string
    {
        $upgrade = $package['upgrade'] ?? null;

        if (! is_array($upgrade) || empty($upgrade['version'])) {
            return $upgrade['note'] ?? '';
        }

        return $upgrade['version'].($upgrade['within_range'] ? ' (cukup update)' : ' (perlu ubah constraint)');
    }
}
