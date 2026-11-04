<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">

        <title>Espace métier — Planning C2ME</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-stone-100 font-sans text-stone-900 antialiased">
        <a href="#workspace-content" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-amber-700 focus:px-4 focus:py-3 focus:text-white">
            Aller au contenu
        </a>

        @php
            $navUser = auth()->user();
            $linkClass = 'inline-flex min-h-10 items-center rounded-lg px-3 py-2 text-sm font-medium text-stone-600 hover:bg-stone-100 hover:text-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2';
            $activeClass = 'inline-flex min-h-10 items-center rounded-lg bg-amber-100 px-3 py-2 text-sm font-semibold text-amber-900';
            $navLink = static fn (bool $active): string => $active ? $activeClass : $linkClass;
        @endphp

        <header class="border-b border-stone-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <a href="{{ route('workspace.home') }}" class="text-xs font-semibold uppercase tracking-wide text-amber-700">Planning C2ME</a>
                        <p class="text-lg font-semibold leading-tight">Espace métier</p>
                    </div>

                    @if ($navUser)
                        <div class="flex items-center gap-3 text-right">
                            <p class="text-xs text-stone-600">
                                <span class="block font-medium text-stone-900">{{ $navUser->name }}</span>
                                {{ $navUser->role->label() }}
                            </p>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-10 items-center rounded-lg border border-stone-300 px-3 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                                    Se déconnecter
                                </button>
                            </form>
                        </div>
                    @endif
                </div>

                <nav aria-label="Navigation de l’espace métier" class="mt-4 flex flex-wrap gap-1 border-t border-stone-100 pt-3">
                    @if ($navUser)
                        @if ($navUser->hasPermission(\App\Enums\Permission::TimesheetValidate))
                            <a href="{{ route('workspace.bureau.dashboard') }}" class="{{ $navLink(request()->routeIs('workspace.bureau.dashboard')) }}">Tableau de bord</a>
                            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'a-controler']) }}" class="{{ $navLink(request()->routeIs('workspace.timesheets.queue') && request()->route('queue') === 'a-controler') }}">À contrôler</a>
                            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'pretes']) }}" class="{{ $navLink(request()->routeIs('workspace.timesheets.queue') && request()->route('queue') === 'pretes') }}">Prêts</a>
                            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'chevauchements']) }}" class="{{ $navLink(request()->routeIs('workspace.timesheets.queue') && request()->route('queue') === 'chevauchements') }}">Chevauchements</a>
                            <a href="{{ route('workspace.bureau.batch') }}" class="{{ $navLink(request()->routeIs('workspace.bureau.batch')) }}">Validation en masse</a>
                            <a href="{{ route('workspace.bureau.journal') }}" class="{{ $navLink(request()->routeIs('workspace.bureau.journal')) }}">Journal</a>
                        @elseif ($navUser->hasPermission(\App\Enums\Permission::TimesheetControl))
                            <a href="{{ route('workspace.team.dashboard') }}" class="{{ $navLink(request()->routeIs('workspace.team.dashboard')) }}">Tableau de bord</a>
                            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'a-controler']) }}" class="{{ $navLink(request()->routeIs('workspace.timesheets.queue') && request()->route('queue') === 'a-controler') }}">À contrôler</a>
                            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'a-corriger']) }}" class="{{ $navLink(request()->routeIs('workspace.timesheets.queue') && request()->route('queue') === 'a-corriger') }}">En correction</a>
                        @else
                            <a href="{{ route('workspace.employee.dashboard') }}" class="{{ $navLink(request()->routeIs('workspace.employee.dashboard')) }}">Tableau de bord</a>
                        @endif

                        <a href="{{ route('workspace.timesheets.index') }}" class="{{ $navLink(request()->routeIs('workspace.timesheets.index')) }}">Relevés</a>

                        @if ($navUser->hasPermission(\App\Enums\Permission::TimesheetOwn))
                            <a href="{{ route('workspace.employee.week') }}" class="{{ $navLink(request()->routeIs('workspace.employee.week')) }}">Ma semaine</a>
                        @endif

                        @if (\Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\PlanningAssignment::class))
                            <a href="{{ route('planning.mobile') }}" class="{{ $navLink(request()->routeIs('planning.mobile')) }}">Planning prévisionnel</a>
                        @endif

                        @if ($navUser->role->canAccessAdministrationPanel())
                            <a href="/admin" class="{{ $navLink(request()->is('admin*')) }}">Administration</a>
                        @endif
                    @endif
                </nav>
            </div>
        </header>

        <main id="workspace-content" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>

        <div wire:loading role="status" class="fixed right-4 top-4 z-50 rounded-lg bg-stone-900 px-3 py-2 text-xs font-medium text-white shadow-lg">
            Traitement en cours…
        </div>

        <footer class="mx-auto max-w-7xl px-4 pb-10 text-xs text-stone-500 sm:px-6 lg:px-8">
            Les relevés d’heures sont soumis au workflow de contrôle et de validation. Le planning prévisionnel ne remplace jamais la saisie des heures réelles.
        </footer>

        @livewireScripts
        @stack('scripts')
    </body>
</html>
