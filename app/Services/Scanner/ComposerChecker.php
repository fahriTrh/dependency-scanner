<?php

namespace App\Services\Scanner;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Mengecek composer.json menggunakan Packagist Security Advisories API.
 *
 * Endpoint: POST https://packagist.org/api/security-advisories/
 * Body    : form-encoded, packages[] = daftar nama paket
 *           (JSON body TIDAK diterima; endpoint menuntut packages[] form/query).
 * Auth    : tidak perlu API key (gratis, tanpa registrasi).
 *
 * Respons berisi advisories per paket dengan field "affectedVersions"
 * seperti ">=2.0,<2.0.34|<7.15.2" (pemisah alternatif: "|"). Kita cek
 * apakah range tersebut beririsan dengan constraint di composer.json.
 */
class ComposerChecker
{
    private const API_URL = 'https://packagist.org/api/security-advisories/';

    private const CHUNK_SIZE = 150;

    /**
     * @param  array<string, string>  $require  map package => constraint (dari composer.json)
     * @param  array<string, string>  $requireDev  map package => constraint (dev)
     * @param  array<string, array{version: string, dev: bool}>  $installed  isi composer.lock (versi pasti, bisa berisi
     *                                                                       paket transitive yang tidak ada di manifest). Jika ada, versi ini yang dicocokkan ke advisory.
     * @return array{packages: array<int, array>, warnings: array<int, string>}
     */
    public function check(array $require, array $requireDev = [], array $installed = []): array
    {
        $warnings = [];
        $packages = [];

        foreach ($require as $name => $constraint) {
            $packages[$name] = [
                'constraint' => (string) $constraint,
                'dev' => false,
                'installed' => null,
                'from_manifest' => true,
            ];
        }

        foreach ($requireDev as $name => $constraint) {
            $packages[$name] = [
                'constraint' => (string) $constraint,
                'dev' => true,
                'installed' => null,
                'from_manifest' => true,
            ];
        }

        foreach ($installed as $name => $meta) {
            $version = is_array($meta) ? ($meta['version'] ?? null) : null;

            if (! is_string($name) || ! is_string($version) || $version === '') {
                continue;
            }

            $dev = is_array($meta) && (bool) ($meta['dev'] ?? false);

            if (isset($packages[$name])) {
                // Lock file otoritatif soal versi & penanda dev.
                $packages[$name]['installed'] = $version;
                $packages[$name]['dev'] = $dev;
            } else {
                // Paket transitive: hanya ada di lock file.
                $packages[$name] = [
                    'constraint' => $version,
                    'dev' => $dev,
                    'installed' => $version,
                    'from_manifest' => false,
                ];
            }
        }

        if ($packages === []) {
            return ['packages' => [], 'warnings' => ['composer.json tidak memiliki require/require-dev.']];
        }

        $advisoriesByPackage = [];

        foreach (array_chunk($packages, self::CHUNK_SIZE, true) as $chunk) {
            try {
                $response = Http::timeout(30)
                    ->retry(2, 500)
                    ->asForm()
                    ->post(self::API_URL, ['packages' => array_keys($chunk)]);

                if ($response->failed()) {
                    $warnings[] = "Packagist API merespons {$response->status()} untuk ".count($chunk).' paket.';

                    continue;
                }

                foreach (($response->json('advisories') ?? []) as $name => $items) {
                    $advisoriesByPackage[$name] = is_array($items) ? $items : [];
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Gagal menghubungi Packagist API: '.$e->getMessage();
            }
        }

        $results = [];

        foreach ($packages as $name => $meta) {
            // Versi terpasang dipakai untuk pencocokan (akurat), tapi versi branch
            // dev (mis. "dev-main") bukan rilis — tidak bisa dibandingkan ke advisory.
            $installedVersion = $meta['installed'];
            $skipReason = $installedVersion !== null && self::isBranchVersion($installedVersion)
                ? 'versi terpasang adalah branch dev ('.$installedVersion.') — tidak bisa dicocokkan ke advisory'
                : null;

            $dependencyRange = $skipReason === null
                ? VersionRange::parse($installedVersion ?? $meta['constraint'], npm: false)
                : null;

            $advisories = [];

            if ($skipReason === null) {
                foreach ($advisoriesByPackage[$name] ?? [] as $advisory) {
                    if (! is_array($advisory)) {
                        continue;
                    }

                    $ranges = self::affectedRanges($advisory);
                    $matched = false;

                    foreach ($ranges as $range) {
                        if (VersionRange::rangeIntersects($dependencyRange, VersionRange::advisoryRange($range))) {
                            $matched = true;
                            break;
                        }
                    }

                    if ($matched) {
                        $advisories[] = self::formatAdvisory($advisory, $ranges);
                    }
                }
            }

            $results[] = [
                'name' => $name,
                'constraint' => $meta['constraint'],
                'installed_version' => $installedVersion,
                'dev' => $meta['dev'],
                'ecosystem' => 'composer',
                'vulnerable' => $advisories !== [],
                'checked' => $skipReason === null,
                'skip_reason' => $skipReason,
                'advisories' => $advisories,
                'upgrade' => null,
            ];
        }

        // Saran versi aman untuk paket rentan (butuh daftar versi dari Packagist p2)
        $vulnerableNames = array_values(array_map(
            fn ($row) => $row['name'],
            array_filter($results, fn ($row) => $row['vulnerable']),
        ));

        if ($vulnerableNames !== []) {
            $versionLists = self::fetchPackagistVersions($vulnerableNames, $warnings);

            foreach ($results as &$row) {
                $versions = $versionLists[$row['name']] ?? null;

                if (! $row['vulnerable'] || ! is_array($versions)) {
                    continue;
                }

                $meta = $packages[$row['name']];
                // Saran disusun terhadap constraint manifest; untuk paket transitive
                // (hanya ada di lock file) constraint-nya adalah versi terpasang.
                $adviceConstraint = $meta['from_manifest'] ? $meta['constraint'] : $meta['installed'];
                $upgrade = SafeVersionAdvisor::recommend($versions, $adviceConstraint, $row['advisories'], npm: false);

                if (! $meta['from_manifest'] && $upgrade['version'] !== null) {
                    $upgrade['note'] = 'paket transitive — perbarui lewat dependensi induk (composer update)';
                }

                $row['upgrade'] = $upgrade;
            }

            unset($row);
        }

        return ['packages' => $results, 'warnings' => $warnings];
    }

    /**
     * Versi branch composer ("dev-main", "dev-feature/x", "1.x-dev").
     */
    private static function isBranchVersion(string $version): bool
    {
        return str_starts_with($version, 'dev-') || preg_match('/-dev$/', $version) === 1;
    }

    private const P2_METADATA_URL = 'https://repo.packagist.org/p2/%s.json';

    private const P2_POOL_SIZE = 20;

    /**
     * Ambil daftar versi rilis dari metadata Packagist p2 (gratis, tanpa key).
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $warnings
     * @return array<string, array<int, string>|null>
     */
    private static function fetchPackagistVersions(array $names, array &$warnings): array
    {
        $out = [];

        foreach (array_chunk($names, self::P2_POOL_SIZE) as $chunk) {
            $responses = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $name) {
                        $pool->as($name)
                            ->timeout(20)
                            ->get(sprintf(self::P2_METADATA_URL, rawurlencode($name)));
                    }
                });
            } catch (\Throwable $e) {
                $warnings[] = 'Gagal menghubungi Packagist (daftar versi): '.$e->getMessage();
            }

            foreach ($chunk as $name) {
                $response = $responses[$name] ?? null;

                if ($response === null || $response instanceof \Throwable || $response->failed()) {
                    $out[$name] = null;

                    continue;
                }

                $items = $response->json("packages.{$name}");
                $out[$name] = is_array($items)
                    ? array_values(array_filter(array_map(fn ($p) => is_array($p) ? ($p['version'] ?? null) : null, $items), 'is_string'))
                    : null;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private static function affectedRanges(array $advisory): array
    {
        $raw = $advisory['affectedVersions'] ?? $advisory['affected_versions'] ?? null;
        $ranges = [];

        // Pemisah alternatif bisa "||" atau "|" (contoh nyata Packagist: ">=8.0.0,<8.0.1|<7.15.2")
        foreach (is_array($raw) ? $raw : (preg_split('/\s*\|\|?\s*/', (string) $raw) ?: []) as $range) {
            if (is_string($range) && trim($range) !== '') {
                $ranges[] = trim($range);
            }
        }

        return $ranges;
    }

    /**
     * @param  array<int, string>  $ranges
     * @return array<string, mixed>
     */
    private static function formatAdvisory(array $advisory, array $ranges): array
    {
        $link = is_string($advisory['link'] ?? null) ? $advisory['link'] : null;
        $cve = is_string($advisory['cve'] ?? null) && $advisory['cve'] !== '' ? $advisory['cve'] : null;

        return [
            'advisory_id' => is_string($advisory['advisoryId'] ?? null) ? $advisory['advisoryId'] : null,
            'title' => is_string($advisory['title'] ?? null) && $advisory['title'] !== '' ? $advisory['title'] : '(tanpa judul)',
            'severity' => isset($advisory['severity']) && is_string($advisory['severity']) ? strtolower($advisory['severity']) : 'unknown',
            'cvss_score' => null,
            'cve' => $cve,
            'affected_versions' => $ranges !== [] ? implode(' || ', $ranges) : 'tidak diketahui',
            'reported_at' => is_string($advisory['reportedAt'] ?? null) ? $advisory['reportedAt'] : null,
            'sources' => array_values(array_filter([
                $link,
                $cve ? "https://nvd.nist.gov/vuln/detail/{$cve}" : null,
            ])),
        ];
    }
}
