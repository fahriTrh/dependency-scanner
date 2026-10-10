<?php

namespace App\Services\Scanner;

/**
 * Mengubah hasil scan menjadi SARIF 2.1.0 — format yang bisa langsung
 * di-upload ke GitHub Code Scanning lewat github/codeql-action/upload-sarif,
 * sehingga temuan tampil sebagai alert di tab Security repo pengguna.
 *
 * Hanya paket rentan yang menghasilkan result; paket aman cukup jadi bagian
 * dari rules (tidak ada temuan).
 */
class SarifReport
{
    private const SCHEMA = 'https://json.schemastore.org/sarif-2.1.0.json';

    private const TOOL_NAME = 'Dependency Security Scanner';

    /** severity SARIF -> level alert (critical/high = error agar gagal di CI) */
    private const LEVELS = [
        'critical' => 'error',
        'high' => 'error',
        'moderate' => 'warning',
        'medium' => 'warning',
        'low' => 'note',
        'unknown' => 'warning',
    ];

    /**
     * Nilai "security-severity" (skala CVSS 0–10) representative per severity,
     * dipakai GitHub untuk mengklasifikasi alert ketika advisory tidak
     * membawa skor CVSS.
     */
    private const SECURITY_SEVERITY = [
        'critical' => '9.5',
        'high' => '8.0',
        'moderate' => '5.5',
        'medium' => '5.5',
        'low' => '3.0',
    ];

    /**
     * @param  array<string, mixed>  $result  keluaran SecurityScanService::scan()
     * @return array<string, mixed> dokumen SARIF (siap json_encode)
     */
    public function render(array $result): array
    {
        $rules = [];
        $ruleIndex = [];
        $results = [];

        foreach ($result['files'] ?? [] as $file) {
            foreach ($file['packages'] ?? [] as $package) {
                if (! ($package['vulnerable'] ?? false)) {
                    continue;
                }

                foreach ($package['advisories'] ?? [] as $advisory) {
                    $rule = $this->rule($advisory);
                    $id = $rule['id'];

                    if (! isset($ruleIndex[$id])) {
                        $ruleIndex[$id] = count($rules);
                        $rules[] = $rule;
                    }

                    $results[] = [
                        'ruleId' => $id,
                        'ruleIndex' => $ruleIndex[$id],
                        'level' => $rule['defaultConfiguration']['level'],
                        'message' => ['text' => $this->message($package, $advisory)],
                        'locations' => [[
                            'physicalLocation' => [
                                'artifactLocation' => ['uri' => (string) ($file['file'] ?? 'dependency')],
                                'region' => ['startLine' => 1],
                            ],
                        ]],
                        'properties' => [
                            'package' => (string) ($package['name'] ?? ''),
                            'ecosystem' => (string) ($file['ecosystem'] ?? ($package['ecosystem'] ?? 'unknown')),
                            'installedVersion' => $package['installed_version'] ?? null,
                            'severity' => (string) ($advisory['severity'] ?? 'unknown'),
                        ],
                    ];
                }
            }
        }

        return [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => [[
                'tool' => [
                    'driver' => [
                        'name' => self::TOOL_NAME,
                        'informationUri' => (string) config('app.url', 'https://github.com'),
                        'rules' => $rules,
                    ],
                ],
                'automationDetails' => ['id' => 'dependency-scanner/scan'],
                'results' => $results,
            ]],
        ];
    }

    /**
     * Definisi rule untuk satu advisory (dipakai ulang untuk tiap temuan
     * dengan advisory_id yang sama).
     *
     * @param  array<string, mixed>  $advisory
     * @return array<string, mixed>
     */
    private function rule(array $advisory): array
    {
        $severity = strtolower((string) ($advisory['severity'] ?? 'unknown'));
        $level = self::LEVELS[$severity] ?? 'warning';
        $title = (string) ($advisory['title'] ?? '(tanpa judul)');
        $affected = (string) ($advisory['affected_versions'] ?? 'tidak diketahui');
        $cve = is_string($advisory['cve'] ?? null) && $advisory['cve'] !== '' ? $advisory['cve'] : null;

        $sources = array_values(array_filter($advisory['sources'] ?? [], 'is_string'));
        $id = is_string($advisory['advisory_id'] ?? null) && $advisory['advisory_id'] !== ''
            ? $advisory['advisory_id']
            : ($cve ?? 'PKG-'.substr(sha1($title.'|'.$affected), 0, 16));

        $properties = ['tags' => ['security', 'dependency', $severity]];

        // GitHub membaca security-severity (string angka 0-10) untuk alert.
        $securitySeverity = isset($advisory['cvss_score']) && is_numeric($advisory['cvss_score'])
            ? (string) $advisory['cvss_score']
            : (self::SECURITY_SEVERITY[$severity] ?? null);

        if ($securitySeverity !== null) {
            $properties['security-severity'] = $securitySeverity;
        }

        $rule = [
            'id' => $id,
            'name' => preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $id),
            'shortDescription' => ['text' => $title],
            'fullDescription' => ['text' => $title.' — versi terdampak: '.$affected],
            'defaultConfiguration' => ['level' => $level],
            'properties' => $properties,
        ];

        if ($sources !== []) {
            $rule['helpUri'] = $sources[0];
        }

        if ($cve !== null) {
            $rule['properties']['cve'] = $cve;
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $advisory
     */
    private function message(array $package, array $advisory): string
    {
        $name = (string) ($package['name'] ?? 'paket');
        $installed = $package['installed_version'] ?? null;
        $title = (string) ($advisory['title'] ?? '(tanpa judul)');
        $affected = (string) ($advisory['affected_versions'] ?? 'tidak diketahui');
        $cve = is_string($advisory['cve'] ?? null) && $advisory['cve'] !== '' ? ' ('.$advisory['cve'].')' : '';

        $message = sprintf('%s%s terdampak: %s%s — versi terdampak: %s', $name, $installed !== null ? ' '.$installed : '', $title, $cve, $affected);

        if ($installed === null && $package['constraint'] ?? null) {
            $message .= sprintf(' (constraint manifest: %s)', $package['constraint']);
        }

        return $message;
    }
}
