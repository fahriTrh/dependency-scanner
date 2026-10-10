<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head')
</head>
<body class="bg-slate-100 dark:bg-slate-950 min-h-screen">
<div class="max-w-5xl mx-auto px-4 py-8">
    @include('partials.header')

    <p class="text-slate-500 dark:text-slate-400 mb-8">Cek keamanan dependency dari <code class="bg-slate-200 dark:bg-slate-800 px-1 rounded">composer.json</code> dan <code class="bg-slate-200 dark:bg-slate-800 px-1 rounded">package.json</code> via Packagist &amp; npm Registry Advisory API. Jika <code class="bg-slate-200 dark:bg-slate-800 px-1 rounded">composer.lock</code> / <code class="bg-slate-200 dark:bg-slate-800 px-1 rounded">package-lock.json</code> disertakan, versi yang dicek adalah versi pasti terpasang (termasuk dependensi transitive).</p>

    <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-6 mb-6">
        <div class="flex gap-2 mb-4" id="tabs">
            <button data-tab="paste" class="tab px-4 py-2 rounded-lg font-medium bg-indigo-600 text-white">Paste JSON</button>
            <button data-tab="upload" class="tab px-4 py-2 rounded-lg font-medium bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300">Upload File</button>
            <button data-tab="github" class="tab px-4 py-2 rounded-lg font-medium bg-slate-200 text-slate-700 dark:bg-slate-800 dark:text-slate-300">Repo GitHub</button>
        </div>

        <form id="scan-form" class="space-y-4">
            <!-- TAB: PASTE -->
            <div class="tab-panel" data-panel="paste">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">composer.json</label>
                        <textarea name="composer_json" rows="8" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none" placeholder='{"require": {"laravel/framework": "^11.0"}}'></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">package.json</label>
                        <textarea name="package_json" rows="8" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none" placeholder='{"dependencies": {"lodash": "^4.17.20"}}'></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">composer.lock <span class="text-xs font-normal text-slate-400">(opsional — versi pasti)</span></label>
                        <textarea name="composer_lock" rows="8" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none" placeholder='{"packages": [{"name": "laravel/framework", "version": "v11.0.0"}]}'></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">package-lock.json <span class="text-xs font-normal text-slate-400">(opsional — versi pasti)</span></label>
                        <textarea name="package_lock" rows="8" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 font-mono text-xs focus:ring-2 focus:ring-indigo-400 focus:outline-none" placeholder='{"packages": {"node_modules/lodash": {"version": "4.17.21"}}}'></textarea>
                    </div>
                </div>
            </div>

            <!-- TAB: UPLOAD -->
            <div class="tab-panel hidden" data-panel="upload">
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">composer.json</label>
                        <input type="file" name="files[composer]" accept=".json,application/json" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">package.json</label>
                        <input type="file" name="files[package]" accept=".json,application/json" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">composer.lock <span class="text-xs font-normal text-slate-400">(opsional)</span></label>
                        <input type="file" name="files[composer_lock]" accept=".lock,.json,application/json" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">package-lock.json <span class="text-xs font-normal text-slate-400">(opsional)</span></label>
                        <input type="file" name="files[package_lock]" accept=".json,application/json" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-2 text-sm">
                    </div>
                </div>
            </div>

            <!-- TAB: GITHUB -->
            <div class="tab-panel hidden" data-panel="github">
                <div class="grid md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Repo GitHub</label>
                        <input type="text" name="repo" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none" placeholder="laravel/laravel atau https://github.com/user/repo/tree/main/subfolder">
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Tanpa token GitHub: ±60 request/jam. Opsional isi GITHUB_TOKEN di .env.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Branch (opsional)</label>
                        <input type="text" name="branch" class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm" placeholder="main">
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" id="scan-btn" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2.5 rounded-lg transition disabled:opacity-50"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 15.5 5 5"/></svg> Scan Sekarang</button>
                <button type="button" id="export-csv" class="hidden inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-4 py-2.5 rounded-lg transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4.5-4.5M12 15l-4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg> Export CSV</button>
                <button type="button" id="export-pdf" class="hidden inline-flex items-center gap-1.5 bg-rose-600 hover:bg-rose-700 text-white font-medium px-4 py-2.5 rounded-lg transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4.5-4.5M12 15l-4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg> Export PDF</button>
                <button type="button" id="export-sarif" class="hidden inline-flex items-center gap-1.5 bg-slate-700 hover:bg-slate-800 text-white font-medium px-4 py-2.5 rounded-lg transition text-sm"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m-7 8h8a2 2 0 0 0 2-2V7l-5-5H7a2 2 0 0 0-2 2v16a2 2 0 0 0 1 1.7"/></svg> Export SARIF</button>
                <span id="loading" class="hidden text-sm text-slate-500 dark:text-slate-400">Memeriksa dependency…</span>
            </div>
        </form>
    </section>

    <section id="error-box" class="hidden bg-red-50 border border-red-200 text-red-700 dark:bg-red-950 dark:border-red-900 dark:text-red-300 rounded-xl p-4 mb-6 text-sm"></section>

    <section id="result" class="hidden space-y-6"></section>

    <footer class="text-center text-xs text-slate-400 dark:text-slate-500 mt-10">
        Data kerentanan: <a class="underline" href="https://packagist.org/apidoc#security-advisories" target="_blank">Packagist Security Advisories</a> &amp;
        <a class="underline" href="https://github.com/npm/registry/blob/main/docs/SECURITY.md" target="_blank">npm Registry Bulk Advisories</a>
    </footer>
</div>

<script>
    window.SCANNER_CONFIG = {
        scanUrl: '{{ route('scan.api') }}',
        exportUrl: '{{ route('scan.export') }}',
        payloadMode: 'form'
    };
</script>
<script src="{{ asset('js/scanner.js') }}"></script>
</body>
</html>
