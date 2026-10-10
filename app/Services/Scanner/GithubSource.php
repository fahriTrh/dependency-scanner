<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\Http;

/**
 * Mengambil isi file dari repositori publik GitHub tanpa autentikasi,
 * memakai endpoint raw.githubusercontent.com.
 *
 * Rate limit tanpa token: ±60 req/jam per IP (cukup untuk pemakaian manual).
 * Jika pengguna ingin limit lebih tinggi, bisa mengisi GITHUB_TOKEN di .env
 * (opsional) sehingga request membawa header Authorization.
 */
class GithubSource
{
    private const RAW_URL = 'https://raw.githubusercontent.com/%s/%s/%s';

    private const API_URL = 'https://api.github.com/repos/%s';

    private const MANIFEST_FILES = ['composer.json', 'package.json'];

    /**
     * @return array{files: array<string, string>, warnings: array<int, string>}
     */
    public function fetchFiles(string $repo, ?string $branch = null, ?string $subdirectory = null): array
    {
        $warnings = [];

        $repo = self::normalizeRepo($repo);

        if ($repo === null) {
            return ['files' => [], 'warnings' => ['Format repo tidak dikenali. Gunakan "user/repo" atau URL github.com.']];
        }

        $branch ??= self::defaultBranch($repo, $warnings);
        $prefix = $subdirectory !== null ? trim($subdirectory, '/').'/' : '';

        $files = [];

        // Manifest wajib ada (404 jadi warning); lock file opsional — 404 senyap.
        foreach ([...self::MANIFEST_FILES, ...LockFileParser::SUPPORTED] as $filename) {
            $path = $prefix.$filename;
            $url = sprintf(self::RAW_URL, $repo, $branch, $path);

            try {
                $request = Http::timeout(20)->accept('text/plain');

                if (config('services.github.token')) {
                    $request = $request->withToken(config('services.github.token'));
                }

                $response = $request->get($url);

                if ($response->status() === 404) {
                    if (in_array($filename, self::MANIFEST_FILES, true)) {
                        $warnings[] = "{$filename} tidak ditemukan di {$repo}@{$branch} ({$path}).";
                    }

                    continue;
                }

                if ($response->failed()) {
                    $warnings[] = "Gagal mengunduh {$filename}: HTTP {$response->status()}.";

                    continue;
                }

                $files[$filename] = $response->body();
            } catch (\Throwable $e) {
                $warnings[] = "Gagal mengunduh {$filename}: ".$e->getMessage();
            }
        }

        return ['files' => $files, 'warnings' => $warnings];
    }

    /**
     * Terima "user/repo", URL https://github.com/user/repo(.git)(/tree/branch),
     * atau URL dengan subdirektori github.com/user/repo/tree/branch/sub/dir.
     *
     * @return array{repo: string, branch: ?string, subdirectory: ?string}|null
     */
    public static function parseRepoInput(string $input): ?array
    {
        $input = trim($input);

        if (preg_match('#^https?://(www\.)?github\.com/([^/\s]+)/([^/\s]+)(/.*)?$#i', $input, $m)) {
            $repo = $m[2].'/'.$m[3];
            $rest = $m[4] ?? '';
            $branch = null;
            $subdirectory = null;

            if (preg_match('#^/(?:tree|blob)/([^/\s]+)(/.*)?$#i', $rest, $t)) {
                $branch = $t[1];
                $subdirectory = isset($t[2]) ? trim($t[2], '/') : null;
            }

            $repo = preg_replace('/\.git$/i', '', $repo) ?? $repo;

            return ['repo' => $repo, 'branch' => $branch, 'subdirectory' => $subdirectory];
        }

        if (preg_match('#^([\w.-]+)/([\w.-]+)$#', $input)) {
            return ['repo' => preg_replace('/\.git$/i', '', $input) ?? $input, 'branch' => null, 'subdirectory' => null];
        }

        return null;
    }

    private static function normalizeRepo(string $repo): ?string
    {
        $parsed = self::parseRepoInput($repo);

        if ($parsed === null) {
            return null;
        }

        return $parsed['repo'];
    }

    private static function defaultBranch(string $repo, array &$warnings): string
    {
        try {
            $request = Http::timeout(15)->accept('application/vnd.github+json');

            if (config('services.github.token')) {
                $request = $request->withToken(config('services.github.token'));
            }

            $response = $request->get(sprintf(self::API_URL, $repo));

            if ($response->ok()) {
                return (string) ($response->json('default_branch') ?? 'main');
            }

            $warnings[] = "Tidak bisa mendeteksi branch default (HTTP {$response->status()}), memakai 'main'.";
        } catch (\Throwable $e) {
            $warnings[] = 'Tidak bisa mendeteksi branch default: '.$e->getMessage().", memakai 'main'.";
        }

        return 'main';
    }
}
