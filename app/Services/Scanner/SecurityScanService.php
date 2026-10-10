<?php

namespace App\Services\Scanner;

/**
 * Orchestrator: parse composer.json / package.json (dan lock file jika ada)
 * lalu delegasikan ke ComposerChecker dan NpmChecker, dan rangkum hasilnya.
 *
 * Jika lock file tersedia, lock file yang menjadi SUMBER paket: versi pasti
 * (menghilangkan false positive dari range constraint) dan termasuk dependensi
 * transitive. Manifest tetap dipakai untuk menampilkan constraint serta
 * menyusun saran upgrade.
 */
class SecurityScanService
{
    public function __construct(
        private readonly ComposerChecker $composerChecker,
        private readonly NpmChecker $npmChecker,
    ) {}

    /**
     * @param  string|null  $composerJson  isi file composer.json (raw)
     * @param  string|null  $packageJson  isi file package.json (raw)
     * @param  array<string, string>  $lockFiles  isi lock file per nama file
     *                                            (kunci: "composer.lock" / "package-lock.json")
     * @return array<string, mixed>
     */
    public function scan(?string $composerJson, ?string $packageJson, array $lockFiles = []): array
    {
        $warnings = [];
        $results = [];
        $totalPackages = 0;
        $vulnerablePackages = 0;
        $totalAdvisories = 0;

        $composerManifest = $this->decodeManifest($composerJson, 'composer.json', $warnings);
        $npmManifest = $this->decodeManifest($packageJson, 'package.json', $warnings);
        $locks = $this->parseLocks($lockFiles, $warnings);

        // ---------------------------------------------------- composer
        $composerLock = $locks[LockFileParser::COMPOSER] ?? null;

        if ($composerLock !== null) {
            $require = $composerManifest !== null ? self::stringMap($composerManifest['require'] ?? null) : [];
            $requireDev = $composerManifest !== null ? self::stringMap($composerManifest['require-dev'] ?? null) : [];

            if ($composerManifest !== null) {
                $missing = array_diff(
                    array_keys($require + $requireDev),
                    array_keys($composerLock['packages']),
                );

                // Syarat platform (php, ext-*, lib-*, composer-plugin-api) memang
                // tidak pernah masuk lock file — bukan berarti belum di-install.
                $missing = array_values(array_filter(
                    $missing,
                    fn (string $name) => str_contains($name, '/'),
                ));

                if ($missing !== []) {
                    $warnings[] = self::missingFromLock('composer.json', LockFileParser::COMPOSER, $missing);
                }
            }

            $composerResult = $this->composerChecker->check(
                require: $require,
                requireDev: $requireDev,
                installed: $composerLock['packages'],
            );

            $results[] = [
                'file' => LockFileParser::COMPOSER,
                'ecosystem' => 'composer',
                'packages' => $composerResult['packages'],
                'warnings' => $composerResult['warnings'],
            ];
        } elseif ($composerManifest !== null) {
            $composerResult = $this->composerChecker->check(
                require: self::stringMap($composerManifest['require'] ?? null),
                requireDev: self::stringMap($composerManifest['require-dev'] ?? null),
            );

            $results[] = [
                'file' => 'composer.json',
                'ecosystem' => 'composer',
                'packages' => $composerResult['packages'],
                'warnings' => $composerResult['warnings'],
            ];
        }

        // ------------------------------------------------------ npm
        $npmLock = $locks[LockFileParser::NPM] ?? null;

        if ($npmLock !== null) {
            $dependencies = $npmManifest !== null ? self::stringMap($npmManifest['dependencies'] ?? null) : [];
            $devDependencies = $npmManifest !== null ? self::stringMap($npmManifest['devDependencies'] ?? null) : [];

            if ($npmManifest !== null) {
                $missing = array_diff(
                    array_keys($dependencies + $devDependencies),
                    array_keys($npmLock['packages']),
                );

                if ($missing !== []) {
                    $warnings[] = self::missingFromLock('package.json', LockFileParser::NPM, $missing);
                }
            }

            $npmResult = $this->npmChecker->check(
                dependencies: $dependencies,
                devDependencies: $devDependencies,
                installed: $npmLock['packages'],
            );

            $results[] = [
                'file' => LockFileParser::NPM,
                'ecosystem' => 'npm',
                'packages' => $npmResult['packages'],
                'warnings' => $npmResult['warnings'],
            ];
        } elseif ($npmManifest !== null) {
            $npmResult = $this->npmChecker->check(
                dependencies: self::stringMap($npmManifest['dependencies'] ?? null),
                devDependencies: self::stringMap($npmManifest['devDependencies'] ?? null),
            );

            $results[] = [
                'file' => 'package.json',
                'ecosystem' => 'npm',
                'packages' => $npmResult['packages'],
                'warnings' => $npmResult['warnings'],
            ];
        }

        foreach ($results as $fileResult) {
            foreach ($fileResult['packages'] as $package) {
                $totalPackages++;

                if ($package['vulnerable']) {
                    $vulnerablePackages++;
                    $totalAdvisories += count($package['advisories']);
                }
            }

            $warnings = array_merge($warnings, $fileResult['warnings']);
        }

        $risk = 'aman';

        if ($totalAdvisories > 0) {
            $risk = 'kritis';

            foreach ($results as $fileResult) {
                foreach ($fileResult['packages'] as $package) {
                    foreach ($package['advisories'] as $advisory) {
                        if (in_array($advisory['severity'], ['high', 'critical'], true)) {
                            $risk = 'tinggi';
                        }
                    }
                }
            }
        }

        return [
            'summary' => [
                'total_packages' => $totalPackages,
                'vulnerable_packages' => $vulnerablePackages,
                'total_advisories' => $totalAdvisories,
                'risk' => $risk,
            ],
            'files' => $results,
            'warnings' => $warnings,
        ];
    }

    /**
     * Decode manifest; null jika tidak diisi atau JSON tidak valid
     * (kasus invalid dicatat sebagai warning, bukan error).
     *
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>|null
     */
    private function decodeManifest(?string $raw, string $filename, array &$warnings): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $warnings[] = $filename.' bukan JSON yang valid, dilewati.';

            return null;
        }

        if (! is_array($decoded)) {
            $warnings[] = $filename.' bukan JSON yang valid, dilewati.';

            return null;
        }

        return $decoded;
    }

    /**
     * Parse semua lock file yang diberikan. File kosong/tak dikenal/tak valid
     * dilewati dengan warning; lock file yang berisi paket dipakai sebagai
     * sumber utama sehingga manifest jadi opsional.
     *
     * @param  array<string, string>  $lockFiles
     * @param  array<int, string>  $warnings
     * @return array<string, array{ecosystem: string, packages: array<string, array{version: string, dev: bool}>, warnings: array<int, string>}>
     */
    private function parseLocks(array $lockFiles, array &$warnings): array
    {
        $locks = [];

        foreach ($lockFiles as $filename => $raw) {
            if (! is_string($filename) || ! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $filename = basename($filename);

            if (! in_array($filename, LockFileParser::SUPPORTED, true)) {
                $warnings[] = "Lock file \"{$filename}\" tidak didukung (baru composer.lock dan package-lock.json), dilewati.";

                continue;
            }

            $parsed = LockFileParser::parse($filename, $raw);

            if ($parsed === null) {
                $warnings[] = "{$filename} bukan JSON yang valid, dilewati.";

                continue;
            }

            if ($parsed['packages'] === []) {
                $warnings[] = "{$filename} tidak berisi paket, dilewati.";

                continue;
            }

            $warnings = array_merge($warnings, $parsed['warnings']);
            $locks[$filename] = $parsed;
        }

        return $locks;
    }

    /**
     * Paket di manifest yang tidak ada di lock file (kemungkinan belum di-install).
     *
     * @param  array<int, string>  $missing
     */
    private static function missingFromLock(string $manifest, string $lock, array $missing): string
    {
        $names = array_slice($missing, 0, 5);
        $suffix = count($missing) > 5 ? ', … ('.count($missing).' paket)' : '';

        return "{$manifest}: paket berikut tidak ada di {$lock} (kemungkinan belum di-install): "
            .implode(', ', $names).$suffix.'.';
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $map): array
    {
        if (! is_array($map)) {
            return [];
        }

        $result = [];

        foreach ($map as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $result[$name] = $constraint;
            } elseif (is_string($name) && is_numeric($constraint)) {
                $result[$name] = (string) $constraint;
            } elseif (is_string($name) && is_bool($constraint)) {
                $result[$name] = $constraint ? 'true' : 'false';
            } elseif (is_string($name) && is_array($constraint)) {
                $result[$name] = json_encode($constraint) ?: '(objek)';
            }
        }

        return $result;
    }
}
