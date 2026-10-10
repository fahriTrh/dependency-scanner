<?php

namespace App\Services\Scanner;

/**
 * Membaca lock file untuk mengetahui versi yang BENAR-BENAR terpasang,
 * termasuk dependensi transitive yang tidak ada di manifest.
 *
 * Didukung:
 * - composer.lock      (JSON; entri "packages" + "packages-dev")
 * - package-lock.json  (v1: pohon "dependencies"; v2/v3: peta "packages")
 *
 * Keunggulan dibanding membaca manifest: tidak ada false positive/negative
 * dari range constraint (mis. "^4.17" padahal yang terpasang sudah 4.17.21),
 * dan paket turunan (bukan dependency langsung) tetap ikut tercecek.
 */
class LockFileParser
{
    public const COMPOSER = 'composer.lock';

    public const NPM = 'package-lock.json';

    /** @var array<int, string> */
    public const SUPPORTED = [self::COMPOSER, self::NPM];

    /**
     * Parse isi lock file.
     *
     * @param  string  $filename  nama file (composer.lock / package-lock.json)
     * @return array{ecosystem: string, packages: array<string, array{version: string, dev: bool}>, warnings: array<int, string>}|null
     *                                                                                                                                 null jika isi file bukan JSON yang valid atau format tidak didukung.
     */
    public static function parse(string $filename, string $raw): ?array
    {
        $filename = basename($filename);

        if (! in_array($filename, self::SUPPORTED, true)) {
            return null;
        }

        if (trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $warnings = [];
        $packages = $filename === self::COMPOSER
            ? self::fromComposerLock($data)
            : self::fromPackageLock($data, $filename, $warnings);

        return [
            'ecosystem' => $filename === self::COMPOSER ? 'composer' : 'npm',
            'packages' => $packages,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{version: string, dev: bool}>
     */
    private static function fromComposerLock(array $data): array
    {
        $packages = [];

        foreach ([['packages', false], ['packages-dev', true]] as [$key, $dev]) {
            $entries = is_array($data[$key] ?? null) ? $data[$key] : [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = $entry['name'] ?? null;
                $version = $entry['version'] ?? null;

                if (! is_string($name) || $name === '' || ! is_string($version) || $version === '') {
                    continue;
                }

                $packages[$name] ??= ['version' => $version, 'dev' => $dev];
            }
        }

        return $packages;
    }

    /**
     * package-lock.json: v2/v3 memakai peta "packages" (path => {version}),
     * v1 memakai pohon "dependencies" bersarang.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $warnings
     * @return array<string, array{version: string, dev: bool}>
     */
    private static function fromPackageLock(array $data, string $filename, array &$warnings): array
    {
        $packages = [];

        if (is_array($data['packages'] ?? null)) {
            foreach ($data['packages'] as $path => $entry) {
                if (! is_array($entry) || ! is_string($path) || $path === '') {
                    continue;
                }

                $name = self::npmNameFromPath($path);
                $version = $entry['version'] ?? null;

                if ($name === null || ! is_string($version) || $version === '') {
                    continue;
                }

                self::addNpm($packages, $warnings, $filename, $name, $version, (bool) ($entry['dev'] ?? false));
            }
        } elseif (is_array($data['dependencies'] ?? null)) {
            self::npmV1($data['dependencies'], $packages, $warnings, $filename, dev: false);
        }

        return $packages;
    }

    /**
     * Rekursi pohon "dependencies" milik lock file npm v1.
     *
     * @param  array<string, mixed>  $entries
     * @param  array<string, array{version: string, dev: bool}>  $packages
     * @param  array<int, string>  $warnings
     */
    private static function npmV1(array $entries, array &$packages, array &$warnings, string $filename, bool $dev): void
    {
        foreach ($entries as $name => $entry) {
            if (! is_array($entry) || ! is_string($name) || $name === '') {
                continue;
            }

            $version = $entry['version'] ?? null;

            if (is_string($version) && $version !== '') {
                $entryDev = $dev || (bool) ($entry['dev'] ?? false);
                self::addNpm($packages, $warnings, $filename, $name, $version, $entryDev);
            }

            if (is_array($entry['dependencies'] ?? null)) {
                // dev diwarisi dari induk jika anak tidak punya penanda sendiri
                self::npmV1($entry['dependencies'], $packages, $warnings, $filename, $dev || (bool) ($entry['dev'] ?? false));
            }
        }
    }

    /**
     * "node_modules/lodash" -> "lodash",
     * "node_modules/a/node_modules/@scope/pkg" -> "@scope/pkg".
     */
    private static function npmNameFromPath(string $path): ?string
    {
        $pos = strrpos($path, 'node_modules/');

        if ($pos === false) {
            return null;
        }

        $name = substr($path, $pos + strlen('node_modules/'));

        return $name === '' ? null : $name;
    }

    /**
     * Satu paket bisa muncul beberapa kali (versi nested berbeda).
     * Simpan yang pertama (biasanya yang ter-hoisting di root) dan catat sisanya.
     *
     * @param  array<string, array{version: string, dev: bool}>  $packages
     * @param  array<int, string>  $warnings
     */
    private static function addNpm(array &$packages, array &$warnings, string $filename, string $name, string $version, bool $dev): void
    {
        if (isset($packages[$name])) {
            if ($packages[$name]['version'] !== $version) {
                $warnings[] = sprintf(
                    '%s: "%s" terpasang pada beberapa versi (%s) — versi %s yang dicek.',
                    $filename,
                    $name,
                    implode(', ', array_filter([$packages[$name]['version'], $version])),
                    $packages[$name]['version'],
                );
            }

            return;
        }

        $packages[$name] = ['version' => $version, 'dev' => $dev];
    }
}
