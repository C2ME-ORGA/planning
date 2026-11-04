<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — Planning C2ME</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-6 py-12">
        <section class="rounded-xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-semibold uppercase tracking-wide text-amber-700">Planning C2ME</p>
            <h1 class="mt-2 text-2xl font-semibold">Connexion</h1>
            <p class="mt-2 text-sm text-slate-600">Accédez à votre planning avec les identifiants transmis lors de l’activation de votre compte.</p>

            @if (session('status'))
                <div role="status" class="mt-5 rounded-md bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium">Adresse e-mail</label>
                    <input id="email" name="email" type="email" autocomplete="username" required autofocus
                        value="{{ old('email') }}"
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-amber-600 focus:outline-none focus:ring-amber-600">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium">Mot de passe</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required
                        class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 shadow-sm focus:border-amber-600 focus:outline-none focus:ring-amber-600">
                </div>
                @if ($errors->any())
                    <p role="alert" class="text-sm text-red-700">{{ $errors->first() }}</p>
                @endif
                    <button type="submit" class="w-full rounded-md bg-amber-600 px-4 py-2.5 font-semibold text-white hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-600 focus:ring-offset-2">
                    Se connecter
                </button>
            </form>
            <p class="mt-6 text-center text-sm text-slate-500">Accès réservé aux utilisateurs disposant d’un compte activé.</p>
        </section>
    </main>
</body>
</html>
