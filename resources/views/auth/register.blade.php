<!DOCTYPE html>
<html lang="id">
<head>
    @include('partials.head', ['title' => 'Daftar — Dependency Security Scanner'])
</head>
<body class="bg-slate-100 dark:bg-slate-950 min-h-screen">
<div class="max-w-md mx-auto px-4 py-10">
    @include('partials.header')

    <section class="bg-white dark:bg-slate-900 rounded-xl shadow p-6 mt-6">
        <h2 class="text-xl font-bold text-slate-800 dark:text-slate-100 mb-1">Buat akun baru</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-5">Satu akun untuk membuat banyak proyek pemantauan.</p>

        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 dark:bg-red-950 dark:border-red-900 dark:text-red-300 rounded-lg p-3 mb-4 text-sm flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4 2.8 19.5a1.2 1.2 0 0 0 1 1.8h16.4a1.2 1.2 0 0 0 1-1.8L12 4Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v4.5M12 17.6h.01"/></svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Nama</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus
                       class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                       placeholder="Nama Anda">
            </div>

            <div>
                <label for="email" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required
                       class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                       placeholder="anda@email.com">
            </div>

            <div>
                <label for="password" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Password</label>
                <input type="password" id="password" name="password" required
                       class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                       placeholder="Minimal 8 karakter">
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Ulangi password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required
                       class="w-full border border-slate-300 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500 rounded-lg p-3 text-sm focus:ring-2 focus:ring-indigo-400 focus:outline-none"
                       placeholder="••••••••">
            </div>

            <button type="submit" class="w-full inline-flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2.5 rounded-lg transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/></svg>
                Daftar
            </button>
        </form>

        <p class="text-sm text-slate-500 dark:text-slate-400 mt-5 text-center">
            Sudah punya akun?
            <a href="{{ route('login') }}" class="text-indigo-600 dark:text-indigo-400 font-semibold underline">Masuk</a>
        </p>
    </section>
</div>
</body>
</html>
