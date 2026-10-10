<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Dependency Security Scanner' }}</title>
<script>
    // Terapkan tema sebelum Tailwind dimuat agar tidak berkedip (FOUC)
    (function () {
        var saved = localStorage.getItem('theme');
        var dark = saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (dark) document.documentElement.classList.add('dark');
    })();
</script>
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = { darkMode: 'class' };
</script>
<style>html.dark { color-scheme: dark; }</style>
