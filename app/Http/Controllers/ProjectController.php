<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Scan;
use App\Services\Scanner\GithubSource;
use App\Services\Scanner\ReportExporter;
use App\Services\Scanner\SecurityScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly SecurityScanService $scanner,
        private readonly GithubSource $github,
    ) {}

    /**
     * GET /projects — daftar proyek milik user yang login.
     */
    public function index(Request $request): View
    {
        return view('projects.index', [
            'projects' => $request->user()->projects()->latest()->get(),
        ]);
    }

    /**
     * GET /projects/create
     */
    public function create(): View
    {
        return view('projects.create');
    }

    /**
     * POST /projects — simpan proyek baru (repo GitHub atau file JSON).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProject($request);

        $project = new Project($validated);
        $project->user()->associate($request->user());
        $project->save();

        return redirect()->route('projects.show', $project)
            ->with('status', 'Proyek "'.$project->name.'" berhasil dibuat.');
    }

    /**
     * GET /projects/{project} — detail proyek + halaman scan.
     */
    public function show(Request $request, Project $project): View
    {
        $this->authorizeProject($request, $project);

        return view('projects.show', ['project' => $project]);
    }

    /**
     * DELETE /projects/{project}
     */
    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $project->delete();

        return redirect()->route('projects.index')
            ->with('status', 'Proyek "'.$project->name.'" dihapus.');
    }

    /**
     * POST /projects/{project}/scan — jalankan scan memakai sumber proyek
     * (tanpa API key: cukup sesi login yang terproteksi middleware auth).
     * Bentuk respons sama dengan POST /api/scan.
     */
    public function scan(Request $request, Project $project): Response
    {
        $result = $this->runScan($request, $project);

        if (isset($result['error'])) {
            return response()->json($result['error'], 422);
        }

        Scan::record(
            $result,
            userId: $request->user()?->id,
            source: 'project',
            label: $project->name,
            projectId: $project->id,
        );

        return response()->json($result);
    }

    /**
     * POST /projects/{project}/scan/export?format=csv|pdf|sarif
     */
    public function scanExport(Request $request, Project $project): Response
    {
        $result = $this->runScan($request, $project);

        if (isset($result['error'])) {
            return response()->json($result['error'], 422);
        }

        return $this->export($request, $result);
    }

    /**
     * Validasi form proyek (web) — gabungan aturan umum + syarat per sumber.
     *
     * @return array<string, mixed>
     */
    private function validateProject(Request $request): array
    {
        $validated = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'source_type' => ['required', 'in:github,file'],
            'repo' => ['nullable', 'required_if:source_type,github', 'string', 'max:300'],
            'branch' => ['nullable', 'string', 'max:200'],
            'path' => ['nullable', 'string', 'max:300'],
            'composer_json' => ['nullable', 'string', 'max:2000000'],
            'package_json' => ['nullable', 'string', 'max:2000000'],
            'composer_lock' => ['nullable', 'string', 'max:4000000'],
            'package_lock' => ['nullable', 'string', 'max:4000000'],
        ], [
            'repo.required_if' => 'Isi nama repo GitHub (user/repo) untuk sumber GitHub.',
            'composer_lock.max' => 'Teks composer.lock maksimal 4 MB.',
            'package_lock.max' => 'Teks package-lock.json maksimal 4 MB.',
        ])->validate();

        // Sumber file: wajib ada minimal satu manifest atau lock file
        if ($validated['source_type'] === 'file'
            && trim((string) ($validated['composer_json'] ?? '')) === ''
            && trim((string) ($validated['package_json'] ?? '')) === ''
            && trim((string) ($validated['composer_lock'] ?? '')) === ''
            && trim((string) ($validated['package_lock'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'composer_json' => 'Sertakan minimal satu file: composer.json, package.json, composer_lock, atau package_lock.',
            ]);
        }

        // Sumber GitHub: format repo divalidasi sedini mungkin
        if ($validated['source_type'] === 'github' && GithubSource::parseRepoInput($validated['repo']) === null) {
            throw ValidationException::withMessages([
                'repo' => 'Format repo tidak dikenali. Gunakan "user/repo" atau URL github.com/user/repo.',
            ]);
        }

        return $validated;
    }

    /**
     * Ambil sumber dari proyek lalu jalankan scan.
     * Mengembalikan hasil scan, atau ['error' => payload 422].
     *
     * @return array<string, mixed>
     */
    private function runScan(Request $request, Project $project): array
    {
        $this->authorizeProject($request, $project);

        $sources = $project->resolveSources($this->github);
        $locks = $sources['locks'];

        if ($sources['composer_json'] === null && $sources['package_json'] === null && $locks === []) {
            return ['error' => [
                'message' => 'Tidak ada composer.json, package.json, atau lock file di proyek ini.',
                'warnings' => $sources['warnings'],
            ]];
        }

        $result = $this->scanner->scan($sources['composer_json'], $sources['package_json'], $locks);
        $result['warnings'] = array_merge($sources['warnings'], $result['warnings']);

        return $result;
    }

    /**
     * Unduh laporan sesuai ?format= (default csv).
     */
    private function export(Request $request, array $result): Response
    {
        return match ($request->query('format')) {
            'pdf' => (new ReportExporter)->pdf($result),
            'sarif' => (new ReportExporter)->sarif($result),
            default => (new ReportExporter)->csv($result),
        };
    }

    /**
     * Proyek hanya boleh diakses pemiliknya (404 jika bukan miliknya).
     */
    private function authorizeProject(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()?->id, 404);
    }
}
