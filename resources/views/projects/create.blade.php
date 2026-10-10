<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head', ['title' => 'Proyek Baru — Dependency Security Scanner'])
</head>
<body class="bg-slate-100 dark:bg-slate-950 min-h-screen">
<div class="max-w-3xl mx-auto px-4 py-8">
    @include('partials.header')

    <div class="mb-6">
        <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            Kembali ke daftar proyek
        </a>
        <h2 class="text-2xl font-bold text-slate-800 dark:text-slate-100 mt-2">Proyek Baru</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Beri nama, pilih sumber pemantauan, lalu simpan. Proyek bisa discan kapan saja.</p>
    </div>

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 dark:bg-red-950 dark:border-red-900 dark:text-red-300 rounded-xl p-4 mb-6 text-sm">
            <div class="flex items-start gap-2 mb-1 font-semibold">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4 2.8 19.5a1.2 1.2 0 0 0 1 1.8h16.4a1.2 1.2 0 0 0 1-1.8L12 4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v4.5M12 17.6h.01"/></svg>
                <span>Formulir belum lengkap:</span>
            </div>
            <ul class="list-disc ml-6">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-6">
        <form method="POST" action="{{ route('projects.store') }}" class="space-y-5">
            @csrf

            <div>
                <label for="name" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Nama proyek</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="100"
                       class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                       placeholder="mis. Proyek Satu">
            </div>

            <div>
                <span class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Sumber pemantauan</span>
                <div class="flex gap-2" id="source-tabs">
                    <button type="button" data-source="github" class="source-tab px-4 py-2 rounded-lg font-medium bg-indigo-600 text-white">Repo GitHub</button>
                    <button type="button" data-source="file" class="source-tab px-4 py-2 rounded-lg font-medium bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300">File JSON</button>
                </div>
            </div>

            <!-- SUMBER: GITHUB -->
            <div id="source-github" class="space-y-4">
                <input type="hidden" name="source_type" id="source_type_github" value="github">
                <div>
                    <label for="repo" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Repo GitHub</label>
                    <input type="text" id="repo" name="repo" value="{{ old('repo') }}"
                           class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                           placeholder="laravel/laravel atau https://github.com/user/repo/tree/main/subfolder">
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">File composer.json / package.json diambil dari repo ini tiap kali discan.</p>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="branch" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Branch (opsional)</label>
                        <input type="text" id="branch" name="branch" value="{{ old('branch') }}"
                               class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                               placeholder="main (default branch jika kosong)">
                    </div>
                    <div>
                        <label for="path" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Subfolder (opsional)</label>
                        <input type="text" id="path" name="path" value="{{ old('path') }}"
                               class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                               placeholder="apps/api">
                    </div>
                </div>
            </div>

            <!-- SUMBER: FILE -->
            <div id="source-file" class="hidden space-y-4">
                <input type="hidden" name="source_type" id="source_type_file" value="file" disabled>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label for="composer_json" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">composer.json</label>
                        <textarea id="composer_json" name="composer_json" rows="8"
                                  class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                                  placeholder='{"require": {"laravel/framework": "^11.0"}}'>{{ old('composer_json') }}</textarea>
                    </div>
                    <div>
                        <label for="package_json" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">package.json</label>
                        <textarea id="package_json" name="package_json" rows="8"
                                  class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                                  placeholder='{"dependencies": {"lodash": "^4.17.20"}}'>{{ old('package_json') }}</textarea>
                    </div>
                    <div>
                        <label for="composer_lock" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">composer.lock <span class="text-xs font-normal text-slate-400">(opsional — versi pasti)</span></label>
                        <textarea id="composer_lock" name="composer_lock" rows="8"
                                  class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                                  placeholder='{"packages": [{"name": "laravel/framework", "version": "v11.0.0"}]}'>{{ old('composer_lock') }}</textarea>
                    </div>
                    <div>
                        <label for="package_lock" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">package-lock.json <span class="text-xs font-normal text-slate-400">(opsional — versi pasti)</span></label>
                        <textarea id="package_lock" name="package_lock" rows="8"
                                  class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                                  placeholder='{"packages": {"node_modules/lodash": {"version": "4.17.21"}}}'>{{ old('package_lock') }}</textarea>
                    </div>
                </div>
                <p class="text-xs text-slate-400 dark:text-slate-500">Isi minimal satu kolom. Lock file disimpan di database dan dipakai tiap scan — versi yang dicek jadi versi pasti terpasang.</p>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2.5 rounded-lg transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                    Simpan Proyek
                </button>
                <a href="{{ route('projects.index') }}" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">Batal</a>
            </div>
        </form>
    </section>
</div>

<script>
    // Pilih sumber: github <-> file (satu input name="source_type" yang aktif)
    (function () {
        const tabs = document.querySelectorAll('.source-tab');
        const panels = { github: document.getElementById('source-github'), file: document.getElementById('source-file') };
        const inputs = { github: document.getElementById('source_type_github'), file: document.getElementById('source_type_file') };
        let active = '{{ old('source_type', 'github') }}' === 'file' ? 'file' : 'github';

        function apply(source) {
            active = source;
            tabs.forEach(t => {
                const on = t.dataset.source === source;
                t.classList.toggle('bg-indigo-600', on);
                t.classList.toggle('text-white', on);
                t.classList.toggle('bg-slate-200', !on);
                t.classList.toggle('text-slate-700', !on);
                t.classList.toggle('dark:bg-slate-800', !on);
                t.classList.toggle('dark:text-slate-300', !on);
            });
            panels.github.classList.toggle('hidden', source !== 'github');
            panels.file.classList.toggle('hidden', source !== 'file');
            inputs.github.disabled = source !== 'github';
            inputs.file.disabled = source !== 'file';
        }

        tabs.forEach(t => t.addEventListener('click', () => apply(t.dataset.source)));
        apply(active);
    })();
</script>
</body>
</html>
