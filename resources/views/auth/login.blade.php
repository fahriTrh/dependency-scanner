<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head', ['title' => 'Login — Dependency Security Scanner'])
</head>
<body class="bg-slate-100 dark:bg-slate-950 min-h-screen">
<div class="max-w-md mx-auto px-4 py-10">
    @include('partials.header')

    <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-6 mt-6">
        <h2 class="text-xl font-bold text-slate-800 dark:text-slate-100 mb-1">Masuk ke akun Anda</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">Kelola proyek dan pemantauan dependency Anda.</p>

        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 dark:bg-red-950 dark:border-red-900 dark:text-red-300 rounded-lg p-3 mb-4 text-sm flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4 2.8 19.5a1.2 1.2 0 0 0 1 1.8h16.4a1.2 1.2 0 0 0 1-1.8L12 4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v4.5M12 17.6h.01"/></svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                       class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                       placeholder="anda@email.com">
            </div>

            <div>
                <label for="password" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Password</label>
                <input type="password" id="password" name="password" required
                       class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                       placeholder="••••••••">
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                Ingat saya
            </label>

            <button type="submit" class="w-full inline-flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2.5 rounded-lg transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                Masuk
            </button>
        </form>

        <p class="text-sm text-slate-500 dark:text-slate-400 mt-5 text-center">
            Belum punya akun?
            <a href="{{ route('register') }}" class="text-indigo-600 dark:text-indigo-400 font-semibold underline">Daftar</a>
        </p>
    </section>
</div>
</body>
</html>
