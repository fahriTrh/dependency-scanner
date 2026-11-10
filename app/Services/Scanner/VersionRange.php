<?php

namespace App\Services\Scanner;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Semver\Constraint\MatchNoneConstraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/**
 * Menerjemahkan constraint versi (ala Composer dan ala npm) menjadi
 * ConstraintInterface composer/semver, lalu mengecek apakah range
 * advisory (mis. "< 2.1.4", ">=2.0,<2.0.34") beririsan dengan range
 * dependency (mis. "^2.0").
 */
class VersionRange
{
    private static ?VersionParser $parser = null;

    private static function parser(): VersionParser
    {
        return self::$parser ??= new VersionParser;
    }

    /**
     * Parse constraint gabungan (mendukung "||" sebagai ATAU).
     */
    public static function parse(string $constraint, bool $npm = false): ConstraintInterface
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*' || $constraint === 'latest') {
            return new MatchAllConstraint;
        }

        if ($npm) {
            $constraint = self::normalizeNpmRange($constraint);
        }

        $alternatives = [];

        foreach (preg_split('/\s*\|\|\s*/', $constraint) ?: [$constraint] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            try {
                $alternatives[] = self::parseGroup($part, $npm);
            } catch (\UnexpectedValueException) {
                // constraint tidak bisa dipahami (mis. dist-tag) -> anggap bisa cocok apa saja
                $alternatives[] = new MatchAllConstraint;
            }
        }

        if ($alternatives === []) {
            return new MatchAllConstraint;
        }

        if (count($alternatives) === 1) {
            return $alternatives[0];
        }

        return MultiConstraint::create($alternatives, false);
    }

    /**
     * Alias semantik untuk range advisory.
     */
    public static function advisoryRange(string $range, bool $npm = false): ConstraintInterface
    {
        return self::parse($range, $npm);
    }

    /**
     * Gabungkan beberapa interval sebagai alternatif (union / ATAU).
     * Dipakai mis. untuk menggabungkan constraint manifest dengan versi lock
     * yang benar-benar terpasang menjadi satu interval dependensi.
     */
    public static function anyOf(ConstraintInterface ...$ranges): ConstraintInterface
    {
        $ranges = array_values($ranges);

        if ($ranges === []) {
            return new MatchNoneConstraint;
        }

        if (count($ranges) === 1) {
            return $ranges[0];
        }

        return MultiConstraint::create($ranges, false);
    }

    /**
     * Apakah range dependency beririsan dengan range advisory.
     * Jika penilaian gagal, hasilnya "true" (fail-safe: lebih baik flag).
     */
    public static function rangeIntersects(ConstraintInterface $dependency, ConstraintInterface $advisory): bool
    {
        try {
            return Intervals::haveIntersections($dependency, $advisory);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Rapikan karakter khusus npm menjadi bentuk yang dipahami composer/semver:
     * - ">= 1.2.3" -> ">=1.2.3"
     * - "1.2.x" -> "1.2", "1.x" -> "1"
     */
    public static function normalizeNpmRange(string $range): string
    {
        $range = preg_replace('/(<=|>=|<|>|=|\^|~)\s+/', '$1', $range) ?? $range;
        $range = preg_replace('/\.?[xX](?=$|[-+\s|])/', '', $range) ?? $range;

        return trim($range);
    }

    private static function parseGroup(string $part, bool $npm): ConstraintInterface
    {
        if ($part === '' || $part === '*') {
            return new MatchAllConstraint;
        }

        // Hyphen range ala npm: "1.2.3 - 2.3.4"
        if ($npm && preg_match('/^\S+\s+-\s+\S+$/', $part)) {
            return self::parser()->parseConstraints($part);
        }

        $constraints = [];

        // Koma dan spasi sama-sama berarti DAN (mis. ">=2.0,<2.0.34").
        foreach (preg_split('/[\s,]+/', $part) ?: [] as $token) {
            if ($token === '' || $token === '*') {
                $constraints[] = new MatchAllConstraint;

                continue;
            }

            $constraints[] = self::parseToken($token, $npm);
        }

        if ($constraints === []) {
            return new MatchAllConstraint;
        }

        if (count($constraints) === 1) {
            return $constraints[0];
        }

        return MultiConstraint::create($constraints, true);
    }

    private static function parseToken(string $token, bool $npm): ConstraintInterface
    {
        // Constraint branch/dev: "dev-main", "1.x-dev", "dev-feature"
        if (str_starts_with($token, 'dev-') || preg_match('/-dev$/', $token)) {
            return self::parser()->parseConstraints($token);
        }

        // Operator perbandingan: >=, >, <=, <, =, != (sudah dinormalisasi tanpa spasi).
        // Versi sederhana dibangun langsung dari bentuk ternormalisasi agar batas
        // interval tetap persis. VersionParser menambahkan akhiran "-dev" pada
        // batas bawah (mis. ">=2.0.0" menjadi ">=2.0.0.0-dev"), dan itu membuat
        // batas eksklusif seperti "<2.0.0" salah dianggap beririsan.
        if (preg_match('/^(>=|<=|>|<|!=|==|=)(v?\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z][0-9A-Za-z.-]*)?)$/', $token, $m)) {
            $operator = $m[1] === '=' ? '==' : $m[1];

            try {
                return new Constraint($operator, self::parser()->normalize(ltrim($m[2], 'v')));
            } catch (\UnexpectedValueException) {
                return self::parser()->parseConstraints($token);
            }
        }

        if (preg_match('/^(>=|<=|>|<|=|!=|==)/', $token)) {
            return self::parser()->parseConstraints($token);
        }

        // Versi polos/parsial, opsional prefix "v" dan prerelease
        if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z][0-9A-Za-z.-]*))?$/', $token, $m)) {
            return self::versionToken(
                (string) $m[1],
                $m[2] ?? null,
                $m[3] ?? null,
                $m[4] ?? null,
                $npm,
            );
        }

        // Caret / tilde
        if ($token[0] === '^' || $token[0] === '~') {
            return self::caretOrTilde($token);
        }

        try {
            return self::parser()->parseConstraints($token);
        } catch (\UnexpectedValueException) {
            return new MatchAllConstraint;
        }
    }

    private static function versionToken(string $major, ?string $minor, ?string $patch, ?string $pre, bool $npm): ConstraintInterface
    {
        $suffix = $pre !== null ? '-'.$pre : '';

        if ($npm) {
            // Semantik npm untuk versi parsial:
            // "1.2.3" -> tepat 1.2.3 (interval titik [1.2.3, 1.2.3])
            // "1.2"   -> >=1.2.0 <1.3.0
            // "1"     -> >=1.0.0 <2.0.0
            //
            // Titik (bukan [1.2.3, 1.2.4)) dipakai agar batas eksklusif seperti
            // ">1.2.3" benar-benar tidak beririsan dengan versi 1.2.3.
            if ($patch !== null) {
                return new Constraint('==', self::norm("{$major}.{$minor}.{$patch}{$suffix}"));
            }

            if ($minor !== null) {
                return self::and(
                    new Constraint('>=', self::norm("{$major}.{$minor}.0{$suffix}")),
                    new Constraint('<', self::norm("{$major}.".(((int) $minor) + 1).'.0')),
                );
            }

            return self::and(
                new Constraint('>=', self::norm("{$major}.0.0{$suffix}")),
                new Constraint('<', self::norm((((int) $major) + 1).'.0.0')),
            );
        }

        // Composer: serahkan ke VersionParser sebagai otoritas.
        $version = $major.($minor !== null ? '.'.$minor : '').($patch !== null ? '.'.$patch : '').$suffix;

        return self::parser()->parseConstraints($version);
    }

    private static function caretOrTilde(string $token): ConstraintInterface
    {
        $op = $token[0];
        $rest = substr($token, 1);

        if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z][0-9A-Za-z.-]*))?$/', $rest, $m)) {
            $major = (int) $m[1];
            $minor = isset($m[2]) ? (int) $m[2] : null;
            $patch = isset($m[3]) ? (int) $m[3] : null;
            $suffix = isset($m[4]) ? '-'.$m[4] : '';
            $base = self::norm($major.'.'.($minor ?? 0).'.'.($patch ?? 0).$suffix);

            if ($op === '^') {
                // ^1.2.3 -> <2.0.0 | ^0.2.3 -> <0.3.0 | ^0.0.3 -> <0.0.4 | ^0 -> <1.0.0
                $upper = match (true) {
                    $major > 0 => ($major + 1).'.0.0',
                    $minor !== null && $minor === 0 && $patch !== null => '0.0.'.($patch + 1),
                    $minor !== null => '0.'.($minor + 1).'.0',
                    default => '1.0.0',
                };
            } else {
                // ~1.2.3 -> <1.3.0 | ~1.2 -> <1.3.0 | ~1 -> <2.0.0
                $upper = match (true) {
                    $minor !== null => "{$major}.".($minor + 1).'.0',
                    default => ($major + 1).'.0.0',
                };
            }

            return self::and(new Constraint('>=', $base), new Constraint('<', self::norm($upper)));
        }

        try {
            return self::parser()->parseConstraints($token);
        } catch (\UnexpectedValueException) {
            return new MatchAllConstraint;
        }
    }

    /**
     * Normalisasi versi ke bentuk internal composer/semver (mis. "1.2.3" -> "1.2.3.0")
     * agar perbandingan Constraint konsisten.
     */
    private static function norm(string $version): string
    {
        try {
            return self::parser()->normalize($version);
        } catch (\UnexpectedValueException) {
            return $version;
        }
    }

    private static function and(ConstraintInterface $a, ConstraintInterface $b): ConstraintInterface
    {
        return MultiConstraint::create([$a, $b], true);
    }
}
