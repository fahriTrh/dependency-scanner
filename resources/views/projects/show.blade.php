<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head', ['title' => $project->name . ' — Dependency Security Scanner'])
</head>
<body class="bg-slate-100 dark:bg-slate-950 min-h-screen">
<div class="max-w-5xl mx-auto px-4 py-8">
    @include('partials.header')

    <div class="mb-6">
        <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            Kembali ke daftar proyek
        </a>

        <div class="flex items-start justify-between gap-4 flex-wrap mt-2">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ $project->name }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 font-mono">
                    @if ($project->source_type === 'github')
                        {{ $project->repo }}{{ $project->branch ? ' @ ' . $project->branch : '' }}{{ $project->path ? ' / ' . $project->path : '' }}
                    @else
                        {{ ($project->composer_json ? 'composer.json' : '') . (($project->composer_json && $project->package_json) ? ' + ' : '') . ($project->package_json ? 'package.json' : '') }}
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('projects.destroy', $project) }}" class="inline" onsubmit="return confirm('Hapus proyek ini?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="inline-flex items-center gap-1.5 text-sm font-medium px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 text-slate-500 dark:text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 hover:border-rose-300 dark:hover:border-rose-800 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                    Hapus proyek
                </button>
            </form>
        </div>
    </div>

    @if (session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 dark:bg-emerald-950 dark:border-emerald-900 dark:text-emerald-300 rounded-xl p-4 mb-6 text-sm flex items-start gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-5"/></svg>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if ($project->source_type === 'file')
        <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-6 mb-6">
            <h3 class="font-bold text-slate-700 dark:text-slate-200 mb-3">File tersimpan di database</h3>
            <div class="grid md:grid-cols-2 gap-4">
                @if ($project->composer_json)
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">composer.json</p>
                        <pre class="text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-3 overflow-auto max-h-64 text-slate-700 dark:text-slate-300">{{ $project->composer_json }}</pre>
                    </div>
                @endif
                @if ($project->package_json)
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">package.json</p>
                        <pre class="text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-3 overflow-auto max-h-64 text-slate-700 dark:text-slate-300">{{ $project->package_json }}</pre>
                    </div>
                @endif
                @if ($project->composer_lock)
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">composer.lock <span class="font-normal text-slate-400">(versi pasti)</span></p>
                        <pre class="text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-3 overflow-auto max-h-64 text-slate-700 dark:text-slate-300">{{ $project->composer_lock }}</pre>
                    </div>
                @endif
                @if ($project->package_lock)
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">package-lock.json <span class="font-normal text-slate-400">(versi pasti)</span></p>
                        <pre class="text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg p-3 overflow-auto max-h-64 text-slate-700 dark:text-slate-300">{{ $project->package_lock }}</pre>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-6 mb-6">
        <form id="scan-form" class="space-y-4">
            <div class="flex items-center gap-3 flex-wrap">
                <button type="submit" id="scan-btn" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2.5 rounded-lg transition disabled:opacity-50"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 15.5 5 5"/></svg> Scan Sekarang</button>
                <button type="button" id="export-csv" class="hidden inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-4 py-2.5 rounded-lg transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4.5-4.5M12 15l-4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg> Export CSV</button>
                <button type="button" id="export-pdf" class="hidden inline-flex items-center gap-1.5 bg-rose-600 hover:bg-rose-700 text-white font-medium px-4 py-2.5 rounded-lg transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4.5-4.5M12 15l-4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg> Export PDF</button>
                <button type="button" id="export-sarif" class="hidden inline-flex items-center gap-1.5 bg-slate-700 hover:bg-slate-800 text-white font-medium px-4 py-2.5 rounded-lg transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m-7 8h8a2 2 0 0 0 2-2V7l-5-5H7a2 2 0 0 0-2 2v16a2 2 0 0 0 1 1.7"/></svg> Export SARIF</button>
                <span id="loading" class="hidden text-sm text-slate-500 dark:text-slate-400">Memeriksa dependency…</span>
            </div>
            <p class="text-xs text-slate-400 dark:text-slate-500">
                @if ($project->source_type === 'github')
                    Sumber diambil dari <span class="font-mono">{{ $project->repo }}</span> setiap kali tombol Scan ditekan.
                @else
                    Sumber dari file yang tersimpan di database proyek ini.
                @endif
            </p>
        </form>
    </section>

    <section id="error-box" class="hidden bg-red-50 border border-red-200 text-red-700 dark:bg-red-950 dark:border-red-900 dark:text-red-300 rounded-xl p-4 mb-6 text-sm"></section>

    <section id="result" class="hidden space-y-6"></section>
</div>

<script>
    window.SCANNER_CONFIG = {
        scanUrl: '{{ route('projects.scan', $project) }}',
        exportUrl: '{{ route('projects.scan.export', $project) }}',
        payloadMode: 'project'
    };
</script>
<script src="{{ asset('js/scanner.js') }}"></script>
</body>
</html>
