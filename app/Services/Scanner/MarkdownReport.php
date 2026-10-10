<?php

namespace App\Services\Scanner;

/**
 * Render hasil scan sebagai Markdown — dirancang untuk GitHub Step Summary
 * ($GITHUB_STEP_SUMMARY) dan komentar PR.
 *
 * Baris terakhir berisi komentar HTML "scan-risk: <level>" yang dipakai
 * workflow CI untuk menentukan apakah build harus gagal.
 */
class MarkdownReport
{
    private const SEVERITY_BADGE = [
        'critical' => '🔴 CRITICAL',
        'high' => '🟠 HIGH',
        'moderate' => '🟡 MODERATE',
        'medium' => '🟡 MEDIUM',
        'low' => '🟢 LOW',
        'unknown' => '⚪ unknown',
    ];

    private const RISK_ICON = [
        'aman' => '✅',
        'tinggi' => '🟠',
        'kritis' => '🔴',
    ];

    public function render(array $result, ?string $repo = null, ?string $branch = null): string
    {
        $summary = $result['summary'];
        $lines = [];

        $title = $repo !== null ? "Laporan Scan: `{$repo}`" : 'Laporan Scan Dependency';

        $lines[] = "## 🛡️ {$title}";
        $lines[] = '';

        $context = [];

        if ($branch !== null) {
            $context[] = "branch: `{$branch}`";
        }

        $context[] = 'dibuat: '.now()->utc()->format('Y-m-d H:i').' UTC';
        $lines[] = implode(' · ', $context);
        $lines[] = '';

        $risk = $summary['risk'];
        $icon = self::RISK_ICON[$risk] ?? '⚪';
        $lines[] = "**Risiko: {$icon} ".strtoupper($risk).'** — '.
            "{$summary['vulnerable_packages']} dari {$summary['total_packages']} paket rentan, ".
            "{$summary['total_advisories']} advisory.";
        $lines[] = '';

        foreach ($result['files'] as $file) {
            $lines[] = '### `'.$file['file'].'`';
            $lines[] = '';
            $lines[] = '| Paket | Constraint | Severity | Advisory | Terdampak | Saran upgrade |';
            $lines[] = '|---|---|---|---|---|---|';

            foreach ($file['packages'] as $package) {
                if (! $package['checked']) {
                    $lines[] = sprintf(
                        '| `%s` | `%s` | — | ⏭️ dilewati: %s | — | — |',
                        $package['name'],
                        self::table(self::constraintCell($package)),
                        self::table($package['skip_reason'] ?? '-'),
                    );

                    continue;
                }

                if ($package['advisories'] === []) {
                    $lines[] = sprintf(
                        '| `%s` | `%s` | — | ✅ AMAN | — | — |',
                        $package['name'],
                        self::table(self::constraintCell($package)),
                    );

                    continue;
                }

                foreach ($package['advisories'] as $i => $advisory) {
                    $name = $i === 0 ? '`'.$package['name'].'`' : '';
                    $constraint = $i === 0 ? '`'.self::table(self::constraintCell($package)).'`' : '';

                    $severity = self::SEVERITY_BADGE[$advisory['severity'] ?? 'unknown'] ?? '⚪ unknown';
                    $link = $advisory['sources'][0] ?? null;
                    $titleCell = $link !== null
                        ? sprintf('[%s](%s)', self::table($advisory['title']), $link)
                        : self::table($advisory['title']);

                    if (! empty($advisory['cve'])) {
                        $titleCell .= ' ('.$advisory['cve'].')';
                    }

                    $upgrade = self::upgradeCell($package, $i);

                    $lines[] = sprintf(
                        '| %s | %s | %s | %s | %s | %s |',
                        $name,
                        $constraint,
                        $severity,
                        $titleCell,
                        self::table((string) ($advisory['affected_versions'] ?? '-')),
                        $upgrade,
                    );
                }
            }

            $lines[] = '';
        }

        if (! empty($result['warnings'])) {
            $lines[] = '<details><summary>⚠️ Catatan ('.count($result['warnings']).')</summary>';
            $lines[] = '';

            foreach ($result['warnings'] as $warning) {
                $lines[] = '- '.self::table($warning);
            }

            $lines[] = '';
            $lines[] = '</details>';
            $lines[] = '';
        }

        // Meta untuk CI: workflow membaca level risiko dari baris ini.
        $lines[] = '<!-- scan-risk: '.$risk.' -->';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Constraint dari manifest, dengan versi terpasang (dari lock file)
     * jika berbeda: "^4.17.21 → 4.17.18".
     */
    private static function constraintCell(array $package): string
    {
        $installed = $package['installed_version'] ?? null;

        if ($installed === null || $installed === $package['constraint']) {
            return $package['constraint'];
        }

        return $package['constraint'].' → '.$installed;
    }

    /**
     * Sel saran upgrade (hanya di baris advisory pertama agar tabel rapi).
     */
    private static function upgradeCell(array $package, int $index): string
    {
        if ($index !== 0) {
            return '';
        }

        $upgrade = $package['upgrade'] ?? null;

        if (! is_array($upgrade)) {
            return '—';
        }

        if (empty($upgrade['version'])) {
            return self::table($upgrade['note'] ?? '—');
        }

        // Catatan khusus (mis. paket transitive) menggantikan label bawaan.
        if (! empty($upgrade['note'])) {
            return self::table('`'.$upgrade['version'].'` — '.$upgrade['note']);
        }

        $label = '`'.$upgrade['version'].'` '.($upgrade['within_range'] ? '✓ cukup update' : '⚠ ubah constraint');

        return self::table($label);
    }

    /**
     * escape karakter yang merusak tabel Markdown.
     */
    private static function table(string $value): string
    {
        return str_replace('|', '\\|', $value);
    }
}
