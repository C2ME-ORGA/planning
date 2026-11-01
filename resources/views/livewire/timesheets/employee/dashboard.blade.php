<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Mon espace</h1>
            <p class="mt-1 text-sm text-stone-600">{{ $employee->fullName() }} · vos relevés d’heures, leurs statuts et les corrections demandées.</p>
        </div>

        @if ($employee)
            <a href="{{ route('workspace.employee.week') }}" class="inline-flex min-h-11 items-center rounded-lg bg-amber-700 px-4 text-sm font-semibold text-white hover:bg-amber-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">
                Saisir mes heures
            </a>
        @endif
    </div>

    @if (! $employee)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="status">
            <p class="font-semibold">Compte non rattaché à une fiche salarié</p>
            <p class="mt-1">Aucun relevé ne peut être affiché. Contactez le Bureau/Direction pour vérifier le rattachement de votre compte.</p>
        </div>
    @else
        <section aria-labelledby="week-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 id="week-heading" class="text-base font-semibold">Semaine en cours</h2>

                    @if ($current)
                        <p class="mt-1 text-sm text-stone-600">
                            Du {{ $current->period_start?->format('d/m/Y') }} au {{ $current->period_end?->format('d/m/Y') }}
                        </p>
                        <p class="mt-3 text-3xl font-semibold tabular-nums">{{ $totalFormatter->formatMinutes($currentTotal) }}</p>
                        <p class="text-xs text-stone-500">enregistrées sur la semaine</p>
                    @else
                        <p class="mt-1 text-sm text-stone-600">Aucune feuille de temps n’est ouverte pour cette semaine.</p>
                    @endif
                </div>

                <div class="flex flex-col items-start gap-2 sm:items-end">
                    @if ($current)
                        <x-status-badge :status="$current->status" />
                    @else
                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-200">Non ouverte</span>
                    @endif

                    <a href="{{ route('workspace.employee.week', $current ? ['week' => $current->period_start?->toDateString()] : []) }}" class="text-sm font-semibold text-amber-800 underline underline-offset-4 hover:text-amber-900">
                        {{ $current ? 'Consulter et modifier' : 'Ouvrir ma semaine' }}
                    </a>
                </div>
            </div>
        </section>

        <section aria-labelledby="counts-heading" class="space-y-3">
            <h2 id="counts-heading" class="text-base font-semibold">Suivi de mes relevés</h2>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <a href="{{ route('workspace.employee.week') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm hover:border-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">Brouillons</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $counts['drafts'] }}</p>
                </a>
                <a href="{{ route('workspace.timesheets.index') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm hover:border-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">En attente</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $counts['pending'] }}</p>
                </a>
                <a href="{{ route('workspace.timesheets.index') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm hover:border-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">Corrections demandées</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $counts['corrections'] }}</p>
                </a>
                <a href="{{ route('workspace.timesheets.index') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm hover:border-amber-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600">
                    <p class="text-xs font-medium uppercase tracking-wide text-stone-500">Validées</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $counts['validated'] }}</p>
                </a>
            </div>
        </section>

        @if ($counts['corrections'] > 0)
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-950" role="status">
                <p class="font-semibold">Une correction est demandée sur {{ $counts['corrections'] }} relevé(s).</p>
                <p class="mt-1">Consultez le motif, reprenez la correction puis transmettez de nouveau votre relevé.</p>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <section aria-labelledby="recent-timesheets-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 id="recent-timesheets-heading" class="text-base font-semibold">Derniers relevés</h2>

                @if ($timesheets->isEmpty())
                    <p class="mt-4 text-sm text-stone-500">Aucun relevé pour le moment.</p>
                @else
                    <ul class="mt-4 divide-y divide-stone-100" role="list">
                        @foreach ($timesheets as $timesheet)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <a href="{{ route('workspace.timesheets.show', $timesheet) }}" class="text-sm font-semibold text-stone-900 underline-offset-4 hover:underline">
                                        Semaine du {{ $timesheet->period_start?->format('d/m/Y') }}
                                    </a>
                                    <p class="text-xs text-stone-500">{{ $totalFormatter->formatMinutes($totalFormatter->totalMinutes($timesheet)) }}</p>
                                </div>
                                <x-status-badge :status="$timesheet->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="recent-events-heading" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 id="recent-events-heading" class="text-base font-semibold">Dernières actions</h2>

                @if ($recentEvents === [])
                    <p class="mt-4 text-sm text-stone-500">Aucune action enregistrée pour le moment.</p>
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
    @endif
</div>
