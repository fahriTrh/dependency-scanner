<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head', ['title' => 'Proyek Saya — Dependency Security Scanner'])
</head>
<body class="bg-slate-100 dark:bg-slate-950 min-h-screen">
<div class="max-w-5xl mx-auto px-4 py-8">
    @include('partials.header')

    <div class="flex items-center justify-between gap-4 mb-6">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Proyek Saya</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Daftar proyek yang dipantau — tiap proyek punya sumber scan sendiri (repo GitHub atau file JSON).</p>
        </div>
        <a href="{{ route('projects.create') }}" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg transition text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Proyek Baru
        </a>
    </div>

    @if (session('status'))
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 dark:bg-emerald-950 dark:border-emerald-900 dark:text-emerald-300 rounded-xl p-4 mb-6 text-sm flex items-start gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.3 2.4 2.4 4.6-5"/></svg>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if ($projects->isEmpty())
        <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-10 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-12 h-12 mx-auto text-slate-300 dark:text-slate-600" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
            <h3 class="text-lg font-bold text-slate-700 dark:text-slate-200 mt-4">Belum ada proyek</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 mb-5">Buat proyek pertama, lalu input repo GitHub atau file JSON-nya.</p>
            <a href="{{ route('projects.create') }}" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2.5 rounded-lg transition text-sm">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Buat Proyek
            </a>
        </section>
    @else
        <div class="grid md:grid-cols-2 gap-4">
            @foreach ($projects as $project)
                <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-5 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('projects.show', $project) }}" class="font-bold text-lg text-slate-800 dark:text-slate-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                            {{ $project->name }}
                        </a>
                        <span class="shrink-0 text-[11px] font-semibold uppercase px-2 py-0.5 rounded-full {{ $project->source_type === 'github' ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300' : 'bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                            {{ $project->source_type === 'github' ? 'GitHub' : 'File JSON' }}
                        </span>
                    </div>

                    <p class="text-xs text-slate-500 dark:text-slate-400 font-mono truncate">
                        {{ $project->source_type === 'github'
                            ? $project->repo . ($project->branch ? ' @ ' . $project->branch : '')
                            : ($project->composer_json ? 'composer.json' : '') . (($project->composer_json && $project->package_json) ? ' + ' : '') . ($project->package_json ? 'package.json' : '') }}
                    </p>

                    <div class="flex items-center justify-between gap-2 mt-auto pt-2">
                        <a href="{{ route('projects.show', $project) }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                            Buka &amp; scan
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                        </a>
                        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="inline" onsubmit="return confirm('Hapus proyek &quot;{{ $project->name }}&quot;?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 transition">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                Hapus
                            </button>
                        </form>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</div>
</body>
</html>
