<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use App\Services\Scanner\GithubSource;
use App\Services\Scanner\LockFileParser;
use App\Services\Scanner\MarkdownReport;
use App\Services\Scanner\ReportExporter;
use App\Services\Scanner\SecurityScanService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScanController extends Controller
{
    public function __construct(
        private readonly SecurityScanService $scanner,
        private readonly GithubSource $github,
    ) {}

    /**
     * POST /api/scan
     * Terima salah satu / kombinasi dari:
     * - composer_json: teks isi composer.json (paste)
     * - package_json: teks isi package.json (paste)
     * - composer_lock: teks isi composer.lock (paste) — versi pasti terpasang
     * - package_lock: teks isi package-lock.json (paste)
     * - files.composer / files.package / files.composer_lock / files.package_lock: file upload
     * - repo: "user/repo" atau URL github.com (branch/subdirektori opsional)
     */
    public function scan(Request $request): Response
    {
        $sources = $this->resolveSources($request);

        $result = $this->scanner->scan($sources['composer_json'], $sources['package_json'], $sources['locks']);
        $result['warnings'] = array_merge($sources['warnings'], $result['warnings']);

        Scan::record(
            $result,
            userId: $request->user()?->id,
            source: 'api',
            label: $this->labelFor($sources, $request->input('repo')),
        );

        // Output Markdown untuk GitHub Step Summary / komentar PR.
        if ($request->query('format') === 'markdown') {
            $markdown = (new MarkdownReport)->render(
                $result,
                repo: $request->input('repo') ?: null,
                branch: $request->input('branch') ?: ($request->header('X-Scan-Branch') ?: null),
            );

            return response()->make($markdown, 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
        }

        return response()->json($result);
    }

    /**
     * POST /api/scan/export?format=csv|pdf|sarif
     * Sumber input sama dengan /api/scan; mengembalikan file laporan.
     * Export tidak direkam ke riwayat karena scan-nya sendiri sudah tercatat
     * lewat POST /api/scan.
     */
    public function export(Request $request): Response
    {
        $sources = $this->resolveSources($request);

        $result = $this->scanner->scan($sources['composer_json'], $sources['package_json'], $sources['locks']);
        $result['warnings'] = array_merge($sources['warnings'], $result['warnings']);

        return match ($request->query('format')) {
            'pdf' => (new ReportExporter)->pdf($result),
            'sarif' => (new ReportExporter)->sarif($result),
            default => (new ReportExporter)->csv($result),
        };
    }

    /**
     * Nama sumber scan untuk riwayat: repo (jika dipakai), atau daftar file.
     *
     * @param  array{composer_json: ?string, package_json: ?string, locks: array<string, string>, warnings: array<int, string>}  $sources
     */
    private function labelFor(array $sources, ?string $repo = null): string
    {
        $repo = is_string($repo) ? trim($repo) : '';

        if ($repo !== '') {
            return $repo;
        }

        $parts = [];

        if (trim((string) $sources['composer_json']) !== '') {
            $parts[] = 'composer.json';
        }

        if (trim((string) $sources['package_json']) !== '') {
            $parts[] = 'package.json';
        }

        foreach (array_keys($sources['locks']) as $filename) {
            $parts[] = $filename;
        }

        return $parts !== [] ? implode(' + ', $parts) : 'scan';
    }

    /**
     * GET /api/history?project={id}&limit=50
     * Riwayat scan milik user yang login (terbaru di atas).
     */
    public function history(Request $request): Response
    {
        $validated = $request->validate([
            'project' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Scan::query()->where('user_id', $request->user()->id)->latest();

        if (! empty($validated['project'])) {
            $query->where('project_id', $validated['project']);
        }

        $scans = $query->limit((int) ($validated['limit'] ?? 50))->get();

        return response()->json([
            'scans' => $scans->map(fn (Scan $scan) => [
                'id' => $scan->id,
                'project_id' => $scan->project_id,
                'source' => $scan->source,
                'label' => $scan->label,
                'risk' => $scan->risk,
                'total_packages' => $scan->total_packages,
                'vulnerable_packages' => $scan->vulnerable_packages,
                'total_advisories' => $scan->total_advisories,
                'created_at' => $scan->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Validasi + resolve sumber input (paste / upload / GitHub)
     * menjadi manifest dan daftar lock file.
     *
     * @return array{composer_json: ?string, package_json: ?string, locks: array<string, string>, warnings: array<int, string>}
     */
    private function resolveSources(Request $request): array
    {
        $validated = $request->validate([
            'composer_json' => ['nullable', 'string', 'max:2000000'],
            'package_json' => ['nullable', 'string', 'max:2000000'],
            'composer_lock' => ['nullable', 'string', 'max:4000000'],
            'package_lock' => ['nullable', 'string', 'max:4000000'],
            'repo' => ['nullable', 'string', 'max:300'],
            'branch' => ['nullable', 'string', 'max:200'],
            'path' => ['nullable', 'string', 'max:300'],
            'files.composer' => ['nullable', 'file', 'max:2048'],
            'files.package' => ['nullable', 'file', 'max:2048'],
            'files.composer_lock' => ['nullable', 'file', 'max:4096'],
            'files.package_lock' => ['nullable', 'file', 'max:4096'],
        ], [
            'files.composer.max' => 'File composer.json maksimal 2 MB.',
            'files.package.max' => 'File package.json maksimal 2 MB.',
            'composer_lock.max' => 'Teks composer.lock maksimal 4 MB.',
            'package_lock.max' => 'Teks package-lock.json maksimal 4 MB.',
            'files.composer_lock.max' => 'File composer.lock maksimal 4 MB.',
            'files.package_lock.max' => 'File package-lock.json maksimal 4 MB.',
        ]);

        $composerJson = $validated['composer_json'] ?? null;
        $packageJson = $validated['package_json'] ?? null;
        $sourceWarnings = [];
        $locks = [];

        foreach (['composer_lock' => LockFileParser::COMPOSER, 'package_lock' => LockFileParser::NPM] as $field => $filename) {
            $value = $validated[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $locks[$filename] = $value;
            }
        }

        // File upload menimpa paste jika keduanya ada
        if ($request->hasFile('files.composer')) {
            $composerJson = $request->file('files.composer')->get();
        }

        if ($request->hasFile('files.package')) {
            $packageJson = $request->file('files.package')->get();
        }

        if ($request->hasFile('files.composer_lock')) {
            $locks[LockFileParser::COMPOSER] = $request->file('files.composer_lock')->get();
        }

        if ($request->hasFile('files.package_lock')) {
            $locks[LockFileParser::NPM] = $request->file('files.package_lock')->get();
        }

        // Ambil dari GitHub jika diminta (menimpa paste/upload)
        if (! empty($validated['repo'])) {
            $parsed = GithubSource::parseRepoInput($validated['repo']);

            if ($parsed === null) {
                $this->fail([
                    'message' => 'Format repo tidak dikenali. Gunakan "user/repo" atau URL github.com.',
                ]);
            }

            $fetched = $this->github->fetchFiles(
                repo: $parsed['repo'],
                branch: $validated['branch'] ?? $parsed['branch'],
                subdirectory: $validated['path'] ?? $parsed['subdirectory'],
            );

            $sourceWarnings = $fetched['warnings'];
            $composerJson = $fetched['files']['composer.json'] ?? null;
            $packageJson = $fetched['files']['package.json'] ?? null;
            $locks = self::locksFrom($fetched['files']);

            if ($composerJson === null && $packageJson === null && $locks === []) {
                $this->fail([
                    'message' => 'Tidak ada composer.json, package.json, composer.lock, atau package-lock.json di repo tersebut.',
                    'warnings' => $sourceWarnings,
                ]);
            }
        }

        $hasManifest = trim((string) $composerJson) !== '' || trim((string) $packageJson) !== '';

        if (! $hasManifest && $locks === []) {
            $this->fail([
                'message' => 'Sertakan minimal satu sumber: composer_json, package_json, composer_lock, package_lock, file upload, atau repo GitHub.',
            ]);
        }

        return [
            'composer_json' => $composerJson,
            'package_json' => $packageJson,
            'locks' => $locks,
            'warnings' => $sourceWarnings,
        ];
    }

    /**
     * Ambil lock file dari daftar file hasil unduhan GitHub.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private static function locksFrom(array $files): array
    {
        $locks = [];

        foreach (LockFileParser::SUPPORTED as $filename) {
            $content = $files[$filename] ?? null;

            if (is_string($content) && trim($content) !== '') {
                $locks[$filename] = $content;
            }
        }

        return $locks;
    }

    private function fail(array $payload): never
    {
        throw new HttpResponseException(response()->json($payload, 422));
    }
}
