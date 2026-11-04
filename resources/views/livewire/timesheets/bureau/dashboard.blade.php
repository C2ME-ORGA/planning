<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Tableau de bord Bureau / Direction</h1>
            <p class="mt-1 text-sm text-stone-600">Pilotage du contrôle, des corrections, des chevauchements et de la validation finale.</p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('workspace.bureau.batch') }}" class="inline-flex min-h-11 items-center rounded-lg bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                Validation en masse
            </a>
            <a href="{{ route('workspace.bureau.journal') }}" class="inline-flex min-h-11 items-center rounded-lg border border-stone-300 bg-white px-4 text-sm font-medium text-stone-700 hover:bg-stone-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                Journal global
            </a>
        </div>
    </div>

    <section aria-labelledby="bureau-counters-heading" class="space-y-3">
        <h2 id="bureau-counters-heading" class="text-base font-semibold">Indicateurs</h2>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'a-controler']) }}" class="rounded-xl border border-sky-200 bg-sky-50 p-4 shadow-sm hover:border-sky-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-sky-800">À contrôler</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-sky-950">{{ $counts['to_control'] }}</p>
            </a>

            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'pretes']) }}" class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-amber-800">Prêts pour validation</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-amber-950">{{ $counts['ready'] }}</p>
            </a>

            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'a-corriger']) }}" class="rounded-xl border border-rose-200 bg-rose-50 p-4 shadow-sm hover:border-rose-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-rose-800">En correction</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-rose-950">{{ $counts['correction'] }}</p>
            </a>

            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'validees']) }}" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm hover:border-emerald-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-emerald-800">Validés</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-emerald-950">{{ $counts['validated'] }}</p>
            </a>

            <a href="{{ route('workspace.timesheets.queue', ['queue' => 'chevauchements']) }}" class="rounded-xl border border-violet-200 bg-violet-50 p-4 shadow-sm hover:border-violet-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                <p class="text-xs font-medium uppercase tracking-wide text-violet-800">Chevauchements à examiner</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-violet-950">{{ $overlapPairs }}</p>
                <p class="text-xs text-violet-800">sur {{ $overlapTimesheets }} relevé(s)</p>
            </a>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="bureau-recent-timesheets-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="bureau-recent-timesheets-heading" class="text-base font-semibold">Derniers relevés</h2>
                <a href="{{ route('workspace.timesheets.index') }}" class="text-sm font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">Tout consulter</a>
            </div>

            @if ($recentTimesheets->isEmpty())
                <p class="mt-4 text-sm text-stone-500">Aucun relevé enregistré.</p>
            @else
                <ul class="mt-4 divide-y divide-stone-100" role="list">
                    @foreach ($recentTimesheets as $timesheet)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div>
                                <a href="{{ route('workspace.timesheets.show', $timesheet) }}" class="text-sm font-semibold text-stone-900 underline-offset-4 hover:underline">
                                    {{ $timesheet->employee?->fullName() ?? 'Salarié inconnu' }}
                                </a>
                                <p class="text-xs text-stone-500">
                                    Semaine du {{ $timesheet->period_start?->format('d/m/Y') }}
                                    · {{ $summary->formatMinutes($summary->totalMinutes($timesheet)) }}
                                </p>
                            </div>
                            <x-status-badge :status="$timesheet->status" />
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="bureau-recent-events-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 id="bureau-recent-events-heading" class="text-base font-semibold">Dernières actions</h2>

            @if ($recentEvents === [])
                <p class="mt-4 text-sm text-stone-500">Aucune action enregistrée.</p>
            @else
                <ol class="mt-4 space-y-3" role="list">
                    @foreach ($recentEvents as $event)
                        <li class="border-s-2 border-stone-200 ps-3">
                            <p class="text-sm font-medium text-stone-900">{{ $event['label'] }}</p>
                            <p class="text-xs text-stone-500">
                                {{ $event['occurred_at']->format('d/m/Y H:i') }}
                                · {{ $event['actor'] ?? 'Acteur non identifié' }}
                            </p>
                            @if ($event['reason'])
                                <p class="mt-1 text-xs text-stone-600">Motif : {{ $event['reason'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    </div>
</div>
