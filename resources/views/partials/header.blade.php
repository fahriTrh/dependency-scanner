<header class="mb-4">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <a href="{{ route('home') }}" class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 shrink-0 text-indigo-600 dark:text-indigo-400" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 2.5 4.5 5.4v5.7c0 4.3 3.1 8.3 7.5 9.4 4.4-1.1 7.5-5.1 7.5-9.4V5.4L12 2.5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m8.7 11.6 2.3 2.3 4.3-4.6"/></svg>
            <span class="text-3xl font-bold text-slate-800 dark:text-slate-100">Dependency Security Scanner</span>
        </a>

        <div class="flex items-center gap-2 mt-1">
            @auth
                <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                    Proyek Saya
                </a>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-slate-500 dark:text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 transition">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                        Keluar
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-slate-700 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-800 border border-transparent hover:border-slate-300 dark:hover:border-slate-700 transition">
                    Login
                </a>
                <a href="{{ route('register') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white transition">
                    Daftar
                </a>
            @endauth

            <button type="button" id="theme-toggle" aria-label="Aktifkan mode gelap" title="Aktifkan mode gelap"
                    class="shrink-0 p-2 bg-white border border-slate-300 dark:bg-slate-800 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.2A8.5 8.5 0 1 1 10.8 3a7 7 0 0 0 10.2 10.2Z"/></svg>
            </button>
        </div>
    </div>
</header>

<script>
    // Toggle tema (moon <-> sun), pilihan disimpan di localStorage
    (function () {
        var ICON_MOON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.2A8.5 8.5 0 1 1 10.8 3a7 7 0 0 0 10.2 10.2Z"/></svg>';
        var ICON_SUN = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2.5M12 19.5V22M4.22 4.22l1.76 1.76M18.02 18.02l1.76 1.76M2 12h2.5M19.5 12H22M4.22 19.78l1.76-1.76M18.02 5.98l1.76-1.76"/></svg>';
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;

        function sync() {
            var dark = document.documentElement.classList.contains('dark');
            btn.innerHTML = dark ? ICON_SUN : ICON_MOON;
            var label = dark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap';
            btn.setAttribute('aria-label', label);
            btn.title = label;
        }

        btn.addEventListener('click', function () {
            var dark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', dark ? 'dark' : 'light');
            sync();
        });

        sync();
    })();
</script>
