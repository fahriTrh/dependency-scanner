<?php

namespace App\Services\Scanner;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;

/**
 * Menentukan saran versi aman untuk paket yang rentan.
 *
 * Strategi (urut prioritas):
 * 1. Versi aman TERTINGGI yang masih berada di dalam constraint user
 *    → cukup `composer update` / `npm update` (within_range = true).
 * 2. Versi aman tertinggi di luar constraint → perlu ubah constraint
 *    di composer.json/package.json (within_range = false).
 * 3. Tidak ada versi aman sama sekali → note "belum ada rilis perbaikan".
 */
class SafeVersionAdvisor
{
    /**
     * @param  array<int, string>  $availableVersions  daftar versi nyata dari registry (urutan bebas)
     * @param  string  $constraint  constraint milik user, mis. "^2.0"
     * @param  array<int, array<string, mixed>>  $advisories  daftar advisory terformat (pakai key affected_versions)
     * @param  bool  $npm  true = semantik range npm, false = semantik composer
     * @return array{version: ?string, within_range: bool, note: ?string}
     */
    public static function recommend(array $availableVersions, string $constraint, array $advisories, bool $npm): array
    {
        $affected = self::affectedConstraints($advisories, $npm);

        if ($affected === []) {
            return ['version' => null, 'within_range' => false, 'note' => null];
        }

        try {
            $dependency = VersionRange::parse($constraint, $npm);
        } catch (\Throwable) {
            return ['version' => null, 'within_range' => false, 'note' => 'constraint tidak bisa dipahami'];
        }

        $allowPrerelease = str_contains($constraint, '-');
        $candidates = [];

        foreach ($availableVersions as $version) {
            if (! is_string($version)) {
                continue;
            }

            $clean = ltrim(trim($version), 'v');

            // Lewati branch dev (mis. "dev-main", "2.x-dev")
            if (preg_match('/-dev$/', $clean) === 1 || ! preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.-]+)?$/', $clean)) {
                continue;
            }

            // Prerelease hanya dipertimbangkan jika constraint user juga prerelease
            if (! $allowPrerelease && str_contains($clean, '-')) {
                continue;
            }

            $candidates[] = $clean;
        }

        if ($candidates === []) {
            return ['version' => null, 'within_range' => false, 'note' => 'tidak ada versi stabil yang bisa dinilai'];
        }

        usort($candidates, 'version_compare');

        $bestOverall = null;

        // Iterasi dari versi tertinggi: kandidat pertama yang aman DAN
        // masih di dalam constraint adalah saran terbaik.
        for ($i = count($candidates) - 1; $i >= 0; $i--) {
            $candidate = $candidates[$i];
            $normalized = self::normalize($candidate);

            if ($normalized === null) {
                continue;
            }

            $candidateConstraint = new Constraint('==', $normalized);

            $isSafe = true;

            foreach ($affected as $affectedConstraint) {
                if ($affectedConstraint->matches($candidateConstraint)) {
                    $isSafe = false;
                    break;
                }
            }

            if (! $isSafe) {
                continue;
            }

            $bestOverall ??= $candidate;

            try {
                if ($dependency->matches($candidateConstraint)) {
                    return ['version' => $candidate, 'within_range' => true, 'note' => null];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($bestOverall !== null) {
            return [
                'version' => $bestOverall,
                'within_range' => false,
                'note' => sprintf('versi aman berada di luar constraint "%s" — constraint perlu diubah', $constraint),
            ];
        }

        return ['version' => null, 'within_range' => false, 'note' => 'belum ada rilis perbaikan untuk advisory terkait'];
    }

    /**
     * Gabungkan semua range affected dari advisory menjadi daftar
     * ConstraintInterface (diuji dengan ATAU).
     *
     * @param  array<int, array<string, mixed>>  $advisories
     * @return array<int, ConstraintInterface>
     */
    private static function affectedConstraints(array $advisories, bool $npm): array
    {
        $ranges = [];

        foreach ($advisories as $advisory) {
            $raw = $advisory['affected_versions'] ?? null;

            if (! is_string($raw) || trim($raw) === '' || $raw === 'tidak diketahui') {
                continue;
            }

            // Pecah hanya pada "||" (ATAU antar alternatif).
            // Koma (">=2.0,<2.4.5") dan spasi (">=3.7.0 <4.17.19") adalah DAN
            // dan harus tetap utuh dalam satu segmen — parse() yang menangani.
            foreach (preg_split('/\s*\|\|\s*/', $raw) ?: [] as $range) {
                $range = trim($range);

                if ($range !== '') {
                    $ranges[] = $range;
                }
            }
        }

        $parsed = [];

        foreach ($ranges as $range) {
            try {
                $parsed[] = VersionRange::parse($range, $npm);
            } catch (\Throwable) {
                continue;
            }
        }

        return $parsed;
    }

    private static function normalize(string $version): ?string
    {
        try {
            return (new VersionParser)->normalize($version);
        } catch (\Throwable) {
            return null;
        }
    }
}
