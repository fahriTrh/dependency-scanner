<?php

namespace App\Services\Scanner;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Mengecek package.json menggunakan npm Registry.
 *
 * Langkah 1 (akurasi): GET https://registry.npmjs.org/<paket>
 *   → daftar SEMUA versi nyata yang pernah dirilis, difilter dengan
 *     constraint dari package.json (semantik npm via composer/semver).
 *   Endpoint ini gratis, tanpa API key.
 *
 * Langkah 2 (advisory): POST https://registry.npmjs.org/-/npm/v1/security/advisories/bulk
 *   Body: JSON top-level map {"paket": ["1.2.3", ...]}
 *   → daftar advisory untuk versi-versi tersebut. Juga gratis, tanpa API key.
 */
class NpmChecker
{
    private const REGISTRY_URL = 'https://registry.npmjs.org/';

    private const BULK_API_URL = 'https://registry.npmjs.org/-/npm/v1/security/advisories/bulk';

    private const MAX_VERSIONS_PER_PACKAGE = 500;

    private const REGISTRY_POOL_SIZE = 25;

    /**
     * @param  array<string, string>  $dependencies  map package => constraint (dari package.json)
     * @param  array<string, string>  $devDependencies  map package => constraint (dev)
     * @param  array<string, array{version: string, dev: bool}>  $installed  isi package-lock.json (versi pasti,
     *                                                                       bisa berisi paket transitive yang tidak ada di manifest).
     * @return array{packages: array<int, array>, warnings: array<int, string>}
     */
    public function check(array $dependencies, array $devDependencies = [], array $installed = []): array
    {
        $warnings = [];
        $packages = [];

        foreach ($dependencies as $name => $constraint) {
            $packages[$name] = [
                'constraint' => (string) $constraint,
                'dev' => false,
                'installed' => null,
                'from_manifest' => true,
            ];
        }

        foreach ($devDependencies as $name => $constraint) {
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
            return ['packages' => [], 'warnings' => ['package.json tidak memiliki dependencies/devDependencies.']];
        }

        // 1. Pisahkan paket yang bisa dicek vs tidak (git/file/workspace dsb.)
        $checkable = [];
        $skipped = [];

        foreach ($packages as $name => $meta) {
            // Paket dari lock file sudah punya versi pasti — langsung bisa dicek.
            if ($meta['installed'] !== null) {
                if (preg_match('/^v?\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $meta['installed']) !== 1) {
                    $skipped[$name] = 'versi terpasang di lock file bukan semver ('.$meta['installed'].')';
                } else {
                    $checkable[$name] = $meta;
                }

                continue;
            }

            $skip = self::uncheckableReason($meta['constraint']);

            if ($skip !== null) {
                $skipped[$name] = $skip;
            } else {
                $checkable[$name] = $meta;
            }
        }

        // 2. Ambil versi nyata dari registry HANYA untuk paket tanpa versi lock
        //    (dipakai untuk menyaring constraint). Paket lock file tidak perlu —
        //    versinya sudah pasti; registry baru dibutuhkan untuk saran upgrade.
        $registryErrors = [];
        $needRegistry = array_filter($checkable, fn (array $m) => $m['installed'] === null);
        $registryVersions = $this->fetchRegistryVersions(array_keys($needRegistry), $warnings, $registryErrors);

        $expansions = [];

        foreach ($checkable as $name => $meta) {
            if ($meta['installed'] !== null) {
                $expansions[$name] = [$meta['installed']];

                continue;
            }

            $real = $registryVersions[$name] ?? null;

            if (! is_array($real)) {
                $skipped[$name] = $registryErrors[$name] ?? 'versi tidak bisa diambil dari npm registry';

                continue;
            }

            $versions = self::filterVersions($real, $meta['constraint']);

            if ($versions === []) {
                $skipped[$name] = 'tidak ada versi registry yang cocok dengan constraint (atau paket tidak ditemukan)';

                continue;
            }

            if (count($versions) > self::MAX_VERSIONS_PER_PACKAGE) {
                $warnings[] = "{$name}: terdapat ".count($versions).' versi yang cocok, hanya '.self::MAX_VERSIONS_PER_PACKAGE.' pertama yang dikirim.';
                $versions = array_slice($versions, 0, self::MAX_VERSIONS_PER_PACKAGE);
            }

            $expansions[$name] = $versions;
        }

        // 3. Kirim ke Bulk Advisory API
        $advisoriesByPackage = [];
        $apiFailed = false;

        if (($expansions ?? []) !== []) {
            try {
                $response = Http::timeout(60)
                    ->retry(2, 500)
                    ->asJson()
                    ->post(self::BULK_API_URL, $expansions);

                if ($response->failed()) {
                    $warnings[] = "npm Bulk Advisory API merespons {$response->status()}.";
                    $apiFailed = true;
                } else {
                    $decoded = $response->json();
                    $advisoriesByPackage = is_array($decoded) ? $decoded : [];
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Gagal menghubungi npm Bulk Advisory API: '.$e->getMessage();
                $apiFailed = true;
            }
        }

        // 4. Susun hasil mengikuti urutan input
        $results = [];

        foreach ($packages as $name => $meta) {
            if (isset($skipped[$name])) {
                $results[] = self::row($name, $meta, checked: false, skipReason: $skipped[$name]);

                continue;
            }

            if ($apiFailed) {
                $results[] = self::row($name, $meta, checked: false, skipReason: 'npm Bulk Advisory API tidak bisa dihubungi');

                continue;
            }

            $formatted = [];

            foreach ($advisoriesByPackage[$name] ?? [] as $advisory) {
                if (! is_array($advisory)) {
                    continue;
                }

                $formatted[] = [
                    'advisory_id' => (string) ($advisory['id'] ?? ''),
                    'title' => $advisory['title'] ?? '(tanpa judul)',
                    'severity' => isset($advisory['severity']) && is_string($advisory['severity']) ? strtolower($advisory['severity']) : 'unknown',
                    'cvss_score' => $advisory['cvss']['score'] ?? null,
                    'cve' => $advisory['cve'] ?? null,
                    'cwe' => isset($advisory['cwe']) && is_array($advisory['cwe']) ? $advisory['cwe'] : null,
                    'affected_versions' => $advisory['vulnerable_versions'] ?? ($advisory['range'] ?? null),
                    'reported_at' => $advisory['created'] ?? ($advisory['publish_date'] ?? null),
                    'sources' => array_values(array_filter([is_string($advisory['url'] ?? null) ? $advisory['url'] : null])),
                ];
            }

            usort($formatted, fn ($a, $b) => self::severityRank($b['severity']) <=> self::severityRank($a['severity']));

            $results[] = [
                ...self::row($name, $meta, checked: true, skipReason: null),
                'vulnerable' => $formatted !== [],
                'advisories' => $formatted,
                'upgrade' => null,
            ];
        }

        // 5. Saran upgrade butuh daftar versi registry. Paket yang dicek lewat
        //    lock file belum tentu punya data itu — ambil sekarang (hanya yang rentan).
        $missingVersions = [];

        foreach ($results as $row) {
            if ($row['vulnerable'] && ! array_key_exists($row['name'], $registryVersions)) {
                $missingVersions[] = $row['name'];
            }
        }

        if ($missingVersions !== []) {
            $more = $this->fetchRegistryVersions($missingVersions, $warnings, $registryErrors);

            foreach ($more as $name => $versions) {
                $registryVersions[$name] = $versions;
            }
        }

        foreach ($results as &$row) {
            if (! $row['vulnerable']) {
                continue;
            }

            $meta = $packages[$row['name']];
            $versions = $registryVersions[$row['name']] ?? null;

            if (! is_array($versions)) {
                $row['upgrade'] = [
                    'version' => null,
                    'within_range' => false,
                    'note' => 'daftar versi tidak bisa diambil dari npm registry',
                ];

                continue;
            }

            // Saran disusun terhadap constraint manifest; untuk paket transitive
            // (hanya ada di lock file) constraint-nya adalah versi terpasang.
            $adviceConstraint = $meta['from_manifest'] ? $meta['constraint'] : $meta['installed'];
            $upgrade = SafeVersionAdvisor::recommend($versions, $adviceConstraint, $row['advisories'], npm: true);

            if (! $meta['from_manifest'] && $upgrade['version'] !== null) {
                $upgrade['note'] = 'paket transitive — perbarui lewat dependensi induk (npm update)';
            }

            $row['upgrade'] = $upgrade;
        }

        unset($row);

        return ['packages' => $results, 'warnings' => $warnings];
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $warnings
     * @param  array<string, string>  $errors  alasan kegagalan per paket
     * @return array<string, array<int, string>|null>
     */
    private function fetchRegistryVersions(array $names, array &$warnings, array &$errors): array
    {
        $out = [];

        foreach (array_chunk($names, self::REGISTRY_POOL_SIZE) as $chunk) {
            $responses = [];

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $name) {
                        $pool->as($name)
                            ->withHeaders(['Accept' => 'application/vnd.npm.install-v1+json'])
                            ->timeout(20)
                            ->get(self::REGISTRY_URL.rawurlencode($name));
                    }
                });
            } catch (\Throwable $e) {
                $warnings[] = 'Gagal menghubungi npm registry: '.$e->getMessage();
            }

            foreach ($chunk as $name) {
                $response = $responses[$name] ?? null;

                if ($response === null || $response instanceof \Throwable) {
                    $out[$name] = null;

                    continue;
                }

                if ($response->status() === 404) {
                    $warnings[] = "{$name}: tidak ditemukan di npm registry.";
                    $errors[$name] = 'paket tidak ditemukan di npm registry';
                    $out[$name] = null;

                    continue;
                }

                if ($response->failed()) {
                    $errors[$name] = 'npm registry merespons '.$response->status();
                    $out[$name] = null;

                    continue;
                }

                $versions = $response->json('versions');
                $out[$name] = is_array($versions) ? array_values(array_filter(array_keys($versions), 'is_string')) : null;
            }
        }

        return $out;
    }

    /**
     * Filter versi nyata dari registry dengan constraint, semantik npm.
     *
     * @param  array<int, string>  $realVersions
     * @return array<int, string>
     */
    public static function filterVersions(array $realVersions, string $constraint): array
    {
        // Tolak constraint yang jelas bukan range registry (git+, http, dll.)
        if (preg_match('/^[0-9^~><=*.,|\sxXv+-]+$/', trim($constraint)) !== 1) {
            return [];
        }

        try {
            $constraintObj = VersionRange::parse($constraint, npm: true);
        } catch (\Throwable) {
            return [];
        }

        $allowPrerelease = str_contains($constraint, '-');
        $matched = [];

        foreach ($realVersions as $version) {
            if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
                continue;
            }

            if (! $allowPrerelease && str_contains($version, '-')) {
                continue;
            }

            try {
                if ($constraintObj->matches(new Constraint('==', self::normalizeVersion($version)))) {
                    $matched[] = $version;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        usort($matched, 'version_compare');

        return $matched;
    }

    /**
     * Normalisasi versi ke bentuk internal composer/semver ("1.2.3" -> "1.2.3.0")
     * agar perbandingan Constraint konsisten.
     */
    private static function normalizeVersion(string $version): string
    {
        try {
            return (new VersionParser)->normalize($version);
        } catch (\Throwable) {
            return $version;
        }
    }

    /**
     * Constraint yang tidak mungkin dicek via registry.
     */
    private static function uncheckableReason(string $constraint): ?string
    {
        $c = trim($constraint);

        if (preg_match('/^(workspace:|link:|file:|git|github|http|npm:)/i', $c) === 1) {
            return 'constraint tidak berbasis registry (workspace/git/file)';
        }

        return null;
    }

    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'high' => 3,
            'moderate', 'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    /**
     * @param  array{constraint: string, dev: bool}  $meta
     * @return array<string, mixed>
     */
    private static function row(string $name, array $meta, bool $checked, ?string $skipReason): array
    {
        return [
            'name' => $name,
            'constraint' => $meta['constraint'],
            'installed_version' => $meta['installed'],
            'dev' => $meta['dev'],
            'ecosystem' => 'npm',
            'vulnerable' => false,
            'checked' => $checked,
            'skip_reason' => $skipReason,
            'advisories' => [],
        ];
    }
}
